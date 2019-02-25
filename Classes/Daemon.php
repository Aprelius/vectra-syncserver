<?php

class Daemon
{
    public $servers = array() ;
    public $clients = array() ;
    public $Mode ;
    public $logFile ;
    
    # Trigger the reloader?
    public $Reload = false ;
    public $ReloadFiles = null ;

    # Sql Settings
    public $SqlFail = 0 ;
    public $SqlFails = 7 ;
    
    public $Shutdown = false ;
    
    public $lastCleanup = 0 ;
    
    public $debug = false ;

    function __construct()
    {     
        global $Dbc ;
        
        $Query = "DELETE FROM `SyncServer`.`SessionList`" ;
        $Dbc->sql_query($Query) ;
        
        $Query = "DELETE FROM `SyncServer`.`BotList`" ;
        $Dbc->sql_query($Query) ;
                
        $this->lastCleanup = time() ;
        return $this->cleanGhosts() ;
    }

    function __destruct()
    {
        $this->log('SyncServer Daemon (PID: ' . posix_getpid() . ') shutting down.') ;
    }

    public function create_server($server_class, $client_class, $bind_address = 0, $bind_port = 0)
    {
        $server = new $server_class($client_class, $bind_address, $bind_port) ;
        if (!is_subclass_of($server, 'SocketServer'))
        {
            throw new socketException("Invalid server class specified! Has to be a subclass of SocketServer") ;
        }
        $this->servers[(int)$server->socket] = $server ;
        $server->on_start() ;
        return $server ;
    }

    public function create_client($client_class, $remote_address, $remote_port, $bind_address = 0, $bind_port = 0)
    {
        $client = new $client_class($bind_address, $bind_port) ;
        if (!is_subclass_of($client, 'ServerClient'))
        {
            throw new socketException("Invalid client class specified! Has to be a subclass of ServerClient") ;
        }
        $client->set_non_block(true) ;
        $client->connect($remote_address, $remote_port) ;
        $this->clients[(int)$client->socket] = $client ;
        return $client ;
    }

    private function create_read_set()
    {
        $ret = array() ;
        foreach ($this->clients as $socket)
        {
            $ret[] = $socket->socket ;
        }
        foreach ($this->servers as $socket)
        {
            $ret[] = $socket->socket ;
        }
        return $ret ;
    }

    private function create_write_set()
    {
        $ret = array() ;
        foreach ($this->clients as $socket)
        {
            if (!empty($socket->write_buffer) || $socket->connecting)
            {
                $ret[] = $socket->socket ;
            }
        }
        foreach ($this->servers as $socket)
        {
            if (!empty($socket->write_buffer))
            {
                $ret[] = $socket->socket ;
            }
        }
        return $ret ;
    }

    private function create_exception_set()
    {
        $ret = array() ;
        foreach ($this->clients as $socket)
        {
            $ret[] = $socket->socket ;
        }
        foreach ($this->servers as $socket)
        {
            $ret[] = $socket->socket ;
        }
        return $ret ;
    }

    private function clean_sockets()
    {
        foreach ($this->clients as $socket)
        {
            if ($socket->disconnected || !is_resource($socket->socket))
            {
                if (isset($this->clients[(int)$socket->socket]))
                {
                    unset($this->clients[(int)$socket->socket]) ;
                }
            }
        }
    }

    private function get_class($socket)
    {
        if (isset($this->clients[(int)$socket]))
        {
            return $this->clients[(int)$socket] ;
        } 
        elseif (isset($this->servers[(int)$socket]))
        {
            return $this->servers[(int)$socket] ;
        }
        else
        {
            throw (new socketException("Could not locate socket class for $socket")) ;
        }
    }

    public function process()
    {
        global $Dbc, $Sync, $runmode ;
        if (!$Dbc->isActive())
        {
            if ($this->SqlFail++ > $this->SqlFails)
            {
                $this->log('[FATAL]: Maximum retries for the SQL connection reached. Something went wrong.') ;
                die() ;
            }
            elseif (!$Dbc->sql_connect())
            {
                $this->log('Error connecting to the SQL Server. Reason: ' . $Dbc->sql_error(1), L_QUERY) ;
            }
        }

        $read_set = $this->create_read_set() ;
        $write_set = $this->create_write_set() ;
        $exception_set = $this->create_exception_set() ;
        $event_time = time() ;
        while (($events = socket_select($read_set, $write_set, $exception_set, 2)) !== false)
        {
            if ($events > 0)
            {
                foreach ($read_set as $socket)
                {
                    $socket = $this->get_class($socket) ;
                    if (is_subclass_of($socket, 'SocketServer'))
                    {
                        $client = $socket->accept() ;
                        /*
                         * Add the new client to the Daemon for global control
                         */
                        $this->clients[(int)$client->socket] = $client ;
                        $Sync->clients[(int)$client->socket] = $client ;
                        $Sync->on_new($client) ;
                        $this->log(0, "[SyncServer] Connection confirmed from " . ((!empty($this->reverse)) ? $this->reverse : '127.0.0.1') . " 
                             there are now " . count($this->clients) . " clients connected.") ;
                    } 
                    elseif (is_subclass_of($socket, 'ServerClient'))
                    {
                        // regular on_read event
                        $socket->read() ;
                    }
                }
                foreach ($write_set as $socket)
                {
                    $socket = $this->get_class($socket) ;
                    if (is_subclass_of($socket, 'ServerClient'))
                    {
                        if ($socket->connecting === true)
                        {
                            $socket->on_connect() ;
                            $socket->connecting = false ;
                        }
                        $socket->do_write() ;
                    }
                }
                foreach ($exception_set as $socket)
                {
                    $socket = $this->get_class($socket) ;
                    if (is_subclass_of($socket, 'ServerClient'))
                    {
                        $socket->on_disconnect() ;
                        if (isset($this->clients[(int)$socket->socket]))
                        {
                            unset($this->clients[(int)$socket->socket]) ;
                        }
                    }
                }
            }
            if (time() - $event_time > 1)
            {
                // only do this if more then a second passed, else we'd keep looping this for every bit recieved
                foreach ($this->clients as $socket)
                {
                    $socket->on_timer() ;
                }
                if ((time() - $this->lastCleanup) >= 60)
                {
                    $this->cleanGhosts() ;
                    $Sync->cleanCache() ;                    
                    $Sync->cleanSessions() ;
                }
                $event_time = time() ;
            }
            $this->clean_sockets() ;
            $read_set = $this->create_read_set() ;
            $write_set = $this->create_write_set() ;
            $exception_set = $this->create_exception_set() ;
            if (!empty($runmode['no-daemon']) && !$runmode['no-daemon'])
            {
                $Buffer = ob_get_contents() ;
                ob_clean() ;
                if (!empty($Buffer))
                {
                    $Buffer = explode("\n", $Buffer) ;
                    foreach ($Buffer as $Str)
                    {
                        if (!empty($Str))
                        {
                            $this->log("[STDOUT]: " . $Str) ;
                        }
                    }
                }                
            }                        
        }
    }
    
    public function cleanGhosts()
    {
        global $Dbc ;
        
        $Query = "
            SELECT `index` 
            FROM `SyncServer`.`BotList`
        " ;
        
        $Result = $Dbc->sql_query($Query) ;
        if ($Dbc->sql_num_rows($Query) == 0)
        {
            return ;
        }
        else
        {
            $Ghosts = 0 ;
            while (($Bot = $Dbc->sql_fetch($Result)) != null)
            {
                if (!isset($this->clients[$Bot->index]))
                {
                    $Query = "
                        DELETE FROM `SyncServer`.`BotList`
                        WHERE `index` = '".$Bot->index."'
                    " ;
                    
                    #$this->log('[SQL]: ' . $Query) ;
                    $Dbc->sql_query($Query) ;
                    
                    if ($Dbc->sql_affectted_rows() > 0)
                    {
                        $Ghosts++ ;
                    }
                }
            }
            
            $Dbc->sql_freeresult($Result) ;
            
            if ($Ghosts > 0)
            {
                $this->log('[Daemon] Cleaned ' . $Ghosts . ' ghost connections from the database.') ;
            }
        }
         return ;
    }
    
    function log($message, $client_socket = null)
    {
        global $Dbc, $Sync, $runmode ;
        $message = str_replace(array("\r", "\n", "\t"), '', trim($message)) ;
        if (!empty($runmode['no-daemon']) && !$runmode['no-daemon'])
        {
            echo $message . chr(10) ;
        }
        if (count($this->clients) > 0)
        {
             reset($this->clients) ;
             $client = current($this->clients) ;
             if ($client_socket != null && $client->socket == $client_socket)
             {
                if (count($this->clients) == 1)
                {
                    return false ;
                }
                foreach ($this->client as $key => $client)
                {
                    if ($key != $client_socket)
                    {
                        $this->clients[$key]->log($message) ;
                    }
                }
             }
             else
             {
                return $client->log($message) ;
             }
        }
        return false ;
    }
    
    public function SyncToAll ($caller_socket, $message)
    {
        global $Dbc, $Sync ;
        
        $message = str_replace(array("\r", "\n", "\t"), '', trim($message)) ;
        foreach ($this->clients as $socket)
        {
            if ($socket->disconnected || !is_resource($socket->socket))
            {
                continue ;
            }
            # No need to sync to this socket
            if ($socket->socket == $caller_socket)
            {
                continue ;
            }
            $socket->write($Message . "\r\n", strlen($Message) + 2) ;
        }
        return ;
    }
    
    public function validHost ($ip, $reverse)
    {
        global $Dbc, $Sync ;
        $Query = "
            SELECT owner 
            FROM `SyncServer`.`authorized_hosts`
            WHERE `ip_address` = '".$Dbc->sql_escape($ip)."' OR hostname = '".$Dbc->sql_escape($reverse)."'
        " ;
        
        $Result = $Dbc->sql_query($Query) ;
        $Results = $Dbc->sql_num_rows($Result) ;
        $Dbc->sql_freeresult($Result) ;
        
        return ($Results > 0) ? true : false ;
    }

    static function md5sum($Filename = null)
    {
        $Shell = @shell_exec('md5sum ' . ((empty($Filename)) ? __file__ : ((@file_exists($Filename)) ? $Filename : __file__))) ;
        $Sum = substr($Shell, 0, strpos($Shell, ' ')) ;
        return trim($Sum) ;
    }

    static function ipreverse($Ip, $Display = true)
    {
        if (@preg_match('/^(?:(25[0-5]|2[0-4][\d]|[01]?[0-9][0-9])?\.){3}(?1)$/', $Ip))
        {
            $Nslookup = @shell_exec('nslookup ' . $Ip) ;
            @preg_match('#name = (.+?)[\r\n]+#i', $Nslookup, $Match) ;
            if (!empty($Match[1]))
            {
                return (($Display) ? '(Hostmask: ' . trim(substr($Match[1], 0, -1)) . ' IP: ' .
                    $Ip . ')' : trim(substr($Match[1], 0, -1))) ;
            }

            $Host = @shell_exec('host -T ' . $Ip) ;
            if (stristr($Host, 'PTR'))
            {
                return (($Display) ? '(IP: ' . $Ip . ')' : $Ip) ;
            } elseif (!stristr($Host, 'not found'))
            {
                $String = trim(substr($Host, strpos($Host, 'name pointer') + strlen('name pointer'))) ;
                return (($Display) ? '(Reverse: ' . trim(substr($String, 0, strlen($String) - 1)) . ')' : trim(substr($String, 0, strlen($String) - 1))) ;
            }
            else
            {
                return (($Display) ? '(Hostmask: Unknown IP: ' . $Ip . ')' : $Ip) ;
            }
        }
        return (($Display) ? '(IP: ' . $Ip . ')' : $Ip) ;
    }
    
    # Sync Server Static functions
    static function vsssafe($String)
    {
        return str_replace(array(':', '*'), array(chr(1), chr(4)), $String) ;
    }
    static function vssdecode($String)
    {
        return str_replace(array(chr(1), chr(4)), array(':', '*'), $String) ;
    }
}

?>
<?php
class SyncServer extends SocketServer
{
    public $clients = array() ;
    
    public function on_start ()
    {
        global $Daemon, $Dbc ;
        
        return ;
    }
    
    public function on_new (&$new)
    {   
        global $Daemon, $Dbc ;
        
        $new->age = time() ;
        
        $this->cleanSessions() ;
        # $this->SyncToAll(null, 'SESSIONSYNC:'.time()) ;
        
        #$this->cleanCache() ;
        $Daemon->cleanGhosts() ;
        #$Query = "
        #    SELECT `table`, `expiry`, `hash`, `data` 
        #    FROM `SyncServer`.`Cache`
        #    WHERE (".time()." - `time`) < 600 AND (".time()." + `expiry`) >= ".time()."
        #" ;
        
        #$Result = $Dbc->sql_query($Query) ;
        #if ($Dbc->sql_num_rows($Result) > 0)
        #{
        #    while (($Obj = $Dbc->sql_fetch($Result)) !== null)
        #    {
        #        $new->Sockwrite('SYNC ' . $Obj->table . ':' . $Obj->expiry . ':' . Daemon::vsssafe($Obj->hash) . ':' . Daemon::vsssafe($Obj->data)) ;
        #    }
        #    $Dbc->sql_freeresult($Result) ;
        #}
        
        $this->cleanBlacklist() ;
        
        $Query = "
            SELECT `network`, `channel`, `duration`, `ignore`, `who`, `reason`, `time`
            FROM `SyncServer`.`BlackList`
        " ;
        $Result = $Dbc->sql_query($Query) ;
        if ($Dbc->sql_num_rows($Result) > 0)
        {
            while (($Obj = $Dbc->sql_fetch($Result)) !== null)
            {
                $Table = ($Obj->ignore == 1) ? 'IGNORE' : 'BLACKLIST' ;
                $Output = $Table.' '.$Obj->duration.':'.time().':'.SyncClient::vsssafe($Obj->network.':'.$Obj->channel).':'.SyncClient::vsssafe($Obj->who.':'.$Obj->network.':'.$Obj->time.':'.$Obj->reason);
                $new->Sockwrite($Output) ;
            }
            $Dbc->sql_freeresult($Result) ;
        }
        return ;
    }
    
    public function SyncToAll ($caller_socket, $message)
    {
        global $Daemon, $Dbc ;
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
            $socket->write($message . "\r\n", strlen($message) + 2) ;
        }
        return ;
    }
    
    public final function cleanCache()
    {
        global $Daemon, $Dbc ;
        
        $Query = "
            DELETE FROM `SyncServer`.`Cache`
            WHERE (unix_timestamp(NOW()) - `time`) >= 3600
            AND `expiry` > 0
        " ; 
             
        $Dbc->sql_query($Query) ;
        
        return $Dbc->sql_affected_rows() ;
    }
    
    public final function cleanBlacklist()
    {
        global $Daemon, $Dbc ;
        
        $Query = "
            DELETE FROM `SyncServer`.`BlackList`
            WHERE (`time` + `duration`) <= unix_timestamp(NOW()) AND `duration` != 0
        " ; 
             
        $Dbc->sql_query($Query) ;
        
        return $Dbc->sql_affected_rows() ;
    }
     
     public final function cleanSessions()
     {
        global $Daemon, $Dbc ;    
        
        // Get the botlist
        $Query = "SELECT `session`, `name` FROM `SyncServer`.`BotList`" ;
        $Result = $Dbc->sql_query($Query) ;
        
        if ($Dbc->sql_num_rows($Result) > 0)
        {
            //clean the session list
            $Query = "DELETE FROM `SyncServer`.`SessionList`" ;
            $Dbc->sql_query($Query) ; 
            
            while (($Obj = $Dbc->sql_fetch($Result)) !== null)
            {
                $Network = trim(substr($Obj->name, 0, strpos($Obj->name, ':'))) ;
                $Query = "
                    INSERT INTO `SyncServer`.`SessionList` (`ipaddress`, `network`, `session`)
                    VALUES ('".$Dbc->sql_escape($Obj->session)."', '".$Dbc->sql_escape($Network)."', '1')
                    ON DUPLICATE KEY UPDATE `session` = `session` + 1
                " ;
                
                $Dbc->sql_query($Query) ;  
            }
            $Dbc->sql_freeresult($Result) ;           
        }
        
        return ;
     }   
}
?>
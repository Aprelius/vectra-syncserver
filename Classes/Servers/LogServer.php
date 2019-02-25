<?php
class LogServer extends SocketServer
{
    public $clients = array() ;
    
    public function on_start ()
    {
        global $Daemon, $Dbc, $Sync ;
        
        return ;
    }   
   
    public function SyncToAll ($call, $message)
    {        
        global $Daemon, $Dbc, $Sync ;
        $call = ($call == null) ? null : $call->socket ;
        $message = str_replace(array("\r", "\n", "\t"), '', trim($message)) ;
        foreach ($this->clients as $socket)
        {
            if (!is_object($socket))
            {
                continue ;
            }
            if ($socket->disconnected || !is_resource($socket->socket))
            {
                continue ;
            }
            # No need to sync to this socket
            if ($socket->socket == $call)
            {
                continue ;
            }
            $socket->Sockwrite($message) ;
        }
    }
     
    public function log ($level, $message)
    {
        global $Daemon, $Dbc, $Sync ;
        
        $this->logClear(3) ;
        
        $message = trim(str_replace(array("\r", "\n", "\t"), '', $message)) ;
        $message = preg_replace("#[\s\s]+#i", ' ', $message) ;
        
        $Query = "
            INSERT INTO ".LOG_TABLE." (`type`, `time`, `log`)
            VALUES ('".$level."', '".time()."', '".$Dbc->sql_escape($message)."')
        " ;
        
        $Dbc->sql_query($Query) ;       
        
        return $this->SyncToAll(null, $message) ;
    }   
    
    private function logClear ($num_days)
    {
        global $Daemon, $Dbc, $Sync ;
        
        $Query = "
            DELETE FROM ".LOG_TABLE." 
            WHERE (".time()." - `time`) > " . ($num_days * 86400) . "
        " ;
        
        $Dbc->sql_query($Query) ;
        return $Dbc->sql_affected_rows() ;
    }
}
?>
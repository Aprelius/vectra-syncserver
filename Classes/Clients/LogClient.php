<?php
class LogClient extends SocketServerClient
{
    public $accepted ;
    public $last_action ;
    public $index ;
    public $pinged ;
    public $ponged ;
    public $reverse ;

    public final function on_read()
    {
        while (($pos = strpos($this->read_buffer, chr(10))) !== false)
        {
            $string = trim(substr($this->read_buffer, 0, $pos + 1)) ;
            $this->read_buffer = substr($this->read_buffer, $pos + 1) ;
            $this->parse_request($string) ;
        }
        $this->read_buffer = '' ;
        return ;
    }
    
    public final function on_connect()
    {
        global $Dbc, $Daemon, $Log, $Sync ;
        $this->reverse = '127.0.0.1' ;        
        $this->Sockwrite('Connection to Vectra Sync Server Established') ;
        $this->Sockwrite('Your host is ' . SERVER . ' running version ' . VERSION) ;
        $this->index = (int)$this->socket ;
        $this->pinged = time() ;
        $this->ponged = time() ;
        $this->Sockwrite('PING ' . $this->pinged) ;            
        return ;
    }

    public final function on_disconnect()
    {
        global $Dbc, $Daemon, $Log, $Sync ;

        return ;
    }

    public final function on_write()
    {
        global $Dbc, $Daemon, $Log, $Sync ;
        $this->last_action = time() ;
        return ;
    }

    public function on_timer()
    {
        global $Dbc, $Daemon, $Log, $Sync ;
        if ($this->disconnected || !is_resource($this->socket))
        {
            return ;
        }
        if ((time() - $this->ponged) >= 90)
        {
            $Daemon->log('Possible ping timeout on Socket id ' . (int)$this->socket . '. Desync of: ' . (time() - $this->ponged) . ' seconds.') ;
            $this->on_disconnect() ;
            $this->close() ;
        }
        if ((time() - $this->pinged) >= 60)
        {
            $this->pinged = time() ;
            $this->sockwrite('PING ' . $this->pinged) ;
        }
        return ;
    }

    public function Sockwrite($Message)
    {
        return $this->write($Message . "\r\n", strlen($Message) + 2) ;
    }
    
    public final function parse_request ($Buffer) 
    {
        global $Dbc, $Daemon, $Sync ;
        $Mode = trim(substr($Buffer, 0, strpos($Buffer, ':'))) ;
        $Data = (strpos($Buffer, ':') > 0) ? explode(':', substr($Buffer, strpos($Buffer, ':') + 1)) : null ;

        if (empty($Data))
        {
            return ;
        }

        if ($Mode == 'PONG')
        {
            $Pong = (int)trim($Data[0]) ;
            if ($Pong == $this->pinged)
            {
                $this->ponged = time() ;
            }
            return ;
        }
        
        return ;
    }
}
?>
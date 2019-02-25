<?php

abstract class SocketServerClient extends ServerClient
{
    public $socket ;
    public $remote_address ;
    public $remote_port ;
    public $local_addr ;
    public $local_port ;

    public function __construct($socket)
    {
        $this->socket = $socket ;
        if (!is_resource($this->socket))
        {
            $Logger->writeLog("Invalid socket or resource", L_ERROR) ;
            return $this->close() ;
        } 
        elseif (!socket_getsockname($this->socket, $this->local_addr, $this->local_port))
        {
            $Error = "Could not retrieve local address & port: " . socket_strerror(socket_last_error($this->socket)) . "\r\n" ;
            $this->write($Error, strlen($Error)) ;
            $Logger->writeLog($Error, L_CONNECT) ;
            $this->on_disconnect() ;
            return $this->close() ;
        } 
        elseif (!socket_getpeername($this->socket, $this->remote_address, $this->remote_port))
        {
            $Error = "Could not retrieve remote address & port: " . socket_strerror(socket_last_error($this->socket)) . "\r\n" ;
            $this->write($Error, strlen($Error)) ;
            $Logger->writeLog($Error, L_CONNECT) ;
            $this->on_disconnect() ;
            return $this->close() ;
        }
        $this->set_non_block() ;
        $this->on_connect() ;
    }
}

?>
<?php

abstract class ServerClient extends Socket
{
    public $remote_address = null ;
    public $remote_port = null ;
    public $connecting = false ;
    public $disconnected = false ;
    public $read_buffer = '' ;
    public $write_buffer = '' ;

    public function connect($remote_address, $remote_port)
    {
        $this->connecting = true ;
        try
        {
            parent::connect($remote_address, $remote_port) ;
            return true ;
        }
        catch (socketException $e)
        {
            echo "Caught exception: " . $e->getMessage() . "\n" ;
            return $e ;
        }
        return true ;
    }

    public function write($buffer, $length = 4096)
    {
        $this->write_buffer .= $buffer ;
        return $this->do_write() ;
    }

    public function do_write()
    {
        $length = strlen($this->write_buffer) ;
        if (!is_resource($this->socket))
        {
            return ;
        }
        try
        {
            $lines = explode("\r\n", $this->write_buffer) ;
            foreach ($lines as $line)
            {                
                $length = strlen($line) ;
                if ($length == 0)
                {
                    $written = 0 ;
                }
                else
                {
                    $written = parent::write($line . "\r\n", $length + 2) ;
                }
                if ($written < $length)
                {
                    $this->write_buffer = substr($this->write_buffer, $written) ;
                }
                else
                {
                    $this->write_buffer = '' ;
                }
            }
            $this->on_write() ;
            return $written ;
        }
        catch (socketException $e)
        {
            $old_socket = (int)$this->socket ;
            $this->close() ;
            $this->socket = $old_socket ;
            $this->disconnected = true ;
            $this->on_disconnect() ;
            return $e ;
        }
        return false ;
    }

    public function read($length = 4096)
    {
        try
        {
            $this->read_buffer .= parent::read($length) ;
            $this->on_read() ;
        }
        catch (socketException $e)
        {
            $old_socket = (int)$this->socket ;
            $this->close() ;
            $this->socket = $old_socket ;
            $this->disconnected = true ;
            $this->on_disconnect() ;
        }
    }
    public function on_read()
    {
    }
    public function on_connect()
    {
    }
    public function on_disconnect()
    {
    }
    public function on_write()
    {
    }
    public function on_timer()
    {
    }    
}

?>
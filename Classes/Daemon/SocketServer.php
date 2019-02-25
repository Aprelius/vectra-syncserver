<?php

abstract class SocketServer extends Socket
{
    protected $client_class ;

    public function __construct($client_class, $bind_address = 0, $bind_port = 0, $domain = AF_INET, $type = SOCK_STREAM, $protocol = SOL_TCP)
    {
        parent::__construct($bind_address, $bind_port, $domain, $type, $protocol) ;
        $this->client_class = $client_class ;        
        $this->listen() ;
        $this->on_start();
    }

    public function accept()
    {
        $client = new $this->client_class(parent::accept()) ;
        if (!is_subclass_of($client, 'ServerClient'))
        {
            throw new socketException("Invalid ServerClient class specified! Has to be a subclass of ServerClient") ;
        }
        $this->on_accept($client) ;
        return $client ;
    }

    // override if desired
    public function on_accept(ServerClient $client)
    {
        return ;
    }
    
    public function on_start() {}
}

?>
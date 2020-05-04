<?php

namespace obray\httpWebSocketServer;

class Handler extends \obray\base\SocketServerBaseHandler
{
    private $activeSockets = [];
    
    public function onData(string $data, $socket, \obray\SocketServer $server): void
    {
        $request = \obray\http\Transport::decode($data);
        $responseData = "Hello World!";
        $response = new \obray\http\Transport();
        $status = new \obray\http\types\Status(\obray\http\types\Status::OK);
        $response->setStatus($status);
        $response->setHeaders(new \obray\http\Headers([
            "Content-Length" => strlen($responseData),
            "Content-Type" => "text/html",
            "Connection" => "Keep-Alive"
        ]));
        $response->setBody(\obray\http\Body::decode($responseData));
        $server->qWrite($socket, $response->encode());
    }

    public function onConnected($socket, \obray\SocketServer $server): void
    {
        print_r("we have a connection\n");
    }
}
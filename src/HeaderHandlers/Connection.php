<?php

namespace obray\httpWebSocketServer\HeaderHandlers;

class Connection
{
    public static function handle(\obray\http\Header $header, \obray\interfaces\SocketConnectionInterface $connection): void
    {
        if($header->getValue()->contains('close')) $connection->qDisconnect();
        return;
    }
}
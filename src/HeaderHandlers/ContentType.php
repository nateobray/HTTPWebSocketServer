<?php

namespace obray\httpWebSocketServer\HeaderHandlers;

class ContentType
{
    public static function handle(\obray\http\Header $header, \obray\httpWebSocketServer\Handler $handler, \obray\interfaces\SocketConnectionInterface $connection): void
    {
        print_r("\n\nHANDLING CONTENT TYPE\n\n");
        return;
    }
}
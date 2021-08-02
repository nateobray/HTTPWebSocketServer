<?php

namespace obray\httpWebSocketServer\interfaces;

/***
     * 
     * if(!empty($properties['expires'])) $this->expires = strtotime($properties['expires']);
        if(!empty($properties['secure'])) $this->secure = (bool)$properties['secure'];
        if(!empty($properties['httpOnly'])) $this->httpOnly = (bool)$properties['httpOnly'];
        if(!empty($properties['sameSite'])) $this->sameSite = $this->validateSameSite(ucwords($properties['sameSite']));
        if(!empty($properties['domain'])) $this->domain = (string)$properties['domain'];
     **/

interface MiddlewareInterface
{
    public function __construct(string $uri, \obray\http\Transport $request, \obray\ConnectionManager\Connection $conn=null, \obray\httpWebSocketServer\interfaces\SessionInterface $session=null);
    public function handle();
}
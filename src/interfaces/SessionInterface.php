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

interface SessionInterface
{
    public function __construct(string $sessionId);
    public function getExpires(): string;
    public function getSecure(): bool;
    public function getHttpOnly(): bool;
    public function getSameSite(): string;
    public function getDomain(): string;
    public function getPath(): string;
}
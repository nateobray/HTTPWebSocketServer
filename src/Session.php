<?php

namespace obray\httpWebSocketServer;

class Session implements \obray\httpWebSocketServer\interfaces\SessionInterface
{
    public $key;
    public $sessionId;
    public $isOnClient = false;

    private $lastAccess;

    public function __construct(string $key, string $sessionId=null, bool $isOnClient=true)
    {
        $this->lastAccess = time();
        $this->key = $key;
        if($sessionId===null){
            $this->generateSessionId();
            return;
        }
        $this->isOnClient = $isOnClient;
        $this->sessionId = $sessionId;
    }

    public function getAge(): int
    {
        return time() - $this->lastAccess;
    }

    private function generateSessionId()
    {
        $crypto_strong = true;
        $this->sessionId = bin2hex(random_bytes(16));
        if($crypto_strong === false) throw new \Exception("Unable to generate cryptographically strong session ID.");
    }

    public function reset()
    {
        $this->lastAccess = time();
        $this->generateSessionId();
        $this->isOnClient = false;
    }

    public function getExpires(): string
    {
        return "now +30min";
    }

    public function getSecure(): bool
    {
        return true;
    }

    public function getHttpOnly(): bool
    {
        return false;
    }

    public function getSameSite(): string
    {
        return 'Lax';
    }

    public function getDomain(): string
    {
        return '';
    }

    public function getPath(): string
    {
        return '/';
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getSessionId(): string
    {
        $this->lastAccess = time();
        return $this->sessionId;
    }

    public function isOnClient(): bool
    {
        $this->lastAccess = time();
        return $this->isOnClient;
    }
}
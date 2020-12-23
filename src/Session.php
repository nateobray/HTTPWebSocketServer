<?php

namespace obray\httpWebSocketServer;

class Session implements \obray\httpWebSocketServer\interfaces\SessionInterface
{
    public $sessionId;
    public $isOnClient = false;

    public function __construct(string $sessionId=null, bool $isOnClient=true)
    {
        print_r("Session ID: " . $sessionId . "\n");
        if($sessionId===null){
            $this->generateSessionId();
            return;
        }
        $this->isOnClient = $isOnClient;
        $this->sessionId = $sessionId;
    }

    private function generateSessionId()
    {
        $crypto_strong = true;
        $this->sessionId = bin2hex(random_bytes(16));
        if($crypto_strong === false) throw new \Exception("Unable to generate cryptographically strong session ID.");
    }

    public function reset()
    {
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

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    public function isOnClient(): bool
    {
        return $this->isOnClient;
    }
}
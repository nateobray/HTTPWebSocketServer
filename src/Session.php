<?php

namespace obray\httpWebSocketServer;

class Session implements \obray\httpWebSocketServer\interfaces\SessionInterface, \JsonSerializable
{
    public $key;
    public $id;
    public $isOnClient = false;
    protected $isAuthenticated = false;

    private $lastAccess;

    public function __construct(string $key, string $id=null, bool $isOnClient=false)
    {
        $this->lastAccess = time();
        $this->key = $key;
        if($id===null){
            $this->id = $this->generateSessionId();
            return;
        }
        $this->isOnClient = $isOnClient;
        $this->id = $id;
    }

    public function isAuthenticated(bool $isAuthenticated=null): bool
    {
        if($isAuthenticated !== null) $this->isAuthenticated = $isAuthenticated;
        return $this->isAuthenticated;
    }

    public function getAge(): int
    {
        return time() - $this->lastAccess;
    }

    private function generateSessionId($data=null)
    {
        // Generate 16 bytes (128 bits) of random data or use the data passed into the function.
        $data = $data ?? random_bytes(16);
        assert(strlen($data) == 16);
    
        // Set version to 0100
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        // Set bits 6-7 to 10
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    
        // Output the 36 character UUID.
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public function reset()
    {
        $this->lastAccess = time();
        $this->generateSessionId();
        $this->isOnClient = false;
    }

    public function getExpires(): string
    {
        return "now";
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

    public function getId(): string
    {
        $this->lastAccess = time();
        return $this->id;
    }

    public function isOnClient(): bool
    {
        $this->lastAccess = time();
        return $this->isOnClient;
    }

    public function __toString()
    {
        $expires = new \DateTime(null, new \DateTimeZone("America/Denver"));
        $expires->modify("+30min");
        print_r($expires->format("D, d M Y H:i:s e") . "\n");
        $expires->setTimezone(new \DateTimeZone("GMT"));
        print_r($expires->format("D, d M Y H:i:s e") . "\n");
        return $this->key . '=' . $this->id . '; SameSite=' . $this->getSameSite() . '; Path=' . $this->getPath() . '; Expires=' . $expires->format("D, d M Y H:i:s e");
    }

    public function jsonSerialize()
    {
        return $this;
    }
}
<?php

namespace obray\httpWebSocketServer;

class Handler extends \obray\base\SocketServerBaseHandler
{
    private $cache = [];
    private $activeSockets = [];
    
    public function onData(string $data, $socket, \obray\SocketServer $server): void
    {
        $time = microtime(true);
        if(empty($data)) return;
        $request = \obray\http\Transport::decode($data);
        $response = $this->getResponse($request);
        $server->qWrite($socket, $response->encode());
        $endTime = microtime(true) - $time;
    }

    /**
     * Get Response
     * 
     * attempt to find resource matching the request URI.  If found it returns a
     * transport object as a response.
     */

    private function getResponse(\obray\http\Transport $request): \obray\http\Transport
    {
        // get the request URI
        $uri = $request->getURI();
        if(empty($uri)) $uri = "/"; // normalize URI

        // check for static content
        if($request->getMethod() == 'GET' && $response = $this->getStatic($uri)){
            return $response;
        }

        // check for root route
        if($response = $this->getRoot($uri, $request)){
            return $response;
        }
        
        // check for defined routes
        if($response = $this->getDefinedRoute($request, $uri)){
            return $response;
        }

        // all else fails return not found response
        return \obray\HttpWebSocketServer\Handler::request("Not Found", \obray\http\types\Status::NOT_FOUND);
    }

    /**
     * Get Static
     * 
     * Attempt to find a static file corresponding to the supplied URI and the 
     * allowed MIME types.
     */

    private function getStatic(string $uri)
    {
        if(file_exists(__ROOT__."/static" . $uri)) { 
            $body = file_get_contents(__ROOT__."/static" . $uri);
            $size = filesize(__ROOT__."/static" . $uri);    
            $this->cache[__ROOT__."/static" . $uri] = $body;
        // load static file with URI plus specified index file
        } else if (file_exists(__ROOT__."/static" . $uri . __INDEX__)) {
            $body = file_get_contents(__ROOT__."/static" . $uri . __INDEX__);
            $size = filesize(__ROOT__."/static" . $uri . __INDEX__);
            $this->cache[__ROOT__."/static" . $uri . __INDEX__] = $body;
        // can't load file return false
        } else {
            return false;
        }
        
        // build & return response
        $response = new \obray\http\Transport();
        $mime = (new \obray\http\types\MIME())->getSetMimeFromExtension($uri);
        $status = new \obray\http\types\Status(\obray\http\types\Status::OK);
        $response->setStatus($status);
        $response->setHeaders(new \obray\http\Headers([
            "Content-Length" => $size,
            "Content-Type" => $mime,
            "Connection" => "Keep-Alive"
        ]));
        $response->setBody(\obray\http\Body::decode($body));
        return $response;
    }

    /**
     * Get Root
     * 
     * Retrieves the root route
     */

    private function getRoot(string $uri, \obray\http\Transport $request)
    {
        if ($uri == "/" && class_exists("\\routes\\Root")){
            $root = new \routes\Root();
            $function = strtolower($request->getMethod());
            if(method_exists($root, $function)){
                return $root->$function();
            }
        }
        return false;
    }

    /**
     * Get Defined Route
     * 
     * Searches for a defined route the matches specified URI.  It does this by
     * traversing the path (longest to shortest path) finding a matching class
     * and passing the remaining path into the constructor
     */

    private function getDefinedRoute(\obray\http\Transport $request, string $uri)
    {
        $path = explode('/', $uri); $remaining = []; $max = 10; $length = 0;
        $path = array_filter($path);
        while(count($path)>0){
            ++$length;
            if(class_exists('\\routes\\' . implode('\\', $path))){
                $class = '\\routes\\' . implode('\\', $path);
                $definedRoute = new $class($remaining);
                $function = strtolower($request->getMethod());
                if(method_exists($definedRoute, $function)){
                    return $definedRoute->$function();
                }
            } else {
                $remaining[] = array_pop($path);
            }
            if($length > $max) return false;
        }
        return false;
    }

    public static function request(string $responseData, int $status=\obray\http\types\Status::OK): \obray\http\Transport
    {
        $response = new \obray\http\Transport();
        $status = new \obray\http\types\Status($status);
        $response->setStatus($status);
        $response->setHeaders(new \obray\http\Headers([
            "Content-Length" => mb_strlen($responseData),
            "Content-Type" => "text/plain",
            "Connection" => "Keep-Alive"
        ]));
        $response->setBody(\obray\http\Body::decode($responseData));
        return $response;
    }

    public function onConnect($socket, \obray\SocketServer $server): void
    {
        return;
    }

    public function onConnected($socket, \obray\SocketServer $server): void
    {
        return;
    }

    public function onWriteFailed($data, $socket, \obray\SocketServer $server): void
    {
        return;
    }

    public function onDisconnect($socket, \obray\SocketServer $server): void
    {
        return;
    }

    public function onDisconnected($socket, \obray\SocketServer $server): void
    {
        return;
    }

}
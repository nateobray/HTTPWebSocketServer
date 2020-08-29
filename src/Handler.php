<?php

namespace obray\httpWebSocketServer;

class Handler extends \obray\base\SocketServerBaseHandler
{
    private $cache = [];
    private $activeSockets = [];

    public function __construct($root, $index)
    {
        $this->root = $root;
	$this->index = $index;
    }

    public function onData(string $data, \obray\interfaces\SocketConnectionInterface $connection): void
    {
        $time = microtime(true);
        if(empty($data)) return;
        try {
            $request = \obray\http\Transport::decode($data);
        } catch (\Exception $e) {
            print_r($e->getMessage()."\n");
            return;
        }

        $this->processMeaningfulHeaders($request->getHeaders(), $connection);

        $response = $this->getResponse($request);
        $connection->qWrite($response->encode());
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
            print_r("get defined\n");
            return $response;
        }

        // all else fails return not found response
        return \obray\HttpWebSocketServer\Handler::response("Not Found", \obray\http\types\Status::NOT_FOUND);
    }

    /**
     * Get Static
     *
     * Attempt to find a static file corresponding to the supplied URI and the
     * allowed MIME types.
     */

    private function getStatic(string $uri)
    {
	    $file = str_replace('//','/',$this->root."/static" . $uri);
        $dir = str_replace('//','/',$this->root."static" . $uri . $this->index);
        if(file_exists($file) && !is_dir($file)) {
            $body = file_get_contents($file);
            $size = strlen($body);
            //$this->cache[$file] = $body;
        // load static file with URI plus specified index file
        } else if (file_exists($dir)) {
            $body = file_get_contents($dir);
            $size = strlen($body);
            //$this->cache[$dir] = $body;
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
                $definedRoute = new $class($remaining, $this);
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

    public static function response(string $responseData, int $status=\obray\http\types\Status::OK, string $contentType=\obray\http\types\MIME::TEXT): \obray\http\Transport
    {
        print_r($responseData);
        $response = new \obray\http\Transport();
        $status = new \obray\http\types\Status($status);
        $response->setStatus($status);
        $response->setHeaders(new \obray\http\Headers([
            "Content-Length" => strlen($responseData),
            "Content-Type" => $contentType,
            "Connection" => "Keep-Alive"
        ]));
        $response->setBody(\obray\http\Body::decode($responseData));
        return $response;
    }

    public function onConnect(\obray\interfaces\SocketConnectionInterface $connection): void
    {
        return;
    }

    public function onConnected(\obray\interfaces\SocketConnectionInterface $connection): void
    {
        return;
    }

    public function onConnectFailed(\obray\interfaces\SocketConnectionInterface $connection): void
    {
        print_r("failed!\n");
    }

    public function onWriteFailed($data, \obray\interfaces\SocketConnectionInterface $connection): void
    {
        print_r("Write Failed\n");
        return;
    }

    public function onDisconnect(\obray\interfaces\SocketConnectionInterface $connection): void
    {
        return;
    }

    public function onDisconnected(\obray\interfaces\SocketConnectionInterface $connection): void
    {
        return;
    }

    private function processMeaningfulHeaders(\obray\http\Headers $headers, \obray\interfaces\SocketConnectionInterface $connection)
    {
        forEach($headers as $index => $header){
            $className = '\obray\httpWebSocketServer\HeaderHandlers\\'.$header->getClassName();
            if(class_exists($className)){
                $className::handle($header, $connection);
            }
        }
    }

}

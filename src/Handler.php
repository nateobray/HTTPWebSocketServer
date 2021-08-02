<?php

namespace obray\httpWebSocketServer;

class Handler extends \obray\base\SocketServerBaseHandler
{
    private $cache = [];
    private $activeSockets = [];
    private $webSocketConns = [];
    private $webSockets = [];
    private $shouldCache = false;
    private $continuations = [];
    private $continuationRequests = [];
    private $requests = [];

    private $sessionHandler;
    private $sessionKey;
    private $sessions = [];

    private $middleware = [];

    public function registerMiddleware(string $path=null, array $middlewares)
    {
        forEach($middlewares as $middleware){
            if(!class_exists($middleware)) throw new \Exception("Registered Middleware (" . $middleware . ") does not exist");
        }
        $this->middleware[$path] = $middlewares;
    }

    public function __construct($root, $index, $shouldCache=false)
    {
        $this->root = $root;
        $this->index = $index;
        $this->shouldCache = $shouldCache;
    }

    public function onStart(\obray\SocketServer $server): void
    {
        print_r("Starting Server...\n");
        $this->sessionWatcher = $server->eventLoop->watchTimer(0, 15, function($watcher){
            print_r("Attempting to cleanup sessions.\n");
            $sessionsCleaned = 0;
            forEach($this->sessions as $key => $session){
                if($session->getAge() > (5 * 60)){ 
                    print_r("unsetting session\n");
                    unset($this->sessions[$key]);
                    ++$sessionsCleaned;
                }
            }
            print_r("Sessions cleaned: " . $sessionsCleaned . "\n");
        });
    }

    /**
     * On Data
     * 
     * This is called when data is received by the socket server and reading is
     * completed, the read data is passed here with the connection
     */

    public function onData(string $data, int $readLength, \obray\interfaces\SocketConnectionInterface $connection)
    {

        // handle body content
        if(!empty($this->requests[(int)$connection->getSocket()]) && $this->requests[(int)$connection->getSocket()]->isComplete()){

            try {
                $contentEncoding = $this->requests[(int)$connection->getSocket()]->getHeaders("Content-Encoding");
                $body = \obray\http\Body::decode($data, $contentEncoding);
            } catch(\Exception $e) {
                $body = \obray\http\Body::decode($data);
            }

            $contentType = $this->requests[(int)$connection->getSocket()]->getHeaders("Content-Type");
            $body->parseFormat($contentType);
            $this->requests[(int)$connection->getSocket()]->setBody($body);

            // no content length found, check for chunked encoding next
            $this->requests[(int)$connection->getSocket()]->complete();

            $dbh = $this->pool->dbh??null;
            $response = $this->getResponse($this->requests[(int)$connection->getSocket()], $dbh, $connection);
            if(!empty($this->pool)) $this->pool->release($dbh);

            // write response to network
            $connection->qWrite($response->encode());

            // show request duration
            print_r("Run time: " . number_format($this->requests[(int)$connection->getSocket()]->getDuration(), 3, '.', ',') . "ms " . $this->requests[(int)$connection->getSocket()]->getURI() . "\n");
            
            // destroy request
            unset($this->requests[(int)$connection->getSocket()]);
            
            return 0;

        }

        // handle end of headers section
        if(!empty($this->requests[(int)$connection->getSocket()]) && empty($data)){   

            try {

                // get content length & determine if body exists
                $contentLength = $this->requests[(int)$connection->getSocket()]->getHeaders("Content-Length");
                $contentLength = intVal($contentLength->getValue()->encode());
                if($contentLength === 0){
                    throw new \Exception('no body found.');
                }
                // if body found complete the request so we know to process the body
                $this->requests[(int)$connection->getSocket()]->complete();
                // read the rest of the request specified by Content-Length header
                $connection->setReadMethod(\obray\SocketConnection::READ_UNTIL_LENGTH);
                // return content length so we know the length to read
                return $contentLength;

            } catch(\Exception $e){
                
                // no content length found, check for chunked encodeing next
                $this->requests[(int)$connection->getSocket()]->complete();

                $dbh = $this->pool->dbh??null;
                $response = $this->getResponse($this->requests[(int)$connection->getSocket()], $dbh, $connection);
                if(!empty($this->pool)) $this->pool->release($dbh);
                
                // write response to network
                $connection->qWrite($response->encode());

                // show request duration
                print_r("Run time: " . number_format($this->requests[(int)$connection->getSocket()]->getDuration(), 3, '.', ',') . "ms " . $this->requests[(int)$connection->getSocket()]->getURI() . "\n");

                // destroy request
                unset($this->requests[(int)$connection->getSocket()]);
                
                return false;
            }
        }

        // parse header
        if(!empty($this->requests[(int)$connection->getSocket()])){
            $header = \obray\http\Header::decode($data);
            $this->requests[(int)$connection->getSocket()]->addHeader($header);
        }

        // decode Protocol
        if(empty($this->requests[(int)$connection->getSocket()])){
            $time = microtime(true);
            if(empty($data)) return; // if nothing to parse, don't bother
            $this->requests[(int)$connection->getSocket()] = \obray\http\Transport::decodeProtocolRequest($data);
        }

    }

    /**
     * Handle WebSocket Messages
     *
     * Interpret the type of message and call the corresponding handler function
     */
    
    public function onMessage(int $opcode, string $msg, \obray\interfaces\SocketConnectionInterface $connection)
	{
		switch($opcode) {
			case \obray\WebSocketFrame::TEXT:
				$this->onText($msg, $connection);
				break;
			case \obray\WebSocketFrame::BINARY:
				$this->onBinary($msg, $connection);
				break;
			case \obray\WebSocketFrame::CLOSE:
				$this->onClose($connection);
				break;
			case \obray\WebSocketFrame::PING:
				$this->onPing($connection);
				break;
			case \obray\WebSocketFrame::PONG:
				$this->onPong($connection);
				break;
		}
	}

    /**
     * Get Response
     *
     * attempt to find resource matching the request URI.  If found it returns a
     * transport object as a response.
     */

    private function getResponse(\obray\http\Transport $request, \obray\ConnectionManager\Connection $conn=null, \obray\interfaces\SocketConnectionInterface $connection): \obray\http\Transport
    {
        // get the request URI
        $uri = $request->getURI();
        if(empty($uri)) $uri = "/"; // normalize URI
        
        // retreive session data
        $session = $this->findSession($request);

        // invoke any middleware
        forEach($this->middleware as $path => $arrayM){
            forEach($arrayM as $m){
                $middle = new $m($uri, $request, $conn, $session);
                $middlewareResponse = $middle->handle();
                print_r("Middleware Response\n");
                print_r($middlewareResponse);
                $path = rtrim($path, '/*');
                $testUri = rtrim($uri, '/');
                if(is_object($middlewareResponse) && get_class($middlewareResponse) === "obray\http\Transport" && (strpos($testUri, $path) === 0 || $testUri === $path)) {
                    $response = $middlewareResponse;
                }
            }
        }

        // check cache for content
        print_r("getting cached response\n");
        if(empty($response) && $this->shouldCache && !empty($this->cache[$uri])){
            $response = $this->getCached($uri);
        }

        // check if this is a websocket connection
        print_r("Getting WebSocket connection\n");
        if(empty($response)){
            $response = $this->getWebSocket($request, $uri, $connection);
            if(!empty($this->pool)) $this->pool->release($conn);
        }
        
        // check for static content
        print_r("getting static response\n");
        if(empty($response) && $request->getMethod() == 'GET'){
            $response = $this->getStatic($uri);
        }
        
        // check for root route
        print_r("getting root response\n");
        if(empty($response)){
            $response = $this->getRoot($uri, $request, $conn, $session);
            if(!empty($this->pool)) $this->pool->release($conn);
        }
        
        // check for defined routes
        print_r("getting defined response\n");
        if(empty($response)){
            $response = $this->getDefinedRoute($request, $uri, $conn, $session);
            if(!empty($this->pool)) $this->pool->release($conn);
        }

        print_r("Setting Session Cookie if needed\n");
        if(!empty($response)) {
            if(!$session->isOnClient()) {
                $response->addHeader(new \obray\http\Header('Set-Cookie', (string)$session));
                $session->isOnClient = true;
            }
            return $response;
        }

        // all else fails return not found response
        return \obray\http\Response::respond(
            \obray\http\types\Status::NOT_FOUND,
            ['Content-Type' => \obray\http\types\MIME::TEXT]
        );
    }

    /**
     * 
     * 
     */

    private function getWebSocket(\obray\http\Transport $request, string $uri, \obray\interfaces\SocketConnectionInterface $connection)
    {
        // validate this is websocket initiation by the headers
        try{
            $connectionHeader = $request->getHeaders('Connection');
            $upgradeHeader = $request->getHeaders('Upgrade');
        } catch (\Exception $e) {
            print_r($e->getMessage()."\n");
            return false;
        }
        //if(empty($connectionHeader) || strtolower($connectionHeader) != 'upgrade' || empty($upgradeHeader) || strtolower($upgradeHeader) != 'websocket') return false;
        print_r("Checking WebSocket Headers\n");
        // validate WebSocket headers
        $secWebSocketKey = $request->getHeaders('Sec-WebSocket-Key');
        if(strlen(base64_decode($secWebSocketKey->__toString())) !== 16) return false;
        $secWebSocketVersion = $request->getHeaders('Sec-WebSocket-Version');
        if($secWebSocketVersion->__toString() !== '13') return false;
        $secWebSocketExtensions = $request->getHeaders('Sec-WebSocket-Extensions');
        if(empty($secWebSocketExtensions)) return false;
        
        // create and save our WebSocket connection
        $this->webSocketConns[] = $connection;
        $this->webSockets[] = new \obray\WebSocket($secWebSocketKey, $secWebSocketVersion, $secWebSocketExtensions);
        
        // send appropriate WebSocket response
        return \obray\http\Response::respond(
            \obray\http\types\Status::SWITCHING_PROTOCOLS,
            [
                'Upgrade' => 'websocket',
                'Connection' => 'Upgrade',
                'Sec-WebSocket-Accept' => base64_encode(pack('H*', sha1($secWebSocketKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')))
            ]
        );
    }

    /**
     * Get Cached
     *
     * Attempt to find the content for the URI in an in memory array of content
     */

    private function getCached(string $uri)
    {
        $body = $this->cache[$uri];
        $size = strlen($body);

        return \obray\http\Response::respond(
            \obray\http\types\Status::OK,
            ['Content-Type' => \obray\http\types\MIME::getSetMimeFromExtension($uri)],
            $body
        );
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
        $dir = str_replace('//','/',$this->root."static" . '/' . trim($uri, '/') . '/' . $this->index);
        
        // load static file with URI
        if(file_exists($file) && !is_dir($file)) {
            $body = file_get_contents($file);
            $size = strlen($body);
            $this->cache[$uri] = $body;
        // load static file with URI plus specified index file
        } else if (file_exists($dir)) {
            $body = file_get_contents($dir);
            $size = strlen($body);
            $this->cache[$uri] = $body;
        // can't load file return false
        } else {
            return false;
        }
        return \obray\http\Response::respond(
            \obray\http\types\Status::OK,
            [
                'Content-Type' => \obray\http\types\MIME::getSetMimeFromExtension($uri),
                'Cache-Control' => 'Cache-Control: public, max-age=604800, immutable'
            ],
            $body
        );
    }

    /**
     * Get Root
     *
     * Retrieves the root route
     */

    private function getRoot(string $uri, \obray\http\Transport $request, \obray\ConnectionManager\Connection $conn=null, \obray\httpWebSocketServer\interfaces\SessionInterface $session)
    {
        if ($uri == "/" && class_exists("\\routes\\Root")){
            $root = new \routes\Root();
            $function = strtolower($request->getMethod());
            if(method_exists($root, $function)){
                return $root->$function($request, $this, $conn, $session);
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

    private function getDefinedRoute(\obray\http\Transport $request, string $uri, \obray\ConnectionManager\Connection $conn=null, \obray\httpWebSocketServer\interfaces\SessionInterface $session)
    {
        $path = explode('/', $uri); $remaining = []; $max = 10; $length = 0;
        $path = array_filter($path);
        while(count($path)>0){
            ++$length;
            print_r('\\routes\\' . implode('\\', $path) . "\n");
            if(class_exists('\\routes\\' . implode('\\', $path))){
                $class = '\\routes\\' . implode('\\', $path);
                $definedRoute = new $class($remaining, $this);
                
                $function = strtolower($request->getMethod());
                
                if(method_exists($definedRoute, $function)){
                    
                    return $definedRoute->$function($request, $this, $conn, $session);
                }
            } else {
                $remaining[] = array_pop($path);
            }
            if($length > $max) return false;
        }
        return false;
    }

    public function setSessionsHandler(string $key, string $sessionHandler)
    {
        $this->sessionHandler = $sessionHandler;
        $this->sessionKey = $key;
    }

    public function findSession(\obray\http\Transport $request)
    {
        print_r("Finding Session\n");
        try {
            print_r("Trying to get cookie header\n");
            $cookies = $request->getHeaders('Cookie');
            try {
                print_r("Trying to get cookie value " . $this->sessionKey . "\n");
                $cookie = $cookies->getPairValue($this->sessionKey);
                if(!empty($this->sessions[$cookie])){
                    print_r("Session found: " . $cookie . "\n");
                    return $this->sessions[$cookie];
                } else {
                    print_r("No session found with: " . $cookie . "\n");
                }
            } catch (\Exception $e) {
                print_r("valid session ID not found\n");
                print_r($e->getMessage());
            }
        } catch(\Exception $e) {
            print_r("No cookie sent\n");
            print_r($e->getMessage());
        }
        print_r("Session not found, creating new one\n");
        $session = new $this->sessionHandler($this->sessionKey);
        $this->sessions[$session->getId()] = $session;
        return $this->sessions[$session->getId()];
    }

    public function getSessions(): array
    {
        return $this->sessions;
    }

    public function onConnect(\obray\interfaces\SocketConnectionInterface $connection): void
    {
        return;
    }

    public function onConnected(\obray\interfaces\SocketConnectionInterface $connection): void
    {
        $connection->setReadMethod(\obray\SocketConnection::READ_UNTIL_LINE_ENDING);
        $connection->setEOL("\r\n");
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

    /**
	 * On Disconnect
	 * 
	 * Required to implement SocketServerHandlerInterface and allows us to clean
	 * up closed socket connections.
	 */
	
	public function onDisconnect(\obray\interfaces\SocketConnectionInterface $connection): void
    {
		if(!empty($this->handler) && method_exists($this->handler, 'onDisconnect')){
			$this->handler->onDisconnect($connection);
		}
		$index = array_search($connection, $this->webSockets);
		if($index !== false) {
			unset($this->webSockets[$index]);
		}
	}

    public function onDisconnected(\obray\interfaces\SocketConnectionInterface $connection): void
    {
        return;
    }

    public function setConnectionPool(\obray\ConnectionManager\Pool $pool)
    {
        $this->pool = $pool;
    }

    public function onText(string $msg, \obray\interfaces\SocketConnectionInterface $connection)
    {
        print_r("Received: " . $msg . "\n");
    }

    public function onBinary(string $msg, \obray\interfaces\SocketConnectionInterface $connection)
    {
        print_r("Received binary message\n");
    }

    public function onClose(\obray\interfaces\SocketConnectionInterface $connection)
    {
        print_r("Received a close frame\n");
    }

    public function onPing(\obray\interfaces\SocketConnectionInterface $connection)
    {
        print_r("Received a ping frame\n");
    }

    public function onPong(\obray\interfaces\SocketConnectionInterface $connection)
    {
        print_r("Received a pong frame\n");
    }

}

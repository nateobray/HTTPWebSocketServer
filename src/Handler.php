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

    private $sessionTypes;
    private $sessions = [];

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

    public function onData(string $data, \obray\interfaces\SocketConnectionInterface $connection): void
    {
        // if no data received simply return
        if(empty($data)) return;
        // begin timing
        $time = microtime(true);
        // check if we're expecting a continuation on this connection
        $index = array_search($connection, $this->continuations);
        if($index !== false){
            print_r("Process continuation\n");
            $request = $this->continuationRequests[$index];
            $request->setBody(\obray\http\Body::decode($data));
            unset($this->continuations[$index]);
            unset($this->continuationRequests[$index]);
        }
        // check if this is one of our websocket connections
        $index = array_search($connection, $this->webSocketConns);
        if($index !== false){
            print_r("\n\nWebsocket connection incoming\n");
            $this->webSockets[$index]->decode($data, $connection, [$this, 'onMessage']);
            $duration = microtime(true) - $time;
            print_r("Run time: " . number_format($duration*1000, 3, '.', ',') . "ms websocket request\n");
            return;
        }

        // attempt to decode our data into a HTTP transport
        if(empty($request)){
            try {
                $request = \obray\http\Transport::decode($data);
                if((string)$request->getHeaders('Expect') === '100-continue'){
                    $this->continuations[] = $connection;
                    $this->continuationRequests[] = $request;
                    $response = \obray\http\Response::respond(\obray\http\types\Status::CONTINUE);
                    $connection->qWrite($response->encode());
                    return;
                }
            } catch (\Exception $e) {
                print_r($e->getMessage()."\n");
                return;
            }
        }
        // retreive sessions defined in our request        
        $this->getSessions($request);
        // get the request response
        
        $dbh = $this->pool->dbh??null;
        $response = $this->getResponse($request, $dbh, $connection);
        if(!empty($this->pool)) $this->pool->release($dbh);

        // write response to network
        $connection->qWrite($response->encode());
        // show request duration
        $duration = microtime(true) - $time;
        print_r("Run time: " . number_format($duration*1000, 3, '.', ',') . "ms " . $request->getURI() . "\n");
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

        // check cache for content
        print_r("getting cached response\n");
        if($this->shouldCache && !empty($this->cache[$uri]) && $response = $this->getCached($uri)){
            $response->addSessionCookies($this->getSessionCookies($request));
            return $response;
        }

        // check if this is a websocket connection
        print_r("Getting WebSocket connection\n");
        if($response = $this->getWebSocket($request, $uri, $connection)){
            if(!empty($this->pool)) $this->pool->release($conn);
            return $response;
        }
        
        // check for static content
        print_r("getting static response\n");
        if($request->getMethod() == 'GET' && $response = $this->getStatic($uri)){
            $response->addSessionCookies($this->getSessionCookies($request));
            return $response;
        }
        
        // check for root route
        print_r("getting root response\n");
        if($response = $this->getRoot($uri, $request, $conn)){
            $response->addSessionCookies($this->getSessionCookies($request));
            if(!empty($this->pool)) $this->pool->release($conn);
            return $response;
        }
        
        // check for defined routes
        print_r("getting defined response\n");
        if($response = $this->getDefinedRoute($request, $uri, $conn)){
            $response->addSessionCookies($this->getSessionCookies($request));
            if(!empty($this->pool)) $this->pool->release($conn);
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
        $connectionHeader = $request->getHeaders('Connection');
        $upgradeHeader = $request->getHeaders('Upgrade');
        if(empty($connectionHeader) || strtolower($connectionHeader) != 'upgrade' || empty($upgradeHeader) || strtolower($upgradeHeader) != 'websocket') return false;
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

    private function getRoot(string $uri, \obray\http\Transport $request, \obray\ConnectionManager\Connection $conn=null)
    {
        if ($uri == "/" && class_exists("\\routes\\Root")){
            $root = new \routes\Root();
            $function = strtolower($request->getMethod());
            if(method_exists($root, $function)){
                return $root->$function($request, $this, $conn);
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

    private function getDefinedRoute(\obray\http\Transport $request, string $uri, \obray\ConnectionManager\Connection $conn=null)
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
                    return $definedRoute->$function($request, $this, $conn);
                }
            } else {
                $remaining[] = array_pop($path);
            }
            if($length > $max) return false;
        }
        return false;
    }

    public function setSessionsTypes(array $sessionType)
    {
        $this->sessionTypes = $sessionType;
    }

    public function refreshSession($key, &$request)
    {
        $sessionType = $this->sessionTypes[$key];
        $s = new $sessionType($key);
        $this->sessions[$s->getSessionId()] = $s;
        $request->setSessionId($key, $s->getSessionId());
    }

    public function getSession(string $key, \obray\http\Transport $request)
    {
        $sessionIds = $request->getSessions();
        return $this->sessions[$sessionIds[$key]];
    }

    public function getSessions(\obray\http\Transport &$request)
    {
        if(empty($this->sessionTypes)) return;
        forEach($this->sessionTypes as $key => $sessionType){
            $cookie = $request->getCookie($key);
            if($cookie && $sessionId = $cookie->getValue()){
                if(empty($this->sessions[$sessionId])){
                    $this->sessions[$sessionId] = new $sessionType($key, $sessionId);
                }
                $sessionIds[$key] = $sessionId;
                continue;
            }
            $s = new $sessionType($key);
            $this->sessions[$s->getSessionId()] = $s;
            $sessionIds[$key] = $s->getSessionId();
        }
        $request->setSessions($sessionIds);
    }

    public function getSessionCookies(\obray\http\Transport $request)
    {
        
        $sessionIds = $request->getSessions();   
        if(empty($sessionIds)) return [];
        $requestCookies = [];
        forEach($sessionIds as $id){
            if(!$this->sessions[$id]->isOnClient()) {
                $requestCookies[] = new \obray\http\Cookie($this->sessions[$id]->getKey(), $this->sessions[$id]->getSessionId(), [
                    'expires' => $this->sessions[$id]->getExpires(),
                    'secure' => $this->sessions[$id]->getSecure(),
                    'httpOnly' => $this->sessions[$id]->getHttpOnly(),
                    'sameSite' => $this->sessions[$id]->getSameSite(),
                    'path' => $this->sessions[$id]->getPath()
                ]);
            }
        }
        return $requestCookies;
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

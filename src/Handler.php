<?php

namespace obray\httpWebSocketServer;

class Handler extends \obray\base\SocketServerBaseHandler
{
    private $cache = [];
    private $activeSockets = [];
    private $shouldCache = false;

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

    public function onData(string $data, \obray\interfaces\SocketConnectionInterface $connection): void
    {
        $time = microtime(true);
        if(empty($data)) return;
        // attempt to decode our data into a HTTP transport
        try {
            $request = \obray\http\Transport::decode($data);
        } catch (\Exception $e) {
            print_r($e->getMessage()."\n");
            return;
        }
        // retreive sessions defined in our request        
        $this->getSessions($request);
        // get the request response
        $response = $this->getResponse($request);
        // write response to network
        $connection->qWrite($response->encode());
        // show request duration
        $duration = microtime(true) - $time;
        print_r("Run time: " . number_format($duration*1000, 3, '.', ',') . "ms " . $request->getURI() . "\n");
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

        // check cache for content
        if($this->shouldCache && !empty($this->cache[$uri]) && $response = $this->getCached($uri)){
            $response->addSessionCookies($this->getSessionCookies($request));
            return $response;
        }
        
        // check for static content
        if($request->getMethod() == 'GET' && $response = $this->getStatic($uri)){
            $response->addSessionCookies($this->getSessionCookies($request));
            return $response;
        }
        
        // check for root route
        if($response = $this->getRoot($uri, $request)){
            $response->addSessionCookies($this->getSessionCookies($request));
            return $response;
        }
        
        // check for defined routes
        if($response = $this->getDefinedRoute($request, $uri)){

            $response->addSessionCookies($this->getSessionCookies($request));
            return $response;
        }

        // all else fails return not found response
        return \obray\http\Response::respond(
            \obray\http\types\Status::NOT_FOUND,
            ['Content-Type' => \obray\http\types\MIME::TEXT]
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
        $dir = str_replace('//','/',$this->root."static" . $uri . $this->index);
        
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
            ['Content-Type' => \obray\http\types\MIME::getSetMimeFromExtension($uri)],
            $body
        );
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
            print_r('\\routes\\' . implode('\\', $path) . "\n");
            if(class_exists('\\routes\\' . implode('\\', $path))){
                $class = '\\routes\\' . implode('\\', $path);
                $definedRoute = new $class($remaining, $this);
                $function = strtolower($request->getMethod());
                if(method_exists($definedRoute, $function)){
                    return $definedRoute->$function($request, $this);
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
        print_r($sessionType . "\n");
        print_r("blah\n");
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

    public function onDisconnect(\obray\interfaces\SocketConnectionInterface $connection): void
    {
        return;
    }

    public function onDisconnected(\obray\interfaces\SocketConnectionInterface $connection): void
    {
        return;
    }

}

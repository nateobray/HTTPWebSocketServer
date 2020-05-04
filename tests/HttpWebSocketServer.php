<?php

error_reporting(E_ALL);
ini_set("display_errors", 1);

include "vendor/autoload.php";

$context = new \obray\StreamContext();
$socketServer = new \obray\SocketServer('tcp', '172.31.36.192', 3100, $context);
$socketServer->showServerStatus(false);
$httpWebSocketHandler = new \obray\httpWebSocketServer\Handler();
$socketServer->registerhandler($httpWebSocketHandler);
$socketServer->start();

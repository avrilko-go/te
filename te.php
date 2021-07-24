<?php

use Te\Server;
use Te\TcpConnection;
use Te\UdpConnection;

require_once dirname(__FILE__) . '/vendor/autoload.php';

$server = new Server("http://0.0.0.0:2345");
$server->setting(
    [
        "workNum" => 1,
        "taskNum" => 2,
        "daemon" => false,
        'task' => [
            'unix_socket_server_file' => '/tmp/server.socket',
            'unix_socket_client_file' => '/tmp/client.socket'
        ]
    ]
);

$server->on(
    "masterStart",
    function (Server $server) {
//        var_dump("主进程开启");
    }
);

$server->on(
    "masterStop",
    function (Server $server) {
//        var_dump("主进程退出");
    }
);

$server->on(
    "workerStart",
    function (Server $server) {
//        var_dump("子进程启动");
    }
);

$server->on(
    "workerStop",
    function (Server $server) {
//        var_dump("子进程退出");
    }
);

$server->on(
    "workerReload",
    function (Server $server) {
//        var_dump("子进程重启了");
    }
);

$server->on(
    "connect",
    function (Server $server, TcpConnection $connection) {
        $server->echoLog("有客户端连接了 pid = %d %s", posix_getpid(), $connection->clientIp);
    }
);

$server->on(
    'receive',
    function (Server $server, string $data, TcpConnection $connection) {
        $server->echoLog("receive from client：%d data:%s", (int)$connection->getSocketFd(), $data);
//        $server->task(
//            function () {
//                sleep(5);
//            }
//        );
        $connection->send("ababababa");
    }
);

$server->on(
    'task',
    function (Server $server, UdpConnection $udpConnection, string $data) {
    }
);

$server->on(
    'close',
    function (Server $server, TcpConnection $connection) {
        $server->echoLog("client ip %s close", $connection->clientIp);
    }
);
$server->start();



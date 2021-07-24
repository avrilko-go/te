<?php

use Te\Client;

require_once dirname(__FILE__) . '/vendor/autoload.php';

$process = $argv[1];
$clients = [];
for ($i = 0; $i < $process; $i++) {
    $client = new Client("tcp://127.0.0.1:2345");
    $client->on(
        'connect',
        function (Client $client) {
        }
    );

    $client->on(
        'error',
        function (Client $client, int $errorNo, string $errorMessage) {
            fprintf(STDOUT, "errorNo:%d,errorMessage:%d\n", $errorNo, $errorMessage);
        }
    );

    $client->on(
        'close',
        function (Client $client) {
            fprintf(STDOUT, "server close...");
        }
    );

    $client->on(
        'receive',
        function (Client $client, string $data) {
        }
    );
    $client->start();
    $clients[] = $client;
}

$startTime = time();
while (1) {
    $now = time();
    $diff = $now - $startTime;
    $startTime = $now;
    if ($diff >= 1) {
        $sendNum = 0;
        $sendMsgNum = 0;
        /**
         * @var $client Client
         */
        foreach ($clients as $client) {
            $sendMsgNum += $client->sendMsgNum;
            $sendNum += $client->sendNum;
        }
        fprintf(
            STDOUT,
            "time<%s>--<clientNum:%d>--<sendNum:%d>--<msgNum:%d>\n",
            $diff,
            $process,
            $sendNum,
            $sendMsgNum
        );

        foreach ($clients as $client) {
            $client->sendMsgNum = 0;
            $client->sendNum = 0;
        }
    }

    for ($i = 0; $i < $process; $i++) {
        $client = $clients[$i];
        $client->send("我是何冰");
        $r = $client->eventLoop();
        if (!$r) {
            break;
        }
    }
}

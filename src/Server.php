<?php

namespace Te;

use Opis\Closure\SerializableClosure;
use Te\Event\Epoll;
use Te\Event\Event;
use Te\Protocols\Http;
use Te\Protocols\Protocol;
use Te\Protocols\Stream;
use Te\Protocols\Text;

class Server
{
    public mixed $mainSocket;

    public string $localSocket;

    public static array $connections = [];

    public array $events = [];

    public ?Protocol $protocol = null;

    public string $protocolLayout;

    /**
     * @var int 统计客户端连接数量
     */
    public static int $clientNum = 0;
    /**
     * @var int 执行recv调用次数
     */
    public static int $receiveNum = 0;
    /**
     * @var int 接受了多少条消息
     */
    public static int $messageNum = 0;

    public int $startTime = 0;

    public array $protocols = [
        "stream" => Stream::class,
        "text" => Text::class,
        "http" => Http::class
    ];

    public string $useProtocols = "tcp";

    public static Event $eventLoop;

    public array $setting = [];

    public array $pidMap = [];

    public static string $pidFile;
    public static string $logFile;
    public static string $startFile;

    public static int $status;

    public const STATUS_STARTING = 1;

    public const STATUS_RUNNING = 2;

    public const STATUS_STOP = 3;

    public mixed $unixSocket;

    public function __construct(string $localSocket)
    {
        [$protocol, $ip, $port] = explode(':', $localSocket);
        if (isset($this->protocols[$protocol])) {
            $this->protocol = new $this->protocols[$protocol]();
            $this->useProtocols = $protocol;
        }
        $this->startTime = time();
        $this->localSocket = "tcp:{$ip}:{$port}";
    }

    public function listen()
    {
        $flag = STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;
        $option['socket']['backlog'] = 102400;
        $option['socket']['so_reuseport'] = 1;
        $option['socket']['tcp_nodelay'] = 1;
        $context = stream_context_create($option);
        $this->mainSocket = stream_socket_server($this->localSocket, $errCode, $errMessage, $flag, $context);
        stream_set_blocking($this->mainSocket, 0);
        if ($this->mainSocket === false) {
            $this->echoLog("server create fail :%s", $errMessage);
            exit(0);
        }
    }

    public function runEventCallback(string $eventName, array $args = [])
    {
        if (isset($this->events[$eventName]) && is_callable($this->events[$eventName])) {
            $this->events[$eventName]($this, ...$args);
        }
    }

    public function accept()
    {
        $connFd = stream_socket_accept($this->mainSocket, -1, $peerName);
        if (is_resource($this->mainSocket)) {
            $connection = new TcpConnection($connFd, $peerName, $this);
            $this->onClientJoin();
            static::$connections[(int)$connFd] = $connection;
            $this->runEventCallback('connect', [$connection]);
        }
    }

    public function checkHeartTime()
    {
        if (!empty(static::$connections)) {
            /**
             * @var TcpConnection $connection
             */
            foreach (static::$connections as $connection) {
                if ($connection->checkHeartTime()) {
                    $connection->close();
                }
            }
        }
    }

    public function eventLoop()
    {
        static::$eventLoop->loop();
    }

    public function on(string $eventName, callable $eventCall)
    {
        $this->events[$eventName] = $eventCall;
    }

    public function reloadWorker()
    {
        $pid = pcntl_fork();
        if ($pid === 0) {
            $this->runEventCallback("workerReload");
            $this->worker();
        } else {
            $this->pidMap[$pid] = $pid;
        }
    }

    public function masterWorker()
    {
        while (1) {
            pcntl_signal_dispatch();
            $pid = pcntl_wait($status);
            pcntl_signal_dispatch();
            pcntl_signal_dispatch();
            if ($pid) {
                unset($this->pidMap[$pid]);
                if (self::STATUS_STOP !== static::$status) {
                    $this->reloadWorker();
                }
            }
            if (empty($this->pidMap)) {
                break;
            }
        }
        $this->runEventCallback("masterStop");
        exit(0);
    }

    public function forkWorker()
    {
        $workNum = 1;
        if (isset($this->setting['workNum'])) {
            $workNum = $this->setting['workNum'];
        }
        for ($i = 0; $i < $workNum; $i++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                $this->worker();
            } else {
                $this->pidMap[$pid] = $pid;
            }
        }
    }

    public function taskSigHandler(int $sigNum)
    {
        static::$eventLoop->del(socket_export_stream($this->unixSocket), Event::EV_READ);
        set_error_handler(
            function () {
            }
        );
        fclose(socket_export_stream($this->unixSocket));
        restore_error_handler();
        $this->unixSocket = null;
        static::$eventLoop->clearTimer();
        static::$eventLoop->clearSignalEvent();
        static::$eventLoop->exitLoop();
    }

    public function tasker(int $index)
    {
        srand();
        mt_rand();
        cli_set_process_title("Te/tasker");
        $unixSocketFile = $this->setting['task']['unix_socket_server_file'] . $index;
        if (file_exists($unixSocketFile)) {
            unlink($unixSocketFile);
        }

        $this->unixSocket = socket_create(AF_UNIX, SOCK_DGRAM, 0);
        socket_bind($this->unixSocket, $unixSocketFile);
        socket_set_option($this->unixSocket, SOL_SOCKET, SO_REUSEADDR, 1);
//        socket_set_option($this->unixSocket, SOL_SOCKET, TCP_NODELAY, 1);
        $stream = socket_export_stream($this->unixSocket);
        stream_set_blocking($stream, 0);
        stream_set_read_buffer($stream, 0);
        stream_set_write_buffer($stream, 0);

        static::$status = self::STATUS_STARTING;
        static::$eventLoop = new Epoll();

        pcntl_signal(SIGTERM, SIG_IGN, false);
        pcntl_signal(SIGQUIT, SIG_IGN, false);
        pcntl_signal(SIGINT, SIG_IGN, false);

        static::$eventLoop->add(SIGTERM, Event::EV_SIGNAL, [$this, "taskSigHandler"]);
        static::$eventLoop->add(SIGINT, Event::EV_SIGNAL, [$this, "taskSigHandler"]);
        static::$eventLoop->add(SIGQUIT, Event::EV_SIGNAL, [$this, "taskSigHandler"]);
        static::$eventLoop->add($stream, Event::EV_READ, [$this, 'acceptUdpClient']);
        static::$status = self::STATUS_RUNNING;
        $this->eventLoop();
        exit(0);


//        while (1) {
//            $len = socket_recvfrom($this->unixSocket, $buff, 1024, 0, $address);
//            if ($len) {
//                var_dump($buff);
//            }
//        }
//        exit(0);
    }

    public function forkTask()
    {
        $taskNum = 1;
        if (isset($this->setting['taskNum'])) {
            $taskNum = $this->setting['taskNum'];
        }
        for ($i = 0; $i < $taskNum; $i++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                $this->tasker($i);
            } else {
                $this->pidMap[$pid] = $pid;
            }
        }
    }

    public function worker()
    {
        srand();
        mt_rand();
        cli_set_process_title("Te/worker");
        $this->listen();
        static::$status = self::STATUS_STARTING;
        static::$eventLoop = new Epoll();

        pcntl_signal(SIGTERM, SIG_IGN, false);
        pcntl_signal(SIGQUIT, SIG_IGN, false);
        pcntl_signal(SIGINT, SIG_IGN, false);

        static::$eventLoop->add(SIGTERM, Event::EV_SIGNAL, [$this, "sigHandler"]);
        static::$eventLoop->add(SIGINT, Event::EV_SIGNAL, [$this, "sigHandler"]);
        static::$eventLoop->add(SIGQUIT, Event::EV_SIGNAL, [$this, "sigHandler"]);

        static::$eventLoop->add($this->mainSocket, Event::EV_READ, [$this, "accept"]);
//        static::$eventLoop->add(1, Event::EV_TIMER, [$this, "statistics"]);
//        static::$eventLoop->add(1, Event::EV_TIMER, [$this, "checkHeartTime"]);
        static::$status = self::STATUS_RUNNING;
        $this->runEventCallback("workerStart");
        $this->eventLoop();
        $this->runEventCallback("workerStop");
        exit();
    }


    public function init()
    {
        $trace = debug_backtrace();
        static::$startFile = array_pop($trace)['file'];
        static::$pidFile = pathinfo(self::$startFile)['filename'] . ".pid";
        static::$logFile = pathinfo(self::$startFile)['filename'] . ".log";
        if (!file_exists(static::$logFile)) {
            touch(static::$logFile);
            chown(static::$logFile, posix_getuid());
        }
    }

    public function saveMasterPid()
    {
        $masterPid = posix_getpid();
        file_put_contents(static::$pidFile, $masterPid);
    }

    public function installSignalHandler()
    {
        pcntl_signal(SIGINT, [$this, 'sigHandler'], false);
        pcntl_signal(SIGTERM, [$this, 'sigHandler'], false);
        pcntl_signal(SIGQUIT, [$this, 'sigHandler'], false);
        pcntl_signal(SIGPIPE, SIG_IGN, false); // 主要是读写socket文件时产生该信号时候忽略
    }

    public function sigHandler(int $sigNum)
    {
        $masterPid = (int)file_get_contents(static::$pidFile);

        switch ($sigNum) {
            case SIGINT:
            case SIGTERM:
            case SIGQUIT:

                if ($masterPid === posix_getpid()) {
                    foreach ($this->pidMap as $pid) {
                        posix_kill($pid, $sigNum);
                    }
                    static::$status = self::STATUS_STOP;
                } else {
                    static::$eventLoop->del($this->mainSocket, Event::EV_READ);
                    set_error_handler(
                        function () {
                        }
                    );
                    fclose($this->mainSocket);
                    restore_error_handler();
                    $this->mainSocket = null;

                    /**
                     * @var TcpConnection $connection
                     */
                    foreach (static::$connections as $fd => $connection) {
                        $connection->close();
                    }


                    static::$connections = [];
                    static::$eventLoop->clearSignalEvent();
                    static::$eventLoop->clearTimer();
                    static::$eventLoop->exitLoop();
                }

                break;
        }
    }

    public function start()
    {
        date_default_timezone_set("Asia/Shanghai");
        static::$status = self::STATUS_STARTING;
        $this->init();
        global $argv;
        $command = $argv[1];
        switch ($command) {
            case "start" :
                if (is_file(static::$pidFile)) {
                    $masterPid = (int)file_get_contents(static::$pidFile);
                    $masterPidOIsAlive = $masterPid && posix_kill($masterPid, 0) && $masterPid !== posix_getpid();
                    if ($masterPidOIsAlive) {
                        exit("server already running...");
                    }
                }


                break;
            case "stop":
                $masterPid = (int)file_get_contents(static::$pidFile);
                $masterPidOIsAlive = $masterPid && posix_kill($masterPid, 0);
                if ($masterPidOIsAlive) {
                    posix_kill($masterPid, SIGTERM);
                    $timeOut = 5;
                    $stopTime = time();
                    while (1) {
                        $masterPidOIsAlive = $masterPid && posix_kill($masterPid, 0) && $masterPid !== posix_getpid();
                        if ($masterPidOIsAlive) {
                            if (time() - $stopTime >= $timeOut) {
                                $this->echoLog("server stop fail");
                                exit(0);
                            }

                            sleep(1);
                            continue;
                        }
                        $this->echoLog("server stop success");
                        exit(0);
                    }
                } else {
                    exit("server not exist\r\n");
                }
            default:
                $usage = "php " . pathinfo(static::$startFile)['filename'] . ".php [start|stop]\n";
                exit($usage);
        }
        $this->runEventCallback("masterStart");

        if ($this->checkSetting('daemon')) {
            $this->daemon();
        }
        cli_set_process_title("Te/master");
        $this->saveMasterPid();
        $this->installSignalHandler();
        $this->forkWorker();
        $this->forkTask();
        static::$status = self::STATUS_RUNNING;
        $this->displayInfo();
        $this->masterWorker();
    }

    public function echoLog($format, ...$data)
    {
        if ($this->checkSetting('daemon')) {
            $info = sprintf($format, ...$data);
            $msg = sprintf("[pid:%d][%s]-[info:%s]\r\n", posix_getpid(), date("Y-m-d H:i:s"), $info);
            file_put_contents(static::$logFile, $msg, FILE_APPEND);
        } else {
            fprintf(STDOUT, $format, ...$data);
        }
    }

    public function daemon()
    {
        umask(000);
        $pid = pcntl_fork();
        if ($pid > 0) {
            exit(0);
        }
        if (-1 === posix_setsid()) {
            throw new \Exception("setpid fail");
        }
        $pid = pcntl_fork();
        if ($pid > 0) {
            exit(0);
        }
    }


    public function checkSetting($key): bool
    {
        if (isset($this->setting[$key]) && $this->setting[$key]) {
            return true;
        } else {
            return false;
        }
    }

    public function setting(array $setting)
    {
        $this->setting = $setting;
    }

    public function removeClient($socketFd)
    {
        if (isset(static::$connections[(int)$socketFd])) {
            unset(static::$connections[(int)$socketFd]);
            --static::$clientNum;
        }
    }

    public function onClientJoin()
    {
        ++static::$clientNum;
    }

    public function onReceive()
    {
        ++static::$receiveNum;
    }

    public function onMessage()
    {
        ++static::$messageNum;
    }

    public function statistics()
    {
        $nowTime = time();
        $diff = $nowTime - $this->startTime;
        if ($diff > 1) {
            $this->echoLog(
                "pid<%d>--time<%s>--socket<%d>--<clientNum:%d>--<recvNum:%d>--<msgNum:%d>",
                posix_getpid(),
                $diff,
                (int)$this->mainSocket,
                static::$clientNum,
                static::$receiveNum,
                static::$messageNum
            );
            static::$receiveNum = 0;
            static::$messageNum = 0;
            $this->startTime = $nowTime;
        }
    }


    public function task(callable $func)
    {
        $taskNum = $this->setting['taskNum'];
        $index = mt_rand(0, $taskNum - 1);

        $unixSocketFileClient = $this->setting['task']['unix_socket_client_file'];
        $unixSocketFileServer = $this->setting['task']['unix_socket_server_file'] . $index;
        if (file_exists($unixSocketFileClient)) {
            unlink($unixSocketFileClient);
        }
        $socketFd = socket_create(AF_UNIX, SOCK_DGRAM, 0);
        socket_bind($socketFd, $unixSocketFileClient);

        $factory = fn($n) => $func($n);
        $wrapper = new SerializableClosure($factory);
        $serialized = serialize($wrapper);

        $len = strlen($serialized);
        $bin = pack("N", $len + 4) . $serialized;

        socket_sendto($socketFd, $bin, $len + 4, 0, $unixSocketFileServer);
        socket_close($socketFd);
    }

    public function acceptUdpClient()
    {
        set_error_handler(
            function () {
            }
        );
        $len = socket_recvfrom($this->unixSocket, $buff, 65535, 0, $unixClientFile);
        restore_error_handler();
        if ($len && $unixClientFile) {
            $udpConnection = new UdpConnection($this->unixSocket, $len, $buff, $unixClientFile);
            $udpConnection->handleMessage();
        }
    }

    public function displayInfo()
    {
        $info = "\r\n";
        $info .= "Te WorkerNum:" . $this->setting['workNum'] . "\r\n";
        $info .= "Te TaskNum:" . $this->setting['taskNum'] . "\r\n";
        $info .= "Te run mode: " . ($this->checkSetting('daemon') ? "daemon" : "debug") . "\r\n";
        $info .= "Te working with :" . $this->useProtocols . " protocol\r\n";
        $info .= "Te listen on :" . $this->localSocket . "\r\n";
        fwrite(STDOUT, $info);
    }
}


<?php

namespace Te;

use Te\Event\Event;

class TcpConnection
{
    public $socketFd;

    public string $clientIp;

    public Server $server;

    public int $readBuffSize = 1024;

    /**
     * @var int 当前缓冲区大小
     */
    public int $receiveBufferSize = 1024 * 100;

    /**
     * @var int 当前连接已经读取的长度
     */
    public int $receiveLen = 0;

    /**
     * @var int 表示当前连接超出缓冲区大小的次数
     */
    public int $receiveBufferFullTimes = 0;
    /**
     * @var string 缓冲区
     */
    public string $receiveBuff = '';


    public int $sendLen = 0;

    public string $sendBuffer = '';

    public int $sendBuffSize = 1024 * 1000;

    public int $sendBuffFullTimes = 0;

    public int $heartTime = 0;

    public const HEART_TIME = 20;

    public const STATUS_CONNECTED = 10;

    public const STATUS_CLOSED = 11;

    public int $status;

    public function __construct($socketFd, string $clientIp, Server $server)
    {
        $this->socketFd = $socketFd;
        $this->clientIp = $clientIp;
        $this->server = $server;
        $this->resetHeartTime();
        $this->status = self::STATUS_CONNECTED;
        stream_set_blocking($this->socketFd, 0);
        stream_set_read_buffer($this->socketFd, 0);
        stream_set_write_buffer($this->socketFd, 0);
        $server::$eventLoop->add($this->socketFd, Event::EV_READ, [$this, 'readSocket']);
    }

    public function isConnected(): bool
    {
        return $this->status === self::STATUS_CONNECTED && is_resource($this->socketFd);
    }

    public function resetHeartTime()
    {
        $this->heartTime = time();
    }

    public function checkHeartTime(): bool
    {
        $now = time();
        if ($now - $this->heartTime >= self::HEART_TIME) {
            $this->server->echoLog("心跳时间已经超出:%d", $now - $this->heartTime);
            return true;
        } else {
            return false;
        }
    }

    public function getSocketFd()
    {
        return $this->socketFd;
    }

    public function readSocket()
    {
        if ($this->receiveLen < $this->receiveBufferSize) {
            $data = fread($this->socketFd, $this->readBuffSize);
            if ($data === '' || $data === false) {
                if (feof($this->socketFd) || !is_resource($this->socketFd)) {
                    $this->close();
                    return;
                }
            } else {
                $this->receiveBuff .= $data;
                $this->receiveLen += strlen($data);
                $this->server->onReceive();
            }
        } else {
            $this->receiveBufferFullTimes++;
        }

        if ($this->receiveLen > 0 && !empty($data)) {
            $this->handleMessage();
        }
    }

    public function handleMessage()
    {
        if ($this->server->protocol !== null && is_object($this->server->protocol)) {
            while ($this->server->protocol->len($this->receiveBuff)) {
                $msgLen = $this->server->protocol->msgLen($this->receiveBuff);
                $oneMsg = substr($this->receiveBuff, 0, $msgLen);
                $this->receiveBuff = substr($this->receiveBuff, $msgLen);
                $this->receiveLen -= $msgLen;
                $this->receiveBufferFullTimes--;
                $this->server->onMessage();
                $this->resetHeartTime();
                $message = $this->server->protocol->decode($oneMsg);
                $this->server->runEventCallback('receive', [$message, $this]);
            }
        } else {
            $this->server->runEventCallback('receive', [$this->receiveBuff, $this]);
            $this->receiveBuff = "";
            $this->receiveLen = 0;
            $this->server->onMessage();
            $this->resetHeartTime();
            $this->receiveBufferFullTimes = 0;
        }
    }

    public function writeSocket()
    {
        if ($this->needWrite()) {
            if (is_resource($this->socketFd)) {
                set_error_handler(
                    function () {
                    }
                );
                $writeLen = fwrite($this->socketFd, $this->sendBuffer, $this->sendLen);
                restore_error_handler();
                if ($writeLen === $this->sendLen) {
                    $this->sendLen = 0;
                    $this->sendBuffer = '';
                    Server::$eventLoop->del($this->socketFd, Event::EV_WRITE);
                    return true;
                } elseif ($writeLen > 0) {
                    $this->sendBuffer = substr($this->sendBuffer, $writeLen);
                    $this->sendLen -= $writeLen;
                } else {
                    $this->close();
                }
            }
        }
    }

    public function needWrite(): bool
    {
        return $this->sendLen > 0;
    }

    public function send(string $data): void
    {
        $len = strlen($data);
        if ($this->sendLen + $len < $this->sendBuffSize) {
            if ($this->server->protocol !== null && is_object($this->server->protocol)) {
                [$encodeLen, $data] = $this->server->protocol->encode($data);
                $this->sendBuffer .= $data;
                $this->sendLen += $encodeLen;
            } else {
                $this->sendBuffer .= $data;
                $this->sendLen += $len;
            }

            if ($this->sendLen >= $this->sendBuffSize) {
                $this->sendBuffFullTimes++;
            }
        }
        if (empty($this->sendBuffer) || $this->sendLen === 0) {
            return;
        }

        Server::$eventLoop->add($this->socketFd, Event::EV_WRITE, [$this, 'writeSocket']);
    }

    public function close()
    {
        Server::$eventLoop->del($this->socketFd, Event::EV_READ);
        Server::$eventLoop->del($this->socketFd, Event::EV_WRITE);

        if (is_resource($this->socketFd)) {
            fclose($this->socketFd);
        }
        $this->server->runEventCallback('close', [$this]);
        $this->server->removeClient($this->socketFd);
        $this->status = self::STATUS_CLOSED;
        $this->socketFd = null;
        $this->sendLen = 0;
        $this->sendBuffer = '';
        $this->sendBuffFullTimes = 0;
        $this->sendBuffSize = 0;

        $this->receiveLen = 0;
        $this->receiveBuff = '';
        $this->receiveBufferFullTimes = 0;
        $this->receiveBufferSize = 0;
    }
}

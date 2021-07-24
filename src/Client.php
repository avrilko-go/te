<?php

namespace Te;

use Te\Protocols\Protocol;
use Te\Protocols\Stream;

class Client
{
    public $mainSocket;

    public array $events = [];

    public int $readBuffSize = 1024;

    public Protocol $protocol;

    public string $localSocket;


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

    public int $sendNum = 0;

    public int $sendMsgNum = 0;

    public int $status;

    public const STATUS_CONNECTED = 10;

    public const STATUS_CLOSED = 11;

    public function __construct($localSocket)
    {
        $this->localSocket = $localSocket;
        $this->protocol = new Stream();
    }

    public function onSend()
    {
        ++$this->sendNum;
    }

    public function onSendMsg()
    {
        ++$this->sendMsgNum;
    }

    public function start()
    {
        $this->mainSocket = stream_socket_client($this->localSocket, $errorCode, $errorMessage);
        $this->protocol = new Stream();
        if (is_resource($this->mainSocket)) {
            $this->runEventCallback('connect');
            $this->status = self::STATUS_CONNECTED;
        } else {
            $this->runEventCallback('error', [$errorCode, $errorMessage]);
            exit(0);
        }
    }

    public function runEventCallback(string $eventName, array $args = [])
    {
        if (isset($this->events[$eventName]) && is_callable($this->events[$eventName])) {
            $this->events[$eventName]($this, ...$args);
        }
    }

    public function on(string $eventName, callable $eventCall)
    {
        $this->events[$eventName] = $eventCall;
    }

    public function eventLoop(): bool
    {
        if (is_resource($this->mainSocket)) {
            $readFds = [$this->mainSocket];
            if ($this->needWrite()) {
                $writeFds = [$this->mainSocket];
            } else {
                $writeFds = [];
            }
            $exptFds = [$this->mainSocket];

            $result = stream_select($readFds, $writeFds, $exptFds, null, null);
            if ($result < 0 || $result === false) {
                return false;
            }

            if (!empty($readFds)) {
                $this->readSocket();
            }

            if ($writeFds) {
                $this->writeSocket();
            }

            return true;
        } else {
            return false;
        }
    }

    public function onClose()
    {
        fclose($this->mainSocket);
        $this->runEventCallback('close');
        $this->status = self::STATUS_CLOSED;
        $this->mainSocket = null;
    }

    public function readSocket()
    {
        if ($this->isConnected()) {
            $data = fread($this->mainSocket, $this->readBuffSize);
            if ($data === '' || $data === false) {
                if (feof($this->mainSocket) || !is_resource($this->mainSocket)) {
                    $this->onClose();
                    exit(0);
                }
            } else {
                $this->receiveBuff .= $data;
                $this->receiveLen += strlen($data);
            }

            if ($this->receiveLen > 0) {
                $this->handleMessage();
            }
        }
    }

    public function handleMessage()
    {
        while ($this->protocol->len($this->receiveBuff)) {
            $msgLen = $this->protocol->msgLen($this->receiveBuff);
            $oneMsg = substr($this->receiveBuff, 0, $msgLen);
            $this->receiveBuff = substr($this->receiveBuff, $msgLen);
            $this->receiveLen -= $msgLen;
            $message = $this->protocol->decode($oneMsg);
            $this->runEventCallback('receive', [$message]);
        }
    }

    public function writeSocket()
    {
        if ($this->needWrite() && $this->isConnected()) {
            $writeLen = fwrite($this->mainSocket, $this->sendBuffer, $this->sendLen);
            $this->onSend();
            if ($writeLen === $this->sendLen) {
                $this->sendLen = 0;
                $this->sendBuffer = '';
                return true;
            } elseif ($writeLen > 0) {
                $this->sendBuffer = substr($this->sendBuffer, $writeLen);
                $this->sendLen -= $writeLen;
            } else {
                $this->onClose();
            }
        }
    }

    public function isConnected(): bool
    {
        return $this->status === self::STATUS_CONNECTED && is_resource($this->mainSocket);
    }

    public function send(string $data): void
    {
        $len = strlen($data);
        if ($this->sendLen + $len < $this->sendBuffSize) {
            [$encodeLen, $data] = $this->protocol->encode($data);
            $this->sendBuffer .= $data;
            $this->sendLen += $encodeLen;

            if ($this->sendLen >= $this->sendBuffSize) {
                $this->sendBuffFullTimes++;
            }
            $this->onSendMsg();
        }
    }

    public function needWrite(): bool
    {
        return $this->sendLen > 0;
    }

}

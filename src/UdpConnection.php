<?php

namespace Te;

use Te\Event\Event;

class UdpConnection
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

    public const STATUS_CONNECTED = 10;

    public const STATUS_CLOSED = 11;

    public int $status;


    public function __construct(mixed $socketFd, int $len, string $buff, string $unixClientFile)
    {
        $this->socketFd = $socketFd;
        $this->clientIp = $unixClientFile;
        $this->receiveBuff .= $buff;
        $this->receiveLen += strlen($buff);
        $this->status = self::STATUS_CONNECTED;
    }


    public function readSocket()
    {
    }

    public function handleMessage()
    {
        while ($this->receiveLen) {
            $bin = unpack("NLength", $this->receiveBuff);
            $length = $bin['Length'];
            $oneMsg = substr($this->receiveBuff, 0, $length);
            $this->receiveBuff = substr($this->receiveBuff, $length);
            $this->receiveLen -= $length;
            if ($oneMsg) {
                $data = substr($oneMsg, 4);
                $wrapper = unserialize($data);
                $closure = $wrapper->getClosure();
                $closure($this);
            }
        }
    }


    public function send(string $data): void
    {
    }

    public function close()
    {
    }
}

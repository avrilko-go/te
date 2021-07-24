<?php

namespace Te\Event;

class Select implements Event
{
    public array $allEvents = [];

    public array $readFds = [];

    public array $writeFds = [];

    public array $expFds = [];

    public int $timeout = 100000000; // 100秒  （微秒级别）

    public static int $timerId = 1;

    public array $timers = [];

    public array $signalEvent = [];

    public bool $run = true;

    public function signalHandler($sigNum)
    {
        [$func] = $this->signalEvent[$sigNum];
        if (is_callable($func)) {
            $func($sigNum);
        }
    }

    public function add(
        $fd,
        int $flag,
        callable $func,
        array $arg = []
    ): int {
        switch ($flag) {
            case self::EV_READ:
                $fdKey = intval($fd);
                $this->readFds[$fdKey] = $fd;
                $this->allEvents[$fdKey][self::EV_READ] = [$func, [$fd, $flag, $arg]];
                return 1;
            case self::EV_WRITE:
                $fdKey = intval($fd);
                $this->writeFds[$fdKey] = $fd;
                $this->allEvents[$fdKey][self::EV_WRITE] = [$func, [$fd, $flag, $arg]];
                return 1;
            case self::EV_SIGNAL:
                $params = [$func];
                pcntl_signal($fd, [$this, "signalHandler"], false);
                $this->signalEvent[$fd] = $params;
                return 1;
            case self::EV_TIMER:
            case self::EV_TIME_ONCE:
                $timerId = static::$timerId;
                $runTime = microtime(true) + $fd;
                $params = [$func, $runTime, $flag, $timerId, $fd, $arg];
                $this->timers[$timerId] = $params;
                $selectTime = $fd * 1000000;
                if ($this->timeout >= $selectTime) {
                    $this->timeout = $selectTime;
                }

                static::$timerId++;
                return $timerId;

            default:
                return 0;
        }
    }

    public function del($fd, int $flag): bool
    {
        switch ($flag) {
            case self::EV_READ:
                $fdKey = intval($fd);
                unset($this->allEvents[$fdKey][self::EV_READ]);
                unset($this->readFds[$fdKey]);
                if (empty($this->allEvents[$fdKey])) {
                    unset($this->allEvents[$fdKey]);
                }

                return true;
            case self::EV_WRITE:
                $fdKey = intval($fd);
                unset($this->allEvents[$fdKey][self::EV_WRITE]);
                unset($this->writeFds[$fdKey]);
                if (empty($this->allEvents[$fdKey])) {
                    unset($this->allEvents[$fdKey]);
                }

                return true;
            case self::EV_SIGNAL:
                if (isset($this->signalEvent[$fd])) {
                    unset($this->signalEvent[$fd]);
                    pcntl_signal($fd, SIG_IGN);
                }

                return true;
            case self::EV_TIMER:
            case self::EV_TIME_ONCE:
                if (isset($this->timers[$fd])) {
                    unset($this->timers[$fd]);
                }

                return true;

            default:
                return false;
        }
    }

    public function timerCallBack()
    {
        foreach ($this->timers as $timer) {
            [$func, $runTime, $flag, $timerId, $fd, $arg] = $timer;
            if (microtime(true) >= $runTime) {
                if ($flag === self::EV_TIME_ONCE) {
                    unset($this->timers[$timerId]);
                } else {
                    $this->timers[$timerId] = [$func, microtime(true) + $fd, $flag, $timerId, $fd, $arg];
                }
                $func($timerId, $arg);
            }
        }
    }

    public function loop(): void
    {
        while ($this->run) {
            pcntl_signal_dispatch();
            $reads = $this->readFds;
            $writes = $this->writeFds;
            $expts = $this->expFds;

            if (!$this->run) {
                break;
            }

            set_error_handler(
                function () {
                }
            );
            // select是可重入函数  有中断信号产生的时候会返回false
            $ret = stream_select($reads, $writes, $expts, 0, $this->timeout);
            restore_error_handler();
            if ($ret === false) {
                continue;
            }

            //定时器
            if (!empty($this->timers)) {
                $this->timerCallBack();
            }

            if (!empty($reads)) {
                $this->callback($reads, self::EV_READ);
            }

            if (!empty($writes)) {
                $this->callback($reads, self::EV_WRITE);
            }
        }
    }

    public function callback(array $fds, int $type)
    {
        foreach ($fds as $fd) {
            $fdKey = intval($fd);
            if (isset($this->allEvents[$fdKey][$type])) {
                [$func, [$fd, $flag, $arg]] = $this->allEvents[$fdKey][$type];
                $func($fd, $flag, $arg);
            }
        }
    }

    public function clearTimer(): void
    {
        $this->timers = [];
    }

    public function clearSignalEvent(): void
    {
        foreach ($this->signalEvent as $fd => $param) {
            pcntl_signal($fd, SIG_IGN);
        }

        $this->signalEvent = [];
    }

    public function exitLoop(): bool
    {
        $this->run = false;
        $this->readFds = [];
        $this->writeFds = [];
        $this->expFds = [];
        $this->allEvents = [];
        return true;
    }
}
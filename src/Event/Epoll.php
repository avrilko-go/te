<?php

namespace Te\Event;

class Epoll implements Event
{
    public \EventBase $eventBase;

    public array $readEvent = [];
    public array $writeEvent = [];

    public array $signalEvents = [];

    public array $timers = [];

    public static int $timerId = 1;


    public function __construct()
    {
        $this->eventBase = new \EventBase();
    }

    public function timerCallBack(mixed $fd, int $what, array $params)
    {
        [$func, $flag, $timerId, $userArg] = $params;
        if ($flag === self::EV_TIME_ONCE) {
            $event = $this->timers[$timerId];
            $event->del();
            unset($this->timers[$timerId]);
        }
        $func($timerId, $userArg);
    }

    public function add($fd, int $flag, callable $func, array $arg = []): int
    {
        switch ($flag) {
            case self::EV_READ:
                $event = new \Event($this->eventBase, $fd, \Event::READ | \Event::PERSIST, $func, $arg);
                if (!$event || !$event->add()) {
                    return 0;
                }
                $this->readEvent[(int)$fd] = $event;
                return true;
            case self::EV_WRITE:
                $event = new \Event($this->eventBase, $fd, \Event::WRITE | \Event::PERSIST, $func, $arg);
                if (!$event || !$event->add()) {
                    return 0;
                }
                $this->writeEvent[(int)$fd] = $event;
                return true;
            case self::EV_SIGNAL:
                $event = new \Event($this->eventBase, $fd, \Event::SIGNAL, $func, $arg);
                if (!$event || !$event->add()) {
                    return 0;
                }
                $this->signalEvents[(int)$fd] = $event;
                return 1;
            case self::EV_TIMER:
            case self::EV_TIME_ONCE:
                $timerId = static::$timerId;
                $params = [$func, $flag, $timerId, $arg];
                $event = new \Event(
                    $this->eventBase,
                    -1,
                    \Event::TIMEOUT | \Event::PERSIST,
                    [$this, "timerCallBack"],
                    $params
                );
                if (!$event || !$event->add($fd)) {
                    return 0;
                }
                $this->timers[$timerId] = $event;
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
                if (isset($this->readEvent[(int)$fd])) {
                    /**
                     * @var $event \Event
                     */
                    $event = $this->readEvent[(int)$fd];
                    unset($this->readEvent[(int)$fd]);
                    $event->del();
                }
                return true;
            case self::EV_WRITE:
                if (isset($this->writeEvent[(int)$fd])) {
                    /**
                     * @var $event \Event
                     */
                    $event = $this->writeEvent[(int)$fd];
                    unset($this->writeEvent[(int)$fd]);
                    $event->del();
                }

                return true;
            case self::EV_SIGNAL:
                if (isset($this->signalEvents[$fd])) {
                    $this->signalEvents[$fd]->del();
                    unset($this->signalEvents[$fd]);
                }

                return true;
            case self::EV_TIMER:
            case self::EV_TIME_ONCE:
                if (isset($this->timers[$fd])) {
                    $event = $this->timers[$fd];
                    $event->del();
                    unset($this->timers[$fd]);
                }

                return true;
            default:
                return false;
        }
    }

    public function loop(): void
    {
        $this->eventBase->dispatch();
    }

    public function clearTimer(): void
    {
        if (!empty($this->timers)) {
            foreach ($this->timers as $fd => $event) {
                if ($event->del()) {
                }
            }
        }


        $this->timers = [];
    }

    public function clearSignalEvent(): void
    {
        if (!empty($this->signalEvents)) {
            foreach ($this->signalEvents as $fd => $event) {
                $event->del();
            }
        }


        $this->signalEvents = [];
    }

    public function exitLoop(): bool
    {
        return $this->eventBase->stop();
    }
}
<?php

namespace Te\Event;

interface Event
{
    public const EV_READ = 10;
    public const EV_WRITE = 11;

    public const EV_SIGNAL = 12;
    public const EV_TIMER = 13;
    public const EV_TIME_ONCE = 14;

    public function add($fd, int $flag, callable $func, array $arg = []): int;

    public function del($fd, int $flag): bool;

    public function loop(): void;

    public function clearTimer(): void;

    public function clearSignalEvent(): void;

    public function exitLoop(): bool;

}
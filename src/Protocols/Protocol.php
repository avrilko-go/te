<?php

namespace Te\Protocols;

interface Protocol
{
    public function len(string $data): bool;


    public function encode(string $data = ''): array;


    public function decode(string $data = ''): string;


    public function msgLen(string $data = ''): int;

}
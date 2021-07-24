<?php

namespace Te\Protocols;

class Text implements Protocol
{
    public function len(string $data): bool
    {
        if (strlen($data)) {
            return strpos($data, "\n");
        }
        return false;
    }

    public function encode(string $data = ''): array
    {
        $data .= "\n";
        return [strlen($data), $data];
    }

    public function decode(string $data = ''): string
    {
        return rtrim($data, "\n");
    }

    public function msgLen(string $data = ''): int
    {
        return strpos($data, "\n") + 1;
    }
}

<?php

namespace Te\Protocols;

class Stream implements Protocol
{
    // 4个字节存储数据总长度
    public function len(string $data): bool
    {
        if (strlen($data) < 4) {
            return false;
        }

        $tmp = unpack("NtotalLen", $data);
        if (strlen($data) < $tmp['totalLen']) {
            return false;
        }
        return true;
    }

    public function encode(string $data = ''): array
    {
        $totalLen = strlen($data) + 6;
        $bin = pack("Nn", $totalLen, "1") . $data;
        return [$totalLen, $bin];
    }

    public function decode(string $data = ''): string
    {
        $cmd = substr($data, 4, 2);
        return substr($data, 6);
    }

    public function msgLen(string $data = ''): int
    {
        $tmp = unpack("Nlength", $data);
        return (int)$tmp['length'];
    }
}

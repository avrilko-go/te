<?php

namespace Te\Protocols;


class Http implements Protocol
{
    public int $headerLen = 0;

    public int $bodyLen = 0;

    public string $method;
    public string $uri;
    public string $scheme;
    public array $header;


    public function parseHeader(string $data)
    {
        $tmp = explode("\r\n", $data);
        $startLine = array_shift($tmp);
        [$this->method, $this->uri, $this->scheme] = explode(' ', $startLine);

        foreach ($tmp as $item) {
            [$key, $value] = explode(":", $item, 2);
            $this->header[trim($key)] = trim($value);
        }
        var_dump($this->header);
    }


    public function len(string $data): bool
    {
        if (strpos($data, "\r\n\r\n")) {
            $this->headerLen = strpos($data, "\r\n\r\n");
            $this->headerLen += 4;
            $bodyLen = 0;
            if (preg_match("/\r\nContent-Length: ?(\d+)/i", $data, $matches)) {
                $bodyLen = $matches[1];
            }
            $this->bodyLen = $bodyLen;
            $totalLen = $this->headerLen + $this->bodyLen;
            if (strlen($data) >= $totalLen) {
                return true;
            }
        }
        return false;
    }

    public function encode(string $data = ''): array
    {
        return [1, 2];
    }

    public function decode(string $data = ''): string
    {
        $header = substr($data, 0, $this->headerLen - 4);
        $this->parseHeader($header);
        $body = substr($data, $this->headerLen, $this->bodyLen);
        return $header;
    }

    public function msgLen(string $data = ''): int
    {
        return $this->bodyLen + $this->headerLen;
    }
}
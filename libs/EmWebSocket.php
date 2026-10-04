<?php

declare(strict_types=1);

/**
 * WebSocket (RFC 6455) für die Serverseite, rein und ohne Symcon: Symcon bringt nur einen
 * Server Socket (TCP) mit, keinen WebSocket-Server. Der Handshake und die Rahmen der Echo-Dots
 * (Go, gorilla/websocket) sind schlicht: ein GET mit Upgrade, danach maskierte Client-Rahmen
 * und unmaskierte Server-Rahmen. Fragmentierung wird zusammengesetzt, Grenzen verhindern, dass
 * ein Fremder den Speicher füllt.
 */
final class EmWebSocket
{
    public const OP_CONT = 0x0;
    public const OP_TEXT = 0x1;
    public const OP_BINARY = 0x2;
    public const OP_CLOSE = 0x8;
    public const OP_PING = 0x9;
    public const OP_PONG = 0xA;

    private const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
    public const MAX_HEADER = 8192;
    public const MAX_MESSAGE = 2097152;

    /**
     * Liest eine HTTP-Anfrage, sobald der Kopf vollständig ist.
     *
     * @return array{path: string, headers: array<string, string>, rest: string}|null null = noch unvollständig
     * @throws \InvalidArgumentException bei Unsinn oder zu großem Kopf
     */
    public static function parseRequest(string $buffer): ?array
    {
        $end = strpos($buffer, "\r\n\r\n");
        if ($end === false) {
            if (strlen($buffer) > self::MAX_HEADER) {
                throw new \InvalidArgumentException('header too large');
            }
            return null;
        }
        if ($end > self::MAX_HEADER) {
            throw new \InvalidArgumentException('header too large');
        }
        $lines = explode("\r\n", substr($buffer, 0, $end));
        $request = array_shift($lines);
        if (preg_match('#^GET (\S+) HTTP/1\.[01]$#', (string)$request, $m) !== 1) {
            throw new \InvalidArgumentException('not a GET request');
        }
        $headers = [];
        foreach ($lines as $line) {
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $headers[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
            }
        }
        $path = (string)parse_url($m[1], PHP_URL_PATH);
        return ['path' => $path, 'headers' => $headers, 'rest' => substr($buffer, $end + 4)];
    }

    /** Ob die Anfrage ein WebSocket-Upgrade ist (Upgrade: websocket, Schlüssel vorhanden). */
    public static function isUpgrade(array $headers): bool
    {
        return strtolower($headers['upgrade'] ?? '') === 'websocket'
            && str_contains(strtolower($headers['connection'] ?? ''), 'upgrade')
            && ($headers['sec-websocket-key'] ?? '') !== '';
    }

    public static function acceptKey(string $key): string
    {
        return base64_encode(sha1(trim($key) . self::GUID, true));
    }

    public static function handshakeResponse(string $key): string
    {
        return "HTTP/1.1 101 Switching Protocols\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . 'Sec-WebSocket-Accept: ' . self::acceptKey($key) . "\r\n\r\n";
    }

    public static function httpError(int $code, string $text): string
    {
        return sprintf("HTTP/1.1 %d %s\r\nContent-Length: 0\r\nConnection: close\r\n\r\n", $code, $text);
    }

    /** Ein Server-Rahmen (unmaskiert, FIN gesetzt). */
    public static function frame(int $opcode, string $payload): string
    {
        $len = strlen($payload);
        $head = chr(0x80 | ($opcode & 0x0F));
        if ($len < 126) {
            $head .= chr($len);
        } elseif ($len < 65536) {
            $head .= chr(126) . pack('n', $len);
        } else {
            $head .= chr(127) . pack('J', $len);
        }
        return $head . $payload;
    }

    public static function text(string $payload): string
    {
        return self::frame(self::OP_TEXT, $payload);
    }

    public static function binary(string $payload): string
    {
        return self::frame(self::OP_BINARY, $payload);
    }

    public static function close(int $code = 1000): string
    {
        return self::frame(self::OP_CLOSE, pack('n', $code));
    }

    /**
     * Zerlegt eingehende Client-Rahmen in vollständige Nachrichten.
     *
     * @param string $buffer     Bytes seit dem letzten Aufruf, die noch nicht verarbeitet sind
     * @param string $partial    bereits empfangene Teile einer fragmentierten Nachricht
     * @param int    $partialOp  Opcode dieser Nachricht (0 = keine offen)
     * @param bool   $requireMask true auf der Serverseite (Clients maskieren); false für Rahmen eines Servers an uns als Client
     * @return array{messages: array<int, array{op: int, data: string}>, buffer: string, partial: string, partialOp: int}
     * @throws \InvalidArgumentException bei Protokollverstoß (nicht maskiert, zu groß, ungültiger Opcode)
     */
    public static function decode(string $buffer, string $partial = '', int $partialOp = 0, bool $requireMask = true): array
    {
        $messages = [];
        while (strlen($buffer) >= 2) {
            $b0 = ord($buffer[0]);
            $b1 = ord($buffer[1]);
            $fin = ($b0 & 0x80) !== 0;
            $op = $b0 & 0x0F;
            $masked = ($b1 & 0x80) !== 0;
            $len = $b1 & 0x7F;
            $offset = 2;
            if ($len === 126) {
                if (strlen($buffer) < 4) {
                    break;
                }
                $len = (int)unpack('n', substr($buffer, 2, 2))[1];
                $offset = 4;
            } elseif ($len === 127) {
                if (strlen($buffer) < 10) {
                    break;
                }
                $len = (int)unpack('J', substr($buffer, 2, 8))[1];
                $offset = 10;
            }
            if ($requireMask && !$masked) {
                throw new \InvalidArgumentException('client frame is not masked');
            }
            if ($len < 0 || $len > self::MAX_MESSAGE) {
                throw new \InvalidArgumentException('frame too large');
            }
            $maskLen = $masked ? 4 : 0;
            if (strlen($buffer) < $offset + $maskLen + $len) {
                break;
            }
            $mask = $masked ? substr($buffer, $offset, 4) : '';
            $payload = substr($buffer, $offset + $maskLen, $len);
            $buffer = (string)substr($buffer, $offset + $maskLen + $len);
            $payload = $masked ? self::unmask($payload, $mask) : $payload;

            if ($op >= 0x8) { // Steuerrahmen: nie fragmentiert, mitten in einer Nachricht erlaubt
                if (!$fin || $len > 125 || !in_array($op, [self::OP_CLOSE, self::OP_PING, self::OP_PONG], true)) {
                    throw new \InvalidArgumentException('bad control frame');
                }
                $messages[] = ['op' => $op, 'data' => $payload];
                continue;
            }
            if ($op === self::OP_CONT) {
                if ($partialOp === 0) {
                    throw new \InvalidArgumentException('unexpected continuation');
                }
                $partial .= $payload;
                if (strlen($partial) > self::MAX_MESSAGE) {
                    throw new \InvalidArgumentException('message too large');
                }
                if ($fin) {
                    $messages[] = ['op' => $partialOp, 'data' => $partial];
                    $partial = '';
                    $partialOp = 0;
                }
                continue;
            }
            if ($op !== self::OP_TEXT && $op !== self::OP_BINARY) {
                throw new \InvalidArgumentException('unknown opcode ' . $op);
            }
            if ($partialOp !== 0) {
                throw new \InvalidArgumentException('new message inside a fragmented one');
            }
            if ($fin) {
                $messages[] = ['op' => $op, 'data' => $payload];
            } else {
                $partial = $payload;
                $partialOp = $op;
            }
        }
        return ['messages' => $messages, 'buffer' => $buffer, 'partial' => $partial, 'partialOp' => $partialOp];
    }

    private static function unmask(string $payload, string $mask): string
    {
        $len = strlen($payload);
        if ($len === 0) {
            return '';
        }
        $key = str_repeat($mask, intdiv($len, 4) + 1);
        return $payload ^ substr($key, 0, $len);
    }

    /** Ein maskierter Client-Rahmen — nur für Tests und die Attrappe eines Geräts. */
    public static function clientFrame(int $opcode, string $payload, ?string $mask = null, bool $fin = true): string
    {
        $mask ??= random_bytes(4);
        $len = strlen($payload);
        $head = chr(($fin ? 0x80 : 0) | ($opcode & 0x0F));
        if ($len < 126) {
            $head .= chr(0x80 | $len);
        } elseif ($len < 65536) {
            $head .= chr(0x80 | 126) . pack('n', $len);
        } else {
            $head .= chr(0x80 | 127) . pack('J', $len);
        }
        return $head . $mask . self::unmask($payload, $mask);
    }
}

<?php

declare(strict_types=1);

/**
 * WebSocket als Client (RFC 6455) für die Verbindung zur Realtime-Schnittstelle: Symcon bringt
 * nur einen Client Socket (TCP/TLS) mit. Anfrage und Antwort des Handshakes, maskierte Rahmen
 * nach oben, unmaskierte Rahmen vom Server nach unten. Rein, ohne Symcon.
 */
final class EmWsClient
{
    private const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    /** @param array<string, string> $headers zusätzliche Kopfzeilen (Authorization …) */
    public static function request(string $host, string $path, string $key, array $headers = []): string
    {
        $out = "GET $path HTTP/1.1\r\nHost: $host\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\n";
        foreach ($headers as $name => $value) {
            if (preg_match('/^[A-Za-z0-9-]+$/', $name) === 1 && !str_contains($value, "\r") && !str_contains($value, "\n")) {
                $out .= "$name: $value\r\n";
            }
        }
        return $out . "\r\n";
    }

    public static function newKey(): string
    {
        return base64_encode(random_bytes(16));
    }

    /**
     * @return array{status: int, headers: array<string, string>, rest: string}|null null = Antwort noch unvollständig
     * @throws \InvalidArgumentException bei Unsinn oder zu großem Kopf
     */
    public static function parseResponse(string $buffer): ?array
    {
        $end = strpos($buffer, "\r\n\r\n");
        if ($end === false) {
            if (strlen($buffer) > EmWebSocket::MAX_HEADER) {
                throw new \InvalidArgumentException('response header too large');
            }
            return null;
        }
        $lines = explode("\r\n", substr($buffer, 0, $end));
        if (preg_match('#^HTTP/1\.[01] (\d{3})#', (string)array_shift($lines), $m) !== 1) {
            throw new \InvalidArgumentException('not an HTTP response');
        }
        $headers = [];
        foreach ($lines as $line) {
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $headers[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
            }
        }
        return ['status' => (int)$m[1], 'headers' => $headers, 'rest' => (string)substr($buffer, $end + 4)];
    }

    /** Ob der Server das Upgrade mit dem richtigen Schlüssel bestätigt hat. */
    public static function accepted(int $status, array $headers, string $key): bool
    {
        return $status === 101 && ($headers['sec-websocket-accept'] ?? '') === EmWebSocket::acceptKey($key);
    }

    public static function text(string $payload): string
    {
        return EmWebSocket::clientFrame(EmWebSocket::OP_TEXT, $payload);
    }

    public static function pong(string $payload): string
    {
        return EmWebSocket::clientFrame(EmWebSocket::OP_PONG, $payload);
    }

    public static function close(int $code = 1000): string
    {
        return EmWebSocket::clientFrame(EmWebSocket::OP_CLOSE, pack('n', $code));
    }
}

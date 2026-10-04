<?php

declare(strict_types=1);

/**
 * Austausch von Audio zwischen zwei Instanzen ohne gegenseitige Aufrufe: Symcon serialisiert
 * je Instanz, und zwei Instanzen, die sich gleichzeitig aufrufen, blockieren einander. Der
 * Schreiber hängt Bytes an eine Datei, der Leser holt ab seiner Position nach; gemeldet wird mit
 * einer Variablenänderung (Nachricht VM_UPDATE), die asynchron zugestellt wird.
 */
final class EmSpool
{
    /** Eine Sprachrunde ist kurz; darüber ist etwas kaputt, und die Platte soll nicht volllaufen. */
    public const MAX_BYTES = 8388608;

    public static function dir(): string
    {
        $dir = rtrim(IPS_GetKernelDir(), '/') . '/media/echomuse/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /** Nur Buchstaben, Ziffern, Unterstrich und Bindestrich: der Name kommt von einem Gerät. */
    public static function path(string $name): string
    {
        return self::dir() . preg_replace('/[^A-Za-z0-9_-]/', '_', $name) . '.pcm';
    }

    public static function append(string $name, string $bytes): bool
    {
        $file = self::path($name);
        if (is_file($file) && filesize($file) + strlen($bytes) > self::MAX_BYTES) {
            return false;
        }
        return @file_put_contents($file, $bytes, FILE_APPEND | LOCK_EX) !== false;
    }

    /** @return array{0: string, 1: int} die neuen Bytes ab $offset und die neue Position */
    public static function read(string $name, int $offset): array
    {
        $file = self::path($name);
        if (!is_file($file) || filesize($file) <= $offset) {
            return ['', $offset];
        }
        $fh = @fopen($file, 'rb');
        if ($fh === false) {
            return ['', $offset];
        }
        fseek($fh, $offset);
        $bytes = (string)stream_get_contents($fh);
        fclose($fh);
        return [$bytes, $offset + strlen($bytes)];
    }

    public static function clear(string $name): void
    {
        @unlink(self::path($name));
    }
}

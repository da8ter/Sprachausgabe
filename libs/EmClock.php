<?php

declare(strict_types=1);

/** Uhr der Wiedergabe in Sekunden mit Nachkommastellen; der Prüfstand setzt $source, um sie zu steuern. */
final class EmClock
{
    /** @var (Closure(): float)|null */
    public static ?Closure $source = null;

    public static function now(): float
    {
        return self::$source !== null ? (self::$source)() : microtime(true);
    }
}

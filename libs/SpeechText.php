<?php

declare(strict_types=1);

/**
 * Text einer Ansage: Varianten (eine je Zeile, zufällig gewählt) und Platzhalter.
 *   {value} {old}   formatierter neuer / alter Wert der Auslöser-Variable
 *   {name}          Name der Auslöser-Variable
 *   {var:12345}     formatierter Wert einer beliebigen Variable
 *   {time} {date}   Uhrzeit (H:i) und Datum (d.m.Y)
 */
final class SpeechText
{
    /** @return array<int, string> die nicht leeren Zeilen */
    public static function variants(string $texts): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $texts) ?: [];
        return array_values(array_filter(array_map('trim', $lines), static fn(string $l): bool => $l !== ''));
    }

    public static function pick(string $texts): string
    {
        $variants = self::variants($texts);
        if ($variants === []) {
            return '';
        }
        return $variants[array_rand($variants)];
    }

    /**
     * @param int $triggerId Auslöser-Variable oder 0
     * @param mixed $old alter Wert (aus VM_UPDATE), null wenn unbekannt
     */
    public static function render(string $template, int $triggerId, mixed $old, int $now): string
    {
        $exists = $triggerId > 0 && @IPS_VariableExists($triggerId);
        $replace = [
            '{time}'  => date('H:i', $now),
            '{date}'  => date('d.m.Y', $now),
            '{value}' => $exists ? (string)@GetValueFormatted($triggerId) : '',
            '{name}'  => $exists ? (string)@IPS_GetName($triggerId) : '',
            '{old}'   => $exists && $old !== null ? self::formatOld($triggerId, $old) : '',
        ];
        $text = strtr($template, $replace);
        return (string)preg_replace_callback('/\{var:(\d+)\}/', static function (array $m): string {
            $id = (int)$m[1];
            return @IPS_VariableExists($id) ? (string)@GetValueFormatted($id) : '';
        }, $text);
    }

    /** Ein alter Wert lässt sich nicht über GetValueFormatted formatieren; Bool und Zahlen roh. */
    private static function formatOld(int $triggerId, mixed $old): string
    {
        if (is_bool($old)) {
            return $old ? 'true' : 'false';
        }
        return is_scalar($old) ? (string)$old : '';
    }
}

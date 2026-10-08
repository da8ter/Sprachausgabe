<?php

declare(strict_types=1);

/**
 * Versandwege der Push Zentrale. Symcon kennt keinen Versand an ein einzelnes Gerät: beide Wege
 * senden an alle Geräte, die der gewählten Visualisierung zugeordnet sind. Ein Empfänger ist
 * deshalb immer eine Visu-Instanz (eine Kachel-Visu bzw. ein WebFront je Person oder Gruppe).
 */
final class PushOutputs
{
    public const VISU = 'visu';
    public const WFC = 'wfc';

    /** Grenzen laut Doku von VISU_PostNotificationEx (Titel 32, Text 256 Zeichen). */
    public const TITLE_MAX = 32;
    public const TEXT_MAX = 256;

    /** Töne der Kachel-Visu laut Doku von VISU_PostNotificationEx. */
    public const SOUNDS = ['', 'alarm', 'bell', 'boom', 'buzzer', 'connected', 'dark', 'digital', 'drums', 'duck', 'full', 'happy',
        'horn', 'inception', 'kazoo', 'roll', 'siren', 'space', 'trickling', 'turn'];

    /** @return array<int, string> */
    public static function types(): array
    {
        return [self::VISU, self::WFC];
    }

    /**
     * Sendet eine Nachricht an einen Empfänger.
     *
     * @param array<string, mixed> $recipient eine Zeile der Liste "Recipients" der Zentrale
     * @return string '' bei Erfolg, sonst der Grund
     */
    public static function send(array $recipient, string $title, string $text, string $icon, string $sound, int $target): string
    {
        $instance = (int)($recipient['instance'] ?? 0);
        if ($instance <= 0 || !@IPS_InstanceExists($instance)) {
            return 'no visualization selected';
        }
        $title = self::cut($title, self::TITLE_MAX);
        $text = self::cut($text, self::TEXT_MAX);
        switch ((string)($recipient['type'] ?? '')) {
            case self::VISU:
                if (!function_exists('VISU_PostNotificationEx')) {
                    return 'tile visualization not available';
                }
                if ($target > 0 && !@IPS_ObjectExists($target)) {
                    $target = 0;
                }
                return self::call(static fn() => VISU_PostNotificationEx($instance, $title, $text, $icon, $sound, $target));
            case self::WFC:
                if (!function_exists('WFC_PushNotification')) {
                    return 'WebFront not available';
                }
                // the WebFront knows other sounds than the tile visualization; keep its default
                return self::call(static fn() => WFC_PushNotification($instance, $title, $text, '', 0));
        }
        return 'unknown type';
    }

    public static function cut(string $text, int $max): string
    {
        $text = trim($text);
        return mb_strlen($text) <= $max ? $text : rtrim(mb_substr($text, 0, $max - 1)) . '…';
    }

    /** Symcon meldet Fehler der Versandfunktionen als Warnung oder als false, nicht als Exception. */
    private static function call(callable $send): string
    {
        $error = '';
        set_error_handler(static function (int $no, string $message) use (&$error): bool {
            $error = $message;
            return true;
        });
        try {
            $result = $send();
        } catch (\Throwable $e) {
            return $e->getMessage();
        } finally {
            restore_error_handler();
        }
        if ($error !== '') {
            return $error;
        }
        return $result === false ? 'sending failed' : '';
    }
}

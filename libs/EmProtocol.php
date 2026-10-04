<?php

declare(strict_types=1);

/**
 * Nachrichten des EchoMuse-Gerätelinks (docs/device-controller-interface.md im Projekt
 * wilbowes/EchoMuse, Stand main 04.10.2026): JSON auf /control, Rahmen mit Typbyte auf /data.
 * Ein Fremd-Controller verhandelt über Fähigkeiten, nie über Versionen; Unbekanntes wird in
 * beide Richtungen ignoriert. Rein, ohne Symcon.
 */
final class EmProtocol
{
    /** /data Controller → Gerät */
    public const DATA_SPEAKER = 0x02;
    public const DATA_SPEAKER_EOS = 0x03;
    public const DATA_MUSIC = 0x04;
    public const DATA_MUSIC_EOS = 0x05;
    /** /data Gerät → Controller */
    public const DATA_MIC = 0x01;
    public const DATA_VAD_END = 0x04;
    public const DATA_NO_SPEECH = 0x05;
    public const DATA_SESSION_AUDIO = 0x07;

    /** Was dieser Controller kann: das Gerät fährt Entzerrer und Begrenzer selbst (Lautsprecherschutz). */
    public const FEATURES = ['output_chain'];

    /** Lautstärke: 0,5-dB-Schritte, 127 = Einheitsverstärkung; darüber übersteuert der Wandler. */
    public const VOLUME_MAX = 127;

    public static function ack(string $deviceId, int $timeMs): string
    {
        // time_ms: ein Echo kennt nach dem Einschalten keine Uhrzeit (Original-Controller sendet es ebenso)
        return self::json(['type' => 'ack', 'device_id' => $deviceId, 'features' => self::FEATURES, 'time_ms' => $timeMs]);
    }

    public static function pending(): string
    {
        return self::json(['type' => 'pending']);
    }

    public static function refused(): string
    {
        return self::json(['type' => 'refused']);
    }

    public static function volumeSet(int $level): string
    {
        return self::json(['type' => 'volume_set', 'level' => max(0, min(self::VOLUME_MAX, $level))]);
    }

    public static function duck(bool $on): string
    {
        return self::json(['type' => 'duck', 'on' => $on]);
    }

    /** @param array<int, array<string, mixed>> $leds ein Bild des Rings, Format wie in der Gerätedoku */
    public static function leds(array $leds): string
    {
        return self::json(['type' => 'leds', 'leds' => $leds]);
    }

    /** @param array<string, mixed> $spec {pattern, colors, periodMs, ttlSec} */
    public static function ledAnim(array $spec): string
    {
        return self::json(['type' => 'led_anim'] + $spec);
    }

    /** @param array<string, mixed> $config Teilupdate in camelCase; Nullwerte ignoriert das Gerät */
    public static function config(array $config): string
    {
        return self::json(['type' => 'config'] + $config);
    }

    public static function playCue(string $cue): string
    {
        return self::json(['type' => 'play_cue', 'cue' => $cue]);
    }

    public static function micStart(bool $lockMic = false): string
    {
        return self::json(['type' => 'mic_start', 'lock_mic' => $lockMic]);
    }

    public static function micStop(): string
    {
        return self::json(['type' => 'mic_stop']);
    }

    public static function speakerFlush(): string
    {
        return self::json(['type' => 'speaker_flush']);
    }

    /** Ein Sprach-PCM-Stück auf /data: Typbyte 0x02, danach mono S16LE 48 kHz. */
    public static function speakerFrame(string $pcm): string
    {
        return chr(self::DATA_SPEAKER) . $pcm;
    }

    public static function speakerEnd(): string
    {
        return chr(self::DATA_SPEAKER_EOS);
    }

    /**
     * Prüft die Anmeldung eines Geräts. Nur was sich sicher verwenden lässt, wird übernommen.
     *
     * @param array<string, mixed> $msg
     * @return array{id: string, version: string, caps: array<int, string>, ip: string, os: string, board: string}|null
     */
    public static function parseRegister(array $msg): ?array
    {
        if (($msg['type'] ?? '') !== 'register') {
            return null;
        }
        $id = (string)($msg['device_id'] ?? '');
        if (preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $id) !== 1) {
            return null;
        }
        $caps = [];
        foreach ((array)($msg['capabilities'] ?? []) as $cap) {
            if (is_string($cap) && preg_match('/^[a-z0-9_]{1,40}$/', $cap) === 1) {
                $caps[] = $cap;
            }
        }
        return [
            'id'      => $id,
            'version' => mb_substr((string)($msg['version'] ?? ''), 0, 40),
            'caps'    => $caps,
            'ip'      => filter_var((string)($msg['ip'] ?? ''), FILTER_VALIDATE_IP) !== false ? (string)$msg['ip'] : '',
            'os'      => mb_substr((string)($msg['base_os'] ?? ''), 0, 20),
            'board'   => mb_substr((string)($msg['board'] ?? ''), 0, 40),
        ];
    }

    /** @return array<string, mixed>|null */
    public static function decodeJson(string $text): ?array
    {
        $data = json_decode($text, true);
        return is_array($data) && isset($data['type']) && is_string($data['type']) ? $data : null;
    }

    /** Prozent (0–100) → Gerätelevel (0–127). */
    public static function percentToLevel(int $percent): int
    {
        return (int)round(max(0, min(100, $percent)) * self::VOLUME_MAX / 100);
    }

    public static function levelToPercent(int $level): int
    {
        return (int)round(max(0, min(self::VOLUME_MAX, $level)) * 100 / self::VOLUME_MAX);
    }

    /** @param array<string, mixed> $data */
    private static function json(array $data): string
    {
        return (string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

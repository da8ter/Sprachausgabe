<?php

declare(strict_types=1);

/**
 * Audio für den Lautsprecher des Dots: mono, 16 Bit, 48 kHz, in Stücken zu 2048 Samples
 * (42,7 ms). Eine WAV-Datei (beliebige Rate, mono oder stereo) wird gewandelt; MP3 nicht —
 * dafür fordert die KI-Stimme der Zentrale WAV beim Anbieter an. Rein, ohne Symcon.
 */
final class EmPcm
{
    public const RATE = 48000;
    public const PERIOD_SAMPLES = 2048;
    public const PERIOD_BYTES = 4096;
    public const PERIOD_SECONDS = self::PERIOD_SAMPLES / self::RATE;
    /** So weit darf die Wiedergabe der Echtzeit vorauslaufen: das Gerät puffert ~5,5 s, der Rest blockiert es. */
    public const LEAD_SECONDS = 3.0;
    /** Mehr als 60 s sind keine Ansage; schützt den Speicher. */
    public const MAX_SECONDS = 60;

    /**
     * @return array{pcm: string, error: string}
     */
    public static function fromWav(string $wav): array
    {
        if (strlen($wav) < 44 || substr($wav, 0, 4) !== 'RIFF' || substr($wav, 8, 4) !== 'WAVE') {
            return ['pcm' => '', 'error' => 'not a WAV file'];
        }
        $pos = 12;
        $fmt = null;
        $data = null;
        $total = strlen($wav);
        while ($pos + 8 <= $total) {
            $id = substr($wav, $pos, 4);
            $size = (int)unpack('V', substr($wav, $pos + 4, 4))[1];
            $body = $pos + 8;
            if ($id === 'fmt ' && $size >= 16) {
                $f = unpack('vtag/vch/Vrate/Vbyterate/valign/vbits', substr($wav, $body, 16));
                $fmt = is_array($f) ? $f : null;
            } elseif ($id === 'data') {
                // Streaming-WAV (Länge 0 oder 0xFFFFFFFF): der Rest der Datei ist der Ton
                $len = ($size === 0 || $size === 0xFFFFFFFF || $body + $size > $total) ? $total - $body : $size;
                $data = substr($wav, $body, $len);
                break;
            }
            $pos = $body + $size + ($size & 1);
        }
        if ($fmt === null || $data === null) {
            return ['pcm' => '', 'error' => 'WAV has no fmt or data chunk'];
        }
        $tag = (int)$fmt['tag'];
        if (($tag !== 1 && $tag !== 0xFFFE) || (int)$fmt['bits'] !== 16) {
            return ['pcm' => '', 'error' => 'only 16-bit PCM WAV is supported'];
        }
        $channels = (int)$fmt['ch'];
        $rate = (int)$fmt['rate'];
        if ($channels < 1 || $channels > 2 || $rate < 8000 || $rate > 192000) {
            return ['pcm' => '', 'error' => 'unsupported channel count or sample rate'];
        }
        $samples = unpack('s*', substr($data, 0, strlen($data) - (strlen($data) % (2 * $channels))));
        if (!is_array($samples) || $samples === []) {
            return ['pcm' => '', 'error' => 'WAV contains no audio'];
        }
        $samples = array_values($samples);
        if ($channels === 2) {
            $mono = [];
            for ($i = 0, $n = intdiv(count($samples), 2); $i < $n; $i++) {
                $mono[] = intdiv($samples[2 * $i] + $samples[2 * $i + 1], 2);
            }
            $samples = $mono;
        }
        if (count($samples) / $rate > self::MAX_SECONDS) {
            return ['pcm' => '', 'error' => 'audio longer than ' . self::MAX_SECONDS . ' seconds'];
        }
        return ['pcm' => self::pack(self::resample($samples, $rate, self::RATE)), 'error' => ''];
    }

    /**
     * Lineare Neuabtastung. Für Sprache genügt das; der Wandler des Dots filtert selbst.
     *
     * @param array<int, int> $in
     * @return array<int, int>
     */
    public static function resample(array $in, int $from, int $to): array
    {
        if ($from === $to) {
            return $in;
        }
        $count = count($in);
        $outCount = (int)floor($count * $to / $from);
        $step = $from / $to;
        $out = [];
        $last = $count - 1;
        for ($i = 0; $i < $outCount; $i++) {
            $pos = $i * $step;
            $idx = (int)$pos;
            $frac = $pos - $idx;
            $a = $in[$idx];
            $b = $in[min($idx + 1, $last)];
            $out[] = (int)round($a + ($b - $a) * $frac);
        }
        return $out;
    }

    /** @param array<int, int> $samples */
    public static function pack(array $samples): string
    {
        $bytes = '';
        foreach (array_chunk($samples, 8192) as $chunk) {
            $bytes .= pack('s*', ...$chunk);
        }
        return $bytes;
    }

    /**
     * PCM in Stücke zu einer Periode (4096 Byte); das letzte wird mit Stille aufgefüllt.
     *
     * @return array<int, string>
     */
    public static function periods(string $pcm): array
    {
        $out = [];
        $len = strlen($pcm);
        for ($i = 0; $i < $len; $i += self::PERIOD_BYTES) {
            $chunk = substr($pcm, $i, self::PERIOD_BYTES);
            $out[] = strlen($chunk) < self::PERIOD_BYTES ? str_pad($chunk, self::PERIOD_BYTES, "\0") : $chunk;
        }
        return $out;
    }

    public static function seconds(string $pcm): float
    {
        return strlen($pcm) / (self::RATE * 2);
    }

    /** Rohes mono-16-Bit-PCM in eine WAV-Datei einpacken (für Anbieter, die nur PCM liefern). */
    public static function wrapWav(string $pcm, int $rate): string
    {
        $len = strlen($pcm);
        return 'RIFF' . pack('V', 36 + $len) . 'WAVE' . 'fmt ' . pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16)
            . 'data' . pack('V', $len) . $pcm;
    }

    /** Ein Sinuston als Test (ohne KI-Stimme), mono 48 kHz. */
    public static function tone(float $seconds, float $hz = 440.0, float $amplitude = 0.3): string
    {
        $n = (int)round($seconds * self::RATE);
        $samples = [];
        for ($i = 0; $i < $n; $i++) {
            $fade = min(1.0, $i / 480, ($n - $i) / 480); // 10 ms Ein- und Ausblenden gegen Knacken
            $samples[] = (int)round(sin(2 * M_PI * $hz * $i / self::RATE) * $amplitude * $fade * 32767);
        }
        return self::pack($samples);
    }
}

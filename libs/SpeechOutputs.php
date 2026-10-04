<?php

declare(strict_types=1);

/**
 * Ausgabewege der Zentrale. Jede Art kennt genau einen Aufruf; fremde Module (Echo Remote,
 * Fully Kiosk) werden über ihre Präfix-Funktionen angesprochen und vorher auf Existenz geprüft,
 * damit eine fehlende Bibliothek als Warnung statt als Fatal endet.
 */
final class SpeechOutputs
{
    public const ECHO_SPEAK = 'echo_speak';
    public const ECHO_ANNOUNCE = 'echo_announce';
    public const FULLY = 'fully';
    public const SCRIPT = 'script';
    public const AI_SCRIPT = 'ai_script';
    public const ECHOMUSE = 'echomuse';

    /** @return array<int, string> */
    public static function types(): array
    {
        return [self::ECHO_SPEAK, self::ECHO_ANNOUNCE, self::FULLY, self::SCRIPT, self::AI_SCRIPT, self::ECHOMUSE];
    }

    /**
     * Spricht $text auf einem Ausgabegerät.
     *
     * @param array<string, mixed> $output eine Zeile der Liste "Outputs" der Zentrale
     * @return string '' bei Erfolg, sonst der Grund
     */
    public static function speak(array $output, string $text, int $volume): string
    {
        $type = (string)($output['type'] ?? '');
        $instance = (int)($output['instance'] ?? 0);
        switch ($type) {
            case self::ECHO_SPEAK:
                if (!self::instanceOk($instance)) {
                    return 'no Echo instance selected';
                }
                if ($volume > 0) {
                    return self::call('ECHOREMOTE_TextToSpeechVolume', [$instance, $text, $volume]);
                }
                return self::call('ECHOREMOTE_TextToSpeech', [$instance, $text]);
            case self::ECHO_ANNOUNCE:
                if (!self::instanceOk($instance)) {
                    return 'no Echo instance selected';
                }
                return self::call('ECHOREMOTE_Announcement', [$instance, $text]);
            case self::FULLY:
                if (!self::instanceOk($instance)) {
                    return 'no Fully Kiosk instance selected';
                }
                return self::call('FKB_textToSpeech', [$instance, $text]);
            case self::ECHOMUSE:
                if (!self::instanceOk($instance)) {
                    return 'no EchoMuse device selected';
                }
                $audio = (array)($output['audio'] ?? []);
                if ((string)($audio['error'] ?? 'no AI audio') !== '') {
                    return 'AI voice: ' . (string)($audio['error'] ?? 'no AI audio'); // der Dot hat keine eigene Stimme
                }
                if (!function_exists('EMGD_SpeakFile')) {
                    return 'EMGD_SpeakFile is not available (module not installed)';
                }
                try {
                    return (string)EMGD_SpeakFile($instance, (string)($audio['file'] ?? '')); // '' = angenommen, sonst der Grund
                } catch (\Throwable $e) {
                    return 'EMGD_SpeakFile: ' . $e->getMessage();
                }
            case self::SCRIPT:
            case self::AI_SCRIPT:
                $script = (int)($output['script'] ?? 0);
                if ($script <= 0 || !@IPS_ScriptExists($script)) {
                    return 'no script selected';
                }
                $audio = (array)($output['audio'] ?? []);
                if ($type === self::AI_SCRIPT && (string)($audio['error'] ?? 'no AI audio') !== '') {
                    return 'AI voice: ' . (string)($audio['error'] ?? 'no AI audio'); // the script plays a file; without one it has nothing to do
                }
                IPS_RunScriptEx($script, [
                    'TEXT'       => $text,
                    'VOLUME'     => (string)$volume,
                    'TARGET'     => (string)($output['name'] ?? ''),
                    'AUDIO_URL'  => (string)($audio['url'] ?? ''),
                    'AUDIO_FILE' => (string)($audio['file'] ?? ''),
                ]);
                return '';
        }
        return 'unknown output type ' . $type;
    }

    private static function instanceOk(int $id): bool
    {
        return $id > 0 && @IPS_InstanceExists($id);
    }

    /** @param array<int, mixed> $args */
    private static function call(string $function, array $args): string
    {
        if (!function_exists($function)) {
            return $function . ' is not available (module not installed)';
        }
        try {
            $result = $function(...$args);
        } catch (\Throwable $e) {
            return $function . ': ' . $e->getMessage();
        }
        return $result === false ? $function . ' returned false' : '';
    }
}

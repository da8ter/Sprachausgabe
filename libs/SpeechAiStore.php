<?php

declare(strict_types=1);

require_once __DIR__ . '/SpeechAi.php';

/**
 * KI-Stimme in der Zentrale: Einstellungen, Zwischenspeicher (eine Datei je Text und Stimme,
 * ein Text wird nur einmal bezahlt) und der Webhook, über den Abspieler die Datei holen.
 * Die Adresse enthält nur die Kennung (SHA-256 aus Text, Anbieter und Stimme) — nicht zu
 * erraten, und ausgeliefert wird nur, was im Zwischenspeicher liegt.
 */
trait SpeechAiStore
{
    private const AI_HOOK = '/hook/sprachausgabe';
    private const AI_CACHE_MAX = 300;

    /** @var array<int, string> Eigenschaft → Schlüssel in SpeechAi */
    private const AI_PROPERTIES = [
        'AiProvider' => 'provider', 'AiInstructions' => 'instructions',
        'AiOpenAIKey' => 'openai_key', 'AiOpenAIModel' => 'openai_model', 'AiOpenAIVoice' => 'openai_voice',
        'AiAzureKey' => 'azure_key', 'AiAzureRegion' => 'azure_region', 'AiAzureVoice' => 'azure_voice',
        'AiElevenKey' => 'eleven_key', 'AiElevenVoice' => 'eleven_voice', 'AiElevenModel' => 'eleven_model',
        'AiPollyKey' => 'polly_key', 'AiPollySecret' => 'polly_secret', 'AiPollyRegion' => 'polly_region',
        'AiPollyVoice' => 'polly_voice', 'AiPollyEngine' => 'polly_engine',
        'AiGeminiKey' => 'gemini_key', 'AiGeminiModel' => 'gemini_model', 'AiGeminiVoice' => 'gemini_voice',
    ];

    private function aiRegister(): void
    {
        foreach (array_keys(self::AI_PROPERTIES) as $name) {
            $this->RegisterPropertyString($name, '');
        }
        $this->RegisterPropertyString('AiBaseUrl', '');
        $this->RegisterHook(self::AI_HOOK);
    }

    private function ai(): SpeechAi
    {
        $cfg = [];
        foreach (self::AI_PROPERTIES as $property => $key) {
            $cfg[$key] = $this->ReadPropertyString($property);
        }
        return new SpeechAi($cfg);
    }

    /**
     * Tondatei für $text: aus dem Zwischenspeicher oder neu erzeugt.
     * @return array{url: string, file: string, error: string}
     */
    private function aiAudio(string $text): array
    {
        $ai = $this->ai();
        $missing = $ai->missing();
        if ($missing !== '') {
            return ['url' => '', 'file' => '', 'error' => $missing];
        }
        $name = $ai->hash($text) . '.' . $ai->format();
        $file = $this->aiDir() . $name;
        if (!is_file($file)) {
            $result = $ai->synthesize($text);
            if ($result['error'] !== '') {
                return ['url' => '', 'file' => '', 'error' => $result['error']];
            }
            if (strlen($result['audio']) > $this->aiOutputLimit()) {
                return ['url' => '', 'file' => '', 'error' => 'audio larger than the hook output limit (shorten the text)'];
            }
            if (@file_put_contents($file, $result['audio']) === false) {
                return ['url' => '', 'file' => '', 'error' => 'audio file could not be written'];
            }
            $this->aiEvict();
        } else {
            @touch($file); // keeps it at the front of the cache
        }
        return ['url' => $this->aiBaseUrl() . self::AI_HOOK . '/' . $name, 'file' => $file, 'error' => ''];
    }

    /** Webhook: liefert eine Datei des Zwischenspeichers aus. */
    protected function ProcessHookData(): void
    {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        $name = basename((string)parse_url($uri, PHP_URL_PATH));
        if (preg_match('/^[a-f0-9]{64}\.(mp3|wav)$/', $name, $m) !== 1 || !is_file($this->aiDir() . $name)) {
            http_response_code(404);
            echo 'Not found';
            return;
        }
        $file = $this->aiDir() . $name;
        header('Content-Type: ' . ($m[1] === 'wav' ? 'audio/wav' : 'audio/mpeg'));
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: public, max-age=31536000, immutable'); // the name is the content hash
        readfile($file);
    }

    private function aiDir(): string
    {
        $dir = rtrim(IPS_GetKernelDir(), '/') . '/media/sprachausgabe_' . $this->InstanceID . '/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /** Älteste Dateien (nach letzter Nutzung) fliegen raus, wenn es mehr als AI_CACHE_MAX sind. */
    private function aiEvict(): void
    {
        $files = glob($this->aiDir() . '*.{mp3,wav}', GLOB_BRACE) ?: [];
        if (count($files) <= self::AI_CACHE_MAX) {
            return;
        }
        usort($files, static fn(string $a, string $b): int => filemtime($a) <=> filemtime($b));
        foreach (array_slice($files, 0, count($files) - self::AI_CACHE_MAX) as $old) {
            @unlink($old);
        }
    }

    /** Die Hook-Ausgabe hat eine Obergrenze (ScriptOutputBufferLimit); darüber ersetzt Symcon die Antwort. */
    private function aiOutputLimit(): int
    {
        $limit = (int)@IPS_GetOption('ScriptOutputBufferLimit');
        return $limit > 0 ? $limit : 1048576;
    }

    /** Adresse, unter der Abspieler im LAN Symcon erreichen: Einstellung, sonst erste LAN-Adresse :3777. */
    private function aiBaseUrl(): string
    {
        $base = rtrim(trim($this->ReadPropertyString('AiBaseUrl')), '/');
        if ($base !== '') {
            return $base;
        }
        if (function_exists('Sys_GetNetworkInfo')) {
            foreach ((array)@Sys_GetNetworkInfo() as $nic) {
                $ip = (string)($nic['IP'] ?? '');
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && !str_starts_with($ip, '127.') && !str_starts_with($ip, '169.254.')) {
                    return 'http://' . $ip . ':3777';
                }
            }
        }
        return 'http://127.0.0.1:3777';
    }

    /** Formular: Testansage als Datei erzeugen und Adresse zeigen. */
    public function TestAiVoice(): void
    {
        $r = $this->aiAudio($this->Translate('This is a test announcement.'));
        echo $r['error'] === '' ? $this->Translate('Audio ready') . ': ' . $r['url'] : $this->Translate('Failed') . ': ' . $this->Translate($r['error']);
    }

    /** @return array<string, mixed> Formular-Bereich der KI-Stimme */
    private function aiFormPanel(): array
    {
        $opt = static fn(array $values): array => array_map(static fn(string $v): array => ['caption' => $v, 'value' => $v], $values);
        $providers = [['caption' => '-', 'value' => '']];
        foreach (['openai' => 'OpenAI', 'azure' => 'Microsoft Azure', 'elevenlabs' => 'ElevenLabs', 'polly' => 'Amazon Polly', 'gemini' => 'Google Gemini'] as $v => $c) {
            $providers[] = ['caption' => $c, 'value' => $v];
        }
        $pw = static fn(string $name, string $caption): array => ['type' => 'PasswordTextBox', 'name' => $name, 'caption' => $caption, 'width' => '420px'];
        $tb = static fn(string $name, string $caption): array => ['type' => 'ValidationTextBox', 'name' => $name, 'caption' => $caption, 'width' => '420px'];
        return ['type' => 'ExpansionPanel', 'caption' => 'AI voice (for outputs of type "AI voice")', 'items' => [
            ['type' => 'Select', 'name' => 'AiProvider', 'caption' => 'Provider', 'options' => $providers],
            $tb('AiInstructions', 'Speaking style (OpenAI, Gemini)'),
            $tb('AiBaseUrl', 'Address of Symcon for players (empty = automatic)'),
            ['type' => 'ExpansionPanel', 'caption' => 'OpenAI', 'items' => [
                $pw('AiOpenAIKey', 'API key'), $tb('AiOpenAIModel', 'Model (default gpt-4o-mini-tts)'),
                ['type' => 'Select', 'name' => 'AiOpenAIVoice', 'caption' => 'Voice', 'options' => $opt(SpeechAi::OPENAI_VOICES)]]],
            ['type' => 'ExpansionPanel', 'caption' => 'Microsoft Azure', 'items' => [
                $pw('AiAzureKey', 'Key'), $tb('AiAzureRegion', 'Region (e.g. westeurope)'),
                ['type' => 'Select', 'name' => 'AiAzureVoice', 'caption' => 'Voice', 'options' => $opt(SpeechAi::AZURE_VOICES)]]],
            ['type' => 'ExpansionPanel', 'caption' => 'ElevenLabs', 'items' => [
                $pw('AiElevenKey', 'API key'), $tb('AiElevenVoice', 'Voice ID'), $tb('AiElevenModel', 'Model (default eleven_multilingual_v2)')]],
            ['type' => 'ExpansionPanel', 'caption' => 'Amazon Polly', 'items' => [
                $pw('AiPollyKey', 'Access key ID'), $pw('AiPollySecret', 'Secret access key'), $tb('AiPollyRegion', 'Region (default eu-central-1)'),
                ['type' => 'Select', 'name' => 'AiPollyVoice', 'caption' => 'Voice', 'options' => $opt(SpeechAi::POLLY_VOICES)],
                ['type' => 'Select', 'name' => 'AiPollyEngine', 'caption' => 'Engine', 'options' => $opt(SpeechAi::POLLY_ENGINES)]]],
            ['type' => 'ExpansionPanel', 'caption' => 'Google Gemini', 'items' => [
                $pw('AiGeminiKey', 'API key'),
                ['type' => 'Select', 'name' => 'AiGeminiModel', 'caption' => 'Model', 'options' => $opt(SpeechAi::GEMINI_MODELS)],
                ['type' => 'Select', 'name' => 'AiGeminiVoice', 'caption' => 'Voice', 'options' => $opt(SpeechAi::GEMINI_VOICES)]]],
            ['type' => 'Button', 'caption' => 'Test AI voice', 'onClick' => 'SPAZ_TestAiVoice($id);'],
        ]];
    }
}

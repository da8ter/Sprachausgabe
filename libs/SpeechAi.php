<?php

declare(strict_types=1);

require_once __DIR__ . '/AwsSigV4.php';
require_once __DIR__ . '/EmPcm.php';

/**
 * KI-Stimme: Text → Tondatei bei einem der fünf Anbieter, die auch SymDo kennt
 * (OpenAI, Azure, ElevenLabs, Amazon Polly, Google Gemini). Die Aufrufe folgen dem
 * gemessenen Stand aus SymDoGateway/libs/Tts.php, inklusive dessen Fallen:
 *  - ElevenLabs: output_format gehört in die ADRESSE, im Rumpf wird es ignoriert.
 *  - Azure und Polly: der Text wird ins SSML maskiert eingesetzt.
 *  - Gemini liefert WAV (Base64 im JSON), alle anderen MP3.
 * Rein, ohne Symcon: die HTTP-Funktion ist austauschbar, damit der Prüfstand ohne Netz läuft.
 */
final class SpeechAi
{
    public const PROVIDERS = ['openai', 'azure', 'elevenlabs', 'polly', 'gemini'];

    public const OPENAI_VOICES = ['alloy', 'ash', 'ballad', 'coral', 'echo', 'fable', 'marin', 'nova', 'onyx', 'sage', 'shimmer', 'verse'];
    public const AZURE_VOICES = ['de-DE-KatjaNeural', 'de-DE-ConradNeural', 'de-DE-ChristophNeural', 'de-DE-KillianNeural',
        'de-DE-RalfNeural', 'de-DE-KasperNeural', 'de-DE-BerndNeural', 'de-DE-AmalaNeural', 'de-DE-ElkeNeural',
        'de-DE-GiselaNeural', 'de-DE-KlarissaNeural', 'de-DE-LouisaNeural', 'de-DE-MajaNeural', 'de-DE-TanjaNeural',
        'de-DE-FlorianMultilingualNeural', 'de-DE-SeraphinaMultilingualNeural'];
    public const POLLY_VOICES = ['Vicki', 'Daniel', 'Marlene', 'Hans'];
    public const POLLY_ENGINES = ['neural', 'standard', 'long-form', 'generative'];
    public const GEMINI_MODELS = ['gemini-3.8-flash-tts', 'gemini-3.8-flash-lite-tts'];
    public const GEMINI_VOICES = ['Kore', 'Zephyr', 'Puck', 'Charon', 'Fenrir', 'Leda', 'Orus', 'Aoede', 'Callirrhoe', 'Autonoe',
        'Enceladus', 'Iapetus', 'Umbriel', 'Algieba', 'Despina', 'Erinome', 'Algenib', 'Rasalgethi', 'Laomedeia', 'Achernar',
        'Alnilam', 'Schedar', 'Gacrux', 'Pulcherrima', 'Achird', 'Zubenelgenubi', 'Vindemiatrix', 'Sadachbia', 'Sadaltager', 'Sulafat'];

    private const DEFAULTS = [
        'provider' => '',
        'openai_key' => '', 'openai_model' => 'gpt-4o-mini-tts', 'openai_voice' => 'alloy',
        'instructions' => 'Sprich auf Deutsch, freundlich, klar und in ruhigem Tempo.',
        'azure_key' => '', 'azure_region' => 'westeurope', 'azure_voice' => 'de-DE-KatjaNeural',
        'eleven_key' => '', 'eleven_voice' => '21m00Tcm4TlvDq8ikWAM', 'eleven_model' => 'eleven_multilingual_v2',
        'polly_key' => '', 'polly_secret' => '', 'polly_region' => 'eu-central-1', 'polly_voice' => 'Vicki', 'polly_engine' => 'neural',
        'gemini_key' => '', 'gemini_model' => 'gemini-3.8-flash-tts', 'gemini_voice' => 'Kore',
    ];

    /** Prüfstand: ersetzt curl für alle Instanzen (null = echtes Netz). */
    public static ?Closure $transport = null;

    /** @var array<string, string> */
    private array $cfg;
    /** @var Closure(string, array<int, string>, string): array{status:int, body:string, err:string} */
    private Closure $http;

    /**
     * @param array<string, mixed> $cfg Schlüssel wie in DEFAULTS
     * @param Closure|null $http POST-Funktion (url, headers, body) → [status, body, err]; null = curl
     */
    public function __construct(array $cfg, ?Closure $http = null)
    {
        $merged = self::DEFAULTS;
        foreach ($cfg as $k => $v) {
            if (array_key_exists($k, $merged) && is_scalar($v) && trim((string)$v) !== '') {
                $merged[$k] = trim((string)$v);
            }
        }
        $this->cfg = $merged;
        $this->http = $http ?? self::$transport ?? static fn(string $url, array $headers, string $body): array => self::curlPost($url, $headers, $body);
    }

    public function provider(): string
    {
        return in_array($this->cfg['provider'], self::PROVIDERS, true) ? $this->cfg['provider'] : '';
    }

    /** Ist der gewählte Anbieter vollständig eingerichtet? '' = ja, sonst was fehlt. */
    public function missing(): string
    {
        switch ($this->provider()) {
            case '':
                return 'no AI voice provider selected';
            case 'openai':
                return $this->cfg['openai_key'] === '' ? 'OpenAI key missing' : '';
            case 'azure':
                return $this->cfg['azure_key'] === '' ? 'Azure key missing' : '';
            case 'elevenlabs':
                return $this->cfg['eleven_key'] === '' ? 'ElevenLabs key missing' : '';
            case 'polly':
                return $this->cfg['polly_key'] === '' || $this->cfg['polly_secret'] === '' ? 'Polly access key or secret missing' : '';
            case 'gemini':
                return $this->cfg['gemini_key'] === '' ? 'Gemini key missing' : '';
        }
        return 'unknown provider';
    }

    /** Ausgabeformat der Aufnahme: mp3, oder wav (immer für Gemini, auf Wunsch bei allen anderen — Echo-Dots spielen PCM). */
    public function format(bool $wav = false): string
    {
        return $wav || $this->provider() === 'gemini' ? 'wav' : 'mp3';
    }

    /** Kennung einer Aufnahme: Text, Anbieter, Stimme und Format — ändert sich eins, entsteht sie neu. */
    public function hash(string $text, bool $wav = false): string
    {
        $p = $this->provider();
        $voice = match ($p) {
            'openai' => $this->cfg['openai_model'] . '/' . $this->cfg['openai_voice'] . '/' . $this->cfg['instructions'],
            'azure' => $this->cfg['azure_voice'],
            'elevenlabs' => $this->cfg['eleven_model'] . '/' . $this->cfg['eleven_voice'],
            'polly' => $this->cfg['polly_engine'] . '/' . $this->cfg['polly_voice'],
            'gemini' => $this->cfg['gemini_model'] . '/' . $this->cfg['gemini_voice'] . '/' . $this->cfg['instructions'],
            default => '',
        };
        return hash('sha256', $p . '|' . $voice . '|' . $this->format($wav) . '|' . $text);
    }

    /** @return array{audio: string, error: string} */
    public function synthesize(string $text, bool $wav = false): array
    {
        $missing = $this->missing();
        if ($missing !== '') {
            return ['audio' => '', 'error' => $missing];
        }
        try {
            return match ($this->provider()) {
                'openai' => $this->openai($text, $wav),
                'azure' => $this->azure($text, $wav),
                'elevenlabs' => $this->eleven($text, $wav),
                'polly' => $this->polly($text, $wav),
                'gemini' => $this->gemini($text),
            };
        } catch (\Throwable $e) {
            return ['audio' => '', 'error' => $e->getMessage()];
        }
    }

    // ------------------------------------------------------------------ Anbieter

    private function openai(string $text, bool $wav): array
    {
        $body = (string)json_encode([
            'model' => $this->cfg['openai_model'],
            'voice' => in_array($this->cfg['openai_voice'], self::OPENAI_VOICES, true) ? $this->cfg['openai_voice'] : 'alloy',
            'input' => $text,
            'instructions' => $this->cfg['instructions'],
            'response_format' => $wav ? 'wav' : 'mp3',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $this->audioFrom('OpenAI', ($this->http)('https://api.openai.com/v1/audio/speech',
            ['Authorization: Bearer ' . $this->cfg['openai_key'], 'Content-Type: application/json'], $body));
    }

    private function azure(string $text, bool $wav): array
    {
        $voice = $this->cfg['azure_voice'];
        $lang = preg_match('/^([a-z]{2}-[A-Z]{2})-/', $voice, $m) === 1 ? $m[1] : 'de-DE';
        $ssml = '<speak version="1.0" xmlns="http://www.w3.org/2001/10/synthesis" xml:lang="' . $lang . '">'
            . '<voice name="' . htmlspecialchars($voice, ENT_QUOTES | ENT_XML1, 'UTF-8') . '">'
            . htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8')
            . '</voice></speak>';
        $region = preg_replace('/[^a-z0-9]/', '', strtolower($this->cfg['azure_region'])) ?: 'westeurope';
        return $this->audioFrom('Azure', ($this->http)('https://' . $region . '.tts.speech.microsoft.com/cognitiveservices/v1', [
            'Ocp-Apim-Subscription-Key: ' . $this->cfg['azure_key'],
            'Content-Type: application/ssml+xml; charset=utf-8',
            'X-Microsoft-OutputFormat: ' . ($wav ? 'riff-24khz-16bit-mono-pcm' : 'audio-24khz-48kbitrate-mono-mp3'),
            'User-Agent: SymconSprachausgabe',
        ], $ssml));
    }

    private function eleven(string $text, bool $wav): array
    {
        $voice = preg_replace('/[^A-Za-z0-9_-]/', '', $this->cfg['eleven_voice']) ?: self::DEFAULTS['eleven_voice'];
        $body = (string)json_encode([
            'text' => $text,
            'model_id' => $this->cfg['eleven_model'],
            'voice_settings' => ['stability' => 0.5, 'similarity_boost' => 0.75, 'style' => 0.0, 'use_speaker_boost' => true, 'speed' => 1.0],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $result = $this->audioFrom('ElevenLabs', ($this->http)(
            'https://api.elevenlabs.io/v1/text-to-speech/' . $voice . '?output_format=' . ($wav ? 'pcm_24000' : 'mp3_44100_64'),
            ['xi-api-key: ' . $this->cfg['eleven_key'], 'Content-Type: application/json', 'Accept: audio/mpeg', 'User-Agent: SymconSprachausgabe'],
            $body
        ));
        $a = $result['audio'];
        if ($wav && $a !== '') {
            return ['audio' => EmPcm::wrapWav($a, 24000), 'error' => '']; // rohes 16-Bit-PCM, 24 kHz
        }
        if ($a !== '' && !str_starts_with($a, 'ID3') && !(strlen($a) > 1 && $a[0] === "\xFF")) {
            return ['audio' => '', 'error' => 'ElevenLabs: expected MP3, got ' . mb_substr($a, 0, 120)];
        }
        return $result;
    }

    private function polly(string $text, bool $wav): array
    {
        $region = preg_replace('/[^a-z0-9-]/', '', strtolower($this->cfg['polly_region'])) ?: 'eu-central-1';
        $engine = in_array($this->cfg['polly_engine'], self::POLLY_ENGINES, true) ? $this->cfg['polly_engine'] : 'neural';
        $body = (string)json_encode([
            'OutputFormat' => $wav ? 'pcm' : 'mp3', 'Text' => $text, 'TextType' => 'text',
            'VoiceId' => $this->cfg['polly_voice'], 'Engine' => $engine, 'LanguageCode' => 'de-DE',
        ] + ($wav ? ['SampleRate' => '16000'] : []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $host = 'polly.' . $region . '.amazonaws.com';
        $headers = [];
        foreach (AwsSigV4::Headers('POST', $host, '/v1/speech', '', $body, ['Content-Type' => 'application/json'],
            $region, 'polly', $this->cfg['polly_key'], $this->cfg['polly_secret']) as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }
        $result = $this->audioFrom('Polly', ($this->http)('https://' . $host . '/v1/speech', $headers, $body));
        if ($wav && $result['error'] === '') {
            $result['audio'] = EmPcm::wrapWav($result['audio'], 16000); // Polly-PCM: 16 kHz, 16 Bit, mono, ohne Kopf
        }
        if ($result['error'] !== '' && (strlen($this->cfg['polly_key']) !== 20 || strlen($this->cfg['polly_secret']) !== 40)) {
            $result['error'] .= ' (AWS access keys have 20 characters, secrets 40)';
        }
        return $result;
    }

    private function gemini(string $text): array
    {
        $part = ['type' => 'text', 'text' => $text];
        if ($this->cfg['instructions'] !== '') {
            $part['annotations'] = [['type' => 'speech_metadata', 'style' => $this->cfg['instructions']]];
        }
        $body = (string)json_encode([
            'model' => in_array($this->cfg['gemini_model'], self::GEMINI_MODELS, true) ? $this->cfg['gemini_model'] : self::GEMINI_MODELS[0],
            'input' => [['type' => 'user_input', 'content' => [$part]]],
            'response_format' => ['type' => 'audio', 'mime_type' => 'audio/wav'],
            'generation_config' => ['speech_config' => [['voice' => in_array($this->cfg['gemini_voice'], self::GEMINI_VOICES, true) ? $this->cfg['gemini_voice'] : 'Kore']]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $resp = ($this->http)('https://generativelanguage.googleapis.com/v1beta/interactions',
            ['x-goog-api-key: ' . $this->cfg['gemini_key'], 'Content-Type: application/json'], $body);
        if ($resp['err'] !== '' || $resp['status'] !== 200) {
            return ['audio' => '', 'error' => 'Gemini ' . ($resp['err'] !== '' ? $resp['err'] : 'HTTP ' . $resp['status'] . ': ' . mb_substr($resp['body'], 0, 200))];
        }
        $wav = self::geminiAudio($resp['body']);
        return $wav === '' ? ['audio' => '', 'error' => 'Gemini: no audio in the answer'] : ['audio' => $wav, 'error' => ''];
    }

    // ------------------------------------------------------------------ Hilfen

    /** @param array{status:int, body:string, err:string} $resp */
    private function audioFrom(string $who, array $resp): array
    {
        if ($resp['err'] !== '') {
            return ['audio' => '', 'error' => $who . ': ' . $resp['err']];
        }
        if ($resp['status'] !== 200 || $resp['body'] === '') {
            return ['audio' => '', 'error' => $who . ' HTTP ' . $resp['status'] . ': ' . mb_substr($resp['body'], 0, 200)];
        }
        if (str_starts_with(ltrim($resp['body']), '{')) {
            return ['audio' => '', 'error' => $who . ': expected audio, got JSON ' . mb_substr($resp['body'], 0, 200)];
        }
        return ['audio' => $resp['body'], 'error' => ''];
    }

    /** Gemini: letztes Audio-Stück aus steps[].content[] oder candidates[].parts[]; rohes PCM bekommt einen WAV-Kopf. */
    public static function geminiAudio(string $json): string
    {
        $d = json_decode($json, true);
        if (!is_array($d)) {
            return '';
        }
        $b64 = '';
        $mime = '';
        foreach ((array)($d['steps'] ?? []) as $step) {
            if (is_array($step) && ($step['type'] ?? '') === 'model_output') {
                foreach ((array)($step['content'] ?? []) as $c) {
                    if (is_array($c) && ($c['type'] ?? '') === 'audio' && is_string($c['data'] ?? null)) {
                        $b64 = $c['data'];
                        $mime = (string)($c['mime_type'] ?? ($c['mimeType'] ?? ''));
                    }
                }
            }
        }
        if ($b64 === '') {
            foreach ((array)($d['candidates'][0]['content']['parts'] ?? []) as $p) {
                $inline = is_array($p) ? ($p['inlineData'] ?? ($p['inline_data'] ?? null)) : null;
                if (is_array($inline) && is_string($inline['data'] ?? null)) {
                    $b64 = $inline['data'];
                    $mime = (string)($inline['mimeType'] ?? ($inline['mime_type'] ?? ''));
                }
            }
        }
        $raw = $b64 === '' ? false : base64_decode($b64, true);
        if (!is_string($raw) || $raw === '') {
            return '';
        }
        if (str_starts_with($raw, 'RIFF')) {
            return $raw;
        }
        $rate = preg_match('/rate=(\d+)/i', $mime, $m) === 1 ? (int)$m[1] : 24000;
        return 'RIFF' . pack('V', 36 + strlen($raw)) . 'WAVE' . 'fmt ' . pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16)
            . 'data' . pack('V', strlen($raw)) . $raw;
    }

    /**
     * @param array<int, string> $headers
     * @return array{status:int, body:string, err:string}
     */
    private static function curlPost(string $url, array $headers, string $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $res = curl_exec($ch);
        $err = $res === false ? curl_error($ch) : '';
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $code, 'body' => is_string($res) ? $res : '', 'err' => $err];
    }
}

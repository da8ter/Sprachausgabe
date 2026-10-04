<?php

declare(strict_types=1);

/**
 * Der Sprachweg von SymDo (Instanz SymDo Gateway, Hook lists/app) für den Dot: SymDo prägt den
 * Zugangsschlüssel der Realtime-Sitzung samt Anweisungen, Werkzeugen und Zuhörsteuerung, führt
 * die Werkzeuge aus (Listen, Termine, Geräte …) und zählt die Sprechzeit gegen das Tagesbudget.
 * Der OpenAI-Schlüssel bleibt in SymDo. Rein, die HTTP-Funktion ist austauschbar.
 */
final class SymDoVoiceClient
{
    /** Prüfstand: ersetzt curl für alle Instanzen (null = echtes Netz). */
    public static ?Closure $transport = null;

    /** @var Closure(string, array<int, string>, string): array{status: int, body: string, err: string} */
    private Closure $post;

    /** @param Closure|null $post POST (url, headers, body) → [status, body, err]; null = curl */
    public function __construct(private string $baseUrl, private string $token, ?Closure $post = null)
    {
        $this->post = $post ?? self::$transport ?? static fn(string $url, array $headers, string $body): array => self::curlPost($url, $headers, $body);
    }

    public function configured(): bool
    {
        return preg_match('#^https?://#', $this->baseUrl) === 1 && $this->token !== '';
    }

    /**
     * Eine Sitzung öffnen: Zugangsschlüssel und Modell.
     * @return array{ok: bool, value?: string, model?: string, live?: bool, sessionSeconds?: int, error?: string}
     */
    public function open(string $userId, int $tile): array
    {
        $r = $this->call(['action' => 'open', 'tile' => $tile, 'userId' => $userId]);
        if (($r['ok'] ?? false) !== true) {
            return ['ok' => false, 'error' => (string)($r['error']['message'] ?? $r['error'] ?? 'open failed')];
        }
        if (($r['live'] ?? false) === true) {
            return ['ok' => false, 'error' => 'SymDo is set to GPT-Live (WebRTC); choose a Realtime model in the SymDo voice settings'];
        }
        if (!is_string($r['value'] ?? null) || $r['value'] === '') {
            return ['ok' => false, 'error' => 'no access token in the answer'];
        }
        return ['ok' => true, 'value' => $r['value'], 'model' => (string)($r['model'] ?? 'gpt-realtime-mini'), 'sessionSeconds' => (int)($r['sessionSeconds'] ?? 60)];
    }

    public function opened(string $callId, string $userId, int $tile): bool
    {
        return ($this->call(['action' => 'opened', 'callId' => $callId, 'userId' => $userId, 'tile' => $tile])['ok'] ?? false) === true;
    }

    /** @return array<string, mixed> das Ergebnis des Werkzeugs (geht als Ausgabe an das Modell) */
    public function tool(string $callId, string $userId, string $name, string $argsJson, string $fnId): array
    {
        return $this->call(['action' => 'tool', 'callId' => $callId, 'userId' => $userId, 'name' => $name, 'arguments' => $argsJson, 'fnId' => $fnId]);
    }

    public function close(string $callId): void
    {
        $this->call(['action' => 'close', 'callId' => $callId]);
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    private function call(array $body): array
    {
        $resp = ($this->post)(rtrim($this->baseUrl, '/') . '/v1/voice',
            ['Authorization: Bearer ' . $this->token, 'Content-Type: application/json'],
            (string)json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($resp['err'] !== '') {
            return ['ok' => false, 'error' => ['message' => $resp['err']]];
        }
        $data = json_decode($resp['body'], true);
        if (!is_array($data)) {
            return ['ok' => false, 'error' => ['message' => 'HTTP ' . $resp['status'] . ' without JSON']];
        }
        return $resp['status'] >= 400 && !isset($data['error']) ? $data + ['ok' => false, 'error' => ['message' => 'HTTP ' . $resp['status']]] : $data;
    }

    /** @param array<int, string> $headers @return array{status: int, body: string, err: string} */
    private static function curlPost(string $url, array $headers, string $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_FOLLOWLOCATION => false]);
        $res = curl_exec($ch);
        $err = $res === false ? curl_error($ch) : '';
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $code, 'body' => is_string($res) ? $res : '', 'err' => $err];
    }
}

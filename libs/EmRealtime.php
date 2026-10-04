<?php

declare(strict_types=1);

/**
 * Nachrichten der OpenAI-Realtime-Schnittstelle (WebSocket): Audio hinein, Ereignisse heraus.
 * Die Sitzung (Anweisungen, Werkzeuge, Spracherkennung, Zuhörsteuerung) legt SymDo beim Prägen
 * des Zugangsschlüssels fest; hier wird nur gesprochen. Die Ereignisnamen der beiden Fassungen
 * (response.output_audio.* der aktuellen, response.audio.* der Beta) werden gleich behandelt.
 * Rein, ohne Symcon.
 */
final class EmRealtime
{
    public const HOST = 'api.openai.com';
    /** Realtime rechnet mit 24 kHz, 16 Bit, mono. */
    public const RATE = 24000;
    public const DEVICE_MIC_RATE = 16000;

    public static function path(string $model): string
    {
        return '/v1/realtime?model=' . rawurlencode($model);
    }

    /** @return array<string, string> */
    public static function headers(string $secret): array
    {
        return ['Authorization' => 'Bearer ' . $secret];
    }

    public static function appendAudio(string $pcm24k): string
    {
        return self::json(['type' => 'input_audio_buffer.append', 'audio' => base64_encode($pcm24k)]);
    }

    public static function commit(): string
    {
        return self::json(['type' => 'input_audio_buffer.commit']);
    }

    /** Antwort auf einen Werkzeugaufruf; danach muss das Modell mit response.create weitersprechen. */
    public static function functionOutput(string $callId, string $output): string
    {
        return self::json(['type' => 'conversation.item.create', 'item' => ['type' => 'function_call_output', 'call_id' => $callId, 'output' => $output]]);
    }

    public static function responseCreate(): string
    {
        return self::json(['type' => 'response.create']);
    }

    public static function responseCancel(): string
    {
        return self::json(['type' => 'response.cancel']);
    }

    /**
     * Ein Ereignis des Servers in eine Form bringen, mit der das Modul arbeiten kann.
     *
     * @return array{kind: string, ...}
     *   kind: audio (pcm24k), speech_started, speech_stopped, tool (name, args, callId), transcript (text, role),
     *         done, error (message), created, other
     */
    public static function parseEvent(string $json): array
    {
        $e = json_decode($json, true);
        if (!is_array($e) || !is_string($e['type'] ?? null)) {
            return ['kind' => 'other'];
        }
        $type = $e['type'];
        switch ($type) {
            case 'response.output_audio.delta':
            case 'response.audio.delta':
                $pcm = base64_decode((string)($e['delta'] ?? ''), true);
                return $pcm === false || $pcm === '' ? ['kind' => 'other'] : ['kind' => 'audio', 'pcm24k' => $pcm];
            case 'input_audio_buffer.speech_started':
                return ['kind' => 'speech_started'];
            case 'input_audio_buffer.speech_stopped':
                return ['kind' => 'speech_stopped'];
            case 'response.function_call_arguments.done':
                return ['kind' => 'tool', 'name' => (string)($e['name'] ?? ''), 'args' => (string)($e['arguments'] ?? '{}'), 'callId' => (string)($e['call_id'] ?? '')];
            case 'conversation.item.input_audio_transcription.completed':
                return ['kind' => 'transcript', 'role' => 'user', 'text' => (string)($e['transcript'] ?? '')];
            case 'response.output_audio_transcript.done':
            case 'response.audio_transcript.done':
                return ['kind' => 'transcript', 'role' => 'assistant', 'text' => (string)($e['transcript'] ?? '')];
            case 'response.done':
                return ['kind' => 'done', 'status' => (string)($e['response']['status'] ?? ''), 'tools' => self::toolsInDone($e)];
            case 'session.created':
            case 'session.updated':
                return ['kind' => 'created'];
            case 'error':
                return ['kind' => 'error', 'message' => mb_substr((string)($e['error']['message'] ?? 'unknown error'), 0, 300), 'code' => (string)($e['error']['code'] ?? '')];
        }
        return ['kind' => 'other'];
    }

    /** Werkzeugaufrufe aus response.done, falls das Modell sie dort statt einzeln meldet. @return array<int, array{name: string, args: string, callId: string}> */
    private static function toolsInDone(array $e): array
    {
        $out = [];
        foreach ((array)($e['response']['output'] ?? []) as $item) {
            if (is_array($item) && ($item['type'] ?? '') === 'function_call') {
                $out[] = ['name' => (string)($item['name'] ?? ''), 'args' => (string)($item['arguments'] ?? '{}'), 'callId' => (string)($item['call_id'] ?? '')];
            }
        }
        return $out;
    }

    /** @param array<string, mixed> $data */
    private static function json(array $data): string
    {
        return (string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

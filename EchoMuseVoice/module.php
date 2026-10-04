<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/EmWebSocket.php';
require_once __DIR__ . '/../libs/EmWsClient.php';
require_once __DIR__ . '/../libs/EmPcm.php';
require_once __DIR__ . '/../libs/EmRealtime.php';
require_once __DIR__ . '/../libs/EmSpool.php';
require_once __DIR__ . '/../libs/EmClock.php';
require_once __DIR__ . '/../libs/SymDoVoiceClient.php';

/**
 * EchoMuse Voice: eine Sprachrunde zwischen einem Dot und der Realtime-Schnittstelle von OpenAI.
 * Sitzung, Anweisungen und Werkzeuge kommen von SymDo (Zugangsschlüssel und Werkzeugaufrufe über
 * dessen Sprachweg, der OpenAI-Schlüssel bleibt dort). Das Mikrofon kommt als Spool-Datei vom
 * Gateway, die Antwort geht als Spool-Datei zurück; gemeldet wird über Variablen (VM_UPDATE wird
 * asynchron zugestellt), damit sich die beiden Instanzen nie gegenseitig aufrufen und blockieren.
 *
 * Eine Sitzung gleichzeitig: ein zweiter Dot wird abgewiesen, solange die erste läuft.
 */
class EchoMuseVoice extends IPSModuleStrict
{
    private const SOCKET_RX = '{018EF6B5-AB94-40C6-AA53-46943E824ACF}';
    private const CLIENT_SOCKET_GUID = '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}';
    private const MIC_BATCH_SAMPLES = 1280; // 80 ms bei 16 kHz

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyString('SymDoUrl', 'http://127.0.0.1:3777/hook/lists/app');
        $this->RegisterPropertyString('SymDoToken', '');
        $this->RegisterPropertyString('UserId', '');
        $this->RegisterPropertyInteger('GatewayInstance', 0);
        $this->RegisterPropertyInteger('MaxSeconds', 60);
        $this->RegisterPropertyString('RealtimeHost', EmRealtime::HOST);
        $this->RegisterPropertyInteger('RealtimePort', 443);
        $this->RegisterPropertyBoolean('RealtimeSSL', true);
        $this->RegisterTimer('Watch', 0, 'EMVS_Watch($_IPS[\'TARGET\']);');
        $this->RegisterVariableString('EVENT', $this->Translate('Event for the gateway'), ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION], 90);
        IPS_SetHidden($this->GetIDForIdent('EVENT'), true);
        $this->RegisterVariableString('STATE', $this->Translate('State'), ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'Microphone'], 10);
        $this->RegisterVariableString('LAST_TEXT', $this->Translate('Last request'), ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'Speech'], 20);
        $this->ConnectParent(self::CLIENT_SOCKET_GUID);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }
        foreach ($this->GetMessageList() as $sender => $messages) {
            foreach ($messages as $message) {
                if ($message === VM_UPDATE || $message === IM_CHANGESTATUS) {
                    $this->UnregisterMessage((int)$sender, $message);
                }
            }
        }
        $gw = $this->ReadPropertyInteger('GatewayInstance');
        $cmd = $gw > 0 ? (int)@IPS_GetObjectIDByIdent('VOICE_CMD', $gw) : 0;
        $mic = $gw > 0 ? (int)@IPS_GetObjectIDByIdent('VOICE_MIC', $gw) : 0;
        foreach ([$cmd, $mic] as $var) {
            if ($var > 0) {
                $this->RegisterMessage($var, VM_UPDATE);
            }
        }
        $io = $this->ioId();
        if ($io > 0) {
            $this->RegisterMessage($io, IM_CHANGESTATUS);
        }
        $this->SetTimerInterval('Watch', 1000);
        $this->SetSummary($this->ReadPropertyString('RealtimeHost'));
        $this->SetStatus($gw > 0 && $this->symdo()->configured() ? IS_ACTIVE : 201);
    }

    public function GetConfigurationForParent(): string
    {
        return (string)json_encode([
            'Host' => $this->ReadPropertyString('RealtimeHost'), 'Port' => $this->ReadPropertyInteger('RealtimePort'),
            'UseSSL' => $this->ReadPropertyBoolean('RealtimeSSL'), 'VerifyHost' => true, 'VerifyPeer' => true, 'Open' => false,
        ]);
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
            return;
        }
        $gw = $this->ReadPropertyInteger('GatewayInstance');
        if ($Message === VM_UPDATE && $gw > 0) {
            if ($SenderID === (int)@IPS_GetObjectIDByIdent('VOICE_CMD', $gw)) {
                $this->command((string)($Data[0] ?? ''));
            } elseif ($SenderID === (int)@IPS_GetObjectIDByIdent('VOICE_MIC', $gw)) {
                $this->pumpMic();
            }
            return;
        }
        if ($Message === IM_CHANGESTATUS && $SenderID === $this->ioId() && (int)($Data[0] ?? 0) === IS_ACTIVE) {
            $s = $this->session();
            if ($s !== null && $s['state'] === 'connecting') {
                $this->sendUpgrade();
            }
        }
    }

    /** Bytes vom Client Socket (Hex, wie bei allen Modulen unter Module Strict). */
    public function ReceiveData(string $JSONString): string
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data) || ($data['DataID'] ?? '') !== self::SOCKET_RX) {
            return '';
        }
        $bytes = (string)hex2bin((string)($data['Buffer'] ?? ''));
        if ($bytes !== '') {
            $this->feed($bytes);
        }
        return '';
    }

    /** Timer: Sitzungen, die zu lange dauern oder hängen, werden beendet. */
    public function Watch(): void
    {
        $s = $this->session();
        if ($s === null) {
            return;
        }
        $age = (int)EmClock::now() - (int)$s['started'];
        if ($age > max(10, $this->ReadPropertyInteger('MaxSeconds'))) {
            $this->finish('timeout', true);
        } elseif ($s['state'] === 'connecting' && $age > 15) {
            $this->finish('connect timeout', true);
        }
    }

    // ------------------------------------------------------------------ Befehle des Gateways

    private function command(string $json): void
    {
        $c = json_decode($json, true);
        if (!is_array($c)) {
            return;
        }
        switch ((string)($c['cmd'] ?? '')) {
            case 'start':
                $this->start((string)$c['dev'], (int)$c['session']);
                break;
            case 'stop':
                $s = $this->session();
                if ($s !== null && $s['dev'] === (string)($c['dev'] ?? '')) {
                    $this->finish('stopped', false);
                }
                break;
        }
    }

    private function start(string $dev, int $sessionNo): void
    {
        $old = $this->session();
        if ($old !== null) {
            if ($old['dev'] !== $dev) {
                $this->emit(['ev' => 'busy', 'dev' => $dev, 'session' => $sessionNo]);
                return;
            }
            $this->finish('replaced', false); // derselbe Dot hat neu geweckt (Barge-in)
        }
        $user = trim($this->ReadPropertyString('UserId'));
        $tile = 900000 + (crc32($dev) % 90000);
        $open = $this->symdo()->open($user, $tile);
        if (!$open['ok']) {
            $this->LogMessage($this->Translate('Voice session not opened') . ': ' . $open['error'], KL_WARNING);
            $this->emit(['ev' => 'error', 'dev' => $dev, 'session' => $sessionNo, 'message' => $open['error']]);
            return;
        }
        $callId = 'echomuse-' . preg_replace('/[^A-Za-z0-9]/', '', $dev) . '-' . (int)EmClock::now();
        $this->symdo()->opened($callId, $user, $tile);
        // mic_ NICHT leeren: das Gateway schreibt seit dem Wake hinein (und leert dort selbst), der Satzanfang liegt schon da
        EmSpool::clear('out_' . $dev);
        $this->saveSession(['dev' => $dev, 'session' => $sessionNo, 'callId' => $callId, 'user' => $user, 'tile' => $tile,
            'state' => 'connecting', 'secret' => $open['value'], 'model' => $open['model'], 'key' => EmWsClient::newKey(),
            'buf' => '', 'partial' => '', 'pop' => 0, 'outOff' => 0, 'started' => (int)EmClock::now(), 'tools' => 0, 'text' => '']);
        $this->SetBuffer('MIC', '0'); // eigener Puffer: pumpMic läuft aus der Nachrichtenbehandlung und darf den Empfangsstand nicht zurückschreiben
        $this->SetValue('STATE', $this->Translate('connecting'));
        $this->openSocket();
    }

    // ------------------------------------------------------------------ Verbindung zur Realtime-Schnittstelle

    private function openSocket(): void
    {
        $io = $this->ioId();
        if ($io <= 0) {
            $this->finish('no socket', true);
            return;
        }
        IPS_SetConfiguration($io, $this->GetConfigurationForParent());
        IPS_ApplyChanges($io); // Open=false: sicher zurücksetzen, dann öffnen
        $cfg = json_decode($this->GetConfigurationForParent(), true);
        $cfg['Open'] = true;
        IPS_SetConfiguration($io, (string)json_encode($cfg));
        IPS_ApplyChanges($io);
        if ((int)(IPS_GetInstance($io)['InstanceStatus'] ?? 0) === IS_ACTIVE) {
            $this->sendUpgrade();
        } // sonst folgt IM_CHANGESTATUS
    }

    private function sendUpgrade(): void
    {
        $s = $this->session();
        if ($s === null || $s['state'] !== 'connecting') {
            return;
        }
        $s['state'] = 'handshake';
        $this->saveSession($s);
        $this->sendRaw(EmWsClient::request($this->ReadPropertyString('RealtimeHost'), EmRealtime::path((string)$s['model']), (string)$s['key'], EmRealtime::headers((string)$s['secret'])));
    }

    private function feed(string $bytes): void
    {
        $s = $this->session();
        if ($s === null) {
            return;
        }
        $buffer = (string)base64_decode((string)$s['buf'], true) . $bytes;
        try {
            if ($s['state'] === 'handshake') {
                $r = EmWsClient::parseResponse($buffer);
                if ($r === null) {
                    $s['buf'] = base64_encode($buffer);
                    $this->saveSession($s);
                    return;
                }
                if (!EmWsClient::accepted($r['status'], $r['headers'], (string)$s['key'])) {
                    $this->LogMessage(sprintf('%s: HTTP %d', $this->Translate('Realtime connection refused'), $r['status']), KL_WARNING);
                    $this->finish('refused', true);
                    return;
                }
                $s['state'] = 'ready';
                $buffer = $r['rest'];
                $this->SetValue('STATE', $this->Translate('listening'));
            }
            $out = EmWebSocket::decode($buffer, (string)base64_decode((string)$s['partial'], true), (int)$s['pop'], false);
            $s['buf'] = base64_encode($out['buffer']);
            $s['partial'] = base64_encode($out['partial']);
            $s['pop'] = $out['partialOp'];
            $this->saveSession($s);
            if ($s['state'] === 'ready') {
                $this->pumpMic(); // was während des Verbindens gesprochen wurde, liegt schon in der Spool-Datei
            }
            foreach ($out['messages'] as $m) {
                if ($this->session() === null) {
                    return;
                }
                if ($m['op'] === EmWebSocket::OP_TEXT) {
                    $this->event(EmRealtime::parseEvent($m['data']));
                } elseif ($m['op'] === EmWebSocket::OP_PING) {
                    $this->sendRaw(EmWsClient::pong($m['data']));
                } elseif ($m['op'] === EmWebSocket::OP_CLOSE) {
                    $this->finish('closed by server', true);
                }
            }
        } catch (\InvalidArgumentException $e) {
            $this->SendDebug('Protocol', $e->getMessage() . ' state=' . ($s['state'] ?? '?') . ' in=' . strlen($bytes) . ' buf=' . strlen($buffer) . ' head=' . bin2hex(substr($buffer, 0, 16)), 0);
            $this->finish('protocol error', true);
        }
    }

    // ------------------------------------------------------------------ Mikrofon hinein, Antwort heraus

    private function pumpMic(): void
    {
        $s = $this->session();
        if ($s === null || $s['state'] !== 'ready') {
            return;
        }
        [$pcm, $off] = EmSpool::read('mic_' . $s['dev'], (int)$this->GetBuffer('MIC'));
        if ($pcm === '') {
            return;
        }
        $this->SetBuffer('MIC', (string)$off);
        $samples = array_values((array)unpack('s*', substr($pcm, 0, strlen($pcm) - (strlen($pcm) % 2))));
        for ($i = 0; $i < count($samples); $i += self::MIC_BATCH_SAMPLES * 4) { // etwa 320 ms je Nachricht
            $chunk = array_slice($samples, $i, self::MIC_BATCH_SAMPLES * 4);
            $this->sendRaw(EmWsClient::text(EmRealtime::appendAudio(EmPcm::pack(EmPcm::resample($chunk, EmRealtime::DEVICE_MIC_RATE, EmRealtime::RATE)))));
        }
    }

    /** @param array<string, mixed> $e */
    private function event(array $e): void
    {
        $s = $this->session();
        if ($s === null) {
            return;
        }
        if ($e['kind'] !== 'other') {
            $this->SendDebug('Event', (string)$e['kind'] . (isset($e['text']) ? ': ' . mb_substr((string)$e['text'], 0, 120) : '') . (isset($e['pcm24k']) ? ' ' . strlen((string)$e['pcm24k']) . ' B' : '') . (isset($e['message']) ? ': ' . $e['message'] : ''), 0);
        }
        switch ($e['kind']) {
            case 'speech_stopped':
                $this->emit(['ev' => 'speech_end', 'dev' => $s['dev'], 'session' => $s['session']]);
                break;
            case 'audio':
                EmSpool::append('out_' . $s['dev'], (string)$e['pcm24k']);
                $this->emit(['ev' => 'audio', 'dev' => $s['dev'], 'session' => $s['session']]);
                break;
            case 'transcript':
                if ($e['role'] === 'user' && $e['text'] !== '') {
                    $this->SetValue('LAST_TEXT', mb_substr(trim((string)$e['text']), 0, 200));
                }
                break;
            case 'tool':
                $this->tool($e['name'], $e['args'], $e['callId']);
                break;
            case 'done':
                $this->done($e);
                break;
            case 'error':
                $this->LogMessage($this->Translate('Realtime error') . ': ' . $e['message'], KL_WARNING);
                $this->emit(['ev' => 'error', 'dev' => $s['dev'], 'session' => $s['session'], 'message' => $e['message']]);
                $this->finish('error', true);
                break;
        }
    }

    private function tool(string $name, string $args, string $callId): void
    {
        $s = $this->session();
        if ($s === null || $name === '') {
            return;
        }
        $done = (array)json_decode((string)($s['doneTools'] ?? '[]'), true);
        if (in_array($callId, $done, true)) {
            return;
        }
        $done[] = $callId;
        $s['doneTools'] = json_encode($done);
        $s['tools'] = (int)$s['tools'] + 1;
        $s['pendingTool'] = true;
        $this->saveSession($s);
        $t0 = microtime(true);
        $result = $this->symdo()->tool((string)$s['callId'], (string)$s['user'], $name, $args, $callId); // blockiert diese Instanz kurz; das Gateway läuft weiter
        $this->SendDebug('Tool', sprintf('%s %d ms', $name, (int)round((microtime(true) - $t0) * 1000)), 0);
        $this->sendRaw(EmWsClient::text(EmRealtime::functionOutput($callId, (string)json_encode($result, JSON_UNESCAPED_UNICODE))));
        $this->sendRaw(EmWsClient::text(EmRealtime::responseCreate()));
    }

    /** @param array<string, mixed> $e */
    private function done(array $e): void
    {
        $s = $this->session();
        if ($s === null) {
            return;
        }
        foreach ($e['tools'] as $t) { // Aufrufe, die nur in response.done stehen
            $this->tool($t['name'], $t['args'], $t['callId']);
        }
        $s = $this->session();
        if ($s === null) {
            return;
        }
        if (!empty($s['pendingTool'])) {
            $s['pendingTool'] = false; // die Antwort auf das Werkzeugergebnis kommt in der nächsten response.done
            $this->saveSession($s);
            return;
        }
        $this->finish($e['status'] === 'completed' || $e['status'] === '' ? 'completed' : (string)$e['status'], true);
    }

    // ------------------------------------------------------------------ Ende

    private function finish(string $reason, bool $closeSocket): void
    {
        $s = $this->session();
        if ($s === null) {
            return;
        }
        $this->SetBuffer('S', '');
        if ($closeSocket) {
            if ($s['state'] === 'ready') {
                $this->sendRaw(EmWsClient::close(1000));
            }
        }
        $io = $this->ioId();
        if ($io > 0) {
            $cfg = json_decode($this->GetConfigurationForParent(), true);
            IPS_SetConfiguration($io, (string)json_encode($cfg)); // Open=false
            IPS_ApplyChanges($io);
        }
        $this->symdo()->close((string)$s['callId']);
        EmSpool::clear('mic_' . $s['dev']);
        $this->emit(['ev' => 'finished', 'dev' => $s['dev'], 'session' => $s['session'], 'reason' => $reason]);
        $this->SetValue('STATE', $this->Translate('idle'));
        $this->SendDebug('Session', sprintf('%s: %s', $s['dev'], $reason), 0);
    }

    // ------------------------------------------------------------------ Hilfen

    /** @param array<string, mixed> $event */
    private function emit(array $event): void
    {
        // Eine neue Zahl je Meldung: gleicher Wert würde keine Aktualisierung auslösen, die meisten Meldungen wiederholen sich
        $event['n'] = (int)($this->GetBuffer('N') ?: 0) + 1;
        $this->SetBuffer('N', (string)$event['n']);
        $this->SetValue('EVENT', (string)json_encode($event));
    }

    private function sendRaw(string $bytes): void
    {
        $io = $this->ioId();
        if ($io > 0 && function_exists('CSCK_SendText')) {
            @CSCK_SendText($io, $bytes);
        }
    }

    private function ioId(): int
    {
        return (int)(@IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
    }

    private function symdo(): SymDoVoiceClient
    {
        return new SymDoVoiceClient($this->ReadPropertyString('SymDoUrl'), $this->ReadPropertyString('SymDoToken'));
    }

    /** @return array<string, mixed>|null */
    private function session(): ?array
    {
        $s = json_decode($this->GetBuffer('S'), true);
        return is_array($s) && $s !== [] ? $s : null;
    }

    /** @param array<string, mixed> $s */
    private function saveSession(array $s): void
    {
        $this->SetBuffer('S', (string)json_encode($s));
    }

    public function GetConfigurationForm(): string
    {
        return (string)json_encode([
            'elements' => [
                ['type' => 'ValidationTextBox', 'name' => 'SymDoUrl', 'caption' => 'SymDo address (hook of the SymDo Gateway)', 'width' => '500px'],
                ['type' => 'PasswordTextBox', 'name' => 'SymDoToken', 'caption' => 'SymDo access token', 'width' => '500px'],
                ['type' => 'ValidationTextBox', 'name' => 'UserId', 'caption' => 'SymDo user ID (whose lists and appointments the voice uses)', 'width' => '500px'],
                ['type' => 'SelectInstance', 'name' => 'GatewayInstance', 'caption' => 'EchoMuse Gateway'],
                ['type' => 'NumberSpinner', 'name' => 'MaxSeconds', 'caption' => 'Longest conversation', 'minimum' => 10, 'maximum' => 600, 'suffix' => ' s'],
                ['type' => 'ExpansionPanel', 'caption' => 'Realtime connection (leave unchanged)', 'items' => [
                    ['type' => 'ValidationTextBox', 'name' => 'RealtimeHost', 'caption' => 'Host'],
                    ['type' => 'NumberSpinner', 'name' => 'RealtimePort', 'caption' => 'Port'],
                    ['type' => 'CheckBox', 'name' => 'RealtimeSSL', 'caption' => 'TLS'],
                ]],
            ],
            'actions' => [],
            'status' => [['code' => 201, 'icon' => 'inactive', 'caption' => 'SymDo address, token and gateway are required']],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

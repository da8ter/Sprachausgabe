<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/EmWebSocket.php';
require_once __DIR__ . '/../libs/EmProtocol.php';
require_once __DIR__ . '/../libs/EmPcm.php';
require_once __DIR__ . '/../libs/EmClock.php';
require_once __DIR__ . '/../libs/EmGatewayState.php';
require_once __DIR__ . '/../libs/EmGatewayPlayback.php';

/**
 * EchoMuse Gateway: Symcon als Controller für Echo Dots mit der EchoMuse-Firmware
 * (github.com/wilbowes/EchoMuse). Die Dots wählen sich auf einen Port (Standard 8767) ein und
 * öffnen /control (JSON) und /data (Binär). Symcon hat keinen WebSocket-Server-I/O, deshalb
 * sitzt das Gateway auf einem Server Socket (TCP) und setzt Handshake und Rahmen selbst um.
 *
 * Zustand (Verbindungen, Geräte, laufende Wiedergabe) liegt in Buffern der Instanz: sie
 * überleben den einzelnen Aufruf und enden mit einem Neustart, wie die Verbindungen selbst.
 */
class EchoMuseGateway extends IPSModuleStrict
{
    use EmGatewayState;
    use EmGatewayPlayback;

    private const SOCKET_GUID = '{8062CF2B-600E-41D6-AD4B-1BA66C32D6ED}';
    private const SOCKET_RX = '{7A1272A4-CBDB-46EF-BFC6-DCF4A53D2FC7}';
    private const SOCKET_TX = '{C8792760-65CF-4C53-B5C7-A30FCC84FEFE}';
    private const CHILD_TX = '{046405B3-995F-400E-95B2-4EB912CB0818}'; // Gerät → Gateway
    private const CHILD_RX = '{70B90512-B075-499B-A777-C70F7FD0D7FF}'; // Gateway → Gerät
    private const DEVICE_GUID = '{077221A9-EA1F-4A00-8BA3-679A85233E80}';

    private const IDLE_CLOSE_SECONDS = 60;

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyInteger('Port', 8767);
        $this->RegisterPropertyBoolean('AutoApprove', false);
        $this->RegisterAttributeString('Approved', '[]');
        $this->RegisterAttributeString('Pending', '{}');
        $this->RegisterTimer('Keepalive', 0, 'EMGW_Keepalive($_IPS[\'TARGET\']);');
        $this->RegisterTimer('Pump', 0, 'EMGW_Pump($_IPS[\'TARGET\']);');
        $this->ConnectParent(self::SOCKET_GUID);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }
        $this->SetTimerInterval('Keepalive', 20000);
        $this->SetSummary(sprintf('Port %d', $this->ReadPropertyInteger('Port')));
        $this->SetStatus(IS_ACTIVE);
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
        }
    }

    /** Der Server Socket bekommt Port und Öffnen vom Gateway vorgegeben. */
    public function GetConfigurationForParent(): string
    {
        return (string)json_encode(['Port' => $this->ReadPropertyInteger('Port'), 'Open' => true]);
    }

    // ------------------------------------------------------------------ Bytes vom Server Socket

    public function ReceiveData(string $JSONString): string
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data) || ($data['DataID'] ?? '') !== self::SOCKET_RX) {
            return '';
        }
        $key = (string)($data['ClientIP'] ?? '') . ':' . (int)($data['ClientPort'] ?? 0);
        switch ((int)($data['Type'] ?? 0)) {
            case 1: // verbunden
                $conns = $this->conns();
                $conns[$key] = ['ip' => (string)$data['ClientIP'], 'port' => (int)$data['ClientPort'], 'phase' => 'http', 'path' => '',
                    'buf' => '', 'partial' => '', 'pop' => 0, 'seen' => (int)EmClock::now(), 'dev' => ''];
                $this->saveConns($conns);
                break;
            case 2: // getrennt
                $this->dropConnection($key, false);
                break;
            default:
                $bytes = mb_convert_encoding((string)($data['Buffer'] ?? ''), 'ISO-8859-1', 'UTF-8');
                $this->feed($key, (string)$data['ClientIP'], (int)$data['ClientPort'], $bytes);
        }
        return '';
    }

    private function feed(string $key, string $ip, int $port, string $bytes): void
    {
        $conns = $this->conns();
        if (!isset($conns[$key])) { // Verbunden-Meldung verpasst (Neustart des Gateways): Verbindung nachtragen
            $conns[$key] = ['ip' => $ip, 'port' => $port, 'phase' => 'http', 'path' => '', 'buf' => '', 'partial' => '', 'pop' => 0, 'seen' => (int)EmClock::now(), 'dev' => ''];
        }
        $c = $conns[$key];
        $c['seen'] = (int)EmClock::now();
        $buffer = base64_decode($c['buf'], true);
        $buffer = ($buffer === false ? '' : $buffer) . $bytes;
        try {
            if ($c['phase'] === 'http') {
                $req = EmWebSocket::parseRequest($buffer);
                if ($req === null) {
                    $c['buf'] = base64_encode($buffer);
                    $conns[$key] = $c;
                    $this->saveConns($conns);
                    return;
                }
                $path = rtrim($req['path'], '/');
                if (!EmWebSocket::isUpgrade($req['headers']) || !in_array($path, ['/control', '/data'], true)) {
                    $this->sendRaw($ip, $port, EmWebSocket::httpError(400, 'Bad Request'));
                    $this->dropConnection($key, true);
                    return;
                }
                $this->sendRaw($ip, $port, EmWebSocket::handshakeResponse($req['headers']['sec-websocket-key']));
                $c['phase'] = 'ws';
                $c['path'] = $path;
                $buffer = $req['rest'];
                $this->SendDebug('Connect', sprintf('%s %s', $key, $path), 0);
            }
            $out = EmWebSocket::decode($buffer, (string)base64_decode($c['partial'], true), (int)$c['pop']);
            $c['buf'] = base64_encode($out['buffer']);
            $c['partial'] = base64_encode($out['partial']);
            $c['pop'] = $out['partialOp'];
            $conns[$key] = $c;
            $this->saveConns($conns);
            foreach ($out['messages'] as $m) {
                $this->message($key, $m['op'], $m['data']);
            }
        } catch (\InvalidArgumentException $e) {
            $this->SendDebug('Protocol', $key . ': ' . $e->getMessage(), 0);
            $this->sendRaw($ip, $port, ($conns[$key]['phase'] ?? 'http') === 'http' && $c['phase'] === 'http'
                ? EmWebSocket::httpError(400, 'Bad Request') : EmWebSocket::close(1002));
            $this->dropConnection($key, true);
        }
    }

    private function message(string $key, int $op, string $data): void
    {
        $conns = $this->conns();
        $c = $conns[$key] ?? null;
        if ($c === null) {
            return;
        }
        switch ($op) {
            case EmWebSocket::OP_PING:
                $this->sendRaw($c['ip'], $c['port'], EmWebSocket::frame(EmWebSocket::OP_PONG, $data));
                return;
            case EmWebSocket::OP_PONG:
                return;
            case EmWebSocket::OP_CLOSE:
                $this->sendRaw($c['ip'], $c['port'], EmWebSocket::close(1000));
                $this->dropConnection($key, true);
                return;
            case EmWebSocket::OP_TEXT:
                if ($c['path'] === '/control') {
                    $this->control($key, $data);
                }
                return;
            default:
                // /data: ohne mic_start schickt das Gerät nichts; Mikrofon-Audio folgt mit der Sprachrunde
                return;
        }
    }

    private function control(string $key, string $text): void
    {
        $msg = EmProtocol::decodeJson($text);
        if ($msg === null) {
            return;
        }
        $conns = $this->conns();
        $c = $conns[$key];
        $this->SendDebug('Control<', mb_substr($text, 0, 300), 0);
        if ($msg['type'] === 'register') {
            $this->register($key, $c, $msg);
            return;
        }
        $id = (string)$c['dev'];
        if ($id === '') {
            return; // ohne Anmeldung zählt nichts
        }
        switch ($msg['type']) {
            case 'button':
                $this->toChildren($id, 'button', ['click' => (string)($msg['clickType'] ?? ''), 'down' => (bool)($msg['down'] ?? false), 'held' => (int)($msg['heldMs'] ?? 0)]);
                break;
            case 'mute_state':
                $this->toChildren($id, 'mute', ['muted' => (bool)($msg['muted'] ?? false)]);
                break;
            case 'volume_state':
                $this->toChildren($id, 'volume', ['level' => (int)($msg['level'] ?? 0)]);
                break;
            case 'stats':
                $this->touch($id);
                break;
        }
    }

    /** @param array<string, mixed> $c @param array<string, mixed> $msg */
    private function register(string $key, array $c, array $msg): void
    {
        $reg = EmProtocol::parseRegister($msg);
        if ($reg === null) {
            $this->sendRaw($c['ip'], $c['port'], EmWebSocket::close(1008));
            $this->dropConnection($key, true);
            return;
        }
        $id = $reg['id'];
        if (!$this->approved($id)) {
            $pending = json_decode($this->ReadAttributeString('Pending'), true) ?: [];
            $pending[$id] = ['ip' => $c['ip'], 'version' => $reg['version'], 'seen' => (int)EmClock::now()];
            $this->WriteAttributeString('Pending', (string)json_encode($pending));
            $this->LogMessage(sprintf('%s: %s (%s)', $this->Translate('Unknown EchoMuse device waiting for approval'), $id, $c['ip']), KL_NOTIFY);
            $this->sendRaw($c['ip'], $c['port'], EmWebSocket::text(EmProtocol::pending()));
            $this->sendRaw($c['ip'], $c['port'], EmWebSocket::close(1000));
            $this->dropConnection($key, true);
            return;
        }
        // Eine neue Anmeldung ersetzt eine alte desselben Geräts
        foreach ($this->conns() as $k => $other) {
            if ($k !== $key && $other['dev'] === $id && $other['path'] === '/control') {
                $this->dropConnection($k, true);
            }
        }
        $conns = $this->conns();
        $conns[$key]['dev'] = $id;
        $this->saveConns($conns);
        $devices = $this->devices();
        $devices[$id] = ['key' => $key, 'ip' => $c['ip'], 'version' => $reg['version'], 'caps' => $reg['caps'], 'os' => $reg['os'], 'board' => $reg['board'], 'seen' => (int)EmClock::now()];
        $this->saveDevices($devices);
        $this->sendRaw($c['ip'], $c['port'], EmWebSocket::text(EmProtocol::ack($id, (int)(microtime(true) * 1000))));
        $this->toChildren($id, 'online', ['version' => $reg['version'], 'caps' => $reg['caps'], 'ip' => $c['ip']]);
    }

    // ------------------------------------------------------------------ Anfragen der Geräte-Instanzen

    public function ForwardData(string $JSONString): string
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data) || ($data['DataID'] ?? '') !== self::CHILD_TX) {
            return '';
        }
        $id = (string)($data['DeviceId'] ?? '');
        switch ((string)($data['Action'] ?? '')) {
            case 'Control':
                $message = (string)($data['Message'] ?? '');
                return EmProtocol::decodeJson($message) === null ? 'invalid message' : $this->sendControl($id, $message);
            case 'SpeakFile':
                return $this->speakFile($id, (string)($data['File'] ?? ''));
            case 'Beep':
                return $this->queuePcm($id, EmPcm::tone(max(0.2, min(5.0, (float)($data['Seconds'] ?? 1.0)))));
            case 'Online':
                return isset($this->devices()[$id]) ? '1' : '';
        }
        return '';
    }

    /** @return string '' bei Erfolg, sonst der Grund */
    private function sendControl(string $id, string $json): string
    {
        $dev = $this->devices()[$id] ?? null;
        $c = $dev !== null ? ($this->conns()[$dev['key']] ?? null) : null;
        if ($c === null) {
            return 'device is offline';
        }
        $this->SendDebug('Control>', mb_substr($json, 0, 300), 0);
        $this->sendRaw($c['ip'], $c['port'], EmWebSocket::text($json));
        return '';
    }

    private function speakFile(string $id, string $file): string
    {
        $base = realpath(rtrim(IPS_GetKernelDir(), '/') . '/media');
        $real = $file !== '' ? realpath($file) : false;
        if ($base === false || $real === false || !str_starts_with($real, $base . DIRECTORY_SEPARATOR) || !is_file($real)) {
            return 'audio file not found';
        }
        $conv = EmPcm::fromWav((string)file_get_contents($real));
        return $conv['error'] !== '' ? $conv['error'] : $this->queuePcm($id, $conv['pcm']);
    }

    // ------------------------------------------------------------------ Verbindungen pflegen

    /** Timer: Ping an alle Dots, stille Verbindungen schließen. */
    public function Keepalive(): void
    {
        $now = (int)EmClock::now();
        foreach ($this->conns() as $key => $c) {
            if ($c['phase'] !== 'ws') {
                if ($now - (int)$c['seen'] > 10) { // Verbindung ohne Handshake
                    $this->dropConnection($key, true);
                }
                continue;
            }
            if ($now - (int)$c['seen'] > self::IDLE_CLOSE_SECONDS) {
                $this->SendDebug('Keepalive', $key . ' timed out', 0);
                $this->sendRaw($c['ip'], $c['port'], EmWebSocket::close(1001));
                $this->dropConnection($key, true);
                continue;
            }
            $this->sendRaw($c['ip'], $c['port'], EmWebSocket::frame(EmWebSocket::OP_PING, ''));
        }
    }

    /**
     * Nimmt ein wartendes Gerät auf und legt dessen Instanz an.
     * @return string '' bei Erfolg, sonst der Grund
     */
    public function ApproveDevice(string $DeviceId): string
    {
        $id = trim($DeviceId);
        if (preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $id) !== 1) {
            return 'invalid device id';
        }
        $approved = $this->approvedList();
        if (!in_array($id, $approved, true)) {
            $approved[] = $id;
            $this->WriteAttributeString('Approved', (string)json_encode($approved));
        }
        $pending = json_decode($this->ReadAttributeString('Pending'), true) ?: [];
        unset($pending[$id]);
        $this->WriteAttributeString('Pending', (string)json_encode($pending));

        foreach (IPS_GetInstanceListByModuleID(self::DEVICE_GUID) as $existing) {
            if (@IPS_GetProperty($existing, 'DeviceId') === $id) {
                return '';
            }
        }
        $new = IPS_CreateInstance(self::DEVICE_GUID);
        IPS_SetParent($new, IPS_GetParent($this->InstanceID));
        IPS_SetName($new, 'EchoMuse ' . $id);
        IPS_SetProperty($new, 'DeviceId', $id);
        IPS_ConnectInstance($new, $this->InstanceID);
        IPS_ApplyChanges($new);
        return '';
    }

    public function GetConfigurationForm(): string
    {
        $pending = array_keys(json_decode($this->ReadAttributeString('Pending'), true) ?: []);
        $options = array_map(static fn(string $id): array => ['caption' => $id, 'value' => $id], $pending);
        $online = array_map(static fn(string $id): string => $id, array_keys($this->devices()));
        return (string)json_encode([
            'elements' => [
                ['type' => 'NumberSpinner', 'name' => 'Port', 'caption' => 'Port', 'minimum' => 1024, 'maximum' => 65535],
                ['type' => 'CheckBox', 'name' => 'AutoApprove', 'caption' => 'Accept every device without asking (home network only)'],
            ],
            'actions' => [
                ['type' => 'Label', 'caption' => $online === [] ? 'No device connected.' : 'Connected: ' . implode(', ', $online)],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Select', 'name' => 'PendingDevice', 'caption' => 'Waiting for approval', 'width' => '300px', 'options' => $options ?: [['caption' => '-', 'value' => '']]],
                    ['type' => 'Button', 'caption' => 'Approve', 'onClick' => 'echo EMGW_ApproveDevice($id, $PendingDevice) ?: "OK";'],
                ]],
            ],
            'status' => [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // ------------------------------------------------------------------ Hilfen

    private function approved(string $id): bool
    {
        return $this->ReadPropertyBoolean('AutoApprove') || in_array($id, $this->approvedList(), true);
    }

    /** @return array<int, string> */
    private function approvedList(): array
    {
        $list = json_decode($this->ReadAttributeString('Approved'), true);
        return is_array($list) ? array_map('strval', $list) : [];
    }

    /** @param array<string, mixed> $payload */
    private function toChildren(string $id, string $event, array $payload): void
    {
        $this->SendDataToChildren((string)json_encode(['DataID' => self::CHILD_RX, 'DeviceId' => $id, 'Event' => $event] + $payload, JSON_UNESCAPED_UNICODE));
    }

    private function touch(string $id): void
    {
        $devices = $this->devices();
        if (isset($devices[$id])) {
            $devices[$id]['seen'] = (int)EmClock::now();
            $this->saveDevices($devices);
        }
    }

    private function sendRaw(string $ip, int $port, string $bytes): void
    {
        @$this->SendDataToParent((string)json_encode([
            'DataID'     => self::SOCKET_TX,
            'Buffer'     => mb_convert_encoding($bytes, 'UTF-8', 'ISO-8859-1'),
            'ClientIP'   => $ip,
            'ClientPort' => $port,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /** @return array<string, mixed>|null die /data-Verbindung eines Geräts: gleiche Adresse wie sein /control */
    private function dataConnection(string $id): ?array
    {
        $dev = $this->devices()[$id] ?? null;
        if ($dev === null) {
            return null;
        }
        foreach ($this->conns() as $c) {
            if ($c['path'] === '/data' && $c['phase'] === 'ws' && $c['ip'] === $dev['ip']) {
                return $c;
            }
        }
        return null;
    }

    private function dropConnection(string $key, bool $closeSocket): void
    {
        $conns = $this->conns();
        $c = $conns[$key] ?? null;
        if ($c === null) {
            return;
        }
        unset($conns[$key]);
        $this->saveConns($conns);
        if ($closeSocket) {
            @$this->SendDataToParent((string)json_encode(['DataID' => self::SOCKET_TX, 'Buffer' => '', 'ClientIP' => $c['ip'], 'ClientPort' => $c['port'], 'Type' => 2]));
        }
        $id = (string)$c['dev'];
        if ($id !== '' && $c['path'] === '/control') {
            $devices = $this->devices();
            if (($devices[$id]['key'] ?? '') === $key) {
                unset($devices[$id]);
                $this->saveDevices($devices);
                $play = $this->plays();
                unset($play[$id]);
                $this->savePlays($play);
                $this->toChildren($id, 'offline', []);
            }
        }
    }
}

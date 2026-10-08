<?php

declare(strict_types=1);

/**
 * Prüfstand für Gateway und Gerät der EchoMuse-Anbindung: ein Attrappen-Dot spricht über einen
 * Attrappen-Server-Socket mit dem echten Modulcode (Symcon 9.1 im Speicher aus dem LG-Prüfstand).
 *   php tests/echomuse_test.php
 */
$sdk = __DIR__ . '/../../LGThinQ/tests/bootstrap.php';
if (!is_file($sdk)) {
    fwrite(STDERR, "Prüfstand-Kernel fehlt: $sdk\n");
    exit(1);
}
require $sdk;
require __DIR__ . '/../libs/EmWebSocket.php';
require __DIR__ . '/../libs/EmProtocol.php';
require __DIR__ . '/../libs/EmPcm.php';
require __DIR__ . '/../libs/EmClock.php';
require __DIR__ . '/../libs/EmRealtime.php';
require __DIR__ . '/../libs/EmSpool.php';
require __DIR__ . '/../libs/EmWsClient.php';
require __DIR__ . '/../libs/SymDoVoiceClient.php';

const SOCKET_GUID = '{8062CF2B-600E-41D6-AD4B-1BA66C32D6ED}';
const SOCKET_RX = '{7A1272A4-CBDB-46EF-BFC6-DCF4A53D2FC7}';
const GW_GUID = '{863162E7-78F5-45C5-8ACC-616ADA42283A}';
const DEV_GUID = '{077221A9-EA1F-4A00-8BA3-679A85233E80}';
const VOICE_GUID = '{01B516D7-72D6-463D-A073-6328154D7F09}';
const CLIENT_GUID = '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}';
const CLIENT_RX = '{018EF6B5-AB94-40C6-AA53-46943E824ACF}';

/** Server Socket als Attrappe: schreibt mit, was das Gateway an die Dots schickt. */
final class FakeServerSocket
{
    /** @var array<int, array{ip: string, port: int, bytes: string, type: int}> */
    public array $sent = [];

    public function ForwardData(string $json): string
    {
        $d = json_decode($json, true);
        $this->sent[] = ['ip' => (string)$d['ClientIP'], 'port' => (int)$d['ClientPort'], 'bytes' => (string)hex2bin((string)$d['Buffer']), 'type' => (int)($d['Type'] ?? 0)];
        return '';
    }
}

if (!function_exists('IPS_SetHidden')) {
    function IPS_SetHidden(int $id, bool $hidden): bool { return true; }
}
if (!function_exists('IPS_SetConfiguration')) {
    function IPS_SetConfiguration(int $id, string $json): bool
    {
        foreach ((array)json_decode($json, true) as $k => $v) {
            IPS_SetProperty($id, (string)$k, $v);
        }
        return true;
    }
}
/** Der Client Socket zur Realtime-Schnittstelle als Attrappe: schreibt mit, was das Voice-Modul sendet. */
function CSCK_SendText(int $id, string $text): bool
{
    $GLOBALS['clientSent'][] = $text;
    return true;
}

/** Der Server Socket als Attrappe: SSCK_SendPacket schreibt wie der echte Socket mit. */
function SSCK_SendPacket(int $id, string $bytes, string $ip, int $port): bool
{
    $GLOBALS['fakeSocket']->sent[] = ['ip' => $ip, 'port' => $port, 'bytes' => $bytes, 'type' => 0];
    return true;
}


/** Ein zweiter Dot meldet sich und weckt, während der erste noch eine Sitzung hat → listen_close busy. */
function EchoMuseTestSecondDot(int $gw, Closure $deliver, Closure $request, Closure $send, Closure $register, Closure $take, Closure $json): void
{
    EMGW_ApproveDevice($gw, 'DOT2DOT2');
    $deliver('192.0.2.70', 43000, 1);
    $deliver('192.0.2.70', 43000, 0, $request('/control'));
    $take('192.0.2.70', 43000);
    $deliver('192.0.2.70', 43000, 0, $send($register('DOT2DOT2')));
    $take('192.0.2.70', 43000);
    $deliver('192.0.2.70', 43000, 0, $send('{"type":"oww_wake","session":3,"barge":false}'));
    Kernel::deliverUpdates();
    $f = $take('192.0.2.70', 43000);
    check(($json($f[0] ?? []))['type'] === 'listen_close' && $json($f[0])['reason'] === 'busy' && $json($f[0])['session'] === 3, 'zweiter Dot weckt bei laufender Sitzung: listen_close busy');
}

Kernel::reset();
EmClock::$source = static fn(): float => (float)Kernel::now();
Kernel::registerModule(['ModuleID' => SOCKET_GUID, 'ModuleName' => 'Server Socket', 'ModuleType' => 1, 'Prefix' => 'SSCK',
    'Implemented' => ['{C8792760-65CF-4C53-B5C7-A30FCC84FEFE}', '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}'],
    'ChildRequirements' => [SOCKET_RX, '{018EF6B5-AB94-40C6-AA53-46943E824ACF}']]);
Kernel::loadLibrary(dirname(__DIR__));
$sock = Kernel::createInstance(SOCKET_GUID);
$fake = new FakeServerSocket();
$GLOBALS['fakeSocket'] = $fake;
Kernel::$instances[$sock]['handler'] = $fake;

/** Bytes vom Dot an den Server Socket → Gateway (wie das Kernel-Modul es zustellt). */
$deliver = static function (string $ip, int $port, int $type, string $bytes = '') use ($sock): void {
    Kernel::sendToChildren($sock, (string)json_encode(['DataID' => SOCKET_RX, 'Type' => $type, 'ClientIP' => $ip, 'ClientPort' => $port,
        'Buffer' => bin2hex($bytes)]));
};
$request = static fn(string $path): string => "GET $path HTTP/1.1\r\nHost: gw\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\nX-EM-Token: t\r\n\r\n";
$send = static fn(string $json): string => EmWebSocket::clientFrame(EmWebSocket::OP_TEXT, $json);
$register = static fn(string $id): string => (string)json_encode(['type' => 'register', 'device_id' => $id, 'version' => 'v2.30.1',
    'capabilities' => ['mic', 'speaker', 'leds', 'buttons'], 'ip' => '192.0.2.50', 'base_os' => 'emos', 'board' => 'biscuit']);
/** Was das Gateway an einen Client geschickt hat, als Liste: ['http' => Kopf] oder ['op' => .., 'data' => ..]. */
$take = static function (string $ip, int $port) use ($fake): array {
    $out = [];
    $rest = [];
    foreach ($fake->sent as $s) {
        if ($s['ip'] === $ip && $s['port'] === $port) {
            $out[] = $s;
        } else {
            $rest[] = $s;
        }
    }
    $fake->sent = $rest;
    $frames = [];
    foreach ($out as $s) {
        $b = $s['bytes'];
        if ($s['type'] === 2) {
            $frames[] = ['close' => true];
            continue;
        }
        if (str_starts_with($b, 'HTTP/')) {
            $end = strpos($b, "\r\n\r\n") + 4;
            $frames[] = ['http' => substr($b, 0, $end)];
            $b = substr($b, $end);
        }
        while (strlen($b) >= 2) {
            $op = ord($b[0]) & 0x0F;
            $len = ord($b[1]) & 0x7F;
            $off = 2;
            if ($len === 126) {
                $len = (int)unpack('n', substr($b, 2, 2))[1];
                $off = 4;
            }
            $frames[] = ['op' => $op, 'data' => substr($b, $off, $len)];
            $b = substr($b, $off + $len);
        }
    }
    return $frames;
};
$json = static fn(array $frame): array => (array)json_decode((string)($frame['data'] ?? ''), true);

section('Gateway anlegen');
$gw = Kernel::createInstance(GW_GUID);
check(Kernel::$instances[$gw]['connection'] === $sock, 'verbindet sich mit dem Server Socket (Datenfluss kompatibel)');
check(IPS_GetInstance($gw)['InstanceStatus'] === IS_ACTIVE, 'aktiv');
check(json_decode(Kernel::$instances[$gw]['object']->GetConfigurationForParent(), true) === ['Port' => 8767, 'Open' => true], 'gibt dem Server Socket Port 8767 und Öffnen vor');
check(is_array(json_decode(Kernel::$instances[$gw]['object']->GetConfigurationForm(), true)), 'Formular ist gültiges JSON');

section('Handshake und unbekanntes Gerät');
$deliver('192.0.2.50', 40001, 1);
$deliver('192.0.2.50', 40001, 0, $request('/control'));
$f = $take('192.0.2.50', 40001);
check(count($f) === 1 && str_contains($f[0]['http'] ?? '', '101 Switching Protocols') && str_contains($f[0]['http'], 's3pPLMBiTxaQ9kYGzzhZRbK+xOo='), 'Upgrade beantwortet, Accept-Schlüssel stimmt');
$deliver('192.0.2.50', 40001, 0, $send($register('G090LF0123456789')));
$f = $take('192.0.2.50', 40001);
check(($json($f[0] ?? [])['type'] ?? '') === 'pending' && ($f[1]['op'] ?? 0) === EmWebSocket::OP_CLOSE, 'unbekanntes Gerät: pending, dann Close-Rahmen');
$status = json_decode(Kernel::$instances[$gw]['attributes']['Pending'], true);
check(isset($status['G090LF0123456789']) && $status['G090LF0123456789']['ip'] === '192.0.2.50', 'steht in der Liste der Wartenden');
$deliver('192.0.2.50', 40001, 2);

section('Freigabe legt das Gerät an');
check(EMGW_ApproveDevice($gw, 'G090LF0123456789') === '', 'ApproveDevice');
$devs = IPS_GetInstanceListByModuleID(DEV_GUID);
check(count($devs) === 1 && IPS_GetProperty($devs[0], 'DeviceId') === 'G090LF0123456789' && Kernel::$instances[$devs[0]]['connection'] === $gw, 'Geräte-Instanz mit Kennung angelegt und mit dem Gateway verbunden');
check(IPS_GetInstance($devs[0])['InstanceStatus'] === IS_ACTIVE, 'Gerät aktiv');
check(EMGW_ApproveDevice($gw, 'G090LF0123456789') === '' && count(IPS_GetInstanceListByModuleID(DEV_GUID)) === 1, 'zweite Freigabe legt keine zweite Instanz an');
check(EMGW_ApproveDevice($gw, '../x') === 'invalid device id', 'ungültige Kennung abgewiesen');
$dev = $devs[0];
check(World::value($dev, 'ONLINE') === false, 'noch offline');

section('Anmeldung, Zustand, Tasten');
$deliver('192.0.2.50', 40002, 1);
$deliver('192.0.2.50', 40002, 0, $request('/control'));
$take('192.0.2.50', 40002);
$deliver('192.0.2.50', 40002, 0, $send($register('G090LF0123456789')));
$f = $take('192.0.2.50', 40002);
$ack = $json($f[0] ?? []);
check(($ack['type'] ?? '') === 'ack' && $ack['device_id'] === 'G090LF0123456789' && $ack['features'] === ['output_chain'] && $ack['time_ms'] > 1700000000000, 'ack mit Fähigkeiten und Uhrzeit');
check(World::value($dev, 'ONLINE') === true && World::value($dev, 'FIRMWARE') === 'v2.30.1', 'Gerät online, Firmware übernommen');
$deliver('192.0.2.50', 40002, 0, $send('{"type":"volume_state","level":64}'));
check(World::value($dev, 'VOLUME') === 50, 'Lautstärke 64/127 = 50 %');
$deliver('192.0.2.50', 40002, 0, $send('{"type":"mute_state","muted":true}'));
check(World::value($dev, 'MUTED') === true, 'Stumm');
$deliver('192.0.2.50', 40002, 0, $send('{"type":"button","clickType":"single","down":false,"heldMs":0}'));
check(str_starts_with((string)World::value($dev, 'BUTTON'), 'single'), 'Taste beim Loslassen');
$deliver('192.0.2.50', 40002, 0, $send('{"type":"stats","tcpUpRetrans":1}') . $send('{"type":"unbekannt"}'));
check(World::value($dev, 'ONLINE') === true && Kernel::$warnings === [], 'stats und unbekannte Nachrichten werden ignoriert');
$deliver('192.0.2.50', 40002, 0, EmWebSocket::clientFrame(EmWebSocket::OP_PING, 'x'));
$f = $take('192.0.2.50', 40002);
check(($f[0]['op'] ?? 0) === EmWebSocket::OP_PONG && ($f[0]['data'] ?? '') === 'x', 'Ping des Geräts wird mit Pong beantwortet');

section('Steuern');
RequestAction(World::varId($dev, 'VOLUME'), 100);
$f = $take('192.0.2.50', 40002);
check($json($f[0] ?? []) === ['type' => 'volume_set', 'level' => 127] && World::value($dev, 'VOLUME') === 100, 'Lautstärke 100 % = Level 127, nie darüber');
check(EMGD_PlayCue($dev, 'wake') === '' && $json($take('192.0.2.50', 40002)[0] ?? [])['cue'] === 'wake', 'Signalton');
check(EMGD_SendConfig($dev, '{"startupVolume":60}') === '' && ($json($take('192.0.2.50', 40002)[0] ?? [])['startupVolume'] ?? 0) === 60, 'Konfiguration als Teilupdate');
check(EMGD_SendConfig($dev, 'kein json') === 'invalid JSON', 'ungültige Konfiguration abgewiesen');

section('Ansage: Ton über /data in Perioden, gebremst');
check(EMGD_Beep($dev, 1) === 'device has no audio connection', 'ohne /data-Verbindung keine Wiedergabe');
$deliver('192.0.2.50', 40003, 1);
$deliver('192.0.2.50', 40003, 0, $request('/data'));
$take('192.0.2.50', 40003);
check(EMGD_Beep($dev, 1) === '', '1 s Testton angenommen');
$f = $take('192.0.2.50', 40003);
$frames = array_filter($f, static fn(array $x): bool => ($x['op'] ?? 0) === EmWebSocket::OP_BINARY);
$speaker = count(array_filter($frames, static fn(array $x): bool => $x['data'][0] === "\x02"));
$eos = count(array_filter($frames, static fn(array $x): bool => $x['data'] === "\x03"));
check($speaker === 24 && $eos === 1, '1 s = 24 Perioden zu 4096 Byte (23,4 aufgerundet) und ein Ende-Rahmen (' . $speaker . '/' . $eos . ')');
check(count(array_filter($frames, static fn(array $x): bool => $x['data'][0] === "\x02" && strlen($x['data']) === 4097)) === 24, 'jede Periode hat Typbyte plus 4096 Byte');
check(Kernel::$instances[$gw]['timers']['Pump']['interval'] === 250 || Kernel::$instances[$gw]['timers']['Pump']['interval'] === 0, 'Pump-Timer gestellt oder schon fertig');
Kernel::advance(1);
check(Kernel::$instances[$gw]['timers']['Pump']['interval'] === 0, 'Pump-Timer steht, wenn alles gesendet ist');

check(EMGD_Beep($dev, 5) === '', '5 s Testton angenommen');
$f = $take('192.0.2.50', 40003);
$first = count(array_filter($f, static fn(array $x): bool => ($x['data'][0] ?? '') === "\x02"));
check($first === 71 && count(array_filter($f, static fn(array $x): bool => ($x['data'] ?? '') === "\x03")) === 0, 'nur 3 s vorausgeschickt (71 Perioden), noch kein Ende: ' . $first);
Kernel::advance(1);
$f = $take('192.0.2.50', 40003);
$second = count(array_filter($f, static fn(array $x): bool => ($x['data'][0] ?? '') === "\x02"));
check($second >= 22 && $second <= 24 && count(array_filter($f, static fn(array $x): bool => ($x['data'] ?? '') === "\x03")) === 0, 'nach 1 s füllt der Timer um 1 s nach (' . $second . ' Perioden), noch kein Ende');
Kernel::advance(4);
$f = $take('192.0.2.50', 40003);
$rest = count(array_filter($f, static fn(array $x): bool => ($x['data'][0] ?? '') === "\x02"));
check($first + $second + $rest === 118 && count(array_filter($f, static fn(array $x): bool => ($x['data'] ?? '') === "\x03")) === 1, 'insgesamt 118 Perioden (5 s, 117,2 aufgerundet), danach das Ende');

section('WAV-Datei aus dem Medienordner');
$dir = IPS_GetKernelDir() . 'media/';
@mkdir($dir, 0755, true);
$wav = EmPcm::wrapWav(EmPcm::pack(array_fill(0, 2400, 800)), 24000);
file_put_contents($dir . 'em_test.wav', $wav);
check(EMGD_SpeakFile($dev, $dir . 'em_test.wav') === '', 'SpeakFile angenommen');
$f = $take('192.0.2.50', 40003);
check(count(array_filter($f, static fn(array $x): bool => ($x['data'][0] ?? '') === "\x02")) === 3, '0,1 s bei 24 kHz = 3 Perioden bei 48 kHz');
check(EMGD_SpeakFile($dev, '/etc/passwd') === 'audio file not found' && EMGD_SpeakFile($dev, $dir . '../x') === 'audio file not found', 'Dateien außerhalb des Medienordners werden abgewiesen');
file_put_contents($dir . 'em_bad.wav', 'kein wav');
check(EMGD_SpeakFile($dev, $dir . 'em_bad.wav') === 'not a WAV file', 'kaputte Datei wird gemeldet');
@unlink($dir . 'em_test.wav');
@unlink($dir . 'em_bad.wav');

section('Verbindungen enden, Fehler im Protokoll');
$deliver('192.0.2.50', 40002, 2);
check(World::value($dev, 'ONLINE') === false, 'Verbindung weg: Gerät offline');
check(EMGD_Beep($dev, 1) === 'device has no audio connection', 'und keine Wiedergabe mehr');
$deliver('192.0.2.51', 41000, 1);
$deliver('192.0.2.51', 41000, 0, "POST / HTTP/1.1\r\n\r\n");
$f = $take('192.0.2.51', 41000);
check(str_contains($f[0]['http'] ?? '', '400'), 'Fremde HTTP-Anfrage: 400');
$deliver('192.0.2.52', 41001, 1);
$deliver('192.0.2.52', 41001, 0, $request('/shell/abc'));
$f = $take('192.0.2.52', 41001);
check(str_contains($f[0]['http'] ?? '', '400'), 'unbekannter Pfad (/shell) wird abgewiesen');
$deliver('192.0.2.53', 41002, 1);
$deliver('192.0.2.53', 41002, 0, $request('/control'));
$take('192.0.2.53', 41002);
$deliver('192.0.2.53', 41002, 0, EmWebSocket::text('x'));
$f = $take('192.0.2.53', 41002);
check(!empty(array_filter($f, static fn(array $x): bool => ($x['op'] ?? 0) === EmWebSocket::OP_CLOSE)), 'unmaskierter Rahmen: Close-Rahmen');
$deliver('192.0.2.54', 41003, 1);
$deliver('192.0.2.54', 41003, 0, $request('/control'));
$take('192.0.2.54', 41003);
Kernel::advance(90);
$f = $take('192.0.2.54', 41003);
check(count(array_filter($f, static fn(array $x): bool => ($x['op'] ?? 0) === EmWebSocket::OP_PING)) >= 2 && !empty(array_filter($f, static fn(array $x): bool => ($x['op'] ?? 0) === EmWebSocket::OP_CLOSE)),
    'stille Verbindung wird alle 20 s gepingt und nach über 60 s geschlossen');

section('Sprachrunde: Wakeword, Mikrofon, Werkzeug, Antwort');
Kernel::registerModule(['ModuleID' => CLIENT_GUID, 'ModuleName' => 'Client Socket', 'ModuleType' => 1, 'Prefix' => 'CSCK',
    'Implemented' => ['{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}'], 'ChildRequirements' => [CLIENT_RX],
    'defaults' => ['Host' => '', 'Port' => 0, 'UseSSL' => false, 'VerifyHost' => true, 'VerifyPeer' => true, 'Open' => false]]);
$io = Kernel::createInstance(CLIENT_GUID);
$GLOBALS['clientSent'] = [];
$GLOBALS['symdo'] = [];
SymDoVoiceClient::$transport = static function (string $url, array $headers, string $body): array {
    $b = json_decode($body, true);
    $GLOBALS['symdo'][] = ['url' => $url, 'auth' => $headers[0], 'body' => $b];
    $r = match ($b['action']) {
        'open' => ['ok' => true, 'value' => 'ek_test_secret', 'model' => 'gpt-realtime-mini', 'sessionSeconds' => 60],
        'tool' => ['ok' => true, 'ergebnis' => 'Milch steht auf der Liste.'],
        default => ['ok' => true],
    };
    return ['status' => 200, 'body' => json_encode($r), 'err' => ''];
};
$voice = Kernel::createInstance(VOICE_GUID);
check(Kernel::$instances[$voice]['connection'] === $io, 'Voice verbindet sich mit dem Client Socket (Datenfluss kompatibel)');
IPS_SetProperty($voice, 'GatewayInstance', $gw);
IPS_SetProperty($voice, 'SymDoToken', 'sdtok');
IPS_SetProperty($voice, 'UserId', 'u-stephan');
IPS_SetProperty($voice, 'RealtimeSSL', false);
IPS_SetProperty($voice, 'RealtimeHost', 'fake.local');
IPS_SetProperty($voice, 'RealtimePort', 9443);
IPS_ApplyChanges($voice);
IPS_SetProperty($gw, 'VoiceInstance', $voice);
IPS_ApplyChanges($gw);
check(IPS_GetInstance($voice)['InstanceStatus'] === IS_ACTIVE, 'Voice aktiv (Gateway, Adresse und Token gesetzt)');

// Der Dot meldet sich neu an: jetzt mit Sprachrunde
$deliver('192.0.2.50', 40010, 1);
$deliver('192.0.2.50', 40010, 0, $request('/control'));
$take('192.0.2.50', 40010);
$deliver('192.0.2.50', 40010, 0, $send($register('G090LF0123456789')));
$f = $take('192.0.2.50', 40010);
$ack = $json($f[0] ?? []);
$cfg = $json($f[1] ?? []);
check(in_array('listen_session', $ack['features'] ?? [], true) && in_array('output_chain', $ack['features'], true), 'ack nennt listen_session, sobald Voice verbunden ist');
check(($cfg['type'] ?? '') === 'config' && ($cfg['owwOnDevice'] ?? '') === 'on' && ($cfg['wakeSound'] ?? null) === true, 'Konfiguration: Wakeword auf dem Dot, Signalton an');
$deliver('192.0.2.50', 40011, 1);
$deliver('192.0.2.50', 40011, 0, $request('/data'));
$take('192.0.2.50', 40011);
Kernel::deliverUpdates();

// Wakeword
$deliver('192.0.2.50', 40010, 0, $send('{"type":"oww_wake","score":0.93,"threshold":0.5,"ageMs":120,"session":7,"floor":10,"barge":false}'));
Kernel::deliverUpdates();
$f = $take('192.0.2.50', 40010);
check(($json($f[0] ?? []))['type'] === 'listen_ack' && $json($f[0])['session'] === 7, 'oww_wake → listen_ack mit der Sitzungsnummer');
$open = array_values(array_filter($GLOBALS['symdo'], static fn(array $c): bool => $c['body']['action'] === 'open'));
check(count($open) === 1 && $open[0]['url'] === 'http://127.0.0.1:3777/hook/lists/app/v1/voice' && $open[0]['auth'] === 'Authorization: Bearer sdtok' && $open[0]['body']['userId'] === 'u-stephan', 'Voice öffnet die SymDo-Sitzung (Adresse, Bearer, Nutzer)');
check(count(array_filter($GLOBALS['symdo'], static fn(array $c): bool => $c['body']['action'] === 'opened')) === 1, 'und meldet sie als geöffnet');
$up = $GLOBALS['clientSent'][0] ?? '';
check(str_starts_with($up, 'GET /v1/realtime?model=gpt-realtime-mini HTTP/1.1') && str_contains($up, 'Authorization: Bearer ek_test_secret') && str_contains($up, 'Host: fake.local'), 'WebSocket-Anfrage an die Realtime-Schnittstelle mit dem Zugangsschlüssel');
check(World::value($voice, 'STATE') === 'verbinde' || World::value($voice, 'STATE') === 'connecting', 'Zustand: verbindet');

// Mikrofon, solange noch verbunden wird: wird zwischengespeichert
$mic = static fn(int $session, int $seq, string $pcm): string => EmWebSocket::clientFrame(EmWebSocket::OP_BINARY, "\x07" . pack('Nn', $session, $seq) . $pcm);
$pcm16 = EmPcm::pack(array_fill(0, 1600, 500)); // 100 ms bei 16 kHz
$deliver('192.0.2.50', 40011, 0, $mic(7, 0, $pcm16));
Kernel::deliverUpdates();
check(empty(array_filter($GLOBALS['clientSent'], static fn(string $t): bool => str_contains($t, "\x81") && strlen($t) > 100 && !str_starts_with($t, 'GET '))), 'vor der Verbindung wird nichts gesendet');
check(is_file(EmSpool::path('mic_G090LF0123456789')) && filesize(EmSpool::path('mic_G090LF0123456789')) === 3200, 'Mikrofon liegt in der Spool-Datei (3200 Byte)');
$deliver('192.0.2.50', 40011, 0, $mic(6, 0, $pcm16)); // alte Sitzung
Kernel::deliverUpdates();
check(filesize(EmSpool::path('mic_G090LF0123456789')) === 3200, 'Rahmen einer anderen Sitzung werden verworfen');

// Der Server bestätigt das Upgrade
preg_match('/Sec-WebSocket-Key: (\S+)/', $up, $km);
$server = static function (string $json) use ($voice): void {
    $b = EmWebSocket::text($json);
    Kernel::sendToChildren(Kernel::$instances[$voice]['connection'], (string)json_encode(['DataID' => CLIENT_RX, 'Buffer' => bin2hex($b)]));
    Kernel::deliverUpdates();
};
$serverRaw = static function (string $bytes) use ($io): void {
    Kernel::sendToChildren($io, (string)json_encode(['DataID' => CLIENT_RX, 'Buffer' => bin2hex($bytes)]));
    Kernel::deliverUpdates();
};
$GLOBALS['clientSent'] = [];
$serverRaw(EmWebSocket::handshakeResponse($km[1]));
$sentFrames = static function (): array {
    $out = [];
    foreach ($GLOBALS['clientSent'] as $t) {
        foreach (EmWebSocket::decode($t)['messages'] as $m) {
            $out[] = json_decode($m['data'], true);
        }
    }
    return $out;
};
$frames = $sentFrames();
$appends = array_values(array_filter($frames, static fn($m): bool => ($m['type'] ?? '') === 'input_audio_buffer.append'));
check(count($appends) >= 1 && strlen(base64_decode($appends[0]['audio'])) === 4800, 'nach dem Upgrade geht das gespeicherte Mikrofon als 24-kHz-Audio hinaus (100 ms = 4800 Byte)');
check(World::value($voice, 'STATE') === 'hört zu' || World::value($voice, 'STATE') === 'listening', 'Zustand: hört zu');
$GLOBALS['clientSent'] = [];
$deliver('192.0.2.50', 40011, 0, $mic(7, 1, $pcm16));
Kernel::deliverUpdates();
$appends = array_values(array_filter($sentFrames(), static fn($m): bool => ($m['type'] ?? '') === 'input_audio_buffer.append'));
check(count($appends) === 1 && strlen(base64_decode($appends[0]['audio'])) === 4800, 'weiteres Mikrofon geht sofort weiter');

// Ende der Sprache → Dot hört auf
$take('192.0.2.50', 40010);
$server('{"type":"input_audio_buffer.speech_stopped"}');
$f = $take('192.0.2.50', 40010);
check(($json($f[0] ?? []))['type'] === 'listen_close' && $json($f[0])['session'] === 7 && $json($f[0])['reason'] === 'end_of_speech', 'speech_stopped → listen_close, der Dot hört danach nur noch lokal');
$server('{"type":"conversation.item.input_audio_transcription.completed","transcript":"Milch auf die Einkaufsliste."}');
check(World::value($voice, 'LAST_TEXT') === 'Milch auf die Einkaufsliste.', 'Mitschrift erscheint im Voice-Modul');

// Werkzeugaufruf
$GLOBALS['clientSent'] = [];
$server('{"type":"response.function_call_arguments.done","name":"einkauf_hinzu","arguments":"{\"artikel\":\"Milch\"}","call_id":"call_42"}');
$tool = array_values(array_filter($GLOBALS['symdo'], static fn(array $c): bool => $c['body']['action'] === 'tool'));
check(count($tool) === 1 && $tool[0]['body']['name'] === 'einkauf_hinzu' && $tool[0]['body']['arguments'] === '{"artikel":"Milch"}' && $tool[0]['body']['fnId'] === 'call_42' && str_starts_with($tool[0]['body']['callId'], 'echomuse-'), 'Werkzeug läuft über SymDo (Name, Argumente, fnId, Sitzung)');
$fr = $sentFrames();
check(($fr[0]['type'] ?? '') === 'conversation.item.create' && $fr[0]['item']['call_id'] === 'call_42' && str_contains($fr[0]['item']['output'], 'Milch steht auf der Liste') && ($fr[1]['type'] ?? '') === 'response.create', 'Ergebnis geht an das Modell, danach response.create');
$server('{"type":"response.done","response":{"status":"completed","output":[{"type":"function_call","name":"einkauf_hinzu","arguments":"{}","call_id":"call_42"}]}}');
check(World::value($voice, 'STATE') !== 'bereit' && count(array_filter($GLOBALS['symdo'], static fn(array $c): bool => $c['body']['action'] === 'tool')) === 1 && count(array_filter($GLOBALS['symdo'], static fn(array $c): bool => $c['body']['action'] === 'close')) === 0, 'response.done mit Werkzeug beendet die Sitzung nicht und führt nichts doppelt aus');

// Antwort
$take('192.0.2.50', 40011);
$delta = base64_encode(EmPcm::pack(array_fill(0, 2400, 1000))); // 100 ms bei 24 kHz
$server(json_encode(['type' => 'response.output_audio.delta', 'delta' => $delta]));
$f = $take('192.0.2.50', 40011);
$sp = array_filter($f, static fn(array $x): bool => ($x['data'][0] ?? '') === "\x02");
check(count($sp) === 3 && count(array_filter($f, static fn(array $x): bool => ($x['data'] ?? '') === "\x03")) === 0, '100 ms Antwort = 3 Perioden, noch kein Ende (die Antwort läuft)');
$server(json_encode(['type' => 'response.audio.delta', 'delta' => $delta]));
$f = $take('192.0.2.50', 40011);
check(count(array_filter($f, static fn(array $x): bool => ($x['data'][0] ?? '') === "\x02")) >= 2, 'weitere Stücke (Beta-Ereignisname) werden angehängt');
Kernel::advance(2);
check(Kernel::$instances[$gw]['timers']['Pump']['interval'] === 250, 'der Pump-Timer bleibt, solange der Strom offen ist');
$GLOBALS['clientSent'] = [];
$server('{"type":"response.done","response":{"status":"completed","output":[{"type":"message"}]}}');
Kernel::advance(2);
$f = $take('192.0.2.50', 40011);
check(count(array_filter($f, static fn(array $x): bool => ($x['data'] ?? '') === "\x03")) === 1, 'Ende der Antwort → Ende-Rahmen 0x03 an den Dot');
check(count(array_filter($GLOBALS['symdo'], static fn(array $c): bool => $c['body']['action'] === 'close')) === 1, 'SymDo-Sitzung geschlossen (zählt die Sprechzeit)');
$closeFrame = array_filter($GLOBALS['clientSent'], static fn(string $t): bool => (EmWebSocket::decode($t)['messages'][0]['op'] ?? 0) === EmWebSocket::OP_CLOSE);
check($closeFrame !== [] && World::value($voice, 'STATE') !== 'verbinde' && !is_file(EmSpool::path('mic_G090LF0123456789')) && !is_file(EmSpool::path('out_G090LF0123456789')), 'Verbindung geschlossen, Spool-Dateien weg');
check(Kernel::$instances[$gw]['buffers']['Voice'] === '[]' || json_decode(Kernel::$instances[$gw]['buffers']['Voice'], true) === [], 'Gateway hat die Sitzung vergessen');

// Zweiter Dot, solange der erste spricht: abgewiesen
$GLOBALS['symdo'] = [];
$deliver('192.0.2.50', 40010, 0, $send('{"type":"oww_wake","session":8,"barge":false}'));
Kernel::deliverUpdates();
$take('192.0.2.50', 40010);
$GLOBALS['clientSent'] = [];
EchoMuseTestSecondDot($gw, $deliver, $request, $send, $register, $take, $json);

section('AutoApprove');
$gw2cfg = IPS_SetProperty($gw, 'AutoApprove', true);
IPS_ApplyChanges($gw);
$deliver('192.0.2.60', 42000, 1);
$deliver('192.0.2.60', 42000, 0, $request('/control'));
$take('192.0.2.60', 42000);
$deliver('192.0.2.60', 42000, 0, $send($register('NEUGERAET1')));
$f = $take('192.0.2.60', 42000);
check(($json($f[0] ?? [])['type'] ?? '') === 'ack', 'mit AutoApprove wird ein neues Gerät sofort angenommen');

check(Kernel::$warnings === [], 'keine PHP-Warnungen' . (Kernel::$warnings === [] ? '' : ': ' . implode(' | ', Kernel::$warnings)));
check(World::logLines('/ERROR/') === [], 'keine Fehler im Log' . (World::logLines('/ERROR/') === [] ? '' : ': ' . implode(' | ', World::logLines('/ERROR/'))));
done();

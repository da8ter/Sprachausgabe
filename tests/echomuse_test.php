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

const SOCKET_GUID = '{8062CF2B-600E-41D6-AD4B-1BA66C32D6ED}';
const SOCKET_RX = '{7A1272A4-CBDB-46EF-BFC6-DCF4A53D2FC7}';
const GW_GUID = '{863162E7-78F5-45C5-8ACC-616ADA42283A}';
const DEV_GUID = '{077221A9-EA1F-4A00-8BA3-679A85233E80}';

/** Server Socket als Attrappe: schreibt mit, was das Gateway an die Dots schickt. */
final class FakeServerSocket
{
    /** @var array<int, array{ip: string, port: int, bytes: string, type: int}> */
    public array $sent = [];

    public function ForwardData(string $json): string
    {
        $d = json_decode($json, true);
        $this->sent[] = ['ip' => (string)$d['ClientIP'], 'port' => (int)$d['ClientPort'], 'bytes' => mb_convert_encoding((string)$d['Buffer'], 'ISO-8859-1', 'UTF-8'), 'type' => (int)($d['Type'] ?? 0)];
        return '';
    }
}

Kernel::reset();
EmClock::$source = static fn(): float => (float)Kernel::now();
Kernel::registerModule(['ModuleID' => SOCKET_GUID, 'ModuleName' => 'Server Socket', 'ModuleType' => 1, 'Prefix' => 'SSCK',
    'Implemented' => ['{C8792760-65CF-4C53-B5C7-A30FCC84FEFE}', '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}'],
    'ChildRequirements' => [SOCKET_RX, '{018EF6B5-AB94-40C6-AA53-46943E824ACF}']]);
Kernel::loadLibrary(dirname(__DIR__));
$sock = Kernel::createInstance(SOCKET_GUID);
$fake = new FakeServerSocket();
Kernel::$instances[$sock]['handler'] = $fake;

/** Bytes vom Dot an den Server Socket → Gateway (wie das Kernel-Modul es zustellt). */
$deliver = static function (string $ip, int $port, int $type, string $bytes = '') use ($sock): void {
    Kernel::sendToChildren($sock, (string)json_encode(['DataID' => SOCKET_RX, 'Type' => $type, 'ClientIP' => $ip, 'ClientPort' => $port,
        'Buffer' => mb_convert_encoding($bytes, 'UTF-8', 'ISO-8859-1')]));
};
$request = static fn(string $path): string => "GET $path HTTP/1.1\r\nHost: gw\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\nX-EM-Token: t\r\n\r\n";
$send = static fn(string $json): string => EmWebSocket::clientFrame(EmWebSocket::OP_TEXT, $json);
$register = static fn(string $id): string => (string)json_encode(['type' => 'register', 'device_id' => $id, 'version' => 'v2.30.1',
    'capabilities' => ['mic', 'speaker', 'leds', 'buttons'], 'ip' => '192.168.0.50', 'base_os' => 'emos', 'board' => 'biscuit']);
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
$deliver('192.168.0.50', 40001, 1);
$deliver('192.168.0.50', 40001, 0, $request('/control'));
$f = $take('192.168.0.50', 40001);
check(count($f) === 1 && str_contains($f[0]['http'] ?? '', '101 Switching Protocols') && str_contains($f[0]['http'], 's3pPLMBiTxaQ9kYGzzhZRbK+xOo='), 'Upgrade beantwortet, Accept-Schlüssel stimmt');
$deliver('192.168.0.50', 40001, 0, $send($register('G090LF0123456789')));
$f = $take('192.168.0.50', 40001);
check(($json($f[0] ?? [])['type'] ?? '') === 'pending' && ($f[1]['op'] ?? 0) === EmWebSocket::OP_CLOSE && !empty($f[2]['close']), 'unbekanntes Gerät: pending, dann Schließen');
$status = json_decode(Kernel::$instances[$gw]['attributes']['Pending'], true);
check(isset($status['G090LF0123456789']) && $status['G090LF0123456789']['ip'] === '192.168.0.50', 'steht in der Liste der Wartenden');
$deliver('192.168.0.50', 40001, 2);

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
$deliver('192.168.0.50', 40002, 1);
$deliver('192.168.0.50', 40002, 0, $request('/control'));
$take('192.168.0.50', 40002);
$deliver('192.168.0.50', 40002, 0, $send($register('G090LF0123456789')));
$f = $take('192.168.0.50', 40002);
$ack = $json($f[0] ?? []);
check(($ack['type'] ?? '') === 'ack' && $ack['device_id'] === 'G090LF0123456789' && $ack['features'] === ['output_chain'] && $ack['time_ms'] > 1700000000000, 'ack mit Fähigkeiten und Uhrzeit');
check(World::value($dev, 'ONLINE') === true && World::value($dev, 'FIRMWARE') === 'v2.30.1', 'Gerät online, Firmware übernommen');
$deliver('192.168.0.50', 40002, 0, $send('{"type":"volume_state","level":64}'));
check(World::value($dev, 'VOLUME') === 50, 'Lautstärke 64/127 = 50 %');
$deliver('192.168.0.50', 40002, 0, $send('{"type":"mute_state","muted":true}'));
check(World::value($dev, 'MUTED') === true, 'Stumm');
$deliver('192.168.0.50', 40002, 0, $send('{"type":"button","clickType":"single","down":false,"heldMs":0}'));
check(str_starts_with((string)World::value($dev, 'BUTTON'), 'single'), 'Taste beim Loslassen');
$deliver('192.168.0.50', 40002, 0, $send('{"type":"stats","tcpUpRetrans":1}') . $send('{"type":"unbekannt"}'));
check(World::value($dev, 'ONLINE') === true && Kernel::$warnings === [], 'stats und unbekannte Nachrichten werden ignoriert');
$deliver('192.168.0.50', 40002, 0, EmWebSocket::clientFrame(EmWebSocket::OP_PING, 'x'));
$f = $take('192.168.0.50', 40002);
check(($f[0]['op'] ?? 0) === EmWebSocket::OP_PONG && ($f[0]['data'] ?? '') === 'x', 'Ping des Geräts wird mit Pong beantwortet');

section('Steuern');
RequestAction(World::varId($dev, 'VOLUME'), 100);
$f = $take('192.168.0.50', 40002);
check($json($f[0] ?? []) === ['type' => 'volume_set', 'level' => 127] && World::value($dev, 'VOLUME') === 100, 'Lautstärke 100 % = Level 127, nie darüber');
check(EMGD_PlayCue($dev, 'wake') === '' && $json($take('192.168.0.50', 40002)[0] ?? [])['cue'] === 'wake', 'Signalton');
check(EMGD_SendConfig($dev, '{"startupVolume":60}') === '' && ($json($take('192.168.0.50', 40002)[0] ?? [])['startupVolume'] ?? 0) === 60, 'Konfiguration als Teilupdate');
check(EMGD_SendConfig($dev, 'kein json') === 'invalid JSON', 'ungültige Konfiguration abgewiesen');

section('Ansage: Ton über /data in Perioden, gebremst');
check(EMGD_Beep($dev, 1) === 'device has no audio connection', 'ohne /data-Verbindung keine Wiedergabe');
$deliver('192.168.0.50', 40003, 1);
$deliver('192.168.0.50', 40003, 0, $request('/data'));
$take('192.168.0.50', 40003);
check(EMGD_Beep($dev, 1) === '', '1 s Testton angenommen');
$f = $take('192.168.0.50', 40003);
$frames = array_filter($f, static fn(array $x): bool => ($x['op'] ?? 0) === EmWebSocket::OP_BINARY);
$speaker = count(array_filter($frames, static fn(array $x): bool => $x['data'][0] === "\x02"));
$eos = count(array_filter($frames, static fn(array $x): bool => $x['data'] === "\x03"));
check($speaker === 24 && $eos === 1, '1 s = 24 Perioden zu 4096 Byte (23,4 aufgerundet) und ein Ende-Rahmen (' . $speaker . '/' . $eos . ')');
check(count(array_filter($frames, static fn(array $x): bool => $x['data'][0] === "\x02" && strlen($x['data']) === 4097)) === 24, 'jede Periode hat Typbyte plus 4096 Byte');
check(Kernel::$instances[$gw]['timers']['Pump']['interval'] === 250 || Kernel::$instances[$gw]['timers']['Pump']['interval'] === 0, 'Pump-Timer gestellt oder schon fertig');
Kernel::advance(1);
check(Kernel::$instances[$gw]['timers']['Pump']['interval'] === 0, 'Pump-Timer steht, wenn alles gesendet ist');

check(EMGD_Beep($dev, 5) === '', '5 s Testton angenommen');
$f = $take('192.168.0.50', 40003);
$first = count(array_filter($f, static fn(array $x): bool => ($x['data'][0] ?? '') === "\x02"));
check($first === 71 && count(array_filter($f, static fn(array $x): bool => ($x['data'] ?? '') === "\x03")) === 0, 'nur 3 s vorausgeschickt (71 Perioden), noch kein Ende: ' . $first);
Kernel::advance(1);
$f = $take('192.168.0.50', 40003);
$second = count(array_filter($f, static fn(array $x): bool => ($x['data'][0] ?? '') === "\x02"));
check($second >= 22 && $second <= 24 && count(array_filter($f, static fn(array $x): bool => ($x['data'] ?? '') === "\x03")) === 0, 'nach 1 s füllt der Timer um 1 s nach (' . $second . ' Perioden), noch kein Ende');
Kernel::advance(4);
$f = $take('192.168.0.50', 40003);
$rest = count(array_filter($f, static fn(array $x): bool => ($x['data'][0] ?? '') === "\x02"));
check($first + $second + $rest === 118 && count(array_filter($f, static fn(array $x): bool => ($x['data'] ?? '') === "\x03")) === 1, 'insgesamt 118 Perioden (5 s, 117,2 aufgerundet), danach das Ende');

section('WAV-Datei aus dem Medienordner');
$dir = IPS_GetKernelDir() . 'media/';
@mkdir($dir, 0755, true);
$wav = EmPcm::wrapWav(EmPcm::pack(array_fill(0, 2400, 800)), 24000);
file_put_contents($dir . 'em_test.wav', $wav);
check(EMGD_SpeakFile($dev, $dir . 'em_test.wav') === '', 'SpeakFile angenommen');
$f = $take('192.168.0.50', 40003);
check(count(array_filter($f, static fn(array $x): bool => ($x['data'][0] ?? '') === "\x02")) === 3, '0,1 s bei 24 kHz = 3 Perioden bei 48 kHz');
check(EMGD_SpeakFile($dev, '/etc/passwd') === 'audio file not found' && EMGD_SpeakFile($dev, $dir . '../x') === 'audio file not found', 'Dateien außerhalb des Medienordners werden abgewiesen');
file_put_contents($dir . 'em_bad.wav', 'kein wav');
check(EMGD_SpeakFile($dev, $dir . 'em_bad.wav') === 'not a WAV file', 'kaputte Datei wird gemeldet');
@unlink($dir . 'em_test.wav');
@unlink($dir . 'em_bad.wav');

section('Verbindungen enden, Fehler im Protokoll');
$deliver('192.168.0.50', 40002, 2);
check(World::value($dev, 'ONLINE') === false, 'Verbindung weg: Gerät offline');
check(EMGD_Beep($dev, 1) === 'device has no audio connection', 'und keine Wiedergabe mehr');
$deliver('192.168.0.51', 41000, 1);
$deliver('192.168.0.51', 41000, 0, "POST / HTTP/1.1\r\n\r\n");
$f = $take('192.168.0.51', 41000);
check(str_contains($f[0]['http'] ?? '', '400') && !empty($f[1]['close']), 'Fremde HTTP-Anfrage: 400 und Schließen');
$deliver('192.168.0.52', 41001, 1);
$deliver('192.168.0.52', 41001, 0, $request('/shell/abc'));
$f = $take('192.168.0.52', 41001);
check(str_contains($f[0]['http'] ?? '', '400'), 'unbekannter Pfad (/shell) wird abgewiesen');
$deliver('192.168.0.53', 41002, 1);
$deliver('192.168.0.53', 41002, 0, $request('/control'));
$take('192.168.0.53', 41002);
$deliver('192.168.0.53', 41002, 0, EmWebSocket::text('x'));
$f = $take('192.168.0.53', 41002);
check(!empty($f[0]['close']) || !empty($f[count($f) - 1]['close']), 'unmaskierter Rahmen: Verbindung wird geschlossen');
$deliver('192.168.0.54', 41003, 1);
$deliver('192.168.0.54', 41003, 0, $request('/control'));
$take('192.168.0.54', 41003);
Kernel::advance(90);
$f = $take('192.168.0.54', 41003);
check(count(array_filter($f, static fn(array $x): bool => ($x['op'] ?? 0) === EmWebSocket::OP_PING)) >= 2 && !empty(array_filter($f, static fn(array $x): bool => !empty($x['close']))),
    'stille Verbindung wird alle 20 s gepingt und nach über 60 s geschlossen');

section('AutoApprove');
$gw2cfg = IPS_SetProperty($gw, 'AutoApprove', true);
IPS_ApplyChanges($gw);
$deliver('192.168.0.60', 42000, 1);
$deliver('192.168.0.60', 42000, 0, $request('/control'));
$take('192.168.0.60', 42000);
$deliver('192.168.0.60', 42000, 0, $send($register('NEUGERAET1')));
$f = $take('192.168.0.60', 42000);
check(($json($f[0] ?? [])['type'] ?? '') === 'ack', 'mit AutoApprove wird ein neues Gerät sofort angenommen');

check(Kernel::$warnings === [], 'keine PHP-Warnungen' . (Kernel::$warnings === [] ? '' : ': ' . implode(' | ', Kernel::$warnings)));
check(World::logLines('/ERROR/') === [], 'keine Fehler im Log' . (World::logLines('/ERROR/') === [] ? '' : ': ' . implode(' | ', World::logLines('/ERROR/'))));
done();

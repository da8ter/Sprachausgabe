<?php

declare(strict_types=1);

/** Prüfung der reinen EchoMuse-Bausteine (WebSocket, Protokoll, PCM) ohne Symcon: php tests/echomuse_lib_test.php */
require __DIR__ . '/../libs/EmWebSocket.php';
require __DIR__ . '/../libs/EmProtocol.php';
require __DIR__ . '/../libs/EmPcm.php';
require __DIR__ . '/../libs/EmWsClient.php';
require __DIR__ . '/../libs/EmRealtime.php';
require __DIR__ . '/../libs/SymDoVoiceClient.php';

$n = 0;
$fail = 0;
function check(bool $ok, string $label): void
{
    global $n, $fail;
    $n++;
    if (!$ok) {
        $fail++;
        echo "FAIL: $label\n";
    } else {
        echo "ok   $label\n";
    }
}
function section(string $t): void { echo "== $t\n"; }

section('WebSocket-Handshake');
check(EmWebSocket::acceptKey('dGhlIHNhbXBsZSBub25jZQ==') === 's3pPLMBiTxaQ9kYGzzhZRbK+xOo=', 'Accept-Schlüssel gegen das Beispiel aus RFC 6455');
$req = "GET /control HTTP/1.1\r\nHost: 192.0.2.6:8767\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\nX-EM-Token: abc\r\n\r\n";
$p = EmWebSocket::parseRequest($req . "\x81");
check($p !== null && $p['path'] === '/control' && $p['headers']['x-em-token'] === 'abc' && $p['rest'] === "\x81" && EmWebSocket::isUpgrade($p['headers']), 'Pfad, Header, Rest hinter dem Kopf, Upgrade erkannt');
check(EmWebSocket::parseRequest(substr($req, 0, 40)) === null, 'unvollständiger Kopf: noch nichts');
$p2 = EmWebSocket::parseRequest("GET /data?x=1 HTTP/1.1\r\n\r\n");
check($p2 !== null && $p2['path'] === '/data', 'Pfad ohne Abfrage');
$threw = false;
try { EmWebSocket::parseRequest(str_repeat('A', 9000)); } catch (\InvalidArgumentException $e) { $threw = true; }
check($threw, 'zu großer Kopf wird abgewiesen');
$threw = false;
try { EmWebSocket::parseRequest("POST / HTTP/1.1\r\n\r\n"); } catch (\InvalidArgumentException $e) { $threw = true; }
check($threw, 'kein GET wird abgewiesen');

section('Rahmen');
check(bin2hex(EmWebSocket::text('Hi')) === '810248' . '69', 'Server-Textrahmen unmaskiert');
$big = str_repeat('x', 300);
check(substr(EmWebSocket::binary($big), 0, 4) === "\x82\x7E\x01\x2C", '16-Bit-Länge');
check(substr(EmWebSocket::binary(str_repeat('y', 70000)), 0, 2) === "\x82\x7F", '64-Bit-Länge');
$d = EmWebSocket::decode(EmWebSocket::clientFrame(EmWebSocket::OP_TEXT, '{"type":"pong"}', "\x37\xfa\x21\x3d"));
check(count($d['messages']) === 1 && $d['messages'][0]['data'] === '{"type":"pong"}' && $d['buffer'] === '', 'maskierten Client-Rahmen entschlüsseln (RFC-Maske)');
$two = EmWebSocket::clientFrame(EmWebSocket::OP_TEXT, 'a') . EmWebSocket::clientFrame(EmWebSocket::OP_BINARY, "\x01\x02");
$d = EmWebSocket::decode($two . "\x81");
check(count($d['messages']) === 2 && $d['messages'][1]['op'] === EmWebSocket::OP_BINARY && $d['buffer'] === "\x81", 'zwei Rahmen und ein angefangener Rest');
$frag = EmWebSocket::clientFrame(EmWebSocket::OP_TEXT, 'Hel', null, false) . EmWebSocket::clientFrame(EmWebSocket::OP_PING, 'p') . EmWebSocket::clientFrame(EmWebSocket::OP_CONT, 'lo');
$d = EmWebSocket::decode($frag);
check(count($d['messages']) === 2 && $d['messages'][0]['op'] === EmWebSocket::OP_PING && $d['messages'][1]['data'] === 'Hello', 'Fragmentierung mit Ping dazwischen');
$part = EmWebSocket::decode(EmWebSocket::clientFrame(EmWebSocket::OP_BINARY, 'AB', null, false));
$rest = EmWebSocket::decode(EmWebSocket::clientFrame(EmWebSocket::OP_CONT, 'CD'), $part['partial'], $part['partialOp']);
check($part['messages'] === [] && $rest['messages'][0]['data'] === 'ABCD', 'Fragmente über zwei Aufrufe');
$whole = EmWebSocket::clientFrame(EmWebSocket::OP_BINARY, str_repeat('z', 200));
$d1 = EmWebSocket::decode(substr($whole, 0, 50));
$d2 = EmWebSocket::decode($d1['buffer'] . substr($whole, 50));
check($d1['messages'] === [] && count($d2['messages']) === 1 && strlen($d2['messages'][0]['data']) === 200, 'Rahmen in zwei Stücken angeliefert');
$threw = false;
try { EmWebSocket::decode(EmWebSocket::text('x')); } catch (\InvalidArgumentException $e) { $threw = true; }
check($threw, 'unmaskierter Client-Rahmen ist ein Protokollverstoß');
$threw = false;
try { EmWebSocket::decode("\x82\xFF" . pack('J', 5000000) . "\0\0\0\0"); } catch (\InvalidArgumentException $e) { $threw = true; }
check($threw, 'übergroßer Rahmen wird abgewiesen, bevor Speicher belegt wird');

section('Protokoll');
$reg = EmProtocol::parseRegister(['type' => 'register', 'device_id' => 'G090LF0123456789', 'version' => 'v2.30.1', 'capabilities' => ['mic', 'speaker', 'bad cap!', 'leds'], 'ip' => '192.0.2.50', 'base_os' => 'emos', 'board' => 'biscuit']);
check($reg !== null && $reg['id'] === 'G090LF0123456789' && $reg['caps'] === ['mic', 'speaker', 'leds'] && $reg['ip'] === '192.0.2.50', 'register: Fähigkeiten gefiltert, Gerätekennung übernommen');
check(EmProtocol::parseRegister(['type' => 'register', 'device_id' => '../../etc']) === null && EmProtocol::parseRegister(['type' => 'stats']) === null, 'register: Pfad als Kennung und fremde Nachricht abgewiesen');
$ack = json_decode(EmProtocol::ack('X1', 1790000000000), true);
check($ack === ['type' => 'ack', 'device_id' => 'X1', 'features' => ['output_chain'], 'time_ms' => 1790000000000], 'ack mit Fähigkeiten und Uhrzeit');
check(json_decode(EmProtocol::volumeSet(500), true)['level'] === 127 && EmProtocol::percentToLevel(100) === 127 && EmProtocol::levelToPercent(64) === 50, 'Lautstärke bleibt unter der Einheitsverstärkung');
check(EmProtocol::speakerFrame("\1\2") === "\x02\x01\x02" && EmProtocol::speakerEnd() === "\x03", 'Sprach-Rahmen 0x02 und Ende 0x03');
check(EmProtocol::decodeJson('{"type":"button","down":true}')['down'] === true && EmProtocol::decodeJson('[1]') === null && EmProtocol::decodeJson('kaputt') === null, 'JSON mit Typfeld, sonst null');

section('PCM');
$mono24 = EmPcm::pack(array_fill(0, 2400, 1000));
$wav = EmPcm::wrapWav($mono24, 24000); // 0,1 s bei 24 kHz
$r = EmPcm::fromWav($wav);
check($r['error'] === '' && strlen($r['pcm']) === 9600, '24 kHz → 48 kHz verdoppelt die Länge (' . strlen($r['pcm']) . ' Byte)');
$st = 'RIFF' . pack('V', 36 + 8) . 'WAVE' . 'fmt ' . pack('VvvVVvv', 16, 1, 2, 48000, 192000, 4, 16) . 'data' . pack('V', 8) . pack('s*', 1000, 3000, -2000, -4000);
$r = EmPcm::fromWav($st);
check($r['error'] === '' && array_values(unpack('s*', $r['pcm'])) === [2000, -3000], 'Stereo wird zu Mono gemittelt');
$stream = EmPcm::wrapWav(EmPcm::pack(array_fill(0, 480, 5)), 48000);
$stream = substr($stream, 0, 40) . pack('V', 0xFFFFFFFF) . substr($stream, 44);
check(strlen(EmPcm::fromWav($stream)['pcm']) === 960, 'Streaming-WAV mit unbekannter Länge');
check(EmPcm::fromWav('RIFFxxxxWAVE')['error'] !== '' && EmPcm::fromWav(str_repeat('a', 100))['error'] === 'not a WAV file', 'Fremdes oder kaputtes Format wird gemeldet');
$mp3ish = 'RIFF' . pack('V', 36) . 'WAVE' . 'fmt ' . pack('VvvVVvv', 16, 1, 1, 8000, 8000, 1, 8) . 'data' . pack('V', 0);
check(EmPcm::fromWav($mp3ish)['error'] === 'only 16-bit PCM WAV is supported', '8-Bit-WAV wird abgewiesen');
$pcm = EmPcm::tone(0.5);
$periods = EmPcm::periods($pcm);
check(strlen($pcm) === 48000 && count($periods) === 12 && strlen($periods[11]) === 4096 && substr($periods[11], -2) === "\0\0", '0,5 s = 12 Perioden zu 4096 Byte, letzte mit Stille aufgefüllt');
check(abs(EmPcm::seconds($pcm) - 0.5) < 1e-9 && abs(EmPcm::PERIOD_SECONDS * 1000 - 42.667) < 0.01, 'Dauer und Periodenlänge (42,7 ms)');
$samples = array_values(unpack('s*', $pcm));
check(max($samples) <= 9830 && max($samples) > 9000 && abs($samples[0]) < 50, 'Sinuston mit Amplitude 0,3 und sanftem Einsatz');

section('WebSocket als Client');
$key = 'dGhlIHNhbXBsZSBub25jZQ==';
$rq = EmWsClient::request('api.openai.com', '/v1/realtime?model=gpt-realtime-mini', $key, ['Authorization' => 'Bearer ek_x', "Bad\r\nHeader" => 'x', 'X-Evil' => "a\r\nInjected: 1"]);
check(str_starts_with($rq, "GET /v1/realtime?model=gpt-realtime-mini HTTP/1.1\r\nHost: api.openai.com\r\n") && str_contains($rq, "Authorization: Bearer ek_x\r\n") && !str_contains($rq, 'Injected') && !str_contains($rq, 'Bad'), 'Anfrage mit Kopfzeilen, Einschleusen von Zeilen verhindert');
$resp = "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: s3pPLMBiTxaQ9kYGzzhZRbK+xOo=\r\n\r\n" . EmWebSocket::text('{"type":"session.created"}');
$pr = EmWsClient::parseResponse($resp);
check($pr !== null && $pr['status'] === 101 && EmWsClient::accepted($pr['status'], $pr['headers'], $key) && !EmWsClient::accepted(101, ['sec-websocket-accept' => 'falsch'], $key), 'Antwort 101, Accept-Schlüssel geprüft');
$d = EmWebSocket::decode($pr['rest'], '', 0, false);
check(count($d['messages']) === 1 && $d['messages'][0]['data'] === '{"type":"session.created"}', 'unmaskierter Server-Rahmen hinter der Antwort');
check(EmWsClient::parseResponse("HTTP/1.1 101 Sw") === null && EmWsClient::parseResponse("HTTP/1.1 401 Unauthorized\r\n\r\n")['status'] === 401, 'unvollständige Antwort, Fehlerstatus');
$up = EmWebSocket::decode(EmWsClient::text('hi') . EmWsClient::pong('p') . EmWsClient::close(1000));
check(count($up['messages']) === 3 && $up['messages'][0]['data'] === 'hi', 'Rahmen nach oben sind maskiert und lesbar');

section('Realtime-Ereignisse');
check(json_decode(EmRealtime::appendAudio("\x01\x02"), true) === ['type' => 'input_audio_buffer.append', 'audio' => 'AQI='], 'Audio anhängen');
$out = json_decode(EmRealtime::functionOutput('call_1', '{"ok":true}'), true);
check($out['type'] === 'conversation.item.create' && $out['item'] === ['type' => 'function_call_output', 'call_id' => 'call_1', 'output' => '{"ok":true}'], 'Werkzeugergebnis an das Modell');
check(EmRealtime::path('gpt-realtime-mini') === '/v1/realtime?model=gpt-realtime-mini' && EmRealtime::headers('ek')['Authorization'] === 'Bearer ek', 'Pfad und Autorisierung');
foreach (['response.output_audio.delta', 'response.audio.delta'] as $t) {
    $e = EmRealtime::parseEvent(json_encode(['type' => $t, 'delta' => base64_encode("\x10\x00\x20\x00")]));
    check($e['kind'] === 'audio' && $e['pcm24k'] === "\x10\x00\x20\x00", "Audio-Ereignis $t");
}
check(EmRealtime::parseEvent('{"type":"input_audio_buffer.speech_stopped"}')['kind'] === 'speech_stopped' && EmRealtime::parseEvent('{"type":"input_audio_buffer.speech_started"}')['kind'] === 'speech_started', 'Sprechbeginn und Sprechende');
$t = EmRealtime::parseEvent('{"type":"response.function_call_arguments.done","name":"einkauf_hinzu","arguments":"{\"artikel\":\"Milch\"}","call_id":"call_9"}');
check($t === ['kind' => 'tool', 'name' => 'einkauf_hinzu', 'args' => '{"artikel":"Milch"}', 'callId' => 'call_9'], 'Werkzeugaufruf');
$dn = EmRealtime::parseEvent('{"type":"response.done","response":{"status":"completed","output":[{"type":"function_call","name":"x","arguments":"{}","call_id":"c1"},{"type":"message"}]}}');
check($dn['kind'] === 'done' && $dn['status'] === 'completed' && count($dn['tools']) === 1 && $dn['tools'][0]['callId'] === 'c1', 'response.done mit Werkzeugaufruf im Ausgang');
check(EmRealtime::parseEvent('{"type":"error","error":{"message":"Invalid token","code":"invalid_api_key"}}') === ['kind' => 'error', 'message' => 'Invalid token', 'code' => 'invalid_api_key'], 'Fehler');
check(EmRealtime::parseEvent('kaputt')['kind'] === 'other' && EmRealtime::parseEvent('{"type":"rate_limits.updated"}')['kind'] === 'other' && EmRealtime::parseEvent('{"type":"response.audio.delta","delta":"!!!"}')['kind'] === 'other', 'Unbekanntes und Kaputtes wird ignoriert');
check(EmRealtime::parseEvent('{"type":"conversation.item.input_audio_transcription.completed","transcript":"Hallo"}') === ['kind' => 'transcript', 'role' => 'user', 'text' => 'Hallo'], 'Mitschrift des Gesagten');

section('SymDo-Sprachweg');
$calls = [];
$fake = static function (string $url, array $headers, string $body) use (&$calls): array {
    $calls[] = ['url' => $url, 'auth' => $headers[0] ?? '', 'body' => json_decode($body, true)];
    $a = json_decode($body, true)['action'];
    $resp = match ($a) {
        'open' => ['ok' => true, 'value' => 'ek_secret', 'model' => 'gpt-realtime-mini', 'sessionSeconds' => 90, 'ttl' => 60],
        'tool' => ['ok' => true, 'ergebnis' => 'Milch steht auf der Liste'],
        default => ['ok' => true],
    };
    return ['status' => 200, 'body' => json_encode($resp), 'err' => ''];
};
$sd = new SymDoVoiceClient('http://127.0.0.1:3777/hook/lists/app/', 'tok123', $fake);
check($sd->configured() && !(new SymDoVoiceClient('ftp://x', 'a'))->configured() && !(new SymDoVoiceClient('http://x', ''))->configured(), 'konfiguriert nur mit Adresse und Token');
$o = $sd->open('u1', 77);
check($o['ok'] && $o['value'] === 'ek_secret' && $o['model'] === 'gpt-realtime-mini' && $calls[0]['url'] === 'http://127.0.0.1:3777/hook/lists/app/v1/voice' && $calls[0]['auth'] === 'Authorization: Bearer tok123' && $calls[0]['body'] === ['action' => 'open', 'tile' => 77, 'userId' => 'u1'], 'open: Adresse, Bearer, Nutzlast, Schlüssel');
check($sd->opened('echo-1', 'u1', 77) && $calls[1]['body']['action'] === 'opened', 'opened');
$t = $sd->tool('echo-1', 'u1', 'einkauf_hinzu', '{"artikel":"Milch"}', 'call_9');
check($t['ergebnis'] === 'Milch steht auf der Liste' && $calls[2]['body'] === ['action' => 'tool', 'callId' => 'echo-1', 'userId' => 'u1', 'name' => 'einkauf_hinzu', 'arguments' => '{"artikel":"Milch"}', 'fnId' => 'call_9'], 'tool: Name, Argumente, fnId');
$sd->close('echo-1');
check($calls[3]['body']['action'] === 'close', 'close');
$live = new SymDoVoiceClient('http://x', 't', static fn(): array => ['status' => 200, 'body' => '{"ok":true,"live":true}', 'err' => '']);
check(str_contains($live->open('u', 1)['error'], 'GPT-Live'), 'GPT-Live (WebRTC) wird erkannt und erklärt');
$no = new SymDoVoiceClient('http://x', 't', static fn(): array => ['status' => 200, 'body' => '{"ok":false,"error":{"code":"voice_quota","message":"The daily talk time is used up."}}', 'err' => '']);
check($no->open('u', 1) === ['ok' => false, 'error' => 'The daily talk time is used up.'], 'Tagesbudget aufgebraucht wird weitergereicht');
$bad = new SymDoVoiceClient('http://x', 't', static fn(): array => ['status' => 0, 'body' => '', 'err' => 'Connection refused']);
check($bad->open('u', 1) === ['ok' => false, 'error' => 'Connection refused'] && ($bad->tool('c', 'u', 'n', '{}', 'f')['ok'] ?? true) === false, 'Netzfehler wird gemeldet');

echo "\n" . ($fail === 0 ? "Alle $n Prüfungen bestanden." : "$fail von $n Prüfungen fehlgeschlagen.") . "\n";
exit($fail === 0 ? 0 : 1);

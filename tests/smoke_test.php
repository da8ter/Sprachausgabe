<?php

declare(strict_types=1);

/**
 * Rauchtest der Sprachausgabe ohne Symcon: läuft im Prüfstand-Kernel der LG-ThinQ-Bibliothek
 * (Nachbarordner modules/LGThinQ, Symcon 9.1 im Speicher inkl. Darstellungsregeln).
 * Echo Remote, Fully Kiosk und Skripte sind Attrappen, die jeden Aufruf mitschreiben.
 *   php tests/smoke_test.php
 */
$sdk = __DIR__ . '/../../LGThinQ/tests/bootstrap.php';
if (!is_file($sdk)) {
    fwrite(STDERR, "Prüfstand-Kernel fehlt: $sdk\n");
    exit(1);
}
require $sdk;

// ------------------------------------------------------------------ Attrappen fremder Funktionen
$GLOBALS['calls'] = [];
$GLOBALS['conditions'] = [];   // condition string => bool
$GLOBALS['scripts'] = [50001 => true];
function ECHOREMOTE_TextToSpeech(int $id, string $tts): bool { $GLOBALS['calls'][] = ['echo', $id, $tts, 0]; return true; }
function ECHOREMOTE_TextToSpeechVolume(int $id, string $tts, int $vol): bool { $GLOBALS['calls'][] = ['echo', $id, $tts, $vol]; return true; }
function ECHOREMOTE_Announcement(int $id, string $tts): bool { $GLOBALS['calls'][] = ['announce', $id, $tts, 0]; return true; }
function FKB_textToSpeech(int $id, string $tts): bool { $GLOBALS['calls'][] = ['fully', $id, $tts, 0]; return true; }
function IPS_ScriptExists(int $id): bool { return isset($GLOBALS['scripts'][$id]); }
function IPS_RunScriptEx(int $id, array $params): bool { $GLOBALS['lastScriptParams'] = $params; $GLOBALS['calls'][] = ['script', $id, $params['TEXT'], (int)$params['VOLUME'], $params['TARGET']]; return true; }
function IPS_GetOption(string $o): mixed { return $o === 'ScriptOutputBufferLimit' ? 1048576 : 0; }
function IPS_IsConditionPassing(string $c): bool { return $GLOBALS['conditions'][$c] ?? true; }
function GetValueFormatted(int $id): string
{
    $v = GetValue($id);
    return is_bool($v) ? ($v ? 'An' : 'Aus') : (is_float($v) ? number_format($v, 1, ',', '') : (string)$v);
}

Kernel::reset();
Kernel::loadLibrary(dirname(__DIR__));
const HUB = '{8DF4B1D9-E589-452D-BE37-8EC5DEF4CF13}';
const ANN = '{94CE47EF-0417-49FB-9DE9-6B292F709A06}';
Kernel::registerModule(['ModuleID' => '{00000000-0000-0000-0000-0000000000E0}', 'ModuleName' => 'Geräte-Attrappe', 'ModuleType' => 3]);
$echo = Kernel::createInstance('{00000000-0000-0000-0000-0000000000E0}'); // stands in for an Echo instance: only its ID matters
$fully = Kernel::createInstance('{00000000-0000-0000-0000-0000000000E0}');
$taken = static function (): array { $c = $GLOBALS['calls']; $GLOBALS['calls'] = []; return $c; };
$fire = static function (int $vid, mixed $new): void {
    $old = GetValue($vid);
    SetValue($vid, $new);
    Kernel::sendMessage($vid, VM_UPDATE, [GetValue($vid), GetValue($vid) !== $old, $old, Kernel::now()]);
};
$hubVar = static fn(int $hub, string $ident): mixed => World::value($hub, $ident);

section('Zentrale');
$hub = Kernel::createInstance(HUB);
IPS_SetProperty($hub, 'Outputs', json_encode([
    ['name' => 'Küche', 'type' => 'echo_speak', 'instance' => $echo, 'script' => 0, 'volume' => 40, 'default' => true],
    ['name' => 'Flur', 'type' => 'echo_announce', 'instance' => $echo, 'script' => 0, 'volume' => 0, 'default' => false],
    ['name' => 'Tablet', 'type' => 'fully', 'instance' => $fully, 'script' => 0, 'volume' => 0, 'default' => false],
    ['name' => 'Log', 'type' => 'script', 'instance' => 0, 'script' => 50001, 'volume' => 25, 'default' => false],
]));
IPS_ApplyChanges($hub);
check(IPS_GetInstance($hub)['InstanceStatus'] === IS_ACTIVE, 'aktiv');
check($hubVar($hub, 'MASTER') === true && $hubVar($hub, 'VOLUME_FACTOR') === 100, 'nach dem Anlegen: Sprachausgabe an, Lautstärke 100 %');
foreach (['MASTER', 'QUIET', 'VOLUME_FACTOR', 'LAST_TEXT', 'LAST_TIME'] as $ident) {
    check(World::variable($hub, $ident)['presentation'] !== [], "$ident hat eine Darstellung");
}
IPS_SetProperty($hub, 'Cooldown', 30);
IPS_ApplyChanges($hub);
check($hubVar($hub, 'MASTER') === true, 'erneutes Übernehmen setzt die Schalter nicht zurück');

section('SPAZ_Speak und Warteschlange');
check(SPAZ_Speak($hub, 'Hallo Welt', '', 0) === '', 'eingereiht');
check($taken() === [], 'noch nichts gesprochen (läuft über den Timer)');
Kernel::advance(1);
check($taken() === [['echo', $echo, 'Hallo Welt', 40]], 'Standardgerät Küche mit seiner Lautstärke');
check($hubVar($hub, 'LAST_TEXT') === 'Hallo Welt' && $hubVar($hub, 'LAST_TIME') > 0, 'Letzte Ansage gesetzt');
check(SPAZ_Speak($hub, 'Hallo Welt', '', 0) === 'same announcement within the cooldown', 'gleicher Text innerhalb der Sperrfrist abgewiesen');
SPAZ_Speak($hub, 'Eins', 'Flur, Tablet', 0);
SPAZ_Speak($hub, 'Zwei', 'Log', 80);
Kernel::advance(1);
check($taken() === [['announce', $echo, 'Eins', 0], ['fully', $fully, 'Eins', 0]], 'erste Ansage auf Flur (Gong) und Tablet, die zweite wartet');
Kernel::advance(10);
check($taken() === [['script', 50001, 'Zwei', 80, 'Log']], 'zweite danach, Skript bekommt Text, Lautstärke und Ziel');
check(IPS_GetInstance($hub) && Kernel::$instances[$hub]['timers']['Process']['interval'] === 0, 'Timer steht, wenn die Schlange leer ist');

section('Schalter, Lautstärke, Bedingung');
RequestAction(World::varId($hub, 'VOLUME_FACTOR'), 50);
SPAZ_Speak($hub, 'Leiser', 'Küche', 0);
Kernel::advance(1);
check($taken() === [['echo', $echo, 'Leiser', 20]], 'Faktor 50 % halbiert die Gerätelautstärke');
RequestAction(World::varId($hub, 'VOLUME_FACTOR'), 100);
RequestAction(World::varId($hub, 'MASTER'), false);
check(SPAZ_Speak($hub, 'Aus', '', 0) === 'announcements are switched off', 'Hauptschalter aus: abgewiesen');
check(SPAZ_SpeakUrgent($hub, 'Feuer', '', 0) === '', 'dringend geht trotzdem');
Kernel::advance(1);
check(count($taken()) === 1, 'und wird gesprochen');
RequestAction(World::varId($hub, 'MASTER'), true);
RequestAction(World::varId($hub, 'QUIET'), true);
check(SPAZ_Speak($hub, 'Nachts', '', 0) === 'quiet mode', 'Ruhemodus: abgewiesen');
RequestAction(World::varId($hub, 'QUIET'), false);
IPS_SetProperty($hub, 'Condition', 'NIEMAND_DA');
IPS_ApplyChanges($hub);
$GLOBALS['conditions']['NIEMAND_DA'] = false;
check(SPAZ_Speak($hub, 'Keiner hört', '', 0) === 'global condition not met', 'globale Bedingung nicht erfüllt: abgewiesen');
IPS_SetProperty($hub, 'Condition', '');
IPS_ApplyChanges($hub);
check(SPAZ_Speak($hub, 'Unbekannt', 'Garten', 0) === '', 'unbekanntes Ziel wird eingereiht');
Kernel::advance(1);
check($taken() === [] && World::warningsLike('/./') === [] && count(World::logLines('/Unbekanntes Ausgabeger|Unknown output/')) >= 1,
    'und als Warnung geloggt, nicht gesprochen');

$vv = IPS_CreateVariable(VARIABLETYPE_INTEGER);
SetValue($vv, 60);
IPS_SetProperty($hub, 'Outputs', json_encode(array_map(static fn(array $o): array => $o['name'] === 'Log' ? $o + ['volumeVar' => $vv] : $o,
    json_decode(IPS_GetProperty($hub, 'Outputs'), true))));
IPS_ApplyChanges($hub);
SPAZ_Speak($hub, 'Variable', 'Log', 0);
Kernel::advance(1);
check(($taken()[0][3] ?? null) === 60, 'Lautstärke-Variable des Geräts schlägt den festen Wert');

section('Ansage: Auslöser „Wert gleich“');
$wm = IPS_CreateVariable(VARIABLETYPE_STRING);
IPS_SetName($wm, 'Betriebsstatus');
SetValue($wm, 'Run');
$a = Kernel::createInstance(ANN);
check(Kernel::$instances[$a]['connection'] === $hub, 'verbindet sich mit der Zentrale');
check(IPS_GetInstance($a)['InstanceStatus'] === 201, 'ohne Text: Status 201');
IPS_SetProperty($a, 'TriggerVariable', $wm);
IPS_SetProperty($a, 'TriggerRule', SpeechTrigger::EQUALS);
IPS_SetProperty($a, 'TriggerValue', 'Finished');
IPS_SetProperty($a, 'Texts', "Die Waschmaschine ist fertig ({value}).");
IPS_ApplyChanges($a);
check(IPS_GetInstance($a)['InstanceStatus'] === IS_ACTIVE && World::value($a, 'ACTIVE') === true, 'aktiv, Schalter an');
$fire($wm, 'Pause');
Kernel::advance(1);
check($taken() === [], 'anderer Wert: nichts');
$fire($wm, 'Finished');
Kernel::advance(1);
check($taken() === [['echo', $echo, 'Die Waschmaschine ist fertig (Finished).', 40]], 'Wechsel auf Finished: Ansage mit Platzhalter');
check(World::value($a, 'LAST_RUN') > 0, 'Letzte Ansage der Instanz gesetzt');
Kernel::advance(60);
$fire($wm, 'Finished');
Kernel::advance(1);
check($taken() === [], 'gleicher Wert erneut ohne „auch bei Wiederholung“: nichts');

section('Ansage: Grenzwert, Ziele, Bedingung, Aktiv');
$t = IPS_CreateVariable(VARIABLETYPE_FLOAT);
IPS_SetName($t, 'Füllstand');
SetValue($t, 10.0);
$b = Kernel::createInstance(ANN);
IPS_SetProperty($b, 'TriggerVariable', $t);
IPS_SetProperty($b, 'TriggerRule', SpeechTrigger::ABOVE);
IPS_SetProperty($b, 'TriggerValue', '80');
IPS_SetProperty($b, 'Texts', "Badewanne voll: {value} ({name}).\nBadewanne voll: {value} ({name}).");
IPS_SetProperty($b, 'Targets', json_encode([['name' => 'Tablet', 'use' => true], ['name' => 'Küche', 'use' => false]]));
IPS_SetProperty($b, 'Condition', 'BAD');
IPS_ApplyChanges($b);
$GLOBALS['conditions']['BAD'] = false;
$fire($t, 85.0);
Kernel::advance(1);
check($taken() === [], 'Bedingung nicht erfüllt: nichts');
$GLOBALS['conditions']['BAD'] = true;
$fire($t, 50.0);
$fire($t, 90.0);
Kernel::advance(1);
check($taken() === [['fully', $fully, 'Badewanne voll: 90,0 (Füllstand).', 0]], 'Überschreiten von 80: nur auf dem angehakten Tablet');
$fire($t, 95.0);
Kernel::advance(40);
check($taken() === [], 'weiter über der Grenze: kein zweites Mal');
RequestAction(World::varId($b, 'ACTIVE'), false);
$fire($t, 50.0);
$fire($t, 99.0);
Kernel::advance(1);
check($taken() === [], 'Ansage deaktiviert: nichts');

section('Testknopf und Formulare');
ob_start();
SPAA_Test($b);
$out = (string)ob_get_clean();
Kernel::advance(1);
check(str_contains($out, 'Gesendet') && count($taken()) === 1, 'Test spricht trotz deaktivierter Ansage: ' . trim($out));
$formA = json_decode(Kernel::$instances[$b]["object"]->GetConfigurationForm(), true);
$targets = null;
foreach ($formA['elements'] as $el) {
    if (($el['name'] ?? '') === 'Targets') {
        $targets = $el;
    }
}
check(is_array($targets) && array_column($targets['values'], 'name') === ['Küche', 'Flur', 'Tablet', 'Log']
    && $targets['values'][2]['use'] === true && $targets['columns'][0]['save'] === true,
    'Zielliste aus den Geräten der Zentrale, Häkchen übernommen, Namensspalte mit save');
check(is_array(json_decode(Kernel::$instances[$hub]["object"]->GetConfigurationForm(), true)), 'Formular der Zentrale ist gültiges JSON');

section('KI-Stimme: alle fünf Anbieter (Netz als Attrappe)');
$GLOBALS['http'] = [];
$GLOBALS['httpAnswer'] = null;
SpeechAi::$transport = static function (string $url, array $headers, string $body): array {
    $GLOBALS['http'][] = ['url' => $url, 'headers' => $headers, 'body' => $body];
    if ($GLOBALS['httpAnswer'] !== null) {
        return $GLOBALS['httpAnswer'];
    }
    if (str_contains($url, 'generativelanguage')) {
        return ['status' => 200, 'body' => json_encode(['steps' => [['type' => 'model_output', 'content' => [['type' => 'audio', 'data' => base64_encode(str_repeat("\0", 64)), 'mime_type' => 'audio/L16;rate=24000']]]]]), 'err' => ''];
    }
    return ['status' => 200, 'body' => 'ID3' . str_repeat('x', 200), 'err' => ''];
};
$cases = [
    'openai' => [['openai_key' => 'sk-test'], 'api.openai.com/v1/audio/speech', 'Authorization: Bearer sk-test', 'mp3'],
    'azure' => [['azure_key' => 'az', 'azure_region' => 'westeurope'], 'westeurope.tts.speech.microsoft.com', 'Ocp-Apim-Subscription-Key: az', 'mp3'],
    'elevenlabs' => [['eleven_key' => 'el'], 'api.elevenlabs.io/v1/text-to-speech/21m00Tcm4TlvDq8ikWAM?output_format=mp3_44100_64', 'xi-api-key: el', 'mp3'],
    'polly' => [['polly_key' => str_repeat('A', 20), 'polly_secret' => str_repeat('s', 40)], 'polly.eu-central-1.amazonaws.com/v1/speech', 'Authorization: AWS4-HMAC-SHA256', 'mp3'],
    'gemini' => [['gemini_key' => 'gm'], 'generativelanguage.googleapis.com/v1beta/interactions', 'x-goog-api-key: gm', 'wav'],
];
foreach ($cases as $provider => [$cfg, $urlPart, $headerPart, $format]) {
    $GLOBALS['http'] = [];
    $ai = new SpeechAi(['provider' => $provider] + $cfg);
    $r = $ai->synthesize('Hallo & Tschüss');
    $call = $GLOBALS['http'][0] ?? ['url' => '', 'headers' => [], 'body' => ''];
    $hdr = implode("\n", $call['headers']);
    check($r['error'] === '' && $r['audio'] !== '' && str_contains($call['url'], $urlPart) && str_contains($hdr, $headerPart) && $ai->format() === $format,
        "$provider: Adresse, Schlüssel im Kopf, Format $format" . ($r['error'] !== '' ? ' — ' . $r['error'] : ''));
}
check(str_contains((new SpeechAi(['provider' => 'azure', 'azure_key' => 'k']))->synthesize('A & B') ? $GLOBALS['http'][count($GLOBALS['http']) - 1]['body'] : '', 'A &amp; B'), 'Azure: Text im SSML maskiert');
check(str_starts_with((new SpeechAi(['provider' => 'gemini', 'gemini_key' => 'k']))->synthesize('x')['audio'], 'RIFF'), 'Gemini: rohes PCM bekommt einen WAV-Kopf');
check((new SpeechAi(['provider' => 'openai']))->missing() === 'OpenAI key missing' && (new SpeechAi([]))->missing() !== '', 'fehlender Schlüssel wird benannt, kein Aufruf');
$GLOBALS['httpAnswer'] = ['status' => 401, 'body' => '{"error":"invalid key"}', 'err' => ''];
$r = (new SpeechAi(['provider' => 'elevenlabs', 'eleven_key' => 'x']))->synthesize('x');
check($r['audio'] === '' && str_contains($r['error'], '401'), 'Fehlerantwort des Anbieters wird gemeldet: ' . $r['error']);
$GLOBALS['httpAnswer'] = ['status' => 200, 'body' => '{"oops":1}', 'err' => ''];
check(str_contains((new SpeechAi(['provider' => 'openai', 'openai_key' => 'x']))->synthesize('x')['error'], 'expected audio'), 'JSON mit Status 200 ist keine Tondatei');
$GLOBALS['httpAnswer'] = null;

section('KI-Stimme in der Zentrale: Zwischenspeicher, Skript-Ausgabe, Webhook');
$GLOBALS['scripts'][50002] = true;
$hub2 = Kernel::createInstance(HUB);
IPS_SetProperty($hub2, 'Outputs', json_encode([['name' => 'Sonos', 'type' => 'ai_script', 'instance' => 0, 'script' => 50002, 'volume' => 30, 'default' => true]]));
IPS_SetProperty($hub2, 'AiProvider', 'openai');
IPS_SetProperty($hub2, 'AiOpenAIKey', 'sk-test');
IPS_SetProperty($hub2, 'AiBaseUrl', 'http://192.0.2.6:3777/');
IPS_SetProperty($hub2, 'Cooldown', 0);
IPS_ApplyChanges($hub2);
check(isset(Kernel::$instances[$hub2]['hooks']['sprachausgabe']), 'Webhook /hook/sprachausgabe registriert');
$GLOBALS['http'] = [];
$GLOBALS['calls'] = [];
SPAZ_Speak($hub2, 'Die Waschmaschine ist fertig.', '', 0);
Kernel::advance(1);
$call = $GLOBALS['calls'][0] ?? [];
check(($call[0] ?? '') === 'script' && count($GLOBALS['http']) === 1, 'eine Aufnahme erzeugt, Skript aufgerufen');
$params = $GLOBALS['lastScriptParams'] ?? [];
check(preg_match('#^http://192\.0\.2\.6:3777/hook/sprachausgabe/[a-f0-9]{64}\.mp3$#', (string)($params['AUDIO_URL'] ?? '')) === 1 && is_file((string)($params['AUDIO_FILE'] ?? '')),
    'Skript bekommt AUDIO_URL und AUDIO_FILE: ' . ($params['AUDIO_URL'] ?? ''));
SPAZ_Speak($hub2, 'Die Waschmaschine ist fertig.', '', 0);
Kernel::advance(5);
check(count($GLOBALS['http']) === 1, 'gleicher Text: aus dem Zwischenspeicher, kein zweiter Abruf');
$_SERVER['REQUEST_URI'] = parse_url((string)$params['AUDIO_URL'], PHP_URL_PATH);
$hook = new ReflectionMethod(Kernel::$instances[$hub2]['object'], 'ProcessHookData');
ob_start();
@$hook->invoke(Kernel::$instances[$hub2]["object"]); // headers cannot be sent on the CLI
$served = (string)ob_get_clean();
check($served === file_get_contents((string)$params['AUDIO_FILE']), 'Webhook liefert die Datei aus');
$_SERVER['REQUEST_URI'] = '/hook/sprachausgabe/../../settings.json';
ob_start();
@$hook->invoke(Kernel::$instances[$hub2]["object"]); // headers cannot be sent on the CLI
check(trim((string)ob_get_clean()) === 'Not found', 'Webhook liefert nur Kennungen aus dem Zwischenspeicher (kein Pfad-Ausbruch)');
IPS_SetProperty($hub2, 'AiOpenAIKey', '');
IPS_ApplyChanges($hub2);
$GLOBALS['calls'] = [];
SPAZ_Speak($hub2, 'Ohne Schlüssel', '', 0);
Kernel::advance(1);
check($GLOBALS['calls'] === [] && count(World::logLines('/OpenAI key missing/')) >= 1, 'ohne Schlüssel: Skript nicht aufgerufen, Grund im Log');
foreach (glob(IPS_GetKernelDir() . 'media/sprachausgabe_' . $hub2 . '/*') ?: [] as $f) { @unlink($f); }
$form = json_decode(Kernel::$instances[$hub2]['object']->GetConfigurationForm(), true);
check(is_array($form) && str_contains(json_encode($form), 'AiElevenKey') && str_contains(json_encode($form), 'AiPollySecret'), 'Formular enthält alle Anbieter');

section('EchoMuse als Ausgabe: WAV von jedem Anbieter, Aufruf des Geräts');
function EMGD_SpeakFile(int $id, string $file): string { $GLOBALS['calls'][] = ['emgd', $id, $file]; return is_file($file) ? '' : 'audio file not found'; }
$wavCases = [
    'openai' => [['openai_key' => 'k'], '"response_format":"wav"'],
    'azure' => [['azure_key' => 'k'], 'riff-24khz-16bit-mono-pcm'],
    'elevenlabs' => [['eleven_key' => 'k'], 'output_format=pcm_24000'],
    'polly' => [['polly_key' => str_repeat('A', 20), 'polly_secret' => str_repeat('s', 40)], '"OutputFormat":"pcm"'],
];
foreach ($wavCases as $provider => [$cfg, $needle]) {
    $GLOBALS['http'] = [];
    $GLOBALS['httpAnswer'] = ['status' => 200, 'body' => str_repeat("\1\0", 200), 'err' => ''];
    $ai = new SpeechAi(['provider' => $provider] + $cfg);
    $r = $ai->synthesize('Hallo', true);
    $call = $GLOBALS['http'][0] ?? ['url' => '', 'headers' => [], 'body' => ''];
    $seen = $call['url'] . implode('|', $call['headers']) . $call['body'];
    $isWav = str_starts_with($r['audio'], 'RIFF') || $provider === 'openai' || $provider === 'azure';
    check($r['error'] === '' && str_contains($seen, $needle) && $isWav && $ai->format(true) === 'wav' && $ai->hash('x', true) !== $ai->hash('x', false),
        "$provider: fordert WAV an ($needle), eigene Kennung für die WAV-Aufnahme" . ($r['error'] !== '' ? ' — ' . $r['error'] : ''));
}
$GLOBALS['httpAnswer'] = ['status' => 200, 'body' => str_repeat("\1\0", 3200), 'err' => ''];
$r = (new SpeechAi(['provider' => 'elevenlabs', 'eleven_key' => 'k']))->synthesize('x', true);
$conv = EmPcm::fromWav($r['audio']);
check($conv['error'] === '' && strlen($conv['pcm']) === 12800, 'ElevenLabs-PCM (24 kHz, ohne Kopf) wird mit WAV-Kopf zu 48-kHz-PCM: ' . strlen($conv['pcm']) . ' Byte');
$GLOBALS['httpAnswer'] = null;
$GLOBALS['http'] = [];
$GLOBALS['httpAnswer'] = ['status' => 200, 'body' => EmPcm::wrapWav(str_repeat("\1\0", 480), 24000), 'err' => ''];
$hub3 = Kernel::createInstance(HUB);
IPS_SetProperty($hub3, 'Outputs', json_encode([['name' => 'Arbeitszimmer', 'type' => 'echomuse', 'instance' => $echo, 'script' => 0, 'volume' => 0, 'default' => true]]));
IPS_SetProperty($hub3, 'AiProvider', 'openai');
IPS_SetProperty($hub3, 'AiOpenAIKey', 'sk-test');
IPS_SetProperty($hub3, 'Cooldown', 0);
IPS_ApplyChanges($hub3);
$GLOBALS['calls'] = [];
SPAZ_Speak($hub3, 'Der Dot spricht.', '', 0);
Kernel::advance(1);
$c = $GLOBALS['calls'][0] ?? [];
check(($c[0] ?? '') === 'emgd' && ($c[1] ?? 0) === $echo && str_ends_with((string)($c[2] ?? ''), '.wav') && is_file((string)$c[2]), 'Zentrale ruft EMGD_SpeakFile mit der WAV-Aufnahme des Textes');
check(str_contains(($GLOBALS['http'][0]['body'] ?? ''), '"response_format":"wav"'), 'und fordert dafür WAV an');
SPAZ_Speak($hub3, 'Der Dot spricht.', '', 0);
Kernel::advance(2);
check(count($GLOBALS['http']) === 1, 'gleicher Text: aus dem Zwischenspeicher');
foreach (glob(IPS_GetKernelDir() . 'media/sprachausgabe_' . $hub3 . '/*') ?: [] as $f) { @unlink($f); }
$GLOBALS['httpAnswer'] = null;

check(Kernel::$warnings === [], 'keine PHP-Warnungen' . (Kernel::$warnings === [] ? '' : ': ' . implode(' | ', Kernel::$warnings)));
check(World::logLines('/ERROR/') === [], 'keine Fehler im Log' . (World::logLines('/ERROR/') === [] ? '' : ': ' . implode(' | ', World::logLines('/ERROR/'))));
done();

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
function IPS_RunScriptEx(int $id, array $params): bool { $GLOBALS['calls'][] = ['script', $id, $params['TEXT'], (int)$params['VOLUME'], $params['TARGET']]; return true; }
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

check(Kernel::$warnings === [], 'keine PHP-Warnungen' . (Kernel::$warnings === [] ? '' : ': ' . implode(' | ', Kernel::$warnings)));
check(World::logLines('/ERROR/') === [], 'keine Fehler im Log' . (World::logLines('/ERROR/') === [] ? '' : ': ' . implode(' | ', World::logLines('/ERROR/'))));
done();

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
// events (time triggers of the announcement list): only what the module calls
$GLOBALS['events'] = [];
foreach (['EVENTTYPE_TRIGGER' => 0, 'EVENTTYPE_CYCLIC' => 1, 'EVENTTYPE_SCHEDULE' => 2] as $c => $v) {
    defined($c) || define($c, $v);
}
function IPS_CreateEvent(int $type): int { $id = Kernel::createObject(OBJECTTYPE_EVENT); $GLOBALS['events'][$id] = ['type' => $type, 'script' => '', 'active' => false, 'time' => null]; return $id; }
function IPS_EventExists(int $id): bool { return isset($GLOBALS['events'][$id]) && Kernel::objectExists($id); }
function IPS_DeleteEvent(int $id): bool { Kernel::deleteObject($id); unset($GLOBALS['events'][$id]); return true; }
function IPS_SetEventCyclic(int $id, int $dt, int $dv, int $dd, int $ddv, int $tt, int $tv): bool { return true; }
function IPS_SetEventCyclicTimeFrom(int $id, int $h, int $m, int $s): bool { $GLOBALS['events'][$id]['time'] = [$h, $m, $s]; return true; }
function IPS_SetEventScript(int $id, string $code): bool { $GLOBALS['events'][$id]['script'] = $code; return true; }
function IPS_SetEventActive(int $id, bool $active): bool { $GLOBALS['events'][$id]['active'] = $active; return true; }
function IPS_SetHidden(int $id, bool $hidden): bool { return true; }
function IPS_SetEventScheduleAction(int $id, int $action, string $name, int $color, string $script): bool { $GLOBALS['events'][$id]['actions'][$action] = $name; return true; }
function IPS_SetEventScheduleGroup(int $id, int $group, int $days): bool { $GLOBALS['events'][$id]['groups'][$group] = ['ID' => $group, 'Days' => $days, 'Points' => []]; return true; }
function IPS_SetEventScheduleGroupPoint(int $id, int $group, int $point, int $h, int $m, int $s, int $action): bool {
    $GLOBALS['events'][$id]['groups'][$group]['Points'][$point] = ['ID' => $point, 'Start' => ['Hour' => $h, 'Minute' => $m, 'Second' => $s], 'ActionID' => $action];
    return true;
}
function IPS_GetEvent(int $id): array {
    $e = $GLOBALS['events'][$id] ?? [];
    return ['EventActive' => $e['active'] ?? false, 'ScheduleGroups' => array_values(array_map(static fn(array $g): array => ['Points' => array_values($g['Points'])] + $g, $e['groups'] ?? []))];
}
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

section('Auslöser: Regel aus dem Bedingungs-Dialog');
$rv = IPS_CreateVariable(VARIABLETYPE_STRING);
$rule = SpeechTrigger::rule(SpeechTrigger::ruleJson($rv, 0, 'Finished'));
check($rule !== null && $rule['variableID'] === $rv && SpeechTrigger::passes($rule, 'Finished') && !SpeechTrigger::passes($rule, 'Run'), 'Regel „= Finished“');
check(SpeechTrigger::firesRule(SpeechTrigger::MODE_BECOMES, $rule, 'Finished', true, 'Run') && !SpeechTrigger::firesRule(SpeechTrigger::MODE_BECOMES, $rule, 'Finished', false, 'Finished'),
    '„wenn erfüllt“: nur beim Übergang');
check(SpeechTrigger::firesRule(SpeechTrigger::MODE_WHILE, $rule, 'Finished', false, 'Finished') && SpeechTrigger::firesRule(SpeechTrigger::MODE_ANY_UPDATE, $rule, 'x', false, 'x')
    && !SpeechTrigger::firesRule(SpeechTrigger::MODE_ANY_CHANGE, $rule, 'x', false, 'x'), '„solange erfüllt“, „jede Aktualisierung“, „jede Änderung“');
$fv = IPS_CreateVariable(VARIABLETYPE_FLOAT);
$legacy = SpeechTrigger::legacyToCondition($fv, SpeechTrigger::ABOVE, '80', false, VARIABLETYPE_FLOAT);
$lr = SpeechTrigger::rule($legacy['condition']);
check($lr['comparison'] === 2 && $lr['value'] == 80 && $legacy['mode'] === SpeechTrigger::MODE_BECOMES, 'altes Format „über Grenzwert 80“ wird zu „> 80, wenn erfüllt“');
check(SpeechTrigger::passes(['comparison' => 5, 'value' => 12, 'type' => 0], 12.0) && !SpeechTrigger::passes(['comparison' => 2, 'value' => 12, 'type' => 0], 'x'),
    '≤ vergleicht Zahlen, > mit Text ist nie erfüllt');

section('Push Zentrale: Empfänger, Nachrichten, Schalter');
const PHUB = '{5C17B714-C9BD-4E8E-B429-E52B6EA84FF5}';
$GLOBALS['push'] = [];
function VISU_PostNotificationEx(int $id, string $title, string $text, string $icon, string $sound, int $target): int { $GLOBALS['push'][] = ['visu', $id, $title, $text, $icon, $sound, $target]; return 1; }
function WFC_PushNotification(int $id, string $title, string $text, string $sound, int $target): bool { $GLOBALS['push'][] = ['wfc', $id, $title, $text]; return true; }
function IPS_RunScriptWaitEx(int $id, array $params): string { $GLOBALS['scriptParams'] = $params; return '  Wasser im ' . ($params['VALUE'] ? 'Keller' : '?') . '  '; }
$pushed = static function (): array { $p = $GLOBALS['push']; $GLOBALS['push'] = []; return $p; };
$switch = static function (int $hub, string $message, string $recipient): int {
    foreach (IPS_GetChildrenIDs($hub) as $c) {
        if (IPS_GetName($c) === $message . ' – ' . $recipient) {
            return $c;
        }
    }
    return 0;
};
$visuS = Kernel::createInstance('{00000000-0000-0000-0000-0000000000E0}');
$visuP = Kernel::createInstance('{00000000-0000-0000-0000-0000000000E0}');
$door = IPS_CreateVariable(VARIABLETYPE_BOOLEAN);
IPS_SetName($door, 'Haustür');
$win = IPS_CreateVariable(VARIABLETYPE_BOOLEAN);
$alarm = IPS_CreateVariable(VARIABLETYPE_BOOLEAN);
$GLOBALS['scripts'][50003] = true;
$ph = Kernel::createInstance(PHUB);
IPS_SetProperty($ph, 'Recipients', json_encode([
    ['name' => 'Stephan', 'type' => 'visu', 'instance' => $visuS],
    ['name' => 'Simone', 'type' => 'wfc', 'instance' => $visuP],
]));
IPS_SetProperty($ph, 'Messages', json_encode([
    ['active' => true, 'name' => 'Haustür', 'TriggerCondition' => SpeechTrigger::ruleJson($door, 0, true), 'TriggerMode' => 0, 'Title' => 'Haustür',
        'Texts' => 'Die {name} wurde geöffnet', 'TextScript' => 0, 'Icon' => 'Door', 'Sound' => 'alarm', 'TargetObject' => 0, 'Condition' => '', 'DelaySeconds' => 0, 'RepeatMinutes' => 0],
    ['active' => true, 'name' => 'Fenster', 'TriggerCondition' => SpeechTrigger::ruleJson($win, 0, true), 'TriggerMode' => 0, 'Title' => '',
        'Texts' => 'Fenster schließen!', 'TextScript' => 0, 'Icon' => '', 'Sound' => '', 'TargetObject' => 0, 'Condition' => '', 'DelaySeconds' => 3600, 'RepeatMinutes' => 60],
    ['active' => true, 'name' => 'Wasser', 'TriggerCondition' => SpeechTrigger::ruleJson($alarm, 0, true), 'TriggerMode' => 0, 'Title' => 'Alarm',
        'Texts' => '', 'TextScript' => 50003, 'Icon' => '', 'Sound' => '', 'TargetObject' => 0, 'Condition' => '', 'DelaySeconds' => 0, 'RepeatMinutes' => 0],
]));
IPS_SetProperty($ph, 'Cooldown', 0);
IPS_ApplyChanges($ph);
Kernel::advance(1); // ids for new rows are applied via timer
$rows = json_decode(IPS_GetProperty($ph, 'Messages'), true);
check(IPS_GetInstance($ph)['InstanceStatus'] === IS_ACTIVE && World::value($ph, 'MASTER') === true, 'Zentrale aktiv, Hauptschalter an');
check(count(array_unique(array_column($rows, 'msgId'))) === 3 && strlen($rows[0]['msgId']) === 8, 'jede Nachricht bekommt beim Übernehmen eine feste Kennung');
$sDoor = $switch($ph, 'Haustür', 'Stephan');
$pDoor = $switch($ph, 'Haustür', 'Simone');
check($sDoor > 0 && $pDoor > 0 && GetValue($sDoor) === true && $switch($ph, 'Wasser', 'Simone') > 0, 'je Nachricht und Empfänger ein Schalter, neu = an');
$fire($door, true);
check($pushed() === [['visu', $visuS, 'Haustür', 'Die Haustür wurde geöffnet', 'Door', 'alarm', 0], ['wfc', $visuP, 'Haustür', 'Die Haustür wurde geöffnet']],
    'Tür auf: Kachel-Visu mit Icon und Ton, WebFront ohne');
check(World::value($ph, 'LAST_TEXT') === 'Haustür: Die Haustür wurde geöffnet', 'Letzte Benachrichtigung gesetzt');
RequestAction($pDoor, false);
$fire($door, false);
$fire($door, true);
check(array_column($pushed(), 0) === ['visu'], 'Simone für die Haustür abgeschaltet: nur Stephan');
RequestAction(World::varId($ph, 'MASTER'), false);
$fire($door, false);
$fire($door, true);
check($pushed() === [], 'Hauptschalter aus: nichts');
RequestAction(World::varId($ph, 'MASTER'), true);
$rows[0]['active'] = false;
IPS_SetProperty($ph, 'Messages', json_encode($rows));
IPS_ApplyChanges($ph);
$fire($door, false);
$fire($door, true);
check($pushed() === [], 'Nachricht in der Liste deaktiviert: nichts');
$rows[0]['active'] = true;
$rows[0]['name'] = 'Eingang';
IPS_SetProperty($ph, 'Messages', json_encode($rows));
IPS_ApplyChanges($ph);
check($switch($ph, 'Eingang', 'Simone') === $pDoor && GetValue($pDoor) === false, 'umbenannte Nachricht behält ihre Schalter (Kennung statt Name)');

section('Push Zentrale: Verzögerung, Wiederholung, Textskript, Sperrfrist, Formular');
$fire($win, true);
check($pushed() === [], 'Fenster auf: noch nichts (Verzögerung)');
Kernel::advance(1800);
$fire($win, true); // repeated updates must not restart the countdown
Kernel::advance(1801);
check(count($pushed()) === 2, 'nach einer Stunde: an beide Empfänger');
Kernel::advance(3601);
check(count($pushed()) === 2, 'eine Stunde später: Wiederholung');
$fire($win, false);
Kernel::advance(7300);
check($pushed() === [], 'Fenster zu: keine weitere Erinnerung');
$fire($win, true);
Kernel::advance(600);
$fire($win, false);
Kernel::advance(3600);
check($pushed() === [], 'vor Ablauf wieder zu: nichts');
$fire($alarm, true);
$p = $pushed();
check(($p[0][3] ?? '') === 'Wasser im Keller' && ($GLOBALS['scriptParams']['VARIABLE'] ?? 0) === $alarm, 'Text kommt aus dem Skript (getrimmt), Skript kennt die Auslöser-Variable');
check(PUSHZ_Trigger($ph, 'Wasser') === '' && count($pushed()) === 2 && PUSHZ_Trigger($ph, 'Gibtsnicht') === 'unknown message', 'PUSHZ_Trigger löst nach Namen aus');
IPS_SetProperty($ph, 'Cooldown', 60);
IPS_ApplyChanges($ph);
check(PUSHZ_Trigger($ph, 'Wasser') === 'same notification within the cooldown' && $pushed() === [], 'Sperrfrist 60 s: erneutes Auslösen kurz danach abgewiesen');
check(PUSHZ_Send($ph, 'Info', 'Freitext', 'Stephan') === '' && $pushed() === [['visu', $visuS, 'Info', 'Freitext', '', '', 0]], 'PUSHZ_Send an einen Empfänger');
check(PushOutputs::cut(str_repeat('a', 40), PushOutputs::TITLE_MAX) === str_repeat('a', 31) . '…', 'zu langer Titel wird auf 32 Zeichen gekürzt');
check(PushOutputs::send(['type' => 'visu', 'instance' => 0], 't', 'x', '', '', 0) === 'no visualization selected', 'ohne Visualisierung: Grund statt Fehler');
$out = PUSHZ_PreviewMessage($ph, '{name} offen', "Die {name} steht {value}", 0, SpeechTrigger::ruleJson($door, 0, true));
check($out === "Haustür offen\n\nDie Haustür steht An", 'Vorschau im Dialog zeigt Titel und Text mit Platzhaltern: ' . str_replace("\n", ' | ', $out));
$out = PUSHZ_TestMessage($ph, 'T', 'Test', 0, '', 'Alert', '', 0);
check(str_contains($out, 'Gesendet') && count($pushed()) === 2, 'Test im Dialog sendet an alle, auch innerhalb der Sperrfrist: ' . trim($out));
$form = json_decode(Kernel::$instances[$ph]['object']->GetConfigurationForm(), true);
check(is_array($form) && ($form['elements'][0]['type'] ?? '') === 'List' && is_array($form['elements'][0]['form'] ?? null), 'Formular: Nachrichtenliste mit eigenem Bearbeiten-Dialog');

section('Ansagen als Liste in der Zentrale');
$hl = Kernel::createInstance(HUB);
IPS_SetProperty($hl, 'Outputs', json_encode([
    ['name' => 'Küche', 'type' => 'echo_speak', 'instance' => $echo, 'script' => 0, 'volume' => 40, 'default' => true],
    ['name' => 'Tablet', 'type' => 'fully', 'instance' => $fully, 'script' => 0, 'volume' => 0, 'default' => false],
]));
IPS_SetProperty($hl, 'Cooldown', 0);
$wash = IPS_CreateVariable(VARIABLETYPE_STRING);
IPS_SetName($wash, 'Waschmaschine');
$tub = IPS_CreateVariable(VARIABLETYPE_FLOAT);
SetValue($tub, 10.0);
$tabletKey = 'T_' . substr(md5('tablet'), 0, 6);
IPS_SetProperty($hl, 'Announcements', json_encode([
    ['active' => true, 'name' => 'Wäsche', 'TriggerCondition' => SpeechTrigger::ruleJson($wash, 0, 'Finished'), 'TriggerMode' => 0,
        'TimeEnabled' => false, 'Time' => '', 'Texts' => 'Die {name} ist fertig', 'Condition' => '', 'Volume' => 0, 'Urgent' => false],
    ['active' => true, 'name' => 'Wanne', 'TriggerCondition' => SpeechTrigger::ruleJson($tub, 2, 80), 'TriggerMode' => 0,
        'TimeEnabled' => true, 'Time' => '{"hour":6,"minute":30,"second":0}', 'Texts' => 'Wanne voll: {value}', 'Condition' => '', 'Volume' => 0, 'Urgent' => false, $tabletKey => true],
]));
IPS_ApplyChanges($hl);
Kernel::advance(1);
$annRows = json_decode(IPS_GetProperty($hl, 'Announcements'), true);
check(count(array_filter(array_column($annRows, 'annId'))) === 2, 'jede Ansage bekommt beim Übernehmen eine feste Kennung');
$GLOBALS['calls'] = [];
$fire($wash, 'Finished');
Kernel::advance(1);
check($taken() === [['echo', $echo, 'Die Waschmaschine ist fertig', 40]], 'Waschmaschine fertig: Ansage aus der Liste auf dem Standardgerät');
$fire($tub, 90.0);
Kernel::advance(1);
check($taken() === [['fully', $fully, 'Wanne voll: 90,0', 0]], 'Grenzwert überschritten: nur auf dem angehakten Tablet');
$ev = @IPS_GetObjectIDByIdent('ANNTIME_' . $annRows[1]['annId'], $hl);
$sw = World::varId($hl, 'A_' . $annRows[0]['annId']);
check($sw > 0 && GetValue($sw) === true && IPS_GetName($sw) === 'Wäsche' && World::variable($hl, 'A_' . $annRows[0]['annId'])['presentation'] !== [],
    'je Ansage eine Schaltvariable unter der Zentrale (neu = an, Name der Ansage, Darstellung)');
RequestAction($sw, false);
$fire($wash, 'Run');
$fire($wash, 'Finished');
Kernel::advance(1);
check($taken() === [], 'Schaltvariable aus: Ansage schweigt');
RequestAction($sw, true);
$vol = World::varId($hl, 'V_' . $annRows[0]['annId']);
check($vol > 0 && GetValue($vol) === 0 && IPS_GetName($vol) === 'Wäsche – Lautstärke' && World::variable($hl, 'V_' . $annRows[0]['annId'])['presentation'] !== [],
    'je Ansage eine Lautstärke-Variable (Startwert aus dem Dialog, Darstellung Slider)');
RequestAction($vol, 70);
$fire($wash, 'Run');
$fire($wash, 'Finished');
Kernel::advance(1);
check($taken() === [['echo', $echo, 'Die Waschmaschine ist fertig', 70]], 'Lautstärke aus der Variable (in der Visu verstellt)');
$tmp = json_decode(IPS_GetProperty($hl, 'Announcements'), true);
IPS_ApplyChanges($hl);
check(GetValue($vol) === 70, 'erneutes Übernehmen ohne Änderung im Dialog lässt den Visu-Wert stehen');
$tmp[0]['Volume'] = 30;
IPS_SetProperty($hl, 'Announcements', json_encode($tmp));
IPS_ApplyChanges($hl);
check(GetValue($vol) === 30, 'neuer Wert im Dialog-Slider wird beim Übernehmen in die Variable geschrieben');
RequestAction($vol, 0);
check(is_int($ev) && $ev > 0 && $GLOBALS['events'][$ev]['time'] === [6, 30, 0] && $GLOBALS['events'][$ev]['active'] === true
    && str_contains($GLOBALS['events'][$ev]['script'], "SPAZ_TriggerAnnouncement($hl, '" . $annRows[1]['annId'] . "')"), 'täglicher Zeitauslöser als Ereignis unter der Zentrale (06:30, ruft die Ansage per Kennung)');
check(SPAZ_TriggerAnnouncement($hl, 'Wanne') === '' && SPAZ_TriggerAnnouncement($hl, 'gibtsnicht') === 'unknown announcement', 'SPAZ_TriggerAnnouncement nach Name');
Kernel::advance(2);
$taken();
$annRows[0]['active'] = false;
$annRows[1]['TimeEnabled'] = false;
IPS_SetProperty($hl, 'Announcements', json_encode($annRows));
IPS_ApplyChanges($hl);
$fire($wash, 'Run');
$fire($wash, 'Finished');
Kernel::advance(1);
check($taken() === [] && !is_int(@IPS_GetObjectIDByIdent('ANNTIME_' . $annRows[1]['annId'], $hl)), 'deaktivierte Ansage schweigt, abgeschalteter Zeitauslöser wird gelöscht');
$out = SPAZ_PreviewAnnouncement($hl, "A {name}\nB {value}", SpeechTrigger::ruleJson($wash, 0, 'x'));
check($out === "• A Waschmaschine\n• B Finished", 'Vorschau im Dialog: ' . str_replace("\n", ' | ', $out));
$out = SPAZ_TestAnnouncement($hl, 'Probe', '', json_encode(['Küche' => false, 'Tablet' => true]), 0);
Kernel::advance(1);
check(str_contains($out, 'Gesendet') && $taken() === [['fully', $fully, 'Probe', 0]], 'Test im Dialog spricht auf den angehakten Geräten');
check(is_array(json_decode(Kernel::$instances[$hl]['object']->GetConfigurationForm(), true)), 'Formular mit Ansageliste ist gültiges JSON');

section('Wochenpläne: Sprechzeiten der Zentrale und eigener Plan');
$g = [['ID' => 0, 'Days' => 127, 'Points' => [['ID' => 0, 'Start' => ['Hour' => 0, 'Minute' => 0, 'Second' => 0], 'ActionID' => 2], ['ID' => 1, 'Start' => ['Hour' => 8, 'Minute' => 0, 'Second' => 0], 'ActionID' => 1]]]];
check(SpeechSchedule::actionAt($g, mktime(7, 59, 0, 10, 8, 2026)) === 2 && SpeechSchedule::actionAt($g, mktime(8, 0, 0, 10, 8, 2026)) === 1 && SpeechSchedule::actionAt($g, mktime(23, 0, 0, 10, 8, 2026)) === 1,
    'Standardplan: vor 08:00 Ruhe, ab 08:00 Sprechen');
$we = [['ID' => 0, 'Days' => 31, 'Points' => [['ID' => 0, 'Start' => ['Hour' => 6, 'Minute' => 0, 'Second' => 0], 'ActionID' => 1], ['ID' => 1, 'Start' => ['Hour' => 22, 'Minute' => 0, 'Second' => 0], 'ActionID' => 2]]],
    ['ID' => 1, 'Days' => 96, 'Points' => [['ID' => 0, 'Start' => ['Hour' => 9, 'Minute' => 0, 'Second' => 0], 'ActionID' => 1]]]];
check(SpeechSchedule::actionAt($we, mktime(7, 0, 0, 10, 10, 2026)) === 2, 'Samstag 07:00: noch Ruhe vom Freitag 22:00 (Schaltpunkt des Vortags)');
check(SpeechSchedule::actionAt($we, mktime(5, 0, 0, 10, 12, 2026)) === 1 && SpeechSchedule::allows([], time()), 'Montag 05:00: noch Sprechen vom Sonntag; leerer Plan sperrt nichts');
$main = @IPS_GetObjectIDByIdent('SCHEDULE_MAIN', $hl);
check(is_int($main) && ($GLOBALS['events'][$main]['type'] ?? -1) === 2 && count($GLOBALS['events'][$main]['groups'][0]['Points']) === 2, 'Zentrale legt den Wochenplan „Sprechzeiten“ an (00:00 Ruhe, 08:00 Sprechen)');
$quietAll = static function (int $eid): void { $GLOBALS['events'][$eid]['groups'] = [0 => ['ID' => 0, 'Days' => 127, 'Points' => [0 => ['ID' => 0, 'Start' => ['Hour' => 0, 'Minute' => 0, 'Second' => 0], 'ActionID' => 2]]]]; };
$quietAll($main);
$annRows = json_decode(IPS_GetProperty($hl, 'Announcements'), true);
$annRows[0]['active'] = true;
$annRows[0]['Schedule'] = 1;
IPS_SetProperty($hl, 'Announcements', json_encode($annRows));
IPS_ApplyChanges($hl);
$GLOBALS['calls'] = [];
$fire($wash, 'Run');
$fire($wash, 'Finished');
Kernel::advance(1);
check($taken() === [] && count($GLOBALS['events'][$main]['groups'][0]['Points']) === 1, 'Sprechzeiten auf Ruhe: Ansage schweigt; vorhandene Schaltpunkte werden nicht überschrieben');
$annRows[0]['Urgent'] = true;
IPS_SetProperty($hl, 'Announcements', json_encode($annRows));
IPS_ApplyChanges($hl);
$fire($wash, 'Run');
$fire($wash, 'Finished');
Kernel::advance(1);
check(count($taken()) === 1, 'dringende Ansage spricht trotz Ruhezeit');
$annRows[0]['Urgent'] = false;
$annRows[0]['Schedule'] = 2;
IPS_SetProperty($hl, 'Announcements', json_encode($annRows));
IPS_ApplyChanges($hl);
$own = @IPS_GetObjectIDByIdent('ANNSCHED_' . $annRows[0]['annId'], $hl);
check(is_int($own) && $GLOBALS['events'][$own]['groups'][0]['Points'][0]['ActionID'] === 2 && str_contains(IPS_GetName($own), 'Wäsche'),
    'eigener Wochenplan wird als Kopie der Sprechzeiten angelegt und nach der Ansage benannt');
$GLOBALS['events'][$own]['groups'][0]['Points'][0]['ActionID'] = 1;
$fire($wash, 'Run');
$fire($wash, 'Finished');
Kernel::advance(1);
check(count($taken()) === 1, 'eigener Plan auf Sprechen: Ansage spricht, obwohl die Sprechzeiten Ruhe haben');
$annRows[0]['Schedule'] = 0;
IPS_SetProperty($hl, 'Announcements', json_encode($annRows));
IPS_ApplyChanges($hl);
check(!is_int(@IPS_GetObjectIDByIdent('ANNSCHED_' . $annRows[0]['annId'], $hl)) && is_int(@IPS_GetObjectIDByIdent('SCHEDULE_MAIN', $hl)), 'kein Zeitplan mehr: eigener Plan wird gelöscht, die Sprechzeiten bleiben');

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

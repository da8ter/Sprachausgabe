<?php

declare(strict_types=1);

/**
 * Übernahme alter Sprachausgaben (Kategorie mit Unterkategorien aus switch, Zeitplan und einem
 * Skript mit festem Text und Auslöser-Ereignis) in die Module Sprachausgabe Zentrale/Ansage.
 *
 * Als Skript-Inhalt in Symcon ausführen. Voreinstellung ist ein PROBELAUF: er liest nur und
 * schreibt den Bericht ins Log (Absender "SPA-Migration"). Erst mit DRY_RUN = false wird angelegt:
 *  - Zentrale in der Kategorie, Ausgabegerät aus der Echo-Kennung der Skripte, Lautstärke über
 *    die bisherige Lautstärke-Variable, globale Bedingung aus den Regeln, die ALLE Ansagen teilen
 *    (z. B. Jemand anwesend, Hauptschalter) — die alten Schalter bleiben in der Visu wirksam;
 *  - je Altansage eine Zeile der Ansageliste der Zentrale (Auslöser, Wert, Wiederholung, Text,
 *    übrige Bedingungen); ihre Schaltvariable unter der Zentrale = Ereignis war aktiv;
 *  - die alten Auslöser-Ereignisse werden DEAKTIVIERT, nicht gelöscht (Rückweg: wieder aktivieren).
 * Bedingungen, die auf Schalter oder Zeitpläne ANDERER Altansagen zeigen (kopierte Ereignisse),
 * werden auf die eigene Kategorie umgehängt oder gestrichen und im Bericht genannt.
 */

// ID der Kategorie mit den alten Sprachausgaben — vor dem Lauf eintragen.
const CATEGORY = 0;
const DRY_RUN = true;
const HUB_GUID = '{8DF4B1D9-E589-452D-BE37-8EC5DEF4CF13}';

if (CATEGORY <= 0 || !IPS_CategoryExists(CATEGORY)) {
    IPS_LogMessage('SPA-Migration', 'Bitte oben CATEGORY auf die Kategorie der alten Sprachausgaben setzen.');
    return;
}

$report = ['dry' => DRY_RUN, 'notes' => [], 'announcements' => []];
$note = static function (string $text) use (&$report): void { $report['notes'][] = $text; };

// ------------------------------------------------------------------ Altbestand lesen
$legacy = [];
$byCategory = []; // variable id => category id (switch/Zeitplan of every legacy announcement)
foreach (IPS_GetChildrenIDs(CATEGORY) as $cat) {
    if (IPS_GetObject($cat)['ObjectType'] !== OBJECTTYPE_CATEGORY) {
        continue;
    }
    $entry = ['category' => $cat, 'name' => IPS_GetName($cat), 'switch' => 0, 'schedule' => 0, 'scripts' => []];
    $stack = [$cat];
    while ($stack !== []) {
        foreach (IPS_GetChildrenIDs(array_pop($stack)) as $id) {
            $o = IPS_GetObject($id);
            if ($o['ObjectType'] === OBJECTTYPE_CATEGORY) {
                $stack[] = $id;
            } elseif ($o['ObjectType'] === OBJECTTYPE_VARIABLE && $o['ObjectName'] === 'switch') {
                $entry['switch'] = $id;
            } elseif ($o['ObjectType'] === OBJECTTYPE_VARIABLE && $o['ObjectName'] === 'Zeitplan') {
                $entry['schedule'] = $id;
            } elseif ($o['ObjectType'] === OBJECTTYPE_SCRIPT) {
                $entry['scripts'][] = $id;
            }
        }
    }
    foreach (['switch', 'schedule'] as $k) {
        if ($entry[$k] > 0) {
            $byCategory[$entry[$k]] = $cat;
        }
    }
    $legacy[] = $entry;
}

$echoIds = [];
$volumeVars = [];
$conditionSets = [];
$plans = [];
foreach ($legacy as $entry) {
    $script = 0;
    $event = 0;
    foreach ($entry['scripts'] as $sid) {
        foreach (IPS_GetChildrenIDs($sid) as $eid) {
            if (IPS_EventExists($eid) && IPS_GetEvent($eid)['EventType'] === EVENTTYPE_TRIGGER) {
                $script = $sid;
                $event = $eid;
            }
        }
    }
    if ($event === 0) {
        $note(sprintf('%s: kein Auslöser-Ereignis (nur Zeitplan) — nichts zu übernehmen', $entry['name']));
        continue;
    }
    $code = IPS_GetScriptContent($script);
    $text = preg_match('/\$text\s*=\s*([\'"])(.*?)\1\s*;/s', $code, $m) === 1 ? $m[2] : '';
    if (preg_match('/\$echoid\s*=\s*GetValueInteger\((\d+)\)/', $code, $m2) === 1) {
        $echoIds[(int)@GetValue((int)$m2[1])] = true;
    }
    foreach ([...(preg_match_all('/ECHOREMOTE_\w+\(\s*(\d{5})\s*,/', $code, $lit) ? $lit[1] : [])] as $literal) {
        $literal = (int)$literal;
        if (!@IPS_InstanceExists($literal)) {
            $note(sprintf('%s: Skript spricht auf #%d, die Instanz gibt es nicht — wird auf die Zentrale umgestellt', $entry['name'], $literal));
        } else {
            $echoIds[$literal] = true;
        }
    }
    if (preg_match('/\$volume\s*=\s*GetValueInteger\((\d+)\)/', $code, $m3) === 1) {
        $volumeVars[(int)$m3[1]] = true;
    }
    $e = IPS_GetEvent($event);
    $rules = [];
    foreach ($e['EventConditions'] as $c) {
        foreach ($c['VariableRules'] as $r) {
            $vid = (int)$r['VariableID'];
            $owner = $byCategory[$vid] ?? null;
            if ($owner !== null && $owner !== $entry['category']) {
                // copied from another announcement: own switch instead, foreign schedule dropped
                $own = IPS_GetName($vid) === 'switch' ? $entry['switch'] : 0;
                $note(sprintf('%s: Bedingung nutzt %s von „%s“ (#%d) — %s', $entry['name'], IPS_GetName($vid), IPS_GetName($owner), $vid,
                    $own > 0 ? 'auf den eigenen Schalter #' . $own . ' umgehängt' : 'gestrichen'));
                if ($own === 0) {
                    continue;
                }
                $vid = $own;
            }
            $rules[$vid . '|' . (int)$r['Comparison'] . '|' . json_encode($r['Value'])] = ['variableID' => $vid, 'comparison' => (int)$r['Comparison'], 'value' => $r['Value'], 'type' => (int)$r['Type']];
        }
    }
    if ($entry['schedule'] > 0) {
        foreach ($rules as $r) {
            if ($r['variableID'] === $entry['schedule'] && $r['value'] === false) {
                $note(sprintf('%s: spricht nur, wenn der Zeitplan AUS ist (Regel Zeitplan = false) — übernommen wie vorhanden', $entry['name']));
            }
        }
    }
    $conditionSets[] = array_keys($rules);
    $ruleMap = [0 => 0, 1 => 1, 2 => 4, 3 => 5, 4 => 2]; // Symcon trigger type → SpeechTrigger rule
    $plans[] = [
        'entry' => $entry, 'script' => $script, 'event' => $event, 'text' => $text, 'rules' => $rules,
        'trigger' => (int)$e['TriggerVariableID'], 'rule' => $ruleMap[(int)$e['TriggerType']] ?? 1,
        'value' => is_bool($e['TriggerValue']) ? ($e['TriggerValue'] ? 'true' : 'false') : (string)$e['TriggerValue'],
        'repeat' => (bool)$e['TriggerSubsequentExecution'], 'active' => (bool)$e['EventActive'],
    ];
}

// rules every announcement shares become the hub's global condition
$common = $conditionSets === [] ? [] : array_values(array_intersect(...$conditionSets));
$globalRules = [];
foreach ($plans as $plan) {
    foreach ($common as $key) {
        $globalRules[$key] = $plan['rules'][$key];
    }
    break;
}
$toCondition = static function (array $rules): string {
    if ($rules === []) {
        return '';
    }
    $variable = [];
    foreach (array_values($rules) as $i => $r) {
        $variable[] = ['id' => $i, 'variableID' => $r['variableID'], 'comparison' => $r['comparison'], 'value' => $r['value'], 'type' => $r['type']];
    }
    return (string)json_encode([['id' => 0, 'parentID' => 0, 'operation' => 0, 'rules' => ['variable' => $variable, 'date' => [], 'time' => [], 'dayOfTheWeek' => []]]]);
};
$echo = (int)(array_key_first($echoIds) ?? 0);
$volumeVar = (int)(array_key_first($volumeVars) ?? 0);
$report['hub'] = [
    'output' => ['name' => 'Küche', 'echo' => $echo . ' ' . ($echo ? IPS_GetLocation($echo) : '?'), 'volumeVar' => $volumeVar . ' ' . ($volumeVar ? IPS_GetName($volumeVar) . ' = ' . GetValue($volumeVar) : '')],
    'globalCondition' => array_map(static fn(array $r): string => IPS_GetName($r['variableID']) . ' #' . $r['variableID'] . ' = ' . json_encode($r['value']), array_values($globalRules)),
];
if (count($echoIds) > 1) {
    $note('Mehrere Echo-Geräte in den Skripten: ' . implode(', ', array_keys($echoIds)) . ' — übernommen wird #' . $echo);
}

// ------------------------------------------------------------------ anlegen
$hub = 0;
if (!DRY_RUN) {
    $existing = IPS_GetInstanceListByModuleID(HUB_GUID);
    $hub = $existing[0] ?? IPS_CreateInstance(HUB_GUID);
    IPS_SetParent($hub, CATEGORY);
    IPS_SetName($hub, 'Sprachausgabe Zentrale');
    IPS_SetProperty($hub, 'Outputs', json_encode([['name' => 'Küche', 'type' => 'echo_speak', 'instance' => $echo, 'script' => 0,
        'volume' => $volumeVar ? (int)GetValue($volumeVar) : 35, 'volumeVar' => $volumeVar, 'default' => true]]));
    IPS_SetProperty($hub, 'Condition', $toCondition($globalRules));
    IPS_SetProperty($hub, 'Cooldown', 30);
    IPS_ApplyChanges($hub);
}
// old trigger (Symcon event type, value as text) → rule of the condition dialog + trigger mode, as SpeechTrigger::legacyToCondition
$toTrigger = static function (array $plan): array {
    $var = (int)$plan['trigger'];
    $type = @IPS_VariableExists($var) ? IPS_GetVariable($var)['VariableType'] : VARIABLETYPE_STRING;
    $raw = (string)$plan['value'];
    $typed = match ($type) {
        VARIABLETYPE_BOOLEAN => in_array(mb_strtolower(trim($raw)), ['1', 'true', 'an', 'ein', 'on', 'ja', 'yes'], true),
        VARIABLETYPE_INTEGER => (int)$raw,
        VARIABLETYPE_FLOAT   => (float)$raw,
        default              => $raw,
    };
    $current = @IPS_VariableExists($var) ? GetValue($var) : $typed;
    $repeatMode = $plan['repeat'] ? 1 : 0;
    [$cmp, $value, $mode] = match ((int)$plan['rule']) {
        0 => [0, $current, 2],          // every update
        1 => [0, $current, 3],          // every change
        3 => [1, $typed, $repeatMode],  // differs
        4 => [2, $typed, $repeatMode],  // above
        5 => [4, $typed, $repeatMode],  // below
        default => [0, $typed, $repeatMode],
    };
    return ['condition' => (string)json_encode([['id' => 0, 'parentID' => 0, 'operation' => 0, 'rules' => ['variable' => [
        ['id' => 0, 'variableID' => $var, 'comparison' => $cmp, 'value' => $value, 'type' => 0]], 'date' => [], 'time' => [], 'dayOfTheWeek' => []]]]), 'mode' => $mode];
};
$listRows = [];
$switchValues = [];
foreach ($plans as $plan) {
    $own = array_diff_key($plan['rules'], array_flip($common));
    $row = [
        'name' => $plan['entry']['name'],
        'trigger' => $plan['trigger'] . ' ' . ($plan['trigger'] ? IPS_GetLocation($plan['trigger']) : ''),
        'rule' => $plan['rule'], 'value' => $plan['value'], 'repeat' => $plan['repeat'],
        'text' => $plan['text'] !== '' ? $plan['text'] : '(kein fester Text)',
        'condition' => array_map(static fn(array $r): string => IPS_GetName($r['variableID']) . ' #' . $r['variableID'] . ' = ' . json_encode($r['value']), array_values($own)),
        'active' => $plan['active'],
    ];
    if ($plan['text'] === '') {
        $row['action'] = 'Skript mit eigener Logik (z. B. Telefonbuch): bleibt, seine ECHOREMOTE-Aufrufe werden auf SPAZ_Speak umgestellt';
    }
    if (!DRY_RUN) {
        if ($plan['text'] !== '') {
            $annId = substr(md5($plan['entry']['category'] . '|' . $plan['entry']['name']), 0, 8);
            $listRows[] = [
                'annId' => $annId, 'active' => true, 'name' => $plan['entry']['name'],
                'TriggerCondition' => $toTrigger($plan)['condition'], 'TriggerMode' => $toTrigger($plan)['mode'],
                'TimeEnabled' => false, 'Time' => '{"hour":7,"minute":0,"second":0}', 'Texts' => $plan['text'],
                'Condition' => $toCondition($own), 'Volume' => 0, 'Urgent' => false, 'Schedule' => 0,
            ];
            $switchValues['A_' . $annId] = $plan['active'];
            $row['list'] = $annId;
        } else {
            $code = IPS_GetScriptContent($plan['script']);
            $new = (string)preg_replace('/ECHOREMOTE_TextToSpeechVolume\(\s*[^,]+,\s*(\$\w+)\s*,\s*\$volume\s*\)/', 'SPAZ_Speak(' . $hub . ', $1, \'\', 0)', $code);
            $new = (string)preg_replace('/^<\?php\s*/', "<?php\n// 01.10.2026: Ausgabe über die Sprachausgabe-Zentrale (#" . $hub . ") statt direkt ECHOREMOTE\n", $new, 1);
            IPS_SetScriptContent($plan['script'], $new);
            $row['script'] = $plan['script'];
            $report['announcements'][] = $row;
            continue; // its event keeps triggering the script
        }
        IPS_SetEventActive($plan['event'], false);
        $row['oldEvent'] = $plan['event'] . ' deaktiviert';
    }
    $report['announcements'][] = $row;
}
if (!DRY_RUN && $hub > 0 && $listRows !== []) {
    // announcements are a list in the hub; each gets a switch variable A_<annId> (set from the old event's state)
    $existingRows = json_decode(IPS_GetProperty($hub, 'Announcements'), true) ?: [];
    IPS_SetProperty($hub, 'Announcements', json_encode(array_merge($existingRows, $listRows), JSON_UNESCAPED_UNICODE));
    IPS_ApplyChanges($hub);
    foreach ($switchValues as $ident => $on) {
        $vid = @IPS_GetObjectIDByIdent($ident, $hub);
        if (is_int($vid)) {
            RequestAction($vid, $on);
        }
    }
    $report['list'] = count($listRows) . ' Ansagen in der Liste der Zentrale';
}
IPS_LogMessage('SPA-Migration', json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
return json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

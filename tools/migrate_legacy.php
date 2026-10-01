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
 *  - je Altansage eine Ansage-Instanz in ihrer Kategorie (Auslöser, Wert, Wiederholung, Text,
 *    übrige Bedingungen, Aktiv = Ereignis war aktiv);
 *  - die alten Auslöser-Ereignisse werden DEAKTIVIERT, nicht gelöscht (Rückweg: wieder aktivieren).
 * Bedingungen, die auf Schalter oder Zeitpläne ANDERER Altansagen zeigen (kopierte Ereignisse),
 * werden auf die eigene Kategorie umgehängt oder gestrichen und im Bericht genannt.
 */

const CATEGORY = 32940;
const DRY_RUN = true;
const HUB_GUID = '{8DF4B1D9-E589-452D-BE37-8EC5DEF4CF13}';
const ANN_GUID = '{94CE47EF-0417-49FB-9DE9-6B292F709A06}';

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
            $ann = 0;
            foreach (IPS_GetChildrenIDs($plan['entry']['category']) as $cid) {
                if (IPS_InstanceExists($cid) && IPS_GetInstance($cid)['ModuleInfo']['ModuleID'] === ANN_GUID) {
                    $ann = $cid;
                }
            }
            if ($ann === 0) {
                $ann = IPS_CreateInstance(ANN_GUID);
                IPS_SetParent($ann, $plan['entry']['category']);
            }
            IPS_SetName($ann, 'Ansage ' . $plan['entry']['name']);
            if (IPS_GetInstance($ann)['ConnectionID'] !== $hub) {
                @IPS_DisconnectInstance($ann);
                IPS_ConnectInstance($ann, $hub);
            }
            IPS_SetProperty($ann, 'TriggerVariable', $plan['trigger']);
            IPS_SetProperty($ann, 'TriggerRule', $plan['rule']);
            IPS_SetProperty($ann, 'TriggerValue', $plan['value']);
            IPS_SetProperty($ann, 'Repeat', $plan['repeat']);
            IPS_SetProperty($ann, 'Texts', $plan['text']);
            IPS_SetProperty($ann, 'Condition', $toCondition($own));
            IPS_ApplyChanges($ann);
            RequestAction(IPS_GetObjectIDByIdent('ACTIVE', $ann), $plan['active']);
            $row['instance'] = $ann;
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
IPS_LogMessage('SPA-Migration', json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
return json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

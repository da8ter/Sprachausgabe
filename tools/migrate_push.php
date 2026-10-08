<?php

declare(strict_types=1);

/**
 * Übernahme alter Push-Benachrichtigungen in die Push Zentrale (eine Instanz, Nachrichten als Liste).
 *
 * Erwarteter Altbestand (Kategorie mit Unterkategorien): je Unterkategorie ein oder mehrere Skripte
 * mit WFC_PushNotification/VISU_PostNotificationEx-Aufrufen, je Empfänger eine Bool-Variable
 * (Name = Empfänger, z. B. „iPhone Anna“), die den jeweiligen Aufruf freischaltet
 * (if (GetValueBoolean(<Schalter>) == true) { …Aufruf… }), ein Auslöser-Ereignis je Skript und in
 * der Kategorie selbst eine Variable „Hauptschalter“.
 *
 * Als Skript-Inhalt in Symcon ausführen. Voreinstellung ist ein PROBELAUF: er liest nur und schreibt
 * den Bericht ins Log (Absender "PUSH-Migration") und als Ausgabe. Erst mit DRY_RUN = false wird
 * angelegt:
 *  - die Zentrale in der Kategorie; Empfänger aus den aktiven Aufrufen der Skripte (Visu-Instanz je
 *    Schaltername), Hauptschalter = Wert der alten Variable;
 *  - je Altskript und Auslöser-Ereignis eine Zeile der Nachrichtenliste: Auslöser aus dem Ereignis
 *    (Bedingungs-Dialog), Titel/Icon/Ton/Ziel aus dem Kachel-Aufruf, der längere Text aus allen
 *    Aufrufen; Schalter je Nachricht und Empfänger = alter Schalter UND aktiver (nicht
 *    auskommentierter) Aufruf;
 *  - Texte aus Variablen werden zu Platzhaltern; lässt sich ein Text nicht auflösen, entsteht ein
 *    Textskript aus dem Code vor dem Hauptschalter-Block;
 *  - ein Skript ohne Ereignis, das ein anderes Skript per IPS_SetScriptTimer verzögert startet,
 *    wird zur Nachricht mit Verzögerung und Wiederholung; die Timer-Zeilen im anderen Skript werden
 *    auskommentiert (Sicherung als Datei im Kernel-Ordner);
 *  - die alten Auslöser-Ereignisse und Skript-Timer werden DEAKTIVIERT, nicht gelöscht.
 */

// ID der Kategorie mit den alten Push-Benachrichtigungen — vor dem Lauf eintragen.
const CATEGORY = 0;
const DRY_RUN = true;
const HUB_GUID = '{5C17B714-C9BD-4E8E-B429-E52B6EA84FF5}';

if (CATEGORY <= 0 || !IPS_CategoryExists(CATEGORY)) {
    IPS_LogMessage('PUSH-Migration', 'Bitte oben CATEGORY auf die Kategorie der alten Push-Benachrichtigungen setzen.');
    return;
}

$report = ['dry' => DRY_RUN, 'notes' => [], 'recipients' => [], 'messages' => []];
$note = static function (string $text) use (&$report): void { $report['notes'][] = $text; };
$rule = static fn(int $var, int $cmp, mixed $value): string => (string)json_encode([['id' => 0, 'parentID' => 0, 'operation' => 0, 'rules' => [
    'variable' => [['id' => 0, 'variableID' => $var, 'comparison' => $cmp, 'value' => $value, 'type' => 0]], 'date' => [], 'time' => [], 'dayOfTheWeek' => []]]]);
$typed = static function (int $var, mixed $raw): mixed {
    $type = @IPS_VariableExists($var) ? IPS_GetVariable($var)['VariableType'] : VARIABLETYPE_STRING;
    return match ($type) {
        VARIABLETYPE_BOOLEAN => !in_array((string)$raw, ['', '0', 'false'], true),
        VARIABLETYPE_INTEGER => (int)$raw,
        VARIABLETYPE_FLOAT   => (float)$raw,
        default              => (string)$raw,
    };
};
/** Aktive (nicht auskommentierte) Push-Aufrufe je Schalter-Variable eines Skripts. */
$calls = static function (string $code): array {
    $out = [];
    $lines = preg_split('/\R/', $code) ?: [];
    $switch = 0;
    foreach ($lines as $line) {
        $t = trim($line);
        if (preg_match('/getvalueboolean\(\s*(\d+)\s*\)\s*==\s*true/i', $t, $m) === 1 && !str_contains($t, '&&')) {
            $switch = (int)$m[1];
        }
        if ($t === '' || str_starts_with($t, '/' . '/') || str_starts_with($t, '#')) {
            continue;
        }
        if (preg_match('/(VISU_PostNotificationEx|WFC_PushNotification)\s*\((.*)\)\s*;/i', $t, $m) === 1) {
            $out[] = ['switch' => $switch, 'fn' => strtoupper($m[1]), 'args' => $m[2]];
        }
    }
    return $out;
};
/** Argumente eines Aufrufs; Zeichenketten-Literale als ['lit', text], alles andere als ['expr', code]. */
$args = static function (string $s): array {
    $out = [];
    preg_match_all('/\s*(\'[^\']*\'|"[^"]*"|[^,]+)\s*(?:,|$)/', $s, $m); // literals without escaped quotes
    foreach ($m[1] as $a) {
        $a = trim($a);
        if (preg_match('/^(?:\'([^\']*)\'|"([^"]*)")$/s', $a, $q) === 1) {
            $out[] = ['lit', ($q[1] ?? '') !== '' ? $q[1] : ($q[2] ?? '')];
        } else {
            $out[] = ['expr', $a];
        }
    }
    return $out;
};

// ------------------------------------------------------------------ Altbestand lesen
$master = 0;
$categories = [];
foreach (IPS_GetChildrenIDs(CATEGORY) as $id) {
    $o = IPS_GetObject($id);
    if ($o['ObjectType'] === OBJECTTYPE_VARIABLE && $o['ObjectName'] === 'Hauptschalter') {
        $master = $id;
    } elseif ($o['ObjectType'] === OBJECTTYPE_CATEGORY) {
        $categories[] = $id;
    }
}
$recipients = [];   // name => ['type', 'instance']
$plans = [];
foreach ($categories as $cat) {
    $switches = [];  // variable id => name
    $scripts = [];
    foreach (IPS_GetChildrenIDs($cat) as $id) {
        $o = IPS_GetObject($id);
        if ($o['ObjectType'] === OBJECTTYPE_VARIABLE && IPS_GetVariable($id)['VariableType'] === VARIABLETYPE_BOOLEAN) {
            $switches[$id] = $o['ObjectName'];
        } elseif ($o['ObjectType'] === OBJECTTYPE_SCRIPT) {
            $scripts[] = $id;
        }
    }
    foreach ($scripts as $sid) {
        $code = IPS_GetScriptContent($sid);
        $plan = ['category' => $cat, 'catName' => IPS_GetName($cat), 'script' => $sid, 'name' => IPS_GetName($sid), 'events' => [],
            'condition' => '', 'mode' => 0, 'delay' => 0, 'repeat' => 0, 'title' => '', 'text' => '', 'icon' => '', 'sound' => '', 'target' => 0,
            'textScript' => '', 'switches' => [], 'active' => true, 'timerSource' => 0, 'extraCondition' => ''];
        // variables read into PHP variables, for texts like 'Anruf von: ' . $anrufer
        $vars = [];
        if (preg_match_all('/\$(\w+)\s*=\s*getvalue\w*\(\s*(\d+)\s*\)/i', $code, $vm, PREG_SET_ORDER)) {
            foreach ($vm as $v) {
                $vars[$v[1]] = (int)$v[2];
            }
        }
        $literals = [];
        if (preg_match_all('/\$(\w+)\s*=\s*(?:\'([^\']*)\'|"([^"]*)")\s*;/', $code, $lm, PREG_SET_ORDER)) {
            foreach ($lm as $l) {
                $literals[$l[1]][] = ($l[2] ?? '') !== '' ? $l[2] : ($l[3] ?? '');
            }
        }
        // ---- Auslöser: one message per trigger event of the script
        $events = [];
        foreach (IPS_GetChildrenIDs($sid) as $eid) {
            if (IPS_EventExists($eid) && IPS_GetEvent($eid)['EventType'] === EVENTTYPE_TRIGGER) {
                $events[] = $eid;
            }
        }
        $base = $plan;
        foreach ($events === [] ? [0] : $events as $n => $event) {
        $plan = $base;
        if (count($events) > 1) {
            $plan['name'] .= ' ' . ($n + 1);
        }
        $trigger = 0;
        if ($event > 0) {
            $e = IPS_GetEvent($event);
            $trigger = (int)$e['TriggerVariableID'];
            $plan['events'][] = $event;
            $plan['active'] = (bool)$e['EventActive'];
            if (!@IPS_VariableExists($trigger)) {
                $note(sprintf('%s/%s: Auslöser-Variable #%d gibt es nicht mehr — Nachricht ohne Auslöser (Status 202)', $plan['catName'], $plan['name'], $trigger));
                $trigger = 0;
            } else {
                $repeat = (bool)$e['TriggerSubsequentExecution'];
                $value = $typed($trigger, $e['TriggerValue']);
                [$cmp, $mode] = match ((int)$e['TriggerType']) {
                    0 => [0, 2],                  // update   → bei jeder Aktualisierung
                    1 => [0, 3],                  // change   → bei jeder Änderung
                    2 => [2, $repeat ? 1 : 0],    // limit exceeded → >
                    3 => [4, $repeat ? 1 : 0],    // limit dropped  → <
                    default => [0, $repeat ? 1 : 0],
                };
                if ((int)$e['TriggerType'] <= 1) {
                    $value = GetValue($trigger);
                }
                $plan['condition'] = $rule($trigger, $cmp, $value);
                $plan['mode'] = $mode;
                if ($repeat && (int)$e['TriggerType'] >= 2) {
                    $note(sprintf('%s/%s: Ereignis mit „nachfolgende Ausführung“ — übernommen als „solange die Regel erfüllt ist“ (jede Aktualisierung sendet)', $plan['catName'], $plan['name']));
                }
            }
        } else {
            // started delayed by another script via IPS_SetScriptTimer(<this>, N)?
            foreach (IPS_GetScriptList() as $other) {
                $oc = @IPS_GetScriptContent($other);
                if (!is_string($oc) || !preg_match_all('/IPS_SetScriptTimer\(\s*' . $sid . '\s*,\s*(\d+)\s*\)/', $oc, $all, PREG_SET_ORDER)) {
                    continue;
                }
                $tm = null;
                foreach ($all as $candidate) {
                    if ((int)$candidate[1] > 0) {
                        $tm = $candidate;
                    }
                }
                if ($tm === null) {
                    continue;
                }
                $before = substr($oc, 0, (int)strpos($oc, $tm[0]));
                $caseValue = preg_match_all('/case\s+([^:]+):/', $before, $cm) ? trim((string)end($cm[1])) : null;
                foreach (IPS_GetChildrenIDs($other) as $eid) {
                    if (IPS_EventExists($eid) && IPS_GetEvent($eid)['EventType'] === EVENTTYPE_TRIGGER) {
                        $trigger = (int)IPS_GetEvent($eid)['TriggerVariableID'];
                    }
                }
                if ($trigger > 0 && $caseValue !== null) {
                    $plan['condition'] = $rule($trigger, 0, $typed($trigger, trim($caseValue, '\'"')));
                    $plan['mode'] = 0;
                    $plan['delay'] = (int)$tm[1];
                    $plan['repeat'] = (int)round((int)$tm[1] / 60);
                    $plan['timerSource'] = $other;
                    $note(sprintf('%s/%s: kein Ereignis, wird von „%s“ (#%d) per Skript-Timer nach %d s gestartet — übernommen als Verzögerung + Wiederholung; die Timer-Zeilen dort werden auskommentiert',
                        $plan['catName'], $plan['name'], IPS_GetName($other), $other, (int)$tm[1]));
                }
            }
            if ($plan['condition'] === '') {
                $note(sprintf('%s/%s: kein Auslöser gefunden — Nachricht ohne Auslöser', $plan['catName'], $plan['name']));
            }
        }
        // extra conditions next to the main switch, e.g. && getvaluefloat(123) < 12
        if (preg_match('/getvalueboolean\(\s*' . $master . '\s*\)\s*==\s*true\s*&&\s*getvalue\w*\(\s*(\d+)\s*\)\s*(<=|>=|==|!=|<|>)\s*([\d.]+)/i', $code, $xm) === 1) {
            $cmp = ['==' => 0, '!=' => 1, '>' => 2, '>=' => 3, '<' => 4, '<=' => 5][$xm[2]];
            $plan['extraCondition'] = $rule((int)$xm[1], $cmp, (float)$xm[3]);
        }
        // ---- Aufrufe: Empfänger, Titel, Text, Icon, Ton, Ziel
        $texts = [];
        foreach ($calls($code) as $call) {
            $a = $args($call['args']);
            $instance = (int)($a[0][1] ?? 0);
            $name = $switches[$call['switch']] ?? '';
            if ($name !== '') {
                $plan['switches'][$name] = true;
                $type = $call['fn'] === 'VISU_POSTNOTIFICATIONEX' ? 'visu' : 'wfc';
                if (!isset($recipients[$name])) {
                    $recipients[$name] = ['type' => $type, 'instance' => $instance];
                } elseif ($recipients[$name]['instance'] !== $instance) {
                    $note(sprintf('%s/%s: „%s“ sendet hier an #%d statt an #%d — übernommen wird #%d', $plan['catName'], $plan['name'], $name, $instance, $recipients[$name]['instance'], $recipients[$name]['instance']));
                }
            }
            [$tKind, $title] = $a[1] ?? ['lit', ''];
            [$xKind, $text] = $a[2] ?? ['lit', ''];
            if ($xKind === 'expr') {
                // 'Anruf von: ' . $anrufer . ' '  →  Anruf von: {var:ID}   |   $push → Textskript
                $resolved = '';
                $ok = true;
                foreach (preg_split('/\s*\.\s*/', $text) ?: [] as $part) {
                    if (preg_match('/^(?:\'([^\']*)\'|"([^"]*)")$/s', $part, $q) === 1) {
                        $resolved .= ($q[1] ?? '') !== '' ? $q[1] : ($q[2] ?? '');
                    } elseif (preg_match('/^\$(\w+)$/', $part, $pv) === 1 && isset($vars[$pv[1]])) {
                        $resolved .= $vars[$pv[1]] === $trigger ? '{value}' : '{var:' . $vars[$pv[1]] . '}';
                    } elseif (preg_match('/^\$(\w+)$/', $part, $pv) === 1 && count($literals[$pv[1]] ?? []) === 1) {
                        $resolved .= $literals[$pv[1]][0];
                    } else {
                        $ok = false;
                    }
                }
                if ($ok) {
                    $text = trim($resolved);
                } else {
                    $text = '';
                    $cut = stripos($code, 'if(getvalueboolean(' . $master);
                    $cut = $cut === false ? stripos($code, 'if (getvalueboolean(' . $master) : $cut;
                    $head = $cut === false ? $code : substr($code, 0, $cut);
                    $head = preg_replace('/^\s*<\?php/', '', (string)$head);
                    // missing sensors would warn on every run
                    $head = preg_replace('/(?<![@\w])(GetValue\w*\()/i', '@$1', (string)$head);
                    $plan['textScript'] = '<' . "?php\n/" . "/ Text für die Push-Nachricht „" . $plan['name'] . "“, übernommen aus Skript #" . $sid . "\n" . trim((string)$head) . "\necho " . trim($call['args'] === '' ? "''" : explode(',', $call['args'])[2]) . ";\n";
                }
            }
            if ($call['fn'] === 'VISU_POSTNOTIFICATIONEX') {
                $plan['title'] = $tKind === 'lit' ? $title : $plan['title'];
                $plan['icon'] = (string)($a[3][1] ?? '');
                $plan['sound'] = (string)($a[4][1] ?? '');
                $plan['target'] = (int)($a[5][1] ?? 0);
            } elseif ($plan['title'] === '' && $tKind === 'lit') {
                $plan['title'] = $title;
            }
            if ($text !== '') {
                $texts[] = $text;
            }
        }
        usort($texts, static fn(string $x, string $y): int => mb_strlen($y) <=> mb_strlen($x));
        $plan['text'] = $texts[0] ?? '';
        if ($plan['text'] === '' && $plan['textScript'] === '') {
            $note(sprintf('%s/%s: kein Text gefunden', $plan['catName'], $plan['name']));
        }
        $plan['switchVars'] = $switches; // evaluated once all recipients are known
        $timer = @IPS_GetScriptTimer($sid);
        if ((int)$timer > 0) {
            $plan['scriptTimer'] = (int)$timer;
        }
        $plans[] = $plan;
        }
    }
}
// switch value per recipient = old switch of that name AND an active call for it; other boolean
// variables of the category (sensors etc.) are not recipients; recipients without a switch here get OFF
foreach ($plans as $i => $plan) {
    $values = array_fill_keys(array_keys($recipients), false);
    foreach ($plan['switchVars'] as $vid => $name) {
        if (!isset($recipients[$name])) {
            continue;
        }
        $values[$name] = GetValueBoolean($vid) && isset($plan['switches'][$name]);
        if (GetValueBoolean($vid) && !isset($plan['switches'][$name])) {
            $note(sprintf('%s/%s: „%s“ ist eingeschaltet, der Aufruf aber auskommentiert — Schalter wird AUS übernommen', $plan['catName'], $plan['name'], $name));
        }
    }
    $plans[$i]['switchValues'] = $values;
}
foreach ($recipients as $name => $r) {
    $report['recipients'][] = sprintf('%s → %s #%d %s', $name, $r['type'], $r['instance'], @IPS_InstanceExists($r['instance']) ? IPS_GetName($r['instance']) : '(fehlt!)');
}

// ------------------------------------------------------------------ anlegen
$switchIdent = static fn(string $msgId, string $name): string => 'R_' . $msgId . '_' . substr(md5(mb_strtolower(trim($name))), 0, 6);
$rows = [];
foreach ($plans as $i => $plan) {
    $plans[$i]['msgId'] = substr(md5($plan['script'] . '|' . $plan['name'] . '|' . $i), 0, 8);
    $rows[] = [
        'active' => $plan['active'], 'name' => $plan['catName'] === $plan['name'] ? $plan['name'] : $plan['catName'] . ': ' . $plan['name'],
        'msgId' => $plans[$i]['msgId'], 'TriggerCondition' => $plan['condition'], 'TriggerMode' => $plan['mode'],
        'Title' => $plan['title'], 'Texts' => $plan['text'], 'TextScript' => 0, 'Icon' => $plan['icon'], 'Sound' => $plan['sound'],
        'TargetObject' => $plan['target'], 'Condition' => $plan['extraCondition'], 'DelaySeconds' => $plan['delay'], 'RepeatMinutes' => $plan['repeat'],
    ];
}
$hub = 0;
if (!DRY_RUN) {
    foreach ($plans as $i => $plan) {
        if ($plan['textScript'] !== '') {
            $ts = IPS_CreateScript(0);
            IPS_SetParent($ts, $plan['category']);
            IPS_SetName($ts, $plan['name'] . ' (Text)');
            IPS_SetScriptContent($ts, $plan['textScript']);
            $rows[$i]['TextScript'] = $ts;
        }
    }
    $hub = IPS_CreateInstance(HUB_GUID);
    IPS_SetParent($hub, CATEGORY);
    IPS_SetName($hub, 'Push Zentrale');
    $recipientRows = [];
    foreach ($recipients as $name => $r) {
        $recipientRows[] = ['name' => $name, 'type' => $r['type'], 'instance' => $r['instance']];
    }
    IPS_SetProperty($hub, 'Recipients', json_encode($recipientRows, JSON_UNESCAPED_UNICODE));
    IPS_SetProperty($hub, 'Messages', json_encode($rows, JSON_UNESCAPED_UNICODE));
    IPS_ApplyChanges($hub);
    if ($master > 0) {
        RequestAction(IPS_GetObjectIDByIdent('MASTER', $hub), GetValueBoolean($master));
    }
    foreach ($plans as $plan) {
        foreach ($plan['switchValues'] as $name => $on) {
            $vid = @IPS_GetObjectIDByIdent($switchIdent($plan['msgId'], $name), $hub);
            if (is_int($vid)) {
                RequestAction($vid, $on);
            }
        }
        foreach ($plan['events'] as $eid) {
            IPS_SetEventActive($eid, false);
        }
        if (($plan['scriptTimer'] ?? 0) > 0 || $plan['timerSource'] > 0) {
            IPS_SetScriptTimer($plan['script'], 0);
        }
        if ($plan['timerSource'] > 0) {
            $src = $plan['timerSource'];
            $old = IPS_GetScriptContent($src);
            file_put_contents(IPS_GetKernelDir() . 'sicherung-skript-' . $src . '-vor-push-migration.php', $old);
            $marker = '/' . '/ Push-Migration: übernimmt die Push Zentrale #' . $hub . ' — ';
            $new = preg_replace('/^(\s*)(IPS_SetScriptTimer\(\s*' . $plan['script'] . '\s*,)/m', '$1' . $marker . '$2', $old);
            IPS_SetScriptContent($src, (string)$new);
        }
    }
}
foreach ($plans as $i => $plan) {
    $report['messages'][] = [
        'name' => $rows[$i]['name'],
        'trigger' => $plan['condition'] !== '' ? json_decode($plan['condition'], true)[0]['rules']['variable'][0] : null,
        'mode' => $plan['mode'], 'delay' => $plan['delay'], 'repeatMin' => $plan['repeat'],
        'title' => $plan['title'], 'text' => $plan['text'] !== '' ? $plan['text'] : ($plan['textScript'] !== '' ? '(Textskript)' : ''),
        'icon' => $plan['icon'], 'sound' => $plan['sound'], 'target' => $plan['target'],
        'switches' => $plan['switchValues'], 'active' => $plan['active'], 'condition' => $plan['extraCondition'] !== '' ? 'ja' : '',
    ];
}
$report['hub'] = $hub;
$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
IPS_LogMessage('PUSH-Migration', (string)$json);
echo $json;

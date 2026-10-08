<?php

declare(strict_types=1);

require_once __DIR__ . '/SpeechText.php';
require_once __DIR__ . '/SpeechTrigger.php';

/**
 * Ansagen als Liste in der Sprachausgabe Zentrale (Nutzerentscheid 08.10.2026, ersetzt die
 * Instanz je Ansage). Jede Zeile: Name, Aktiv, Auslöser aus dem Bedingungs-Dialog, optional
 * täglich zu einer Uhrzeit, Textvarianten, Bedingung, Ausgabegeräte (ein Häkchen je Gerät der
 * Zentrale, keins = Standardgeräte), Lautstärke, Dringend. Gesprochen wird über enqueue() der
 * Zentrale, die Hauptschalter, Ruhemodus, Bedingung, Sperrfrist und Warteschlange kennt.
 *
 * Erwartet von der Klasse: enqueue(), outputs(), conditionPassing(), Translate().
 */
trait SpeechAnnouncements
{
    private const ANN_GUID = '{94CE47EF-0417-49FB-9DE9-6B292F709A06}';
    private const ANN_EVENT_PREFIX = 'ANNTIME_';

    private function annRegister(): void
    {
        $this->RegisterPropertyString('Announcements', '[]');
        // Symcon rejects IPS_ApplyChanges of the own instance inside ApplyChanges (re-entrant): apply again via timer
        $this->RegisterTimer('Reapply', 0, 'IPS_ApplyChanges($_IPS[\'TARGET\']);');
    }

    /** In ApplyChanges: Kennungen, Meldungen, Referenzen, Zeitauslöser. true = zweiter Durchlauf folgt per Timer. */
    private function annApply(): bool
    {
        @$this->SetTimerInterval('Reapply', 0);
        if ($this->annAssignIds()) {
            $this->SetTimerInterval('Reapply', 100);
            return true;
        }
        foreach ($this->GetMessageList() as $sender => $messages) {
            foreach ($messages as $message) {
                if ($message === VM_UPDATE) {
                    $this->UnregisterMessage((int)$sender, VM_UPDATE);
                }
            }
        }
        foreach ($this->GetReferenceList() as $ref) {
            $this->UnregisterReference($ref);
        }
        $wantedEvents = [];
        foreach ($this->annRows() as $row) {
            $var = SpeechTrigger::rule((string)($row['TriggerCondition'] ?? ''))['variableID'] ?? 0;
            if ($var > 0 && @IPS_VariableExists($var)) {
                $this->RegisterMessage($var, VM_UPDATE);
                $this->RegisterReference($var);
            }
            if ((bool)($row['TimeEnabled'] ?? false)) {
                $wantedEvents[self::ANN_EVENT_PREFIX . $row['annId']] = $row;
            }
        }
        foreach ($this->outputs() as $o) {
            foreach (['instance', 'script', 'volumeVar'] as $k) {
                if ((int)($o[$k] ?? 0) > 0 && @IPS_ObjectExists((int)$o[$k])) {
                    $this->RegisterReference((int)$o[$k]);
                }
            }
        }
        $this->annMaintainTimeEvents($wantedEvents);
        return false;
    }

    /** In MessageSink: VM_UPDATE einer Auslöser-Variable. */
    private function annMessage(int $SenderID, array $Data): void
    {
        foreach ($this->annRows() as $row) {
            $rule = SpeechTrigger::rule((string)($row['TriggerCondition'] ?? ''));
            if ($rule === null || $rule['variableID'] !== $SenderID || !($row['active'] ?? true)) {
                continue;
            }
            if (SpeechTrigger::firesRule((int)($row['TriggerMode'] ?? 0), $rule, $Data[0] ?? null, (bool)($Data[1] ?? false), $Data[2] ?? null)) {
                $this->annSpeak($row, $Data[2] ?? null, false);
            }
        }
    }

    /** Zeitauslöser und Skripte: Ansage nach Kennung oder Name. '' = eingereiht, sonst der Grund. */
    public function TriggerAnnouncement(string $key): string
    {
        foreach ($this->annRows() as $row) {
            if ($row['annId'] === $key || strcasecmp(trim((string)$row['name']), trim($key)) === 0) {
                return ($row['active'] ?? true) ? $this->annSpeak($row, null, false) : 'announcement is inactive';
            }
        }
        return 'unknown announcement';
    }

    /** Dialog einer Ansage: alle Varianten mit ersetzten Platzhaltern, auch ungespeichert. */
    public function PreviewAnnouncement(string $Texts, string $TriggerCondition): string
    {
        return SpeechText::preview($Texts, SpeechTrigger::rule($TriggerCondition)['variableID'] ?? 0, $this->Translate('No text entered'));
    }

    /** Dialog einer Ansage: einmal sprechen, dringend (ohne Schalter, Bedingungen und Sperrfrist). $Targets = JSON {Gerät: bool}. */
    public function TestAnnouncement(string $Texts, string $TriggerCondition, string $Targets, int $Volume): string
    {
        $template = SpeechText::pick($Texts);
        if ($template === '') {
            return $this->Translate('No text entered');
        }
        $text = SpeechText::render($template, SpeechTrigger::rule($TriggerCondition)['variableID'] ?? 0, null, time());
        $map = json_decode($Targets, true);
        $targets = array_keys(array_filter(is_array($map) ? $map : []));
        $reason = $this->enqueue($text, array_map('strval', $targets), $Volume, true, '');
        return $reason === '' ? $this->Translate('Sent') : $this->Translate('Not sent') . ': ' . $this->Translate($reason);
    }

    /**
     * Übernimmt die Ansage-Instanzen (Modul „Sprachausgabe Ansage“) dieser Zentrale in die Liste und
     * löscht sie samt ihren Variablen und Zeitereignissen. Liefert einen Bericht.
     */
    public function ImportAnnouncements(): string
    {
        $rows = json_decode($this->ReadPropertyString('Announcements'), true);
        $rows = is_array($rows) ? $rows : [];
        $outputs = array_column($this->outputs(), 'name');
        $report = [];
        foreach (IPS_GetInstanceListByModuleID(self::ANN_GUID) as $inst) {
            if (IPS_GetInstance($inst)['ConnectionID'] !== $this->InstanceID) {
                continue;
            }
            $p = static fn(string $name): mixed => @IPS_GetProperty($inst, $name);
            $condition = (string)$p('TriggerCondition');
            $mode = (int)$p('TriggerMode');
            if ($condition === '' && (int)$p('TriggerVariable') > 0) {
                $var = @IPS_GetVariable((int)$p('TriggerVariable'));
                $legacy = SpeechTrigger::legacyToCondition((int)$p('TriggerVariable'), (int)$p('TriggerRule'), (string)$p('TriggerValue'),
                    (bool)$p('Repeat'), is_array($var) ? (int)$var['VariableType'] : VARIABLETYPE_STRING);
                [$condition, $mode] = [$legacy['condition'], $legacy['mode']];
            }
            $name = IPS_GetName($inst);
            if (in_array($name, ['Sprachausgabe Ansage', 'Sprachausgabe - Ansage', 'Ansage'], true)) {
                $name = IPS_GetName(IPS_GetParent($inst));
            }
            $activeId = @IPS_GetObjectIDByIdent('ACTIVE', $inst);
            $row = [
                'annId' => '', 'active' => is_int($activeId) ? GetValueBoolean($activeId) : true, 'name' => $name,
                'TriggerCondition' => $condition, 'TriggerMode' => $mode,
                'TimeEnabled' => (bool)$p('TimeEnabled'), 'Time' => (string)$p('Time'), 'Texts' => (string)$p('Texts'),
                'Condition' => (string)$p('Condition'), 'Volume' => (int)$p('Volume'), 'Urgent' => (bool)$p('Urgent'),
            ];
            $targets = json_decode((string)$p('Targets'), true);
            foreach (is_array($targets) ? $targets : [] as $t) {
                if (is_array($t) && ($t['use'] ?? false) && in_array((string)($t['name'] ?? ''), $outputs, true)) {
                    $row[self::annTargetKey((string)$t['name'])] = true;
                }
            }
            $rows[] = $row;
            foreach (IPS_GetChildrenIDs($inst) as $child) {
                $o = IPS_GetObject($child);
                match ($o['ObjectType']) {
                    OBJECTTYPE_VARIABLE => IPS_DeleteVariable($child),
                    OBJECTTYPE_EVENT    => IPS_DeleteEvent($child),
                    default             => null,
                };
            }
            $deleted = @IPS_DeleteInstance($inst);
            $report[] = sprintf('%s (#%d)%s', $name, $inst, $deleted ? '' : ' — ' . $this->Translate('instance could not be deleted'));
        }
        if ($report === []) {
            return $this->Translate('No announcement instances found');
        }
        IPS_SetProperty($this->InstanceID, 'Announcements', (string)json_encode($rows, JSON_UNESCAPED_UNICODE));
        IPS_ApplyChanges($this->InstanceID);
        return sprintf($this->Translate('%d announcements imported'), count($report)) . ":\n" . implode("\n", $report);
    }

    /** Formular: Liste der Ansagen mit eigenem Dialog je Zeile. */
    private function annFormList(): array
    {
        $modes = [];
        foreach (SpeechTrigger::modeCaptions() as $value => $caption) {
            $modes[] = ['caption' => $caption, 'value' => $value];
        }
        $targetBoxes = [];
        $targetArgs = [];
        foreach ($this->outputs() as $o) {
            $key = self::annTargetKey((string)$o['name']);
            $targetBoxes[] = ['type' => 'CheckBox', 'name' => $key, 'caption' => (string)$o['name'], 'value' => false];
            $targetArgs[] = var_export((string)$o['name'], true) . ' => $' . $key;
        }
        $targetJson = 'json_encode([' . implode(', ', $targetArgs) . '])';
        return ['type' => 'List', 'name' => 'Announcements', 'caption' => 'Announcements', 'add' => true, 'delete' => true, 'rowCount' => 12, 'changeOrder' => true,
            'form' => [
                ['type' => 'ValidationTextBox', 'name' => 'name', 'caption' => 'Name', 'width' => '100%', 'validate' => '\S'],
                ['type' => 'ExpansionPanel', 'caption' => 'Trigger', 'expanded' => true, 'items' => [
                    ['type' => 'SelectCondition', 'name' => 'TriggerCondition', 'caption' => 'Rule', 'multi' => false],
                    ['type' => 'Select', 'name' => 'TriggerMode', 'caption' => 'Trigger', 'options' => $modes, 'width' => '100%', 'value' => SpeechTrigger::MODE_BECOMES],
                    ['type' => 'RowLayout', 'items' => [
                        ['type' => 'CheckBox', 'name' => 'TimeEnabled', 'caption' => 'additionally daily at', 'value' => false],
                        ['type' => 'SelectTime', 'name' => 'Time', 'caption' => 'Time', 'value' => '{"hour":7,"minute":0,"second":0}'],
                    ]],
                ]],
                ['type' => 'ExpansionPanel', 'caption' => 'Text', 'expanded' => true, 'items' => [
                    ['type' => 'ValidationTextBox', 'name' => 'Texts', 'caption' => 'Text (one variant per line)', 'multiline' => true, 'width' => '100%'],
                    ['type' => 'ValidationTextBox', 'caption' => 'Placeholders (select and copy)', 'value' => SpeechText::placeholderHelp(fn(string $t): string => $this->Translate($t)), 'multiline' => true, 'width' => '100%'],
                ]],
                ['type' => 'ExpansionPanel', 'caption' => 'Outputs (none ticked = default outputs)', 'items' => $targetBoxes ?: [['type' => 'Label', 'caption' => 'No outputs yet']]],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'NumberSpinner', 'name' => 'Volume', 'caption' => 'Volume (0 = output default)', 'minimum' => 0, 'maximum' => 100, 'suffix' => ' %', 'value' => 0],
                    ['type' => 'CheckBox', 'name' => 'Urgent', 'caption' => 'Urgent (ignores main switch, quiet mode and global condition)', 'value' => false],
                ]],
                ['type' => 'ExpansionPanel', 'caption' => 'Condition', 'items' => [
                    ['type' => 'SelectCondition', 'name' => 'Condition', 'multi' => true],
                ]],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Button', 'caption' => 'Preview text', 'onClick' => 'echo SPAZ_PreviewAnnouncement($id, $Texts, $TriggerCondition);'],
                    ['type' => 'Button', 'caption' => 'Test announcement', 'onClick' => 'echo SPAZ_TestAnnouncement($id, $Texts, $TriggerCondition, ' . $targetJson . ', $Volume);'],
                ]],
            ],
            'columns' => [
                ['caption' => 'Active', 'name' => 'active', 'width' => '70px', 'add' => true, 'edit' => ['type' => 'CheckBox']],
                ['caption' => 'Name', 'name' => 'name', 'width' => '220px', 'add' => '', 'save' => true],
                ['caption' => 'Text', 'name' => 'Texts', 'width' => 'auto', 'add' => '', 'save' => true],
                ['caption' => 'ID', 'name' => 'annId', 'width' => '0px', 'visible' => false, 'add' => '', 'save' => true],
            ]];
    }

    // ------------------------------------------------------------------ internals

    /** @return string '' wenn eingereiht, sonst der Grund */
    private function annSpeak(array $row, mixed $old, bool $test): string
    {
        if (!$test && !$this->conditionPassing((string)($row['Condition'] ?? ''))) {
            return 'condition not met';
        }
        $template = SpeechText::pick((string)($row['Texts'] ?? ''));
        if ($template === '') {
            return 'no text';
        }
        $trigger = SpeechTrigger::rule((string)($row['TriggerCondition'] ?? ''))['variableID'] ?? 0;
        $text = SpeechText::render($template, $trigger, $old, time());
        $targets = [];
        foreach ($this->outputs() as $o) {
            if ((bool)($row[self::annTargetKey((string)$o['name'])] ?? false)) {
                $targets[] = (string)$o['name'];
            }
        }
        $reason = $this->enqueue($text, $targets, (int)($row['Volume'] ?? 0), $test || (bool)($row['Urgent'] ?? false), $test ? '' : 'ann:' . $row['annId']);
        $this->SendDebug('Announcement', $row['name'] . ': ' . $text . ($reason !== '' ? ' — ' . $reason : ''), 0);
        return $reason;
    }

    /** @return array<int, array<string, mixed>> */
    private function annRows(): array
    {
        $list = json_decode($this->ReadPropertyString('Announcements'), true);
        return is_array($list) ? array_values(array_filter($list, static fn($r): bool => is_array($r) && trim((string)($r['name'] ?? '')) !== '' && (string)($r['annId'] ?? '') !== '')) : [];
    }

    private static function annTargetKey(string $output): string
    {
        return 'T_' . substr(md5(mb_strtolower(trim($output))), 0, 6);
    }

    /** Neue Zeilen bekommen eine feste Kennung (für Zeitereignisse und Sperrfrist). */
    private function annAssignIds(): bool
    {
        $rows = json_decode($this->ReadPropertyString('Announcements'), true);
        if (!is_array($rows)) {
            return false;
        }
        $changed = false;
        $seen = [];
        foreach ($rows as &$row) {
            $id = (string)($row['annId'] ?? '');
            if ($id === '' || isset($seen[$id])) {
                $row['annId'] = substr(md5(uniqid('', true) . count($seen)), 0, 8);
                $changed = true;
            }
            $seen[(string)$row['annId']] = true;
        }
        unset($row);
        if ($changed) {
            IPS_SetProperty($this->InstanceID, 'Announcements', (string)json_encode($rows, JSON_UNESCAPED_UNICODE));
        }
        return $changed;
    }

    /**
     * Tägliche Zeitauslöser als eigene zyklische Ereignisse unter der Zentrale (Modul-Timer verhungern
     * bei Reloads und zählen nach jedem SetTimerInterval neu).
     *
     * @param array<string, array<string, mixed>> $wanted ident => row
     */
    private function annMaintainTimeEvents(array $wanted): void
    {
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $child) {
            $ident = (string)IPS_GetObject($child)['ObjectIdent'];
            if (str_starts_with($ident, self::ANN_EVENT_PREFIX) && !isset($wanted[$ident]) && IPS_EventExists($child)) {
                IPS_DeleteEvent($child);
            }
        }
        foreach ($wanted as $ident => $row) {
            $eid = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
            if (!is_int($eid) || $eid <= 0) {
                $eid = IPS_CreateEvent(EVENTTYPE_CYCLIC);
                IPS_SetParent($eid, $this->InstanceID);
                IPS_SetIdent($eid, $ident);
                IPS_SetHidden($eid, true);
            }
            IPS_SetName($eid, $this->Translate('Time trigger') . ': ' . $row['name']);
            $time = json_decode((string)($row['Time'] ?? ''), true) ?: [];
            IPS_SetEventCyclic($eid, 2, 1, 0, 0, 0, 0); // daily
            IPS_SetEventCyclicTimeFrom($eid, (int)($time['hour'] ?? 7), (int)($time['minute'] ?? 0), (int)($time['second'] ?? 0));
            IPS_SetEventScript($eid, 'SPAZ_TriggerAnnouncement(' . $this->InstanceID . ', ' . var_export((string)$row['annId'], true) . ');');
            IPS_SetEventActive($eid, (bool)($row['active'] ?? true));
        }
    }
}

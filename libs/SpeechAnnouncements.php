<?php

declare(strict_types=1);

require_once __DIR__ . '/SpeechText.php';
require_once __DIR__ . '/SpeechTrigger.php';
require_once __DIR__ . '/SpeechSchedule.php';

/**
 * Ansagen als Liste in der Sprachausgabe Zentrale (Nutzerentscheid 08.10.2026, ersetzt die
 * Instanz je Ansage). Jede Zeile: Name, Aktiv, Auslöser aus dem Bedingungs-Dialog, optional
 * täglich zu einer Uhrzeit, Textvarianten, Bedingung, Ausgabegeräte (ein Häkchen je Gerät der
 * Zentrale, keins = Standardgeräte), Lautstärke, Dringend, Zeitplan. Je Ansage gibt es eine
 * Schaltvariable unter der Zentrale (Ident A_<annId>) für die Visualisierung. Gesprochen wird über
 * enqueue() der Zentrale, die Hauptschalter, Ruhemodus, Bedingung, Sperrfrist und Warteschlange kennt.
 *
 * Erwartet von der Klasse: enqueue(), outputs(), conditionPassing(), Translate().
 */
trait SpeechAnnouncements
{
    private const ANN_EVENT_PREFIX = 'ANNTIME_';
    private const ANN_SCHEDULE_MAIN = 'SCHEDULE_MAIN';
    private const ANN_SCHEDULE_PREFIX = 'ANNSCHED_';
    /** Zeitplan je Ansage: keiner, Sprechzeiten der Zentrale, eigener Wochenplan. */
    private const ANN_SCHEDULE_NONE = 0;
    private const ANN_SCHEDULE_SHARED = 1;
    private const ANN_SCHEDULE_OWN = 2;

    private function annRegister(): void
    {
        $this->RegisterPropertyString('Announcements', '[]');
        $this->RegisterAttributeString('AnnSwitchIdents', '[]');
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
        $wantedSchedules = [];
        foreach ($this->annRows() as $row) {
            if ((int)($row['Schedule'] ?? 0) === self::ANN_SCHEDULE_OWN) {
                $wantedSchedules[self::ANN_SCHEDULE_PREFIX . $row['annId']] = $row;
            }
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
        $this->annMaintainSchedules($wantedSchedules);
        $this->annMaintainSwitches();
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
                    ['type' => 'Select', 'name' => 'Schedule', 'caption' => 'Schedule', 'width' => '100%', 'value' => self::ANN_SCHEDULE_NONE, 'options' => [
                        ['caption' => 'none', 'value' => self::ANN_SCHEDULE_NONE],
                        ['caption' => 'shared speaking times of the hub', 'value' => self::ANN_SCHEDULE_SHARED],
                        ['caption' => 'own schedule (appears under the hub after applying)', 'value' => self::ANN_SCHEDULE_OWN],
                    ]],
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
                ['caption' => 'Schedule', 'name' => 'Schedule', 'width' => '170px', 'add' => self::ANN_SCHEDULE_NONE, 'save' => true, 'edit' => ['type' => 'Select', 'options' => [
                    ['caption' => '–', 'value' => self::ANN_SCHEDULE_NONE],
                    ['caption' => 'speaking times', 'value' => self::ANN_SCHEDULE_SHARED],
                    ['caption' => 'own', 'value' => self::ANN_SCHEDULE_OWN],
                ]]],
                ['caption' => 'Text', 'name' => 'Texts', 'width' => 'auto', 'add' => '', 'save' => true],
                ['caption' => 'ID', 'name' => 'annId', 'width' => '0px', 'visible' => false, 'add' => '', 'save' => true],
            ]];
    }

    // ------------------------------------------------------------------ internals

    /** @return string '' wenn eingereiht, sonst der Grund */
    private function annSpeak(array $row, mixed $old, bool $test): string
    {
        if (!$test && !$this->annSwitchOn($row)) {
            return 'announcement is switched off';
        }
        if (!$test && !$this->conditionPassing((string)($row['Condition'] ?? ''))) {
            return 'condition not met';
        }
        if (!$test && !(bool)($row['Urgent'] ?? false) && !$this->annScheduleAllows($row)) {
            $this->SendDebug('Announcement', $row['name'] . ': outside the schedule', 0);
            return 'outside the schedule';
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

    /** Je Ansage eine Schaltvariable (neu = an); umbenannte Ansagen benennen sie mit um. */
    private function annMaintainSwitches(): void
    {
        $old = json_decode($this->ReadAttributeString('AnnSwitchIdents'), true) ?: [];
        $now = [];
        $position = 100;
        foreach ($this->annRows() as $row) {
            $ident = 'A_' . $row['annId'];
            $caption = trim((string)$row['name']);
            $isNew = !is_int(@IPS_GetObjectIDByIdent($ident, $this->InstanceID));
            $this->MaintainVariable($ident, $caption, VARIABLETYPE_BOOLEAN, [
                'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
                'ICON_TRUE'    => 'Speaker',
                'ICON_FALSE'   => 'Speaker',
            ], $position++, true);
            $this->EnableAction($ident);
            if ($isNew) {
                $this->SetValue($ident, true);
            } elseif (IPS_GetName($this->GetIDForIdent($ident)) !== $caption) {
                IPS_SetName($this->GetIDForIdent($ident), $caption);
            }
            $now[] = $ident;
        }
        foreach (array_diff($old, $now) as $gone) {
            $this->MaintainVariable((string)$gone, '', VARIABLETYPE_BOOLEAN, [], 0, false);
        }
        $this->WriteAttributeString('AnnSwitchIdents', (string)json_encode(array_values($now)));
    }

    private function annSwitchOn(array $row): bool
    {
        $vid = @IPS_GetObjectIDByIdent('A_' . $row['annId'], $this->InstanceID);
        return !is_int($vid) || GetValueBoolean($vid);
    }

    /** Zeitplan der Ansage: gemeinsamer Plan der Zentrale oder eigener; dringende Ansagen kommen hier nicht an. */
    private function annScheduleAllows(array $row): bool
    {
        $ident = match ((int)($row['Schedule'] ?? 0)) {
            self::ANN_SCHEDULE_SHARED => self::ANN_SCHEDULE_MAIN,
            self::ANN_SCHEDULE_OWN    => self::ANN_SCHEDULE_PREFIX . $row['annId'],
            default                   => '',
        };
        if ($ident === '') {
            return true;
        }
        $eid = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if (!is_int($eid) || !IPS_EventExists($eid)) {
            return true; // plan missing: never silence an announcement because of it
        }
        $event = IPS_GetEvent($eid);
        return !($event['EventActive'] ?? false) || SpeechSchedule::allows((array)($event['ScheduleGroups'] ?? []), time());
    }

    /**
     * Wochenpläne als Ereignisse unter der Zentrale: „Sprechzeiten“ immer, je Ansage mit eigenem Plan
     * einer. Angelegt werden sie einmal mit 00:00 Ruhe / 08:00 Sprechen (eigene als Kopie der
     * Sprechzeiten); danach gehören die Schaltpunkte dem Nutzer und werden nie überschrieben.
     *
     * @param array<string, array<string, mixed>> $wanted ident => row
     */
    private function annMaintainSchedules(array $wanted): void
    {
        $main = $this->annEnsureSchedule(self::ANN_SCHEDULE_MAIN, $this->Translate('Speaking times'), null);
        $mainGroups = (array)(IPS_GetEvent($main)['ScheduleGroups'] ?? []);
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $child) {
            $ident = (string)IPS_GetObject($child)['ObjectIdent'];
            if (str_starts_with($ident, self::ANN_SCHEDULE_PREFIX) && !isset($wanted[$ident]) && IPS_EventExists($child)) {
                IPS_DeleteEvent($child);
            }
        }
        foreach ($wanted as $ident => $row) {
            $eid = $this->annEnsureSchedule($ident, $this->Translate('Schedule') . ': ' . $row['name'], $mainGroups);
            IPS_SetName($eid, $this->Translate('Schedule') . ': ' . $row['name']);
        }
    }

    /** @param array<int, array<string, mixed>>|null $copyGroups Schaltpunkte für einen neuen Plan, null = Vorgabe */
    private function annEnsureSchedule(string $ident, string $name, ?array $copyGroups): int
    {
        $eid = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if (is_int($eid) && $eid > 0 && IPS_EventExists($eid)) {
            return $eid;
        }
        $eid = IPS_CreateEvent(EVENTTYPE_SCHEDULE);
        IPS_SetParent($eid, $this->InstanceID);
        IPS_SetIdent($eid, $ident);
        IPS_SetName($eid, $name);
        IPS_SetEventScheduleAction($eid, SpeechSchedule::SPEAK, $this->Translate('Speak'), 0x00A000, '');
        IPS_SetEventScheduleAction($eid, SpeechSchedule::QUIET, $this->Translate('Quiet'), 0x808080, '');
        $groups = $copyGroups ?: [['ID' => 0, 'Days' => 127, 'Points' => [
            ['ID' => 0, 'Start' => ['Hour' => 0, 'Minute' => 0, 'Second' => 0], 'ActionID' => SpeechSchedule::QUIET],
            ['ID' => 1, 'Start' => ['Hour' => 8, 'Minute' => 0, 'Second' => 0], 'ActionID' => SpeechSchedule::SPEAK],
        ]]];
        foreach ($groups as $g) {
            IPS_SetEventScheduleGroup($eid, (int)$g['ID'], (int)$g['Days']);
            foreach ((array)($g['Points'] ?? []) as $p) {
                IPS_SetEventScheduleGroupPoint($eid, (int)$g['ID'], (int)$p['ID'], (int)$p['Start']['Hour'], (int)$p['Start']['Minute'], (int)$p['Start']['Second'], (int)$p['ActionID']);
            }
        }
        IPS_SetEventActive($eid, true);
        return $eid;
    }

    /** Formular: Knöpfe zum Öffnen der Wochenpläne. */
    private function annScheduleButtons(): array
    {
        $items = [];
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $child) {
            $ident = (string)IPS_GetObject($child)['ObjectIdent'];
            if (IPS_EventExists($child) && ($ident === self::ANN_SCHEDULE_MAIN || str_starts_with($ident, self::ANN_SCHEDULE_PREFIX))) {
                $items[] = ['type' => 'OpenObjectButton', 'caption' => IPS_GetName($child), 'objectID' => $child];
            }
        }
        return ['type' => 'ExpansionPanel', 'caption' => 'Schedules', 'items' => [
            ['type' => 'Label', 'caption' => 'Each announcement uses no schedule, the shared speaking times or its own schedule (choose in its dialog). Outside "Speak" it stays silent; urgent announcements always speak.'],
            ['type' => 'RowLayout', 'items' => $items ?: [['type' => 'Label', 'caption' => 'Created on the next apply']]],
        ]];
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

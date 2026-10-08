<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/SpeechText.php';
require_once __DIR__ . '/../libs/SpeechTrigger.php';

/**
 * Sprachausgabe Ansage: eine Ansage mit Auslöser (Variable und/oder Uhrzeit), Bedingung,
 * Text und Zielen. Gesprochen wird über die Zentrale (Elterninstanz), die Hauptschalter,
 * Ruhemodus, Lautstärke und Warteschlange verwaltet.
 */
class SprachausgabeAnsage extends IPSModuleStrict
{
    private const ZENTRALE_GUID = '{8DF4B1D9-E589-452D-BE37-8EC5DEF4CF13}';
    private const DATA_TX = '{4942173C-5F03-4B89-835D-D3698A337C2D}';
    private const TIME_EVENT = 'TimeTrigger';

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyString('TriggerCondition', '');
        $this->RegisterPropertyInteger('TriggerMode', SpeechTrigger::MODE_BECOMES);
        // before 10/2026: variable, rule and value as text; only read to convert old instances
        $this->RegisterPropertyInteger('TriggerVariable', 0);
        $this->RegisterPropertyInteger('TriggerRule', SpeechTrigger::EQUALS);
        $this->RegisterPropertyString('TriggerValue', '');
        $this->RegisterPropertyBoolean('Repeat', false);
        $this->RegisterPropertyBoolean('TimeEnabled', false);
        $this->RegisterPropertyString('Time', '{"hour":7,"minute":0,"second":0}');
        $this->RegisterPropertyString('Texts', '');
        $this->RegisterPropertyString('Condition', '');
        $this->RegisterPropertyString('Targets', '[]');
        $this->RegisterPropertyInteger('Volume', 0);
        $this->RegisterPropertyBoolean('Urgent', false);

        $this->RegisterVariableBoolean('ACTIVE', $this->Translate('Active'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'ICON_TRUE'    => 'Speaker',
            'ICON_FALSE'   => 'Speaker',
        ], 10);
        $this->EnableAction('ACTIVE');
        $this->RegisterVariableInteger('LAST_RUN', $this->Translate('Last announcement at'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_DATE_TIME,
        ], 20);
        $this->RegisterAttributeBoolean('Initialized', false);

        $this->ConnectParent(self::ZENTRALE_GUID);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        if (!$this->ReadAttributeBoolean('Initialized')) {
            $this->SetValue('ACTIVE', true); // once; Create runs on every load and must not reset it
            $this->WriteAttributeBoolean('Initialized', true);
        }
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }
        if ($this->convertLegacyTrigger()) {
            return; // ApplyChanges ran again with the converted properties
        }

        foreach ($this->GetMessageList() as $sender => $messages) {
            foreach ($messages as $message) {
                if ($message === VM_UPDATE) {
                    $this->UnregisterMessage((int)$sender, VM_UPDATE);
                }
            }
        }
        $trigger = $this->triggerVariable();
        if ($trigger > 0 && @IPS_VariableExists($trigger)) {
            $this->RegisterMessage($trigger, VM_UPDATE);
            $this->RegisterReference($trigger);
        }
        $this->maintainTimeEvent();

        $hasTrigger = ($trigger > 0 && @IPS_VariableExists($trigger)) || $this->ReadPropertyBoolean('TimeEnabled');
        if (SpeechText::variants($this->ReadPropertyString('Texts')) === []) {
            $this->SetStatus(201); // no text
        } elseif (!$hasTrigger) {
            $this->SetStatus(202); // no trigger
        } else {
            $this->SetStatus(IS_ACTIVE);
        }
        $this->SetSummary($trigger > 0 ? (string)@IPS_GetName($trigger) : '');
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
            return;
        }
        $rule = SpeechTrigger::rule($this->ReadPropertyString('TriggerCondition'));
        if ($Message !== VM_UPDATE || $rule === null || $SenderID !== $rule['variableID']) {
            return;
        }
        $fires = SpeechTrigger::firesRule($this->ReadPropertyInteger('TriggerMode'), $rule, $Data[0] ?? null, (bool)($Data[1] ?? false), $Data[2] ?? null);
        if ($fires) {
            $this->announce($Data[2] ?? null, false);
        }
    }

    /** Die Zentrale sendet nichts an ihre Ansagen; die Rückrichtung ist nur für Symcons Datenfluss deklariert. */
    public function ReceiveData(string $JSONString): string
    {
        return '';
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($Ident === 'ACTIVE') {
            $this->SetValue('ACTIVE', (bool)$Value);
            return;
        }
        throw new Exception($this->Translate('Unknown action') . ': ' . $Ident);
    }

    /** Zeitauslöser und Skripte: Ansage prüfen (Aktiv, Bedingung) und sprechen. */
    public function Trigger(): string
    {
        return $this->announce(null, false);
    }

    /** Formular: alle Textvarianten mit ersetzten Platzhaltern zeigen (Werte aus dem Formular, auch ungespeichert). */
    public function Preview(string $Texts, string $TriggerCondition): void
    {
        echo SpeechText::preview($Texts, SpeechTrigger::rule($TriggerCondition)['variableID'] ?? 0, $this->Translate('No text entered'));
    }

    /** Formular: einmal sprechen, ohne Aktiv-Schalter und Bedingung. */
    public function Test(): void
    {
        $reason = $this->announce(null, true);
        echo $reason === '' ? $this->Translate('Sent') : $this->Translate('Not sent') . ': ' . $this->Translate($reason);
    }

    public function GetConfigurationForm(): string
    {
        $modes = [];
        foreach (SpeechTrigger::modeCaptions() as $value => $caption) {
            $modes[] = ['caption' => $caption, 'value' => $value];
        }
        return (string)json_encode([
            'elements' => [
                ['type' => 'ExpansionPanel', 'caption' => 'Trigger', 'expanded' => true, 'items' => [
                    ['type' => 'SelectCondition', 'name' => 'TriggerCondition', 'caption' => 'Rule', 'multi' => false],
                    ['type' => 'Select', 'name' => 'TriggerMode', 'caption' => 'Trigger', 'options' => $modes, 'width' => '100%'],
                    ['type' => 'RowLayout', 'items' => [
                        ['type' => 'CheckBox', 'name' => 'TimeEnabled', 'caption' => 'additionally daily at'],
                        ['type' => 'SelectTime', 'name' => 'Time', 'caption' => 'Time'],
                    ]],
                ]],
                ['type' => 'ValidationTextBox', 'name' => 'Texts', 'caption' => 'Text (one variant per line)', 'multiline' => true, 'width' => '100%'],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Label', 'caption' => 'Placeholders: {value} {old} {name} {var:12345} {time} {date}'],
                    ['type' => 'Button', 'caption' => 'Preview text', 'onClick' => 'SPAA_Preview($id, $Texts, $TriggerCondition);'],
                ]],
                ['type' => 'ExpansionPanel', 'caption' => 'Condition', 'items' => [
                    ['type' => 'SelectCondition', 'name' => 'Condition', 'multi' => true],
                ]],
                ['type' => 'List', 'name' => 'Targets', 'caption' => 'Outputs (none ticked = default outputs of the hub)', 'add' => false, 'delete' => false,
                    'columns' => [
                        ['caption' => 'Output', 'name' => 'name', 'width' => 'auto', 'save' => true],
                        ['caption' => 'Use', 'name' => 'use', 'width' => '80px', 'edit' => ['type' => 'CheckBox']],
                    ],
                    'values' => $this->targetRows()],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'NumberSpinner', 'name' => 'Volume', 'caption' => 'Volume (0 = output default)', 'minimum' => 0, 'maximum' => 100, 'suffix' => ' %'],
                    ['type' => 'CheckBox', 'name' => 'Urgent', 'caption' => 'Urgent (ignores main switch, quiet mode and global condition)'],
                ]],
            ],
            'actions' => [
                ['type' => 'Button', 'caption' => 'Test announcement', 'onClick' => 'SPAA_Test($id);'],
            ],
            'status' => [
                ['code' => 201, 'icon' => 'inactive', 'caption' => 'No text entered'],
                ['code' => 202, 'icon' => 'inactive', 'caption' => 'No trigger: select a variable or a time'],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // ------------------------------------------------------------------ internals

    /** @return string '' wenn an die Zentrale übergeben, sonst der Grund */
    private function announce(mixed $old, bool $test): string
    {
        if (!$test) {
            if (!$this->GetValue('ACTIVE')) {
                return 'announcement is inactive';
            }
            if (!$this->conditionPassing($this->ReadPropertyString('Condition'))) {
                return 'condition not met';
            }
        }
        $template = SpeechText::pick($this->ReadPropertyString('Texts'));
        if ($template === '') {
            return 'no text';
        }
        $text = SpeechText::render($template, $this->triggerVariable(), $old, time());
        if (!$this->HasActiveParent()) {
            $this->LogMessage($this->Translate('No hub connected') . ': ' . $text, KL_WARNING);
            return 'no hub connected';
        }
        $reason = (string)$this->SendDataToParent((string)json_encode([
            'DataID'  => self::DATA_TX,
            'Action'  => 'Speak',
            'Text'    => $text,
            'Targets' => $this->selectedTargets(),
            'Volume'  => $this->ReadPropertyInteger('Volume'),
            'Urgent'  => $test || $this->ReadPropertyBoolean('Urgent'),
            'Key'     => $test ? '' : 'SPAA' . $this->InstanceID,
        ], JSON_UNESCAPED_UNICODE));
        $this->SendDebug('Announce', $text . ($reason !== '' ? ' — ' . $reason : ''), 0);
        if ($reason === '') {
            $this->SetValue('LAST_RUN', time());
        }
        return $reason;
    }

    /** @return array<int, string> */
    private function selectedTargets(): array
    {
        $rows = json_decode($this->ReadPropertyString('Targets'), true);
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && ($row['use'] ?? false) && trim((string)($row['name'] ?? '')) !== '') {
                $out[] = (string)$row['name'];
            }
        }
        return $out;
    }

    /** Alle Geräte der Zentrale in deren Reihenfolge, Häkchen aus der gespeicherten Auswahl. */
    private function targetRows(): array
    {
        $names = [];
        if ($this->HasActiveParent()) {
            $names = json_decode((string)$this->SendDataToParent((string)json_encode(['DataID' => self::DATA_TX, 'Action' => 'Outputs'])), true);
        }
        $selected = array_flip($this->selectedTargets());
        $rows = [];
        foreach (is_array($names) ? $names : [] as $name) {
            $rows[] = ['name' => (string)$name, 'use' => isset($selected[(string)$name])];
            unset($selected[(string)$name]);
        }
        foreach (array_keys($selected) as $gone) {
            $rows[] = ['name' => (string)$gone, 'use' => true, 'rowColor' => '#FFC0C0']; // no longer in the hub
        }
        return $rows;
    }

    /** Täglicher Zeitauslöser als eigenes Ereignis unter der Instanz (Timer verhungern bei Reloads). */
    private function maintainTimeEvent(): void
    {
        $eid = @IPS_GetObjectIDByIdent(self::TIME_EVENT, $this->InstanceID);
        if (!$this->ReadPropertyBoolean('TimeEnabled')) {
            if (is_int($eid) && $eid > 0) {
                IPS_DeleteEvent($eid);
            }
            return;
        }
        if (!is_int($eid) || $eid <= 0) {
            $eid = IPS_CreateEvent(EVENTTYPE_CYCLIC);
            IPS_SetParent($eid, $this->InstanceID);
            IPS_SetIdent($eid, self::TIME_EVENT);
            IPS_SetName($eid, $this->Translate('Time trigger'));
            IPS_SetHidden($eid, true);
        }
        $time = json_decode($this->ReadPropertyString('Time'), true) ?: [];
        IPS_SetEventCyclic($eid, 2, 1, 0, 0, 0, 0); // daily
        IPS_SetEventCyclicTimeFrom($eid, (int)($time['hour'] ?? 7), (int)($time['minute'] ?? 0), (int)($time['second'] ?? 0));
        IPS_SetEventScript($eid, 'SPAA_Trigger(' . $this->InstanceID . ');');
        IPS_SetEventActive($eid, true);
    }

    private function triggerVariable(): int
    {
        return SpeechTrigger::rule($this->ReadPropertyString('TriggerCondition'))['variableID'] ?? 0;
    }

    /** Alte Instanzen (Variable, Regel, Wert als Text) einmal auf die Regel des Bedingungs-Dialogs umstellen. */
    private function convertLegacyTrigger(): bool
    {
        $variable = $this->ReadPropertyInteger('TriggerVariable');
        if ($variable <= 0 || $this->ReadPropertyString('TriggerCondition') !== '') {
            return false;
        }
        $var = @IPS_GetVariable($variable);
        $new = SpeechTrigger::legacyToCondition($variable, $this->ReadPropertyInteger('TriggerRule'), $this->ReadPropertyString('TriggerValue'),
            $this->ReadPropertyBoolean('Repeat'), is_array($var) ? (int)$var['VariableType'] : VARIABLETYPE_STRING);
        IPS_SetProperty($this->InstanceID, 'TriggerCondition', $new['condition']);
        IPS_SetProperty($this->InstanceID, 'TriggerMode', $new['mode']);
        IPS_SetProperty($this->InstanceID, 'TriggerVariable', 0); // guard: converts exactly once
        IPS_ApplyChanges($this->InstanceID);
        return true;
    }

    private function conditionPassing(string $condition): bool
    {
        $condition = trim($condition);
        if ($condition === '' || $condition === '[]') {
            return true;
        }
        try {
            return (bool)IPS_IsConditionPassing($condition);
        } catch (\Throwable $e) {
            $this->LogMessage($this->Translate('Condition could not be evaluated') . ': ' . $e->getMessage(), KL_WARNING);
            return false;
        }
    }
}

<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/SpeechText.php';
require_once __DIR__ . '/../libs/SpeechTrigger.php';
require_once __DIR__ . '/../libs/PushOutputs.php';

/**
 * Push Nachricht: eine Benachrichtigung mit Auslöser, Bedingung, Titel, Text, Icon und Ton.
 * Je Empfänger der Zentrale gibt es einen Schalter (Variable), damit jede Person in der Visu
 * selbst wählt, welche Nachrichten sie bekommt. Verschickt wird über die Zentrale.
 *
 * Erinnerung: mit Verzögerung wird erst gesendet, wenn der Auslöser-Wert so lange gilt; mit
 * Wiederholung erneut, solange er gilt. Die Fälligkeit steht im Attribut "Due", weil
 * SetTimerInterval bei jedem Aufruf neu zu zählen beginnt und nach Kernelstart verfällt.
 */
class PushNachricht extends IPSModuleStrict
{
    private const DATA_TX = '{ADF206D0-BC6E-4C0A-842B-514A4F32EF92}';
    private const DATA_RX = '{8CA7C83F-7320-466A-AAC3-DC2A14A2AFC1}';
    private const ZENTRALE_GUID = '{5C17B714-C9BD-4E8E-B429-E52B6EA84FF5}';

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyString('TriggerCondition', '');
        $this->RegisterPropertyInteger('TriggerMode', SpeechTrigger::MODE_BECOMES);
        $this->RegisterPropertyString('Title', '');
        $this->RegisterPropertyString('Texts', '');
        $this->RegisterPropertyInteger('TextScript', 0);
        $this->RegisterPropertyString('Icon', '');
        $this->RegisterPropertyString('Sound', '');
        $this->RegisterPropertyInteger('TargetObject', 0);
        $this->RegisterPropertyString('Condition', '');
        $this->RegisterPropertyInteger('DelaySeconds', 0);
        $this->RegisterPropertyInteger('RepeatMinutes', 0);
        $this->RegisterAttributeBoolean('Initialized', false);
        $this->RegisterAttributeInteger('Due', 0);
        $this->RegisterAttributeString('RecipientIdents', '[]');
        $this->RegisterTimer('Reminder', 0, 'PUSHN_Reminder($_IPS[\'TARGET\']);');

        $this->RegisterVariableBoolean('ACTIVE', $this->Translate('Active'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'ICON_TRUE'    => 'Alert',
            'ICON_FALSE'   => 'Alert',
        ], 10);
        $this->EnableAction('ACTIVE');
        $this->RegisterVariableInteger('LAST_RUN', $this->Translate('Last notification at'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_DATE_TIME,
        ], 20);

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
        $trigger = $this->triggerVariable();
        if ($trigger > 0 && @IPS_VariableExists($trigger)) {
            $this->RegisterMessage($trigger, VM_UPDATE);
            $this->RegisterReference($trigger);
        }
        foreach (['TextScript', 'TargetObject'] as $prop) {
            $id = $this->ReadPropertyInteger($prop);
            if ($id > 0 && @IPS_ObjectExists($id)) {
                $this->RegisterReference($id);
            }
        }

        if ($this->HasActiveParent()) {
            $names = json_decode((string)$this->SendDataToParent((string)json_encode(['DataID' => self::DATA_TX, 'Action' => 'Recipients'])), true);
            $this->maintainRecipients(is_array($names) ? array_map('strval', $names) : []);
        }
        $this->resumeReminder();

        $hasText = SpeechText::variants($this->ReadPropertyString('Texts')) !== [] || $this->scriptOk($this->ReadPropertyInteger('TextScript'));
        if (!$hasText) {
            $this->SetStatus(201); // no text
        } elseif (!($trigger > 0 && @IPS_VariableExists($trigger))) {
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
        if ($this->ReadAttributeInteger('Due') > 0 && !$this->stateHolds()) {
            $this->cancelReminder(); // the window was closed before the reminder was due
        }
        if (!$fires) {
            return;
        }
        $delay = max(0, $this->ReadPropertyInteger('DelaySeconds'));
        if ($delay > 0 && $this->isStateRule()) {
            if ($this->ReadAttributeInteger('Due') === 0) {
                $this->armReminder(time() + $delay); // already armed: keep the running countdown
            }
            return;
        }
        $this->notify($Data[2] ?? null, false);
        if ($this->ReadPropertyInteger('RepeatMinutes') > 0 && $this->isStateRule()) {
            $this->armReminder(time() + $this->ReadPropertyInteger('RepeatMinutes') * 60);
        }
    }

    /** Die Zentrale meldet ihre Empfängerliste, wenn sie sich ändert. */
    public function ReceiveData(string $JSONString): string
    {
        $data = json_decode($JSONString, true);
        if (is_array($data) && ($data['Action'] ?? '') === 'Recipients') {
            $this->maintainRecipients(array_map('strval', (array)($data['Names'] ?? [])));
        }
        return '';
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($Ident === 'ACTIVE' || str_starts_with($Ident, 'R_')) {
            $this->SetValue($Ident, (bool)$Value);
            if ($Ident === 'ACTIVE' && !$Value) {
                $this->cancelReminder();
            }
            return;
        }
        throw new Exception($this->Translate('Unknown action') . ': ' . $Ident);
    }

    /** Für Skripte: Nachricht prüfen (Aktiv, Bedingung) und senden. */
    public function Trigger(): string
    {
        return $this->notify(null, false);
    }

    /** Timer-Ziel der Verzögerung und Wiederholung. */
    public function Reminder(): void
    {
        $this->SetTimerInterval('Reminder', 0);
        $this->WriteAttributeInteger('Due', 0);
        if (!$this->stateHolds()) {
            return;
        }
        $this->notify(null, false);
        $minutes = $this->ReadPropertyInteger('RepeatMinutes');
        if ($minutes > 0) {
            $this->armReminder(time() + $minutes * 60);
        }
    }

    /** Formular: Titel und Text mit ersetzten Platzhaltern zeigen (Werte aus dem Formular, auch ungespeichert). */
    public function Preview(string $Title, string $Texts, int $TextScript, string $TriggerCondition): void
    {
        $trigger = SpeechTrigger::rule($TriggerCondition)['variableID'] ?? 0;
        $title = PushOutputs::cut(SpeechText::render($Title, $trigger, null, time()), PushOutputs::TITLE_MAX);
        if ($this->scriptOk($TextScript)) {
            $out = @IPS_RunScriptWaitEx($TextScript, ['SENDER' => 'PushNachricht', 'INSTANCE' => $this->InstanceID, 'VARIABLE' => $trigger,
                'VALUE' => $trigger > 0 && @IPS_VariableExists($trigger) ? GetValue($trigger) : null, 'OLD' => null]);
            $body = trim((string)$out) !== '' ? PushOutputs::cut((string)$out, PushOutputs::TEXT_MAX) : $this->Translate('The text script returned no text.');
        } else {
            $body = SpeechText::preview($Texts, $trigger, $this->Translate('No text entered'));
        }
        echo ($title !== '' ? $title . "\n\n" : '') . $body;
    }

    /** Formular: einmal senden, ohne Aktiv-Schalter, Bedingung und Sperrfrist. */
    public function Test(): void
    {
        $reason = $this->notify(null, true);
        echo $reason === '' ? $this->Translate('Sent') : $this->Translate('Not sent') . ': ' . $this->Translate($reason);
    }

    public function GetConfigurationForm(): string
    {
        $modes = [];
        foreach (SpeechTrigger::modeCaptions() as $value => $caption) {
            $modes[] = ['caption' => $caption, 'value' => $value];
        }
        $sounds = array_map(fn(string $s): array => ['caption' => $s === '' ? $this->Translate('none') : $s, 'value' => $s], PushOutputs::SOUNDS);
        return (string)json_encode([
            'elements' => [
                ['type' => 'ExpansionPanel', 'caption' => 'Trigger', 'expanded' => true, 'items' => [
                    ['type' => 'SelectCondition', 'name' => 'TriggerCondition', 'caption' => 'Rule', 'multi' => false],
                    ['type' => 'Select', 'name' => 'TriggerMode', 'caption' => 'Trigger', 'options' => $modes, 'width' => '100%'],
                    ['type' => 'RowLayout', 'items' => [
                        ['type' => 'NumberSpinner', 'name' => 'DelaySeconds', 'caption' => 'Only after the value holds for', 'suffix' => ' s', 'minimum' => 0],
                        ['type' => 'NumberSpinner', 'name' => 'RepeatMinutes', 'caption' => 'Repeat while it holds, every', 'suffix' => ' min', 'minimum' => 0],
                    ]],
                    ['type' => 'Label', 'caption' => 'Delay and repetition need "when the rule becomes true" or "while the rule is true".'],
                ]],
                ['type' => 'ExpansionPanel', 'caption' => 'Message', 'expanded' => true, 'items' => [
                    ['type' => 'ValidationTextBox', 'name' => 'Title', 'caption' => 'Title (max. 32 characters)', 'width' => '100%'],
                    ['type' => 'ValidationTextBox', 'name' => 'Texts', 'caption' => 'Text (one variant per line)', 'multiline' => true, 'width' => '100%'],
                    ['type' => 'Label', 'caption' => 'Placeholders: {value} {old} {name} {var:12345} {time} {date}'],
                    ['type' => 'SelectScript', 'name' => 'TextScript', 'caption' => 'or text from script (its output is the text)'],
                    ['type' => 'Button', 'caption' => 'Preview text', 'onClick' => 'PUSHN_Preview($id, $Title, $Texts, $TextScript, $TriggerCondition);'],
                    ['type' => 'RowLayout', 'items' => [
                        ['type' => 'SelectIcon', 'name' => 'Icon', 'caption' => 'Icon'],
                        ['type' => 'Select', 'name' => 'Sound', 'caption' => 'Sound', 'options' => $sounds],
                        ['type' => 'SelectObject', 'name' => 'TargetObject', 'caption' => 'Opens on tap'],
                    ]],
                    ['type' => 'Label', 'caption' => 'Icon, sound and target apply to the tile visualization only.'],
                ]],
                ['type' => 'ExpansionPanel', 'caption' => 'Condition', 'items' => [
                    ['type' => 'SelectCondition', 'name' => 'Condition', 'multi' => true],
                ]],
                ['type' => 'Label', 'caption' => 'Who gets the message is switched per recipient with the variables of this instance.'],
            ],
            'actions' => [
                ['type' => 'Button', 'caption' => 'Send test', 'onClick' => 'PUSHN_Test($id);'],
            ],
            'status' => [
                ['code' => 201, 'icon' => 'inactive', 'caption' => 'No text entered'],
                ['code' => 202, 'icon' => 'inactive', 'caption' => 'No trigger: select a variable'],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // ------------------------------------------------------------------ internals

    /** @return string '' wenn an die Zentrale übergeben und gesendet, sonst der Grund */
    private function notify(mixed $old, bool $test): string
    {
        if (!$test) {
            if (!$this->GetValue('ACTIVE')) {
                return 'notification is inactive';
            }
            if (!$this->conditionPassing($this->ReadPropertyString('Condition'))) {
                return 'condition not met';
            }
        }
        $trigger = $this->triggerVariable();
        $text = $this->scriptText($old);
        if ($text === '') {
            $template = SpeechText::pick($this->ReadPropertyString('Texts'));
            $text = $template === '' ? '' : SpeechText::render($template, $trigger, $old, time());
        }
        if (trim($text) === '') {
            return 'no text';
        }
        $title = SpeechText::render($this->ReadPropertyString('Title'), $trigger, $old, time());
        $recipients = $this->selectedRecipients();
        if ($recipients === []) {
            return 'no recipient';
        }
        if (!$this->HasActiveParent()) {
            $this->LogMessage($this->Translate('No hub connected') . ': ' . $text, KL_WARNING);
            return 'no hub connected';
        }
        $reason = (string)$this->SendDataToParent((string)json_encode([
            'DataID'     => self::DATA_TX,
            'Action'     => 'Send',
            'Title'      => $title,
            'Text'       => $text,
            'Icon'       => $this->ReadPropertyString('Icon'),
            'Sound'      => $this->ReadPropertyString('Sound'),
            'Target'     => $this->ReadPropertyInteger('TargetObject'),
            'Recipients' => $recipients,
            'Test'       => $test,
            'Key'        => $test ? '' : 'PUSHN' . $this->InstanceID,
        ], JSON_UNESCAPED_UNICODE));
        $this->SendDebug('Notify', $title . ' | ' . $text . ($reason !== '' ? ' — ' . $reason : ''), 0);
        if ($reason === '') {
            $this->SetValue('LAST_RUN', time());
        }
        return $reason;
    }

    /** Text aus dem Textskript (dessen Ausgabe), '' ohne Skript oder bei leerer Ausgabe. */
    private function scriptText(mixed $old): string
    {
        $script = $this->ReadPropertyInteger('TextScript');
        if (!$this->scriptOk($script)) {
            return '';
        }
        $trigger = $this->triggerVariable();
        $out = @IPS_RunScriptWaitEx($script, [
            'SENDER'   => 'PushNachricht',
            'INSTANCE' => $this->InstanceID,
            'VARIABLE' => $trigger,
            'VALUE'    => $trigger > 0 && @IPS_VariableExists($trigger) ? GetValue($trigger) : null,
            'OLD'      => $old,
        ]);
        return trim((string)$out);
    }

    /** @param array<int, string> $names */
    private function maintainRecipients(array $names): void
    {
        $old = json_decode($this->ReadAttributeString('RecipientIdents'), true) ?: [];
        $now = [];
        $position = 100;
        foreach ($names as $name) {
            $ident = self::recipientIdent($name);
            $isNew = !is_int(@IPS_GetObjectIDByIdent($ident, $this->InstanceID));
            $this->MaintainVariable($ident, $name, VARIABLETYPE_BOOLEAN, [
                'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
                'ICON_TRUE'    => 'Mobile',
                'ICON_FALSE'   => 'Mobile',
            ], $position++, true);
            $this->EnableAction($ident);
            if ($isNew) {
                $this->SetValue($ident, true); // a new recipient gets the message until switched off
            }
            $now[] = $ident;
        }
        foreach (array_diff($old, $now) as $gone) {
            $this->MaintainVariable((string)$gone, '', VARIABLETYPE_BOOLEAN, [], 0, false);
        }
        $this->WriteAttributeString('RecipientIdents', (string)json_encode(array_values($now)));
    }

    /** @return array<int, string> Namen der Empfänger, deren Schalter an ist */
    private function selectedRecipients(): array
    {
        $out = [];
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $child) {
            $ident = (string)IPS_GetObject($child)['ObjectIdent'];
            if (str_starts_with($ident, 'R_') && @IPS_VariableExists($child) && GetValueBoolean($child)) {
                $out[] = IPS_GetName($child);
            }
        }
        return $out;
    }

    private static function recipientIdent(string $name): string
    {
        return 'R_' . substr(md5(mb_strtolower(trim($name))), 0, 10);
    }

    private function triggerVariable(): int
    {
        return SpeechTrigger::rule($this->ReadPropertyString('TriggerCondition'))['variableID'] ?? 0;
    }

    /** Nur Auslöse-Arten mit Regel beschreiben einen Zustand, der andauern kann. */
    private function isStateRule(): bool
    {
        return SpeechTrigger::isStateMode($this->ReadPropertyInteger('TriggerMode'));
    }

    /** Ist die Regel gerade noch erfüllt? */
    private function stateHolds(): bool
    {
        $rule = SpeechTrigger::rule($this->ReadPropertyString('TriggerCondition'));
        if (!$this->isStateRule() || $rule === null || !@IPS_VariableExists($rule['variableID'])) {
            return false;
        }
        return SpeechTrigger::passes($rule, GetValue($rule['variableID']));
    }

    private function armReminder(int $due): void
    {
        $this->WriteAttributeInteger('Due', $due);
        $this->SetTimerInterval('Reminder', max(1, $due - time()) * 1000);
    }

    private function cancelReminder(): void
    {
        $this->WriteAttributeInteger('Due', 0);
        $this->SetTimerInterval('Reminder', 0);
    }

    /** Nach Kernelstart oder Übernehmen: eine offene Erinnerung mit der Restzeit wieder scharfstellen. */
    private function resumeReminder(): void
    {
        $due = $this->ReadAttributeInteger('Due');
        if ($due <= 0) {
            $this->SetTimerInterval('Reminder', 0);
            return;
        }
        if (!$this->stateHolds() || ($this->ReadPropertyInteger('DelaySeconds') <= 0 && $this->ReadPropertyInteger('RepeatMinutes') <= 0)) {
            $this->cancelReminder();
            return;
        }
        $this->SetTimerInterval('Reminder', max(1, $due - time()) * 1000);
    }

    private function scriptOk(int $id): bool
    {
        return $id > 0 && @IPS_ScriptExists($id);
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

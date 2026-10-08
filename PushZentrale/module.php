<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/PushOutputs.php';
require_once __DIR__ . '/../libs/SpeechText.php';
require_once __DIR__ . '/../libs/SpeechTrigger.php';
require_once __DIR__ . '/../libs/PushForm.php';

/**
 * Push Zentrale: alle Pushbenachrichtigungen in einer Instanz.
 *  - Empfänger: je Person oder Gruppe eine Visualisierung (Symcon sendet immer an alle Geräte
 *    einer Visualisierung, nie an ein einzelnes Gerät).
 *  - Nachrichten: Liste mit eigenem Bearbeiten-Dialog je Zeile (Auslöser aus dem Bedingungs-Dialog,
 *    Titel, Textvarianten oder Textskript, Icon, Ton, Ziel, Bedingung, Verzögerung, Wiederholung).
 *  - Je Nachricht und Empfänger ein Schalter (Variable), damit jede Person in der Visu selbst
 *    wählt, was sie bekommt; dazu ein Hauptschalter.
 * Verzögerung und Wiederholung laufen über einen Timer auf die früheste Fälligkeit; die
 * Fälligkeiten stehen im Attribut "Due", weil SetTimerInterval bei jedem Aufruf neu zählt.
 */
class PushZentrale extends IPSModuleStrict
{
    use PushForm;

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyString('Recipients', '[]');
        $this->RegisterPropertyString('Messages', '[]');
        $this->RegisterPropertyString('Condition', '');
        $this->RegisterPropertyInteger('Cooldown', 10);
        $this->RegisterAttributeString('Recent', '{}');
        $this->RegisterAttributeString('Due', '{}');
        $this->RegisterAttributeString('LastRun', '{}');
        $this->RegisterAttributeString('SwitchIdents', '[]');
        $this->RegisterAttributeBoolean('Initialized', false);
        $this->RegisterTimer('Reminder', 0, 'PUSHZ_Reminder($_IPS[\'TARGET\']);');
        // Symcon rejects IPS_ApplyChanges of the own instance inside ApplyChanges (re-entrant): apply again via timer
        $this->RegisterTimer('Reapply', 0, 'IPS_ApplyChanges($_IPS[\'TARGET\']);');

        $this->RegisterVariableBoolean('MASTER', $this->Translate('Notifications'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'ICON_TRUE'    => 'Alert',
            'ICON_FALSE'   => 'Alert',
        ], 10);
        $this->EnableAction('MASTER');
        $this->RegisterVariableString('LAST_TEXT', $this->Translate('Last notification'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Alert',
        ], 20);
        $this->RegisterVariableInteger('LAST_TIME', $this->Translate('Last notification at'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_DATE_TIME,
        ], 30);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        if (!$this->ReadAttributeBoolean('Initialized')) {
            $this->SetValue('MASTER', true); // once; Create runs on every load and must not reset it
            $this->WriteAttributeBoolean('Initialized', true);
        }
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }
        @$this->SetTimerInterval('Reapply', 0);
        if ($this->assignMessageIds()) {
            $this->SetTimerInterval('Reapply', 100);
            return; // applied again by the timer, with the ids
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
        foreach ($this->messages() as $m) {
            $var = $this->triggerOf($m);
            if ($var > 0 && @IPS_VariableExists($var)) {
                $this->RegisterMessage($var, VM_UPDATE);
                $this->RegisterReference($var);
            }
            foreach (['TextScript', 'TargetObject'] as $key) {
                $id = (int)($m[$key] ?? 0);
                if ($id > 0 && @IPS_ObjectExists($id)) {
                    $this->RegisterReference($id);
                }
            }
        }
        foreach ($this->recipients() as $r) {
            if ((int)$r['instance'] > 0 && @IPS_ObjectExists((int)$r['instance'])) {
                $this->RegisterReference((int)$r['instance']);
            }
        }
        $this->maintainSwitches();
        $this->scheduleReminder();
        $this->SetSummary(sprintf($this->Translate('%d messages, %d recipients'), count($this->messages()), count($this->recipients())));
        $this->SetStatus(IS_ACTIVE);
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
            return;
        }
        if ($Message !== VM_UPDATE) {
            return;
        }
        $due = $this->readJson('Due');
        foreach ($this->messages() as $m) {
            $rule = SpeechTrigger::rule((string)($m['TriggerCondition'] ?? ''));
            if ($rule === null || $rule['variableID'] !== $SenderID || !($m['active'] ?? true)) {
                continue;
            }
            $id = (string)$m['msgId'];
            $mode = (int)($m['TriggerMode'] ?? 0);
            if (isset($due[$id]) && !$this->stateHolds($m)) {
                unset($due[$id]); // e.g. the window was closed before the reminder was due
            }
            if (!SpeechTrigger::firesRule($mode, $rule, $Data[0] ?? null, (bool)($Data[1] ?? false), $Data[2] ?? null)) {
                continue;
            }
            $delay = max(0, (int)($m['DelaySeconds'] ?? 0));
            if ($delay > 0 && SpeechTrigger::isStateMode($mode)) {
                $due[$id] ??= time() + $delay; // already armed: keep the running countdown
                continue;
            }
            $this->notify($m, $Data[2] ?? null, false);
            if ((int)($m['RepeatMinutes'] ?? 0) > 0 && SpeechTrigger::isStateMode($mode)) {
                $due[$id] = time() + (int)$m['RepeatMinutes'] * 60;
            }
        }
        $this->writeJson('Due', $due);
        $this->scheduleReminder();
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($Ident === 'MASTER' || str_starts_with($Ident, 'R_')) {
            $this->SetValue($Ident, (bool)$Value);
            return;
        }
        throw new Exception($this->Translate('Unknown action') . ': ' . $Ident);
    }

    /** Timer-Ziel: fällige Verzögerungen und Wiederholungen senden. */
    public function Reminder(): void
    {
        $due = $this->readJson('Due');
        if ($due === []) {
            $this->scheduleReminder();
            return;
        }
        // the timer was set for the earliest due entry; everything due within a second of it is due now
        $limit = max((int)min($due) + 1, time());
        $byId = array_column($this->messages(), null, 'msgId');
        foreach ($due as $id => $at) {
            if ((int)$at > $limit) {
                continue;
            }
            unset($due[$id]);
            $m = $byId[$id] ?? null;
            if ($m === null || !($m['active'] ?? true) || !$this->stateHolds($m)) {
                continue;
            }
            $this->notify($m, null, false);
            if ((int)($m['RepeatMinutes'] ?? 0) > 0) {
                $due[$id] = time() + (int)$m['RepeatMinutes'] * 60;
            }
        }
        $this->writeJson('Due', $due);
        $this->scheduleReminder();
    }

    /** Für Skripte: Nachricht nach Namen auslösen (prüft Aktiv und Bedingungen). '' = gesendet, sonst der Grund. */
    public function Trigger(string $name): string
    {
        foreach ($this->messages() as $m) {
            if (strcasecmp(trim((string)$m['name']), trim($name)) === 0) {
                return ($m['active'] ?? true) ? $this->notify($m, null, false) : 'notification is inactive';
            }
        }
        return 'unknown message';
    }

    /** Für Skripte: freier Text an Empfänger (Namen durch Komma getrennt; leer = alle). '' = gesendet, sonst der Grund. */
    public function Send(string $title, string $text, string $recipients): string
    {
        $names = array_values(array_filter(array_map('trim', explode(',', $recipients)), static fn(string $n): bool => $n !== ''));
        return $this->deliver($title, $text, '', '', 0, $names === [] ? $this->names() : $names, false, '');
    }

    /** Formular (Empfänger): Testnachricht an einen Empfänger. */
    public function TestRecipient(string $name): string
    {
        $recipient = $this->findRecipient($name);
        if ($recipient === null) {
            return $this->Translate('Recipient not found');
        }
        $error = PushOutputs::send($recipient, 'Symcon', $this->Translate('This is a test notification.'), 'Alert', '', 0);
        return $error === '' ? $this->Translate('Sent') : $this->Translate('Failed') . ': ' . $this->Translate($error);
    }

    /** Formular (Dialog einer Nachricht): Titel und Text mit ersetzten Platzhaltern, auch ungespeichert. */
    public function PreviewMessage(string $Title, string $Texts, int $TextScript, string $TriggerCondition): string
    {
        $m = ['Title' => $Title, 'Texts' => $Texts, 'TextScript' => $TextScript, 'TriggerCondition' => $TriggerCondition];
        [$title, $text] = $this->compose($m, null);
        return ($title !== '' ? $title . "\n\n" : '') . ($text !== '' ? $text : $this->Translate('No text entered'));
    }

    /** Formular (Dialog einer Nachricht): einmal an alle Empfänger senden, ohne Schalter und Bedingungen. */
    public function TestMessage(string $Title, string $Texts, int $TextScript, string $TriggerCondition, string $Icon, string $Sound, int $TargetObject): string
    {
        $m = ['Title' => $Title, 'Texts' => $Texts, 'TextScript' => $TextScript, 'TriggerCondition' => $TriggerCondition, 'Icon' => $Icon, 'Sound' => $Sound, 'TargetObject' => $TargetObject];
        [$title, $text] = $this->compose($m, null);
        $reason = $this->deliver($title, $text, $Icon, $Sound, $TargetObject, $this->names(), true, '');
        return $reason === '' ? $this->Translate('Sent') : $this->Translate('Not sent') . ': ' . $this->Translate($reason);
    }

    // ------------------------------------------------------------------ internals

    /** @return string '' wenn gesendet, sonst der Grund */
    private function notify(array $m, mixed $old, bool $test): string
    {
        if (!$test && !$this->conditionPassing((string)($m['Condition'] ?? ''))) {
            return $this->skip('condition not met', (string)$m['name']);
        }
        [$title, $text] = $this->compose($m, $old);
        $recipients = [];
        foreach ($this->recipients() as $r) {
            $vid = @IPS_GetObjectIDByIdent(self::switchIdent((string)$m['msgId'], (string)$r['name']), $this->InstanceID);
            if (is_int($vid) && GetValueBoolean($vid)) {
                $recipients[] = (string)$r['name'];
            }
        }
        $reason = $this->deliver($title, $text, (string)($m['Icon'] ?? ''), (string)($m['Sound'] ?? ''), (int)($m['TargetObject'] ?? 0),
            $recipients, $test, 'msg:' . $m['msgId']);
        if ($reason === '') {
            $last = $this->readJson('LastRun');
            $last[(string)$m['msgId']] = time();
            $this->writeJson('LastRun', $last);
        }
        return $reason;
    }

    /** @return array{0: string, 1: string} Titel und Text, Platzhalter ersetzt */
    private function compose(array $m, mixed $old): array
    {
        $trigger = $this->triggerOf($m);
        $text = '';
        $script = (int)($m['TextScript'] ?? 0);
        if ($script > 0 && @IPS_ScriptExists($script)) {
            $text = trim((string)@IPS_RunScriptWaitEx($script, ['SENDER' => 'PushZentrale', 'INSTANCE' => $this->InstanceID, 'VARIABLE' => $trigger,
                'VALUE' => $trigger > 0 && @IPS_VariableExists($trigger) ? GetValue($trigger) : null, 'OLD' => $old]));
        }
        if ($text === '') {
            $template = SpeechText::pick((string)($m['Texts'] ?? ''));
            $text = $template === '' ? '' : SpeechText::render($template, $trigger, $old, time());
        }
        return [SpeechText::render((string)($m['Title'] ?? ''), $trigger, $old, time()), trim($text)];
    }

    /** @param array<int, string> $names */
    private function deliver(string $title, string $text, string $icon, string $sound, int $target, array $names, bool $test, string $key): string
    {
        $text = trim($text);
        if ($text === '') {
            return $this->skip('no text', $title);
        }
        if (!$test) {
            if (!$this->GetValue('MASTER')) {
                return $this->skip('notifications are switched off', $text);
            }
            if (!$this->conditionPassing($this->ReadPropertyString('Condition'))) {
                return $this->skip('global condition not met', $text);
            }
            $key = $key !== '' ? $key : md5($title . "\n" . $text);
            $recent = $this->readJson('Recent');
            $now = time();
            $cooldown = max(0, $this->ReadPropertyInteger('Cooldown'));
            if ($cooldown > 0 && isset($recent[$key]) && $now - (int)$recent[$key] < $cooldown) {
                return $this->skip('same notification within the cooldown', $text);
            }
            $recent = array_filter($recent, static fn($t): bool => $now - (int)$t < max(3600, $cooldown));
            $recent[$key] = $now;
            $this->writeJson('Recent', $recent);
        }
        if ($names === []) {
            return $this->skip('no recipient', $text);
        }
        $sent = 0;
        foreach ($names as $name) {
            $recipient = $this->findRecipient($name);
            if ($recipient === null) {
                $this->LogMessage(sprintf('%s: %s', $this->Translate('Unknown recipient'), $name), KL_WARNING);
                continue;
            }
            $error = PushOutputs::send($recipient, $title, $text, $icon, $sound, $target);
            if ($error !== '') {
                $this->LogMessage(sprintf('%s (%s): %s', $this->Translate('Notification failed'), $name, $this->Translate($error)), KL_WARNING);
            } else {
                $sent++;
            }
            $this->SendDebug('Send', sprintf('%s | %s → %s %s', $title, $text, $name, $error), 0);
        }
        if ($sent === 0) {
            return 'sending failed';
        }
        $this->SetValue('LAST_TEXT', $title !== '' ? $title . ': ' . $text : $text);
        $this->SetValue('LAST_TIME', time());
        return '';
    }

    /** Je Nachricht und Empfänger ein Schalter; neue Schalter starten eingeschaltet. */
    private function maintainSwitches(): void
    {
        $old = $this->readJson('SwitchIdents');
        $now = [];
        $position = 100;
        foreach ($this->messages() as $m) {
            foreach ($this->recipients() as $r) {
                $ident = self::switchIdent((string)$m['msgId'], (string)$r['name']);
                $isNew = !is_int(@IPS_GetObjectIDByIdent($ident, $this->InstanceID));
                $caption = trim((string)$m['name']) . ' – ' . trim((string)$r['name']);
                $this->MaintainVariable($ident, $caption, VARIABLETYPE_BOOLEAN, [
                    'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
                    'ICON_TRUE'    => 'Mobile',
                    'ICON_FALSE'   => 'Mobile',
                ], $position++, true);
                $this->EnableAction($ident);
                if ($isNew) {
                    $this->SetValue($ident, true);
                } elseif (IPS_GetName($this->GetIDForIdent($ident)) !== $caption) {
                    IPS_SetName($this->GetIDForIdent($ident), $caption); // message or recipient renamed
                }
                $now[] = $ident;
            }
        }
        foreach (array_diff($old, $now) as $gone) {
            $this->MaintainVariable((string)$gone, '', VARIABLETYPE_BOOLEAN, [], 0, false);
        }
        $this->writeJson('SwitchIdents', array_values($now));
    }

    /** Neue Zeilen bekommen eine feste Kennung (für Schalter und Fälligkeiten); übernommen wird sie über den Timer "Reapply". */
    private function assignMessageIds(): bool
    {
        $rows = json_decode($this->ReadPropertyString('Messages'), true);
        if (!is_array($rows)) {
            return false;
        }
        $changed = false;
        $seen = [];
        foreach ($rows as &$row) {
            $id = (string)($row['msgId'] ?? '');
            if ($id === '' || isset($seen[$id])) {
                $row['msgId'] = substr(md5(uniqid('', true) . count($seen)), 0, 8);
                $changed = true;
            }
            $seen[(string)$row['msgId']] = true;
        }
        unset($row);
        if (!$changed) {
            return false;
        }
        IPS_SetProperty($this->InstanceID, 'Messages', (string)json_encode($rows, JSON_UNESCAPED_UNICODE));
        return true;
    }

    private static function switchIdent(string $msgId, string $recipient): string
    {
        return 'R_' . $msgId . '_' . substr(md5(mb_strtolower(trim($recipient))), 0, 6);
    }

    private function stateHolds(array $m): bool
    {
        $rule = SpeechTrigger::rule((string)($m['TriggerCondition'] ?? ''));
        if ($rule === null || !SpeechTrigger::isStateMode((int)($m['TriggerMode'] ?? 0)) || !@IPS_VariableExists($rule['variableID'])) {
            return false;
        }
        return SpeechTrigger::passes($rule, GetValue($rule['variableID']));
    }

    /** Timer auf die früheste Fälligkeit; nur stellen, wenn sich etwas ändert (SetTimerInterval zählt neu). */
    private function scheduleReminder(): void
    {
        $due = $this->readJson('Due');
        $byId = array_column($this->messages(), null, 'msgId');
        $due = array_filter($due, static fn($at, $id): bool => isset($byId[$id]), ARRAY_FILTER_USE_BOTH);
        $this->writeJson('Due', $due);
        $next = $due === [] ? 0 : max(1, (int)min($due) - time()) * 1000;
        if ($this->GetTimerInterval('Reminder') !== $next) {
            $this->SetTimerInterval('Reminder', $next);
        }
    }

    private function triggerOf(array $m): int
    {
        return SpeechTrigger::rule((string)($m['TriggerCondition'] ?? ''))['variableID'] ?? 0;
    }

    /** @return array<int, array<string, mixed>> */
    private function messages(): array
    {
        $list = json_decode($this->ReadPropertyString('Messages'), true);
        return is_array($list) ? array_values(array_filter($list, static fn($m): bool => is_array($m) && trim((string)($m['name'] ?? '')) !== '' && (string)($m['msgId'] ?? '') !== '')) : [];
    }

    /** @return array<int, array<string, mixed>> */
    private function recipients(): array
    {
        $list = json_decode($this->ReadPropertyString('Recipients'), true);
        return is_array($list) ? array_values(array_filter($list, static fn($r): bool => is_array($r) && trim((string)($r['name'] ?? '')) !== '')) : [];
    }

    /** @return array<int, string> */
    private function names(): array
    {
        return array_map(static fn(array $r): string => trim((string)$r['name']), $this->recipients());
    }

    /** @return array<string, mixed>|null */
    private function findRecipient(string $name): ?array
    {
        foreach ($this->recipients() as $recipient) {
            if (strcasecmp(trim((string)$recipient['name']), trim($name)) === 0) {
                return $recipient;
            }
        }
        return null;
    }

    private function readJson(string $attribute): array
    {
        $v = json_decode($this->ReadAttributeString($attribute), true);
        return is_array($v) ? $v : [];
    }

    private function writeJson(string $attribute, array $value): void
    {
        $this->WriteAttributeString($attribute, (string)json_encode($value, JSON_UNESCAPED_UNICODE));
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

    private function skip(string $reason, string $text): string
    {
        $this->SendDebug('Skip', $reason . ': ' . $text, 0);
        return $reason;
    }
}

<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/PushOutputs.php';

/**
 * Push Zentrale: kennt die Empfänger (je Person oder Gruppe eine Visualisierung), den Hauptschalter,
 * eine globale Bedingung und eine Sperrfrist. Nachrichten kommen von den Kind-Instanzen
 * (ForwardData) oder aus Skripten (PUSHZ_Send).
 */
class PushZentrale extends IPSModuleStrict
{
    private const DATA_TX = '{ADF206D0-BC6E-4C0A-842B-514A4F32EF92}';
    private const DATA_RX = '{8CA7C83F-7320-466A-AAC3-DC2A14A2AFC1}';

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyString('Recipients', '[]');
        $this->RegisterPropertyString('Condition', '');
        $this->RegisterPropertyInteger('Cooldown', 10);
        $this->RegisterAttributeString('Recent', '{}');
        $this->RegisterAttributeBoolean('Initialized', false);

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
        $this->SetSummary(sprintf($this->Translate('%d recipients'), count($this->recipients())));
        $this->SetStatus(IS_ACTIVE);
        // the messages keep one switch per recipient; tell them the current list
        $this->SendDataToChildren((string)json_encode(['DataID' => self::DATA_RX, 'Action' => 'Recipients', 'Names' => $this->names()], JSON_UNESCAPED_UNICODE));
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($Ident === 'MASTER') {
            $this->SetValue('MASTER', (bool)$Value);
            return;
        }
        throw new Exception($this->Translate('Unknown action') . ': ' . $Ident);
    }

    /** Nachrichten der Kind-Instanzen ("Send") und die Empfängerliste ("Recipients"). */
    public function ForwardData(string $JSONString): string
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data) || ($data['DataID'] ?? '') !== self::DATA_TX) {
            return '';
        }
        switch ((string)($data['Action'] ?? '')) {
            case 'Recipients':
                return (string)json_encode($this->names(), JSON_UNESCAPED_UNICODE);
            case 'Send':
                return $this->deliver(
                    (string)($data['Title'] ?? ''),
                    (string)($data['Text'] ?? ''),
                    (string)($data['Icon'] ?? ''),
                    (string)($data['Sound'] ?? ''),
                    (int)($data['Target'] ?? 0),
                    array_map('strval', (array)($data['Recipients'] ?? [])),
                    (bool)($data['Test'] ?? false),
                    (string)($data['Key'] ?? '')
                );
        }
        return '';
    }

    /**
     * Für Skripte: sendet an die Empfänger in $recipients (Namen, durch Komma getrennt; leer = alle).
     * Rückgabe: '' wenn gesendet, sonst der Grund.
     */
    public function Send(string $title, string $text, string $recipients): string
    {
        $names = array_values(array_filter(array_map('trim', explode(',', $recipients)), static fn(string $n): bool => $n !== ''));
        return $this->deliver($title, $text, '', '', 0, $names === [] ? $this->names() : $names, false, '');
    }

    /** Formular: Testnachricht an einen Empfänger, ohne Hauptschalter und Bedingung. */
    public function TestRecipient(string $name): void
    {
        $recipient = $this->findRecipient($name);
        if ($recipient === null) {
            echo $this->Translate('Recipient not found');
            return;
        }
        $error = PushOutputs::send($recipient, 'Symcon', $this->Translate('This is a test notification.'), 'Alert', '', 0);
        echo $error === '' ? $this->Translate('Sent') : $this->Translate('Failed') . ': ' . $this->Translate($error);
    }

    public function GetConfigurationForm(): string
    {
        $typeOptions = [];
        foreach (PushOutputs::types() as $type) {
            $typeOptions[] = ['caption' => $this->Translate('type:' . $type), 'value' => $type];
        }
        $names = $this->names();
        return (string)json_encode([
            'elements' => [
                ['type' => 'List', 'name' => 'Recipients', 'caption' => 'Recipients', 'add' => true, 'delete' => true, 'rowCount' => 5,
                    'columns' => [
                        ['caption' => 'Name', 'name' => 'name', 'width' => '200px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                        ['caption' => 'Type', 'name' => 'type', 'width' => '220px', 'add' => PushOutputs::VISU, 'edit' => ['type' => 'Select', 'options' => $typeOptions]],
                        ['caption' => 'Visualization', 'name' => 'instance', 'width' => 'auto', 'add' => 0, 'edit' => ['type' => 'SelectInstance']],
                    ]],
                ['type' => 'Label', 'caption' => 'A push always goes to every device of the chosen visualization. For single persons or devices create one visualization each and enable only those devices in its "Notifications" tab.'],
                ['type' => 'ExpansionPanel', 'caption' => 'Global condition', 'items' => [
                    ['type' => 'SelectCondition', 'name' => 'Condition', 'multi' => true],
                ]],
                ['type' => 'NumberSpinner', 'name' => 'Cooldown', 'caption' => 'Same notification at most every', 'suffix' => ' s', 'minimum' => 0],
            ],
            'actions' => [
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Select', 'name' => 'TestTarget', 'caption' => 'Recipient', 'options' => array_map(static fn(string $n): array => ['caption' => $n, 'value' => $n], $names ?: [''])],
                    ['type' => 'Button', 'caption' => 'Send test', 'onClick' => 'PUSHZ_TestRecipient($id, $TestTarget);'],
                ]],
            ],
            'status' => [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // ------------------------------------------------------------------ internals

    /** @param array<int, string> $names */
    private function deliver(string $title, string $text, string $icon, string $sound, int $target, array $names, bool $test, string $key): string
    {
        $text = trim($text);
        if ($text === '') {
            return 'empty text';
        }
        if (!$test) {
            if (!$this->GetValue('MASTER')) {
                return $this->skip('notifications are switched off', $text);
            }
            if (!$this->conditionPassing($this->ReadPropertyString('Condition'))) {
                return $this->skip('global condition not met', $text);
            }
            $key = $key !== '' ? $key : md5($title . "\n" . $text);
            $recent = json_decode($this->ReadAttributeString('Recent'), true) ?: [];
            $now = time();
            $cooldown = max(0, $this->ReadPropertyInteger('Cooldown'));
            if ($cooldown > 0 && isset($recent[$key]) && $now - (int)$recent[$key] < $cooldown) {
                return $this->skip('same notification within the cooldown', $text);
            }
            $recent = array_filter($recent, static fn($t): bool => $now - (int)$t < max(3600, $cooldown));
            $recent[$key] = $now;
            $this->WriteAttributeString('Recent', (string)json_encode($recent));
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

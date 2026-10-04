<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/EmProtocol.php';

/**
 * EchoMuse Gerät: ein Echo Dot mit der EchoMuse-Firmware. Zeigt Zustand und Bedienung des Dots
 * als Variablen (online, Lautstärke, Stumm, letzte Taste) und gibt Ansagen über das Gateway aus.
 */
class EchoMuseGeraet extends IPSModuleStrict
{
    private const GATEWAY_GUID = '{863162E7-78F5-45C5-8ACC-616ADA42283A}';
    private const CHILD_TX = '{046405B3-995F-400E-95B2-4EB912CB0818}';
    private const CHILD_RX = '{70B90512-B075-499B-A777-C70F7FD0D7FF}';

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyString('DeviceId', '');
        $this->RegisterPropertyInteger('StartupVolume', 0);
        $this->RegisterAttributeBoolean('Initialized', false);
        $this->ConnectParent(self::GATEWAY_GUID);

        $this->RegisterVariableBoolean('ONLINE', $this->Translate('Online'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'OPTIONS'      => json_encode([
                $this->option(false, $this->Translate('Offline')),
                $this->option(true, $this->Translate('Online')),
            ], JSON_UNESCAPED_UNICODE),
        ], 10);
        $this->RegisterVariableInteger('VOLUME', $this->Translate('Volume'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
            'ICON'         => 'Speaker',
            'SUFFIX'       => ' %',
            'MIN'          => 0,
            'MAX'          => 100,
            'STEP_SIZE'    => 1,
        ], 20);
        $this->EnableAction('VOLUME');
        $this->RegisterVariableBoolean('MUTED', $this->Translate('Muted'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'OPTIONS'      => json_encode([
                $this->option(false, $this->Translate('Microphone on')),
                $this->option(true, $this->Translate('Muted')),
            ], JSON_UNESCAPED_UNICODE),
        ], 30);
        $this->RegisterVariableString('BUTTON', $this->Translate('Last button'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Hand',
        ], 40);
        $this->RegisterVariableString('FIRMWARE', $this->Translate('Firmware'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
        ], 50);
        $this->RegisterVariableInteger('LAST_SEEN', $this->Translate('Last contact'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_DATE_TIME,
        ], 60);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $id = trim($this->ReadPropertyString('DeviceId'));
        $this->SetSummary($id);
        if ($id === '') {
            $this->SetStatus(201);
            return;
        }
        $this->SetStatus(IS_ACTIVE);
        if ($this->HasActiveParent()) {
            $online = $this->ask('Online') === '1';
            if ($online !== $this->GetValue('ONLINE')) {
                $this->SetValue('ONLINE', $online);
            }
        }
    }

    public function ReceiveData(string $JSONString): string
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data) || ($data['DataID'] ?? '') !== self::CHILD_RX || (string)($data['DeviceId'] ?? '') !== trim($this->ReadPropertyString('DeviceId'))) {
            return '';
        }
        $this->SetValue('LAST_SEEN', time());
        switch ((string)($data['Event'] ?? '')) {
            case 'online':
                $this->SetValue('ONLINE', true);
                $this->SetValue('FIRMWARE', mb_substr((string)($data['version'] ?? ''), 0, 40));
                $startup = $this->ReadPropertyInteger('StartupVolume');
                if ($startup > 0) {
                    $this->control(EmProtocol::config(['startupVolume' => EmProtocol::percentToLevel($startup)]));
                }
                break;
            case 'offline':
                $this->SetValue('ONLINE', false);
                break;
            case 'button':
                if ((bool)($data['down'] ?? false) === false) { // gezählt wird beim Loslassen
                    $this->SetValue('BUTTON', trim(((string)($data['click'] ?? 'press')) . ' ' . date('H:i:s')));
                }
                break;
            case 'mute':
                $this->SetValue('MUTED', (bool)($data['muted'] ?? false));
                break;
            case 'volume':
                $this->SetValue('VOLUME', EmProtocol::levelToPercent((int)($data['level'] ?? 0)));
                break;
        }
        return '';
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($Ident === 'VOLUME') {
            $percent = max(0, min(100, (int)$Value));
            $reason = $this->control(EmProtocol::volumeSet(EmProtocol::percentToLevel($percent)));
            if ($reason === '') {
                $this->SetValue('VOLUME', $percent);
            } else {
                $this->LogMessage($this->Translate('Volume not set') . ': ' . $this->Translate($reason), KL_WARNING);
            }
            return;
        }
        throw new Exception($this->Translate('Unknown action') . ': ' . $Ident);
    }

    /** Spielt eine WAV-Datei aus dem Medienordner von Symcon ab (z. B. die KI-Stimme der Sprachausgabe). Rückgabe '' = angenommen. */
    public function SpeakFile(string $File): string
    {
        return $this->ask('SpeakFile', ['File' => $File]);
    }

    /** Ein Testton von 0,2 bis 5 Sekunden. */
    public function Beep(int $Seconds): string
    {
        return $this->ask('Beep', ['Seconds' => $Seconds]);
    }

    /** Ein Signalton, den das Gerät selbst erzeugt (z. B. "wake"). */
    public function PlayCue(string $Cue): string
    {
        return $this->control(EmProtocol::playCue($Cue));
    }

    /** Teilkonfiguration an das Gerät schicken (JSON-Objekt in camelCase, Schlüssel wie in der EchoMuse-Doku). */
    public function SendConfig(string $Json): string
    {
        $cfg = json_decode($Json, true);
        return is_array($cfg) ? $this->control(EmProtocol::config($cfg)) : 'invalid JSON';
    }

    public function GetConfigurationForm(): string
    {
        return (string)json_encode([
            'elements' => [
                ['type' => 'ValidationTextBox', 'name' => 'DeviceId', 'caption' => 'Device ID (shown in the gateway when the device asks to join)'],
                ['type' => 'NumberSpinner', 'name' => 'StartupVolume', 'caption' => 'Volume after connecting (0 = leave unchanged)', 'minimum' => 0, 'maximum' => 100, 'suffix' => ' %'],
            ],
            'actions' => [
                ['type' => 'Button', 'caption' => 'Test tone', 'onClick' => 'echo EMGD_Beep($id, 1) ?: "OK";'],
            ],
            'status' => [
                ['code' => 201, 'icon' => 'inactive', 'caption' => 'No device ID entered'],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // ------------------------------------------------------------------ Hilfen

    private function control(string $json): string
    {
        return $this->ask('Control', ['Message' => $json]);
    }

    /** @param array<string, mixed> $extra */
    private function ask(string $action, array $extra = []): string
    {
        if (!$this->HasActiveParent()) {
            return 'no gateway connected';
        }
        return (string)$this->SendDataToParent((string)json_encode(
            ['DataID' => self::CHILD_TX, 'DeviceId' => trim($this->ReadPropertyString('DeviceId')), 'Action' => $action] + $extra,
            JSON_UNESCAPED_UNICODE
        ));
    }

    /** @return array<string, mixed> */
    private function option(bool $value, string $caption): array
    {
        return ['Value' => $value, 'Caption' => $caption, 'IconActive' => false, 'IconValue' => '', 'ColorActive' => false,
            'ColorValue' => -1, 'ContentColorActive' => false, 'ContentColorValue' => -1];
    }
}

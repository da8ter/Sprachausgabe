<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/SpeechOutputs.php';
require_once __DIR__ . '/../libs/SpeechAiStore.php';
require_once __DIR__ . '/../libs/SpeechAnnouncements.php';

/**
 * Sprachausgabe Zentrale (Gerät): kennt die Ausgabegeräte, die globalen Schalter (Hauptschalter,
 * Ruhemodus, Lautstärke), die Ansagen (Liste, Trait SpeechAnnouncements) und die Warteschlange.
 * Ansagen kommen aus der eigenen Liste oder aus Skripten (SPAZ_Speak). Gesprochen wird über einen Timer, nicht im
 * Thread des Auslösers: Echo-Aufrufe gehen in die Cloud und dürfen den Auslöser nicht aufhalten.
 */
class SprachausgabeZentrale extends IPSModuleStrict
{
    use SpeechAiStore;
    use SpeechAnnouncements;

    private const QUEUE_MAX = 20;

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyString('Outputs', '[]');
        $this->RegisterPropertyString('Condition', '');
        $this->RegisterPropertyInteger('Cooldown', 30);
        $this->RegisterAttributeString('Queue', '[]');
        $this->RegisterAttributeString('Recent', '{}');
        $this->RegisterAttributeBoolean('Initialized', false);
        $this->RegisterTimer('Process', 0, 'SPAZ_ProcessQueue($_IPS[\'TARGET\']);');
        $this->aiRegister();
        $this->annRegister();

        $this->RegisterVariableBoolean('MASTER', $this->Translate('Announcements'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'ICON_TRUE'    => 'Speaker',
            'ICON_FALSE'   => 'Speaker',
        ], 10);
        $this->EnableAction('MASTER');
        $this->RegisterVariableBoolean('QUIET', $this->Translate('Quiet mode'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'ICON_TRUE'    => 'Moon',
            'ICON_FALSE'   => 'Moon',
        ], 20);
        $this->EnableAction('QUIET');
        $this->RegisterVariableInteger('VOLUME_FACTOR', $this->Translate('Volume'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
            'ICON'         => 'Speaker',
            'SUFFIX'       => ' %',
            'MIN'          => 0,
            'MAX'          => 200,
            'STEP_SIZE'    => 5,
        ], 30);
        $this->EnableAction('VOLUME_FACTOR');
        $this->RegisterVariableString('LAST_TEXT', $this->Translate('Last announcement'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Speech',
        ], 40);
        $this->RegisterVariableInteger('LAST_TIME', $this->Translate('Last announcement at'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_DATE_TIME,
        ], 50);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        if (!$this->ReadAttributeBoolean('Initialized')) {
            // once after creation: announcements on, volume 100 % (Create runs on every load and must not reset them)
            $this->SetValue('MASTER', true);
            $this->SetValue('VOLUME_FACTOR', 100);
            $this->WriteAttributeBoolean('Initialized', true);
        }
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }
        if ($this->annApply()) {
            return; // applied again by the timer, with the ids of new announcements
        }
        $this->SetSummary(sprintf($this->Translate('%d outputs, %d announcements'), count($this->outputs()), count($this->annRows())));
        $this->SetStatus(IS_ACTIVE);
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
            return;
        }
        if ($Message === VM_UPDATE) {
            $this->annMessage($SenderID, $Data);
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        if (str_starts_with($Ident, 'A_')) {
            $this->SetValue($Ident, (bool)$Value); // switch of one announcement
            return;
        }
        switch ($Ident) {
            case 'MASTER':
            case 'QUIET':
                $this->SetValue($Ident, (bool)$Value);
                return;
            case 'VOLUME_FACTOR':
                $this->SetValue($Ident, max(0, min(200, (int)$Value)));
                return;
        }
        throw new Exception($this->Translate('Unknown action') . ': ' . $Ident);
    }

    /**
     * Für Skripte: spricht $text auf den Geräten in $targets (Namen, durch Komma getrennt;
     * leer = die Standardgeräte). $volume 0 = Standardlautstärke des Geräts.
     * Rückgabe: '' wenn eingereiht, sonst der Grund.
     */
    public function Speak(string $text, string $targets, int $volume): string
    {
        $names = array_values(array_filter(array_map('trim', explode(',', $targets)), static fn(string $n): bool => $n !== ''));
        return $this->enqueue($text, $names, $volume, false, '');
    }

    /** Wie Speak, aber auch bei ausgeschaltetem Hauptschalter, im Ruhemodus und ohne Bedingung. */
    public function SpeakUrgent(string $text, string $targets, int $volume): string
    {
        $names = array_values(array_filter(array_map('trim', explode(',', $targets)), static fn(string $n): bool => $n !== ''));
        return $this->enqueue($text, $names, $volume, true, '');
    }

    /** Timer-Ziel: spricht den nächsten Eintrag der Warteschlange. */
    public function ProcessQueue(): void
    {
        $queue = $this->readQueue();
        $item = array_shift($queue);
        $this->WriteAttributeString('Queue', (string)json_encode($queue, JSON_UNESCAPED_UNICODE));
        if (!is_array($item)) {
            $this->SetTimerInterval('Process', 0);
            return;
        }
        $this->deliver($item);
        // next entry after the estimated speaking time, so announcements do not overlap
        $this->SetTimerInterval('Process', $queue === [] ? 0 : max(1500, mb_strlen((string)$item['text']) * 65 + 1000));
    }

    /** Formular: Ansage auf einem Gerät der Liste testen. */
    public function TestOutput(string $name): string
    {
        $output = $this->findOutput($name);
        if ($output === null) {
            return $this->Translate('Output not found');
        }
        $text = $this->Translate('This is a test announcement.');
        if (($output['type'] ?? '') === SpeechOutputs::AI_SCRIPT) {
            $output['audio'] = $this->aiAudio($text);
        } elseif (($output['type'] ?? '') === SpeechOutputs::ECHOMUSE) {
            $output['audio'] = $this->aiAudio($text, true);
        }
        $error = SpeechOutputs::speak($output, $text, $this->volumeFor($output, 0));
        return $error === '' ? $this->Translate('Sent') : $this->Translate('Failed') . ': ' . $error;
    }

    public function GetConfigurationForm(): string
    {
        $typeOptions = [];
        foreach (SpeechOutputs::types() as $type) {
            $typeOptions[] = ['caption' => $this->Translate('type:' . $type), 'value' => $type];
        }
        $names = array_column($this->outputs(), 'name');
        return (string)json_encode([
            'elements' => [
                $this->annFormList(),
                $this->annScheduleButtons(),
                ['type' => 'Label', 'caption' => 'Each announcement has a switch variable below this instance (for the visualization); Active in the list switches it off for good.'],
                ['type' => 'ExpansionPanel', 'caption' => 'Outputs', 'items' => [
                ['type' => 'List', 'name' => 'Outputs', 'caption' => 'Outputs', 'add' => true, 'delete' => true, 'rowCount' => 6,
                    'columns' => [
                        ['caption' => 'Name / room', 'name' => 'name', 'width' => '180px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                        ['caption' => 'Type', 'name' => 'type', 'width' => '200px', 'add' => SpeechOutputs::ECHO_SPEAK, 'edit' => ['type' => 'Select', 'options' => $typeOptions]],
                        ['caption' => 'Device', 'name' => 'instance', 'width' => '220px', 'add' => 0, 'edit' => ['type' => 'SelectInstance']],
                        ['caption' => 'Script', 'name' => 'script', 'width' => '200px', 'add' => 0, 'edit' => ['type' => 'SelectScript']],
                        ['caption' => 'Volume', 'name' => 'volume', 'width' => '90px', 'add' => 40, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 0, 'maximum' => 100, 'suffix' => ' %']],
                        ['caption' => 'Volume variable', 'name' => 'volumeVar', 'width' => '200px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                        ['caption' => 'Default', 'name' => 'default', 'width' => '80px', 'add' => true, 'edit' => ['type' => 'CheckBox']],
                    ]],
                ['type' => 'Label', 'caption' => 'Script outputs receive $_IPS[\'TEXT\'], $_IPS[\'VOLUME\'] and $_IPS[\'TARGET\']; "AI voice" outputs also $_IPS[\'AUDIO_URL\'] and $_IPS[\'AUDIO_FILE\'].'],
                ]],
                ['type' => 'ExpansionPanel', 'caption' => 'Global condition', 'items' => [
                    ['type' => 'Label', 'caption' => 'Applies to every announcement except urgent ones, e.g. "somebody is home".'],
                    ['type' => 'SelectCondition', 'name' => 'Condition', 'multi' => true],
                ]],
                $this->aiFormPanel(),
                ['type' => 'NumberSpinner', 'name' => 'Cooldown', 'caption' => 'Same announcement at most every', 'suffix' => ' s', 'minimum' => 0],
            ],
            'actions' => [
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Select', 'name' => 'TestTarget', 'caption' => 'Output', 'options' => array_map(static fn(string $n): array => ['caption' => $n, 'value' => $n], $names ?: [''])],
                    ['type' => 'Button', 'caption' => 'Test', 'onClick' => 'echo SPAZ_TestOutput($id, $TestTarget);'],
                ]],
            ],
            'status' => [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // ------------------------------------------------------------------ internals

    /** @param array<int, string> $targets */
    private function enqueue(string $text, array $targets, int $volume, bool $urgent, string $key): string
    {
        $text = trim($text);
        if ($text === '') {
            return 'empty text';
        }
        if (!$urgent) {
            if (!$this->GetValue('MASTER')) {
                return $this->skip('announcements are switched off', $text);
            }
            if ($this->GetValue('QUIET')) {
                return $this->skip('quiet mode', $text);
            }
            if (!$this->conditionPassing($this->ReadPropertyString('Condition'))) {
                return $this->skip('global condition not met', $text);
            }
        }
        $key = $key !== '' ? $key : md5($text);
        $recent = json_decode($this->ReadAttributeString('Recent'), true) ?: [];
        $now = time();
        $cooldown = max(0, $this->ReadPropertyInteger('Cooldown'));
        if ($cooldown > 0 && isset($recent[$key]) && $now - (int)$recent[$key] < $cooldown) {
            return $this->skip('same announcement within the cooldown', $text);
        }
        $recent = array_filter($recent, static fn($t): bool => $now - (int)$t < max(3600, $cooldown));
        $recent[$key] = $now;
        $this->WriteAttributeString('Recent', (string)json_encode($recent));

        $queue = $this->readQueue();
        if (count($queue) >= self::QUEUE_MAX) {
            return $this->skip('queue full', $text);
        }
        $queue[] = ['text' => $text, 'targets' => $targets, 'volume' => $volume];
        $this->WriteAttributeString('Queue', (string)json_encode($queue, JSON_UNESCAPED_UNICODE));
        if ($this->GetTimerInterval('Process') === 0) {
            $this->SetTimerInterval('Process', 100); // arm only when idle; re-arming would postpone it
        }
        return '';
    }

    /** @param array<string, mixed> $item */
    private function deliver(array $item): void
    {
        $text = (string)$item['text'];
        $outputs = $this->selectOutputs((array)($item['targets'] ?? []));
        if ($outputs === []) {
            $this->LogMessage(sprintf('%s: %s', $this->Translate('No output for announcement'), $text), KL_WARNING);
            return;
        }
        $audio = null;
        $wavAudio = null;
        foreach ($outputs as $output) {
            if (($output['type'] ?? '') === SpeechOutputs::ECHOMUSE) {
                $wavAudio ??= $this->aiAudio($text, true); // PCM für den Dot: WAV beim Anbieter anfordern
                $output['audio'] = $wavAudio;
            }
            if (($output['type'] ?? '') === SpeechOutputs::AI_SCRIPT) {
                $audio ??= $this->aiAudio($text); // one recording for every AI output of this announcement
                $output['audio'] = $audio;
            }
            $error = SpeechOutputs::speak($output, $text, $this->volumeFor($output, (int)($item['volume'] ?? 0)));
            if ($error !== '') {
                $this->LogMessage(sprintf('%s (%s): %s', $this->Translate('Announcement failed'), (string)$output['name'], $error), KL_WARNING);
            }
            $this->SendDebug('Speak', sprintf('%s → %s %s', $text, (string)$output['name'], $error), 0);
        }
        $this->SetValue('LAST_TEXT', $text);
        $this->SetValue('LAST_TIME', time());
    }

    /** @return array<int, array<string, mixed>> */
    private function outputs(): array
    {
        $list = json_decode($this->ReadPropertyString('Outputs'), true);
        return is_array($list) ? array_values(array_filter($list, static fn($o): bool => is_array($o) && trim((string)($o['name'] ?? '')) !== '')) : [];
    }

    /** @return array<string, mixed>|null */
    private function findOutput(string $name): ?array
    {
        foreach ($this->outputs() as $output) {
            if (strcasecmp(trim((string)$output['name']), trim($name)) === 0) {
                return $output;
            }
        }
        return null;
    }

    /**
     * @param array<int, string> $targets
     * @return array<int, array<string, mixed>>
     */
    private function selectOutputs(array $targets): array
    {
        if ($targets === []) {
            return array_values(array_filter($this->outputs(), static fn(array $o): bool => (bool)($o['default'] ?? false)));
        }
        $out = [];
        foreach ($targets as $name) {
            $output = $this->findOutput($name);
            if ($output !== null) {
                $out[] = $output;
            } else {
                $this->LogMessage(sprintf('%s: %s', $this->Translate('Unknown output'), $name), KL_WARNING);
            }
        }
        return $out;
    }

    /** Lautstärke: eigene der Ansage, sonst die des Geräts (Variable vor festem Wert), beides mal dem globalen Faktor. */
    private function volumeFor(array $output, int $requested): int
    {
        $volumeVar = (int)($output['volumeVar'] ?? 0);
        $deviceVolume = $volumeVar > 0 && @IPS_VariableExists($volumeVar) ? (int)GetValue($volumeVar) : (int)($output['volume'] ?? 0);
        $base = $requested > 0 ? $requested : $deviceVolume;
        if ($base <= 0) {
            return 0; // device keeps its own volume
        }
        return max(1, min(100, (int)round($base * $this->GetValue('VOLUME_FACTOR') / 100)));
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

    /** @return array<int, array<string, mixed>> */
    private function readQueue(): array
    {
        $queue = json_decode($this->ReadAttributeString('Queue'), true);
        return is_array($queue) ? array_values($queue) : [];
    }
}

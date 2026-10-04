<?php

declare(strict_types=1);

/**
 * Sprachrunden im Gateway: Der Dot erkennt sein Wakeword selbst (Private Listening), meldet
 * oww_wake mit einer Sitzungsnummer und schickt danach das Mikrofon als 0x07-Rahmen. Das Gateway
 * quittiert, legt das Mikrofon in eine Spool-Datei und meldet dem Voice-Modul per Variable, dass
 * Ton da ist; die Antwort kommt als Spool-Datei zurück und wird sofort abgespielt. Die beiden
 * Instanzen rufen sich nie gegenseitig auf (sie würden einander blockieren): Ton liegt in
 * Dateien, Meldungen laufen über Variablenänderungen, die Symcon asynchron zustellt.
 */
trait EmGatewayVoice
{
    private function voiceEnabled(): bool
    {
        $id = $this->ReadPropertyInteger('VoiceInstance');
        return $id > 0 && @IPS_InstanceExists($id);
    }

    /** @return array<int, string> */
    private function ackFeatures(): array
    {
        return $this->voiceEnabled() ? array_merge(EmProtocol::FEATURES, ['listen_session']) : EmProtocol::FEATURES;
    }

    /** Nach der Anmeldung: Wakeword auf dem Gerät, Signalton bei erkanntem Wakeword. */
    private function configureVoice(string $id): void
    {
        if ($this->voiceEnabled()) {
            $this->sendControl($id, EmProtocol::config(['owwOnDevice' => 'on', 'wakeSound' => $this->ReadPropertyBoolean('WakeSound')]));
        }
    }

    /** @param array<string, mixed> $msg */
    private function onWake(string $id, array $msg): void
    {
        if (!$this->voiceEnabled() || !isset($msg['session'])) {
            return;
        }
        $session = (int)$msg['session'];
        $voice = $this->voiceState();
        if ($voice !== [] && $voice['dev'] !== $id) {
            $this->sendControl($id, EmProtocol::listenClose($session, 'busy')); // ein zweiter Dot, solange der erste spricht
            return;
        }
        if ($voice !== []) { // derselbe Dot weckt erneut (Barge-in): alte Antwort abbrechen
            $this->flushPlayback($id);
            $this->voiceCommand(['cmd' => 'stop', 'dev' => $id]);
        }
        EmSpool::clear('mic_' . $id);
        EmSpool::clear('out_' . $id);
        $this->saveVoiceState(['dev' => $id, 'session' => $session, 'outOff' => 0, 'closed' => false, 'mic' => 0]);
        $this->sendControl($id, EmProtocol::listenAck($session));
        $this->voiceCommand(['cmd' => 'start', 'dev' => $id, 'session' => $session]);
    }

    /** Mikrofon-Rahmen 0x07 [Sitzung u32 BE][Folge u16 BE][PCM 16 kHz] vom /data-Socket des Dots. */
    private function onSessionAudio(string $ip, string $data): void
    {
        if (strlen($data) < 8) {
            return;
        }
        $head = unpack('Nsession/nseq', substr($data, 1, 6));
        $voice = $this->voiceState();
        if ($voice === [] || (int)$head['session'] !== (int)$voice['session'] || $this->deviceByIp($ip) !== $voice['dev']) {
            return; // Rahmen einer beendeten oder fremden Sitzung
        }
        if (!EmSpool::append('mic_' . $voice['dev'], substr($data, 7))) {
            return;
        }
        $voice['mic'] = (int)$voice['mic'] + 1;
        $this->saveVoiceState($voice);
        $this->SetValue('VOICE_MIC', (int)$voice['mic']);
    }

    /** Meldung des Voice-Moduls (Variable EVENT). */
    private function onVoiceEvent(string $json): void
    {
        $e = json_decode($json, true);
        $voice = $this->voiceState();
        if (!is_array($e) || $voice === [] || (string)($e['dev'] ?? '') !== $voice['dev'] || (int)($e['session'] ?? 0) !== (int)$voice['session']) {
            return;
        }
        $id = (string)$voice['dev'];
        switch ((string)$e['ev']) {
            case 'speech_end':
                $this->closeListen($id, $voice, 'end_of_speech');
                break;
            case 'audio':
                [$pcm, $off] = EmSpool::read('out_' . $id, (int)$voice['outOff']);
                if ($pcm !== '') {
                    $voice['outOff'] = $off;
                    $this->saveVoiceState($voice);
                    $samples = array_values((array)unpack('s*', substr($pcm, 0, strlen($pcm) - (strlen($pcm) % 2))));
                    $this->queuePcm($id, EmPcm::pack(EmPcm::resample($samples, EmRealtime::RATE, EmPcm::RATE)), true);
                }
                break;
            case 'finished':
            case 'error':
            case 'busy':
                $this->closeListen($id, $voice, (string)$e['ev']);
                $this->endStream($id);
                EmSpool::clear('out_' . $id);
                $this->saveVoiceState([]);
                break;
        }
    }

    /** @param array<string, mixed> $voice */
    private function closeListen(string $id, array $voice, string $reason): void
    {
        if (empty($voice['closed'])) {
            $voice['closed'] = true;
            $this->saveVoiceState($voice);
            $this->sendControl($id, EmProtocol::listenClose((int)$voice['session'], $reason));
        }
    }

    /** Ein Dot ist weg: seine Sitzung endet. */
    private function stopVoice(string $id): void
    {
        $voice = $this->voiceState();
        if ($voice !== [] && $voice['dev'] === $id) {
            $this->voiceCommand(['cmd' => 'stop', 'dev' => $id]);
            EmSpool::clear('mic_' . $id);
            EmSpool::clear('out_' . $id);
            $this->saveVoiceState([]);
        }
    }

    /** @param array<string, mixed> $cmd */
    private function voiceCommand(array $cmd): void
    {
        $cmd['n'] = (int)($this->GetBuffer('VoiceN') ?: 0) + 1; // jede Meldung ist anders, sonst gäbe es keine Aktualisierung
        $this->SetBuffer('VoiceN', (string)$cmd['n']);
        $this->SetValue('VOICE_CMD', (string)json_encode($cmd));
    }

    private function deviceByIp(string $ip): string
    {
        foreach ($this->devices() as $id => $d) {
            if ($d['ip'] === $ip) {
                return (string)$id;
            }
        }
        return '';
    }

    /** @return array<string, mixed> */
    private function voiceState(): array
    {
        $s = json_decode($this->GetBuffer('Voice'), true);
        return is_array($s) ? $s : [];
    }

    /** @param array<string, mixed> $s */
    private function saveVoiceState(array $s): void
    {
        $this->SetBuffer('Voice', (string)json_encode($s));
    }
}

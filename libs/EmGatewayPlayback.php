<?php

declare(strict_types=1);

/**
 * Wiedergabe auf dem Lautsprecher des Dots: Ton wird in Perioden zu 42,7 ms als 0x02-Rahmen
 * geschickt, höchstens EmPcm::LEAD_SECONDS der Echtzeit voraus (sonst blockiert das Gerät seinen
 * Lesevorgang und beantwortet keine Pings mehr), am Ende folgt 0x03. Ein Timer füllt nach.
 */
trait EmGatewayPlayback
{
    // ------------------------------------------------------------------ Wiedergabe

    /** Hängt Ton an die Wiedergabe des Geräts an; den Rest übernimmt der Pump-Timer. */
    private function queuePcm(string $id, string $pcm, bool $open = false): string
    {
        if ($this->dataConnection($id) === null) {
            return 'device has no audio connection';
        }
        $play = $this->plays();
        if (isset($play[$id])) {
            $rest = (string)base64_decode($play[$id]['pcm'], true);
            $play[$id]['pcm'] = base64_encode(substr($rest, (int)$play[$id]['pos']) . $pcm);
            $play[$id]['sentBase'] = (float)$play[$id]['sent'];
            $play[$id]['pos'] = 0;
            $play[$id]['open'] = $open || !empty($play[$id]['open']);
        } else {
            $play[$id] = ['pcm' => base64_encode($pcm), 'pos' => 0, 'start' => EmClock::now(), 'sent' => 0.0, 'sentBase' => 0.0, 'open' => $open];
        }
        $this->savePlays($play);
        $this->pumpDevices();
        $this->SetTimerInterval('Pump', 250);
        return '';
    }

    public function Pump(): void
    {
        $this->pumpDevices();
        if ($this->plays() === []) {
            $this->SetTimerInterval('Pump', 0);
        }
    }

    private function pumpDevices(): void
    {
        $play = $this->plays();
        foreach ($play as $id => $p) {
            $conn = $this->dataConnection($id);
            if ($conn === null) {
                unset($play[$id]);
                $this->LogMessage(sprintf('%s: %s', $this->Translate('Audio connection lost during playback'), $id), KL_WARNING);
                continue;
            }
            $pcm = (string)base64_decode($p['pcm'], true);
            $len = strlen($pcm);
            $pos = (int)$p['pos'];
            $sent = (float)$p['sent'];
            $elapsed = EmClock::now() - (float)$p['start'];
            if ($sent < $elapsed) { // Unterlauf (die Antwort kam langsamer als sie gespielt wird): Zählung neu ansetzen, sonst entfällt die Bremse
                $p['start'] = EmClock::now() - $sent;
                $play[$id]['start'] = $p['start'];
                $elapsed = $sent;
            }
            $out = '';
            while ($pos < $len && $sent - $elapsed < EmPcm::LEAD_SECONDS) {
                $chunk = substr($pcm, $pos, EmPcm::PERIOD_BYTES);
                if (strlen($chunk) < EmPcm::PERIOD_BYTES) {
                    $chunk = str_pad($chunk, EmPcm::PERIOD_BYTES, "\0");
                }
                $out .= EmWebSocket::binary(EmProtocol::speakerFrame($chunk));
                $pos += EmPcm::PERIOD_BYTES;
                $sent += EmPcm::PERIOD_SECONDS;
            }
            if ($pos >= $len && !empty($p['open'])) {
                $play[$id]['pos'] = $pos; // die Antwort wird noch erzeugt: kein Ende, weiter warten
                $play[$id]['sent'] = $sent;
            } elseif ($pos >= $len) {
                $out .= EmWebSocket::binary(EmProtocol::speakerEnd());
                unset($play[$id]);
            } else {
                $play[$id]['pos'] = $pos;
                $play[$id]['sent'] = $sent;
            }
            if ($out !== '') {
                $this->sendRaw($conn['ip'], $conn['port'], $out);
            }
        }
        $this->savePlays($play);
    }

    /** Die Antwort ist vollständig: nach dem restlichen Ton folgt das Ende. */
    private function endStream(string $id): void
    {
        $play = $this->plays();
        if (isset($play[$id])) {
            $play[$id]['open'] = false;
            $this->savePlays($play);
            $this->pumpDevices();
        }
    }

    /** Wiedergabe sofort abbrechen (Barge-in oder neue Sprachrunde). */
    private function flushPlayback(string $id): void
    {
        $play = $this->plays();
        if (isset($play[$id])) {
            unset($play[$id]);
            $this->savePlays($play);
            $this->sendControl($id, EmProtocol::speakerFlush());
        }
    }
}

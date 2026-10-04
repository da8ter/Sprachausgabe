<?php

declare(strict_types=1);

/** Zustand des EchoMuse Gateways in Buffern der Instanz: Verbindungen, angemeldete Geräte, laufende Wiedergabe. */
trait EmGatewayState
{
    /** @return array<string, array<string, mixed>> */
    private function conns(): array
    {
        return $this->loadMap('Conns');
    }

    /** @param array<string, array<string, mixed>> $conns */
    private function saveConns(array $conns): void
    {
        $this->SetBuffer('Conns', (string)json_encode($conns));
    }

    /** @return array<string, array<string, mixed>> */
    private function devices(): array
    {
        return $this->loadMap('Devices');
    }

    /** @param array<string, array<string, mixed>> $devices */
    private function saveDevices(array $devices): void
    {
        $this->SetBuffer('Devices', (string)json_encode($devices));
    }

    /** @return array<string, array<string, mixed>> */
    private function plays(): array
    {
        return $this->loadMap('Plays');
    }

    /** @param array<string, array<string, mixed>> $plays */
    private function savePlays(array $plays): void
    {
        $this->SetBuffer('Plays', (string)json_encode($plays));
    }

    /** @return array<string, array<string, mixed>> */
    private function loadMap(string $name): array
    {
        $map = json_decode($this->GetBuffer($name), true);
        return is_array($map) ? $map : [];
    }
}

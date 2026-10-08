# EchoMuse Gateway und Gerät

Echo Dots der **2. Generation** mit der freien Firmware [EchoMuse](https://github.com/wilbowes/EchoMuse) wählen sich bei einem Controller ein. Diese Bibliothek macht Symcon selbst zum Controller, ohne Home Assistant: `EchoMuseGateway` (EMGW, Splitter) spricht den Gerätelink, je Dot gibt es ein `EchoMuseGeraet` (EMGD). Neuere Echo-Generationen (Dot 4, Dot 5) und Echo Show laufen nicht mit EchoMuse (nicht am Code prüfbar).

## Entscheidungen

- **Symcon ist der Controller, die Firmware bleibt unverändert** (Nutzerentscheid 04.10.2026, nicht am Code prüfbar). Der Dot braucht nur die Controller-Adresse in `/data/local/etc/echomuse/controller.json` (ab Firmware 2.16.0; mDNS gibt es noch nicht).
- **Eigener WebSocket-Server auf einem Server Socket.** Symcon 9.1 hat keinen WebSocket-Server-I/O, nur den Server Socket (gemessen 04.10.2026, nicht am Code prüfbar). Handshake, Rahmen, Ping/Pong und Close macht `libs/EmWebSocket.php`; das Gateway führt je Client (IP:Port) einen Zustand `http` → `ws`. Pfade: `/control` (JSON) und `/data` (binär). Die `/data`-Verbindung eines Geräts wird über die gleiche IP wie sein `/control` zugeordnet.
- **Datenfluss:** `parentRequirements` = Server-Socket-Schnittstelle, `implemented` = deren Empfangs-GUID. So liefert der Socket `ClientIP`, `ClientPort`, `Type` (1 verbunden, 0 Daten, 2 getrennt) und `Buffer`.
- **Empfang als HEX:** als `IPSModuleStrict`-Kind bekommt das Gateway `Buffer` hex-kodiert und wandelt mit `hex2bin` (Plattformwissen `module-strict-und-php.md`).
- **Senden über `SSCK_SendPacket`, nicht über den Datenfluss.** `SendDataToParent` an den Server Socket lieferte in diesem Modul nichts aus; `SSCK_SendPacket($socket, $bytes, $ip, $port)` nimmt Binärdaten roh an (gemessen 04.10.2026: `81 02 48 69 00 FF` kam unverändert an).
- **Trennen gibt es nicht.** Der Server Socket hat keine Funktion zum Schließen einer Client-Verbindung; das Gateway schickt einen Close-Rahmen (oder HTTP 400) und vergisst die Verbindung, der Dot schließt selbst.
- **Anmeldung mit Freigabe:** `register` eines unbekannten Geräts → `pending`, Eintrag in der Warteliste, Meldung im Log, Verbindung zu. Freigeben im Formular (`EMGW_ApproveDevice`) legt die Geräte-Instanz an und verbindet sie. „Jedes Gerät annehmen“ ist aus und nur fürs Heimnetz gedacht: die Gerätekennung ist die einzige Identität, es gibt noch kein TLS und kein Token. Eine neue Anmeldung desselben Geräts ersetzt die alte Verbindung.
- **Lebenszeichen:** alle 20 s ein Ping an jede Verbindung; nach 60 s ohne Lebenszeichen Close und vergessen; Verbindungen ohne fertigen Handshake nach 10 s.
- **Zustand in Instanz-Puffern** (`Conns`, `Devices`, `Plays`), Freigaben und Warteliste in Attributen (überleben den Neustart). Verpasst das Gateway die Verbunden-Meldung (eigener Neustart), trägt es die Verbindung beim ersten Datenpaket nach.

## Protokoll-Eigenheiten

- **Lautsprecher:** mono S16LE 48 kHz in Perioden zu 4096 Byte (2048 Samples, 42,7 ms) als Rahmen `0x02`, Ende mit `0x03`. Eine Ansage ist auf 60 s begrenzt.
- **Höchstens 3 s der Echtzeit voraus senden.** Das Gerät puffert etwa 5,5 s; läuft man weiter voraus, blockiert es seinen Lesevorgang und beantwortet keine Pings mehr. Ein Pump-Timer (250 ms) füllt nach und steht still, wenn nichts spielt.
- **Gerätelautstärke 0–127** in 0,5-dB-Schritten, 127 = Einheitsverstärkung; darüber übersteuert der Wandler. Die Geräte-Instanz rechnet Prozent um.
- **Ton erzeugt der Anbieter als WAV**, `EmPcm` liest WAV und rechnet selbst auf 48 kHz um (siehe `ki-stimme.md`). In der Zentrale heißt die Ausgabeart „EchoMuse-Dot (KI-Stimme)“.

## Fallen / gemessen

- **`IPS_CreateInstance` legt den Eltern-Socket nicht selbst an**; `ConnectParent` in `Create` wirkte nur beim Anlegen über die Konsole (beobachtet 04.10.2026, nicht am Code prüfbar). Für Skript- oder Prüfstandsaufbau den Server Socket selbst anlegen und verbinden. Zum Widerspruch mit dem Plattformwissen siehe `../stand.md`.
- **Mikrofonrahmen** `0x07` = `[0x07][Sitzung u32 BE][Folge u16 BE][PCM 16 kHz]` gehören zur Sprachrunde, siehe `echomuse-voice.md`.

Stand: geprüft gegen den Code am 08.10.2026

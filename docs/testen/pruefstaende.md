# Prüfstände und Live-Proben

## Prüfstände (ohne Symcon)

| Aufruf | Was | Braucht |
|---|---|---|
| `php tests/echomuse_lib_test.php` | reine Bausteine: WebSocket-Rahmen und Handshake, Gerätelink-Nachrichten, PCM/WAV, Realtime-Ereignisse, SymDo-Client | nichts |
| `php tests/smoke_test.php` | Zentrale und Ansage: Auslöser, Bedingungen, Warteschlange, Sperrfrist, Ausgabearten, KI-Anbieter (Adressen, Kopfzeilen, Format) | Prüfstand-Kernel `../LGThinQ/tests/bootstrap.php` |
| `php tests/echomuse_test.php` | Gateway, Gerät und Voice: Attrappen-Dot über einen Attrappen-Server-Socket, Freigabe, Wiedergabe, Sprachrunde | Prüfstand-Kernel wie oben |

- Der Prüfstand-Kernel liegt in der LG-ThinQ-Bibliothek (Symcon 9.1 im Speicher, inklusive Darstellungsregeln und Zustellung von `VM_UPDATE` über eine Warteschlange). Fehlt der Nachbarordner, brechen die beiden Prüfstände mit einer Meldung ab.
- Fremde Module (Echo Remote, Fully Kiosk, Skripte, `SSCK_SendPacket`, `CSCK_SendText`) sind Attrappen, die jeden Aufruf mitschreiben; Netzaufrufe laufen über austauschbare Transporte (`SpeechAi::$transport`, `SymDoVoiceClient::$transport`).
- Jeder Prüfstand endet mit „Alle N Prüfungen bestanden.“ und Exit-Code 0. Ein Prüfstand, der grün bleibt, obwohl der Fix fehlt, prüft das Falsche: Gegenprobe machen.

## Live-Probe am Testsystem (Rezept)

Für Gateway und Voice reicht der Prüfstand nicht, wenn es um Takt, Socket-Verhalten oder echte Anbieter geht. Bewährt (04.10.2026):

1. **Server Socket selbst anlegen** (Port wie im Gateway, Vorgabe 8767) und mit dem Gateway verbinden; `IPS_CreateInstance` legt ihn nicht an.
2. **Attrappen-Dot** (kleines Skript, nicht im Repo): verbindet `/control` und `/data`, meldet sich mit `register` an, beantwortet Pings, zählt `0x02`/`0x03`-Rahmen. Für die Sprachrunde schickt er `oww_wake` und danach eine 16-kHz-PCM-Datei als `0x07`-Rahmen **im Echtzeittakt**. Testsatz erzeugen, z. B. auf macOS mit `say -v Anna` und `afconvert -d LEI16@16000` (16 kHz, 16 Bit, little endian); der Attrappen-Dot schickt die Bytes so, wie sie in der Datei stehen.
3. **Gerät freigeben** über das Gateway-Formular, Geräte-Instanz prüfen.
4. **Sprachrunde ohne Kosten:** Attrappe für Realtime (WebSocket) und den SymDo-Sprachweg (HTTP) auf dem Entwicklungsrechner; im Voice-Modul Host, Port und TLS auf die Attrappe stellen.
5. **Echter Lauf:** echtes SymDo (frisch gekoppeltes Gerät → Bearer-Token) und echtes OpenAI mit TLS. Steht SymDo auf GPT-Live, für die Probe auf ein Realtime-Modell stellen und **danach zurückstellen**.
6. Ergebnis über Debug-Ausgaben von Gateway und Voice prüfen (Verbindung, Mikrofon-Bytes, `listen_close`, Werkzeug, Antwortperioden, `0x03`).

Probe-Instanzen nach dem Test entfernen oder deaktivieren; Token aus der Probe nicht in Dateien des Repos übernehmen.

## Offen

- Kein Lauf mit einem echten Dot (nicht am Code prüfbar).

Stand: geprüft gegen den Code am 08.10.2026

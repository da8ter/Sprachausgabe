# EchoMuse Voice: Sprachgespräche mit dem Dot

`EchoMuseVoice` (EMVS) führt eine Sprachrunde zwischen einem Dot und der Realtime-Schnittstelle von OpenAI. Anweisungen, Werkzeuge (Listen, Termine, Geräte …) und Sprechzeit-Grenzen kommen von **SymDo**; der OpenAI-Schlüssel bleibt dort. Das Modul hängt an einem Client Socket.

## Entscheidungen

- **Wakeword auf dem Dot** (`owwOnDevice: on`, Private Listening): bis zum Wakeword verlässt kein Ton das Gerät. Das Gateway schaltet das nach der Anmeldung ein, wenn ein Voice-Modul gewählt ist, und meldet dann das Merkmal `listen_session` im `ack`. Das Wakeword-Modell installiert die EchoMuse-Einrichtung, nicht dieses Modul.
- **Ablauf:** `oww_wake` (mit Sitzungsnummer) → `listen_ack` → Mikrofon als `0x07`-Rahmen (16 kHz) → Voice → OpenAI meldet `speech_stopped` → Gateway `listen_close` → Werkzeugaufrufe über SymDo → Antwort-Audio (24 kHz) als **offener Strom** zum Dot, der erst nach der letzten `response.done` endet.
- **Gestreamt statt getrennter Erkennung und Stimme:** kein Whisper-Umweg und keine fertige Tondatei; das erste Antwort-Audio liegt rund eine Sekunde nach Sprachende vor (gemessen 04.10.2026 mit echtem SymDo, echtem OpenAI und Attrappen-Dot, nicht am Code prüfbar).
- **SymDo prägt den Zugangsschlüssel:** `POST <SymDo-Hook>/v1/voice` mit `action` = `open` | `opened` | `tool` | `close`, Bearer-Token eines gekoppelten SymDo-Geräts und Nutzerkennung. Sitzung, Werkzeuge und Zuhörsteuerung stecken im Schlüssel; das Modul verbindet nur `wss://api.openai.com/v1/realtime?model=…` und spricht. Der Schlüssel lebt nur im Sitzungspuffer.
- **Nur Realtime-Modelle**, nicht GPT-Live: GPT-Live läuft über WebRTC. Meldet SymDo `live: true`, bricht die Sitzung mit einem klaren Hinweis ab.
- **Feste Kachelkennung je Dot** oberhalb des Bereichs echter Objekt-IDs, damit die SymDo-Regel „ein Gespräch je Kachel“ je Dot greift (wie bei den SymDo-Sprachgeräten).
- **Eine Sitzung gleichzeitig.** Ein zweiter Dot bekommt `busy`. Weckt derselbe Dot erneut, wird die laufende Antwort abgebrochen und eine neue Runde beginnt.
- **Sicherheitsnetze:** Sitzung endet nach der eingestellten Höchstdauer (Vorgabe 60 s) oder wenn der Verbindungsaufbau 15 s hängt.

## Entkopplung von Gateway und Voice

- **Die beiden Instanzen rufen sich nie gegenseitig auf.** Symcon arbeitet je Instanz nacheinander ab; zwei Instanzen, die sich gleichzeitig synchron aufrufen, blockieren einander (Plattformwissen `instanzen-und-nebenlaeufigkeit.md`).
- **Ton liegt in Spool-Dateien** (`libs/EmSpool.php`, im Symcon-Medienordner unter `echomuse/`): `mic_<Gerät>.pcm` schreibt das Gateway, `out_<Gerät>.pcm` schreibt Voice; jeder Leser merkt sich seine Position. Höchstens 8 MiB je Datei.
- **Meldungen laufen über Variablen** (`VOICE_CMD`, `VOICE_MIC` am Gateway, `EVENT` an Voice) und `RegisterMessage(VM_UPDATE)`, die Symcon asynchron zustellt. Jede Meldung trägt einen Zähler, damit auch eine gleiche Meldung eine Aktualisierung auslöst.
- Ein Werkzeugaufruf an SymDo blockiert nur die Voice-Instanz kurz; das Gateway spielt weiter.

## Fallen / gemessen (04.10.2026, echter Lauf mit Attrappen-Dot)

- **Mikrofon-Spool beim Öffnen nicht leeren.** Das Gateway schreibt ab dem Wakeword hinein; Voice baut die Verbindung erst danach auf (~1 s). Leerte Voice die Datei beim Öffnen, fehlte der Satzanfang (Transkript „Ist“ statt „Wie spät ist es?“). Geleert wird nur im Gateway beim Wecken (Commit 22ab440).
- **Kein Lesen-Ändern-Schreiben eines gemeinsamen Puffer-Blobs aus zwei Einstiegspunkten.** `pumpMic` läuft aus `MessageSink` und schrieb den ganzen Sitzungspuffer zurück; parallel wuchs der Empfangspuffer aus `ReceiveData`. Folge: verlorene 4096-Byte-Blöcke, „unknown opcode“/„bad control frame“, zufällig etwa jeder zweite Lauf mit Werkzeug. Abhilfe: der Mikrofon-Versatz steht im eigenen Puffer `MIC`; danach 5 von 5 Läufen sauber (Commit 22ab440). Beobachtung: `MessageSink` und `ReceiveData` derselben Instanz liefen hier überlappend (nicht am Code prüfbar).
- **Client Socket liefert ebenfalls HEX** (Module Strict); gesendet wird mit `CSCK_SendText` (Binärdaten roh).
- Der Takt der Attrappe muss echt sein: ohne Warten auf den Socket beim Streamen lief die Probe etwa 2,5-fach zu langsam.

Stand: geprüft gegen den Code am 08.10.2026

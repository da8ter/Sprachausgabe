# KI-Stimme

Die Zentrale kann Texte bei einem Sprachanbieter als Tondatei erzeugen lassen (Ausgabeart „KI-Stimme“ mit Skript und „EchoMuse-Dot“). Code: `libs/SpeechAi.php` (rein, ohne Symcon) und Trait `libs/SpeechAiStore.php` (Einstellungen, Zwischenspeicher, Hook).

## Entscheidungen

- **Dieselben fünf Anbieter wie SymDo** (OpenAI, Microsoft Azure, ElevenLabs, Amazon Polly, Google Gemini). Die Aufrufe folgen dem dort gemessenen Stand (`SymDoGateway/libs/Tts.php` der SymDo-Bibliothek), samt Fallen; Polly wird mit eigener SigV4-Signatur angesprochen (`libs/AwsSigV4.php`), ohne AWS-Bibliothek.
- **Jeder Text wird nur einmal bezahlt:** eine Datei je Kombination aus Anbieter, Modell/Stimme (bei OpenAI und Gemini auch Sprechstil), Dateiformat und Text. Dateiname ist der SHA-256 daraus. Ablage im Symcon-Medienordner je Zentrale; höchstens 300 Dateien, die zuletzt benutzten bleiben (ein Treffer frischt das Änderungsdatum auf).
- **Abspieler holen die Datei über den Hook `/hook/sprachausgabe/<sha256>.<mp3|wav>`.** Ausgeliefert wird nur, was genau diesem Muster entspricht und im Zwischenspeicher liegt; der Name ist nicht zu erraten. Weil der Name der Inhalts-Hash ist, darf der Hook `immutable` senden.
- **`RegisterHook('sprachausgabe')` ohne `/hook/`**: mit Präfix meldete Symcon „Hook not found“ (Commit 47cee13; Plattformwissen `hooks-und-grenzen.md`).
- **Größenriegel vor dem Ablegen:** ist die Datei größer als die Hook-Ausgabegrenze (`ScriptOutputBufferLimit`, Rückfall 1 MiB), wird sie gar nicht gespeichert und die Ansage meldet „Text kürzen“. Symcon würde die Antwort sonst bei HTTP 200 durch einen Fehlertext ersetzen (Plattformwissen `hooks-und-grenzen.md`). Eine Stückelung wie in SymDo gibt es hier nicht.
- **Adresse für Abspieler:** Einstellung „Adresse von Symcon“, leer = erste LAN-IPv4 des Symcon-Rechners mit Port 3777. Ein Abspieler im LAN muss diese Adresse erreichen.
- **WAV für den Dot direkt vom Anbieter**, weil auf den geprüften Symcon-Systemen weder `ffmpeg` noch `sox` vorhanden war (nicht am Code prüfbar). OpenAI und Azure liefern WAV, ElevenLabs (`pcm_24000`) und Polly (PCM 16 kHz) liefern rohes PCM, das `EmPcm::wrapWav` mit einem Kopf versieht; Gemini liefert immer WAV (Base64 im JSON).
- **Text im SSML maskiert** (Azure, Polly): ein `<` im Ansagetext darf das SSML nicht brechen.
- **Schlüssel als `PasswordTextBox`**: maskiert nur die Anzeige, der Wert liegt im Klartext in `settings.json` (Plattformwissen `daten-und-sicherheit.md`). Bewusst wie in SymDo belassen.

## ElevenLabs-Fallen

- **`output_format` gehört in die Adresse** (`/v1/text-to-speech/{voice_id}?output_format=…`). Im JSON-Rumpf wird es stillschweigend ignoriert und es gilt die Vorgabe `mp3_44100_128`. In SymDo gemessen: rund 4,5-fache Dateigröße (1257 statt 279 Byte je Zeichen), was bei langen Texten die Ausgabegrenze reißt (nicht am Code prüfbar). Der Code setzt `mp3_44100_64` bzw. `pcm_24000` in die Adresse; `tests/smoke_test.php` prüft die Adresse.
- **Das wirkliche Format muss in den Schlüssel des Zwischenspeichers**, sonst liefert er nach einer Formatänderung dauerhaft die alte Aufnahme. Hier steckt nur `mp3`/`wav` im Schlüssel, nicht der genaue Anbieter-Formatname – siehe `../stand.md`.
- Die Stimmen-ID ist ein freies Feld; im öffentlichen Repo steht nur die Vorgabestimme von ElevenLabs, nie die ID einer eigenen oder kopierten Stimme.

Stand: geprüft gegen den Code am 08.10.2026

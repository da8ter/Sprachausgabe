# Sprachausgabe (Bibliothek für Symcon)

Ansagen für Symcon: Auslöser, Bedingung, Text, ein oder mehrere Ausgabegeräte; dazu eine KI-Stimme und die Anbindung von Echo Dots der 2. Generation mit der Firmware EchoMuse (Ansagen und Sprachgespräche über SymDo). Öffentliches Repo `da8ter/Sprachausgabe`, Zweig `main`.

Projektwissen (Entscheidungen, Protokoll-Eigenheiten, Test-Rezepte): **`.claude/docs/README.md`**. Offenes und bekannte Widersprüche: `.claude/docs/stand.md`. Betriebsdaten dieses Rechners stehen in `CLAUDE.local.md` (nicht eingecheckt).

## Aufbau

- **`SprachausgabeZentrale/`** (SPAZ, Splitter): Ausgabegeräte, globale Bedingung, Sperrfrist, Warteschlange, KI-Stimme (Trait `libs/SpeechAiStore.php`, Hook `sprachausgabe`).
- **`SprachausgabeAnsage/`** (SPAA, Gerät): Variablen- und Zeitauslöser, Bedingung, Textvarianten mit Platzhaltern, Ziele.
- **`EchoMuseGateway/`** (EMGW, Splitter auf einem Server Socket): Gerätelink der EchoMuse-Firmware, Freigabe, Wiedergabe, Sprachrunden-Vermittlung (Traits `libs/EmGateway*.php`).
- **`EchoMuseGeraet/`** (EMGD): ein Dot mit Variablen und `SpeakFile`, `Beep`, `PlayCue`, `SendConfig`.
- **`EchoMuseVoice/`** (EMVS, auf einem Client Socket): Sprachrunde Dot ↔ OpenAI Realtime, Sitzung und Werkzeuge über SymDo.
- **`libs/`**: reine Bausteine ohne Symcon (`SpeechAi`, `EmWebSocket`, `EmProtocol`, `EmPcm`, `EmRealtime`, `EmSpool`, `SymDoVoiceClient`, `AwsSigV4`) plus die Traits.
- **`tools/migrate_legacy.php`**: Übernahme alter Ansage-Skripte, als Skript-Inhalt in Symcon auszuführen.
- Alle Module: `IPSModuleStrict`, Darstellungen statt Variablenprofilen.

## Prüfen

```bash
php tests/echomuse_lib_test.php   # reine Bausteine, ohne Symcon
php tests/smoke_test.php          # Zentrale und Ansage im Prüfstand-Kernel
php tests/echomuse_test.php       # Gateway, Gerät, Voice mit Attrappen-Dot
php -l <Datei>
```

`smoke_test.php` und `echomuse_test.php` brauchen den Prüfstand-Kernel aus dem Nachbarordner `../LGThinQ/tests/bootstrap.php`. Live-Proben mit Attrappen: `.claude/docs/testen/pruefstaende.md`.

## Regeln

- **Commits:** ein Thema je Commit, deutsche Botschaft, **ohne** Co-Authored-By-Zeile. Prüfstände vorher laufen lassen.
- **Nie** `git checkout`/`git restore` auf Dateien: die Arbeitskopie kann nicht committete Arbeit enthalten.
- **Push und Release nur auf Zuruf.** Release: `build` und `date` in `library.json` hochsetzen, Versionshistorie im README nachziehen.
- **Doku nachziehen:** Ändert ein Commit eine Entscheidung aus `docs/`, wird die Datei im selben Commit angepasst und ihr „Stand“-Datum erneuert.
- **Öffentliches Repo:** keine IP-Adressen, Ports lokaler Systeme, Instanz-IDs, Token, API-Schlüssel, Stimmen-IDs eines privaten Kontos, Gerätekennungen echter Dots, Pfade unter `/Users/` – weder im Code noch in Tests oder Doku. Fixtures mit Platzhaltern.
- **Echte Geräte:** jede Aktion an einem echten Dot oder eine hörbare Probe nur auf Zusage des Nutzers.
- **Symcon-Plattformwissen** (Module Strict, HEX-Datenfluss, Hooks, Timer, Nebenläufigkeit) steht geprüft in der SymDo-Bibliothek: `../List/.claude/docs/plattform/` bzw. https://github.com/da8ter/SymDo-Family-Organizer/tree/SymDo-Beta/.claude/docs/plattform – dort nachlesen, nicht hierher kopieren.

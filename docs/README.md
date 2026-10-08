# Sprachausgabe — Projektdoku

Versioniertes Projektwissen: **warum** etwas so gebaut ist, was am laufenden System gemessen wurde und wie man es prüft. Was der Code selbst zeigt, steht hier nicht; Bedienung steht im README der Bibliothek.

Jede Datei endet mit „Stand: geprüft gegen den Code am …“. Ändert ein Commit eine hier beschriebene Entscheidung, wird die Datei im selben Commit nachgezogen.

## Entscheidungen (`entscheidungen/`)

- [zentrale-und-ansage](entscheidungen/zentrale-und-ansage.md) – Splitter und Ansagen, Warteschlange, Zeitauslöser, Übernahme alter Skripte
- [ki-stimme](entscheidungen/ki-stimme.md) – fünf Anbieter, Zwischenspeicher, Hook, ElevenLabs-Fallen
- [echomuse-gateway](entscheidungen/echomuse-gateway.md) – Symcon als Controller für Echo Dots: Server Socket, eigener WebSocket, Gerätelink
- [echomuse-voice](entscheidungen/echomuse-voice.md) – Sprachrunde über SymDo und OpenAI Realtime, Entkopplung der Instanzen

## Testen (`testen/`)

- [pruefstaende](testen/pruefstaende.md) – die drei Prüfstände und Live-Proben mit Attrappen-Dot und Attrappen-Realtime

## Symcon-Plattform

Allgemeines, gemessenes Symcon-Verhalten steht in der SymDo-Bibliothek: [docs/plattform](https://github.com/da8ter/SymDo-Family-Organizer/tree/SymDo-Beta/docs/plattform) (lokal `../../List/docs/plattform/`). Für diese Bibliothek wichtig: `module-strict-und-php.md` (HEX-Datenfluss), `hooks-und-grenzen.md` (`RegisterHook` ohne `/hook/`, Ausgabegrenze), `instanzen-und-nebenlaeufigkeit.md` (Verbinden braucht beide GUID-Richtungen, Abarbeitung je Instanz), `timer.md`.

## Stand

[stand.md](stand.md) – offene Punkte und bekannte Widersprüche zwischen Code und Plattformwissen.

Stand: geprüft gegen den Code am 08.10.2026

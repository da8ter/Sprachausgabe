# Stand und offene Punkte

## Stand des Repos (08.10.2026)

- `library.json`: Version 0.1, Build 1. Die EchoMuse-Module (Gateway, Gerät, Voice) sind lokal committet, aber noch nicht veröffentlicht; die Versionshistorie im README nennt sie noch nicht. Beim nächsten Release Build, Datum und Historie nachziehen.

## Offen

- **Echter Dot:** bisher nur Attrappen-Dot. Offen sind Anmeldung, Ansage und Sprachrunde an echter Hardware, Hörprobe der Antwortqualität und Latenz (nicht am Code prüfbar). Jede Aktion am Gerät nur auf Zusage.
- **Absicherung des Gerätelinks:** kein TLS, kein Token, kein mDNS. Die Gerätekennung ist die einzige Identität.
- **Mehrere gleichzeitige Sprachrunden** (heute eine, zweiter Dot bekommt `busy`).
- **Dazwischenreden während der Antwort:** erneutes Wecken desselben Dots bricht die Antwort ab; am echten Gerät nicht erprobt.
- Hörprobe der klassischen Ansagen auf einem Echo über Echo Remote (nicht am Code prüfbar).

## Widersprüche und Risiken (nur gemeldet, nicht geändert)

- **`ConnectParent` in `IPSModuleStrict`:** alle vier Module mit Elter (Ansage, EchoMuse Gateway, Gerät, Voice) rufen `ConnectParent` in `Create`. Das Plattformwissen (`instanzen-und-nebenlaeufigkeit.md`, `module-strict-und-php.md`) sagt, die Methode gebe es nur für `IPSModule`, Ersatz sei `GetCompatibleParents()` (ab 8.2). Beobachtet wurde unter 9.1, dass der Aufruf beim Anlegen über die Konsole wirkt. Klären, bevor man sich darauf verlässt.
- **Mindestversion:** `library.json` und README nennen Symcon 8.1. Die Slider-Darstellungen nutzen `STEP_SIZE`, das laut SymDo-Doku der Name ab Symcon 9.1 ist. Unter 8.x nicht geprüft.
- **Schlüssel des KI-Zwischenspeichers** enthält nur `mp3`/`wav`, nicht den genauen Anbieter-Formatnamen (z. B. `mp3_44100_64`). Ändert sich das angeforderte Format im Code, liefern alte Dateien weiter (siehe `entscheidungen/ki-stimme.md`). Abhilfe wäre der Formatname im Hash.
- **Puffer des Gateways ohne Sperre:** `Conns` und `Plays` werden aus mehreren Einstiegspunkten gelesen und ganz zurückgeschrieben (`ReceiveData`, Timer `Keepalive`/`Pump`, `MessageSink`). Bei Voice führte genau dieses Muster zu verlorenen Blöcken (`entscheidungen/echomuse-voice.md`). Im Gateway ist kein Fehler beobachtet; Risiko, nicht belegt.

Stand: geprüft gegen den Code am 08.10.2026

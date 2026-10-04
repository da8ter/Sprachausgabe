# Sprachausgabe (Symcon)

Ansagen für Symcon: ein Auslöser, eine Bedingung, ein Text, ein oder mehrere Ausgabegeräte. Eine **Zentrale** kennt die Geräte und die globalen Schalter, jede **Ansage** ist eine eigene kleine Instanz.

## Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Enthaltene Module](#3-enthaltene-module)
4. [Einrichten](#4-einrichten)
5. [PHP-Befehle](#5-php-befehle)
6. [Versionshistorie](#6-versionshistorie)

## 1. Funktionsumfang

- **Ausgabegeräte:** Echo (sprechen oder Ankündigung mit Gong, über Echo Remote), Fully Kiosk Browser und eigene Skripte für jedes andere Gerät.
- **KI-Stimme:** OpenAI, Microsoft Azure, ElevenLabs, Amazon Polly oder Google Gemini erzeugen eine Audiodatei; ein Skript bekommt `$_IPS['AUDIO_URL']` und `$_IPS['AUDIO_FILE']` und spielt sie auf Sonos, Media-Playern oder Ähnlichem ab. Jeder Text wird nur einmal erzeugt und bezahlt.
- **Auslöser:** Variable bei Aktualisierung, Änderung, Wert gleich/ungleich, über/unter Grenzwert; zusätzlich täglich zu einer Uhrzeit.
- **Bedingungen** mit Symcons eigenem Bedingungs-Editor: Anwesenheit, Zeitfenster, Wochentage, beliebige Variablen.
- **Texte** mit Varianten (eine je Zeile, zufällig gewählt) und Platzhaltern: `{value}`, `{old}`, `{name}`, `{var:12345}`, `{time}`, `{date}`.
- **Zentral schaltbar:** Hauptschalter, Ruhemodus, Lautstärke in Prozent, je Ansage ein Aktiv-Schalter. „Dringende“ Ansagen (z. B. Rauchmelder) sprechen immer.
- **Warteschlange:** Ansagen überlappen nicht; dieselbe Ansage kommt innerhalb einer Sperrfrist nur einmal.

## 2. Voraussetzungen

- Symcon 8.1 oder neuer
- Für Echo: das Modul Echo Remote; für Fully Kiosk: das Fully-Kiosk-Modul

## 3. Enthaltene Module

- **Sprachausgabe Zentrale** (Splitter, Präfix `SPAZ`): Ausgabegeräte, globale Bedingung, Sperrfrist, Variablen Sprachausgabe, Ruhemodus, Lautstärke, Letzte Ansage.
- **Sprachausgabe Ansage** (Gerät, Präfix `SPAA`): Auslöser, Text, Bedingung, Ziele, Lautstärke, Dringend; Variablen Aktiv und Letzte Ansage um.

## 4. Einrichten

1. **Zentrale anlegen** und die Ausgabegeräte eintragen: Name (z. B. „Küche“), Art, Gerät oder Skript, Lautstärke, ob es ein Standardgerät ist.
2. Optional eine **globale Bedingung** setzen, etwa „Jemand anwesend“.
3. Je Ansage eine **Ansage-Instanz** anlegen, sie verbindet sich mit der Zentrale. Auslöser, Text und Bedingung eintragen, Ausgabegeräte anhaken (keins angehakt = Standardgeräte).
4. Mit **Testansage** prüfen.

**Eigenes Skript als Ausgabe:** Das Skript bekommt `$_IPS['TEXT']`, `$_IPS['VOLUME']` (0 = Gerätestandard) und `$_IPS['TARGET']` (Name des Ausgabegeräts).

**Umstieg von eigenen Ansage-Skripten:** [tools/migrate_legacy.php](tools/migrate_legacy.php) übernimmt eine Kategorie mit Unterkategorien aus `switch`, `Zeitplan` und einem Skript mit festem Text und Auslöser-Ereignis. Als Skript-Inhalt ausführen; die Voreinstellung ist ein Probelauf, der nur einen Bericht ins Log schreibt. Mit `DRY_RUN = false` entstehen Zentrale und Ansagen, die alten Ereignisse werden deaktiviert, nicht gelöscht.

## EchoMuse: Symcon als Controller für Echo Dots (Stufe 1)

Echo Dots der **2. Generation** mit der Firmware [EchoMuse](https://github.com/wilbowes/EchoMuse) wählen sich bei einem **EchoMuse Gateway** (Präfix `EMGW`) ein, ganz ohne Home Assistant. Das Gateway setzt auf einem Server Socket auf (Port 8767) und spricht den Gerätelink der Firmware: Anmeldung mit Freigabe, Lautstärke, Tasten, Stumm, Ansagen. Je Dot gibt es ein **EchoMuse Gerät** (Präfix `EMGD`) mit Variablen und `EMGD_SpeakFile`, `EMGD_Beep`, `EMGD_PlayCue`, `EMGD_SendConfig`. In der Zentrale gibt es die Ausgabeart „EchoMuse-Dot (KI-Stimme)“: Der Text wird bei der KI-Stimme als WAV erzeugt und auf dem Dot abgespielt.

Auf dem Dot muss die Controller-Adresse eingetragen sein (`/data/local/etc/echomuse/controller.json`, ab Firmware 2.16.0; mDNS gibt es in Stufe 1 nicht). Noch nicht enthalten: Sprachbefehle (Mikrofon), TLS mit Token. Prüfung: `php tests/echomuse_lib_test.php`, `php tests/echomuse_test.php`.

## 5. PHP-Befehle

```php
SPAZ_Speak(int $ZentraleID, string $Text, string $Ziele, int $Lautstaerke): string
SPAZ_SpeakUrgent(int $ZentraleID, string $Text, string $Ziele, int $Lautstaerke): string
SPAA_Trigger(int $AnsageID): string
```

`$Ziele` sind Gerätenamen, durch Komma getrennt, leer für die Standardgeräte. `$Lautstaerke` 0 nimmt die Lautstärke des Geräts. Die Rückgabe ist leer, wenn die Ansage eingereiht wurde, sonst der Grund (z. B. Ruhemodus).

## 6. Versionshistorie

- **0.1**: Erste Version: Zentrale mit Echo, Fully Kiosk, Skript-Ausgabe und KI-Stimme (OpenAI, Azure, ElevenLabs, Amazon Polly, Google Gemini; Audiodatei über den Webhook `/hook/sprachausgabe`), Lautstärke-Variable je Gerät, Warteschlange und Sperrfrist; Ansage mit Variablen- und Zeitauslöser, Bedingung, Textvarianten und Platzhaltern. Rauchtest [tests/smoke_test.php](tests/smoke_test.php) (nutzt den Prüfstand-Kernel aus `modules/LGThinQ`).

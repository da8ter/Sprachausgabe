# Sprachausgabe (Symcon)

Ansagen und Pushbenachrichtigungen für Symcon: ein Auslöser, eine Bedingung, ein Text, ein oder mehrere Ziele. Die **Sprachausgabe Zentrale** kennt die Geräte, die globalen Schalter und alle Ansagen (als Liste). Pushbenachrichtigungen stehen ebenso in **einer** Instanz, der **Push Zentrale**.

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
- **Auslöser** im bekannten Bedingungs-Dialog der Konsole (Variable, Vergleich, Wert passend zum Typ): wenn die Regel erfüllt wird, solange sie erfüllt ist, bei jeder Aktualisierung oder Änderung; zusätzlich täglich zu einer Uhrzeit. Ältere Ansagen werden automatisch umgestellt.
- **Text-Vorschau** im Formular: zeigt den fertigen Text mit ersetzten Platzhaltern.
- **Wochenpläne:** gemeinsame „Sprechzeiten“ der Zentrale und je Ansage wahlweise ein eigener Wochenplan; außerhalb der Sprechzeit schweigt die Ansage, dringende sprechen immer.
- **Pushbenachrichtigungen** (Push Zentrale): Nachrichten als Liste mit eigenem Dialog, Kachel-Visualisierung (mit Icon, Ton, Ziel beim Antippen) oder WebFront, Schalter je Nachricht und Person, Verzögerung („erst nach 60 min offen“) und Wiederholung, Text aus Skript.
- **Bedingungen** mit Symcons eigenem Bedingungs-Editor: Anwesenheit, Zeitfenster, Wochentage, beliebige Variablen.
- **Texte** mit Varianten (eine je Zeile, zufällig gewählt) und Platzhaltern: `{value}`, `{old}`, `{name}`, `{var:12345}`, `{time}`, `{date}`.
- **Zentral schaltbar:** Hauptschalter, Ruhemodus, Lautstärke in Prozent, je Ansage ein Schalter unter der Zentrale (einfach in die Visu zu verlinken). „Dringende“ Ansagen (z. B. Rauchmelder) sprechen immer.
- **Warteschlange:** Ansagen überlappen nicht; dieselbe Ansage kommt innerhalb einer Sperrfrist nur einmal.

## 2. Voraussetzungen

- Symcon 8.1 oder neuer
- Für Echo: das Modul Echo Remote; für Fully Kiosk: das Fully-Kiosk-Modul

## 3. Enthaltene Module

- **Sprachausgabe Zentrale** (Gerät, Präfix `SPAZ`): Ansagen (Liste mit Dialog: Auslöser, täglich um, Text, Ausgabegeräte, Lautstärke, Dringend, Bedingung), Ausgabegeräte, globale Bedingung, Sperrfrist, Variablen Sprachausgabe, Ruhemodus, Lautstärke, Letzte Ansage und je Ansage ein Schalter (für die Visualisierung).
- **Push Zentrale** (Gerät, Präfix `PUSHZ`): Empfänger, Nachrichtenliste, globale Bedingung, Sperrfrist; Variablen Benachrichtigungen (Hauptschalter), Letzte Benachrichtigung und je Nachricht und Empfänger ein Schalter.

## 4. Einrichten

1. **Zentrale anlegen** und die Ausgabegeräte eintragen: Name (z. B. „Küche“), Art, Gerät oder Skript, Lautstärke, ob es ein Standardgerät ist.
2. Optional eine **globale Bedingung** setzen, etwa „Jemand anwesend“.
3. Unter **Ansagen** je Ansage eine Zeile anlegen: Name, Auslöser (Bedingungs-Dialog), optional täglich um, Text, Ausgabegeräte anhaken (keins angehakt = Standardgeräte), Lautstärke, Dringend, Bedingung.
4. Im Dialog mit **Text-Vorschau** und **Testansage** prüfen.

**Eigenes Skript als Ausgabe:** Das Skript bekommt `$_IPS['TEXT']`, `$_IPS['VOLUME']` (0 = Gerätestandard) und `$_IPS['TARGET']` (Name des Ausgabegeräts).

**Umstieg von eigenen Ansage-Skripten:** [tools/migrate_legacy.php](tools/migrate_legacy.php) übernimmt eine Kategorie mit Unterkategorien aus `switch`, `Zeitplan` und einem Skript mit festem Text und Auslöser-Ereignis. Als Skript-Inhalt ausführen; die Voreinstellung ist ein Probelauf, der nur einen Bericht ins Log schreibt. Mit `DRY_RUN = false` entstehen Zentrale und Ansageliste, die alten Ereignisse werden deaktiviert, nicht gelöscht.

### Pushbenachrichtigungen

1. **Push Zentrale anlegen** und unter „Empfänger“ je Person eine Visualisierung eintragen. Symcon sendet immer an **alle Geräte einer Visualisierung**; für einzelne Personen je eine eigene Kachel-Visualisierung anlegen und dort im Reiter „Benachrichtigungen“ nur deren Geräte einschalten.
2. Unter „Nachrichten“ je Nachricht eine Zeile anlegen: Name, Auslöser, Titel, Text (oder Textskript), Icon, Ton, Ziel, Bedingung, optional Verzögerung und Wiederholung. „Text-Vorschau“ und „Test an alle senden“ stehen im Dialog.
3. Je Nachricht und Empfänger entsteht ein Schalter unter der Zentrale – in die Visualisierung verlinken, damit jede Person selbst wählt.

**Umstieg von eigenen Push-Skripten:** [tools/migrate_push.php](tools/migrate_push.php) übernimmt eine Kategorie mit `Hauptschalter` und Unterkategorien aus Skript, Schaltern je Empfänger und Auslöser-Ereignis. Probelauf als Voreinstellung; mit `DRY_RUN = false` entsteht die Push Zentrale, die alten Ereignisse werden deaktiviert, nicht gelöscht.

## EchoMuse: Symcon als Controller für Echo Dots (Stufe 1)

Echo Dots der **2. Generation** mit der Firmware [EchoMuse](https://github.com/wilbowes/EchoMuse) wählen sich bei einem **EchoMuse Gateway** (Präfix `EMGW`) ein, ganz ohne Home Assistant. Das Gateway setzt auf einem Server Socket auf (Port 8767) und spricht den Gerätelink der Firmware: Anmeldung mit Freigabe, Lautstärke, Tasten, Stumm, Ansagen. Je Dot gibt es ein **EchoMuse Gerät** (Präfix `EMGD`) mit Variablen und `EMGD_SpeakFile`, `EMGD_Beep`, `EMGD_PlayCue`, `EMGD_SendConfig`. In der Zentrale gibt es die Ausgabeart „EchoMuse-Dot (KI-Stimme)“: Der Text wird bei der KI-Stimme als WAV erzeugt und auf dem Dot abgespielt.

Auf dem Dot muss die Controller-Adresse eingetragen sein (`/data/local/etc/echomuse/controller.json`, ab Firmware 2.16.0; mDNS gibt es noch nicht). Noch nicht enthalten: TLS mit Token. Prüfung: `php tests/echomuse_lib_test.php`, `php tests/echomuse_test.php`.

### Sprachgespräche mit dem Dot (EchoMuse Voice, Präfix `EMVS`)

Der Dot erkennt sein Wakeword selbst (Private Listening: bis dahin verlässt kein Ton das Gerät). Danach läuft die Sprache **gestreamt** zur Realtime-Schnittstelle von OpenAI und die Antwort kommt schon beim Erzeugen zurück, ohne getrennte Spracherkennung und Stimme. Anweisungen, Werkzeuge (Listen, Termine, Geräte …) und die Sprechzeit-Grenzen kommen von **SymDo**; der OpenAI-Schlüssel bleibt dort.

1. Das **EchoMuse Voice**-Modul anlegen (es legt einen Client Socket an). SymDo-Adresse (`http://<symcon>:3777/hook/lists/app`), SymDo-Zugangstoken und Nutzerkennung eintragen, das Gateway wählen.
2. Im **Gateway** das Voice-Modul wählen. Das Gateway sagt dem Dot „owwOnDevice“ und schaltet den Signalton beim Wakeword ein.
3. In SymDo muss ein **Realtime-Modell** eingestellt sein (`gpt-realtime-mini` oder `gpt-realtime`), nicht GPT-Live: GPT-Live läuft über WebRTC.

Gemessen am 04.10.2026 (Symcon 9.1 im Docker, echtes SymDo, echtes OpenAI mit TLS, Attrappen-Dot mit gesprochenem Satz): „Wie spät ist es?“ wird richtig erkannt und beantwortet, der Werkzeugaufruf über SymDo dauert rund 15 ms, das erste Antwort-Audio liegt etwa eine Sekunde nach Sprachende vor. Ein echter Dot ist noch nicht daran gelaufen.

Ablauf: `oww_wake` → `listen_ack` → Mikrofon (0x07) → Voice → `speech_stopped` → `listen_close` → Werkzeugaufrufe über SymDo → Antwort-Audio als Strom zum Dot. Es läuft **eine Sitzung gleichzeitig**, ein zweiter Dot bekommt „busy“. Der Dot braucht sein Wakeword-Modell (wird beim Einrichten mit dem EchoMuse-Controller installiert). Prüfung: `php tests/echomuse_test.php`.

## 5. PHP-Befehle

```php
SPAZ_Speak(int $ZentraleID, string $Text, string $Ziele, int $Lautstaerke): string
SPAZ_SpeakUrgent(int $ZentraleID, string $Text, string $Ziele, int $Lautstaerke): string
SPAZ_TriggerAnnouncement(int $ZentraleID, string $AnsageName): string
PUSHZ_Trigger(int $PushZentraleID, string $NachrichtName): string
PUSHZ_Send(int $PushZentraleID, string $Titel, string $Text, string $Empfaenger): string
```

`$Ziele` sind Gerätenamen, durch Komma getrennt, leer für die Standardgeräte. `$Lautstaerke` 0 nimmt die Lautstärke des Geräts. Die Rückgabe ist leer, wenn die Ansage eingereiht wurde, sonst der Grund (z. B. Ruhemodus). Bei `PUSHZ_Send` sind `$Empfaenger` Empfängernamen, durch Komma getrennt, leer für alle; `PUSHZ_Trigger` löst eine Nachricht der Liste mit ihren Schaltern und Bedingungen aus.

## 6. Versionshistorie

- **0.2, Build 6**: Sprachausgabe Zentrale ist ein Gerät (kein Splitter mehr) mit Schaltvariable je Ansage; das Modul „Sprachausgabe Ansage“ ist entfernt.
- **0.2, Build 5**: Wochenpläne für Ansagen (gemeinsame Sprechzeiten der Zentrale, eigener Plan je Ansage).
- **0.2, Build 4**: Platzhalter im Formular als Textfeld zum Kopieren, je Zeile mit Erklärung.
- **0.2, Build 3**: Vorschau- und Test-Knöpfe zeigen ihren Text als Meldung statt als Warnung mit Dateipfad (Funktionen geben den Text zurück, der Knopf gibt ihn aus).
- **0.2**: Ansagen als Liste in der Zentrale (Dialog je Ansage, Text-Vorschau, Testansage, Übernahme bestehender Ansage-Instanzen per Knopf); Auslöser über den Bedingungs-Dialog der Konsole mit Auslöse-Art; neues Modul **Push Zentrale** für Pushbenachrichtigungen (Empfänger je Visualisierung, Nachrichtenliste, Schalter je Nachricht und Person, Verzögerung und Wiederholung, Textskript) samt Übernahme-Werkzeug `tools/migrate_push.php`; EchoMuse Gateway, Gerät und Voice.
- **0.1**: Erste Version: Zentrale mit Echo, Fully Kiosk, Skript-Ausgabe und KI-Stimme (OpenAI, Azure, ElevenLabs, Amazon Polly, Google Gemini; Audiodatei über den Webhook `/hook/sprachausgabe`), Lautstärke-Variable je Gerät, Warteschlange und Sperrfrist; Ansage mit Variablen- und Zeitauslöser, Bedingung, Textvarianten und Platzhaltern. Rauchtest [tests/smoke_test.php](tests/smoke_test.php) (nutzt den Prüfstand-Kernel aus `modules/LGThinQ`).

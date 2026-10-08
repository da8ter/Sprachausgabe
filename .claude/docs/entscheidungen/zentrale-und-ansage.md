# Zentrale und Ansage

Eine **Zentrale** (`SprachausgabeZentrale`, SPAZ, Splitter) kennt die Ausgabegeräte, die globalen Schalter und – seit 08.10.2026 – alle **Ansagen als Liste** (Trait `libs/SpeechAnnouncements.php`). Das Modul **Sprachausgabe Ansage** (SPAA, Instanz je Ansage) ist veraltet und bleibt nur, damit bestehende Instanzen nach einem Update weiterlaufen, bis sie per Knopf übernommen sind.

## Entscheidungen

- **Ansagen als Liste in der Zentrale** (Nutzerentscheid 08.10.2026, ersetzt „eine Instanz je Ansage“): `List` mit eigenem Dialog je Zeile (`form`) – Name, Auslöser, täglich um, Text, Ausgabegeräte (ein Häkchen `T_<md5(Gerät)[0..6]>` je Gerät der Zentrale, keins = Standardgeräte), Lautstärke, Dringend, Bedingung, Text-Vorschau, Testansage. Aktiv-Häkchen direkt in der Liste. Feste Kennung `annId` je Zeile (vergeben über den Timer `Reapply`), Sperrschlüssel `ann:<annId>`.
- **Übernahme der Instanzen** per Knopf bzw. `SPAZ_ImportAnnouncements`: liest jede verbundene SPAA-Instanz (altes Auslöser-Format wird dabei umgerechnet), übernimmt Aktiv aus deren Variable und die Ziele, löscht Instanz, Variablen und Zeitereignis. `tools/migrate_legacy.php` ruft das am Ende selbst auf.
- **Datenfluss in beiden Richtungen deklariert**, obwohl die Zentrale den Ansagen nichts schickt: `childRequirements` der Zentrale = `implemented` der Ansage. Ohne die Rückrichtung weist Symcon das Verbinden mit „Datenfluss ist inkompatibel“ ab (gemessen, siehe Plattformwissen `instanzen-und-nebenlaeufigkeit.md`). `SPAA::ReceiveData` ist deshalb absichtlich leer.
- **Ausgabearten** (`libs/SpeechOutputs.php`): Echo sprechen, Echo Ankündigung (beide über das Modul Echo Remote), Fully Kiosk, eigenes Skript, KI-Stimme mit Skript, EchoMuse-Dot. Fremde Module werden über ihre Präfix-Funktionen angesprochen und vorher auf Existenz geprüft, damit eine fehlende Bibliothek als Warnung statt als Fatal endet.
- **Warteschlange über einen Timer**, damit Ansagen nicht überlappen: der nächste Eintrag kommt nach der geschätzten Sprechzeit (Textlänge × 65 ms + 1 s, mindestens 1,5 s). Höchstens 20 Einträge. Der Timer wird nur scharf gestellt, wenn er steht – jedes `SetTimerInterval` zählt neu und würde die laufende Ansage verschieben (Plattformwissen `timer.md`).
- **Sperrfrist** (Vorgabe 30 s): dieselbe Ansage kommt innerhalb der Frist nur einmal. Schlüssel ist bei Ansage-Instanzen deren Kennung (`SPAA<ID>`, also unabhängig von der gewählten Textvariante), bei `SPAZ_Speak` der Text selbst.
- **Dringend** umgeht Hauptschalter, Ruhemodus und globale Bedingung, **nicht** die Sperrfrist. Die Testansage aus dem Formular gilt als dringend und hat keinen Sperrschlüssel.
- **Rückgabe statt Ausnahme:** `SPAZ_Speak`/`SPAZ_SpeakUrgent`/`SPAA_Trigger` liefern leer, wenn eingereiht, sonst den Grund. Skripte können so ohne `try` reagieren.
- **Zeitauslöser als eigenes zyklisches Ereignis** (Ident `ANNTIME_<annId>`, versteckt) unter der Zentrale, nicht als Modul-Timer: lange Timer verhungern bei Reloads und zählen nach jedem `SetTimerInterval` neu (Plattformwissen `timer.md`). Das Ereignis ruft `SPAZ_TriggerAnnouncement(<Zentrale>, '<annId>')`; nicht mehr gewünschte `ANNTIME_`-Ereignisse löscht `ApplyChanges`.
- **Bedingungen mit Symcons eigenem Editor** (`SelectCondition`, `IPS_IsConditionPassing`); eine nicht auswertbare Bedingung zählt als „nicht erfüllt“ und wird protokolliert.
- **Auslöser aus dem Bedingungs-Dialog** (seit 08.10.2026, Nutzerwunsch): eine Variablenregel (`SelectCondition` mit `multi: false`, Wertefeld passend zum Variablentyp) plus Auslöse-Art „wenn erfüllt / solange erfüllt / jede Aktualisierung / jede Änderung“. Ausgewertet in `libs/SpeechTrigger.php` (`rule`, `passes`, `firesRule`); „wenn erfüllt“ feuert nur beim Übergang. Alte Instanzen (Variable, Regel, Wert als Text) stellt `convertLegacyTrigger` einmal um und übernimmt über den Timer `Reapply` – ein `IPS_ApplyChanges` der eigenen Instanz in `ApplyChanges` lehnt Symcon ab.
- **Text-Vorschau** im Formular (`SPAA_Preview`): alle Varianten mit ersetzten Platzhaltern, auch für ungespeicherte Formularwerte (onClick bekommt die Felder als Variablen).
- **Zielliste im Ansage-Formular** mit `save: true` an der Namensspalte – sonst speichert Symcon nur die Häkchen und die Namen fallen still weg (Plattformwissen `formulare.md`).
- **Einmal-Vorbelegung über ein Attribut** (`Initialized`): `MASTER` an, Lautstärke 100 % bzw. `ACTIVE` an. `Create` läuft bei jedem Laden und darf Nutzerwerte nicht zurücksetzen.

## Übernahme alter Ansage-Skripte (`tools/migrate_legacy.php`)

- Als Skript-Inhalt in Symcon auszuführen; die Kategorie steht als Konstante oben im Skript und muss vor dem Lauf auf die eigene gesetzt werden.
- **Probelauf als Voreinstellung** (`DRY_RUN = true`): liest nur und schreibt einen Bericht ins Log.
- Gemeinsame Regeln aller Altansagen (z. B. Anwesenheit, Hauptschalter) werden zur globalen Bedingung der Zentrale; die alten Schalter bleiben so in der Visualisierung wirksam.
- Alte Auslöser-Ereignisse werden **deaktiviert, nicht gelöscht** (Rückweg: wieder aktivieren, Ansage-Instanzen löschen).
- Bedingungen, die auf Schalter oder Zeitpläne **anderer** Altansagen zeigen (kopierte Ereignisse), werden umgehängt oder gestrichen und im Bericht genannt.

## Fallen / gemessen

- Eine frisch per Module Control installierte Bibliothek registriert ihre Präfix-Funktionen sofort; einen Kernelstart braucht es nur für neue Funktionen einer schon geladenen Bibliothek (beobachtet 01.10.2026, nicht am Code prüfbar; zur Reload-Grenze siehe Plattformwissen `module-lebenszyklus.md`).

Stand: geprüft gegen den Code am 08.10.2026 (Auslöser-Umbau am selben Tag)

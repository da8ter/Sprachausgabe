# Zentrale und Ansage

Die **Zentrale** (`SprachausgabeZentrale`, SPAZ, **Gerät**) kennt die Ausgabegeräte, die globalen Schalter und alle **Ansagen als Liste** (Trait `libs/SpeechAnnouncements.php`). Das frühere Modul „Sprachausgabe Ansage“ (SPAA, Instanz je Ansage) ist seit 08.10.2026 entfernt; damit entfiel der Datenfluss, und die Zentrale ist kein Splitter mehr (Nutzerwunsch).
- **Lautstärke-Variable je Ansage** (`V_<annId>`, 0–100 %, Darstellung Slider, 0 = Gerätestandard; Nutzerwunsch 08.10.2026): sie gilt beim Sprechen. Der Schieberegler im Dialog schreibt seinen Wert beim Übernehmen nur dann in die Variable, wenn er sich geändert hat (gemerkt im Attribut `AnnVolumeApplied`) – sonst bliebe ein in der Visu verstellter Wert nicht stehen.
- **Schaltvariable je Ansage** (`A_<annId>`, Name = Ansage, neu = an) unter der Zentrale – wie bei der Push Zentrale, damit sie sich einfach in die Visualisierung legen lässt. Aus = die Ansage schweigt, auch wenn sie dringend ist; „Aktiv“ in der Liste schaltet sie dauerhaft ab.

## Entscheidungen

- **Ansagen als Liste in der Zentrale** (Nutzerentscheid 08.10.2026, ersetzt „eine Instanz je Ansage“): `List` mit eigenem Dialog je Zeile (`form`) – Name, Auslöser, täglich um, Text, Ausgabegeräte (ein Häkchen `T_<md5(Gerät)[0..6]>` je Gerät der Zentrale, keins = Standardgeräte), Lautstärke, Dringend, Bedingung, Text-Vorschau, Testansage. Aktiv-Häkchen direkt in der Liste. Feste Kennung `annId` je Zeile (vergeben über den Timer `Reapply`), Sperrschlüssel `ann:<annId>`.
- **Wochenpläne** (Nutzerentscheid 08.10.2026, „Beides“): die Zentrale legt immer den Plan „Sprechzeiten“ an (Ident `SCHEDULE_MAIN`, Vorgabe 00:00 Ruhe / 08:00 Sprechen); je Ansage wählbar keiner / Sprechzeiten / eigener Plan (`ANNSCHED_<annId>`, beim Anlegen eine Kopie der Sprechzeiten). Aktionen 1 = Sprechen, 2 = Ruhe; außerhalb von „Sprechen“ schweigt die Ansage, dringende sprechen immer. Schaltpunkte gehören nach dem Anlegen dem Nutzer und werden nie überschrieben; ein deaktivierter oder fehlender Plan sperrt nichts. Der geltende Zustand wird aus den Schaltpunkten berechnet (`libs/SpeechSchedule.php`, auch über Tagesgrenzen), weil `LastActionID` eines frischen Plans 0 ist (gemessen 08.10.2026). Einen Wochenplan-Editor gibt es im Formular nicht; geöffnet wird der Plan per `OpenObjectButton`.
- **Übernahme früherer Ansage-Instanzen** lief bis Build 5 per `SPAZ_ImportAnnouncements` (Knopf); mit dem Modul ist sie entfernt. `tools/migrate_legacy.php` schreibt Altansagen direkt als Listenzeilen und setzt ihre Schaltvariablen.
- **Ausgabearten** (`libs/SpeechOutputs.php`): Echo sprechen, Echo Ankündigung (beide über das Modul Echo Remote), Fully Kiosk, eigenes Skript, KI-Stimme mit Skript, EchoMuse-Dot. Fremde Module werden über ihre Präfix-Funktionen angesprochen und vorher auf Existenz geprüft, damit eine fehlende Bibliothek als Warnung statt als Fatal endet.
- **Warteschlange über einen Timer**, damit Ansagen nicht überlappen: der nächste Eintrag kommt nach der geschätzten Sprechzeit (Textlänge × 65 ms + 1 s, mindestens 1,5 s). Höchstens 20 Einträge. Der Timer wird nur scharf gestellt, wenn er steht – jedes `SetTimerInterval` zählt neu und würde die laufende Ansage verschieben (Plattformwissen `timer.md`).
- **Sperrfrist** (Vorgabe 30 s): dieselbe Ansage kommt innerhalb der Frist nur einmal. Schlüssel ist bei Ansagen der Liste ihre Kennung (`ann:<annId>`, also unabhängig von der gewählten Textvariante), bei `SPAZ_Speak` der Text selbst.
- **Dringend** umgeht Hauptschalter, Ruhemodus und globale Bedingung, **nicht** die Sperrfrist. Die Testansage aus dem Formular gilt als dringend und hat keinen Sperrschlüssel.
- **Rückgabe statt Ausnahme:** `SPAZ_Speak`/`SPAZ_SpeakUrgent`/`SPAZ_TriggerAnnouncement` liefern leer, wenn eingereiht, sonst den Grund. Skripte können so ohne `try` reagieren.
- **Zeitauslöser als eigenes zyklisches Ereignis** (Ident `ANNTIME_<annId>`, versteckt) unter der Zentrale, nicht als Modul-Timer: lange Timer verhungern bei Reloads und zählen nach jedem `SetTimerInterval` neu (Plattformwissen `timer.md`). Das Ereignis ruft `SPAZ_TriggerAnnouncement(<Zentrale>, '<annId>')`; nicht mehr gewünschte `ANNTIME_`-Ereignisse löscht `ApplyChanges`.
- **Bedingungen mit Symcons eigenem Editor** (`SelectCondition`, `IPS_IsConditionPassing`); eine nicht auswertbare Bedingung zählt als „nicht erfüllt“ und wird protokolliert.
- **Auslöser aus dem Bedingungs-Dialog** (seit 08.10.2026, Nutzerwunsch): eine Variablenregel (`SelectCondition` mit `multi: false`, Wertefeld passend zum Variablentyp) plus Auslöse-Art „wenn erfüllt / solange erfüllt / jede Aktualisierung / jede Änderung“. Ausgewertet in `libs/SpeechTrigger.php` (`rule`, `passes`, `firesRule`); „wenn erfüllt“ feuert nur beim Übergang. Alte Instanzen (Variable, Regel, Wert als Text) stellt `convertLegacyTrigger` einmal um und übernimmt über den Timer `Reapply` – ein `IPS_ApplyChanges` der eigenen Instanz in `ApplyChanges` lehnt Symcon ab.
- **Text-Vorschau** im Formular (`SPAZ_PreviewAnnouncement`, Rückgabe statt echo): alle Varianten mit ersetzten Platzhaltern, auch für ungespeicherte Formularwerte (onClick bekommt die Felder als Variablen).
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

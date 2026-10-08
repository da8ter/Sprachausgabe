# Zentrale und Ansage

Eine **Zentrale** (`SprachausgabeZentrale`, SPAZ, Splitter) kennt die Ausgabegeräte und die globalen Schalter; jede **Ansage** (`SprachausgabeAnsage`, SPAA) ist eine eigene kleine Instanz darunter.

## Entscheidungen

- **Eine Instanz je Ansage statt einer großen Tabelle.** Auslöser, Bedingung, Texte und Ziele stehen am Ort der Ansage im Objektbaum; die Zentrale hält nur, was alle teilen (Geräte, globale Bedingung, Sperrfrist, Hauptschalter, Ruhemodus, Lautstärke).
- **Datenfluss in beiden Richtungen deklariert**, obwohl die Zentrale den Ansagen nichts schickt: `childRequirements` der Zentrale = `implemented` der Ansage. Ohne die Rückrichtung weist Symcon das Verbinden mit „Datenfluss ist inkompatibel“ ab (gemessen, siehe Plattformwissen `instanzen-und-nebenlaeufigkeit.md`). `SPAA::ReceiveData` ist deshalb absichtlich leer.
- **Ausgabearten** (`libs/SpeechOutputs.php`): Echo sprechen, Echo Ankündigung (beide über das Modul Echo Remote), Fully Kiosk, eigenes Skript, KI-Stimme mit Skript, EchoMuse-Dot. Fremde Module werden über ihre Präfix-Funktionen angesprochen und vorher auf Existenz geprüft, damit eine fehlende Bibliothek als Warnung statt als Fatal endet.
- **Warteschlange über einen Timer**, damit Ansagen nicht überlappen: der nächste Eintrag kommt nach der geschätzten Sprechzeit (Textlänge × 65 ms + 1 s, mindestens 1,5 s). Höchstens 20 Einträge. Der Timer wird nur scharf gestellt, wenn er steht – jedes `SetTimerInterval` zählt neu und würde die laufende Ansage verschieben (Plattformwissen `timer.md`).
- **Sperrfrist** (Vorgabe 30 s): dieselbe Ansage kommt innerhalb der Frist nur einmal. Schlüssel ist bei Ansage-Instanzen deren Kennung (`SPAA<ID>`, also unabhängig von der gewählten Textvariante), bei `SPAZ_Speak` der Text selbst.
- **Dringend** umgeht Hauptschalter, Ruhemodus und globale Bedingung, **nicht** die Sperrfrist. Die Testansage aus dem Formular gilt als dringend und hat keinen Sperrschlüssel.
- **Rückgabe statt Ausnahme:** `SPAZ_Speak`/`SPAZ_SpeakUrgent`/`SPAA_Trigger` liefern leer, wenn eingereiht, sonst den Grund. Skripte können so ohne `try` reagieren.
- **Zeitauslöser als eigenes zyklisches Ereignis** unter der Ansage (Ident fest, versteckt), nicht als Modul-Timer: lange Timer verhungern bei Reloads und zählen nach jedem `SetTimerInterval` neu (Plattformwissen `timer.md`).
- **Bedingungen mit Symcons eigenem Editor** (`SelectCondition`, `IPS_IsConditionPassing`); eine nicht auswertbare Bedingung zählt als „nicht erfüllt“ und wird protokolliert.
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

Stand: geprüft gegen den Code am 08.10.2026

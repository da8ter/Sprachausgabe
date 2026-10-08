# Push Zentrale

Pushbenachrichtigungen in **einer** Instanz (`PushZentrale`, PUSHZ, Gerät): Empfänger, Nachrichten als Liste, Schalter je Nachricht und Empfänger.

## Entscheidungen

- **Alles in der Zentrale statt einer Instanz je Nachricht** (Nutzerentscheid 08.10.2026, nachdem die erste Fassung „Push Nachricht“ je Instanz gebaut war). Die Nachrichten sind eine `List` mit eigenem Bearbeiten-Dialog je Zeile (`form`, Symcon ≥ 7.0); darin funktionieren `SelectCondition`, `SelectIcon`, `SelectObject` und Knöpfe (Vorschau, Test), deren `onClick` die Dialogfelder als Variablen bekommt.
- **Empfänger = eine Visualisierung.** `VISU_PostNotificationEx` und `WFC_PushNotification` senden immer an alle Geräte der Instanz; einzelne Personen oder Geräte brauchen je eine eigene Visualisierung (Doku von `VISU_PostNotificationEx`). Beide Wege in `libs/PushOutputs.php`, Titel auf 32, Text auf 256 Zeichen gekürzt.
- **Feste Kennung je Zeile** (`msgId`, 8 Hex-Zeichen), vergeben beim ersten Übernehmen. Schalter-Idents `R_<msgId>_<md5(Empfänger)[0..6]>` überstehen so Umbenennen und Umsortieren; umbenannte Nachrichten oder Empfänger benennen ihre Schalter mit um.
- **Schalter je Nachricht und Empfänger** als Variable unter der Zentrale (neu = an), damit jede Person in der Visu wählt, was sie bekommt; dazu `MASTER`. Ein Aktiv-Häkchen je Zeile steht direkt in der Liste.
- **Auslöser** wie bei der Ansage: eine Regel aus dem Bedingungs-Dialog plus Auslöse-Art (`libs/SpeechTrigger.php`).
- **Verzögerung und Wiederholung** nur für „wenn erfüllt“/„solange erfüllt“: Fälligkeiten je Nachricht im Attribut `Due`, **ein** Timer auf die früheste. Ein erneutes Update bei laufender Verzögerung stellt nichts neu (SetTimerInterval zählt neu); fällt die Regel weg, wird die Fälligkeit gestrichen. Der Timer-Lauf bearbeitet alles, was bis zur frühesten Fälligkeit + 1 s fällig ist, damit der Prüfstand (eigene Uhr) und Symcon (Wanduhr) gleich laufen.
- **Text aus Skript** (`TextScript`), wenn Platzhalter nicht reichen: die Ausgabe des Skripts ist der Text; es bekommt `VARIABLE`, `VALUE`, `OLD`.
- **Sperrfrist** je Nachricht (Schlüssel `msg:<msgId>`), Test aus dem Dialog umgeht Schalter, Bedingungen und Sperrfrist.

## Übernahme (`tools/migrate_push.php`)

- Altbestand: Kategorie mit `Hauptschalter` und Unterkategorien aus Skript(en) mit `WFC_PushNotification`/`VISU_PostNotificationEx`, Bool-Schaltern je Empfänger und Auslöser-Ereignis. Probelauf als Voreinstellung.
- Empfänger aus den aktiven Aufrufen (Name des Schalters → Visu-Instanz); ein Schalter, dessen Aufruf auskommentiert ist, wird AUS übernommen. Andere Bool-Variablen der Kategorie sind keine Empfänger.
- Je Skript und Auslöser-Ereignis eine Zeile. Titel/Icon/Ton/Ziel aus dem Kachel-Aufruf, der längste Text aus allen Aufrufen; `'…' . $var` wird zu `{value}`/`{var:ID}`, eine einmal belegte Text-Variable zum Text, alles andere zu einem Textskript (Code vor dem Hauptschalter-Block, `GetValue*` mit `@`).
- Skripte ohne Ereignis, die ein anderes Skript per `IPS_SetScriptTimer(<id>, N)` startet: Regel aus dem `case` davor, Verzögerung N s, Wiederholung N/60 min; die Timer-Zeilen dort werden auskommentiert (Sicherung im Kernel-Ordner).
- Alte Ereignisse werden **deaktiviert**, nicht gelöscht.

## Fallen / gemessen

- Symcon lehnt `IPS_ApplyChanges` der eigenen Instanz innerhalb von `ApplyChanges` ab („re-entranter Aufruf abgelehnt“, 08.10.2026, Docker 9.1); der Prüfstand-Kernel lässt es zu. Deshalb übernimmt der Timer `Reapply` (100 ms) neu vergebene Kennungen.
- Über `IPS_RunScriptText(Wait)` (MCP) verschwinden `<?php` und `//…` auch **innerhalb von Zeichenketten**; im Werkzeug stehen sie deshalb als `'<' . '?php'` bzw. `'/' . '/'`.

Stand: geprüft gegen den Code am 08.10.2026

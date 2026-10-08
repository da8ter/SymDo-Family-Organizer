# Fälligkeit: eine Leiter für alle Oberflächen

Fälligkeitstexte („Heute, 14:00“, „vor 3 Tagen“) folgen in Web-App, Kacheln und iOS-App einer einzigen Regel. Diese Datei hält die Regel und ihre Begründung fest und nennt, wo die Gleichheit geprüft wird.

## Entscheidungen

Die Leiter (entschieden am 06.09.2026):

| Abstand | Text | Hinweis |
|---|---|---|
| unter 1 h | „in 20 Min.“ | nur bei Aufgaben mit Uhrzeit; Minuten gerundet, mindestens 1 |
| heute | „Heute“ bzw. „Heute, 14:00“ | Stunde ohne führende Null („9:00“) |
| morgen | „Morgen“ bzw. „Morgen, 9:00“ | |
| ab übermorgen | „22. Okt.“ bzw. „22. Okt., 9:00“ | Jahr nur außerhalb des laufenden Jahres |
| gestern | „Gestern“ | bewusst ohne Uhrzeit |
| vorgestern und älter | „vor x Tagen“ | unbegrenzt relativ, nie Datum, nie „vorgestern“ |

- **Die relative Zukunft („in 5 Tagen“) ist abgeschafft.** Ab übermorgen steht das Datum, denn einen Termin plant man nach dem Kalender.
- **Die Vergangenheit bleibt relativ.** „15. Jan.“ verrät nicht, dass etwas seit acht Monaten überfällig ist.
- **„Gestern“ ohne Uhrzeit,** weil bei Verpasstem der Tag zählt und nicht die Minute.
- **Gerechnet wird in Kalendertagen, nicht in 24-Stunden-Schritten.** „Morgen früh“ ist morgen, auch wenn es nur 14 Stunden bis dahin sind.
- **`dueDay` (Kalendertag ohne Zeitzone) gewinnt überall über den `due`-Zeitstempel,** weil der Zeitstempel die Mitternacht des Servers kodiert. Auf einem Gerät westlich des Servers zeigte er sonst den Vortag.
- `Intl.RelativeTimeFormat` läuft mit `numeric: 'always'` (Swift: `.numeric`), die Uhrzeit mit `hour: 'numeric'`. Beides stimmt mit der Ausgabe der iOS-App überein.
- Umgesetzt in `formatDueChip` (`SymDoWebApp/module.html`, die Kacheln über die Übernahme) und auf iOS in `ListsDomain.DueBadgeText`. `DueBadgeText` liefert Text und Überfällig-Farbe aus EINER Rechnung.
- Der Dashboard-Text „Seit 2 Tagen überfällig“ ist bewusst ein eigener Text und gehört nicht zur Leiter.

## Fallen

- **Wer eine Seite ändert, zieht beide Prüfstände nach.** `node SymDoWebApp/tools/due-parity.mjs` schneidet `dueDateOf`, `dayDiff` und `formatDueChip` wörtlich aus der Web-App und prüft sie bei fester Uhr (06.09.2026, 12:00 Berlin) und festem `de-DE` gegen dieselben Goldwerte wie `DueBadgeTextTests` im iOS-Paket. Den `dueDay`-Fall startet das Skript selbst als Unterprozess mit `TZ=America/New_York`, weil die Zeitzone je Prozess feststeht.
- Die „in 0 Min.“-Anzeige zappelte bei jedem Neuzeichnen. Deshalb sind die Minuten auf mindestens 1 geklemmt.
- Dass Push-Erinnerungen und KI-Chips denselben Wortlaut tragen, war Teil der Entscheidung. Die Texte dieser Wege entstehen aber nicht in `formatDueChip` (nicht am Code prüfbar).

Stand: geprüft gegen den Code am 08.10.2026 (`due-parity.mjs` läuft grün)

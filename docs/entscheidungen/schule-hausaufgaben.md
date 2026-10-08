# Schule: Hausaufgaben und Menüpunkt „Schule"

Hausaufgaben liegen im Gateway (`SymDoGateway/libs/Homework.php`, `HomeworkCalc.php`) und kommen von Hand, per Sprache/KI oder aus den Schulquellen WebUntis und LOGINEO. Angezeigt werden sie im Web-App-Bereich `homework` und in der Kachel `SymDoHomework` (Präfix `SDHW`).

## Entscheidungen

- **Eine selbst abgehakte Schulaufgabe wartet ohne Frist auf die Schule.** Hakt die Familie eine Aufgabe aus WebUntis oder LOGINEO ab, bleibt sie bei den offenen (durchgestrichen, Haken in Warnfarbe) und rutscht erst nach „Erledigt", wenn die Schule dasselbe meldet (`doneBy` = `untis`). Steuernd: `hwWartetAufSchule()` in `SymDoWebApp/module.html`. Das gilt ausdrücklich OHNE Frist – auch wenn die Aufgabe deshalb dauerhaft unter „Überfällig" steht, bis das Aufräumen sie nimmt. Eine Frist, nach der das eigene Häkchen endgültig zählt, wurde vorgeschlagen und vom Maintainer abgelehnt. Wer den Zustand für einen Fehler hält: er ist gewollt, bitte nicht „reparieren". Eigene Aufgaben sind sofort erledigt.
- **Das Häkchen der Schule ist eine Sperrklinke:** ein von der Schule gesetztes Erledigt kann in SymDo nicht zurückgenommen werden (`locked_by_school` in `Homework.php`, gilt für App, Kachel und Sprachdialog). Umgekehrt nimmt ein Abruf ein zu Hause gesetztes Häkchen nie zurück.
- **Zusammenführung je Quelle** (`HomeworkCalc::Zusammenfuehren(…, $quelle)`): WebUntis und LOGINEO zählen ihre Nummern unabhängig. Ohne Quelle im Schlüssel hätte der erste LOGINEO-Abruf alle WebUntis-Aufgaben für verschwunden gehalten und gelöscht. Gelöscht wird nur, was die Quelle im abgerufenen Fenster nicht mehr nennt.
- **Lern-Erinnerung zu Prüfungen** ist eine gewöhnliche Hausaufgabe mit `source 'exam'` und dem Prüfungsschlüssel als `srcId` – so wird sie auch ohne gespeicherten Prüfungsstand wiedergefunden und entsteht nie doppelt. Verlegt → zieht mit; selbst gelöscht → bleibt gelöscht. Vorlauf `UntisExamStudyDays` (Standard 7, 0 = aus).
- **Kachel = Web-App:** `SymDoHomework/module.html` ist die Web-App, wortgleich übernommen (`tools/uebernahme-webapp.py`); Unterschied ist nur der Zustand (`tabs` nennt nur `homework`). Änderungen gehören in `SymDoWebApp/module.html`. Die Kachel spricht über das AiCall-Relay mit dem Gateway; dafür muss ihre GUID in der Weißliste des Gateways stehen (`AppCore::IsSymDoWebAppInstance`).
- **Die Hausaufgaben-Kachel lädt den Stundenplan**, obwohl sie kein Raster zeigt (`loadTimetable` hängt an `dashboard || homework`): Symbol und Farbe des Fachs stehen im Fachkatalog des Plans, die Fachliste im Plan des Kindes. Ohne ihn ist jede Zeile ein grauer Kasten und man kann nichts anlegen. Ist in der Web-App-Instanz kein Plan eingeschaltet (`TimetableChoice` leer), sieht die Kachel genau so aus.

## Menüpunkt „Schule" in der Web-App

- **Gebündelt wird die Leiste, nicht der Inhalt.** Es gibt kein `#screen-school`; `activeTab` bleibt `homework`/`plan`/`edumaps`, `SCHOOL_TABS` sagt nur, welche Knöpfe zu einem zusammengefasst werden. Gründe: an jedem Bereich hängt ein gewachsener Zeichenweg, und die Kacheln tragen je genau einen Bereich – dort darf nichts gebündelt werden.
- **`t.dashboard` ist das Merkmal der vollen Oberfläche.** Daran hängen Bündelung, „nur beim Kind" und der Stundenplan-Bereich. In einer Kachel ist es `false`; dort erscheint der eine Bereich immer, und die Avatarleiste im Bereich filtert.
- Unter zwei verfügbaren Bereichen bleibt die Umschaltleiste weg; der Knopf führt zum zuletzt genutzten Bereich.

## Fallen

- **`renderInto` liefert `false`, wenn sich nichts geändert hat**, und lässt den Inhalt stehen. Wer danach trotzdem Handler bindet, hängt einen zweiten Satz an dieselben Knoten (der Abschnitt „Erledigt" klappte auf und sofort wieder zu). Also: `if (renderInto(...)) binden(...)`.
- **`mitgliedWaehlen()` muss den offenen Bereich selbst neu zeichnen:** `applyTabVisibility()` ruft `setTab`/`renderAll` nur, wenn der aktive Bereich verschwunden ist. Bereiche ohne eigene Avatarleiste (Klassenseiten) zeigten sonst nach dem Wechsel die Seite des Geschwisterkindes.
- Aufbewahrung (`HomeworkCalc::Aufbewahrung`): erledigte fallen `KEEP_DONE_DAYS` (14) nach dem Abhaken heraus – das gilt auch für eine selbst abgehakte, noch auf die Schule wartende Aufgabe –, offene `KEEP_OPEN_DAYS` (60) nach der Fälligkeit.

Stand: geprüft gegen den Code am 08.10.2026

# Kacheln: Last in der Visu (Push, Größe, Animation)

Die Tile-Visu lädt jede HTML-Kachel als eigenes Dokument und hält sie über WebSocket aktuell. Diese Datei hält fest, was 2026 gemessen wurde, welche Bremsen daraus entstanden sind und welche Kandidaten offen sind.

## Entscheidungen

- **Den vollen Stand gibt es nur bei einer Änderung: Web-App-Kopien** (`libs/KachelStand.php`, `fbe5c1b`, 26.09.2026). Vorher fragten die Listen-Kacheln alle 15 s per `requestAction('GetState')` und bekamen jedes Mal den ganzen Stand (Einkauf gemessen 2 × 164 kB je 30 s). Jetzt trägt jeder Stand einen `stateHash`, die Kachel schickt ihn mit, und bei Gleichheit sendet das Modul nichts. Kacheln mit Prüfwert fragen nicht mehr im Takt, sondern nur noch beim Sichtbarwerden, weil die Module jede Änderung selbst pushen. Der Prüfwert wird über die Daten mit festen Kodier-Optionen gerechnet und nicht über den versandten Text. Sonst ergäbe der Anfangsstand (`JSON_HEX_TAG`) einen anderen Wert als der Push.
- **Den vollen Stand gibt es nur bei einer Änderung: kleine Kacheln** (`libs/KachelPush.php`). Das betrifft Einkaufs- und ToDo-Übersicht, Essensplan, Routinen und VRR. Ein `VM_UPDATE` zählt dort nur mit `$Data[1] === true`; fehlt der Wert, zählt es. Eine Nachricht, die der zuletzt gesendeten gleicht, geht nicht erneut hinaus. Mehrere Ereignisse werden per `RegisterOnceTimer` zu einem Push gebündelt.
- **Korrekturzustand nach einer Aktion aus der Kachel.** Kacheln, die einen Tipp sofort anzeigen, brauchen bei einem Fehlschlag gerade die unveränderte Nachricht, die die Filter sonst wegnehmen. Deshalb gehen nach einer Aktion alle Aktualisierungen hinaus. Der Zustand endet mit der ersten Nachricht, die mindestens 1,5 s nach der letzten Aktion gesendet wird.
- **Filter nur für Kachel-Nachrichten, nie für Steuerlogik.**
- **Große, selten veränderte Daten reisen als versionierte Datei statt im Stand.** Die Produktbild-Karte der Einkaufs-Kachel (rund 3 300 Namen, ~105 kB) kommt als `availableImagesUrl` über den Hook der Einkaufsliste und wird ein Jahr gecacht. Gemessen sank der Push dadurch von 145 auf 39 kB. Die Einkaufs-Übersicht holt `SL_GetOverviewState` statt des ganzen Stands (100 kB → 1–2 kB je Push). Der Stundenplan sendet nur bei einer Änderung und nimmt die laufende Minute nicht in den Prüfwert.
- **Ein gemeinsames App-Skript statt Inline-Kopie je Kachel**, siehe `kacheln-uebernahme-skript.md`.
- **Keine Dauer-Animation im Leerlauf** (28.09.2026, durch `SymDoWebApp/tests/DaueranimationTest.php` abgesichert). Schon EINE laufende Animation in einer sichtbaren Kachel, selbst ein 2×2-Pixel-Punkt, lässt die Flutter-Visu die ganze Seite mit 60 Hz neu zusammensetzen. Das kostet rund 0,15–0,3 CPU-Kerne, vor allem im GPU-Prozess. Die Zahl der Animationen spielt kaum eine Rolle. Kacheln außerhalb des Bildschirms drosselt Chrome selbst, ein IntersectionObserver bringt deshalb wenig. Folgen:
  - Laufschrift läuft zweimal, danach steht der Anfang mit Ellipse, und Antippen startet sie neu.
  - „Verspätet“ ist nur rot und blinkt nicht.
  - Endlos läuft nur, was an eine Tätigkeit gebunden ist (Lauschen, Laden, Diktat, Scanner).

## Fallen

- Speicherangaben aus ps-RSS liegen unter macOS etwa um das Dreifache zu hoch. Belastbar sind Transfer, Dokumentgröße und Skriptzeit, für Speicher der Fußabdruck und der JS-Heap nach GC (Rezept: `../testen/visu-last-messen.md`).
- Eine Wechselanimation, die ohne Zustand weiterläuft, sieht im Code harmlos aus und kostet trotzdem die ganze Seite.

## Offen

- `SymDoToDoList`: `SyncPostComplete` ruft nach jedem Sync `SendState` auf, auch ohne Änderung. `SendState` sendet ohne Prüfwertvergleich, die Kachel bekommt also nach jedem Sync den vollen Stand.
- Die Kachel `SymDoWebApp` (Gateway-Stand) schickt die Bildkarte weiter im Stand mit (`images` in `module.php`), anders als die Einkaufs-Kachel.
- `app.css` auslagern (rund 218 kB je Web-App-Dokument), nach dem Muster von `app.js`.

Stand: geprüft gegen den Code am 08.10.2026 (Messwerte selbst nicht am Code prüfbar)

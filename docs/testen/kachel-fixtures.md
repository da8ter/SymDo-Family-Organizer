# Testen: Kacheln und Web-App im Headless-Browser fahren

Die Web-App und ihre Kachel-Kopien lassen sich ohne Symcon in einem Headless-Chrome starten und bedienen. Das Rezept folgt den Prüfständen unter `SymDoWebApp/tests/` (Browser-Teil mit `MIT_CHROME=1`). Diese Datei sammelt Aufbau und Fallen.

## Rezept

1. **Seite bauen.** `module.html` lesen und einen Treiber als `<script>` einsetzen, und zwar vor dem LETZTEN `</body>` (`strrpos` bzw. `rindex`, siehe Fallen). Ins Dateisystem schreiben und per `file://` laden.
2. **Laufzeitumgebung nachbilden:**
   - *als Web-App:* `window.__SYMDO__ = { apiBase, tabs, … }` setzen und die JSON-API über den vorgesehenen Haken `window.__symdoApiPost = (path, body) => Promise.resolve({ status, json })` ersetzen.
   - *als Visu-Kachel:* Attrappen für `requestAction` (Aufrufe mitschreiben) und `translate` gehören in den KOPF der Seite. Ohne `requestAction` stirbt das Skript beim ersten Melden, und es wird nichts gezeichnet. Die Listen-Kacheln brauchen zusätzlich `window.__SYMDO_KACHEL__ = { art: 'todo' | 'shopping' }` vor dem App-Skript. Der Zustand kommt über `window.handleMessage(<flacher Stand>)`, also über das, was das Modul in `GetVisualizationTile` einbettet (`type: 'state'`, `items`, …). Bei der Einkaufsliste ist das der INNERE `state` aus `SL_GetAppState`, nicht der Umschlag `{revision, kind, state}`.
   - `window.requestAnimationFrame = (f) => setTimeout(f, 0)`, sonst bleibt in Headless-Läufen manche Messung aus.
3. **Fahren, was ein Mensch tut:** Knöpfe per `.click()`, Wischen per synthetischen Maus- oder Touch-Ereignissen am ZEILENELEMENT. Die Web-App hört auf Maus und Touch, nicht auf Pointer Events.
4. **Ergebnis herausholen:** Der Treiber schreibt am Ende `document.title = 'FERTIG ' + JSON.stringify(ergebnis)`. Chrome läuft mit `--headless=new --dump-dom --virtual-time-budget=… --user-data-dir=<Wegwerfprofil>`, das Ergebnis wird aus `<title>` gelesen. Den Aufruf mit einer harten Frist versehen, damit ein hängendes Chrome den Prüfstand nicht mitreißt.
5. **Syntax vorab prüfen:** das größte `<script>` aus der Seite schneiden und `node --check` darauf laufen lassen. Geht schneller als ein Browserlauf, und ein SyntaxError meldet sich dort mit Zeilennummer.

## Fallen

- **Treiber vor das LETZTE `</body>`.** Die Druckvorlage (`doPrint`) baut in einem Template-String ein vollständiges HTML-Dokument samt `</body></html>`. Ein `replace('</body>', …)` trifft dieses innere Vorkommen, zerreißt den Skriptblock, und die Seite meldet nur „Uncaught SyntaxError: Unexpected end of input“.
- **Touch-Ereignisse am Element auslösen, nicht an `document`.** Die Scrollbereiche stoppen die Weitergabe von `touchmove`. Ein am `document` ausgelöstes Ereignis umgeht genau den Weg, der am Gerät bricht (siehe `../entscheidungen/listen-gesten.md`).
- **Fehler in `async`-Treibern bleiben still.** `window.onerror` sieht sie nicht, deshalb immer auch auf `unhandledrejection` lauschen und den Fehler ins Ergebnis schreiben.
- **Handler an `DOMContentLoaded`:** Kacheln wie der Essensplan binden ihre Knöpfe erst dort. Ein geskripteter Klick am Dateiende läuft davor ins Leere, deshalb gehört er in einen eigenen `DOMContentLoaded`- oder `load`-Listener.
- **Headless legt mindestens 500 px Breite an**, `--window-size` darunter schneidet nur den Screenshot ab. Breiten unter 500 px misst man im iframe, siehe `layout-messen.md`.
- **`/icons.js` lädt über `file://` nicht.** Die `<i>`-Platzhalter bleiben schmal, und zwar gleichmäßig. Layout-Fehler, die erst durch die nachgelieferten Symbole entstehen, bleiben so unsichtbar (siehe `layout-messen.md`).
- **Nur den eigenen Browser beenden.** Ein Prüflauf erkennt seinen Chrome am eigenen Profilpfad und beendet nie Browser-Prozesse pauschal nach Namen.

Stand: geprüft gegen den Code am 08.10.2026

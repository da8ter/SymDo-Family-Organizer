# Kacheln: HTML-SDK, Vollbild, Ränder, Icons

## HTML-SDK in Kürze (Doku)

- Aktivieren mit `SetVisualizationType(...)`, Inhalt liefert `GetVisualizationTile(): string` (ab 7.1). Die Funktion wird **einmal** zu Beginn aufgerufen; Änderungen zur Laufzeit gehen über Nachrichten.
- Modul → Kachel: `UpdateVisualizationValue(...)`, in der Kachel per JavaScript-Funktion `handleMessage(daten)` empfangen. Wie diese Nachrichten zugestellt werden: [kachel-nachrichten.md](kachel-nachrichten.md).
- Kachel → Modul: `requestAction(ident, wert)` im JavaScript ruft `RequestAction` im Modul.
- Texte lassen sich mit `translate`/`translateHTML` über die `locale.json` übersetzen.
- `openObject(id)` öffnet ein Objekt in der Visualisierung; Nicht-Kategorien erscheinen als maximierte Kachel.

## Visualisierungstypen und Vollbild

- **Doku:** `INSTANCE_VISUALIZATION_TYPE_NONE` 0, `..._HTML` 1 (HTML nur in der normalen Kachel; maximiert zeigt die Visu die Variablenliste), `..._HTML_FULLSCREEN` 2 (ab 9.1, HTML auch in der geöffneten Kachel), `..._FORM` 3 (Formular als Visualisierung).
- **Gemessen (07.10.2026, Symcon 9.1):**
  - Die maximierte Ansicht lädt **dasselbe** `GetVisualizationTile`-HTML in ein großes iframe.
  - Die URL-Parameter sind gleich bis auf `margintop` (mehr Platz für die Kopfzeile). Einen Parameter „maximiert“ gibt es nicht; die Kachel muss sich an ihrer Größe orientieren (Media Queries, `innerWidth`).
- **Muster:** Auf älteren Versionen fehlt die Konstante, deshalb `defined('INSTANCE_VISUALIZATION_TYPE_HTML_FULLSCREEN') ? INSTANCE_VISUALIZATION_TYPE_HTML_FULLSCREEN : 1`. So in allen Kachel-Modulen dieser Bibliothek.
- **Falle:** `html, body { width: 100% }` plus der Seitenrand, den die Visu am Rumpf setzt, macht den Rumpf breiter als die Kachel. Das fällt erst maximiert auf (rechte Spalte angeschnitten). Abhilfe: `body { width: auto }` bzw. den Rand ins eigene Wurzelelement legen.

## Ränder kommen von Symcon

- **Was:** Die Visu legt Kachelname und Vollbild-Symbol über den oberen Teil der Kachel. Wie viel Platz das braucht, steht in der iframe-Adresse: `margintop`, `marginside`, `marginbottom` (Pixel). Diese Parameter sind nicht dokumentiert, aber stabil beobachtet (zuletzt 07.10.2026, Symcon 9.1).
- **Folge:** Feste Ränder passen nur zu einer Bildschirmgröße und einem Skin.
- **Muster:** Die Werte per `URLSearchParams` lesen, in CSS-Variablen ablegen und als Padding des eigenen Wurzelelements nutzen; kleine Vorgaben nur als Rückfall. `html`/`body` dürfen auf 0. Ausnahme: Kacheln mit zentriertem, selbst begrenztem Inhalt, der keinen Rand berührt; dort wirken die Werte doppelt.

## Icons: nur der Light-Schnitt

- **Doku:** `<script src="/icons.js"></script>` einbinden; nutzbar seien `fa-light`, `fa-brands` und die Symcon-eigenen Icons über `fa-kit`.
- **Gemessen (26. und 28.09.2026, Symcon 9.1):**
  - `fa-solid`, `fa-regular` und `fa-thin` bleiben ein **leeres** `<i>`.
  - `fa`, `fa-duotone` und `fa-sharp` bekommen ein Platzhalter-SVG, das dauerhaft pulsiert (kostet Rechenzeit).
  - Für `fa-brands` und `fa-kit` widersprechen sich zwei Messungen (einmal gezeichnet, einmal leer); `FontAwesome.findIconDefinition` fand beim zweiten Mal nur `fal`.
  - `/icons.js` ist rund 3,4 MB groß und wird in **jedem** Kachel-iframe geladen und ausgeführt.
  - `/icons.js` ersetzt `<i>`-Elemente erst nachträglich durch SVG. Layout-Tests müssen das berücksichtigen, sonst bleiben Fehler unsichtbar.
- **Muster:** Immer `fa-light`; Rückfälle in Icon-Auflösern ebenfalls auf `fa-light`. Gefüllte Formen (z. B. ein ausgefülltes Herz) als Inline-SVG. So im Repo umgesetzt.

## Größe einer Kachel

- Die HTML-Antwort von `GetVisualizationTile` unterliegt nicht `ScriptOutputBufferLimit` (gemessen 11.09.2026, Symcon 9.1). Große Kacheln laden trotzdem in jedes iframe einzeln; jede Kachel, die die ganze Web-App mitbringt, kostet rund 1 MB je Instanz.

---

Stand: geprüft am 08.10.2026 (Doku und Repo-Code); Messwerte mit Datum

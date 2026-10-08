# Testen: Layout einer Kachel messen

Wie man prüft, ob ein Layout in einer Kachel wirklich passt. Mehrere Messungen haben „passt“ gemeldet, während beim Nutzer abgeschnitten oder umgebrochen wurde. Dieses Rezept vermeidet die bekannten Fehlerquellen.

## Rezept

1. **In einem iframe der Kachelbreite messen, nicht über die Fensterbreite.** In der Tile-Visu ist die Kachel viel schmaler als das Fenster. Mehrere Breiten nebeneinander in einem weiten Fenster messen, dann greifen die Media Queries auf die iframe-Breite, genau wie in der Visu:
   ```html
   <iframe src="kachel.html" width="360" height="700"></iframe>
   <iframe src="kachel.html" width="430" height="700"></iframe>
   ```
   Die Kachel im iframe braucht dieselben Attrappen wie im Prüfstand (`requestAction`, `translate`, siehe `kachel-fixtures.md`).
2. **Die Symcon-Ränder mitgeben:** `kachel.html?margintop=…&marginside=…&marginbottom=…`. Ohne sie misst man eine Kachel ohne Titelzone.
3. **Die Nachlieferung der Symbole nachstellen.** `/icons.js` ersetzt jedes `<i class="fa-…">` erst NACH dem Zeichnen durch ein `<svg>`. Der Treiber misst einmal, ersetzt dann alle `<i>` durch ein `<svg>` realistischer Breite (rund 14 px) und prüft erneut. Die Web-App misst deshalb nach (`layoutTodoMeta`: MutationObserver auf die Ersetzung, entprellt, dazu `document.fonts.ready`).
4. **Vor jeder Diagnose `clientWidth` und `scrollWidth` melden lassen.** Liegt `clientWidth` bei 500, ist das die Untergrenze von Headless-Chrome und kein Layout-Fehler.
5. **Am Ende hinsehen:** Screenshot mit `--force-device-scale-factor=2`. Eine Zahl, die „lesbar: true“ sagt, ersetzt den Blick nicht. Beide Fehlmessungen unten sind erst im Bild aufgefallen.

## Fallen

- **Headless-Chrome legt mindestens 500 px Breite an.** `--window-size=430,…` liefert einen 430 px breiten Screenshot einer 500 px breiten Seite. Rechts fehlen 70 px mitten im Text, das sieht aus wie ein Layout-Fehler. Schmaler geht es nur im iframe oder per Geräte-Emulation über das DevTools-Protokoll.
- **`getComputedStyle(el).font` ist in Chrome leer.** Ein Messklon mit `font: <leer>` erbt die Schrift des Rumpfes und rechnet mit falschen Breiten. Die Einzelwerte übernehmen: `fontFamily`, `fontSize`, `fontWeight`, `letterSpacing`, `lineHeight`.
- **Bei `-webkit-line-clamp` sagen `scrollWidth`/`scrollHeight` nichts** (sie entsprechen dem sichtbaren Kasten), ebenso bei `text-overflow: ellipsis` auf umbrochenen Zeilen. Tragfähig ist nur ein Klon mit denselben Schriftwerten, demselben `white-space` und derselben Breite, verglichen mit der erlaubten Zeilenzahl.
- **`clientWidth` enthält das Padding.** Verfügbarer Platz ist `clientWidth - paddingLeft - paddingRight`.
- **`getComputedStyle(el).gridTemplateColumns` liefert bei `display: none` den unaufgelösten Wert** (`repeat(auto-fill, …)`). Die Spaltenzahl lässt sich dann besser aus den linken Kanten der Kinder bestimmen, aber nur bei Containern ohne überspannende Überschriften.
- **Hintergründe auf `::before`/`::after`** sieht `getComputedStyle(el)` nicht. Für Pseudoelemente `getComputedStyle(el, '::before')` fragen.
- **Erst strukturell absichern, dann messen.** Damit nichts abgeschnitten werden KANN, gehören `flex-wrap` und `min-width: 0` ins CSS. Die Messung steuert danach nur noch die Kosmetik.

Stand: geprüft gegen den Code am 08.10.2026 (Browser-Verhalten nicht am Code prüfbar)

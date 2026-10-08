# Kacheln: Stilregeln (Knöpfe, Symbole, Scrollleisten, Ränder)

Kacheln und Web-App sind ein Produkt. Diese Datei hält die Regeln fest, nach denen eigenständige Kacheln (Essensplan, Routinen, Ämtchen, Stundenplan, VRR und weitere) gestaltet werden, damit sie nicht fremd neben der Web-App wirken.

## Entscheidungen

- **Die Referenz ist immer `SymDoWebApp/module.html`.** Vor dem Gestalten eines Knopfs oder Bausteins dort nachschlagen, nichts eigenes erfinden. Die Kernsprache:
  - Tokens auf `:root`: Fläche `--surface` = Inhaltsfarbe 7 %, Rand `--border` = 14 %, gedämpft `--muted` = 45 % (jeweils `color-mix` mit `transparent`), Rot `#ff4d4d`, Akzent `--accent-color`.
  - Dialog- und Blattknöpfe (`.overlay-footer button`): `padding: 12px; border-radius: 12px; font-weight: 700`, randlos auf der Fläche. `.primary` hat einen Akzent-Hintergrund mit weißer Schrift, `.destructive` roten Text auf der Fläche, `:disabled` die Deckkraft .4.
  - Runde Knöpfe (`.round-btn`): Kreis mit Akzent und weißer Schrift. `.secondary` = Fläche plus 1px Rand.
- **Symbole kommen aus Font Awesome über `/icons.js` (Schnitt `fa-light`), nie als Emoji** (Essensplan: `3ab7ee9`). Übliche Zuordnung: Schließen `fa-xmark`, Bearbeiten `fa-pencil`, In den Wagen `fa-cart-plus`.
- **Scrollende Bereiche bekommen das Hausrezept, nie den nativen Balken.** Der native Balken ist 11 px breit, wird in Safari hell gemalt und bleibt bei `overflow-y: scroll` dauerhaft sichtbar. Das Rezept: 6 px breit, transparente Spur, ein Griff in Akzentfarbe zu 70 % und `border-radius: 999px`. Den Standard (`scrollbar-width: thin; scrollbar-color: …`) gibt es nur hinter `@supports not selector(::-webkit-scrollbar)`. Vorlage ist `.screen` bzw. `.overlay-body` in der Web-App.
- **`overflow-y: scroll` statt `auto`:** Der Streifen bleibt reserviert, und der rechte Abstand springt nicht. Waagerechte Streifen (Chips, Vorschlagsleisten) blenden den Balken dagegen ganz aus (`scrollbar-width: none` plus `::-webkit-scrollbar { display: none }`).
- **Die Kachelränder kommen von Symcon, nicht von der Kachel.** Die Visu schreibt Instanzname und Vollbild-Symbol über den oberen Teil der Kachel und übergibt den nötigen Platz als URL-Parameter `margintop`, `marginside` und `marginbottom` (Pixel). Die Kachel liest sie so früh wie möglich per `URLSearchParams` in `--sym-mt`, `--sym-ms` und `--sym-mb` und nutzt sie als Padding des eigenen Wurzelelements. `html` und `body` dürfen dabei auf 0 stehen. Feste Werte wie `padding-top: 42px` passen nur zu einer Bildschirmgröße und einem Skin. Eigene Vorgaben gelten nur als kleiner Rückfall.
- **Der Scrollbalken sitzt mittig im seitlichen Randstreifen, nicht innen am Inhalt** (Vorlage `SymDoTimetable/module.html`). Der Rahmen bekommt das Padding `max(var(--sym-mt), env(safe-area-inset-top,0px)) var(--sb-pad) var(--sym-mb) var(--sym-ms)`, der Scrollbereich darin `padding-right: var(--sb-pad)`. Dabei gilt `--sb-pad = max(0px, calc((var(--sym-ms) - var(--sbw)) / 2))`. Rechts ergeben `--sb-pad` + `--sb-pad` + Balken wieder genau `--sym-ms`. Bei `marginside=16` liegt die Balkenmitte gemessen 8 px vor der Kante. Der Stundenplan misst die echte Balkenbreite zur Laufzeit, weil sie je nach Umgebung 6–15 px beträgt.
- Bestehende Kacheln werden nicht pauschal umgestellt. Neue und überarbeitete Kacheln folgen diesen Regeln.

## Fallen

- **`scrollbar-width`/`scrollbar-color` und `::-webkit-scrollbar` schließen sich aus.** Ist der Standard gesetzt, ignoriert WebKit die Pseudoelemente. Gemessen wurden 11 statt 6 px, und die Spur wurde hell. Deshalb steht der Standard hinter dem `@supports`-Riegel.
- **Jeder Selektor braucht sein eigenes Pseudoelement.** `#a, #b::-webkit-scrollbar` trifft nur `#b`. Richtig ist `#a::-webkit-scrollbar, #b::-webkit-scrollbar`.
- **Nicht scrollende Teile neben dem Scrollbereich** (Kopfzeile, Chips, Hinweise) hängen direkt am Rahmen und bekommen rechts nur `--sb-pad`. Ohne Ausgleich ragen sie in den Randstreifen (bei `marginside` 16 gemessen 11 px zu weit). Sie brauchen `margin-right: calc(var(--sb-pad) + var(--sbw))`, in den kleinen Kacheln über die Klasse `.randrest`.
- **Die Variable für den Akzent heißt nicht überall gleich:** In den großen Kacheln (Web-App-Kopien, Stundenplan) heißt sie `--accent`, in den kleinen Kacheln (Essensplan, Routinen, Ämtchen, VRR, Sprachdialog) `--akzent`.
- **Ausnahme Ränder:** Die Übernahme der Systemränder gilt für Kacheln mit Kopfzeile oder randnahem Inhalt. Bei zentriertem Inhalt, der seine Breite selbst begrenzt, wirken die Ränder doppelt. Dort vorher prüfen, ob der Inhalt den Rand überhaupt berührt.

Stand: geprüft gegen den Code am 08.10.2026

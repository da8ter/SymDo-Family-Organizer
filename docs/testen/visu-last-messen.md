# Testen: Last einer Kachel in der Visu messen

So misst man, was eine Kachel die Tile-Visu kostet: Verkehr, Dokumentgröße, CPU im Leerlauf und Speicher. Die Messwerte und die daraus gezogenen Entscheidungen stehen in `../entscheidungen/kacheln-ressourcen.md`.

## Rezept

- **Verkehr:** Die Visu-Seite per Chrome DevTools Protocol öffnen und die WebSocket-Rahmen je Kachel zählen und summieren (`Network.webSocketFrameReceived`). Eine ruhige Phase von 30 s bis 2 min ohne Bedienung zeigt, was im Leerlauf ohne Änderung hinausgeht. Danach eine einzelne Änderung auslösen und deren Push messen.
- **A/B-Vergleich einer Kachel ohne Webserver:** Die Variante per `Fetch.requestPaused` / `Fetch.fulfillRequest` direkt aus dem Messskript ausliefern, statt einen lokalen HTTP-Server zu starten.
- **Dokumentgröße:** Die gepackte Größe der `GetVisualizationTile`-Antwort je Kachel messen. Für die Startseite die Summe über alle Kacheln bilden.
- **CPU im Leerlauf:** CPU-Zeit je Chrome-Prozess (Renderer, GPU, Browser, Netzwerk) über ein festes Fenster messen. Danach alle Animationen anhalten (`document.getAnimations().forEach(a => a.pause())`, SMIL über `svg.pauseAnimations()`) und erneut messen. Die Differenz ist der Preis der Animationen.
- **Eine einzelne Kachel isoliert:** Die Kachel über `srcdoc` in die Visu-Hülle laden. Die Grundlast von Browser und Netzwerk ist dabei hoch, verglichen werden nur Renderer und GPU.
- **Speicher:** Unter macOS den Fußabdruck (`top`, Spalte MEM) und den JS-Heap nach einer erzwungenen Speicherbereinigung messen, nicht ps-RSS.

## Fallen

- **ps-RSS überzeichnet unter macOS etwa um das Dreifache.** Frühere Angaben von „18–20 MB je Kachel“ waren deshalb zu hoch. Transfer, Dokumentgröße und Skriptzeit sind davon nicht betroffen.
- **Ein Zähler angehaltener Animationen zählt jedes animierte SVG-Symbol mit.** Die Zahl ist kein Maß für die Last, ausschlaggebend ist, ob überhaupt etwas läuft.
- **Software-Rendering verfälscht hier nicht:** Mit echter GPU statt SwiftShader ergaben sich dieselben Zahlen.
- **Kacheln außerhalb des Bildschirms drosselt Chrome selbst.** Eine Animation kostet nur in einer sichtbaren Kachel, deshalb muss die gemessene Kachel im Bild liegen.
- **Kernel-Meldungen (`VM_UPDATE` virtueller Testgeräte im Sekundentakt) erscheinen im WebSocket-Verkehr,** auch wenn sich ihr Wert nicht ändert. Sie gehören nicht zur Kachel und müssen beim Zählen herausgefiltert werden.

Stand: geprüft gegen den Code am 08.10.2026 (Messverfahren, nicht am Code prüfbar)

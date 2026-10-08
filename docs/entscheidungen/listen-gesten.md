# Listen: Wischen und Umsortieren

Die Zeilen der Web-App und ihrer Kachel-Kopien kann man wischen (Löschen/Bearbeiten), ToDos lassen sich außerdem umsortieren. Diese Datei hält fest, warum das Umsortieren nur über einen Griff geht und welche Ereigniswege dafür nötig sind.

## Entscheidungen

- **Natives Scrollen bleibt, Umsortieren nur über den Griff.** Der Zeilenkörper hat `touch-action: pan-y`, das vertikale Scrollen mit Schwung gehört also dem Browser. Der waagerechte Wisch läuft per JavaScript: links löscht, rechts bearbeitet. Umsortiert wird nur über `.todo-handle` (Symbol `fa-grip-dots-vertical`), und nur dort gilt `touch-action: none`. Eine Berührung, die am Griff beginnt, gehört damit ganz dem Skript und scrollt nie, während der Rest der Liste nativ scrollt. Die gezogene Karte bekommt eine Akzent-Tönung (`.todo-card.todo-dragging .todo-content` = `color-mix(accent 22 %, --row)`).
- **Kein Langdruck zum Umsortieren, und das bitte nicht erneut versuchen.** Auf iOS schließen sich natives Schwung-Scrollen und ein JS-gesteuerter vertikaler Zug auf DERSELBEN Berührung aus. Mit `pan-y` gehört das Scrollen dem Browser, `touchmove` ist nicht abbrechbar, und `preventDefault` wirkt nicht. Mit `none` fehlt das native Scrollen, und manuelles Scrollen hat keinen Schwung. Auch `touch-action` mitten in der Geste umzuschalten oder per `overflow: hidden` einzufrieren hat am iPhone nicht funktioniert. Beim Versuch scrollte das Umsortieren, und Scrollen löste versehentlich Umsortieren aus.
- **Maus- und Touch-Ereignisse, keine Pointer Events.** Die Desktop-Webview der Symcon-Visu liefert keine Pointer Events, ein reiner Pointer-Wisch wäre dort tot. Siehe `bindCardSwipe` und `bindTodoGesture`.
- `user-select: none` und `-webkit-touch-callout: none` auf den Zeilen verhindern Textauswahl und das Kontextmenü beim Halten.
- Ist das Wischen abgeschaltet (`.no-swipe`), steht der Zeilenkörper auf `touch-action: auto`, und die waagerechte Geste gehört wieder dem Browser.

## Fallen

- **Die Dokument-Listener für Zug und Wisch müssen in der Capture-Phase hängen** (`{ capture: true }`, Entfernen mit `true`). `bindScrollContainment` hält Scrollbereiche zusammen und stoppt dazu die Weitergabe von `touchmove`. Grund: `html-sdk.js` der Visu lauscht an `window` auf `wheel` und `touchmove` und scrollt sonst die ganze Visu mit. Ein Listener in der Bubble-Phase an `document` sieht diese Ereignisse nie. Der Fehler zeigte sich nur am echten Gerät.
- **Ein Prüfstand, der `touchmove` direkt auf `document` auslöst, täuscht.** Das Ereignis muss am Zeilenelement entstehen und durch den Container laufen, sonst bleibt der obige Fehler unsichtbar.
- Eine Magic Mouse liefert nie reines `deltaY`. Ohne `stopPropagation` auch bei waagerechtem Rad landete gemessen ein Teil jeder Wischgeste (6 von 22 Ereignissen) bei der Visu.
- Die Einkaufsartikel haben in der Web-App nur den Wisch und keinen Griff. Die früheren Klassen `.drag-handle` und `.item.dragging` stammen aus der alten, handgepflegten Einkaufs-Kachel und gibt es nicht mehr.

Stand: geprüft gegen den Code am 08.10.2026 (iOS-Verhalten am Gerät gemessen, nicht am Code prüfbar)

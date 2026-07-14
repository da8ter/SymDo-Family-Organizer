# ToDo Liste / Einkaufsliste

Diese Modul-Bibliothek enthält zwei Module: Modul 1 stellt eine ToDo-Liste für die Tile-Visualisierung bereit. Optional mit Synchronisation für Google Tasks, Microsoft To Do und CalDAV. Modul 2 ist eine Einkaufsliste für die Tile-Visualisierung.

## ToDo Liste
Aufgabenverwaltung für die IP-Symcon Kachel-Visualisierung.

- Aufgaben direkt in der Visualisierung anlegen, bearbeiten und abhaken
- Zwei-Wege-Synchronisation mit Google Tasks, Microsoft To Do und CalDAV (z. B. Nextcloud)
- Push-Benachrichtigungen vor Fälligkeit, Vorlaufzeit pro Aufgabe einstellbar
- Wiederkehrende Aufgaben (wöchentlich bis jährlich oder eigenes Intervall); erledigte Aufgaben werden zum nächsten Termin automatisch wieder geöffnet
- Aufgaben mit Notiz, Priorität, Menge und Fälligkeit — mit Uhrzeit oder ganztägig
- Übersicht über offene, heute fällige und überfällige Aufgaben; optional als separate Übersichtskachel
- Sortierung nach Fälligkeit, Priorität, Datum oder Titel; manuelle Reihenfolge per Drag & Drop
- Löschen per Wischgeste; optional automatisches Entfernen erledigter Aufgaben
- Mehrere Listen über separate Instanzen
- Zählerwerte (offen/überfällig/heute fällig) als Variablen für eigene Automationen; alle Funktionen per PHP-Skript nutzbar
- Zusätzliche HTML-Ausgabe für IPSView

![ToDo List](https://github.com/da8ter/images/blob/main/todo.png)

## Einkaufsliste
Einkaufsliste für die IP-Symcon Kachel-Visualisierung.

- Artikel anlegen, bearbeiten, abhaken und löschen
- Suchvorschläge auf Basis häufig verwendeter Artikel
- Automatische Mengenerhöhung, wenn ein Artikel bereits auf der Liste steht
- Gruppierung nach Kategorien; Reihenfolge der Kategorien frei konfigurierbar
- Barcode-Scanner über die Gerätekamera mit automatischer Produkterkennung; alternativ Anbindung eines externen Scanners über eine Variable
- Favoritenlisten (z. B. Wocheneinkauf) — komplette Artikelgruppen mit einem Klick übernehmen
- Abgehakte Artikel wandern in den Bereich „Zuletzt benutzt" und lassen sich von dort wieder auf die Liste setzen
- Funktionen „Alles erledigt" und „Benutzte Artikel löschen" für die ganze Liste
- Optionale Produktbilder, im Backend abschaltbar
- Druckfunktion mit drei Layouts (kompakt bis detailliert mit Bildern und Notizen)
- Artikelanzahl als Variable für eigene Automationen

![Shopping List](https://github.com/da8ter/images/blob/main/shoppinglist.png)

## Anleitungen:

- [ToDo Liste](ToDoList/README.md)
- [Einkaufsliste](ShoppingList/README.md)
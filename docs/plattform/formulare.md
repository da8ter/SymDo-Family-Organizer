# Konfigurationsformulare

## Listen: nur gespeicherte Spalten überleben

- **Doku (List):** Beim Übernehmen werden nur Spalten gesichert, die editierbar sind oder `save: true` haben; `save` ist standardmäßig `true` nur bei editierbaren Spalten (oder bei `add` mit automatischer Generierung). Werte ohne passende Spalte werden immer gespeichert.
- **Folge für Module:** Eine Kennungsspalte ohne `edit` (z. B. die ID einer Instanz) fällt beim Übernehmen **lautlos** weg; zurück kommen Zeilen nur mit den editierbaren Feldern. Mehrfach erlebt, jedes Mal ohne Fehlermeldung.
- **Muster:**
  - `'save' => true` an jede Kennungsspalte, sichtbar oder unsichtbar.
  - Bereits verstümmelte Stände beim Übernehmen positionsgetreu reparieren (Kennung über den Zeilenindex nachtragen), aber **nur bei gleicher Zeilenzahl**, sonst nicht raten.
  - Für positionsgetreue Listen: alle Zeilen in fester Reihenfolge bauen, `add`/`delete` aus, neue Einträge ans Ende.
  - Zeilen aus dem Formular immer durch eine Weißliste zurück in den eigenen Bestand führen; ein Formular, das Felder nicht kennt, darf sie nicht löschen.

## Fehlende Zellen fallen auf `add` zurück

- **Was:** Fehlt einer Zeile der Wert einer Spalte, zeigt die Konsole den `add`-Wert der Spalte (nachgelesen im Quelltext der Konsole, 11.09.2026, Symcon 9.1; die Doku beschreibt dasselbe für generierte `add`-Werte).
- **Folge:** Eine neue Spalte braucht keine Wanderung über bestehende Zeilen.
- **Muster:** Im PHP dieselbe Vorgabe annehmen (`$zeile['feld'] ?? <add-Wert>`), sonst weichen Anzeige und Verhalten auseinander.

## onClick sieht den aktuellen Formularstand

- **Was:** Vor dem Ausführen eines `onClick`/`onChange` setzt die Konsole **jedes benannte Feld** als PHP-Variable, samt ungespeicherter Änderungen. Listen (`List`, `Configurator`) kommen als **`IPSList`**-Objekt an (nachgelesen 11.09.2026, Symcon 9.1).
- **Falle:** `json_encode($Liste)` liefert nur die **ausgewählte** Zeile; alle Zeilen gibt `iterator_to_array($Liste)`.
- **Muster:** Knöpfe arbeiten mit dem übergebenen Stand statt `IPS_SetProperty` im Rücken des Nutzers; z. B. `IPS_RequestAction($id, "Fall", json_encode(iterator_to_array($Liste)))` (so im Repo).
- **Doku-Ergänzung:** Skripte in einem Listen-Dialog erhalten nur die Felder des Dialogs, nicht die Liste.

## UpdateFormField erreicht auch Zeilen und Spalten

- **Was:** Bei einer Liste zerlegt die Konsole den Parameternamen an Punkten (nachgelesen 11.09.2026, Symcon 9.1):
  - `values` ersetzt die ganze Liste (Scrollstand und Auswahl bleiben), `values.N` bzw. `values.N.feld` eine Zeile bzw. Zelle.
  - `columns` ersetzt die Spaltendefinition, `columns.N` bzw. `columns.N.edit` eine Spalte bzw. nur ihren Editor.
- **Folge:** Die Optionen einer Auswahlspalte lassen sich zur Laufzeit austauschen.
- **Muster:** Komplexe Werte als JSON-**String** übergeben (Doku: „bei komplexen Parametern JSON-codiert“).
- **Doku:** `name` und `type` eines Feldes sind nicht änderbar. Änderungen per `UpdateFormField` bleiben in wiederverwendeten statischen Dialogen erhalten.

## Felder für noch unbekannte Properties

Zeigt ein Formular ein Feld für eine Property, die die Instanz noch nicht kennt, scheitert „Übernehmen“ für die ganze Konfiguration. Muster: neue Felder nur einblenden, wenn die Property existiert (siehe [module-lebenszyklus.md](module-lebenszyklus.md)).

## Elemente, die man leicht übersieht

- **`SelectLocation`:** Textfeld plus Dialog mit Karte, verschiebbarer Markierung und Ortssuche, auch als Listenspalte. Wert ist ein JSON-String `{"latitude":…,"longitude":…}`; der Startwert 0/0 zeigt „nie gesetzt“. Die Karte lädt im **Browser** aus dem Internet; ohne Internet tippt man die Zahlen.
- **`HorizontalSlider`:** Schieberegler mit `minimum`/`maximum`/`stepSize`, ab 8.1 auch `displayValue`/`prefix`/`suffix`. Dass kein installiertes Modul ihn nutzt, beweist nichts; siehe [doku-quellen.md](doku-quellen.md).

---

Stand: geprüft am 08.10.2026 (Doku und Repo-Code); Messwerte mit Datum

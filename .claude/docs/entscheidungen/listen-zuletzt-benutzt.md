# Einkaufsliste: „Zuletzt benutzt“

„Zuletzt benutzt“ ist der Wagen: die abgehakten Zeilen (`inCart: true`) im Attribut `Items` der Einkaufsliste, als einklappbarer Abschnitt unter der Liste. Er ist nicht zu verwechseln mit „Schon gekauft“, der Kaufhistorie `PurchaseHistory` im Favoriten-Blatt.

## Entscheidungen (21.10.2026, nach einer Nutzer-Rückmeldung)

- **Sortierung je Gerät, Vorgabe A–Z.** Alternativ „Zuletzt gekauft zuerst“ (nach `boughtAt`, Zeilen ohne den Wert fallen auf `addedAt` zurück).
  - Gespeichert wird lokal: Web-App `symdo.cartSort` (`wagenSortiert()` in `SymDoWebApp/module.html`), iOS `@AppStorage("cartSort")`.
  - Der Server sortiert nicht. Vorher stand der Wagen in der Reihenfolge, in der die Zeilen einmal angelegt wurden.
- **Ein Name, eine Zeile – aber nur künftig.**
  - Wird ein Artikel abgehakt (einzeln oder per „Alles erledigt“), weichen gleichnamige Wagen-Zeilen (`mb_strtolower(trim)`). Menge und Notiz des neuesten Kaufs gewinnen (`CartGleichnamige()` in `SymDoShoppingList/libs/ItemStore.php`).
  - Vorher wich nur eine Zeile mit gleicher Menge UND Notiz, deshalb standen „Milch 1“ und „Milch 2“ beide da.
  - Ein Bestand, der schon doppelt steht, wird bewusst nicht aufgeräumt (Nutzerentscheid). Er löst sich auf, sobald der Artikel das nächste Mal abgehakt wird.
- **Löschen im Wagen trifft genau eine Zeile.**
  - Vorher nahm `DeleteItem` alle gleichnamigen Zeilen mit, auch den offenen Artikel auf der Liste.
  - Das Löschen eines OFFENEN Artikels nimmt weiterhin alle gleichnamigen mit, auch die im Wagen.
  - Der iOS-Reducer spiegelt beides.

## Prüfen

- `php SymDoShoppingList/tests/WagenTest.php` (mit Gegenprobe gegen den alten Stand)
- `node SymDoWebApp/tests/WagenSortTest.mjs`
- iOS: `CartRulesTests`

Stand: geprüft gegen den Code am 21.10.2026

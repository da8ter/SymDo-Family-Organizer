# Essensplan: Rezepte, Einkauf und Gerichtsbilder

Der Essensplan (`SymDoMealPlan`) plant je Tag ein Gericht für diese und die nächste Woche. Die Rezepte sind die Favoritenlisten einer gewählten Einkaufsliste. Diese Datei hält fest, wie Einkauf und Gerichtsbilder verteilt sind und was dabei gemessen wurde.

## Entscheidungen

- **Rezepte sind Favoritenlisten mit Rezept-Haken (`isRecipe`).** Die Zutaten gehen per `IPS_RequestAction(<Einkaufsliste>, 'AddFavoriteListToCart', <listId>)` in den Wagen, mit der rohen `listId`. Das Gateway-Briefing holt das Tagesgericht über `MPL_GetMealForDate`, das Bindeglied ist der Trait `MealPlanBridge`.
- **Die Gerichtsbilder erzeugt und lagert das Gateway, nicht der Essensplan** (Trait `SymDoGateway/libs/DishImages.php`, seit `d12ded9`). Ein Bild entsteht, wenn eine Favoritenliste mit Rezept-Haken angelegt wird, und nicht erst beim Einplanen. Es ersetzt dann in allen Favoriten-Ansichten das Herz. Die Bild-ID steht als `imageId` am Favoritenlisten-Datensatz, der Essensplan liest sie nur mit. Das Quelldokument des Rezepts (`mediaId`) bleibt unangetastet, das KI-Bild gewinnt nur in der Miniatur. Ein Schalter im KI-Bereich des Gateways (`DishImagesEnabled`, Vorgabe aus) steuert alles. Erzeugt wird nacheinander über einen Timer, weil ein Bild bis zu zwei Minuten braucht und den Anlege-Aufruf nicht blockieren darf.
- **Keine Bild-Bytes im Stand der Einkaufsliste.** Gemessen: Mit eingebetteten Miniaturen wuchs der Stand von 168 auf 350 kB, bei rund 37 kB je 120-px-Miniatur. Ausgeliefert wird stattdessen über zwei Wege, je nach Verbraucher:
  - Web-App und ihre Kachel-Kopien holen das Bild über den Asset-Hook der Einkaufsliste (`&a=dish&s=96&mid=…`, das Token steckt in `imageBase`).
  - Die iOS-App holt es über die Gateway-Route `GET /v1/dishimage/{id}?s=96` (`d54c36a`).
- **Ausnahme: die Essensplan-Kachel bettet ihre Miniaturen als data-URL ein** (`MealStore::MiniBild`). Sie hat kein Token für den Hook, und sieben Miniaturen je Woche bleiben weit unter der Ausgabegrenze. Die Kantenlänge ist deshalb hart begrenzt (120 px, in der Detailansicht 480 px), weil Symcon zu große Antworten ersetzt statt sie abzuschneiden.
- Symbole im Essensplan kommen aus Font Awesome, nicht als Emoji (`3ab7ee9`). Ein Tag ohne Bild zeigt `fa-plate-utensils`.
- **Der Plan als Bereich der Web-App** (seit 19.10.2026, Nutzerentscheid): Zeile `mealplan` in der Bereichsliste der Web-App-Instanz, Vorgabe an, hinten angehängt. Sichtbar nur in der vollen Oberfläche (`t.dashboard`) und nur, wenn das Gateway eine Essensplan-Instanz meldet (`mealplan` ≠ null), wie der Nahverkehr.
  - Weg: Web-App → Gateway `POST /v1/mealplan` bzw. Kachel-Relay (`…mealplan` vor dem KI-Riegel) → `MPL_AppAction` (`libs/MealApp.php`). Öffentliche Funktion statt RequestAction, weil die Web-App eine Antwort braucht. Das Gateway lässt nur die acht Aktionen und die bekannten Felder durch; das Modul prüft Datum, Rezept und Zutaten selbst.
  - Umfang wie die Kachel, seit dem Nachtrag am selben Tag auch mit Rezept-Scan: ausgewertet wird über die KI-Wege des Gateways (`/ai/ingredients`, Quelle über `/ai/savephoto`), gespeichert über `saveScan` (Modul bereinigt Name, Zutaten, Link, Medien-ID). Nur mit `aiEnabled`. Ein gewähltes Kind sieht den Plan nur an (`mpNurLesen()`); das ist eine Regel der Oberfläche, der Server unterscheidet keine Mitglieder.
  - Gerichtsbilder reisen im App-Stand nur als Kennung (`dishId`) und kommen über `dishImageUrl` (Bridge mit Token bzw. Asset-Hook). Nur ein Rezeptfoto ohne Gerichtsbild kommt als Miniatur mit. Gemessen im Docker: 2,5 kB Stand ohne geplante Fotos.
  - iOS hat denselben Bereich (Paket `MealPlanFeature`, gleicher Weg und dieselben Regeln in `MealPlanRules`). Ältere App-Fassungen lesen `tabs` als offenes Wörterbuch; der neue Schlüssel stört sie nicht.

## Fallen

- **Die Einkaufsliste übernimmt einen schon offenen gleichnamigen Artikel nicht doppelt.** Zweimal `AddFavoriteListToCart` lässt Menge und Anzahl unverändert, Mengen werden nicht addiert (gemessen). Die Entdoppelung der Woche im Essensplan spart also nur Arbeit und korrigiert keine Mengen.
- **Rezept-„Fotos“ können PDFs sein** (Medientyp Dokument). Dafür gibt es keine Miniatur, der Tag zeigt das Symbol. Das ist ein gewollter Rückfall, kein Fehler.
- **Kachel-Prüfstand:** Die Pfeil- und Knopf-Handler hängen an `DOMContentLoaded`. Ein geskripteter `.click()` am Dateiende läuft davor ins Leere, deshalb gehört der Klick in einen eigenen `DOMContentLoaded`-Listener (siehe `../testen/kachel-fixtures.md`).

Stand: geprüft gegen den Code am 19.10.2026 (Messwerte nicht am Code prüfbar)

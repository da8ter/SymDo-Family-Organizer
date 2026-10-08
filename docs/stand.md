# Stand

Offenes über alle Bereiche. Einzelheiten stehen jeweils unter „Offen“ in der verlinkten Datei. Erledigtes wird hier gestrichen, nicht abgehakt.

## Offen

- **Sprache:** Hörprobe mit echter Stimme am Sprachgerät, Freihand-Weckwort im Betrieb, Ton über das Tablet mit echtem Tablet (Echo) und in der iOS-App; GPT-Live am Gerät ([sprache-geraet](entscheidungen/sprache-geraet.md), [sprache-tablet-ton](entscheidungen/sprache-tablet-ton.md), [sprache-dialog](entscheidungen/sprache-dialog.md)).
- **Auslieferung:** Sprachdialog-Skripte als eigene, zwischenspeicherbare Anfragen; gzip für die JSON-Schnittstelle ([gateway-auslieferung](entscheidungen/gateway-auslieferung.md)).
- **Kachel-Last:** ToDo-Kachel pusht nach jedem Sync den vollen Stand; Bildkarte im Stand der Web-App-Kachel; `app.css` auslagern ([kacheln-ressourcen](entscheidungen/kacheln-ressourcen.md)).
- **Zweite Spur:** doppelter Anhangabruf bei geänderten Klassenseiten-Karten ([gateway-entlasten](entscheidungen/gateway-entlasten.md)).
- **Schule:** Prüfstand für Kurswahl und Abbildung (`UntisKurseWaehlen`, `UntisAbbilden`) fehlt ([schule-webuntis](entscheidungen/schule-webuntis.md)).
- **Listen:** Titel-Rückfall gegen Verdopplung auch für Google Tasks und CalDAV ([listen-sync-dedup](entscheidungen/listen-sync-dedup.md)).
- **iOS-App:** Originale der KI-Vorschläge anzeigen ([ki-originale](entscheidungen/ki-originale.md)).
- **VRR:** Produktivzugang beantragt; mit Zusage nur `Efa::BASIS` umstellen ([vrr-quelle-und-richtungen](entscheidungen/vrr-quelle-und-richtungen.md)).
- **Nicht gebaut, bewusst:** eingeschränkte Geräte ([geraete-umfang](entscheidungen/geraete-umfang.md)).

## Code-Kommentare, die dem Verhalten widersprechen

Bei der Doku-Prüfung am 08.10.2026 gefunden, noch nicht korrigiert:

- `SymDoGateway/libs/EduMaps.php`, `EduCreate()`: Kommentar an `EduFollowLinks` sagt „nur gespiegelt, nie ausgewertet“ — verlinkte Seiten werden seit dem 10.09.2026 auch ausgewertet.
- `SymDoScanner/module.php`, Kopfkommentar: „Draussen bleibt WebUntis“ — WebUntis läuft inzwischen in der zweiten Spur.
- `SymDoGateway/module.php` (um Zeile 220): Ein Modul-Reload führe `Create()` nicht erneut aus — gemessen läuft es erneut ([module-lebenszyklus](plattform/module-lebenszyklus.md)).
- `SymDoGateway/libs/AppCore.php`, Manifest `display`: „oben und unten Systemstreifen“ — gemessen nur oben ([webapp-ios-homescreen](entscheidungen/webapp-ios-homescreen.md)).
- `libs/KachelApp.php`: „15–20 MB je Kachel“ ist ein ps-RSS-Wert; im Speicher-Fußabdruck sind es rund 7 MB.
- `RegisterReference` auf Instanzen derselben Bibliothek (WebApp, Routines, MealPlan) widerspricht der Regel in [instanzen-und-nebenlaeufigkeit](plattform/instanzen-und-nebenlaeufigkeit.md) — die Regel beruht auf einem einzelnen Befund; klären, bevor geändert wird.

Stand: 08.10.2026

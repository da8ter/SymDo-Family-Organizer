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

## Zu klären

- `RegisterReference` auf Instanzen derselben Bibliothek (WebApp, Routines, MealPlan) widerspricht der Regel in [instanzen-und-nebenlaeufigkeit](plattform/instanzen-und-nebenlaeufigkeit.md). Die Regel beruht auf einem einzelnen Befund; erst klären, dann ändern.

Stand: 08.10.2026

# SymDo — Projektdoku

Versioniertes Projektwissen: **warum** etwas so gebaut ist, was am laufenden System gemessen wurde und wie man es prüft. Was der Code selbst zeigt, steht hier nicht; Bedienung steht in den READMEs der Module.

Jede Datei endet mit „Stand: geprüft gegen den Code am …“. Ändert ein Commit eine hier beschriebene Entscheidung, wird die Datei im selben Commit nachgezogen.

## Entscheidungen (`entscheidungen/`)

**Gateway und Apps**
- [gateway-aufbau](entscheidungen/gateway-aufbau.md) – Gateway als Kern, Hook-Pfade, Vertrag zu den Apps
- [app-api-vertrag](entscheidungen/app-api-vertrag.md) – Endpunkte, Kopplung, Token, Fehlerform, Idempotenz
- [gateway-entlasten](entscheidungen/gateway-entlasten.md) – zweite Spur (SymDo Scanner) für KI und Abrufe
- [gateway-auslieferung](entscheidungen/gateway-auslieferung.md) – Größe der Web-App, gzip
- [geraete-umfang](entscheidungen/geraete-umfang.md) – eingeschränkte Geräte (Entwurf, nicht gebaut)
- [webpush](entscheidungen/webpush.md) – Web Push ohne Fremdbibliothek
- [mail-eingang](entscheidungen/mail-eingang.md) – Mailgun-Einrichtung und Webhook

**KI**
- [ki-vorschlaege](entscheidungen/ki-vorschlaege.md) – Foto, PDF, Rezept → Vorschläge
- [ki-originale](entscheidungen/ki-originale.md) – Originale der Vorschläge
- [ki-lokaler-anbieter](entscheidungen/ki-lokaler-anbieter.md) – OpenAI-kompatibler lokaler Server

**Listen, Kacheln, Web-App**
- [kacheln-uebernahme-skript](entscheidungen/kacheln-uebernahme-skript.md) – Kacheln als Web-App-Kopien
- [kacheln-stilregeln](entscheidungen/kacheln-stilregeln.md) – Knöpfe, Symbole, Scrollleisten, Ränder
- [kacheln-ressourcen](entscheidungen/kacheln-ressourcen.md) – Last in der Visu
- [listen-gesten](entscheidungen/listen-gesten.md) – Wischen und Umsortieren
- [listen-sync-dedup](entscheidungen/listen-sync-dedup.md) – ToDo-Abgleich ohne Verdopplung
- [listen-produktbilder](entscheidungen/listen-produktbilder.md) – Produktbilder der Einkaufsliste
- [faelligkeit-leiter](entscheidungen/faelligkeit-leiter.md) – eine Fälligkeitsanzeige für alle Oberflächen
- [notizen-gateway-bestand](entscheidungen/notizen-gateway-bestand.md) – Notizen als Gateway-Bestand
- [essensplan-gerichtsbilder](entscheidungen/essensplan-gerichtsbilder.md) – Essensplan, Rezepte, Bilder
- [vrr-quelle-und-richtungen](entscheidungen/vrr-quelle-und-richtungen.md) – Nahverkehr: Quelle und Richtungsfilter
- [webapp-ios-homescreen](entscheidungen/webapp-ios-homescreen.md) – Web-App auf dem iOS-Home-Bildschirm

**Schule**
- [schule-webuntis](entscheidungen/schule-webuntis.md), [schule-logineo-moodle](entscheidungen/schule-logineo-moodle.md), [schule-klassenseiten](entscheidungen/schule-klassenseiten.md), [schule-hausaufgaben](entscheidungen/schule-hausaufgaben.md)

**Sprache**
- [sprache-dialog](entscheidungen/sprache-dialog.md) – Sprachkachel, Werkzeuge, Rechte
- [sprache-geraet](entscheidungen/sprache-geraet.md) – Sprachgerät (ESP32) und Gateway
- [sprache-tablet-ton](entscheidungen/sprache-tablet-ton.md) – Gerät als Mikrofon, Antwort am Tablet

**Sicherheit**
- [sicherheit-eduhtml](entscheidungen/sicherheit-eduhtml.md) – die eine HTML-Weißliste
- [sicherheit-codereview-2026-09](entscheidungen/sicherheit-codereview-2026-09.md) – externer Review und Folgen

## Symcon-Plattform (`plattform/`)

Gemessenes und nachgelesenes Verhalten von Symcon, das für alle Module gilt. Einstieg: [doku-quellen](plattform/doku-quellen.md).
[module-lebenszyklus](plattform/module-lebenszyklus.md) · [module-strict-und-php](plattform/module-strict-und-php.md) · [variablen-und-darstellungen](plattform/variablen-und-darstellungen.md) · [instanzen-und-nebenlaeufigkeit](plattform/instanzen-und-nebenlaeufigkeit.md) · [timer](plattform/timer.md) · [hooks-und-grenzen](plattform/hooks-und-grenzen.md) · [formulare](plattform/formulare.md) · [daten-und-sicherheit](plattform/daten-und-sicherheit.md) · [kachel-visu](plattform/kachel-visu.md) · [kachel-nachrichten](plattform/kachel-nachrichten.md) · [webrtc-sicherer-kontext](plattform/webrtc-sicherer-kontext.md)

## Testen (`testen/`)

- [kachel-fixtures](testen/kachel-fixtures.md) – Kacheln und Web-App im Headless-Browser fahren
- [layout-messen](testen/layout-messen.md) – Layout in Kachelbreite messen
- [visu-last-messen](testen/visu-last-messen.md) – Last einer Kachel in der Visu messen

## Stand

[stand.md](stand.md) – offene Punkte über alle Bereiche und bekannte Widersprüche zwischen Code-Kommentar und Verhalten.

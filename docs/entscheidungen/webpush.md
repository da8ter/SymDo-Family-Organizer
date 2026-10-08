# Web Push in der SymDo-Web-App

Die Web-App (auch als Home-Bildschirm-App) bekommt Benachrichtigungen über Web Push, komplett in PHP ohne Fremdbibliothek: VAPID (RFC 8292) und Nutzlast-Verschlüsselung (RFC 8291) im Trait `SymDoGateway/libs/WebPush.php`. Die Begründungen stehen größtenteils an den Konstanten; hier die Übersicht und das am Gerät Gemessene.

## Entscheidungen

- **Kein `openssl_pkey_new`.** Braucht auf manchen Systemen eine auffindbare `openssl.cnf` und läge im heißen Pfad (ein Einmalschlüssel je Nachricht). Stattdessen 32 Zufallsbytes in einem PKCS#8-DER mit festem Vorsatz (`PUSH_PKCS8_PREFIX`); OpenSSL 3 rechnet den öffentlichen Punkt selbst aus. Verifizieren nur mit einem separat gebauten SPKI — mit dem privaten Handle scheitert `openssl_verify`.
- **Service Worker unter `/hook/lists/pwa`** (eigener Hook, ohne Endung). Dessen Verzeichnis ist `/hook/lists/`, also deckt der Vorgabe-Scope die Seite ab — ohne `Service-Worker-Allowed`, dessen Weg durch den Connect-Proxy nicht zugesagt ist. Folge: der Scope umfasst auch die token-gesicherte API. Der Worker fasst Anfragen deshalb nur eng an: sein `fetch`-Handler (seit Commit `12f663a`) bedient ausschließlich eine feste Liste statischer Dateien und die App-Icons gleicher Herkunft ohne Token-Parameter; alles andere, insbesondere die API, lässt er unberührt (kein `respondWith`).
- **Nur 404 und 410 löschen ein Abo.** 400/401/403/413 sind Fehler auf unserer Seite (Kontakt, Uhr, Größe) und treffen alle Geräte gleichzeitig — als „Abo tot" gelesen, räumte ein einziger Timerlauf den ganzen Bestand ab.
- **Ein Gerät ohne Mitglieds-Zuordnung bekommt alles** (`DeviceRegistry::PushSubscriptions`). Sonst verschwände jede Nachricht an ein Mitglied, solange niemand sein Gerät zuordnet — und das ist die Vorgabe.
- **Keine Liste erlaubter Push-Dienste.** Sie wäre enger, altert aber, und ein vergessener Dienst hieße „Benachrichtigungen kommen nicht mehr an", ohne Fehlermeldung. Stattdessen dieselbe Zielklasse wie für Rezeptseiten (`AiRecipePage::istOeffentlich`, `libs/PushZiel.php`) plus `CURLOPT_RESOLVE` gegen DNS-Rebinding. Ein Abo fliegt nur bei einem Formfehler des Ziels — eine DNS-Störung darf kein gültiges Abo kosten.
- **Auslöser:** manuell `TGW_SendPush` bzw. RequestAction `SendPush`; automatisch je mit eigenem Schalter, Vorgabe aus: fällige Aufgaben, fertiges Briefing, neue Mail-Vorschläge (nur Zahlen, kein Betreff), Hausaufgaben (mit Uhrzeit). Fällige Aufgaben laufen über einen eigenen Minutentimer im Gateway mit eigenem Merker (`PushSent`) — der `notifiedFor` der Aufgabenliste hängt am Erfolg der Visu-Zustellung und taugt nicht als zweiter Kanal.
- **Der Erinnerungstimer startet versetzt** (erster Schlag +30 s), weil er seit Symcon 9.1 sonst dauerhaft im selben Moment wie `CalNotify` feuerte.
- Ergänzend spielt `SDWA_PlayBriefing(<Kachel>)` das Audio-Briefing in genau einer Kachel. Browser dürfen Ton ohne Nutzergeste abweisen; die Kachel bittet dann um einmaliges Antippen.

## Fallen/gemessen

Am iPhone gemessen (iOS 26.6, August 2026) — was eine Web-Push-Meldung zeigt:

| Baustein | iOS 26 |
|---|---|
| `title` | ja, halbfett |
| `body` | ja, lang — sieben Zeilen, weit über 256 Zeichen |
| `icon` | Symbol erscheint (ob ein abweichendes greift, ist ungetestet) |
| `image` | nein |
| `actions` | nein, auch nicht aufgezogen |
| Badging-API | funktioniert separat (`setAppBadge` im Service Worker) |

- Daraus folgt `PUSH_BODY_MAX` = 1000 (die frühere 256 war von den Visu-Nachrichten geerbt, nicht vom System). Zwingend dazu `PushFitPayload`: `PushEncrypt` lehnt eine zu große Nutzlast ab (`payload_too_large`), statt zu kappen — die Meldung fiele für jedes Gerät aus. Ein Emoji wiegt 4 Byte; die Größenprüfung muss mit denselben `json_encode`-Flags rechnen wie der Versand (`JSON_UNESCAPED_UNICODE`), sonst misst sie `ä` statt `ä`.
- Die Zeile „from SymDo" setzt iOS selbst zwischen Titel und Text (Herkunft, nicht abschaltbar). Eine angehängte „2" bedeutet, dass schon ein gleichnamiges Symbol auf dem Home-Bildschirm liegt. Der Name kommt aus `apple-mobile-web-app-title`.
- Auf dem iPhone geht Web Push nur aus der zum Home-Bildschirm hinzugefügten App; diese koppelt sich einmal selbst neu, weil `start_url` bewusst kein Token trägt.

## Offen

- Ob iOS die Zeile „from" bei `lang: de` im Manifest deutsch schreibt oder fest englisch bleibt (Kommentar an der Manifest-Erzeugung in `AppCore.php`; nicht am Code prüfbar).
- Zustellung über Android/Chrome-Dienste ist nicht dokumentiert gemessen (nicht am Code prüfbar).

Stand: geprüft gegen den Code am 08.10.2026

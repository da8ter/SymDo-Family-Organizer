# App-API: Vertrag zwischen Gateway und Clients

Die iOS-App, die Web-App über Connect und die Sprachgeräte sprechen mit dem Gateway über den Hook `/hook/lists/app`, Pfade `/v1/…` (`SymDoGateway/libs/ApiRouter.php`, `DeviceRegistry.php`). Diese Datei beschreibt die Gateway-Seite des Vertrags; App-Interna gehören nicht hierher.

## Entscheidungen

- **Kopplung per Einmal-Code.** `TGW_CreatePairing` erzeugt 12 Hex-Zeichen, 10 Minuten gültig; es gibt immer nur EINEN offenen Code, ein neuer ersetzt den alten. `POST /v1/pair` tauscht ihn gegen einen 32-Byte-Token. Gespeichert wird nur der SHA-256 von Code und Token.
- **90 Sekunden Gnadenfenster** nach dem ersten Einlösen: geht die Antwort verloren, darf der Client mit demselben Code wiederholen. Scheitert das Ablegen des Geräts, gibt es 500 und keinen Token — ein Token, der nie gespeichert wurde, darf nicht hinaus.
- **QR für die App**: `symdo://pair?v=1&u=<Connect-Adresse>&l=<lokale Adresse>&c=<Code>&n=<Systemname>` (Adressen Base64url). **Browserzugang**: HTTPS-Adresse der Web-App mit dem Code im Fragment `#c=…` — das Fragment erreicht den Server nicht und steht in keinem Log.
- **Token im Kopf, nicht in der Adresse.** `Authorization: Bearer …` (alternativ `X-SymDo-Token`). Als `?t=` nur dort, wo ein schlichter Lader keinen Kopf setzen kann: `GET /assets/…`, `/users/{id}/avatar`, `/ai/media/{id}`, `/notes/media/{id}`, `/dishimage/{id}`. Sonst landete der langlebige Token in Proxy- und Zugriffsprotokollen.
- **CORS `*`** ist Absicht: die über Connect geladene Web-App ruft die lokale API cross-origin; die Sicherheitsgrenze ist der Token, nicht der Ursprung (keine Cookies).
- **Unauthentifiziert sind nur** `POST /v1/pair`, `GET /v1/ping` und der Mail-Webhook. `ping` liefert bewusst nur `ok` und `apiVersion`; Systemname, Version, lokale Adressen und Connect-Adresse gibt es erst nach der Kopplung (`/pair`, `/discovery`).
- **Fehlerform**: `{"ok":false,"error":{"code":"…","message":"…"}}`. Vertrag ist `error.code`; die Meldung ist Anzeige. Ausgewählte Codes: `unauthorized` und `token_revoked` (401), `pairing_invalid` (403), `unknown_route`/`unknown_instance` (404), `invalid_payload` (400/422), `rate_limited` (429). Aktionsfehler einer Liste kommen als 400 (`unknown_action`) oder 422, der Rumpf trägt trotzdem Revision und Zustand zum Abgleich.
- **Synchronisation per Revision.** `GET /v1/revisions` liefert je Liste eine Zahl; `GET /v1/instances/{id}/state` antwortet mit ETag und 304. Schwache ETags (`W/"5"`) werden akzeptiert, weil Proxys sie herabstufen; `?rev=` ersetzt den Kopf, wo ein Client ihn nicht setzen kann. Ein schmaler Zustand (`?images=0`, `?suggestions=0`) hat einen eigenen ETag (`"5-is"`), sonst bekäme ein Client ein 304 auf einen Rumpf, der nicht zu seiner Fassung passt. Schmal nur auf Wunsch: eine ausgelieferte App erwartet die Bildzuordnung im Zustand.
- **Idempotente Aktionen.** `POST /v1/instances/{id}/actions` mit `clientActionId`: das Gateway merkt sich `Instanz|clientActionId` 24 Stunden (höchstens 200, Kennung höchstens 64 Zeichen). Eine Wiederholung führt nichts aus und liefert `replayed:true` mit dem aktuellen Zustand. Prüfen und Reservieren laufen unter einer Semaphore; schlägt die Aktion fehl, wird die Reservierung zurückgenommen, damit ein ehrlicher Wiederholversuch durchgeht.
- **Ein Pfad, Aktion im Rumpf** für neuere Bereiche (`/voice`, `/notes`, `/calendar`, `/homework`, `/edumaps`, `/mail/proposals`): die Visu-Kachel kann nur POSTs auf einen Pfad weiterreichen; so benutzen Browser, App und Kachel denselben Aufruf.
- **Notizen, Klassenseiten und Hausaufgaben stehen nicht in `/discovery`.** Eine neue Listenart dort zerlegt die ausgelieferte iOS-App, deren Listenart nur Einkauf und Aufgaben kennt und nicht optional ist.
- **Sprachweg mit eigenem Ratentopf** (900 je Stunde und Gerät), getrennt vom KI-Fenster; Begründung in `sprache-dialog.md`.
- **Entkoppeln** per `DELETE /v1/pair`, zusätzlich `POST /v1/unpair`, falls ein Proxy DELETE nicht durchlässt. Widerrufene Geräte bekommen 401 `token_revoked`.

## Sicherheit

- Jeder Token hat vollen Zugriff auf die API; eine Beschränkung je Gerät gibt es nicht. Das gilt auch für Sprachgeräte (siehe `sprache-geraet.md`).
- `POST /v1/users` und `POST /v1/users/{id}` prüfen nicht, wem ein Profil gehört: Geräte sind keinem Mitglied fest zugeordnet. Dass die App nur das eigene Profil anbietet, ist eine Regel der App, nicht des Servers.
- Angaben des Clients beim Koppeln (Gerätename, Modell, Plattform, Version) werden auf 80 Zeichen gekürzt; sie landen in der Geräteliste, und ein überlanger Wert ließe das Schreiben der ganzen Liste scheitern.
- `/v1/pair` schreibt nur bei einer echten Änderung, weil es unauthentifiziert ist und nicht bei jeder Probe Attribute schreiben soll.

## Fallen / gemessen

- **`clientActionId` muss global eindeutig sein.** Ein Zähler, der bei jedem Seitenladen neu beginnt, wiederholt Kennungen; die Aktionen gelten dann als Wiederholung und werden still nicht ausgeführt (aufgetreten in der Web-App, behoben).
- **Hook-Ausgabe höchstens 1 MB**: darüber ersetzt Symcon die Antwort durch einen Fehlertext, mit dem gesetzten Content-Type und HTTP 200. Deshalb Avatare auf 256 px skaliert und das Bild-Bündel auf 60 Dateien und 600 kB gedeckelt.
- **Token-Entwertung kostet Daten.** Die iOS-App behandelt 401 als nicht wiederholbar und verwirft den betroffenen Eintrag ihrer Warteschlange; die Web-App löscht nur ihren Token und zeigt die Kopplung. Jede Maßnahme, die Tokens entwertet (Instanz neu anlegen, Geräteliste nicht mitnehmen, Kopplung widerrufen), sollte erst nach einer Synchronisation aller Geräte erfolgen.
- **Kopplungen sind übertragbar**: der gespeicherte Hash ist ein ungesalzener SHA-256 des Tokens und nicht an die Instanz gebunden. Wandert die Geräteliste mit, gelten die Kopplungen weiter.
- **Lokale Adressen** im QR und in der Server-Auskunft setzen den Symcon-Standardport voraus; bei abweichendem Port sind die Kandidaten falsch.
- **Home-Screen-App auf iOS**: mit Manifest startet iOS über `start_url`, das Fragment aus dem Lesezeichen kommt nie an. Deshalb steht der Code im Formular auch einzeln da, und die Web-App nimmt ihn im Kopplungsbildschirm von Hand an.

Stand: geprüft gegen den Code am 08.10.2026

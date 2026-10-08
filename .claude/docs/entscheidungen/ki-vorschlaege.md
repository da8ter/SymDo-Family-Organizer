# KI: Foto, PDF und Rezept zu Vorschlägen

Das Gateway nimmt Fotos, PDFs und Rezeptadressen entgegen und lässt sie von einem KI-Anbieter (Anthropic, OpenAI oder ein lokaler OpenAI-kompatibler Server) in Aufgaben, Termine, Notizen oder Zutaten übersetzen. Diese Datei hält die Grundsatzentscheidungen und die im Betrieb gemessenen Fallen fest.

## Entscheidungen

- **Der API-Schlüssel bleibt auf dem Server.** Browser und App verkleinern nur das Bild (Canvas, rund 1280 px, JPEG 0,7) und schicken es ans Gateway; das Gateway ruft den Anbieter. Deshalb läuft auch die iOS-App für Rezepte über dieselben `/v1/ai/*`-Routen (Gerätetoken), obwohl sie Text selbst erkennen könnte: nur der Server kann eine Rezeptadresse SSRF-sicher laden und ein Rezeptfoto als Medienobjekt ablegen.
- **Kein OCR auf dem Server.** Das Foto geht direkt an ein Vision-Modell. PDFs: zuerst die Textebene (`pdftotext`), erst wenn sie zu dünn ist (`AiProvider::PDF_MIN_TEXT`), die Seitenbilder (`pdftoppm`, höchstens drei Seiten).
- **Die Routen legen nichts an.** `/v1/ai/extract` und `/v1/ai/ingredients` liefern nur Vorschläge; angelegt wird erst nach der Durchsicht in der Oberfläche.
- **Rezepte kommen als Objekt** (`{ok, title, servings, items}`), nicht als bloße Liste, damit Portionen skaliert und Favoritenlisten mit Titel angelegt werden können. Das Anlegen samt Einträgen ist EIN Aufruf (`AddItemsToFavoriteList`), weil eine neu angelegte Liste ihre Kennung erst asynchron hätte.
- **Rezeptadressen nur öffentlich.** `AiRecipePage::istOeffentlich` prüft jede aufgelöste Adresse, jede Weiterleitung einzeln, und pinnt die geprüfte IP per `CURLOPT_RESOLVE` gegen DNS-Rebinding. Dieselbe Zielklasse gilt für Push-Endpunkte (siehe `webpush.md`).
- **Aufbewahrung der Vorschläge: 21 Tage ab Auswertung, nicht ab Dokumentdatum.** `at` (Dokumentdatum) steht in der App und sortiert, `created` (Zeitpunkt der Auswertung) entscheidet über Aufbewahrung und Deckel. Vorher war ein Vorschlag aus einem älteren Dokument schon beim Entstehen zu alt — bezahlt und nie sichtbar. Ältere Sätze ohne `created` fallen auf `at` zurück.
- **Die KI-Knöpfe gibt es in Web-App und Visu-Kachel.** Die Kachel hat keinen Token und geht über das Kachel-Relay (`AiRelayBody`); sind die KI-Funktionen aus, verschwinden die Knöpfe ganz (`applyAiEnabled`).

## Fallen/gemessen

- **`/ai/savephoto` und `/ai/media` (POST) antworten immer mit HTTP 200;** Erfolg steht nur im Feld `ok` des Körpers. `/ai/extract` und `/ai/ingredients` melden Fehler dagegen über den HTTP-Status. Clients dürfen das nicht über einen Kamm scheren.
- Fehlermeldungen entstehen serverseitig über `Translate` — neue Fehlertexte gehören in die `locale.json` des Gateways.
- **Aufzählungen im Prompt:** aus einer Materialliste machte das Modell 35 Einzelaufgaben statt einer Aufgabe mit der Liste in `info`, und aus einem Stundenplan vier datumslose „Termine". Beides ist seit Commit `44aec12` im Prompt abgegrenzt (Kommentar an der Stelle in `AiExtract.php`). Solche Fehler sind Prompt-Fragen, keine Code-Fragen.
- Fotos müssen clientseitig verkleinert werden — die Hook-Ausgabe und die Fernverbindung haben Größengrenzen.

Stand: geprüft gegen den Code am 08.10.2026

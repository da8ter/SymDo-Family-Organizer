# Gateway: Auslieferungsgröße der Web-App

Die Web-App kommt als eine HTML-Antwort durch den Hook `lists/webapp`. Hier steht, warum sie gepackt ausgeliefert wird und was an weiterer Verkleinerung offen ist.

## Entscheidungen

- **gzip, Stufe 4** (`AppCore::WEBAPP_GZIP`, seit Commit `b408564`). Die Messreihe zur Stufenwahl steht an der Konstante. Über die Fernverbindung (Symcon Connect) sank die Ladezeit gemessen von 4,2 auf 1,6 Sekunden.
- **Das war keine Kosmetik:** ungepackt lag die Seite (rund 1 MB) nur wenige Kilobyte unter der Kernoption `ScriptOutputBufferLimit` (ab Werk 1.048.576 Byte). Diese Grenze kappt nicht, sie ERSETZT die Hook-Antwort still — bei HTTP 200. Jede weitere Funktion in der Web-App hätte sie auf Installationen mit Werkseinstellung unbenutzbar gemacht.
- **Kommentare werden beim Ausliefern NICHT entfernt.** Rund 30 % der Rohgröße sind Kommentare, aber der Gewinn verschwindet hinter der Kompression, und ein Ausdruck, der ein `//` in einer Zeichenkette für einen Kommentar hält, zerlegt die App lautlos.

## Fallen/gemessen

- Symcon komprimiert seine eigenen statischen Dateien (`/icons.js` kommt mit `Content-Encoding: gzip`), Hook-Antworten aber nicht. Ein Verzeichnis, in das ein Modul statische Dateien legen könnte, gibt es nicht — alles muss durch den Hook.
- Die HTTP-Ebene reicht ein selbst gesetztes `Content-Encoding` unverändert weiter, berechnet `Content-Length` aus den gepackten Bytes und packt nicht nach; `HTTP_ACCEPT_ENCODING` ist im Hook sichtbar.
- Zusammensetzung der ungepackten Seite (09.09.2026): Haupt-JS 63 %, CSS 18 %, Sprachdialog-Skripte 9 %, Markup 4 %, Übersetzungen 3 % — Anhaltspunkt dafür, wo sich weiteres Kürzen überhaupt lohnt.

## Offen

1. **Sprachdialog-Skripte als eigene, zwischenspeicherbare Anfragen.** `voice-core.js`, `voice-blob.js` und `voice-wake.js` (zusammen rund 94 kB) stehen weiterhin inline im Seitenkopf, wenn der Sprachdialog aktiv ist (`AppCore`, Aufbau des Kopfes). Tokenfreie, cachebare Dateiwege existieren bereits (`lists/pwa` über `ServePwaFile`; das Kachel-Skript `app.js` unter `lists/webapp` über `ServeWebAppScript` mit Versionsparameter) und wären das Muster dafür.
2. **Kompression auch für die JSON-Schnittstelle.** Antworten wachsen mit großen Listen; `ApiRouter::SendJson` ist die eine Stelle, an der das nachzurüsten wäre. Heute ungepackt.

Stand: geprüft gegen den Code am 08.10.2026

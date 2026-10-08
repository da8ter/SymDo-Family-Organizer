# Sprachdialog: Kachel, Gateway, Werkzeuge

Zweiwege-Sprachdialog mit der KI: die Kachel `SymDoVoice` (Präfix SDVC) ist die Sprechstelle, alles Übrige liegt im Gateway (`SymDoGateway/libs/Voice*.php`, gemeinsame Browser-Kerne `voice-core.js`, `voice-blob.js`, `voice-wake.js`). Diese Datei hält fest, warum der Dialog so gebaut ist und welche Fallen beim Weiterbauen warten.

## Entscheidungen

- **Der Ton läuft nie über Symcon.** Der Browser spricht per WebRTC direkt mit dem Anbieter. Symcon prägt nur eine kurzlebige Marke (der Dauerschlüssel bleibt im Haus), führt Buch und führt Werkzeuge aus. Kostensicherung ist der Wachhund-Timer im Gateway, nicht der Browser: ohne Herzschlag (45 s), nach dem Sitzungsdeckel oder bei leerem Tagesbudget legt das Gateway selbst auf.
- **Identität wird serverseitig gesetzt.** Die Kachel überschreibt `userId`, Kachelkennung und Standardlisten mit ihren Eigenschaften; was der Browser behauptet, zählt nicht. Bei Sprachgeräten mit Profil gilt dasselbe am Geräteeintrag (siehe `sprache-geraet.md`).
- **Vier getrennte Einwilligungen**: KI allgemein, Sprachdialog, Freisprechen (Dauerlauschen), Gerätesteuerung. Begründung: wer dem Knopf zugestimmt hat, hat nicht dem Dauerlauschen zugestimmt, und wer reden darf, schaltet noch kein Licht. Der Widerruf der Sprach-Einwilligung nimmt die beiden weitergehenden mit und legt laufende Gespräche auf.
- **Ein Riegel an zwei Stellen.** Gerätewerkzeuge werden beim Prägen der Sitzung ausgeblendet UND je Aufruf in `VoiceRunTool` geprüft. Nur die zweite Prüfung hält eine Sitzung auf, die geprägt wurde, bevor jemand den Schalter umlegte. Ebenso prüft `tool` denselben Riegel wie `open`: ein abgeschalteter Dialog schaltet auch die Hand ab, nicht nur den Ton.
- **Fakten formuliert der Server.** Jede Werkzeugantwort trägt `sag`, einen in PHP gebauten Satz; das Modell liest ihn sinngemäß vor. Beim Handbuch ist `sag` bewusst nur ein Satzanfang, sonst hört das Modell dort auf.
- **Doppelte Ausführung, zwei Ebenen** (nur schreibende Werkzeuge):
  1. die `call_id` des Modellaufrufs (`fnId`) über die Aktionssperre des Gateways — fängt eine doppelt zugestellte Anfrage;
  2. ein Fingerabdruck aus Werkzeug, Argumenten und Benutzer, 90 s — fängt den eigentlichen Fall: Antwort verloren, Werkzeugfrist läuft ab, das Modell wiederholt mit NEUER `call_id`.
  Die vorhandene Aktionssperre taugt für Ebene 2 nicht (24 h Haltbarkeit: „Milch" am Abend wäre blockiert). Geräteschaltungen (`art: steuern`) haben nur Ebene 1 — „Licht an, aus, an" muss dreimal schalten.
- **Löschen ist zweistufig.** Der erste Aufruf löscht nichts, prägt eine servererzeugte Einmal-Marke (90 s, Serien 60 s) und liefert die Rückfrage. Das Ziel ist beim Prägen festgeschrieben; ein zweiter Hörfehler kann es nicht umlenken. Deckel 10 Löschungen je Stunde. Nach dem Löschen wird gegengelesen statt dem `ok` des Zielmoduls zu glauben.
- **Gerätesteuerung**: Reichweite sind Wurzel-Kategorien, eine zweite Liste nennt Geräte nur mit gesprochener Rückfrage (Schloss, Alarm). Kinder schalten nichts von dieser Liste. Eine leere `userId` zählt als erwachsen, weil Koppeln ein Erwachsenenakt ist. Schaltdeckel 60 je Stunde (umkehrbar, daher höher als beim Löschen).
- **Geräteauflösung hybrid** (Nutzerentscheid 08.09.2026): der strenge Bewerter entscheidet klare Fälle; bei Mehrdeutigkeit bekommt das Modell Kandidaten und wählt per `id`, zugelassen werden nur Katalogeinträge.
- **Zeitpläne sind native Symcon-Ereignisse am Zielobjekt**, ausgeblendet, mit eingebauter Aktion — kein Rückruf ins Gateway (Nutzerentscheid: Referenzsuche, Ereignis-Reiter, Pausieren in der Konsole sollen gelten). Widerruf der Einwilligung löscht sie nicht: der Bestand gehört dem Haus. Riegel wirken beim Anlegen.
- **Weckwort nur lokal.** `voice-wake.js` erkennt mit `processLocally = true`. Ohne die Flagge wäre die Erkennung in Chrome „available" — über die Cloud des Browserherstellers. Deshalb bewusst kein Rückfall; das Sprachpaket lädt nur auf ausdrücklichen Griff. Die Regel für eigene Weckwörter (mindestens sechs Buchstaben, Komma-Alternativen) sitzt nur im Lauscher; der Server reicht den Text roh durch.
- **Freisprech-Schalter über die Konfiguration gelesen**, nicht über eine neue `TGW_`-Funktion: öffentliche Modulfunktionen entstehen erst beim Kernel-Start. Die Einwilligung (Attribut) wird serverseitig über die Aktion `handsfree` geprüft.
- **GPT-Live als zweiter Weg** (`VoiceModel = gpt-live-1`): keine Marke im Browser, das Gateway tauscht das SDP selbst (`livesdp`), weil hier die bezahlte Sitzung entsteht und dieselben Riegel wie bei `open` greifen sollen.

## Sicherheit

- Der Sprachweg hat einen eigenen Ratentopf je Gerät (900 Anfragen je Stunde). Am allgemeinen KI-Fenster (60 je Stunde) war nach einer Viertelstunde Reden Schluss, weil jeder Herzschlag zählte. Der Topf fängt eine Schleife, die Kosten deckeln Tagesbudget und Sitzungsdeckel.
- `opened` mit bekannter Kennung frischt nur den Herzschlag auf. Früher setzte es Start- und Buchungszeit neu — ein Client, der regelmäßig `opened` schickte, telefonierte unbegrenzt.
- Werkzeugantworten und Anweisungen nennen keine Objektkennungen. Mehrdeutige Treffer gehen als Titelliste ans Modell, nie als Zeilen mit IDs.
- `ApplyChanges` legt nur Sitzungen ohne frischen Herzschlag auf; es läuft auch beim Speichern des Formulars und legte sonst laufende Gespräche mitten im Satz auf.

## Fallen / gemessen

- **WebRTC puffert nichts**: Gesprochenes vor `connectionState === 'connected'` ist verloren (gemessen 1,5–2,5 s Aufbau). Deshalb holt der Kern Mikrofon und Marke parallel, prägt beim Lauschen vor (bis 300 s, Erneuerung alle 240 s) und meldet die offene Leitung mit einem Zweiklang. Nach dem Weckwort schreibt der lokale Erkenner weiter mit; der Text geht als Eingabe über den Datenkanal, solange bleibt die Mikrofonspur stumm.
- **Zwei Lauscher im selben Browser** (zwei Sprachkacheln oder Kachel und Web-App) reißen sich die Spracherkennung gegenseitig ab („reagiert auf kein Weckwort mehr"). Der Lauscher zählt fremde `aborted` und pausiert nach drei.
- **GPT-Live**: der Endpunkt antwortet mit HTTP 201, nicht 200 (gemessen 23.09.2026); eine Prüfung auf 200 warf eine gültige Antwort weg und ließ eine Sitzung verwaist. Der Auflegepfad für Live-Sitzungen ist nach dem Muster der Realtime-Schnittstelle gebildet und nicht belegt; der Kern schickt deshalb selbst `session.close` und lässt den Kanal 600 ms offen. Live kennt kein `response.cancel`; „Ruhe" läuft über `session.instructions.append`.
- **Handbuch**: Mindestähnlichkeit 60 (Skalarprodukt = 127 · Kosinus); gemessen echte Fragen 74–96, abwegige 35–46. Leser-Latenz gemessen: gpt-4.1 etwa 1,1–1,4 s, gpt-5 deutlich langsamer — daher die Vorgabe gpt-4.1. Werkzeugfrist im Kern: Handbuch 15 s, Verlauf 12 s, sonst 8 s.
- **Geräteschwelle 70, nicht 60**: unter 70 trägt nur noch `similar_text`, und ein einzelner schwacher Treffer schaltete im Prüfstand ein fremdes Gerät. Etagennamen sind sich per `similar_text` zu 87 % ähnlich und meinen das Gegenteil.
- **Einkaufsliste**: `DeleteItem` über `SL_AppCall` will die rohe ID als Text, kein JSON — sonst `ok:true` ohne Wirkung.
- **Fremde Ausgaben**: manche Zielmodule echoen beim Schalten (z. B. Szenen). Ausgaben um Schaltaufrufe werden gepuffert, sonst zerbrechen sie die Hook-Antwort.
- **Handbuch-Verzeichnis**: beim Fertigwerden werden die Baudateien umbenannt. Wer auf die Baunamen schaut, sieht „0 Stücke".
- Knopf-Ausgaben im Formular nie per `echo` aus `RequestAction` (erscheint als Warnung mit Zeilennummer), sondern per `UpdateFormField`.
- Prüfstände: `SymDoGateway/tests/VoiceDevicesTest.php` (braucht Symcon-Stubs, Pfad über `SYMCON_STUBS`), `VoiceGeraetProfilTest.php`, `VoiceLiveTest.php`, `VoiceLiveKernTest.mjs`, `VoiceVerlaufTest.php`. Wer den Geräte-Auflöser ändert: erst dieser Prüfstand, dann live.

## Offen

- Der echte Push-Versand von `nachricht_senden` und der Tagesdeckel dafür sind nur im Prüfstand belegt (nicht am Code prüfbar).
- GPT-Live: nur der Sitzungsaufbau ist belegt, eine Hörprobe fehlt (nicht am Code prüfbar).
- Wahl genau eines Weckwort-Lauschers je Ursprung (etwa per `BroadcastChannel`) ist nicht gebaut.
- Schaltdeckel je Stunde und Rollladen-Richtung sind nicht live geprüft (nicht am Code prüfbar).

Stand: geprüft gegen den Code am 08.10.2026

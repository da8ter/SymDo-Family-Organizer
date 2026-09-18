# SymDo - Scanner

**Die zweite Spur. Er nimmt dem Gateway alles ab, was lange dauert — damit die
App antwortet, während im Hintergrund eine Klassenseite gelesen oder eine KI
befragt wird.**

Symcon führt je Instanz genau **eine** Sache zur Zeit aus. Ein Hook, ein Timer,
ein `RequestAction` — alles in derselben Reihe. Solange das Gateway einen
Elternbrief auswertet (bis zu 45 Sekunden) oder vier Klassenseiten abruft
(jede eine halbe bis fünfzehn Sekunden), wartet jede Anfrage der App.

Gemessen am 11.09.2026: 30 gleichzeitige Abrufe brauchten **874 ms statt 31**,
während ein Timer lief. Der Hook einer *anderen* Instanz blieb dabei
unbeeindruckt — die Sperre gilt je Instanz, nicht für den Server. Genau dort
setzt dieses Modul an: es ist eine andere Instanz.

## Inhalt

- **1. Was er tut**
- **2. Einrichtung**
- **3. Die Rollen**
- **4. Wie ein Auftrag läuft**
- **5. Was beim Gateway bleibt — und warum**
- **6. Wenn etwas klemmt**
- **7. PHP-Befehlsreferenz**

## 1. Was er tut

Er holt, liest und rechnet. Er schreibt **nichts** in die Bestände — weder
Notizen noch Aufgaben, Termine, Medien oder Vorschläge. Was er erarbeitet,
legt er als Datei ab; das Gateway pflegt es in einem kurzen Griff ein
(Millisekunden) und klingelt bei der App.

Diese Teilung ist keine Geschmacksfrage. Medienobjekte hängen unter der
Instanz, die sie anlegt — ein Anhang, den der Scanner ablegte, fände das
Gateway nie wieder. Dasselbe gilt für Attribute: was hier steht, kann drüben
niemand lesen.

## 2. Einrichtung

**Es gibt keine.** Das Gateway legt seine Scanner beim Übernehmen selbst an,
verbindet sie und trägt ein, welche Quellen sie bedienen. Im Objektbaum stehen
sie **neben** dem Gateway, nicht darunter: Symcon räumt Kinder mit ihrer
Instanz weg, und ein gelöschtes Gateway nähme die Scanner sonst mit.

Sie sind **Splitter-Instanzen**, keine Geräte: der Scanner steuert nichts und
zeigt nichts an, er nimmt dem Gateway die langen Abrufe ab. In der
Geräteliste der Konsole steht er deshalb nicht — versteckt wird er auch
nicht mehr (bis zum 18.09.2026 war er es).

Wer eine Scanner-Instanz von Hand löscht, verliert nichts — das Gateway macht
die Arbeit dann wieder selbst, nur eben in seiner eigenen Spur. Beim nächsten
Übernehmen legt es sie neu an.

Zwei Felder gibt es trotzdem:

| Feld | Bedeutung |
|---|---|
| **Rolle** | Wofür diese Instanz da ist (`jobs`, `schule`, `briefing`). Das Gateway setzt sie; von Hand ändern heißt, ihm zu widersprechen. |
| **Aufgaben** | Die Liste der Quellen. Eine Zeile abschalten gibt die Quelle ans Gateway zurück — das ist der Rückwärtsgang, falls etwas klemmt. |

Nach einer Änderung an den Quellen braucht es einen **Kernel-Neustart**, wenn
dabei neue Attribute oder Präfixfunktionen hinzugekommen sind. Das steht dann
im Meldungsfenster.

## 3. Die Rollen

Drei Instanzen, drei Reihen — damit nichts hinter etwas anderem wartet.

**`jobs` — Aufträge.** Sie arbeitet die Warteschlange der KI-Aufrufe ab:
Foto-Scan, Zutatenliste, Diktat, und im Hintergrund die Auswertung von
Klassenseiten-Karten, LOGINEO-Karten, Postfach-Mails und dem Briefing. Takt:
alle zwei Sekunden, geweckt wird sie aber sofort.

**`schule` — Klassenseiten, LOGINEO, WebUntis, Handbuch.** Das Langsame: vier
Klassenseiten abrufen und zerlegen, ein LOGINEO-Konto mit allen Kursen lesen
(je Aufgabe ein eigener Abruf für den Abgabestand), den Stundenplan samt
Hausaufgaben holen, das Handbuch-Verzeichnis bauen.

Bei **WebUntis** gilt eine Regel, die sonst nirgends nötig ist: **genau eine
Anmeldung je Lauf.** Drei Fehlanmeldungen sperren das Schulkonto der Familie,
also klammert `UntisKontoErnten` alle Kinder in eine Sitzung und meldet sich am
Ende ab — auch wenn dazwischen etwas wirft. Ob überhaupt angemeldet werden
darf, hat das Gateway vorher entschieden.

**`briefing`** — heute noch leer. Das Briefing läuft als KI-Auftrag über die
Rolle `jobs`; die eigene Instanz entsteht erst, wenn eine Quelle sie braucht.

Eine Rolle ohne Quellen bekommt **keine** Instanz. Eine stumme Instanz wäre nur
eine Zeile im Objektbaum, die niemand erklären kann.

## 4. Wie ein Auftrag läuft

1. Das Gateway legt eine **Auftragsdatei** ab (0600, im Kernel-Verzeichnis) und
   erhöht eine **Signalvariable**.
2. Der Scanner hört auf diese Variable. Sein `MessageSink` tut nur eines: einen
   Einmal-Timer stellen — denn die Nachricht kommt in einem eigenen Thread an,
   und Arbeit gehört nicht dorthin.
3. Der Timer läuft in **seiner** Reihe. Er nimmt den Auftrag, arbeitet ihn ab
   und legt das Ergebnis als **Umschlag** ab.
4. Er ruft kurz ins Gateway (`ScanInbox`). Das kostet dort die Dauer eines
   Hooks, also Millisekunden.
5. Das Gateway pflegt ein und klingelt bei App und Web-App.

Fällt der Weckruf aus, greift der **Takt**: alle fünf Minuten sieht die Instanz
von selbst nach. Das Signal ist der schnelle Weg, der Takt das Sicherheitsnetz.

Aufträge **verschmelzen**, wenn mehrere warten: zwei Anstöße kurz hintereinander
werden ein Lauf. Der jüngere Stand gewinnt — sonst brächte ein alter Auftrag
eine gelöschte Seite oder einen widerrufenen Token zurück.

### Was mit einem Auftrag reist

Alles, was der Scanner nicht selbst lesen kann. `IPS_GetProperty` auf eine
fremde Instanz zeigt einen **hinterlegten, noch nicht übernommenen** Wert
nicht, und an ein Attribut kommt er gar nicht heran. Also reisen mit:

- die **Seitenliste** der Klassenseiten,
- die **Zugänge** von LOGINEO **samt Token**,
- die **Kinder** von WebUntis (Zuordnung zur Stundenplan-Instanz, Mitglied,
  Elementnummer),
- die **Sperrliste** — Adressen, die jemand in der App gelöscht hat.

Der Token liegt damit in der Auftragsdatei. Sie hat 0600 im
Kernel-Verzeichnis — strenger als der Ort, an dem er ohnehin steht:
`settings.json` ist weltlesbar.

**Zugangsdaten reisen nicht mit.** Server, Schule, Benutzer und Kennwort von
WebUntis sind *Eigenschaften* des Gateways, und `IPS_GetConfiguration` auf eine
fremde Instanz liefert sie — der Scanner liest sie selbst. Ein Kennwort in
einer Datei wäre ein Risiko ohne Gegenwert.

## 5. Was beim Gateway bleibt — und warum

Nicht alles ist umgezogen, und das ist jeweils eine Entscheidung mit Grund:

| Bleibt im Gateway | Warum |
|---|---|
| **Jede Ablage** — Notizen, Aufgaben, Termine, Medien, Vorschläge | Medien hängen unter der Instanz, die sie anlegt; Attribute sind von außen nicht lesbar. |
| **Die Merker** (`EduSeen`, `MoodleSeen`, `MailSeenUIDs`) | Ein Bestand, ein Besitzer. Zwei wären ein doppelter Scan und doppelte Kosten. |
| **Die Tagesdeckel** | Sie zählen an einem Attribut dieser Instanz. |
| **Der Prompt** | Er braucht den Bestand: Mitglieder, Kinder, erlaubte Arten. |
| **Der WebUntis-Fehlerzähler** | An ihm hängt der Schutz vor der Kontosperre: drei Fehlanmeldungen sperren das Schulkonto. Er ist ein Attribut dieser Instanz, und nur sie führt ihn — der Scanner meldet, *was* passiert ist. |
| **Der Mail-Webhook** | Sein Zustand *ist* die Spool-Datei: sie trägt den vollen Text und ist zugleich der Wiederholungsvermerk. |
| **„Jetzt prüfen" (LOGINEO)** | Ein Trockenlauf schreibt nichts, und jemand wartet davor. |
| **Die Briefing-Vorschau** | Sie läuft im Hook und antwortet dem Wartenden sofort. |
| **Das Sammeln fürs Briefing** | Es liest ein Dutzend Bestände dieser Instanz — mitschicken hieße, den halben Haushalt in eine Datei zu schreiben. |

### Die Richtungsregel

**Die Gateway-Spur ruft nie synchron in einen Scanner.** Kein `SDSC_…`, kein
`IPS_RequestAction` auf eine Scanner-Instanz aus Hook, Timer oder
Formularaufbau — das Gateway würde sonst auf eine Reihe warten, in der gerade
ein Scan von Minuten läuft.

Umgekehrt ist es erlaubt: ein Ruf ins Gateway kostet dort Millisekunden. Und
ein Knopf im Konsolen-Formular läuft in seinem eigenen Skript-Thread, nicht in
der Gateway-Spur — er darf direkt hereinrufen
(`echo SDSC_Auftrag(<id>, 'edu', '{}');`).

Ein Prüfstand durchsucht die Gateway-Dateien nach beiden Mustern.

## 6. Wenn etwas klemmt

**Nichts passiert.** `SDSC_Stand(<id>)` sagt, was die Instanz sieht: ihre
Rolle, ihre Quellen, das gefundene Gateway, wie viele Aufträge warten. Steht
dort `gateway: 0`, findet sie keines — dann ist die Verbindung gelöst.

**Aufträge stapeln sich.** Der Kanal hat einen Deckel und einen Verfall: was
älter als 24 Stunden ist, fliegt raus. Ein Umschlag, der sich nicht einpflegen
lässt, landet sichtbar im Ordner `fehler/` statt still zu verschwinden.

**Eine Quelle soll zurück ins Gateway.** Die Zeile in *Aufgaben* abschalten,
übernehmen. Das Gateway merkt es beim nächsten Formularaufbau und macht die
Arbeit wieder selbst.

**Messen.** Eine leere Datei `symdo-messung.an` im Datenverzeichnis von Symcon
anlegen; danach schreiben Gateway *und* Scanner je eine Zeile pro Vorgang nach
`symdo-belegung.log`. Dort steht die Instanz-Kennung vorn — so sieht man, wer
wie lange belegt. Näheres im Handbuch des Gateways, Kapitel 19.

## 7. PHP-Befehlsreferenz

```php
// Einen Auftrag von Hand ausführen und den Bericht zurückbekommen.
// Läuft im Skript-Thread der Konsole, nicht in der Gateway-Spur.
echo SDSC_Auftrag(<id>, 'edu', '{}');
echo SDSC_Auftrag(<id>, 'probe', '{"verweilen":5}');

// Was diese Instanz gerade sieht (JSON).
echo SDSC_Stand(<id>);
```

`SDSC_Auftrag` weist eine Quelle ab, die diese Instanz nicht bedient — und eine,
die es gar nicht gibt. Der Rückgabewert ist der Statustext des Laufs.

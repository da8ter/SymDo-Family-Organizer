# SymDo - VRR Transit

**Die Abfahrtstafel für zu Hause — und ein Schulweg, der von selbst weiß, wann und in
welche Richtung er geht.**

Ein Blick an die Wand genügt: Wann fährt der nächste Bus? Reicht die Zeit noch
für einen Kaffee? Und wie kommt das Kind heute zur Schule? Die Kachel zeigt
beides — die Abfahrten an euren Haltestellen und ganze Verbindungen von Tür zu
Tür, live aus der Fahrplanauskunft des VRR, mit Rheinbahn und allen anderen im
Verbund.

Das Beste daran: Der **Schulweg** richtet sich selbst ein. Er holt sich Beginn
und Ende des Schultags aus dem Stundenplan und dreht morgens wie mittags die
Richtung um — hin, solange Unterricht ist, zurück, sobald Schluss ist. Fällt
die erste Stunde aus, darf länger geschlafen werden.

## Inhalt

- **1. Was die Kachel zeigt**
- **2. Voraussetzungen**
- **3. Einrichtung**
- **4. Der Schulweg**
- **5. Bedienung**
- **6. Wann abgerufen wird**
- **7. Wenn die Auskunft nicht antwortet**
- **8. Auch in der App**
- **9. Woher die Daten kommen**
- **10. PHP-Befehlsreferenz**

## 1. Was die Kachel zeigt

Ganz oben wechselt man zwischen **Abfahrten** und **Strecken**. Wer nur eines
von beidem eingerichtet hat, sieht den Umschalter gar nicht erst. Die Wahl
bleibt gemerkt.

Gehört eine Haltestelle oder eine Strecke zu einem Familienmitglied, steht sie
unter dessen Foto und Namen — man sieht auf einen Blick, wessen Bus da fährt.

**Abfahrten** — je Haltestelle eine Karte: Symbol und Liniennummer, ein Pfeil
und das Ziel, rechts die Minuten. Und zwar die Minuten, auf die es ankommt: Ist
ein Fußweg eingetragen, zählt die Kachel herunter, bis man **losgehen** muss,
sonst bis zur Abfahrt. Eine Verspätung steht als `+3` daneben, das Gleis als
`Steig 3`. Was ausfällt, ist durchgestrichen, was man ohnehin nicht mehr
erwischt, tritt blass zurück.

**Strecken** — je Strecke eine Karte mit den nächsten Fahrten nebeneinander;
zur übernächsten wischt man seitwärts, die Punkte darunter zeigen, wo man ist.
Je Fahrt: Abfahrt → Ankunft, die Dauer und *direkt* oder *n Umstiege*. Darunter
die Zeitachse, ein Balken je Abschnitt — so sieht man sofort, wo die Zeit
hingeht: lange im Bus, kurz umsteigen, zehn Minuten zu Fuß.

**Zwei Ansichten für die Strecken.** Unter *Darstellung* im Formular wählt man:

- **Kompakt (Balken)** — eine Zeile je Fahrt, mehrere Fahrten passen
  untereinander. Gut für eine schmale Kachel.
- **Ausführliche Zeitachse** — die Fahrt ausgebreitet: links die Abfahrt und
  „in 9 min", rechts die Ankunft und die Gesamtdauer. Dazwischen die Strecke mit
  einem Punkt je Halt, dem Liniennummernschild über jedem Abschnitt, Fahrzeit
  und Zahl der Haltestellen darunter, und am Umstieg der Name mit der Uhrzeit.
  Ganz unten Gesamtdauer, Umstiege und — wenn das Verkehrsunternehmen sie meldet
  — die Auslastung. Am schönsten auf einer breiten Kachel; wird es eng, fallen
  Steig und Haltestellenzahl weg, Uhrzeiten und Linien bleiben.

- **Senkrecht, mit Haltestellen** — die Fahrt von oben nach unten gelesen: je
  Halt die Uhrzeit, der Name und der Steig, dazwischen die Linie mit ihrer
  Richtung. Und je Fahrt lässt sich aufklappen, wo der Wagen überall hält —
  mit Uhrzeit für jede Haltestelle. Die Striche dazwischen sind so lang, wie die
  Fahrt dauert; nur unter einer knappen Viertelstunde gibt der Platzbedarf den
  Ausschlag, weil Linie und Knopf hineinpassen müssen. Am schönsten auf einer
  hohen Kachel.

**Farben und Symbole nach Verkehrsmittel.** Jede Fahrt trägt die Farbe ihrer
Gattung — S-Bahn grün, U-Bahn blau, Straßenbahn rot, Bus violett, Regionalzug
schiefergrau, Fernzug dunkelrot. So sieht man auf einen Blick, worin man sitzt.

Unter *Darstellung* steht dafür eine Tabelle: je Verkehrsmittel ein Symbol und
eine Farbe, beides frei wählbar. **Vorgaben wiederherstellen** holt die
Ausgangswerte zurück. Der VRR selbst liefert keine Farben mit — das ist unsere
Zuordnung, die im deutschen Nahverkehr übliche.

Die Wahl gilt für die Instanz, nicht je Betrachter — anders als der Schalter
*Alle / Ohne Umsteigen*, den sich jedes Gerät für sich merkt. Welche Verbindung
man sich gerade ansieht und welche Haltestellenliste offen steht, bleibt
dagegen erhalten, auch wenn im Hintergrund neue Zeiten hereinkommen.

## 2. Voraussetzungen

| | |
|---|---|
| Symcon | ab 8.1 |
| **SymDo Gateway** | Pflicht — von dort kommen die Familienmitglieder |
| **SymDo Stundenplan** | nur für den Schulweg |

Ohne Stundenplan läuft die Kachel genauso; nur eine Strecke mit *Schulweg*
bleibt dann leer.

## 3. Einrichtung

1. Instanz **SymDo - VRR Transit** anlegen; als übergeordnete Instanz das
   vorhandene SymDo Gateway wählen (die Konsole schlägt es vor).
2. **Haltestelle suchen** aufklappen, den Ort mit eingeben — das grenzt die
   Treffer stark ein — und **Als Haltestelle übernehmen**.
3. In der Liste **Haltestellen** je Zeile einstellen:

| Spalte | Bedeutung |
|---|---|
| Name | wie die Karte überschrieben wird |
| Zeigen | ohne Haken erscheint sie nicht auf der Tafel und wird nicht abgerufen |
| Für wen | leer = die ganze Familie |
| Linien und Richtungen | je Linie und Ziel ein Haken — ohne Haken erscheint diese Tour nicht auf der Tafel |
| Fußweg (min) | Zeit bis zur Haltestelle; schaltet die Anzeige auf „wann muss ich los" |
| Anzahl | wie viele Abfahrten die Karte zeigt |

4. In der Liste **Strecken** Start und Ziel wählen. Jede eingerichtete
   Haltestelle steht dort zur Auswahl; für die eigene Haustür stattdessen
   **Von (Karte)** bzw. **Nach (Karte)** nehmen und die Stelle auf der Karte
   setzen. **Von (Name)** / **Nach (Name)** überschreiben, was die Auskunft
   nennt; bei einer Koordinate ist das die Adresse.
5. **Wann**: *Schulweg*, *Jetzt losfahren* oder *Ankommen bis …*.

**Linien und Richtungen.** Beim Übernehmen einer
Haltestelle schaut die Kachel selbst nach, was dort fährt, und legt es als
kleine Liste in die Zeile: je Linie und Ziel eine Reihe mit einem Haken. Man
nimmt die Haken weg, die man nicht sehen will — fertig. Für Haltestellen, die
schon in der Liste stehen, holt der Knopf **Linien und Richtungen holen** das
nach; er füllt nur leere Listen, eine gepflegte bleibt in Ruhe. Gespeichert
wird erst mit *Übernehmen*.

Weg ist nur, was in der Liste steht und keinen Haken hat. Kommt später eine
neue Linie an die Haltestelle, fährt sie einfach mit auf der Tafel. An einem
großen Bahnhof wird bei vierzig Einträgen Schluss gemacht; die Kachel sagt es
dann.

**Lieber ohne Umsteigen?** Bei den Strecken steht ein zweiter kleiner Schalter:
**Alle** oder **Ohne Umsteigen**. Er wechselt sofort, ohne Warten — und unter
*Ohne Umsteigen* tauchen dabei Verbindungen auf, die unter *Alle* gar nicht
dabei waren. Die Wahl merkt sich jedes Gerät für sich; gibt es auf einer
Strecke wirklich keine durchgehende Fahrt, sagt die Karte das.

Auf der **Webapp Dashboard** gibt es keinen Schalter, dort soll ohne Zutun das
Richtige stehen. Was die Schulweg-Karte dort zeigt, bestimmt der Haken
**Übersicht ohne Umsteigen** an der Strecke.

## 4. Der Schulweg

Hier muss keine Uhrzeit eingetragen werden — die kommt aus dem Stundenplan des
Kindes. Morgens zeigt die Karte die Fahrt **zur Schule**, nach Unterrichtsbeginn
die **nach Hause**; Start und Ziel tauschen dabei die Plätze, und die Kopfzeile
sagt, welche Richtung gerade dran ist.

Und zwar nach dem echten Tag: Fällt die erste Stunde aus, geht es später los.
Fällt die letzte aus, früher heim.

Der **Puffer** ist die Luft, die man haben will — auf dem Hinweg „so viele
Minuten vor Unterrichtsbeginn da sein", auf dem Rückweg „so viele Minuten vom
Klassenraum bis zur Haltestelle". Der Wert unter *Schulweg* gilt für alle
Strecken, jede Zeile darf davon abweichen.

**Stundenplan (leer = automatisch)** muss nur gesetzt werden, wenn es mehrere
Stundenplan-Instanzen gibt.

## 5. Bedienung

| Geste | Wirkung |
|---|---|
| **Abfahrten** / **Strecken** antippen | Ansicht wechseln, die Wahl bleibt gemerkt |
| in einer Streckenkarte seitwärts wischen | zur nächsten Fahrt |
| auf eine Abfahrt zeigen | Verkehrsmittel, Linie, Ziel und die **planmäßige** Zeit erscheinen als Hinweis |

Mehr gibt es nicht zu tun — die Kachel zeigt, man liest.

## 6. Wann abgerufen wird

**Jede Minute — aber nur, solange jemand hinschaut.** Ist die Kachel zu und die
App aus, fragt auch niemand nach; nach drei Minuten ohne Zuschauer ist Ruhe.

Eine Ausnahme gibt es für den Schulweg: **zwischen 5 und 9 Uhr** wird alle zehn
Minuten auch dann abgerufen, wenn keiner hinsieht. So steht die Auskunft schon
da, wenn morgens der Erste in die Küche kommt.

## 7. Wenn die Auskunft nicht antwortet

Schweigt die Auskunft **dreimal hintereinander**, wird eine Stunde lang nicht
mehr gefragt. Die letzten geholten Zeiten bleiben stehen und bekommen ein
**veraltet** an die Karte — lieber die von vorhin als gar keine. Der morgendliche
Abruf zwischen 5 und 9 Uhr läuft trotzdem weiter.

## 8. Auch in der App

Dieselben Abfahrten und Verbindungen stehen auch in der SymDo-Web-App.

## 9. Woher die Daten kommen

Alle Zeiten kommen aus der Fahrplanauskunft des **Verkehrsverbunds Rhein-Ruhr
(VRR)**. Der Verbund gibt sie als offene Daten heraus: nutzen darf sie jeder,
solange er sagt, woher sie stammen. Genau das steht deshalb klein unter der
Kachel und im Formular:

> Fahrplandaten: Verkehrsverbund Rhein-Ruhr (VRR)

Die Lizenz ist Creative Commons Namensnennung 4.0 (CC BY 4.0). Für zu Hause ist
damit alles getan: Der VRR stellt seinen offenen Zugang ausdrücklich für
Forschung, Test, Entwicklung und Hobby bereit, ohne Anmeldung und ohne Kosten.
Er behält sich dabei vor, denselben Server auch für eigene Tests zu benutzen —
sehr selten kann deshalb Ungereimtes dabei sein. Wer darauf eine Anwendung baut
und sie anderen öffentlich anbietet, holt sich vorher einen Produktivzugang:
eine kurze Mail mit Projektbeschreibung an `opendata-oepnv@vrr.de` genügt.

## 10. PHP-Befehlsreferenz

```php
// Jetzt abrufen — auch der Knopf im Formular tut genau das
SDVT_Refresh(<InstanzID>);

// Alles, was die Kachel anzeigt, als JSON-Text
echo SDVT_GetBoard(<InstanzID>);

// Die Kachel neu zeichnen lassen und melden, dass jemand hinsieht
IPS_RequestAction(<InstanzID>, 'GetState', 0);
```

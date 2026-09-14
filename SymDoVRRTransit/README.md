# SymDo - VRR Transit

**Abfahrtstafel und Verbindungen aus der Fahrplanauskunft des VRR — und der
Schulweg, der von selbst weiß, in welche Richtung er heute geht.**

Die Fahrplanauskunft des VRR (EFA) umfasst die Rheinbahn und alle anderen
Verkehrsunternehmen im Verbund, braucht keinen Schlüssel und liefert
Echtzeitdaten. Zwei Ansichten in einer Kachel: die Abfahrtstafel einer
Haltestelle und die Zeitachse einer Verbindung.

Das Stück, das eine Fahrplan-App nicht kann: **SymDo kennt den Stundenplan.**
Eine Strecke im Modus *Schulweg* fragt ihn nach Beginn und Ende des Schultags
und dreht die Richtung selbst um — morgens die Verbindung, mit der das Kind
pünktlich ankommt, ab Unterrichtsbeginn die für den Rückweg.

## Inhalt

- **1. Was die Kachel zeigt**
- **2. Voraussetzungen**
- **3. Einrichtung**
- **4. Der Schulweg**
- **5. Bedienung**
- **6. Wann abgerufen wird**
- **7. Wenn die Auskunft nicht antwortet**
- **8. Auch in der App**
- **9. PHP-Befehlsreferenz**

## 1. Was die Kachel zeigt

Oben nur der Umschalter zwischen **Abfahrten** und **Strecken**. Ist nur eine
der beiden Sorten eingerichtet, fällt er weg — er wäre dann Lärm. Die Wahl
bleibt im Browser gemerkt.

Gehören Haltestellen oder Strecken einem Familienmitglied, stehen sie unter
einer Überschrift mit Foto und Namen. Wem eine Verbindung gehört, sagt also die
Überschrift; Filterknöpfe gibt es bewusst keine, denn die Kachel zeigt ohnehin
nur, was eingerichtet ist.

**Abfahrten** — je Haltestelle eine Karte: Symbol und Liniennummer, das Ziel,
und rechts die Zahl, auf die man wirklich schaut. Ist ein Fußweg gepflegt, ist
das **„wann muss ich los"**, sonst **„wann fährt es"**. Dahinter eine
Verspätung als `+3`, dazu das Gleis (`Gl. 3`), wenn die Auskunft eines nennt.
Was entfällt, steht durchgestrichen als *entfällt*; was mit dem eingetragenen
Fußweg nicht mehr zu schaffen ist, steht blass.

**Strecken** — je Strecke eine Karte mit den nächsten Fahrten **nebeneinander**;
gewischt wird seitwärts, die Punkte darunter sagen, wo man ist. Je Fahrt:
Abfahrt → Ankunft, die Dauer, und *direkt* oder *n Umstiege*. Darunter die
Zeitachse — ein Balken je Abschnitt, breit nach Dauer, im Balken nur die
Liniennummer. Wie viel Zeit wohin geht, sagt die Zeile darunter; sie bleibt
lesbar, auch wenn ein Fußweg nur ein schmales Stück bekommt.

## 2. Voraussetzungen

| | |
|---|---|
| Symcon | ab 8.1 |
| **SymDo Gateway** | Pflicht — von dort kommen die Familienmitglieder |
| **SymDo Stundenplan** | nur für den Schulweg; ohne ihn bleiben die übrigen Modi |
| Schlüssel, Konto, Registrierung | **nichts davon** — die EFA ist offen |

Die Kachel läuft auch ohne Stundenplan. Eine Strecke im Modus *Schulweg* findet
dann allerdings keine Zeiten und bleibt leer.

## 3. Einrichtung

1. Instanz **SymDo - VRR Transit** anlegen; als Eltern das vorhandene SymDo
   Gateway wählen (die Konsole schlägt es vor).
2. **Haltestelle suchen** aufklappen, den Ort mit eingeben — das grenzt die
   Treffer stark ein — und **Als Haltestelle übernehmen**. Die Kennungen der
   EFA (`de:05111:18235`) tippt niemand ab; was in den Listen steht, ist
   eingerichtet.
3. In der Liste **Haltestellen** je Zeile einstellen:

| Spalte | Bedeutung |
|---|---|
| Name | wie die Karte überschrieben wird |
| Haltestellen-Kennung | kommt aus der Suche |
| Zeigen | ohne Haken erscheint sie nicht auf der Tafel — **und kostet keinen Abruf**; genau richtig für eine Haltestelle, die nur Start oder Ziel einer Strecke ist |
| Für wen | leer = die ganze Familie |
| Nur diese Linien | leer = alle |
| Richtung | das Ziel, wie es vorn am Fahrzeug steht („Hbf, Krankenhaus"), mehrere durch Komma, oder ein Steig („Steig 1"). Leer = beide Richtungen |
| Fußweg (min) | Zeit bis zur Haltestelle; schaltet die Anzeige auf „wann muss ich los" |
| Anzahl | wie viele Abfahrten die Karte zeigt |

4. In der Liste **Strecken** Start und Ziel wählen. Jede eingerichtete
   Haltestelle steht dort zur Auswahl; für die eigene Haustür stattdessen
   **Von (Karte)** bzw. **Nach (Karte)** nehmen und den Marker setzen. **Von
   (Name)** / **Nach (Name)** überschreiben, was die Auskunft nennt — bei einer
   Koordinate ist das die Adresse, und „Zuhause" liest sich besser.
5. **Wann**: *Schulweg*, *Jetzt losfahren* oder *Ankommen bis …*.

Der Richtungsfilter bleibt bewusst unsichtbar im Kopf der Tafel: er gehört zur
Einrichtung, nicht zur Bedienung.

## 4. Der Schulweg

Eine Strecke im Modus **Schulweg** braucht keine Zeit — sie kommt aus dem
Stundenplan des Kindes. Vor Unterrichtsbeginn zeigt die Karte die Fahrt **zur
Schule**, danach die **nach Hause**; Start und Ziel tauschen dabei die Plätze,
und die Kopfzeile sagt, welche Richtung gerade zu sehen ist.

Gezählt wird nur, was wirklich stattfindet: **fällt die erste Stunde aus, darf
es später losgehen; fällt die letzte aus, geht es früher heim.**

Der **Puffer** heißt auf dem Hinweg „so viele Minuten vor Unterrichtsbeginn da
sein", auf dem Rückweg „so viele Minuten vom Klassenraum bis zur Haltestelle".
Der Wert unter *Schulweg* gilt für alle Strecken; jede Zeile darf davon
abweichen.

**Stundenplan (leer = automatisch)** muss nur gesetzt werden, wenn es mehrere
Stundenplan-Instanzen gibt.

## 5. Bedienung

| Geste | Wirkung |
|---|---|
| **Abfahrten** / **Strecken** antippen | Ansicht wechseln, die Wahl bleibt gemerkt |
| in einer Streckenkarte seitwärts wischen | zur nächsten Fahrt |
| auf eine Abfahrt zeigen | Verkehrsmittel, Linie, Ziel und die **planmäßige** Zeit als Tooltip |

Die Kachel ist eine Anzeige — es gibt nichts zu ändern, nur zu lesen.

## 6. Wann abgerufen wird

**Im Minutentakt, aber nur, solange jemand hinsieht.** Kachel und App melden
sich beim Öffnen und danach jede Minute; der Stempel verfällt nach drei
Minuten. Liegt niemands Blick darauf, fragt das Modul nicht bei der Auskunft
nach.

Eine Ausnahme: gibt es einen Schulweg, läuft **zwischen 5 und 9 Uhr** ein
langsamer Schlag alle zehn Minuten mit — die Auskunft soll am Frühstückstisch
schon dastehen und nicht erst auf den ersten Blick warten.

**Geholt wird in dieser Instanz, nicht im Gateway.** Symcon führt je Instanz
genau eine Sache zur Zeit aus; ein Abruf von ein bis anderthalb Sekunden im
Gateway ließe jede Anfrage der App so lange warten. Gemessen am 11.09.2026:
während das Modul 1,30 s lang holt, bleibt der Hook des Gateways bei 10 ms.

## 7. Wenn die Auskunft nicht antwortet

Nach **drei Fehlschlägen in Folge** legt das Modul eine Stunde Pause ein. Der
zuletzt geholte Stand bleibt stehen und trägt dann ein **veraltet** an der
Karte — eine leere Tafel wäre die schlechtere Auskunft. Während der Pause läuft
der langsame Schlag weiter, sonst öffnete sie sich nie wieder.

## 8. Auch in der App

Das Gateway holt sich die fertige Auskunft aus allen VRR-Instanzen und reicht
sie an App und Web-App weiter (`/v1/transit`). Eingerichtet wird trotzdem nur
hier; das Gateway pflegt nichts eigenes.

## 9. PHP-Befehlsreferenz

```php
// Jetzt abrufen — auch der Knopf im Formular tut genau das
SDVT_Refresh(<InstanzID>);

// Der fertige Zustand als JSON (Haltestellen, Strecken, Sperre).
// Rein lesend: läuft auch im Hook einer beschäftigten Instanz vollständig durch.
echo SDVT_GetBoard(<InstanzID>);

// Die Kachel neu zeichnen lassen und „jemand sieht hin" melden
IPS_RequestAction(<InstanzID>, 'GetState', 0);
```

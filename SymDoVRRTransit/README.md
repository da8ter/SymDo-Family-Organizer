# SymDo - VRR Transit

**Abfahrtstafel und Verbindungen aus der Fahrplanauskunft des VRR — und der
Schulweg, der von selbst weiß, in welche Richtung er heute geht.**

Die Fahrplanauskunft des VRR umfasst die Rheinbahn und alle anderen
Verkehrsunternehmen im Verbund, braucht keinen Schlüssel und liefert
Echtzeitdaten. Zwei Ansichten in einer Kachel: die Abfahrtstafel einer
Haltestelle und die Zeitachse einer Verbindung.

Eine Strecke mit *Schulweg* nimmt Beginn und Ende des Schultags aus dem
Stundenplan und dreht die Richtung selbst um: morgens die Verbindung, mit der
das Kind pünktlich ankommt, ab Unterrichtsbeginn die für den Rückweg.

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

Oben der Umschalter zwischen **Abfahrten** und **Strecken**. Ist nur eine der
beiden Sorten eingerichtet, fällt er weg. Die Wahl bleibt im Browser gemerkt.

Gehören Haltestellen oder Strecken einem Familienmitglied, stehen sie unter
einer Überschrift mit Foto und Namen.

**Abfahrten** — je Haltestelle eine Karte: Symbol und Liniennummer, Pfeil und
Ziel, rechts die Minuten. Ist ein Fußweg eingetragen, sind das die Minuten bis zum
Losgehen, sonst bis zur Abfahrt. Dahinter eine Verspätung als `+3`, dazu das
Gleis (`Gl. 3`), wenn die Auskunft eines nennt.
Was entfällt, steht durchgestrichen als *entfällt*; was mit dem eingetragenen
Fußweg nicht mehr zu schaffen ist, steht blass.

**Strecken** — je Strecke eine Karte mit den nächsten Fahrten **nebeneinander**;
gewischt wird seitwärts, die Punkte darunter sagen, wo man ist. Je Fahrt:
Abfahrt → Ankunft, die Dauer, und *direkt* oder *n Umstiege*. Darunter die
Zeitachse — ein Balken je Abschnitt, breit nach Dauer, im Balken die
Liniennummer. Wie viel Zeit wohin geht, sagt die Zeile darunter.

## 2. Voraussetzungen

| | |
|---|---|
| Symcon | ab 8.1 |
| **SymDo Gateway** | Pflicht — von dort kommen die Familienmitglieder |
| **SymDo Stundenplan** | nur für den Schulweg; ohne ihn bleiben die übrigen Modi |
| Schlüssel, Konto, Registrierung | **nichts davon** — die Auskunft ist offen |

Die Kachel läuft auch ohne Stundenplan. Eine Strecke mit *Schulweg* findet
dann allerdings keine Zeiten und bleibt leer.

## 3. Einrichtung

1. Instanz **SymDo - VRR Transit** anlegen; als übergeordnete Instanz das
   vorhandene SymDo Gateway wählen (die Konsole schlägt es vor).
2. **Haltestelle suchen** aufklappen, den Ort mit eingeben — das grenzt die
   Treffer stark ein — und **Als Haltestelle übernehmen**. Die Kennung der
   Haltestelle (`de:05111:18235`) kommt aus der Suche.
3. In der Liste **Haltestellen** je Zeile einstellen:

| Spalte | Bedeutung |
|---|---|
| Name | wie die Karte überschrieben wird |
| Haltestellen-Kennung | kommt aus der Suche |
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

**Linien und Richtungen** werden nicht getippt. Jede Haltestellenzeile trägt
eine eigene kleine Liste: je Linie und Ziel eine Zeile mit einem Haken. Sie
wird beim **Übernehmen einer Haltestelle** gefüllt — mit dem, was dort fährt,
in der Reihenfolge der nächsten Abfahrten. Zu tun bleibt das Entfernen der
Haken, die nicht auf die Tafel sollen. Für Haltestellen, die schon in der Liste
stehen, tut der Knopf **Linien und Richtungen holen** dasselbe; gefüllt werden
nur **leere** Listen. Gespeichert wird nichts, bis *Übernehmen* gedrückt ist.

Versteckt wird nur, was in der Liste steht und keinen Haken hat. Eine Linie,
die neu an die Haltestelle kommt, erscheint weiter auf der Tafel. An einer großen Station wird bei vierzig Einträgen gekürzt; die
Statuszeile sagt es dann.

**Mit oder ohne Umsteigen** — bei den Strecken steht in der Kachel ein zweiter
Schalter neben *Abfahrten / Strecken*: **Alle** oder **Ohne Umsteigen**. Beide
Listen werden vorab geholt, der Schalter wechselt ohne Wartezeit. Unter *Ohne
Umsteigen* stehen dabei auch Verbindungen, die unter *Alle* nicht vorkommen.
Die Stellung wird im Browser gemerkt und gilt nur an diesem Gerät. Gibt es auf
einer Strecke keine umsteigefreie Verbindung, sagt die Karte „keine
umsteigefreie Verbindung".

**Auf der Übersicht** gibt es keinen Schalter. Was die Schulweg-Karte dort
zeigt, entscheidet der Haken **Übersicht ohne Umsteigen** an der Strecke. Er
steuert nur diese Karte.

## 4. Der Schulweg

Eine Strecke mit **Schulweg** braucht keine Zeit — sie kommt aus dem
Stundenplan des Kindes. Vor Unterrichtsbeginn zeigt die Karte die Fahrt **zur
Schule**, danach die **nach Hause**; Start und Ziel tauschen dabei die Plätze,
und die Kopfzeile sagt, welche Richtung gerade zu sehen ist.

Gezählt wird nur, was stattfindet: fällt die erste Stunde aus, geht es später
los; fällt die letzte aus, früher heim.

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
| auf eine Abfahrt zeigen | Verkehrsmittel, Linie, Ziel und die **planmäßige** Zeit erscheinen als Hinweis |

Die Kachel ist eine Anzeige; geändert wird nichts.

## 6. Wann abgerufen wird

**Jede Minute, aber nur, solange jemand hinsieht.** Kachel und App melden sich
beim Öffnen und danach jede Minute. Bleibt eine solche Meldung länger als drei
Minuten aus, hört das Abrufen auf.

Eine Ausnahme: gibt es einen Schulweg, wird **zwischen 5 und 9 Uhr** auch ohne
Zuschauer abgerufen, alle zehn Minuten. Die Auskunft steht dann am
Frühstückstisch schon bereit.

Geholt wird in dieser Instanz, nicht im Gateway.

## 7. Wenn die Auskunft nicht antwortet

Nach **drei Fehlschlägen in Folge** legt das Modul eine Stunde Pause ein. Der
zuletzt geholte Stand bleibt stehen und trägt ein **veraltet** an der Karte.
Der Abruf zwischen 5 und 9 Uhr läuft auch während der Pause weiter.

## 8. Auch in der App

Das Gateway holt die fertige Auskunft aus allen VRR-Instanzen und reicht sie an
App und Web-App weiter (`/v1/transit`). Eingerichtet wird nur hier.

## 9. PHP-Befehlsreferenz

```php
// Jetzt abrufen — auch der Knopf im Formular tut genau das
SDVT_Refresh(<InstanzID>);

// Alles, was die Kachel anzeigt, als JSON-Text
echo SDVT_GetBoard(<InstanzID>);

// Die Kachel neu zeichnen lassen und „jemand sieht hin" melden
IPS_RequestAction(<InstanzID>, 'GetState', 0);
```

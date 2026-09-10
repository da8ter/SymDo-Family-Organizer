# SymDo - Hausaufgaben

**Die Hausaufgaben der Kinder als eigene Kachel — dieselbe Liste wie in der
App, im Aufbau der Aufgabenliste.**

Was heute fällig ist, was überfällig ist und was erledigt wurde: für das
Tablett in der Küche oder im Flur, ohne dass jemand die App öffnen muss.
Eingetragen wird von Hand, und wenn die Schule ihre Hausaufgaben über WebUntis
stellt, holt das SymDo Gateway sie dazu.

## Inhalt

- **1. Was die Kachel zeigt**
- **2. Voraussetzungen**
- **3. Einrichtung**
- **4. Bedienung**
- **5. Was aus WebUntis kommt**
- **6. Änderungen an der Oberfläche**
- **7. PHP-Befehlsreferenz**

## 1. Was die Kachel zeigt

Oben die Kinder als Avatar-Leiste (ab zwei Kindern), darunter drei Zahlen —
**offen**, **überfällig**, **heute fällig**. Dann die Liste, gruppiert nach
Fälligkeit: *Überfällig*, *Heute*, *Morgen*, *Später*, *ohne Datum*. Jede Zeile
trägt ein Häkchen, das Symbol und die Farbe des Fachs, den Fachnamen, die Notiz
und den Fälligkeitstag; bei mehreren Kindern zusätzlich das Foto des Kindes.

Erledigtes rutscht nach unten in einen eingeklappten Abschnitt **Erledigt** —
es verschwindet nicht: ein Kind soll sehen, was es geschafft hat, und ein
falsch gesetztes Häkchen muss zurückgenommen werden können.

Die Farbe des Häkchens sagt, wer abgehakt hat: **orange**, wenn es hier
geschah, **Akzentfarbe**, wenn die Angabe aus WebUntis kam.

## 2. Voraussetzungen

| | |
|---|---|
| Symcon | ab 8.1 |
| **SymDo Gateway** | Pflicht — dort liegen die Hausaufgaben und die Familienmitglieder |
| Mindestens ein Familienmitglied mit der Rolle **Kind** | ohne Kind gibt es keine Hausaufgabe |
| **SymDo Stundenplan** (empfohlen) | liefert Symbol und Farbe der Fächer und die Fachliste beim Anlegen |

Ohne eingerichteten Stundenplan funktioniert die Kachel, aber die Fächer stehen
ohne Symbol da und beim Anlegen ist keine Fachliste zu wählen.

## 3. Einrichtung

1. Instanz **SymDo - Hausaufgaben** anlegen; als Eltern das vorhandene SymDo
   Gateway wählen (die Konsole schlägt es vor).
2. Fertig. Es gibt nichts einzustellen: die Aufgaben und die Mitglieder stehen
   im Gateway.

## 4. Bedienung

| Geste | Wirkung |
|---|---|
| Häkchen antippen | abhaken oder zurücknehmen |
| Zeile antippen | Blatt öffnen (ändern) |
| nach **links** wischen | löschen, ohne Rückfrage |
| nach **rechts** wischen | ändern |
| unten tippen und **+** | Blatt mit dem Getippten als Notiz öffnen |
| Avatar antippen | auf dieses Kind filtern, erneut antippen hebt es auf |

Das Plus legt nichts direkt an: eine Hausaufgabe braucht ein Fach, und das kann
eine Zeile Text nicht raten. Getippt wird also die Notiz, das Fach wählt man im
Blatt.

## 5. Was aus WebUntis kommt

Holt das Gateway die Hausaufgaben aus WebUntis, tragen diese Zeilen ein kleines
Schulzeichen. Sie werden **gezeigt, nicht bearbeitet**: kein Wischen, kein
Ändern, und was die Schule abgehakt hat, lässt sich hier nicht zurücknehmen.
Der Grund ist nicht Vorsicht, sondern Haltbarkeit — beim nächsten Abruf gewinnt
wieder die Fassung der Schule. Der Riegel selbst sitzt im Gateway.

Das Häkchen an einer **offenen** Aufgabe aus WebUntis geht dagegen: es gehört
dem Kind.

## 6. Änderungen an der Oberfläche

`module.html` ist die Web-App, wortgleich übernommen. Wer etwas ändern will,
ändert es in `SymDoWebApp/module.html` und übernimmt neu:

```
python3 SymDoHomework/tools/uebernahme-webapp.py
```

Das Skript kopiert `module.html` **und** `locale.json` und bricht ab, wenn eine
der Stellen fehlt, auf denen die Kachel steht. Von Hand wird hier nichts
nachgepflegt, sonst laufen die beiden Oberflächen auseinander.

## 7. PHP-Befehlsreferenz

```php
// Die Kachel neu zeichnen lassen
IPS_RequestAction(<InstanzID>, 'GetState', 0);
```

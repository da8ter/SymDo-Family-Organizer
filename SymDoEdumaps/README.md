# SymDo - Edumaps

**Die Klassenseiten der Schule als eigene Kachel — dieselben Karten wie in der
App, nur lesend.**

Viele Schulen führen ihre Klassenseiten bei Edumaps: eine Pinnwand mit Karten
für Elternbriefe, Materiallisten, AG-Wahlen und Termine. Das SymDo Gateway sieht
dort regelmäßig nach und spiegelt jede Karte mit ihrem Text und ihren Dateien.
Diese Kachel zeigt das Ergebnis — für das Tablett in der Küche, ohne dass jemand
die App öffnen muss.

Bis September 2026 lagen die Karten mit in den Notizen. Sie haben jetzt einen
eigenen Bestand, einen eigenen Bereich in der Web-App und diese Kachel.

## Inhalt

- **1. Was die Kachel zeigt**
- **2. Voraussetzungen**
- **3. Einrichtung**
- **4. Was hier möglich ist — und was nicht**
- **5. Änderungen an der Oberfläche**
- **6. PHP-Befehlsreferenz**

## 1. Was die Kachel zeigt

Links stehen die Kinder, je Kind ein Ordner mit seinem Foto; darunter seine
Seiten. Rechts stehen die Karten einer Seite, gruppiert nach den Bereichen der
Klassenseite und in ihrer Reihenfolge — mit dem Farbband des Bereichs, dem
Datum der letzten Änderung, Bildern im Kartenfuß und der Seitenvorschau eines
PDF. Eine buchbare Karte (AG-Wahl, Elternsprechtag) zeigt, wie voll es ist und
wo gebucht wird; gebucht wird auf der Schulseite, nicht hier.

Karten, die es auf der Seite nicht mehr gibt, wandern in einen eingeklappten
Abschnitt **Archiv** am Ende. Dort ist auch die einzige Stelle, an der sich eine
Karte endgültig wegwerfen lässt.

## 2. Voraussetzungen

| | |
|---|---|
| Symcon | ab 8.1 |
| **SymDo Gateway** | Pflicht — dort liegen die Karten, dort werden die Seiten eingetragen |
| Klassenseiten | im Gateway unter *Schule → Klassenseiten (Edumaps)* eingeschaltet, mit mindestens einer Seite |

## 3. Einrichtung

1. Instanz **SymDo - Edumaps** anlegen; als Eltern das vorhandene SymDo
   Gateway wählen (die Konsole schlägt es vor).
2. Fertig. Es gibt nichts einzustellen: die Seiten stehen im Gateway.

Zeigt die Kachel nichts, fehlt eine der Voraussetzungen aus Kapitel 2. Die
Statuszeile im Gateway-Formular nennt nach jedem Durchlauf, wie viele Karten
gefunden wurden.

## 4. Was hier möglich ist — und was nicht

Der Bestand ist ein **Spiegel**. Deshalb:

- **Umbenennen** einer Seite geht. Wiedererkannt wird sie an ihrer Adresse,
  nicht am Namen — der gehört dir.
- **Löschen** einer Seite geht und **sperrt** sie zugleich. Ohne die Sperre
  legte der nächste Durchlauf denselben Ordner kommentarlos neu an; die Sperre
  lässt sich im Gateway-Formular wieder aufheben.
- **Eine archivierte Karte** lässt sich endgültig wegwerfen.
- **Anlegen, Schreiben, Dateien hochladen** gibt es nicht. Was hier von Hand
  entstünde, wäre beim nächsten Durchlauf entweder weg oder archiviert.

## 5. Änderungen an der Oberfläche

`module.html` ist die Web-App, wortgleich übernommen. Wer etwas ändern will,
ändert es in `SymDoWebApp/module.html` und übernimmt neu:

```
python3 SymDoEdumaps/tools/uebernahme-webapp.py
```

Das Skript kopiert `module.html` **und** `locale.json` und bricht ab, wenn eine
der Stellen fehlt, auf denen die Kachel steht. Von Hand wird hier nichts
nachgepflegt, sonst laufen die beiden Oberflächen auseinander.

## 6. PHP-Befehlsreferenz

```php
// Die Kachel neu zeichnen lassen (das Gateway tut das nach jedem Durchlauf selbst)
IPS_RequestAction(<InstanzID>, 'GetState', 0);
```

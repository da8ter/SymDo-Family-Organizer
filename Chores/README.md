# SymDo - Ämtchenplan

**Haushaltsaufgaben, die wochenweise zwischen den Familienmitgliedern wechseln — als Kachel für die Tile-Visualisierung.**

Wer bringt diese Woche den Müll raus, wer räumt den Tisch ab, wer gießt die Blumen? Die Kachel beantwortet genau diese Frage: eine Tabelle aus Ämtchen und Wochentagen, in jeder Zelle das Gesicht dessen, der dran ist, und ein Häkchen je Erledigung.

Das Gegenstück für den Tag ist **SymDo - Routinen**: Häkchenlisten, die sich abends zurücksetzen. Hier geht es um die Woche und um die Reihe, wer dran ist.

## Inhalt

- **1. Was die Kachel zeigt**
- **2. Voraussetzungen**
- **3. Einrichtung**
- **4. Wie die Rotation rechnet**
- **5. Statusvariablen**
- **6. PHP-Befehlsreferenz**

## 1. Was die Kachel zeigt

Oben die Woche mit einem Schritt zurück und einem nach vorn, darunter der Fortschritt der Woche. Dann je Person ein Block: Foto, Name, „2 von 3" und das Guthaben, darunter ihre Ämtchen mit Symbol, Punktwert und den Häkchen.

- **Eine Spalte je Wochentag.** Oben stehen die Wochentage als Überschrift, und jedes Häkchen sitzt in der Spalte des Tages, an dem das Ämtchen dran ist. Der heutige Tag ist hervorgehoben.
- **Was noch nicht dran war, lässt sich nicht abhaken.** Ein Tag, der noch aussteht, zeigt seinen Kreis blass und gepunktet. Nachtragen geht: vergangene Tage dieser Woche bleiben offen.
- **Ein Häkchen je Erledigung.** Ein Ämtchen, das zweimal in der Woche dran ist, hat zwei Kreise — an zwei Tagen.
- **Gestrichelter Kreis**: ein Platz, der aus der Vorwoche übrig ist (nur mit *Übertrag*, Kapitel 3).
- **Vorschau**: die nächste Woche zeigt, wer dann dran ist — ohne Häkchen, denn abgehakt wird ab Montag.
- **Rückblick**: die letzte Woche als Bilanz je Person.
- **Pause**: wer aussetzt, steht blass mit dem Hinweis da. Das erklärt, warum jemand anders zwei Ämtchen trägt.

## 2. Voraussetzungen

- Symcon ab Version **8.1**
- Eine **SymDo - Gateway**-Instanz für die Familienmitglieder mit Foto. Ohne Gateway funktioniert der Plan, nur ohne Namen und Bilder

## 3. Einrichtung

1. Instanz **SymDo - Ämtchenplan** anlegen und in der Kachel-Visualisierung einbinden. Die Konsole schlägt beim Anlegen ein vorhandenes Gateway als übergeordnete Instanz vor
2. **Teilnehmer** eintragen, in der Reihenfolge, in der rotiert werden soll. *Last* 2 heißt doppelt so viele Ämtchen, *Pause* lässt jemanden aussetzen
3. **Ämtchen** eintragen: Symbol, Name, **an welchen Wochentagen** (ein Haken je Tag), und wer in Frage kommt — alle Teilnehmer, nur die Kinder, nur die Erwachsenen oder eine feste Person
4. Unter *Verhalten* den ersten Wochentag und die **Uhrzeit des Wochenwechsels** wählen (Vorgabe Montag 3:00). Der Sonntagabend gehört noch zur alten Woche
5. Unter *Anzeige* wählen, welche Kästen die Kachel zeigt

| Einstellung | Bedeutung |
|---|---|
| Teilnehmer | Reihenfolge = Reihenfolge der Rotation. *Pause* verschiebt die Reihe der anderen **nicht**, eine Zeile zu löschen schon |
| Ämtchen | Reihenfolge entscheidet, wer welches Ämtchen zuerst bekommt. Umsortieren wirkt ab der nächsten Woche. **Die sieben Haken sagen, an welchen Tagen es dran ist** — wie oft in der Woche ergibt sich daraus |
| Woche beginnt am | Montag bis Sonntag |
| Wochenwechsel um | Uhrzeit, zu der die neue Woche beginnt |
| Übertrag | Nicht erledigte Ämtchen wandern als zusätzliche Plätze in die neue Woche, beim **alten** Zuständigen. Höchstens eine Woche tief |
| Anzeige | Welche Kästen die Kachel zeigt: Übersichtskarten, Gesamtfortschritt, nächste Ämtchen, Schlusswort |

## 4. Wie die Rotation rechnet

Die Zuweisung einer Woche wird **gerechnet** und dann **eingefroren**.

Gerechnet heißt: aus der laufenden Nummer der Woche seit einem festen Bezugspunkt. Damit ist jede Woche der Vergangenheit und Zukunft bestimmbar, und nichts driftet — ein verpasster Wochenwechsel, weil die Box aus war, überspringt keine Runde, und eine Rücksicherung springt nicht zurück.

Eingefroren heißt: die Zuweisung der laufenden Woche steht fest, sobald sie einmal gerechnet wurde. Wer mitten in der Woche einen Teilnehmer hinzufügt, verteilt damit nicht die laufende Woche neu — gesetzte Häkchen hängen sonst plötzlich an der falschen Person. Wer allerdings gar nicht mehr in den Kreis seines Ämtchens gehört — etwa, weil die Spalte *Wer* mitten in der Woche auf „Nur Kinder“ gestellt wurde —, wird sofort ersetzt. Ein mitten in der Woche angelegtes Ämtchen bekommt trotzdem sofort einen Zuständigen.

**Pause** überspringt eine Person, nimmt sie aber nicht aus dem Kreis: nach dem Urlaub geht die Reihe dort weiter, wo sie war. Eine Zeile zu **löschen** verändert die Kreisgröße und damit die Reihe aller anderen — das ist der Unterschied zwischen Aussetzen und Aufhören.

**Last** verteilt ungleich, und zwar verzahnt: bei Last 2 für Anna und je 1 für Ben und Clara lautet der Kreis Anna, Ben, Clara, Anna. So bekommt Anna in einer Woche nicht beide Ämtchen, sondern ist über die Wochen doppelt so oft dran.

Über einen vollen Umlauf des Kreises geht die Verteilung genau auf. Innerhalb eines angebrochenen Umlaufs kann sie um bis zu die Zahl der Ämtchen abweichen — das ist Arithmetik und kein Fehler.

Wer die Reihe von Hand verschieben will, nimmt den Knopf **Eine Person weiterdrehen**. Er wirkt ab der nächsten Woche.

## 5. Statusvariablen

| Ident | Typ | Inhalt |
|---|---|---|
| `PROGRESS` | Integer | Wie viel der Woche geschafft ist, in Prozent |
| `DONE_<Ämtchen>` | Boolean | Ob dieses Ämtchen die Woche über erledigt ist |
| `WHO_<Ämtchen>` | String | Wer diese Woche dran ist |

Die Variablen sind zum **Lesen** da, etwa für eine Ansage oder eine Automation. Abgehakt wird über die Kachel — dort hängt die Sperre für Tage, die noch nicht dran waren.

## 6. PHP-Befehlsreferenz

```php
CHR_GetState(int $InstanzID): string          // der Zustand der Kachel als JSON
CHR_Rotate(int $InstanzID, int $Personen): string   // Reihe verschieben, ab nächster Woche
CHR_Preview(int $InstanzID, int $Wochen): string    // Vorschau als Text
```

## Prüflauf

Die Rotation läuft ohne Symcon unter Prüfstand:

```
php Chores/tests/ChoresRotationTest.php
```

110 Zusicherungen: Wochengrenzen über beide Zeitumstellungen und über einen Jahreswechsel, Rotation mit Pause und ungleicher Last, täglicher Wechsel, Wochenwechsel, Übertrag und Häkchen.

# Stundenplan

Der Wochenplan der Kinder als Symcon-Kachel: Fächer, Zeiten, Betreuung, Ferien.
Eingerichtet wird alles im Backend der Instanz — es gibt keine zweite Ablage und
keine App, in der man den Plan pflegen müsste.

Zwei Darstellungen aus denselben Daten:

- **Wochenraster** — feste Zeitachse links, daneben die Tagesspalten. Jede
  Stunde eine farbige Karte mit Symbol, Fach und Zeitspanne; Lücken als graue
  „frei"-Blöcke; der heutige Tag mit Kapsel, die laufende oder nächste Stunde
  mit hellem Rand.
- **Timeline** — ein Balken je Kind mit den heutigen Stunden, anteilig zur
  Wochenspanne, darunter ein 15-Minuten-Raster. Ein Klick auf die Kachel klappt
  eine **Legende** der heute sichtbaren Fächer auf: Farbe, Symbol und Name, und
  wenn ein Fach nur bei einem Kind vorkommt, auch dessen Name. Im Balken steht
  nur ein Symbol — auf einem Tablet gibt es keinen Mauszeiger, der es verrät.

## Einrichtung

1. **Kinder** anlegen: Name und Farbe. Ist ein SymDo-Gateway gewählt, lässt sich
   jedes Kind einem Familienmitglied zuordnen — dann trägt die Karte in der App
   denselben Namen.
2. **Fächer** pflegen: Name, **Symcon-Symbol** und Farbe. Neun gängige Fächer
   sind vorbelegt. Der Knopf *Fächer aus den Stunden ergänzen* trägt nach, was
   in den Stunden vorkommt, aber noch fehlt — mit einem Vorschlag, den Sie
   danach ändern können.
3. **Stunden** eintragen: Kind, Tag, Fach, Von, Bis. Die Farbe bleibt leer, dann
   gilt die des Fachs.
4. **Betreuung** (falls vorhanden): je Wochentag eine Endzeit.
5. **Ferien**: Quelle wählen und einmal abrufen.
6. **Anzeige**: Wochenraster oder Timeline.

Die Statuszeile unten meldet Überschneidungen und Stunden, deren Kind oder Fach
es nicht (mehr) gibt.

## Beide Ansichten gleichzeitig

Eine zweite Instanz dieses Moduls anlegen, auf **Timeline** stellen und die
erste Instanz als **Datenquelle** wählen. Sie zeigt denselben Plan in der
anderen Form — der Plan wird nur einmal gepflegt.

## Ferien und Feiertage

| Quelle | Zustand |
|---|---|
| **OpenHolidaysAPI** | Kostenlos, ohne Konto und ohne Schlüssel. Bundesland im Formular wählen. Geprüft. |
| **Almanac** (Wilkware) | **Ungeprüft** — siehe unten. |
| **Keine** | Der Plan gilt immer. |

Abgerufen wird einmal täglich, dazu auf Knopfdruck. Ein misslungener Abruf lässt
den abgelegten Stand stehen, statt ihn zu leeren: ein Netzfehler darf keinen
Schultag zum Ferientag machen und umgekehrt.

> **Zum Almanac-Anschluss:** Das Modul *Jahreskalender (Almanac)* ist auf dem
> Entwicklungssystem nicht installiert. Der Adapter entstand deshalb nach der
> Dokumentation und **nicht** gegen die echte Schnittstelle. Er ist so gebaut,
> dass ein Fehlschlag folgenlos bleibt — antwortet keine der erwarteten
> Funktionen, kennt der Plan einfach keine Ferien. Sobald das Modul installiert
> ist, wird der Adapter gegen das echte nachgezogen.

## Grenzen

- Der Plan ist eine **Wochenvorlage, kein Kalender**: Vertretung, Ausfall und
  einzelne Verschiebungen kennt er nicht.
- In den Ferien blendet er den Unterricht aus, zeigt aber **keine
  Betreuungszeiten** — wer in den Ferien Hort hat, sieht ihn hier nicht.
- Der 14-tägliche Samstag folgt der **Parität der ISO-Kalenderwoche**, nicht
  einem strikten 14-Tage-Rhythmus. In Jahren mit 53 Wochen wiederholt sich die
  Parität deshalb am Jahreswechsel — genau wie im Schulaushang.
- Kinder und Fächer werden über ihren **Namen** verknüpft. Wer ein Kind oder
  Fach umbenennt, löst die Zuordnung der zugehörigen Stunden; die Statuszeile
  meldet das, damit es nicht still im Raster fehlt.

## Öffentliche Funktionen

```php
STPL_GetPlan(int $InstanzID): string        // der fertige Plan als JSON
STPL_FetchHolidays(int $InstanzID): string  // Ferien abrufen, Meldung zurück
STPL_Refresh(int $InstanzID): void          // Anzeige nachziehen (Timer)
```

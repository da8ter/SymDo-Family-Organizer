# Stundenplan

Der Wochenplan der Kinder als Symcon-Kachel: Fächer, Zeiten, Betreuung, Ferien.
Eingerichtet wird alles im Backend der Instanz — es gibt keine zweite Ablage und
keine App, in der man den Plan pflegen müsste.

Zwei Darstellungen aus denselben Daten:

- **Wochenraster** — feste Zeitachse links, daneben die Tagesspalten. Jede
  Stunde eine farbige Karte mit Symbol, Fach und Zeitspanne; Lücken als graue
  „frei"-Blöcke; der heutige Tag mit Kapsel, die laufende oder nächste Stunde
  mit hellem Rand. Jede Stunde sitzt **auf ihrer Uhrzeit**, nicht auf der
  vorigen — sie steht damit genau auf ihrer Marke in der Zeitachse. Alle Tage
  sind **gleich breit** und teilen sich die Breite neben der Zeitachse; ihre
  Mindestbreite ist „das Wort *Mathematik* passt neben den Symbolkreis",
  gemessen an der tatsächlich verwendeten Schrift. Ist die Kachel breiter,
  werden die Spalten breiter; ist sie schmaler, scrollt das Raster waagerecht.
  Fachnamen, die trotzdem nicht hineinpassen, enden mit „…".
- **Timeline** — ein Balken je Kind, anteilig zur Wochenspanne, darunter ein
  15-Minuten-Raster. Ein Strich in der Akzentfarbe markiert die **aktuelle
  Zeit** — nur am heutigen Tag und nur, solange sie in der Wochenspanne liegt;
  danach verschwindet er, statt am Rand zu kleben. Er läuft alle 30 Sekunden
  weiter, gerechnet ab der Uhrzeit des Servers, nicht der des Betrachters.
  Oben rechts ein Umschalter (‹ Wochentag ›), der durch die
  Woche blättert; er startet auf heute und hebt den heutigen Tag farbig hervor.
  Seine Breite richtet sich nach dem längsten Wochentagsnamen, damit die Pfeile
  beim Blättern nicht wandern. Ein Klick auf die Kachel klappt
  eine **Legende** der heute sichtbaren Fächer auf: Farbe, Symbol und Name, und
  wenn ein Fach nur bei einem Kind vorkommt, auch dessen Name. Im Balken steht
  nur ein Symbol — auf einem Tablet gibt es keinen Mauszeiger, der es verrät.
  Dieselbe Legende, derselbe Umschalter **und derselbe Jetzt-Strich** stecken
  in der Karte der SymDo-App: ein Tippen klappt die Fächer auf, die Pfeile
  blättern durch die Woche.

Beide Ansichten halten sich an die **Ränder, die für die Kachel eingestellt
sind** (oben, seitlich, unten) — sie werden aus der Adresse gelesen und selbst
aufgetragen, statt zu einem festen Innenabstand hinzuzukommen. Gescrollt wird
in der Kachel selbst, mit demselben schmalen Balken in der Akzentfarbe wie in
der ToDo-Kachel; sein Streifen ist immer reserviert, damit der rechte Abstand
nicht springt, sobald der Balken auftaucht. Das Wochenraster ist dabei eine
begrenzte Fläche und scrollt in sich — sonst säße sein waagerechter Balken ganz
unten am Inhalt statt an der Unterkante der Kachel.

Die Kachel behält die Geste außerdem **bei sich**: Symcons Kachel-SDK meldet
jedes Rad- und Wischereignis an die Visualisierung, die daraufhin mitscrollt.
Dagegen hilft kein CSS, deshalb werden die Ereignisse abgefangen, bevor die
Meldung entsteht.

## Einrichtung

1. **Kinder** anlegen: Name und Farbe. Ist ein SymDo-Gateway gewählt, lässt sich
   jedes Kind einem Familienmitglied zuordnen — dann trägt die Karte in der App
   denselben Namen und zeigt das Foto. Höchstens **sechs** Kinder können Stunden
   bekommen; die Zahl steht im Code, weil Symcon Eigenschaften nur fest
   registriert.
2. **Fächer** pflegen: Name, **Symcon-Symbol** und Farbe. Neun gängige Fächer
   sind vorbelegt. Der Knopf *Fächer aus den Stunden ergänzen* trägt nach, was
   in den Stunden vorkommt, aber noch fehlt — mit einem Vorschlag, den Sie
   danach ändern können.
3. **Stunden** eintragen: Der Bereich zeigt je Kind einen ausklappbaren
   Abschnitt, darin nebeneinander eine schmale Liste je Wochentag — Fach, Von,
   Bis. Von und Bis sind **Zeitwähler**; die Sekunden, die der Wähler anbietet
   und die Zelle anzeigt, werden ignoriert (das Format der Zelle steckt fest in
   der Konsole). Der Samstag erscheint nur bei Kindern, für die er eingeschaltet
   ist. Die Farbe kommt immer vom Fach; eine Ausnahmefarbe je Stunde gibt es
   nicht.
4. **Betreuung**: unter jeder Tagesliste ein Schalter und daneben die Endzeit —
   dort, wo der Tag ohnehin gepflegt wird. Ein grauer Block läuft dann vom Ende
   des Unterrichts bis zu dieser Zeit, aber nur an Tagen mit Unterricht, und er
   zählt nicht als Unterrichtszeit.
5. **Ferien**: Quelle wählen und einmal abrufen.
6. **Anzeige**: Wochenraster oder Timeline.

Ob die **App** den Plan zeigt, wird nicht hier eingestellt, sondern im
SymDo-Gateway unter *Stundenplan* — zusammen mit dem Übrigen, was die App
anzeigt. Der Schalter stand bis zuletzt in jeder Stundenplan-Instanz; wer die
Karte suchte, suchte sie aber im SymDo-Backend.

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
- **Fächer** werden über ihren Namen verknüpft. Wer ein Fach umbenennt, löst die
  Zuordnung der zugehörigen Stunden; die Statuszeile meldet das, damit es nicht
  still im Raster fehlt.
- Stunden **und Betreuung** hängen an der **Position** des Kindes in der
  Kinderliste. Beim Umsortieren oder Löschen wandern sie gemeinsam mit — das
  Modul gleicht das beim Übernehmen ab und meldet, was verschoben oder geleert
  wurde.

## Öffentliche Funktionen

```php
STPL_GetPlan(int $InstanzID): string        // der fertige Plan als JSON
STPL_FetchHolidays(int $InstanzID): string  // Ferien abrufen, Meldung zurück
STPL_Refresh(int $InstanzID): void          // Anzeige nachziehen (Timer)
```

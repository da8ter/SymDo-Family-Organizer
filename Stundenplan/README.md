# SymDo Stundenplan

Der Wochenplan der Kinder als Symcon-Kachel: Fächer, Zeiten, Betreuung, Ferien.
Eingerichtet wird alles im Backend der Instanz — es gibt keine zweite Ablage und
keine App, in der man den Plan pflegen müsste.

Zwei Darstellungen aus denselben Daten:

- **Wochenraster** — feste Zeitachse links, daneben die Tagesspalten. Jede
  Stunde eine farbige Karte mit Symbol, Fach und Zeitspanne; Lücken als graue
  „frei"-Blöcke; der heutige Tag mit Kapsel, die laufende oder nächste Stunde
  mit hellem Rand — eine eigene Zeile „Als nächstes …" braucht es dafür nicht,
  und eine Überschrift auch nicht: beide nähmen dem Plan nur Höhe weg. Die
  Heute-Kapsel und der gewählte Kinder-Chip tragen die **Akzentfarbe der
  Visualisierung**; sie bezeichnen eine Auswahl, und die sieht überall gleich
  aus. Jede Stunde sitzt **auf ihrer Uhrzeit**, nicht auf der
  vorigen — sie steht damit genau auf ihrer Marke in der Zeitachse. Alle Tage
  sind **gleich breit** und teilen sich die Breite neben der Zeitachse; ihre
  Mindestbreite ist „das Wort *Mathematik* passt neben den Symbolkreis",
  gemessen an der tatsächlich verwendeten Schrift. Ist die Kachel breiter,
  werden die Spalten breiter; ist sie schmaler, scrollt das Raster waagerecht.
  Fachnamen, die trotzdem nicht hineinpassen, enden mit „…".
- **Timeline** — ein Balken je Kind, anteilig zur Wochenspanne, darunter ein
  15-Minuten-Raster. An Tagen ohne Schule wird der Balken **ganz grau** und
  trägt mittig den Hinweis — mit dem Namen aus der Ferienquelle und dem
  Enddatum („Sommerferien bis 05.09."), bei einem Feiertag nur dessen Namen, denn
  der dauert einen Tag. Ein Band über dem Plan gibt es dort nicht: es wäre
  dieselbe Auskunft ein zweites Mal. Gerechnet wird **je Wochentag**, nicht für
  heute — sonst wäre der Donnerstag grau, nur weil heute ein Feiertag ist. Ein Strich in der Akzentfarbe markiert die **aktuelle
  Zeit** — nur am heutigen Tag und nur, solange sie in der Wochenspanne liegt;
  danach verschwindet er, statt am Rand zu kleben. Er läuft alle 30 Sekunden
  weiter, gerechnet ab der Uhrzeit des Servers, nicht der des Betrachters.
  Oben rechts ein Umschalter (‹ Wochentag ›), der durch die
  Woche blättert; er startet auf heute und hebt den heutigen Tag farbig hervor.
  Seine Breite richtet sich nach dem längsten Wochentagsnamen, damit die Pfeile
  beim Blättern nicht wandern. Ein Tipp auf die Kachel öffnet eine **Legende**
  der sichtbaren Fächer — als Blatt **über** dem Plan, nicht darunter angehängt:
  unten angehängt schob sie die Balken aus der Kachel. Sie nennt Farbe, Symbol
  und Name, und wenn ein Fach nur bei einem Kind vorkommt, auch dessen Namen.
  Ein zweiter Tipp schließt sie wieder — auf das Blatt, auf ein Fach, daneben
  oder auf das ✕, alles schließt. Im Balken steht nur ein Symbol — auf einem Tablet gibt es keinen
  Mauszeiger, der es verrät.
  Dieselbe Legende, derselbe Umschalter, derselbe Jetzt-Strich **und derselbe
  graue Ferienbalken** stecken in der Karte der SymDo-App; dort deckt das Blatt
  die Karte ab statt den ganzen Schirm. Beim **Betreten des Dashboards** steht
  die Karte wieder auf heute — die Tageswahl bliebe sonst stehen, wo man sie
  zuletzt gelassen hat, und wer abends bis Freitag geblättert hatte, sähe am
  nächsten Morgen den Freitag. Das Blättern selbst bleibt erhalten, solange man
  auf dem Dashboard ist.

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

1. **Kinder** anlegen: Name und Farbe. Jedes Kind lässt sich einem
   **Familienmitglied** aus SymDo zuordnen — dann trägt die Karte in der App
   denselben Namen, und Kachel wie App zeigen das Foto statt des
   Anfangsbuchstabens. Das Gateway wird von selbst gefunden; die Auswahl unter
   *Anzeige* ist nur nötig, wenn mehrere in Frage kommen. Steht die Spalte
   *Familienmitglied* nur auf „— keins —", fehlt der Bezug zum Gateway — der
   Hinweis unter der Auswahl sagt dann, welches genommen wird. Höchstens **sechs** Kinder können Stunden
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

Ob die **App** den Plan zeigt, wird nicht hier eingestellt, sondern in der
**SymDo Web App** unter *Sichtbare Bereiche* — zusammen mit dem Übrigen, was die
App anzeigt. Dort steht eine Zeile je Instanz mit einem Häkchen; eine neu
angelegte Instanz ist zunächst **aus**, damit sie den Stundenplan der Kinder
nicht ungefragt auf jedes gekoppelte Gerät schiebt.

> **Zwei Instanzen mit denselben Kindern** stellen jedes Kind **doppelt** in die
> App — die Zeile in der Web App nennt deshalb die Kinder je Instanz. Wer beide
> Ansichten *eines* Plans will, gibt der zweiten Instanz die erste als
> **Datenquelle** (siehe unten); dann taucht sie in der Liste gar nicht auf und
> der Plan wird nur einmal gepflegt.

Die Statuszeile unten meldet Überschneidungen und Stunden, deren Kind oder Fach
es nicht (mehr) gibt.

## Beide Ansichten gleichzeitig

Eine zweite Instanz dieses Moduls anlegen, unter *Anzeige* auf **Timeline**
stellen und oben im Bereich *Stunden* die erste Instanz als **Datenquelle**
wählen. Sie zeigt denselben Plan in der anderen Form — der Plan wird nur einmal
gepflegt.

## Ferien und Feiertage

| Quelle | Zustand |
|---|---|
| **OpenHolidaysAPI** | Kostenlos, ohne Konto und ohne Schlüssel. Bundesland im Formular wählen. Geprüft. |
| **Almanac** (Wilkware) | **Ungeprüft** — siehe unten. |
| **Keine** | Der Plan gilt immer. Ein bereits abgerufener Stand bleibt gespeichert, **wirkt aber nicht**: kein Band, kein grauer Ferienbalken, keine Ferienzeile im Briefing. Er greift wieder, sobald eine Quelle gewählt ist. |

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

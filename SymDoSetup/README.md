# SymDo - Installationsassistent

Führt durch die Ersteinrichtung: Familienmitglieder anlegen, die gewünschten
Bausteine als Instanzen erzeugen, Zugang und Schule vorbereiten — und setzt
alles **in einem Zug** um.

Das Modul ist eine **Erkennung** (Discovery, `type: 5`): es ist kein Gerät und
braucht kein Gateway als Eltern, denn es richtet das Gateway erst ein.

## Ablauf

Der Knopf *SymDo-Installationsassistenten starten* öffnet acht Seiten:

1. **Willkommen bei SymDo** — was SymDo mitbringt, und was auf diesem Server
   schon steht.
2. **Deine Familie** — die vorhandenen Mitglieder stehen oben beim Namen.
   Neue trägt man eines nach dem anderen ein, mit
   **allen** Feldern der Mitgliederliste: Vorname, Nachname, Geburtsdatum,
   Rolle, Foto und Push-Visualisierung. Wer schon im Gateway steht, bleibt
   unverändert; leere Felder werden ergänzt.
3. **Was möchtest du nutzen?** — ein Kästchen je Baustein. Was es schon gibt,
   ist **angehakt und gesperrt** (der Assistent legt an, er räumt nicht ab).
   Wählt man einen Baustein, kommen seine **Voraussetzungen automatisch mit** —
   mit einem Hinweis darunter, welche das waren.
4. **Dein Zugang** — der Zugang für die SymDo Web-App; er entsteht am Ende und
   steht als Code im Bericht.
5. **Schule** — WebUntis oder LOGINEO, mit Adresse, Benutzername und Kennwort,
   und für welches Kind.
6. **Kluge Helfer** — KI-Anbieter, Schlüssel, der vollständige
   Datenschutzhinweis und die Zustimmung.
7. **Alles bereit?** — eine Vorschau dessen, was gleich entsteht.
8. **Fertig** — der Bericht, Zeile für Zeile.

Der Bericht steht danach auch im Hauptformular und überlebt einen Neustart.

## Abhängigkeiten

Wer einen Baustein wählt, bekommt seine Voraussetzungen mit:

| Baustein | braucht |
|---|---|
| Hausaufgaben | Stundenplan |
| Essensplan | Einkaufsliste |
| Ämtchenplan | Routinen |
| Sprachassistent | ToDo-Liste und Einkaufsliste |
| WebUntis | Stundenplan, Hausaufgaben |
| LOGINEO | Klassenseiten, Hausaufgaben (und über sie den Stundenplan) |

## Was der Assistent nicht tut

- **Kein zweites Gateway.** Findet er zwei, hält er an, bevor er etwas
  schreibt: welches die App bedient, entscheidet die *niedrigste* Instanz-ID,
  und Symcon vergibt IDs zufällig.
- **Nichts löschen.** Mitglieder werden nur ergänzt, Instanzen nur angelegt.
  Eine Wahl, die schon getroffen ist, wird nie überschrieben.

Bei **WebUntis** bleibt genau ein Schritt beim Nutzer: im Gateway die Schüler
abrufen und das Kind auswählen — diese Wahl verlangt WebUntis. Bei **LOGINEO**
tauscht der Assistent das Kennwort selbst gegen einen Token; gespeichert wird
nur der Token. Kennwörter liegen währenddessen im Arbeitsspeicher der Instanz
(Puffer) und werden nach dem Lauf geleert.

## Reihenfolge des Laufs

Sie ist nicht Geschmack, sondern erzwungen:

1. **Gateway** — anlegen oder übernehmen. Scheitert es, bricht der ganze Zug
   ab: eine Kachel, die ihr erstes *Übernehmen* ohne Gateway durchläuft,
   verbrennt dabei ihr einmaliges Verbindungsrecht und bleibt elternlos.
2. **Mitglieder** und Stammdaten, dann **ein** Übernehmen am Gateway — in
   diesem Lauf vergibt das Gateway die Kennungen und legt die
   Mitglieder-Ordner an.
3. **Kacheln**: Einkaufsliste → Aufgaben → Routinen → Essensplan → Ämtchen →
   Stundenplan → Notizen, Hausaufgaben, Klassenseiten → Sprache → Web-App.
   Jede wird selbst mit dem Gateway verbunden, dann bekommt sie ihre
   Querverweise, dann ihr Übernehmen.
4. **Zugang** zuletzt: der Code gilt zehn Minuten und ersetzt eine offene
   Kopplung.

Scheitert ein Schritt ab Stufe 3, laufen die anderen weiter — der Bericht sagt,
welcher nicht. Eine Instanz, deren Erzeugung mitten drin scheitert, wird samt
ihrer Unterobjekte wieder weggeräumt; bleibt etwas übrig, steht auch das im
Bericht.

## Symcon 9.1 und darunter

Die geführten Seiten (`popup.pages`) gibt es ab **Symcon 9.1**. Unter 9.1 zeigt
derselbe Knopf dieselben Felder als Aufklapp-Panels mit einem
*Jetzt einrichten*-Knopf. Die Bibliothek bleibt deshalb bei
`compatibility 8.1`.

## Für Entwickler

Die Entscheidungslogik steht in `libs/SetupPlan.php` — ohne Symcon, damit sie
prüfbar ist (`tests/SetupPlanTest.php`, 66 Zusicherungen). Der Ausführer hängt
an keiner Stelle an `pages`; er nimmt ein Antwort-JSON:

```php
SDSU_Apply($id, '{"familyName":"…","members":[{"name":"Tim","persona":"child"}],'
              . '"bausteine":{"todo":true},"access":false,"school":"none"}');
```

Ohne Parameter nimmt er die im Assistenten gesammelten Antworten. Rückgabe ist
der Bericht als Text. Genau so ist der Einmal-Zug prüfbar, ohne das Formular zu
bedienen.

# SymDo - Installationsassistent

Führt durch die Ersteinrichtung: Familienmitglieder anlegen, die gewünschten
Bausteine als Instanzen erzeugen, Zugang und Schule vorbereiten — und setzt
alles **in einem Zug** um.

Das Modul ist eine **Erkennung** (Discovery, `type: 5`): es ist kein Gerät und
braucht kein Gateway als Eltern, denn es richtet das Gateway erst ein.

## Ablauf

Der Knopf *SymDo-Installationsassistenten starten* öffnet sieben Seiten:

1. **Willkommen** — was schon da ist und was fehlt. Bis zur letzten Seite wird
   nichts geschrieben.
2. **Familie** — ein Mitglied eintragen, *Mitglied übernehmen* drücken,
   nächstes. Wer im Gateway schon steht, bleibt unverändert; leere Felder werden
   ergänzt.
3. **Was möchtest du nutzen?** — ein Kästchen je Baustein. Angelegt wird nur,
   was fehlt.
4. **Zugänge** — auf Wunsch ein Browser-Zugang, erzeugt als letzter Schritt.
5. **Schule und KI** — Schulsystem und KI-Anbieter samt Schlüssel.
6. **Bereit** — eine Vorschau dessen, was gleich passiert.
7. **Fertig** — der Bericht, Zeile für Zeile.

Der Bericht steht danach auch im Hauptformular und überlebt einen Neustart.

## Was der Assistent nicht tut

- **Kein zweites Gateway.** Findet er zwei, hält er an, bevor er etwas
  schreibt: welches die App bedient, entscheidet die *niedrigste* Instanz-ID,
  und Symcon vergibt IDs zufällig. Er würde ins falsche schreiben, während die
  Kacheln aus dem anderen lesen.
- **Keine Schul-Zugangsdaten.** WebUntis braucht nach dem Login eine
  Schülerauswahl, LOGINEO tauscht das Kennwort über einen Knopf gegen einen
  Token — beides braucht eine menschliche Entscheidung und bleibt im Gateway.
- **Keine KI-Einwilligung.** Anbieter und Schlüssel setzt er, den Schalter
  lässt er aus: die Einwilligung gehört dorthin, wo ihr Text steht.
- **Nichts löschen.** Mitglieder werden nur ergänzt, Instanzen nur angelegt.
  Eine Wahl, die schon getroffen ist, wird nie überschrieben.
- **Keinen Geburtstag.** Ein Datumsfeld lässt sich nicht als Feldwert an ein
  Skript geben; der Geburtstag gehört in die Mitgliederliste des Gateways.

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

# Instanzen, Nebenläufigkeit und Datenfluss

## Symcon arbeitet Aufrufe je Instanz nacheinander ab

- **Was:** Timer, Hooks, `RequestAction` und Präfix-Aufrufe derselben Instanz laufen hintereinander. Ein Timer, der lange rechnet, lässt jede Hook-Anfrage warten (gemessen 02.09.2026, Symcon 9.0: 4 s Arbeit alle 5 s machten die Web-App praktisch unbenutzbar).
- **Folge für Module:** Ein Gateway mit Hooks darf keine schwere Dauerarbeit in eigenen Timern tragen. `IPS_ApplyChanges` auf eine fremde Instanz wartet auf deren Spur.
- **Muster:**
  - Hintergrundarbeit in kurzen Häppchen mit viel Luft (danach gemessen: 2 s alle 15 s, Hooks antworten in 0–20 ms).
  - Ganz aussetzen, solange etwas Zeitkritisches läuft.
  - Schwere oder lange Aufträge in eigene Instanzen auslagern (in dieser Bibliothek: `SymDoScanner`), die über einen Kanal und ein Signal angestoßen werden.

## Rückruf in dieselbe Instanz läuft auf einem anderen PHP-Objekt

- **Was:** Kette `A::RequestAction → IPS_RequestAction(B) → B ruft IPS_RequestAction(A)`: Der Rückruf kommt synchron an, läuft aber auf einem **anderen PHP-Objekt** der Instanz A. Ein dort gesetztes Objektfeld (`$this->foo`) ist im äußeren Aufruf danach nicht sichtbar (gemessen 30.08.2026, Symcon 9.0).
- **Folge für Module:** Objektfelder taugen nicht als Rückkanal zwischen verschachtelten Aufrufen. Auch zwischen Aktions- und Timer-Durchläufen gibt es keinen gemeinsamen Objektzustand.
- **Muster: Attribut-Briefkasten.** Attribute liegen kernelseitig je Instanz und queren Objekt- und Thread-Grenzen zuverlässig. Vor dem Aufruf leeren, im Rückruf schreiben, danach lesen. Große Nutzlasten im Rückruf selbst verarbeiten und nur ein kompaktes Ergebnis hinterlassen. Für Flüchtiges eignet sich der Puffer (`SetBuffer`/`GetBuffer`), siehe [daten-und-sicherheit.md](daten-und-sicherheit.md).

## Read-modify-write über Aufrufe hinweg

- **Was:** Weil Aufrufe verschiedener Instanzen und Threads parallel laufen, ist ein Lesen-Ändern-Schreiben auf geteilten Daten (Datei, Puffer, Zähler) nicht atomar.
- **Muster:** `IPS_SemaphoreEnter($name, $ms)` / `IPS_SemaphoreLeave($name)` mit eindeutigem Namen; bei Fehlschlag sauber abbrechen. Im Repo vielfach so verwendet.

## Objektbaum und Datenfluss sind getrennt

- **Was:** Der Eltern-Anschluss (Datenfluss, `ConnectionID`) sortiert nichts im Objektbaum. Gemessen 31.08.2026: bei allen 43 verbundenen Instanzen eines Systems lag der Baum-Elter woanders als der Datenfluss-Elter.
- **Folge:** Der Zweck des Anschlusses ist Installation und Gruppierung im Datenfluss-Baum, nicht die Ablage.

## Verbinden braucht beide Seiten der Schnittstellen-GUID

- **Was:** Ein Gateway mit `childRequirements: ["{GUID}"]` verlangt, dass das Kind diese Schnittstelle **implementiert**. Ein Kind nur mit `parentRequirements` weist `IPS_ConnectInstance` mit „Datenfluss ist inkompatibel“ ab; das gilt auch bei leeren `childRequirements` des Gateways (gemessen 15.09.2026, Symcon 9.1).
- **Muster:** Im Kind-Modul dieselbe GUID in `implemented` **und** `parentRequirements`.
- Nachträglich ergänztes `parentRequirements` legt Bestandsinstanzen nicht lahm; eine unverbundene Instanz bleibt aktiv (gemessen).

## GetCompatibleParents statt ConnectParent

- **Doku:** `ConnectParent`/`RequireParent`/`ForceParent` gibt es nur für `IPSModule`. Für `IPSModuleStrict` (ab 8.2) liefert `GetCompatibleParents(): string` ein JSON, das der Konsole beim Anlegen passende Eltern vorschlägt. Die Funktion legt selbst nichts an.
  - `type: "connect"`: neue **und** vorhandene Instanzen werden vorgeschlagen. Richtig, wenn mehrere Kacheln an einem Gateway hängen.
  - `type: "require"`: nur neue Instanzen und solche ohne andere Kinder.
  - `moduleIDs` sind **Modul**-GUIDs, nicht die Schnittstellen-GUID. Alternativ `modules` mit `configuration` (erzwungen), `initial` (vorbelegt), `formOverride`.
- **Repo:** Die Kachel-Module dieser Bibliothek nutzen `type: "connect"` auf das Gateway-Modul.

## Automatisches Verbinden nur beim Kernelstart

- **Was:** Ein Modul, das sich in `ApplyChanges` selbst an ein Gateway hängt, tut das auch beim **Anlegen** über die Konsole, bevor die Konsole die Wahl des Nutzers einträgt. Die Konsole versucht dann ein zweites `IPS_ConnectInstance` und meldet „Konnte nicht zur Instanz verbinden / hat bereits ein übergeordnetes Objekt“. Quittiert der Nutzer, baut der Anlege-Assistent alles zurück, was er angelegt zu haben glaubt: die neue Instanz **und das Gateway**, auch wenn weitere Instanzen daran hängen (beobachtet 15.09.2026, Symcon 9.1).
- **Folge:** Datenverlust des Gateways, nur aus einer Sicherung wiederherstellbar.
- **Muster:** Selbstverbinden nur für Altinstanzen im Kernelstart-Zweig (Merker im `MessageSink`), neue Instanzen verbindet die Konsole. Anschluss und Rückbau-Gefahr lassen sich in Symcon nicht trennen; wer den Anschluss behält, braucht diese Regel.

## RegisterReference auf eine Instanz derselben Bibliothek

- **Was:** Eine Instanz, die per `RegisterReference` eine **Instanz** derselben Bibliothek referenzierte, blieb bei jedem Modul-Reload mit „Kann Schnittstellen-Instanz nicht erstellen:“ (leere Meldung) auf Status 101 hängen. Nach dem Umbau auf Referenzen auf die **Variablen** der Quell-Instanz: Status 102, kein Fehler (gemessen 11.09.2026, Symcon 9.1). Erklärung (Annahme): Beim Reload ist die referenzierte Instanz in diesem Moment selbst noch nicht wieder da.
- **Muster:** Möglichst Variablen der Quell-Instanz referenzieren, nicht die Instanz.
- **Repo-Stand 08.10.2026:** Einige Module referenzieren weiterhin Instanzen derselben Bibliothek (WebApp, Routines, MealPlan); ein Fehlerbild ist dort nicht dokumentiert. Die Regel stützt sich auf einen Fall.

## Fremde Instanz soll etwas übernehmen

Nicht `IPS_ApplyChanges` aus der eigenen Spur (wartet auf die fremde Spur, und hinterlegte Werte sind von außen ohnehin nicht sichtbar). Stattdessen eine Markierung in einen gemeinsamen Kanal legen und die Instanz wecken. Siehe [module-lebenszyklus.md](module-lebenszyklus.md).

---

Stand: geprüft am 08.10.2026 (Doku und Repo-Code); Messwerte mit Datum

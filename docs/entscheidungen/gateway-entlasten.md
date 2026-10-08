# Gateway frei halten: die zweite Spur (SymDo Scanner)

Symcon führt je Instanz genau eine Sache zur Zeit aus. Lange KI-Aufrufe und Schulabrufe im Gateway ließen deshalb die App hängen; seit September 2026 laufen sie in Instanzen des Moduls SymDo Scanner. Was umgezogen ist und was bewusst blieb, steht im Gateway-Handbuch (Kapitel 19a) und im Kopf von `SymDoScanner/module.php`; hier stehen die Gründe und Fallen dahinter.

## Entscheidungen

- **Eigene Instanz statt Skript-Trampolin.** `IPS_RunScriptText` wird nicht verwendet: Symcons Laufzeit-Analyse rechnet ein so gestartetes Skript demselben Thread zu, die Last wäre nur versteckt, nicht verlagert (Hinweis aus der Symcon-Entwicklung; nicht am Code prüfbar).
- **Wecken über eine Signalvariable, arbeiten im Einmal-Zeitgeber.** Das Gateway legt den Auftrag als Datei ab und erhöht `ScanSignal`; der `MessageSink` des Scanners setzt nur `RegisterOnceTimer`. Grund: `MessageSink` läuft in einem eigenen Nachrichten-Thread, nicht in der Instanz-Spur — Arbeit dort würde den Nachrichten-Thread blockieren, Arbeit im Zeitgeber läuft in der Spur des Scanners.
- **Richtungsregel: die Gateway-Spur ruft nie synchron in einen Scanner.** Umgekehrt ist es erlaubt (ein Ruf ins Gateway kostet Millisekunden). Auch `IPS_ApplyChanges` auf einen laufenden Scanner verletzt diese Regel.
- **KI-Aufträge sind ein Angebot, kein Zwang.** Nur `"async": true` plus ein bereitstehender Läufer führt zu 202 und `GET /v1/ai/jobs/{id}`; sonst antwortet die Route synchron wie zuvor. Die Visu-Kachel ist die Ausnahme: sie wird zusammen mit dem Gateway ausgeliefert und reiht ohne Opt-in ein.
- **Der Läufer hat eine eigene Sperre** (`SDSC_AiJob_<gateway>`), nicht die des Gateways — sonst hätte ein laufender Auftrag Sprachdialog, Briefing und Postauswertung mit „belegt" abgewiesen.
- **Alle Gateway-Semaphoren warten 0 ms.** In einer serialisierten Spur kann eine belegte Sperre während des Wartens gar nicht frei werden; jedes Warten lief garantiert ins Leere und blockierte die Spur zusätzlich. „busy" sofort ist die richtige Antwort.
- **Fristen sind Gesamtbudgets, nicht je Anfrage.** Das Holen einer fremden Seite galt früher je Weiterleitungssprung (vier erlaubt = bis zu 60 s Stillstand); heute `AiRecipePage::holen($url, $frist)` mit Hook 8 s, Scan 15 s.
- **Ausgewertet wird immer im Gateway, nie im Scanner.** Der Prompt braucht den Bestand, die Merker sind Gateway-Attribute, die Tagesdeckel zählen dort. Der Scanner liest und zerlegt. Deshalb gibt es für Klassenseiten keine Übergabe der Merker an den Scanner.
- **Merker bleiben quellen-eigen** (`EduSeen`, `MoodleSeen`, `MailSeenUIDs`). Ein vereinheitlichtes Format sähe sauberer aus, entwertete aber jeden vorhandenen Merker: jede schon ausgewertete Karte liefe noch einmal (kostenpflichtig) durch die KI. Aus demselben Grund trägt ein Merker auf dem Rückweg seine Quelle — wer beim Zurücknehmen den falschen Bestand anfasst, lässt das Gescheiterte für immer „gesehen" und zahlt für Fremdes doppelt.
- **Medien und Ablagen entstehen nur im Gateway.** `NotesMediaCategory` hängt die Kategorie unter die eigene Instanz — im Scanner angelegt, fände das Gateway sie nie wieder. `created` eines Vorschlags wird beim Schreiben gestempelt, nicht beim Rechnen (sonst zählte die Wartezeit in der Schlange gegen die 21-Tage-Aufbewahrung).
- **WebUntis läuft ebenfalls im Scanner (Build 146),** obwohl die Messung es nicht verlangt (ein vollständiger Lauf kostete die Gateway-Spur 1,5–2,0 s je Stunde): Einheitlichkeit aller Schulquellen war der Grund. Je Lauf genau eine Anmeldung, der Fehlerzähler (Schutz vor Kontosperre nach drei Fehlanmeldungen) bleibt im Gateway, Zugangsdaten reisen nicht mit — der Scanner liest die Eigenschaften selbst über `KonfigID()`. Hinweis: der Kopfkommentar in `SymDoScanner/module.php` nennt WebUntis noch als „draußen"; maßgeblich ist `ScanBridge::SCAN_ROLLEN`.
- **Briefing: nur der Anbieteraufruf ist Auftrag.** Gesammelt und formuliert wird im Gateway (der Sammler liest ein Dutzend Bestände dieser Instanz), die Sprachaufnahme bleibt dort, weil die Medienobjekte dort hängen.
- **Die Rückfallwege im Gateway bleiben stehen.** Eine Quelle im Scanner abschalten gibt sie ans Gateway zurück.

## Fallen/gemessen

- Ausgangslage: 30 gleichzeitige Hook-Abrufe brauchten 874 ms statt 31 ms, während ein Timer im Gateway lief. Nach dem Umzug: Gateway-Anteil eines KI-Auftrags rund 20 ms, Hook-Abrufe währenddessen p50 0,1 ms.
- **Der Zeitgeber des Gateways ist die einzige Uhr eines Laufs.** Der Scanner arbeitet nur ab, was im Kanal liegt. Zweimal wäre beim Umzug der Gateway-Zeitgeber abgeschaltet worden — die Quelle wäre nach einem Lauf eingeschlafen.
- **Eine volle Warteschlange ist kein Fehlversuch.** `MailAnalyse` liefert `?bool`: `null` = gerade kein Platz. Als `false` gezählt wäre eine Mail nach dreimal „kein Platz" endgültig übersprungen worden.
- **Der Rauchtest darf keine Handlisten führen.** Ein Scanner-Lauf starb lautlos im Zeitgeber an einer Methode, die nur das Gateway hat, später an einer Konstante, davor fehlte eine Datei in der Prüfliste — der Test war jedes Mal grün. `SymDoScanner/tests/ScannerSmokeTest.php` leitet Dateiliste (`require_once`), Methoden (`$this->…(`) und Konstanten (`self::…`) heute aus dem Quelltext ab.
- **Eine Obergrenze ist keine Messung.** Die Planung rechnete für WebUntis mit 20 s je Stunde — das war `UNTIS_HTTP_FRIST`, eine Frist je Aufruf. Gemessen waren es zwei Sekunden.
- **Prüfstände müssen die echte Eingabe fahren.** Mehrere Fehler blieben unentdeckt, weil Teilprüfstände die Nutzlast des Erzeugers von Hand nachbauten (siehe `sicherheit-codereview-2026-09.md`).
- Testattrappen kennen `WC_PushMessage`/`CC_GetURL` nicht — Rechenkerne, die solche Pfade berühren, brauchen eine `function_exists`-Wache.

## Offen

- Bei geänderten Klassenseiten-Karten werden die Anhänge für die KI ein zweites Mal geholt (bis zu fünf je Lauf, in der Gateway-Spur), obwohl `EduSeiteSpiegeln` sie schon geladen hat. Kein Commit behebt das ausdrücklich (nicht am Code prüfbar).
- Live noch nicht belegt (Stand der Umbaunotizen): Briefing als Auftrag, Postfach-Weg, „eine Karte hat sich wirklich geändert" (nicht am Code prüfbar).

Stand: geprüft gegen den Code am 08.10.2026

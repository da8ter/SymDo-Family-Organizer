# Geräte-Umfang: eingeschränkte Geräte (Entwurf, nicht gebaut)

Heute darf jedes gekoppelte Gerät alles. Für eingeschränkte Geräte (Kindertablet, Küchenpanel, Gast) ist hier festgehalten, wie ein Umfang aussehen und wo er durchgesetzt werden soll — beschlossen im August 2026, bewusst noch nicht gebaut, aber beim nächsten Anfassen der Geräteverwaltung vorzusehen, solange die Kopplungsdaten noch jung sind.

## Entscheidungen

- **Heute: Erlaubnis aus dem Typ, nicht aus dem Artefakt.** `GetInstanceKind()` prüft nur die Modul-GUID (Einkaufs- oder ToDo-Liste); jedes gekoppelte Gerät erreicht jede solche Liste, auch eine ausgeblendete — `hidden` ist eine Anzeigeeigenschaft, die mitgeliefert, aber nicht durchgesetzt wird. Für ein Familiengerät ist das stimmig. Zum Vergleich: IPSView leitet die Erlaubnis aus dem geladenen View ab (lesen, was darin vorkommt; schreiben, was dort schreibbar ist).
- **Datenmodell:** ein Feld `scope` am Geräteeintrag in `PairedDevices` (`DeviceRegistry`), etwa `{instances: [...], readOnly: bool, notes: bool, calendar: bool, ai: bool}`.
- **Fehlt `scope`, gilt voller Zugriff** — nicht „nichts". Sonst verlören alle bestehenden Kopplungen mit dem Update ihren Zugang, ohne dass jemand versteht, warum.
- **Durchsetzung an genau zwei Stellen in `ApiRouter.php`:**
  1. `RouteInstance()` — die einzige Tür zu Listendaten. Nach `GetInstanceKind()` prüfen, ob die ID im Umfang liegt; sonst 403 (nicht 404 — das wäre gelogen). Bei `readOnly` zusätzlich die schreibenden Unterrouten `actions` und `visibility` sperren.
  2. `HandleDiscovery()` — dieselbe Karte auf dem Rückweg: nur Instanzen im Umfang beschreiben. Sonst zeigt die Oberfläche Türen ohne Klinke.
- **Instanzlose Bereiche** hängen an keiner Liste. Erster Wurf: je ein Schalter, ganz an oder ganz aus. Seit dem Entwurf sind es deutlich mehr geworden (u. a. `notes`, `calendar`, `ai`, `revisions`, `mail`, `original`, `edumaps`, `homework`, `push`, `briefing`, `timetable`, `transit`, `voice`, `tts`, `users`) — die Schalter müssten heute gruppiert werden.
- **Der Umfang entsteht beim Koppeln, nicht danach.** `CreatePairing()` (heute ohne Parameter) bekäme den Umfang mit, das Formular eine Auswahl „Dieses Gerät darf …"; der Kopplungscode trägt den Umfang. Sonst hätte ein frisch gekoppeltes Gerät für einen Moment alles.
- **Bewusst nicht vorgesehen:** eine Objekt-Ebene wie bei IPSView (einzelne Variablen). Die Einheit ist die Liste; feiner wäre Aufwand ohne Nutzen.

## Prüfsteine für die Umsetzung

- Bestandsgerät ohne `scope` behält alles.
- Gerät mit Liste A erreicht Liste B weder über `/state` noch über `/actions`.
- `/discovery` nennt B nicht.
- `readOnly` lässt GET durch und beantwortet POST mit 403.
- Ein Umfang mit gelöschter Instanz-ID stört nicht.

## Offen

- Komplett: nichts davon ist gebaut (Stand Code: kein `scope` in `DeviceRegistry`, keine Prüfung in `RouteInstance`).

Stand: geprüft gegen den Code am 08.10.2026

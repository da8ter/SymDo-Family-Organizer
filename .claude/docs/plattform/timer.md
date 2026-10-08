# Timer in Modulen

## Grundlagen laut Doku

- `RegisterTimer` nur in `Create()`; der Timer ist zustandslos und wird bei jedem Start mit dem dort angegebenen Intervall neu angelegt. Intervalle gehören deshalb in `ApplyChanges` (`SetTimerInterval`).
- `RegisterOnceTimer` (ab 5.5) führt einen Skriptinhalt einmal sofort aus. Im Repo genutzt, um Arbeit aus einem Hook- oder Aktionsaufruf in einen eigenen Durchlauf zu verlagern.

## SetTimerInterval startet den Countdown neu, auch beim gleichen Wert

- **Was:** Jeder Aufruf zählt ab **jetzt** neu, auch wenn das Intervall unverändert ist (gemessen 12.09.2026, Symcon 9.1). Die Doku sagt dazu nichts.
- **Folge für Module:**
  - Steht der Aufruf in einem Pfad, der von außen im Takt gerufen wird (Kachel-Status, App-Abfrage), feuert der Timer **nie**: Er wird immer kurz vor dem Ablauf zurückgesetzt. Gemessen: sechs Abrufe im 50-Sekunden-Takt, vier Minuten ohne einen Lauf eines 60-Sekunden-Timers.
  - Jedes `ApplyChanges` setzt lange Timer zurück. An einem Entwicklungstag mit rund 20 Modul-Reloads liefen Stunden-Timer nie an, während ein 60-Sekunden-Timer durchlief (11.09.2026, Symcon 9.1).
- **Muster:** Intervall nur setzen, wenn es sich ändert; den zuletzt gesetzten Wert in einem Attribut merken. Für lange Takte die Fälligkeit als Zeitstempel im Attribut halten und mit der **Restzeit** scharfstellen.

## Ein Timer auf 0 weckt sich nicht selbst

- **Was:** Intervall 0 heißt „nie“. Wer einen Timer außerhalb eines Zeitfensters abschaltet und erwartet, dass er sich zum Fensterbeginn selbst wieder einschaltet, wartet ewig.
- **Muster:** Ein langsamer Dauerschlag, der das Fenster prüft und den schnellen Takt bei Bedarf setzt.

## Nach dem Kernelstart ist der Takt weg, das Attribut nicht

- **Was:** Nach dem Start gilt wieder das Intervall aus `RegisterTimer` (meist 0). Ein gemerktes „zuletzt gesetzt“ im Attribut überlebt den Start dagegen.
- **Folge:** Vergleicht das Modul nur mit dem gemerkten Wert, setzt es den Timer nie wieder.
- **Muster:** Den gemerkten Wert in `ApplyChanges` (Kernelstart-Zweig) zurücksetzen.

## Timer sind keine Objekte

- **Was:** In Symcon 9.1 erscheinen Modul-Timer nicht mehr als Ereignis-Objekte: `IPS_GetChildrenIDs(<Instanz>)` liefert sie nicht, `IPS_GetEventList()` kennt sie nicht (11.09.2026). `IPS_GetObjectIDByIdent('<Timername>', <Instanz>)` liefert `false` (30.08.2026). Die Timer laufen trotzdem.
- **Folge:** „Keine Timer-Objekte gefunden“ ist kein Befund. Ob ein Timer läuft, zeigt nur das Modul selbst (z. B. ein Zeitstempel des letzten Laufs) oder `IPS_GetTimerList()`/`IPS_GetTimer()`.

## Doppelte Timer nach einem Modul-Reload

- **Was:** Gemessen am 02.09.2026 unter **Symcon 9.0**: Ein `MC_ReloadModule` legte jeden **aktiven** Timer (Intervall > 0) neu an und ließ den alten stehen. Der Überrest hing (`LastRun == NextRun`), der neue lief nie. Timer mit Intervall 0 blieben einfach. Ein `IPS_DeleteTimer` gibt es nicht; erst ein Kernelstart bereinigt. Unter 9.1 nicht erneut geprüft.
- **Erkennen:** `IPS_GetTimerList()` durchgehen, `IPS_GetTimer($id)` nach `InstanceID` filtern; mehr als ein Timer je Name ist der Befund.
- **Abhilfe:** Vor einem Reload aktive Timer auf 0 setzen oder nach dem Reload einen Kernelstart einplanen.

## Timer, die noch nicht registriert sind

- **Was:** `SetTimerInterval` auf einen Timer, den `Create()` noch nicht registriert hat, erzeugt eine **Warnung**, keine Exception (20.08.2026, Symcon 9.0). Das passiert z. B. nach dem Einbau eines neuen Traits, bevor `Create()` erneut lief.
- **Muster:** `@$this->SetTimerInterval(...)`, damit nicht jedes `ApplyChanges` warnt (im Repo so in mehreren Modulen). Hintergrund in [module-strict-und-php.md](module-strict-und-php.md).

## Lange Arbeit im Timer blockiert die Instanz

Timer, Hooks und Aktionen einer Instanz laufen nacheinander. Hintergrundarbeit deshalb in kurzen Häppchen mit viel Luft. Details in [instanzen-und-nebenlaeufigkeit.md](instanzen-und-nebenlaeufigkeit.md).

---

Stand: geprüft am 08.10.2026 (Doku und Repo-Code); Messwerte mit Datum

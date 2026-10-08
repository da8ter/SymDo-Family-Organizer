# Modul-Lebenszyklus: Create, ApplyChanges, Reload, Properties, Status

Plattformwissen für die Module dieser Bibliothek. Doku-Aussagen sind mit „Doku“ markiert, Messungen mit Datum und Symcon-Version.

## Create()

- **Was:** Laut Doku läuft `Create()` beim Anlegen der Instanz und bei jedem Start von Symcon. Gemessen (15.09.2026, Symcon 9.1) läuft es zusätzlich bei jedem `MC_ReloadModule` für **jede bestehende** Instanz der Bibliothek.
- **Folge für Module:** Ein in `Create()` gesetztes Kennzeichen „ich bin neu“ trifft auch Altinstanzen. Neue `RegisterProperty*`/`RegisterAttribute*` erscheinen nach einem Reload sofort an bestehenden Instanzen (gemessen 31.08.2026, Symcon 9.0). `IPS_ApplyChanges` allein macht eine neue Property nicht bekannt.
- **Muster:** In `Create()` nur registrieren. Wer Erst-Anlage von Bestand unterscheiden muss, macht das nicht über `Create()`, sondern z. B. über einen Merker im `MessageSink` (siehe unten).

## ApplyChanges() und Kernelstart

- **Was:** Doku: `ApplyChanges` läuft nach dem Erstellen der Instanz und bei „Übernehmen“. Gemessen (15.09.2026, Symcon 9.1) kommt der Kernelstart in **zwei Wellen**: erst `ApplyChanges` bei Runlevel `KR_INIT` (10102), rund neun Sekunden später `MessageSink(IPS_KERNELSTARTED)` und ein zweites `ApplyChanges` bei `KR_READY` (10103).
- **Folge für Module:** Wer kurz nach `KR_READY` misst, sieht den Kernelstart-Zweig eventuell noch nicht. Bei einem Modul-Reload bleibt der Runlevel auf `KR_READY`; die Runlevel-Wache greift dort also nicht.
- **Muster:** Die Wache aus den Hausregeln (`IPS_GetKernelRunlevel() !== KR_READY` → `RegisterMessage(0, IPS_KERNELSTARTED)` → `return`). Aktionen, die nur beim Kernelstart laufen sollen (z. B. das automatische Umhängen an ein Gateway), über einen Merker steuern, der im `MessageSink` gesetzt und im `finally` gelöscht wird. So verwenden es die Kachel-Module dieser Bibliothek (`applyFromKernelStart`).

## Modul neu laden (MC_ReloadModule)

- **Was:** `MC_ReloadModule(<ModuleControl-ID>, '<Bibliotheksordner>')`. Der zweite Parameter ist der **Ordnername der Bibliothek**, nicht Modulname, Bibliotheksname oder GUID (sonst „Modul … existiert nicht“). Die Funktion steht nicht im offiziellen Funktionsindex (geprüft 08.10.2026).
- **Was der Reload leistet** (gemessen 31.08. und 10.09.2026, Symcon 9.0/9.1):
  - liest `module.json` neu ein,
  - macht neue Properties an bestehenden Instanzen bekannt,
  - lässt ein **ganz neues Modul** der Bibliothek erscheinen (`IPS_CreateInstance`, Formular und `GetVisualizationTile` funktionieren sofort),
  - lässt Attribute, Status und `ConnectionID` unangetastet.
  - Code (`module.php`, Traits, `form.json`, `module.html`, `locale.json`) greift ohnehin ohne Reload.
- **Grenze:** Neue öffentliche `PREFIX_`-Funktionen registriert der Reload **nicht**; die Funktionstabelle entsteht beim Kernelstart. Muster: statt einer neuen Präfix-Funktion einen neuen Fall in `RequestAction` anlegen und per `IPS_RequestAction($id, 'Fall', …)` ansprechen.
- **Nebenwirkungen:**
  - Offene Anfragen von Konsole und Visualisierung an die API brechen ab („Connection closed before full header was received“). Das ist kein Fehler der Kachel. Häufige Reloads während jemand zusieht vorher ansagen.
  - `IPS_GetInstanceListByModuleID()` kann während des Reloads kurz **leer** zurückkommen (02.09.2026, Symcon 9.0). Eine Instanz, die sich selbst sucht, darf daraus nicht „ich bin nicht zuständig“ schließen; Rückfall auf die eigene ID.
  - `IPS_GetProperty` auf eine Geschwister-Instanz, die gerade neu erzeugt wird, liefert „InstanceInterface is not available“ plus `false` (23.09.2026, Symcon 9.1). Muster: `@` plus `is_string()`-Probe und später wiederholen.
  - Doppelte Timer nach Reload: siehe [timer.md](timer.md).
  - Lange Timer verhungern bei vielen Reloads: siehe [timer.md](timer.md).

## Präfix-Kollision zweier Bibliotheken

- **Was:** Tragen zwei Bibliotheken dasselbe `prefix`, meldet Symcon nur Funktionen mit **abweichender Parameterzahl** („Parameter count does not match“). Gleichnamige Funktionen gleicher Parameterzahl gewinnen still für die zuerst registrierte Bibliothek (gemessen 11.09.2026, Symcon 9.1).
- **Folge:** Aufrufe landen im fremden Modul, Parameter kommen nicht an.
- **Abhilfe:** Eindeutige Präfixe; nach einer Umbenennung braucht es einen Kernelstart.

## Properties lesen und schreiben

- **`IPS_SetProperty` liefert `false`** sowohl bei unbekannter Eigenschaft als auch bei **unverändertem Wert** (04.09.2026, Symcon 9.0). `IPS_HasChanges` ist nach dem Setzen des gleichen Werts ebenfalls `false`. Der Rückgabewert taugt also weder als Fehler- noch als Erfolgssignal.
- **`IPS_GetProperty`/`IPS_GetConfiguration` zeigen den aktiven Stand**, nicht den hinterlegten. Nach `IPS_SetProperty` ohne `IPS_ApplyChanges` liefern beide den alten Wert (13.09.2026, Symcon 9.1).
- **`IPS_GetProperty` auf eine unbekannte Eigenschaft wirft.** Also in `try/catch` und nicht auf `false` prüfen.
- **Muster:** Schreiben, `IPS_ApplyChanges`, gegenlesen, dann erst melden.
- **Fremde Instanz soll etwas übernehmen:** Kein `IPS_ApplyChanges` auf die fremde Instanz aus der eigenen Spur (der Aufruf wartet auf deren Spur, siehe [instanzen-und-nebenlaeufigkeit.md](instanzen-und-nebenlaeufigkeit.md)). Stattdessen eine Markierung in einem gemeinsamen Kanal ablegen und die Instanz wecken; sie übernimmt selbst.
- **Formularfeld für eine noch unbekannte Property:** Zeigt ein Formular ein Feld für eine Property, die die Instanz (noch) nicht kennt, scheitert „Übernehmen“ für die **ganze** Konfiguration („Eigenschaft … nicht gefunden“). Muster: neue Felder nur einblenden, wenn `array_key_exists($name, json_decode(IPS_GetConfiguration($id), true))`.

## Migration von Properties

- **Korrektur gegenüber älteren Notizen:** Symcon hat einen Migrations-Hook. Doku: `Migrate(string $JSONData): string` (ab 7.0) läuft beim Start von Symcon und nach einem Modul-Update, nicht beim erstmaligen Erstellen. Die Funktion bekommt Konfiguration und Attribute als JSON und gibt die korrigierte Fassung zurück (leerer String = keine Änderung). Im Repo wird sie bisher nicht verwendet.
- **Alternative (bisher im Bestand verwendet, Muster aus Symcons eigenem Modul SceneControl):** In `ApplyChanges` neue Werte per `IPS_SetProperty($this->InstanceID, …)` schreiben, `IPS_ApplyChanges($this->InstanceID)` aufrufen und **sofort `return`**; der zweite Durchlauf erledigt den Rest.
  - Gegen Endlosschleifen ein Wächter-Attribut **vor** dem Schreiben auf `true` setzen (Attribute sind sofort persistent, der verschachtelte Durchlauf sieht es).
  - Bereits gepflegte Zielstruktur hat Vorrang; Neuinstallation setzt das Attribut trotzdem; eine absichtlich geleerte Liste darf nicht zurückkommen.
  - Alte Properties bleiben registriert (`ReadProperty` auf eine nicht registrierte Property wirft), werden nach der Übernahme geleert und aus dem Formular genommen.

## Instanzstatus

- **Was:** Symcon setzt `IS_ACTIVE` (102) bei der Erzeugung selbst. Der Status ist persistent und wird **nie neu berechnet**; `IPS_ApplyChanges` ändert ihn nur, wenn das Modul `SetStatus` aufruft (gemessen 31.07. und 10.08.2026, Symcon 9.0). Ein im Hochlauf gesetzter Status kann vom Hochlauf wieder überschrieben werden; Instanzen standen nach jedem Neustart wieder auf `IS_CREATING` (101), obwohl sie einwandfrei arbeiteten.
- **Folge für Module:** Niemals Logik auf den Status einer Geschwister-Instanz stützen, auch nicht vorübergehend.
- **Muster:** Den Aufruf an die andere Instanz in `try/catch` klammern und Fehlschlag als „kein Zustand“ werten; als Bereitschaftsprüfung den Kernel-Runlevel (`KR_READY`) nehmen. Unter `IPSModuleStrict` heißt die Methode weiterhin `SetStatus`.

## Instanzen deinstallierter Module

- **Was:** Ist das Modul weg (Status 105, `IS_NOTCREATED`), liefern `IPS_GetConfiguration` und `IPS_GetProperty` stillschweigend `false`. `IPS_GetInstance`, `IPS_GetChildrenIDs` und `IPS_SetParent` funktionieren weiter (13.08.2026, Symcon 9.0).
- **Abhilfe:** Kinder (z. B. Medienobjekte) lassen sich noch umhängen; Konfiguration und Attribute nur noch über `settings.json` (siehe [daten-und-sicherheit.md](daten-und-sicherheit.md)).
- Die Fehlermeldung „Kann Schnittstellen-Instanz nicht erstellen: Modul mit der GUID … nicht gefunden“ gehört zu diesem Fall. Dieselbe Meldung **ohne** Text siehe `RegisterReference` in [instanzen-und-nebenlaeufigkeit.md](instanzen-und-nebenlaeufigkeit.md).

---

Stand: geprüft am 08.10.2026 (Doku und Repo-Code); Messwerte mit Datum

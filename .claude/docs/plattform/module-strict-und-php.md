# Module Strict und PHP-Eigenheiten in Symcon

## IPSModuleStrict und IPSModule

- **Doku:** `IPSModuleStrict` gibt es seit 8.1 und ist für neue Module vorgesehen. Unterschiede zu `IPSModule` laut Doku:
  - Typangaben sind Pflicht, fehlende führen zu Fehlern statt Warnungen.
  - `RegisterVariable*`/`MaintainVariable` liefern `bool` (Variable neu angelegt?) statt der Variablen-ID.
  - Modul-Variablen sind schreibgeschützt; schreiben nur über `$this->SetValue`.
  - Datenfluss zu Eltern und Kindern ist **HEX-kodiert** statt UTF-8.
  - Hooks und OAuth nativ (`RegisterHook`, `RegisterOAuth`), siehe [hooks-und-grenzen.md](hooks-und-grenzen.md).
  - `ConnectParent`/`RequireParent`/`ForceParent` entfallen, Ersatz `GetCompatibleParents()`.
- **Repo:** Alle Module dieser Bibliothek erben von `IPSModuleStrict`.

## Signaturen von Überschreibungen folgen der Basisklasse

- **IPSModuleStrict:** Basisklasse voll typisiert, Überschreibungen müssen typisiert sein, z. B. `MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void`, `RequestAction(string $Ident, mixed $Value): void`, `ProcessHookData(): void`.
- **IPSModule** (nur relevant für Module außerhalb dieser Bibliothek): Die Basismethoden sind **ohne Parametertypen** deklariert. Typisierte Parameter in einer Überschreibung führen zum Fatal „Declaration … must be compatible with IPSModule::…“, das Modul lädt nicht (beobachtet 09.07.2026, PHP 8.5). Rückgabetypen dürfen ergänzt werden, **außer bei `ProcessHookData`** (siehe [hooks-und-grenzen.md](hooks-und-grenzen.md)).
- **Prüfen:** `php -l` erkennt solche Unverträglichkeiten nicht, weil die Elternklasse nicht geladen ist. Ein kleiner Test mit einer nachgebildeten Basisklasse reicht.
- **Muster:** Vor jeder Signatur-Änderung nachsehen, wovon die Klasse erbt.

## Datenfluss von nativen I/O-Eltern ist HEX-kodiert

- **Was:** Native Symcon-Eltern (gemessen: MQTT Client, Server Socket) liefern `Payload`/`Buffer` an ein `IPSModuleStrict`-Kind **hex-kodiert**, an ein `IPSModule`-Kind als Text (gemessen 25.09.2026, Symcon 9.1). Die Doku nennt die HEX-Kodierung allgemein.
- **Folge:** Beim Umstieg eines bestehenden Moduls auf Strict kommen statt JSON Hex-Zeichen an; ein Parser verwirft dann still alles.
- **Muster:** `hex2bin` im Empfang; robust mit Probe `ctype_xdigit($roh) && strlen($roh) % 2 === 0` (so im Repo bei `SymDoESPVoice`).

## PHP-Methodennamen sind nicht case-sensitive

- **Was:** PHP unterscheidet Methodennamen nicht nach Groß-/Kleinschreibung. Eine Klassenmethode `Dispatch()` als Wrapper um eine Trait-Methode `dispatch()` gibt beim Laden **keinen** Fehler; die Klassenmethode gewinnt und `$this->dispatch()` ruft sich selbst auf.
- **Symptom in Symcon:** Der Aufruf läuft knapp eine Sekunde und endet mit „Output-Buffer exceeds Limit … Operation halted“, ohne Stacktrace und ohne Zeile im Modul (beobachtet 21.09.2026, Symcon 9.1).
- **Muster:** Trait-Methoden hinter öffentlichen Wrappern anders benennen (`runDispatch`, `applyNow`), nie nur durch Groß-/Kleinschreibung unterscheiden. Bei „Output-Buffer exceeds Limit“ ohne erkennbare Schleife zuerst nach solchen Kollisionen suchen. Gleiches gilt für eigene Methoden, die Namen der Basisklasse treffen (z. B. ein privates `RegisterHook` überdeckt das native).

## Symcon warnt, statt zu werfen

Viele Kernfunktionen melden Fehler als **PHP-Warnung** und Rückgabewert `false`, nicht als Exception.

- **Folgen:**
  - `try { … } catch (\Throwable $e)` greift nicht; ein `catch` allein täuscht Sicherheit vor.
  - Die Warnung geht in die Ausgabe. In einem Hook landet sie vor den Kopfzeilen und zerlegt die Antwort („headers already sent“), der Client bekommt Warntext statt JSON.
- **Gemessene Fälle:**
  - Attribut oder Timer, den `Create()` noch nicht registriert hat: „Attribut … nicht gefunden“, „Timer … existiert nicht“ (20.08.2026, Symcon 9.0).
  - `RequestAction` auf eine Variable ohne Aktion oder mit werfender Aktion: `false` plus Warnung (23.09.2026, Symcon 9.1).
  - `IPS_RunActionWait` liefert die **Ausgabe** der Aktion: Leerstring/Text bei Erfolg, PHP-Fehlertext („Fatal error: Uncaught …“) bei Fehler, `false` plus Warnung bei unbekannter Aktion. `IPS_RunScriptEx` mit fehlendem Skript: `false` plus Warnung (23.09.2026).
  - `IPS_GetProperty` auf eine Instanz, die ein Reload gerade neu erzeugt: „InstanceInterface is not available“ plus `false`.
  - `IPS_VariableExists` mit einer ID über 59999: Warnung „Parameter … not inside of the specified bounds“.
  - `SetValue*`/`GetValue*` mit falschem Typ, `IPS_GetObjectIDByIdent` ohne Treffer, unbekannte Objekte: siehe [variablen-und-darstellungen.md](variablen-und-darstellungen.md).
- **Ausnahme:** `IPS_GetProperty` auf eine unbekannte Eigenschaft wirft.
- **Muster:** Beides: `@` vor den Aufruf **und** eine Rückgabe- oder Rücklese-Probe danach, z. B. Attribut schreiben und gegenlesen; bei Abweichung `LogMessage(…, KL_ERROR)` mit Hinweis auf Neustart oder Reload. Mit `@` wird ein eigener Error-Handler trotzdem aufgerufen.

## Weitere gemessene Kern-Eigenheiten (25.09.2026, Symcon 9.1)

- `IPS_GetInstance()`: die Modul-GUID steht unter `ModuleInfo.ModuleID`, nicht auf oberster Ebene.
- `SendDebug` landet nie im Logfile, auch nicht mit `IPS_EnableDebug`. Live-Diagnose ohne Konsole nur über eine eigene Datei oder `LogMessage`.
- `IPS_GetSystemLanguage()` liefert z. B. `"de_DE"`; `Translate()` nutzt dann den `de`-Block der `locale.json`.
- `VariableUpdated` ändert sich bei jedem Schreiben, `VariableChanged` nur bei Wertänderung.
- `IPS_GetMedia` liefert `MediaUpdated`, `MediaCRC`, `MediaSize`; `IPS_GetObject` hat kein Feld zur Visualisierung.
- Native Instanzen per `IPS_CreateInstance`: Ein MQTT Client bekommt dabei **keinen** Socket-Elter. Seine Eigenschaften heißen u. a. `ClientID`, `KeepAliveInterval`, `UserName`, `Password`, `Subscriptions` (JSON-String); `CleanSession` gibt es nicht.

---

Stand: geprüft am 08.10.2026 (Doku und Repo-Code); Messwerte mit Datum

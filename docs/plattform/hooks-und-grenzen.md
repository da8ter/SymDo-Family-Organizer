# Hooks und Ausgabegrenzen

## Native Hooks (RegisterHook)

- **Doku:** `RegisterHook(string $Adresse)` (ab 8.1) registriert `/hook/<Adresse>` beim WebHook Control; Aufrufe landen in `ProcessHookData()`. Die Adresse wird **ohne** `/hook/` angegeben. Die Registrierung ist **flüchtig**: Nach einem Neustart ist sie weg, bis die Instanz `RegisterHook` erneut ausführt; aufgeräumt werden muss beim Löschen nichts. Die Doku nennt `Create()` als üblichen Ort. Die Methode ist für `IPSModuleStrict` gedacht.
- **Doku, WebHook Control:** Es gewinnt der Hook mit der **längsten Übereinstimmung**; `/hook/abc/xyz` landet bei `/hook/abc`, solange es keinen spezifischeren Hook gibt.
- **Gemessen:**
  - Per Reflection ist `RegisterHook` nur in `IPSModuleStrict` eine echte Methode. In `IPSModule` fehlt sie in der Reflection und in `method_exists`, ein Aufruf ging in beobachteten Builds trotzdem (28.09.2026, Symcon 9.1). Daraus nicht „nicht verfügbar“ schließen, aber auch nicht darauf bauen. Eine eigene Methode gleichen Namens im Modul überdeckt den nativen Aufruf.
  - Native Hooks erscheinen **nicht** in der Eigenschaft `Hooks` des WebHook Control. Alte Einträge dort (aus dem früheren Weg) sind harmlos.
- **Store-Anforderung:** Module, die WebHook Control oder OAuth Control per `IPS_SetProperty`/`IPS_ApplyChanges` manipulieren, lehnt der Module Store ab (Erfahrung Juli 2026). Nur `RegisterHook`/`RegisterOAuth` verwenden. Dazu `$this->LogMessage()` statt `IPS_LogMessage()`.
- **Repo:** Das Gateway registriert die OAuth-Rückruf-Hooks in `Create()` und die App-Hooks in `ApplyChanges()` (nur die zuständige Instanz). Gemessen läuft `Create()` auch bei einem Modul-Reload (siehe [module-lebenszyklus.md](module-lebenszyklus.md)); die Registrierung in `ApplyChanges` schadet nicht und erneuert die Hooks zusätzlich bei jedem Übernehmen.

## „Hook not found“ nach einem Reload

- **Was:** Fragt eine Instanz während eines Reloads `IPS_GetInstanceListByModuleID()` ab, um zu entscheiden, ob sie zuständig ist, kann die Liste kurz **leer** sein. Wer dann die Hook-Anmeldung überspringt, verliert die Hooks: HTTP 404 „Hook not found“ auf allen Pfaden, bis zum nächsten `IPS_ApplyChanges` (gemessen 02.09.2026, Symcon 9.0).
- **Muster:** Leere Liste heißt nicht „nicht ich“; Rückfall auf die eigene ID. Ein Reload allein nimmt korrekt angemeldete Hooks nicht weg.

## Fatal „HookInstance::ProcessHookData() must be compatible“

- **Was:** Für jeden Hook erzeugt Symcon eine Hilfsklasse `HookInstance extends <Modulklasse>`, um die geschützte Methode aufzurufen. Deren Signatur muss zu der des Moduls passen.
  - **IPSModuleStrict:** `ProcessHookData(): void` ist Pflicht. Meldet ein Hook trotzdem den Fatal, ist **Symcon zu alt**: 9.1-Builds bis Anfang September 2026 erzeugten die Hilfsklasse ohne Rückgabetyp (gemessen 15.09.2026: Build vom 06.09.2026 Fatal, Builds ab 10.09.2026 laufen). Den Rückgabetyp im Modul zu entfernen hilft **nicht**: Die Klasse lädt dann gar nicht (Status 105, „InstanceInterface is not available“) und die Hooks melden sich ab. Abhilfe: Symcon aktualisieren.
  - **IPSModule:** Hier erzeugt Symcon die Hilfsklasse ohne Rückgabetyp; hat das Modul `ProcessHookData(): void`, ist jeder Hook-Aufruf ein Fatal (gemessen 26.09.2026, Symcon 9.1). Dort muss `: void` weg.
- **Unterscheiden:** Fatal im Hook heißt Signatur-Unverträglichkeit, „Hook not found“ heißt Abmeldung. Builds vergleicht man mit `IPS_GetKernelRevision()` und `IPS_GetKernelDate()`; `IPS_GetKernelVersion()` sagt nur „9.1“.

## Ausgabegrenze ScriptOutputBufferLimit

- **Doku (Spezialschalter):** `ScriptOutputBufferLimit` (ab 5.2), Vorgabe 1 048 576 Bytes. Längere Ausgabe bricht das Skript mit „Output-Buffer exceeds Limit“ ab. `ServerMaxPostSize` (ab 5.1), Vorgabe 25 165 824 Bytes, begrenzt die Eingangsseite.
- **Gemessen (21.08.2026, Symcon 9.0):**
  - Es zählt die **Summe** der Ausgabe einer Anfrage. Stückeln mit `flush()` oder `readfile()` in Häppchen hilft nicht.
  - Die Antwort wird **ersetzt**, nicht abgeschnitten: Der Client bekommt 62 Bytes Fehlertext bei **HTTP 200** und mit dem schon gesendeten `Content-Type`, also eine kaputte Datei statt einer Fehlermeldung.
  - Die Grenze sitzt in Symcons PHP-SAPI (`PHP_SAPI === 'IP-Symcon'`), nicht in PHP-Einstellungen; `output_buffering` ist 0. `memory_limit` steht auf 32M.
  - Ein Hook darf `Content-Encoding: gzip` setzen; gepackte Ausgabe zählt mit ihrer gepackten Größe.
  - Die HTML-Antwort von `GetVisualizationTile` fällt **nicht** unter diese Grenze (über 1,1 MB gingen mit Status 200 durch, 11.09.2026, Symcon 9.1).
- **Folge für Module:** Die Option kann pro Installation erhöht sein; Module müssen mit dem Werkswert 1 MiB auskommen.
- **Muster:** Riegel **vor** der Ausgabe: Größe beim Ablegen und beim Lesen prüfen, nicht beim Senden. Große Antworten packen oder in Stücke über mehrere Anfragen teilen. Im Repo: `libs/KachelApp.php` liest die aktuelle Grenze per `IPS_GetOption('ScriptOutputBufferLimit')` mit 1 MiB als Rückfall.
- „Output-Buffer exceeds Limit“ ohne große Ausgabe deutet oft auf eine Endlosrekursion (siehe [module-strict-und-php.md](module-strict-und-php.md)).

## Weitere Grenzen laut Doku

- Instanz-Puffer (`SetBuffer`): Warnung ab 256 kB, laut Doku hart bei 1024 kB, darüber wird abgeschnitten. Gemessen (bis 28.09.2026, Symcon 9.1) behielt Symcon schon nur die letzten 512 kB; die strengere Zahl gilt.
- PHP: standardmäßig 50 Threads, 32 MB je Thread.
- Objekt-IDs liegen zwischen 10000 und 59999.

---

Stand: geprüft am 08.10.2026 (Doku und Repo-Code); Messwerte mit Datum

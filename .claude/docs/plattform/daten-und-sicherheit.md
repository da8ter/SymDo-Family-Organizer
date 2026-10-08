# Daten, Persistenz und Geheimnisse

## settings.json: Aufbau

- `IPS_GetKernelDir() . 'settings.json'` enthält je Objekt unter `objects → "ID<n>" → data` u. a. `configuration` (alle Properties), `attributes` (alle Attribute), `moduleID`, `connectionID`, `lastChange`.
- **Einziger Weg an Attribute von außen:** Es gibt kein `IPS_GetAttribute`, `ReadAttribute*` ist geschützt. Für Migrationen über Instanzgrenzen hinweg oder für Instanzen deinstallierter Module (dort liefern `IPS_GetConfiguration`/`IPS_GetProperty` nur `false`) bleibt nur die Datei.
- **Verzug:** Die Datei hinkt dem Speicherstand hinterher. Gemessen (22.08.2026, Symcon 9.0): bis zu acht Minuten und drei verpasste Schreibvorgänge. `IPS_ApplyChanges` erzwingt das Schreiben **nicht**; einen Hebel von außen gibt es nicht.
- **Folge für Module:** Die Datei ist nur eine Rückfallebene hinter der offiziellen API und nur dort verlässlich, wo nichts mehr schreibt. Ein „alter Wert in der Datei“ ist kein gescheitertes Schreiben. Umgekehrt kann der Verzug helfen, einen versehentlich überschriebenen Wert zurückzuholen.

## settings.json enthält Geheimnisse im Klartext

- **Was:** `PasswordTextBox` maskiert nur die **Anzeige**. Der Wert landet unverändert in `settings.json` (gemessen 22.08.2026, Symcon 9.0). Auf der gemessenen Installation war die Datei für alle Benutzer lesbar (Modus 0604).
- **Folge für Module:** Eine Property ist kein Ort für ein Geheimnis. Eine Verschleierung mit einem Schlüssel, der aus der Instanz-ID abgeleitet ist, nützt nichts, weil die ID im selben Dokument steht.
- **Muster:**
  - Geheimnisse in einen selbst verwalteten, verschlüsselten Bestand überführen und die Property danach leeren.
  - Machbar mit Bordmitteln (geprüft in Symcons PHP 8.5): `sodium_crypto_aead_xchacha20poly1305_ietf_*` oder AES-256-GCM; Schlüssel als eigene Datei im Kernel-Verzeichnis mit Modus 0600 (`umask(0077)` vor dem ersten Schreiben); als Zusatzdaten `InstanzID|Feldname`, damit vertauschte Felder nicht entschlüsseln.
  - Grenze offen benennen: Ein unbeaufsichtigter Dienst muss selbst entschlüsseln können; gegen Root-Leserechte hilft das nicht. Es schützt gegen das Realistische: lesbare `settings.json`, Sicherungen, Support-Exporte, Cloud-Ordner.

## Flüchtige Geheimnisse gehören in den Puffer

- **Doku:** `SetBuffer`/`GetBuffer` halten Daten nur, solange Symcon läuft.
- **Gemessen (06.10.2026, Symcon 9.1):** Puffer gelten über Aufrufe hinweg und landen **nicht** in `settings.json`.
- **Muster:** Kopplungscodes, Sitzungsschlüssel und Ähnliches in Puffer statt Attribute. Lesen-Ändern-Schreiben auf einem Puffer (z. B. Versuchszähler) unter `IPS_SemaphoreEnter`. Größengrenzen siehe [hooks-und-grenzen.md](hooks-und-grenzen.md).

## Attribute

- **Doku:** Geänderte Attribute sind im Gegensatz zu Properties **sofort** verfügbar; `ReadAttribute*` geht nicht in `Create()`.
- **Gemessen:** Attribute queren Objekt- und Thread-Grenzen derselben Instanz zuverlässig (siehe [instanzen-und-nebenlaeufigkeit.md](instanzen-und-nebenlaeufigkeit.md)) und eignen sich deshalb als Wächter bei Migrationen.
- Schreiben auf ein noch nicht registriertes Attribut warnt nur; siehe [module-strict-und-php.md](module-strict-und-php.md).

---

Stand: geprüft am 08.10.2026 (Doku und Repo-Code); Messwerte mit Datum

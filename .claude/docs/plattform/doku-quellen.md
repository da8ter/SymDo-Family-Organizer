# Symcon-Doku: Quellen und Umgang

## Doku für KI-Agenten und Entwickler (llms.txt)

Symcon erzeugt die gesamte Dokumentation bei jedem Build als Markdown nach dem llms.txt-Standard. Jede Datei nennt Originalseite und Erzeugungsdatum.

- Index: <https://www.symcon.de/de/llms.txt> (englisch: <https://www.symcon.de/en/llms.txt>)
- Funktionsindex mit allen Signaturen: <https://www.symcon.de/de/llms/function-index.md>. Ohne `llms/` im Pfad gibt es 404.
- Alles in einer Datei: <https://www.symcon.de/de/llms-full.txt> (sehr groß, nur für Wissensdatenbanken).
- Übersichtsseite: <https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/ki-agenten/>

Für Modulentwicklung (Präfix `https://www.symcon.de/de/llms/`):

| Datei | Inhalt |
|---|---|
| `developer/sdk-tools/sdk-php.md` | SDK, Aktionen, Bibliotheken, Darstellungen, HTML-SDK, Konstanten |
| `developer/sdk-tools/sdk-php/module.md` | Modul-Methoden (Create, RegisterHook, Migrate, …) |
| `developer/sdk-tools/sdk-php/configuration-forms.md` | Konfigurationsformulare |
| `developer/sdk-tools/sdk-php/configuration-forms/list.md` | Listen, `save`, Dialoge |
| `developer/limitations.md` | Grenzen (Puffer, Strings, Threads) |
| `developer/special-switches.md` | Spezialschalter (`ScriptOutputBufferLimit` u. a.) |
| `components/tile-visualization.md` | Kachel-Visualisierung |
| `components/object-presentation.md` | Objekt-Darstellung |
| `functions/management-modules.md` | Modulverwaltung |
| `getting-started/migrationen.md` | Neuerungen je Version |

Der Zugriff per `curl -sL <adresse>` genügt.

## Das Handbuch ist die Referenz, nicht der Modulbestand

- **Was:** Dass kein installiertes Modul ein Element oder eine Funktion nutzt, beweist nicht, dass es sie nicht gibt. Beispiel: `HorizontalSlider` in Konfigurationsformularen wurde übersehen, weil keins von über 100 installierten Modulen ihn verwendete.
- **Muster:** Vor jeder Aussage „Symcon kann X nicht“ die passende Handbuchseite abrufen. Der Bestand taugt für Hausstil und Muster, nicht als Beleg für den Funktionsumfang.

## Doku und Messung

- Widerspricht eine eigene Messung der Doku, gilt für den Code die Messung; die Doku-Stelle wird trotzdem genannt. Beispiele in dieser Sammlung: Puffergrenze (512 statt 1024 kB), Icon-Schnitte (nur `fa-light` verlässlich), Aufruf von `Create()` beim Modul-Reload.
- Umgekehrt korrigiert die Doku auch Annahmen: Einen Migrations-Hook gibt es (`Migrate()`, ab 7.0), siehe [module-lebenszyklus.md](module-lebenszyklus.md).
- Nicht im Funktionsindex steht `MC_ReloadModule` (geprüft 08.10.2026); die Angaben dazu beruhen auf Messungen.
- Builds derselben Version unterscheiden sich mitunter im Verhalten. `IPS_GetKernelVersion()` sagt nur „9.1“; zum Vergleich `IPS_GetKernelRevision()` und `IPS_GetKernelDate()` mitschreiben.
- Zahlenwerte von Konstanten nie raten, siehe [variablen-und-darstellungen.md](variablen-und-darstellungen.md).

## Übersicht dieser Sammlung

- [module-lebenszyklus.md](module-lebenszyklus.md): Create, ApplyChanges, Kernelstart, Reload, Properties, Migration, Status
- [module-strict-und-php.md](module-strict-und-php.md): IPSModuleStrict, Signaturen, HEX-Datenfluss, Warnungen statt Exceptions
- [variablen-und-darstellungen.md](variablen-und-darstellungen.md): Werte, Idents, Darstellungen, Konstanten
- [instanzen-und-nebenlaeufigkeit.md](instanzen-und-nebenlaeufigkeit.md): Serialisierung, Objektgrenzen, Datenfluss-Eltern, Referenzen
- [timer.md](timer.md): Timer-Fallen
- [hooks-und-grenzen.md](hooks-und-grenzen.md): RegisterHook, HookInstance-Fatal, Ausgabegrenze
- [kachel-visu.md](kachel-visu.md): HTML-SDK, Vollbild, Ränder, Icons
- [formulare.md](formulare.md): Listen, onClick, UpdateFormField
- [daten-und-sicherheit.md](daten-und-sicherheit.md): settings.json, Geheimnisse, Puffer
- [kachel-nachrichten.md](kachel-nachrichten.md): Zustellung von `UpdateVisualizationValue` an Kacheln
- [webrtc-sicherer-kontext.md](webrtc-sicherer-kontext.md): Mikrofon und WebRTC nur im sicheren Kontext

---

Stand: geprüft am 08.10.2026 (Doku und Repo-Code); Messwerte mit Datum

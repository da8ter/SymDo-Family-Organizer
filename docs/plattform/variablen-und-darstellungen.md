# Variablen, Idents, Darstellungen und Konstanten

## Werte schreiben und lesen (gemessen 25.09.2026, Symcon 9.1)

- `SetValueInteger` auf eine Float-Variable (jede Typ-Kreuzung): `false` plus Warnung „Variablentyp stimmt nicht überein“, der Wert bleibt. Gleiches für `GetValue<Typ>`.
- `SetValue` mit anderem PHP-Typ rechnet um: Floats werden **gerundet** (23.5 → 24, auch `SetValueInteger(3.7)` → 4), `'false'` auf Boolean → false, `null` → 0. Ein Array → `false` plus „Cannot auto-convert value …“.
- **Folge:** Ganzzahl-Variablen nicht mit Kommawerten beschreiben, wenn Abschneiden erwartet wird; vorher selbst runden oder abschneiden.

## Idents

- Erlaubt sind Buchstaben, Ziffern und Unterstrich; eine führende Ziffer ist erlaubt. Je Ebene muss ein Ident eindeutig sein (gemessen 25.09.2026, Symcon 9.1).
- `IPS_GetObjectIDByIdent` ohne Treffer: `false` plus Warnung, keine Exception. Muster: `@IPS_GetObjectIDByIdent(...)` und auf `is_int($id) && $id > 0` prüfen.
- Modul-Timer haben **keinen** Ident (siehe [timer.md](timer.md)).

## Darstellungen statt Profile

- **Hausregel:** Modul-eigene Variablen bekommen ausschließlich Darstellungs-Arrays über `RegisterVariable*`/`MaintainVariable`, keine Variablenprofile.
- `MaintainVariable` aktualisiert die Darstellung einer **bestehenden** Variable; Name und Position bleiben (gemessen 01.10.2026, Symcon 9.1).
- `IPS_SetVariableCustomPresentation` ist laut Doku die Nutzerebene für bestehende Variablen per Skript, nicht der Weg für Module.

### Strenge Prüfung der Parameter (gemessen 01.10.2026, Symcon 9.1)

- Passt ein Parameter nicht zum Variablentyp, lehnt `IPS_SetVariableCustomPresentation` den **ganzen** Aufruf ab („Der Parameter X ist für diese Darstellung nicht definiert“). Ältere 9.1-Builds nahmen fremde Schlüssel noch an.
- Erlaubt bei der Wertanzeige (`VARIABLE_PRESENTATION_VALUE_PRESENTATION`), deckungsgleich mit der Doku:
  - alle Typen: `ICON`, `COLOR`, `PREFIX`, `SUFFIX`, dazu gemessen `PERCENTAGE`, `USAGE_TYPE`
  - Integer/Float: `DIGITS`, `DECIMAL_SEPARATOR`, `THOUSANDS_SEPARATOR`, `MIN`, `MAX`, `INTERVALS_ACTIVE`, `INTERVALS`
  - String: `MULTILINE`, `OPTIONS`; Boolean: `OPTIONS`
- `OPTIONS` und `INTERVALS` immer als **JSON-String**, nie als Array (Array → „Type is not supported“).
- Integer-Variablen nehmen `MIN`/`MAX` nur als `int` („falscher Typ“ bei Float).
- Optionen der Wertanzeige kennen nur `Value`, `Caption`, `IconActive`, `IconValue`, `ColorActive`, `ColorValue`, `ContentColorActive`, `ContentColorValue`; andere Schlüssel (`Color`, `ColorDisplay`) werden abgelehnt.
- Der Schieberegler nimmt kein `COLOR`.

## Konstanten nicht raten

- **Was:** Zahlenwerte von Symcon-Konstanten in Testattrappen nachzubilden ist gefährlich. Ein geratenes `MEDIATYPE_DOCUMENT = 2` lässt Tests grün laufen, weil derselbe falsche Wert beim Anlegen und beim Vergleichen benutzt wird.
- **Richtige Werte laut Doku** (auch gemessen): `MEDIATYPE_IPSVIEW` 0, `MEDIATYPE_IMAGE` 1, `MEDIATYPE_SOUND` 2, `MEDIATYPE_STREAM` 3, `MEDIATYPE_CHART` 4, `MEDIATYPE_DOCUMENT` 5.
- Weitere gemessene Werte (25.09.2026): `VARIABLE_PRESENTATION_ENUMERATION` = `{52D9E126-D7D2-2CBB-5E62-4CF7BA7C5D82}`, `KL_CUSTOM` 10207, `IS_NOTCREATED` 105, `KR_INIT` 10102, `KR_READY` 10103.
- **Muster:** Produktionscode nutzt immer die Konstanten. Attrappen übernehmen Werte aus der Doku oder einer Messung, nicht aus dem Gedächtnis. Dateitypen möglichst an Magic Bytes erkennen (z. B. `%PDF-`) statt an Metadaten.

## Fehlertexte (gemessen 25.09.2026, Symcon 9.1)

Hilfreich beim Lesen von Logs: „Objekt/Instanz/Variable #X existiert nicht“, „Eigenschaft X nicht gefunden“, „Objekt mit Ident X wurde nicht gefunden“, „Ident muss für jede Ebene eindeutig sein“, „Profil mit dem Namen X existiert nicht“.

## Größen-Grenzen laut Doku

- String-Variable: ab 1024 kB Fehler, Inhalt wird nicht geschrieben; empfohlen unter 8 kB, größere Daten auslagern.
- `AC_*`-Abfragen liefern höchstens 10 000 Zeilen, ohne Warnung.

---

Stand: geprüft am 08.10.2026 (Doku und Repo-Code); Messwerte mit Datum

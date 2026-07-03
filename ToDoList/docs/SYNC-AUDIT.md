# ToDoList — Sync-Audit (Microsoft To Do, Google Tasks, CalDAV)

**Stand:** 2026-07-01 · **Re-Audit:** 2026-07-02 (siehe Abschnitt „Re-Audit 2026-07-02" am Ende — enthält Korrekturen zu B5/A6/A3-CalDAV und neue kritische Befunde)
**Umfang:** `libs/MicrosoftToDoSync.php`, `libs/GoogleTasksSync.php`, `libs/CalDAVSync.php`, `libs/SyncHelper.php`, `libs/OAuthHelper.php`, sync-relevante Teile von `module.php` sowie `ToDoGateway/module.php` + `ToDoGateway/libs/OAuthHelper.php`.
**Methodik:** Implementierung kartiert → gegen offizielle APIs/RFCs abgeglichen (Microsoft Graph v1.0 To Do, Google Tasks API v1, RFC 4791/6578/5545/4918, OAuth 2.0 RFC 6749) → jeder Befund adversarial gegen den aktuellen Code verifiziert. 44 verifizierte Befunde.

> Google-Befunde: gegen den Code verifiziert + bekanntes API-Verhalten; im Recherche-Log wurden dort keine URLs erfasst. Microsoft/CalDAV/OAuth: gegen live abgerufene Docs/RFCs geprüft.

---

## Gesamtbild

Alle drei Backends funktionieren im Happy Path, teilen aber dieselben strukturellen Schwächen: **kein inkrementeller Sync, kein Throttling-Handling, keine ETag/If-Match-Nebenläufigkeitskontrolle** (ETags werden gespeichert, aber nie gesendet), und eine Konfliktauflösung, die lokale und Server-Uhren direkt vergleicht. Diese Querschnittsthemen wiegen schwerer als die Einzel-Bugs.

---

## A. Querschnittliche Befunde (höchste Priorität)

### A1 — Kein inkrementeller Sync (Voll-Fetch jedes Mal) · HOCH
Jeder Timer-Tick (bis zu alle 5 Min) lädt/parst die komplette Liste.
- Microsoft: `MicrosoftFetchTasks` (`libs/MicrosoftToDoSync.php:210`) nutzt `/tasks?$top=100` statt `/tasks/delta` + `@odata.deltaLink`.
- Google: `GoogleFetchTasks` (`libs/GoogleTasksSync.php:230`) ohne `updatedMin`; `GoogleLastSync` wird geschrieben, aber nie verwendet.
- CalDAV: `CalDAVFetchItems` (`libs/CalDAVSync.php:218`) macht vollen `calendar-query` REPORT ohne `sync-token` (RFC 6578)/`getctag`; `CalDAVSyncToken` ist registriert, aber ungenutzt.
- **Fix:** MS `/tasks/delta` + deltaLink persistieren (+ `@removed`); Google `updatedMin` + periodischer Voll-Reconcile; CalDAV `sync-collection` (RFC 6578) oder mind. `getctag`-Vorabprüfung. **Voraussetzung: A6 zuerst.**

### A2 — Kein 429/Retry-After/Backoff · KRITISCH (MS/Google), HOCH
`OAuthHttpRequest` (`ToDoGateway/libs/OAuthHelper.php:58`) gibt nur den Body zurück, verwirft Status + `Retry-After`. 429 → `null` → Lauf bricht ab, fester Timer hämmert im nächsten Tick erneut → verlängert Throttling. Das status-fähige `OAuthHttpRequestMeta` (`:101`) existiert, wird auf API-Pfaden aber nicht genutzt.
- **Fix:** API-Pfade auf `OAuthHttpRequestMeta` umstellen; bei 429/503/504 `Retry-After` respektieren, Timer auf `max(Retry-After, Intervall)` setzen, transiente vs. permanente Fehler unterscheiden.

### A3 — Keine ETag/If-Match-Nebenläufigkeitskontrolle (Lost Updates) · HOCH
ETags werden gespeichert, aber nie als `If-Match` gesendet:
- MS PATCH/DELETE `libs/MicrosoftToDoSync.php:833/848`.
- Google PATCH/DELETE `libs/GoogleTasksSync.php:476/491`.
- CalDAV DELETE ohne If-Match `libs/CalDAVSync.php:582` (Upload nutzt es, `:566`).
- **Fix:** Header-Parameter durch den Transport, `If-Match:{etag}` bei PATCH/DELETE, `412` als Konflikt über `ConflictMode`.

### A4 — OAuth: 401 löst keinen Refresh+Retry aus · KRITISCH
`OAuthGetValidAccessToken` (`ToDoGateway/libs/OAuthHelper.php:266`) refresht nur zeitbasiert. Vor Ablauf serverseitig invalidiertes Token → jeder Call still `null` bis zum Ablauf (~1 h), ohne Fehler und ohne Erholung im Lauf.
- Zusatz (HOCH): `invalid_grant` beim Refresh (`:227`) nicht von transienten Fehlern unterschieden; Tokens nicht gelöscht, `IsConnected` prüft nur „Refresh-Token nicht leer" → UI zeigt dauerhaft „Connected" trotz toter Verbindung.
- Zusatz (MITTEL): `expires == 0`-Guard (`:269` `$expires > 0 && time() >= $expires`) deaktiviert Refresh bei frischem/migriertem Zustand.
- **Fix:** Bei 401 erzwungener Refresh + 1 Retry; `invalid_grant` → Tokens löschen + `ReauthorizationRequired`-Flag (sichtbar in `IsConnected`/Formular); Refresh auch bei `expires <= 0`.

### A5 — `newest_wins` vergleicht lokale `time()` gegen Server-Zeitstempel · HOCH
`localModified` = Symcon-Hostuhr (`module.php:533` u. a.) vs. Server-`lastModified`/`updated` (MS `:683`, Google `:374`, CalDAV `:516`). Ohne strikte NTP verliert eine echt neuere Server-Änderung ggf. gegen eine ältere lokale (und umgekehrt). CalDAVs `>=` kippt Gleichstände zu lokal.
- **Fix:** Gleiche Uhren vergleichen — Server-Zeitstempel des letzten Syncs speichern und Server gegen Server prüfen; lokal gegen lokalen Last-Synced-Wert.

### A6 — Löschung aus „fehlt in der Voll-Liste" abgeleitet · HOCH
Google/MS markieren ein Item als serverseitig gelöscht, sobald die ID in der Liste fehlt (Google `:354`, MS `:656`). Jeder unvollständige Fetch kann lokale Tasks permanent löschen — und der Ansatz ist inkompatibel mit A1 (Delta würde alle unveränderten löschen).
- **Fix:** Löschen nur auf autoritatives Signal — Google `deleted=true` verarbeiten, MS Delta-`@removed`; reine Abwesenheit als „unbekannt" behandeln bzw. hinter zwei vollständigen Fetches gaten.

---

## B. Microsoft To Do

| # | Sev | Befund | Ort |
|---|-----|--------|-----|
| B1 | MITTEL | Recurrence-Verlust: Parse liest nur `type`+`interval`, ignoriert `daysOfWeek` und relativeMonthly/Yearly → „Mo+Mi+Fr"/„2. Dienstag" degradiert und beim Push überschrieben | `libs/MicrosoftToDoSync.php:431` |
| B2 | MITTEL | Reminder/Due immer in UTC-Wall-Clock; To-Do-Reminder sind lokal → falsche Uhrzeit über DST/Offset | `:235` |
| B3 | MITTEL (PLAUSIBLE) | Recurring + `status:completed` im selben Body → ggf. ganze Serie abgeschlossen statt einer Instanz | `:562` |
| B4 | MITTEL | Listen-Fetch nicht paginiert → Überlauf-Listen fehlen im Selektor | `:43` |
| B5 | MITTEL | nextLink-Slicing per `strpos('/v1.0')` bricht bei National Clouds → stille Trunkierung | `:222` |
| B6 | NIEDRIG | Windows→IANA nur ~37 Einträge, Unbekanntes → still UTC. Besser `IntlTimeZone::getIDForWindowsID()` | `:268` |
| B7 | NIEDRIG | Kein `$select` → volle Payload bei jedem Fetch | `:213` |

---

## C. Google Tasks

| # | Sev | Befund | Ort |
|---|-----|--------|-----|
| C1 | HOCH | `showDeleted=true` lädt alle Tombstones bei jedem Sync — und verwirft sie sofort; unbegrenzt wachsend | `libs/GoogleTasksSync.php:236/247` |
| C2 | MITTEL | PATCH lässt `due` weg bei lokal `due=0` → Fälligkeitsdatum lässt sich nie löschen, taucht wieder auf. Fix: `'due' => null` | `:305` |
| C3 | MITTEL (PLAUSIBLE) | Due date-only (spec-bedingt); Rekonstruktion UTC-Mitternacht + lokale Zeit kann Tag verschieben (Negativ-Offset) | `:271` |
| C4 | NIEDRIG | Listen-Fetch nicht paginiert (nur ~20) | `:65` |
| C5 | NIEDRIG | Subtasks (`parent`) und Reihenfolge (`position`) werden nie gelesen → flache Liste | `:260` |
| C6 | NIEDRIG | `GooglePendingDeletes` pro Iteration neu dekodiert (MS macht es einmal) | `:406` |

---

## D. CalDAV

| # | Sev | Befund | Ort |
|---|-----|--------|-----|
| D1 | HOCH | PUT (Create) ohne `If-None-Match: *` → existierende Ressource still überschrieben (Lost Update) | `libs/CalDAVSync.php:547` |
| D2 | HOCH | `DUE;TZID=...` ohne VTIMEZONE → RFC-5545-invalides .ics bei Nicht-UTC. Fix: UTC-Z-Form | `:659` |
| D3 | MITTEL | DUE nie als `VALUE=DATE` → Ganztages-Drift ±1 Tag über Zeitzonen | `:659` |
| D4 | MITTEL | Keine Zeilenfaltung bei 75 Oktetts → nicht-konforme lange SUMMARY/DESCRIPTION | `:595` |
| D5 | MITTEL | STATUS/PERCENT-COMPLETE lossy — IN-PROCESS/CANCELLED → NEEDS-ACTION; Fortschritt anderer Clients verworfen | `:342` |
| D6 | NIEDRIG | PRIORITY-Round-Trip lossy (3→1, 5→undefiniert) | `:344` |
| D7 | NIEDRIG | DELETE ohne If-Match (A3); doppelte `caldavUid` still (last-wins) + toter `localByUid`-Map | `:582` / `:408` |

---

## E. Sicherheit & Sonstiges

- **Token-„Verschlüsselung" ist nur XOR** (`ToDoGateway/libs/OAuthHelper.php:7`): `base64(token)` XOR `'TDL_'+InstanceID+prefix`. Schlüssel nicht geheim → jeder mit Datei-/Backup-Zugriff kann live Refresh-Tokens extrahieren. Empfehlung: Klartext in Attributen (IPS-Store als Trust-Boundary dokumentieren) **oder** echte AEAD (OpenSSL/libsodium) mit nicht-ableitbarem Schlüssel.
- **Timeouts:** fester 30s/15s-Read-Timeout, kein Connect-Timeout, kein Retry (`OAuthHelper.php:80`).
- **On-Change-Debounce:** Änderung während laufendem MS/Google-Sync kann bis zum nächsten Intervall stranden (kein Trailing-Re-Arm wie bei CalDAV) (`module.php` SyncOnChange).

---

## Priorisierte Roadmap

1. **A4** — OAuth 401→Refresh→Retry + `invalid_grant`-Erkennung + `expires<=0` (Gateway, klein abgegrenzt).
2. **A2** — 429/Retry-After/Backoff über vorhandenes `OAuthHttpRequestMeta`.
3. **A3** — If-Match/ETag durch Transport + alle Backends.
4. **A5 + A6** — Konflikt-Uhren + autoritatives Löschsignal (A6 ist Voraussetzung für A1).
5. **A1** — Inkrementeller Sync (größter Umbau, erst nach A6).
6. Einzelbugs: **C2** (due-Löschen), **D1/D2** (CalDAV-Konformität), **B1** (MS-Recurrence).

---

## Umsetzungsstatus

- [x] **A4 — OAuth-Refresh-Robustheit** (2026-07-01, `ToDoGateway`)
  - `OAuthGetValidAccessToken`: refresht auch bei `expires<=0` (wenn Refresh-Token vorhanden).
  - `OAuthRefreshToken`: nutzt `OAuthHttpRequestMeta`; `invalid_grant` (HTTP 400/401) → Tokens löschen (→ `IsConnected` = false) + Warnung ins Meldungs-Log; 5xx/Netz = transient, Tokens bleiben; `invalid_client`/`unauthorized_client` löschen NICHT (Secret-Problem).
  - Neu `OAuthAuthorizedRequest`: 401 → erzwungener Refresh → **ein** Retry. `Google-/MicrosoftApiRequest` nutzen es.
- [x] **A2 — Throttling/Backoff** (Gateway) — 429/503/504 setzen ein Retry-After-Fenster pro Backend (`{Google,Microsoft,CalDAV}RetryAfter`); während des Fensters kurzschließt das Gateway alle Aufrufe (kein Netz), egal wie oft die Timer feuern. Retry-After honoriert, sonst 60 s Default, Cap 1 h.
- [x] **A3 — ETag/If-Match** (Updates) — `If-Match:{etag}` bei PATCH (MS/Google) bzw. PUT-Update (CalDAV); `If-None-Match:*` beim CalDAV-Create (D1); im local-wins-Konflikt wird der Server-ETag übernommen, damit die bedingte Schreiboperation nicht dauerhaft 412 wirft. 412 = Schreiben schlägt fehl (kein Clobber) → nächster Sync reconciled. **DELETE-If-Match** ebenfalls umgesetzt (siehe Einzelbugs unten).
- [x] **A5 — Clock-Skew** — MS/Google speichern eine Server-Zeitstempel-Baseline (`*ServerSynced`) und prüfen `serverChanged` server-gegen-server (Upload-Response-Zeitstempel wird erfasst). CalDAV nutzt bereits ETag-Vergleich (clock-unabhängig). *Rest:* `newest_wins`-Gewinnervergleich bleibt cross-clock (dokumentiert).
- [x] **A6 — Löschsignal** — Google: `deleted=true` ist jetzt das autoritative Löschsignal (Tombstones werden nicht mehr importiert). MS/CalDAV: an A1-Delta bzw. sync-token gekoppelt. **⚠️ Re-Audit 02.07.: überzeichnet — MS parst `@removed` nirgends, CalDAV macht kein sync-collection REPORT; Löschung bleibt bei beiden absence-basiert innerhalb des Voll-Fetch (R11).**
- [x] **A1 — Inkrementeller Sync** — alle drei Backends nach dem sicheren **Probe-dann-Voll**-Muster (Änderungssignal skippt nur den Leerlauf; jede echte Änderung → voller, löschsicherer Merge):
  - **CalDAV:** CTag-Kurzschluss (`getctag`/`sync-token` per Depth:0-PROPFIND). Token in `CalDAVSyncToken`.
  - **Google:** `updatedMin`-Probe mit Server-Cursor (`GoogleSyncCursor`).
  - **Microsoft:** `/tasks/delta`-Probe (nur als Änderungssignal!) mit Cursor `MicrosoftDeltaLink`; Idle → kleiner Delta-Aufruf + Skip, sonst bestehender Voll-Fetch + Merge. Abgelaufener deltaLink (410) startet transparent neu; Gateway folgt `@odata.deltaLink`/`nextLink` als Absolut-URL verbatim (behebt zugleich B5). **⚠️ Re-Audit 02.07.: gilt nur für die Delta-Probe — `MicrosoftFetchTasks` slict den nextLink weiterhin, mit Off-by-one → Sync bricht bei Listen >100 Tasks komplett (R1).** Delta treibt **nie** einen partiellen Merge → kein Lösch-/Duplikat-Risiko.

**Einzelbugs behoben (2026-07-01):**
- [x] **C2 — Google due löschbar** (`LocalToGoogleTask`): entfernte Fälligkeit wird beim PATCH bestehender Tasks explizit als `due => null` gesendet (neue Tasks lassen `due` weg). Kein „reappear" mehr.
- [x] **D2 — CalDAV valides iCal** (`CalDAVBuildVTodo`): `DUE` immer als UTC-Z-Form statt `DUE;TZID=` ohne VTIMEZONE (RFC-5545-konform).
- [x] **B1 — MS-Recurrence-Verlust** (`MicrosoftToDoSync`): die rohe Server-`recurrence` wird pro Item gespeichert (`microsoftRecurrenceRaw`) und beim Push **unverändert** zurückgesendet, wenn der Nutzer sie lokal nicht geändert hat (Fälligkeit + Muster gleich) → „Mo+Mi+Fr"/„2. Dienstag" bleiben erhalten statt überschrieben; Parse für `relativeMonthly/relativeYearly` ergänzt.
- [x] **DELETE-If-Match** (A3-Rest, alle 3 Backends): Neue Gateway-Methoden `MicrosoftApiStatus`/`GoogleApiStatus` reichen den HTTP-Status durch (CalDAV hatte ihn schon). Der Tombstone trägt jetzt den ETag (MS/Google als Wert im geteilten Helper; CalDAV als JSON `{href,etag}`, mit Legacy-Fallback). `*DeleteTask`/`CalDAVDeleteItem` senden `If-Match` und werten den Status aus: **2xx/404 → gelöscht**, **412 → Konflikt: Tombstone verwerfen** (Server-Version überlebt, wird beim nächsten Voll-Sync reimportiert — **keine** Endlos-Retry-Schleife), **0/5xx → transient, Retry**. Legacy-Tombstones (`'1'`/plain-href) löschen unbedingt wie bisher.
- Offen (niedriger): D3 (VALUE=DATE Ganztag), D4 (Zeilenfaltung 75 Oktett), D5/D6 (STATUS/PRIORITY lossy), C5 (Subtasks), B2/B6 (Reminder-/TZ-UTC), B4/C4 (Listen-Pagination), E (Token-XOR, Timeouts).

**Adversariale A1-Prüfung (3 Agenten) — Befunde behoben:**
- **CalDAV TOCTOU (HIGH):** Eine Fremdänderung *während* des Sync-Fensters wurde vom Post-Sync-CTag „mitgespeichert", aber nie gemergt → dauerhaft übersprungen. Fix: CTag nur speichern, wenn er sich über den ganzen Lauf **nicht** geändert hat (sonst `''` → nächster Lauf voller REPORT).
- **Same-Second-Edit (MEDIUM):** Ein Edit in derselben Sekunde wie der letzte Sync (`localModified == *Synced`) wurde durch striktes `>` dauerhaft nie hochgeladen. Fix: `>=` (mit `>0`-Guard) in allen `HasPendingWork`-Gattern **und** der Merge-Änderungserkennung (MS/Google/CalDAV).
- **MS leeres-200 (LOW):** Skip nur noch, wenn die Delta-Probe einen gültigen deltaLink lieferte.

> ⚠️ **A1/A3 vor Produktiveinsatz gegen echte Konten testen.** A3 sendet erstmals `If-Match` (falls ein Provider das anders handhabt als erwartet, können Updates 412 liefern); A1 verändert die Fetch-Strategie. A2/A5/A6 sind risikoärmer.

---

# Re-Audit 2026-07-02

**Methodik:** Vier parallele adversariale Prüf-Läufe (Microsoft, Google, CalDAV, OAuth/Gateway). Jeder als umgesetzt markierte Fix wurde gegen den aktuellen Code verifiziert und mit den offiziellen Quellen abgeglichen: Microsoft Graph v1.0 (todotask-delta, -update, -delete, delta-query-overview, throttling), Google Tasks API v1 (tasks.list/patch/delete, tasklists.list), RFC 4791/6578/5545/4918, caldav-ctag.txt, RFC 6749 §5.2 sowie MS-Identity-Platform- und Google-OAuth-Doku. Alle KRITISCH-Befunde wurden zusätzlich manuell am Code bestätigt; MS-nextLink-Bug und CalDAV-Escaping wurden empirisch (PHP) nachgestellt.

## Fix-Verifikation (Kurzfassung)

| Fix | Microsoft | Google | CalDAV | Gateway/OAuth |
|---|---|---|---|---|
| A1 inkrementell | Probe ✅, aber Voll-Fetch **defekt** (R1) | **wirkungslos** — Boundary-Echo (R6) | ✅ CTag-Skip + TOCTOU-Fix | — |
| A2 Throttling | ✅ | ✅ (Lücke: 403-Quota, R12) | ✅ | ✅ Kurzschluss ohne Netz-I/O verifiziert |
| A3 If-Match (PATCH/PUT) | ✅ codeseitig¹ | ✅ codeseitig¹ | **PARTIAL** — kein ETag-Adopt im Konflikt (R7) | Status-Durchreichung ✅ |
| A3 DELETE-If-Match | ✅ | ✅ | ✅ (Legacy-`'1'`-Randfall, s. NIEDRIG) | ✅ |
| A4 OAuth | — | — | — | ✅ alle Teilpunkte: 401-Retry nutzt nachweislich das NEUE Token; `invalid_grant` nur bei 400/401 **plus** JSON-`error`-Feld (RFC-konform, transientes 400 löscht nichts); `expires<=0`-Refresh ✅ |
| A5 Server-Baseline | ✅ | ✅ | ✅ (ETag-basiert) | — |
| A6 Löschsignal | **PARTIAL** — `@removed` nie geparst (R11) | ✅ (Vorbehalte R13) | überzeichnet — kein sync-collection, absence-basiert | — |
| C2 `due => null` | — | ✅ | — | — |
| D2 DUE UTC-Z | — | — | ✅ | — |
| B1 Recurrence-Raw | ✅ (TZ-Randfall R21) | — | — | — |
| Same-Second `>=` | PARTIAL — Titel-Adopt-Zweig vergessen (`:767`, strikt `>`) | ✅ | ✅ | — |

¹ **If-Match/412 ist weder für Graph To Do noch für Google Tasks dokumentiert** (die Update-/Delete-Referenzen listen den Header nicht: [todotask-update](https://learn.microsoft.com/en-us/graph/api/todotask-update?view=graph-rest-1.0), [tasks.patch](https://developers.google.com/workspace/tasks/reference/rest/v1/tasks/patch)). MS degradiert schlimmstenfalls stumm zu unbedingten Writes (kein Crash); bei Google wäre eine 400-Ablehnung fatal (jeder PATCH scheitert → lokale Edits erreichen den Server nie, Dauer-Retry). ⇒ **Der Live-Test-Vorbehalt aus dem Erst-Audit bleibt zwingend.**

## Korrekturen am Umsetzungsstatus vom 01.07.

- **B5 „behoben" ist falsch:** Nur die Delta-Probe reicht den `nextLink` verbatim durch (`MicrosoftToDoSync.php:277`). `MicrosoftFetchTasks` slict weiterhin per `strpos('/v1.0')` — und mit Off-by-one (R1).
- **A6-MS überzeichnet:** `@odata.removed` wird nirgends verarbeitet (0 Code-Treffer); Löschung bleibt absence-basiert. Derzeit abgesichert nur dadurch, dass ein fehlgeschlagener Voll-Fetch `null` liefert — der latente Trunkierungspfad (`:327-329` `break` statt Fehler) ist genau die im Erst-Audit gewarnte Massenlösch-Kette (R11).
- **A6/A1-CalDAV:** kein echtes RFC-6578 sync-collection; der CTag ist nur ein Skip-Signal, Löschung absence-basiert innerhalb des vollen REPORT (funktional ok, Formulierung „an sync-token gekoppelt" suggeriert mehr).
- **A3-CalDAV:** die behauptete Server-ETag-Übernahme im local-wins-Konflikt existiert nur bei MS/Google — bei CalDAV fehlt sie (R7).
- **C4-Prämisse falsch:** tasklists.list-Default = Max = **1000** (nicht ~20, [Doku](https://developers.google.com/workspace/tasks/reference/rest/v1/tasklists/list)) — Pagination erst ab >1000 Listen relevant.
- **C3 ist inzwischen behoben:** Regex-Zweig `GoogleTasksSync.php:326-331` mappt `T00:00:00Z` auf lokale Mitternacht desselben Kalendertags (kein Tages-Shift mehr); Round-Trip konsistent.

## Neue Befunde — KRITISCH

| # | Backend | Befund | Ort |
|---|---|---|---|
| R1 | MS | **nextLink-Slicing mit Off-by-one:** `substr($next, $pos + 4)` bei 5-Zeichen-Needle `'/v1.0'` → Endpoint `0/me/todo/…` → Gateway baut `…/v1.00/…` → Seite-2-Request scheitert immer → Fetch `null` → **jeder Sync mit Änderungen schlägt fehl, sobald die Liste >100 Tasks hat** ($top=100). Besonders tückisch: Idle-Läufe (Probe meldet „keine Änderung") liefern weiter `true` und aktualisieren `MicrosoftLastSync` — der Status wirkt gesund. Empirisch verifiziert. Fix: nextLink verbatim durchreichen wie in der Probe; zusätzlich `break` bei `$pos === false` (`:327-329`) durch `return null` ersetzen (sonst stille Trunkierung → Massenlöschung, R11) | `libs/MicrosoftToDoSync.php:326-330` |
| R2 | CalDAV | **XML-Parse-Fehler = „leerer Kalender":** `simplexml_load_string` schlägt fehl → `[]` wird als gültiges Ergebnis zurückgegeben; der Merge löscht dann jedes lokale Item mit `caldavSynced>0`, das „fehlt". Ein kaputtes/trunkiertes/falsch-encodiertes 207 (Proxy, Server-Bug, Steuerzeichen in DESCRIPTION) **löscht alle synchronisierten Tasks lokal in einem Lauf**. Fix: Parse-Fehler → `null` (Lauf abbrechen) | `libs/CalDAVSync.php:340-343` + Löschpfad `:515-517` |
| R3 | CalDAV | **UID-Divergenz bei neuen Items:** Merge vergibt und speichert `symcon-<inst>-<id>` als UID (`:506-511`), aber `CalDAVBuildVTodo` regeneriert für jede `symcon-`-UID eine **Zufalls-UUID** im .ics (`:698-708`), während die PUT-URL den symcon-Namen nutzt. Server-UID ≠ lokal gespeicherte UID → nächster voller REPORT findet die lokale UID nicht → Item wird als server-gelöscht verworfen (`:515-517`), die Server-Kopie als **neues** Item reimportiert → Verlust aller lokalen Felder (Benachrichtigung, Vorlaufzeit, Wiederholung, Menge) + ID-Churn — **bei jedem lokal neu angelegten Task**. Fix: gespeicherte UID unverändert ins VTODO schreiben | `libs/CalDAVSync.php:506-511, 642, 698-708` |

## Neue Befunde — HOCH

| # | Backend | Befund | Ort |
|---|---|---|---|
| R4 | MS + Google | **`pending_`-Strandung:** Schlägt der POST eines neuen Tasks fehl (Throttle-Fenster, Timeout, 5xx), bleibt die im Merge per Referenz zugewiesene `pending_…`-ID persistiert. Der nächste Merge queued sie **nie wieder**: der `''`-Zweig matcht nicht mehr, der Absence-Zweig schließt `pending_` explizit aus → `continue` ohne Aktion. Task erreicht den Server nie; zudem hält das Item `HasPendingWork` dauerhaft `true` → der A1-Idle-Skip ist permanent tot. Unabhängig für beide Backends gefunden. Fix: `pending_`-Items im Merge erneut in `toUpload` | MS `:787-802`, GO `:407-432` |
| R5 | Gateway | **OAuth-`state` wird nie validiert:** `state` wird beim Authorize erzeugt (GW:89/293), aber `ProcessHookData` liest nur `code`/`error` → Login-CSRF/Session-Fixation (RFC 6749 §10.12): per untergeschobenem Callback bindet ein Angreifer SEIN Konto ans Gateway; alle lokalen Items syncen ab dann in die Liste des Angreifers. Fix: `state` persistieren, im Callback einmalig + zeitbegrenzt prüfen | `ToDoGateway/module.php:770-799` |
| R6 | Google | **A1-Boundary-Echo — Idle-Skip feuert nie:** Cursor = Sekunden-Floor von max(`updated`); `updatedMin` ist inklusiv (Code-Annahme selbst) → der Cursor-Task matcht die Probe **immer** → nie `count === 0` → jeder Tick = Probe **plus** Voll-Fetch = mehr Requests als vor A1 (Netto-Regression, kein Datenrisiko). Fix: `updatedMin = Cursor + 1s` (+ periodischer Voll-Reconcile) | GO `:197-210, 253-258, 288-292, 335-338` |
| R7 | CalDAV | **Konfliktpfad übernimmt Server-ETag nicht:** `local_wins`/`newest_wins`-lokal gibt `$Local` mit altem ETag zurück → PUT `If-Match` → 412 → Upload scheitert → `localModified` bleibt → identischer Konflikt im nächsten Lauf → **Endlos-412, lokale Änderung erreicht den Server nie** (dauerhafte Divergenz). Fix: wie bei MS/Google den Server-ETag bei beabsichtigtem Overwrite übernehmen | `libs/CalDAVSync.php:593-611, 649-651` |
| R8 | CalDAV | **Escaping doppelt + Unescape auf Rohtext:** `CalDAVEscapeText` ersetzt `\` **zuletzt** → `\n`/`\,`/`\;` werden zu `\\n`/`\\,`/`\\;` (RFC-5545-invalide; Apple Reminders/Tasks.org/Thunderbird zeigen literale `\n`). Gegenstück: `CalDAVMaybeUnescapeText` läuft bei Merge (`:538-539`) und Upload (`:721-722`) auf **nie escapten lokalen** Texten → `C:\notes` wird zu `C:` + Zeilenumbruch + `otes`. Empirisch verifiziert. Fix: `\` zuerst escapen; Unescape nur auf Wire-Daten | `libs/CalDAVSync.php:777-802` |
| R9 | CalDAV | **VALARM nicht übersprungen:** Zeilen aus `BEGIN:VALARM…END:VALARM` landen im flachen Props-Array → Alarm-`DESCRIPTION`/`SUMMARY`/`UID` überschreiben die des Tasks; beim Upload werden alle VALARMs gedroppt (local-wins wischt Alarm + echte Beschreibung serverseitig) | `libs/CalDAVSync.php:391-419` |
| R10 | CalDAV | **Tombstone-Lücke in `UpdateItem`:** Der Delete-on-Complete-Pfad legt Google/MS-Tombstones an, aber **keinen** CalDAV-Tombstone (ToggleDone/DeleteItem haben ihn). Bei „Erledigte löschen" + Abhaken im Edit-Dialog bleibt das Server-VTODO stehen → Task ersteht beim nächsten Sync wieder auf. `SyncHandleDeleteOnComplete` (`SyncHelper.php:123-152`) wäre die Lösung, wird aber nirgends aufgerufen (toter Code) | `module.php:564-581` |
| R11 | MS | **A6 nicht umgesetzt + latente Massenlöschung:** `@removed` wird nie geparst; Löschung absence-basiert. Der stille Trunkierungs-`break` (`$pos === false` → Teil-Liste zurückgeben) ist der im Erst-Audit gewarnte Pfad: Format-/Host-Änderung des nextLink → alle Tasks ab Seite 2 gelten als server-gelöscht. Fix: `return null` statt `break` + `@removed` verarbeiten | `libs/MicrosoftToDoSync.php:327-329, 787-801` |

## Neue Befunde — MITTEL

| # | Backend | Befund | Ort |
|---|---|---|---|
| R12 | Google | Quota-Fehler kommen bei Google oft als **HTTP 403** (`usageLimits`/`rateLimitExceeded`, [Beleg](https://issuetracker.google.com/issues/36758511)) — A2 deckt nur 429/503/504; 403 → `null` ohne Backoff → Timer hämmert bei Tages-Quota weiter | GWH `:380-384`, GW `:203-206` |
| R13 | Google | Server-Tombstone übersteuert `local_wins`: `_deleted` wird **vor** der Konfliktauswertung angewandt → lokal editierter Task wird trotz „Local wins" gelöscht (inkonsistent zum Absence-Pfad, der re-uploaded) | GO `:436-439` vs. `:422-427` |
| R14 | alle | UI-Edit während laufendem Sync geht verloren: Sync macht `LoadItems … SaveItems` nur unter dem Sync-Semaphor; die UI-Edit-Pfade laden/schreiben dasselbe Attribut ungeschützt → Edit wird beim `SaveItems` des Syncs überschrieben (nicht nur verzögert). Zudem: `SyncOnChange` während laufendem Sync wird ohne Re-Arm verworfen — bei Intervall „Manuell" synct die Änderung nie | MS `:191/229`, `module.php:556-683, 884-897` |
| R15 | Gateway | Redirect-Doppelbehandlung: PHP-Wrapper `follow_location` nie deaktiviert + Status wird aus dem **ersten** Header der Kette geparst; CalDAV hat zusätzlich eine eigene Redirect-Schleife → redirected PUT/DELETE wird doppelt ausgeführt (Create mit `If-None-Match:*`: Auto-Follow ok, Replay 412 → fälschlich „failed"); 401/429-Erkennung geblendet; `Authorization: Basic` geht an Fremdhost-Location mit | GW `:534-565, 817-857`, GWH `:122-143` |
| R16 | CalDAV | Weak-ETag-Mangling: `trim($etag,'"')` macht aus `W/"abc"` → `W/"abc` → malformed `If-Match` → hinter nginx/gzip scheitern alle bedingten Writes dauerhaft | `libs/CalDAVSync.php:366, 651, 680` |
| R17 | Gateway | Kein gateway-weiter Refresh-Lock: zwei ToDoList-Instanzen (oder Sync + „Test Connection") refreshen konkurrent mit demselben Refresh-Token; verliert Request B mit `invalid_grant`, löscht das auch die Millisekunden zuvor geschriebenen **frischen** Tokens → Spontan-Disconnect + Reauth-Zwang. Fix: Semaphore um den Refresh | GWH `:265-338` |
| R18 | Gateway | 4 Legacy-Pfade (`GoogleTestConnection`, `GoogleFetchTaskLists`, `MicrosoftTestConnection`, `MicrosoftFetchLists`) nutzen weiter `OAuthHttpRequest` ohne Meta: kein 401-Retry, kein Throttle-Fenster-Check und kein Fenster-Setzen bei 429 | GW `:156, 239, 363, 453` |
| R19 | CalDAV | `METHOD:PUBLISH` für iCloud verletzt RFC 4791 §4.1 (Kalenderobjekte MUST NOT METHOD); für iCloud unnötig, strikte Server lehnen ab | `libs/CalDAVSync.php:724-738` |
| R20 | CalDAV | Date-only-Parse: `createFromFormat('Ymd', …)` ohne `!` füllt die **aktuelle Uhrzeit** ein (nichtdeterministisch, empirisch verifiziert); `VALUE=DATE`-Param wird ignoriert → verschärft D3 (Phantom-„Server-Änderungen", zufällige Notification-Zeiten) | `libs/CalDAVSync.php:475-481` |
| R21 | MS | B1-Randfall: `dueMatches` vergleicht `gmdate('Y-m-d')` (UTC) gegen `range.startDate` in `recurrenceTimeZone` → bei Nicht-UTC-Serien mit Due nahe Mitternacht wird unveränderte Recurrence als „geändert" fehlbeurteilt → lossy Rebuild („Mo+Mi+Fr" → ein Wochentag) | `libs/MicrosoftToDoSync.php:462-475` |
| R22 | Kind | Tote Datei `ToDoList/libs/OAuthHelper.php` (nirgends eingebunden) enthält die **alte, unfixierte** Refresh-Logik; zudem bleiben die Legacy-Token-Attribute im Kind registriert und werden nie geleert → Prä-Gateway-Refresh-Tokens liegen dauerhaft XOR-obfuskiert in Settings/Backups | CH `:1-253`, `module.php:88-90, 104-106` |

## Neue Befunde — NIEDRIG (kompakt)

- **MS:** Same-Second-Fix fehlt im Titel-Adopt-Zweig (strikt `>`, `:767`); leere 2xx-Write-Response wischt gespeicherten ETag → nächster PATCH unbedingt (GW `:410-412` → MS `:993-998`); Outlook-HTML-Bodies werden als `contentType:text` zurückgepusht → Markup wird literal (`:643, :661-664`); Delta-Probe ohne `$select=id` + Erst-Lauf enumeriert die Liste doppelt (Doku unterstützt `$select` auf Delta explizit).
- **Google:** ungefangene `new DateTime($dueStr)`-Exception im Fallback bricht den ganzen Sync-Lauf ab (`:329`); Probe verwirft ihr Ergebnis und paginiert vollständig, obwohl `count>0` nach Seite 1 feststeht (Effizienz); leerer Response-Body wird als Fehler gewertet — Asymmetrie zu MS (GW `:196-201`).
- **CalDAV:** Legacy-Tombstone `'1'` wird als Href interpretiert → DELETE auf `<kalender>/1` → 404 → Tombstone weg, Server-Item ersteht wieder (`:153-161, :670-684`); rein numerische UIDs rutschen durch das strict-`in_array`-Pending-Filter (PHP-Key-Koerzierung, `:141-145`); PUT-Response-ETag nicht erfasst → jeder Upload erzwingt Token-Reset + vollen REPORT (`:659-667`); quoted TZID (`TZID="America/New_York"`) wirft → stiller Host-TZ-Fallback (`:413-417, :464-470`); `urlencode` (Space→`+`) in Resource-URLs + fehlendes CRLF nach `END:VCALENDAR` (`:642, :774`); getctag-Regex prefix-blind und ohne `-`/`_` in der Prefix-Klasse (`:270-275`); CTag-Skip-Pfad ruft `SyncPostComplete` nicht (kosmetisch, `:126`); importierte Items tragen tote Feldnamen `notified`/`recurrenceReopenDays` (`:576, :581`); propstat-Status wird ignoriert (Props aus 404-propstats, `:356-360`).
- **Gateway:** 429 vom Token-Endpoint setzt kein Backoff-Fenster (GWH `:282-323`); `TGW_CalDAVGetCredentials` liefert das CalDAV-Passwort im Klartext an jeden Skript-Aufrufer (GW `:477-484`).

## API-/RFC-Abgleich — Kernaussagen

- **Graph To Do:** `/tasks/delta` in v1.0 bestätigt ([Doku](https://learn.microsoft.com/en-us/graph/api/todotask-delta?view=graph-rest-1.0); China/21Vianet: ganze To-Do-API ❌). Doku verlangt, nextLink/deltaLink „ohne Inspektion" zu übernehmen — Probe konform, Voll-Fetch nicht (R1). `@removed`-Handling wird von Clients erwartet (fehlt, R11). Token-Ablauf: 410+Location, bei Outlook-Entitäten (inkl. todoTask) auch 40x `syncStateNotFound` — der Blanket-Restart des Codes deckt beides, konflatiert aber transiente Fehler. `dateTimeTimeZone`-Antworten kommen mit Windows-TZ-Namen → B6-Map liegt auf dem kritischen Parse-Pfad. Update-Doku („only HTML supported") widerspricht dem Delta-Beispiel (`text`) — Code passt zum beobachteten Verhalten.
- **Google Tasks:** `updatedMin`-Inklusivität undokumentiert (→ R6); Tombstone-Lebensdauer undokumentiert → der Absence-Fallback ist faktisch nötig (C1 bleibt); `due` date-only bestätigt („time portion … discarded"); `due:null`-Löschung undokumentiert-aber-übliche Google-Semantik; `showCompleted` erfordert `showHidden` — Code setzt beide ✓; tasks.list max 100 + pageToken-Schleife ✓.
- **CalDAV/iCal:** getctag-PROPFIND (Depth:0, `calendarserver.org/ns`) exakt per Spec; `METHOD` in Kalenderobjekten verboten (R19); getetag MUST strong + PUT liefert neuen ETag zurück (ungenutzt); TEXT-Escaping-Reihenfolge falsch (R8); Input-Unfolding korrekt, Output-Faltung fehlt weiter (D4).
- **OAuth:** `invalid_grant`-Behandlung RFC-6749-konform und konservativ (nacktes 400 ohne `error`-Feld löscht nichts — die Sorge „transientes 400 killt Tokens" trifft nicht zu). MS rotiert Refresh-Tokens (Code persistiert das neue ✓; alter bleibt laut Doku gültig → R17-Race selten-aber-fatal). Google rotiert nicht ✓. Google-App im „Testing"-Status: RT läuft nach 7 Tagen ab → die periodische Reauth-Warnung ist dann korrektes Verhalten, kein Bug.
- **TLS:** keine ssl-Kontext-Optionen gesetzt → PHP-Defaults `verify_peer(_name)=true` aktiv, Zertifikatsprüfung an. OK.

## Offene Alt-Punkte (Stand Re-Audit)

- **Weiterhin offen:** D3 (verschärft durch R20), D4 (Faltung), D5/D6 (STATUS/PRIORITY lossy), D7-Reste (doppelte `caldavUid` last-wins `:491-494`, toter `localByUid` `:496-501`), B2 (präzisiert: Instants korrekt, aber Datumsanzeige verschiebt bei mitternachts-nahen Dues; jetzt `:335-341`), B4 (jetzt `:52-71` + GW `:446-471`), B6 (38 Einträge, jetzt `:368-410`), B7 (jetzt `:313` + Probe `:252`), C1 (Tombstone-Volumen, jetzt GO `:288`; wegen R6 faktisch bei jedem Tick), C5 (parent/position; `position` ist read-only, `parent` nur via `move`), C6 (Decode jetzt **in** der Import-Schleife GO `:480-483`), E-Punkte (XOR unverändert GWH `:7-42`; Timeouts unverändert 30 s ohne Gesamt-Cap/Retry).
- **Erledigt/gegenstandslos:** C3 (behoben, GO `:326-331`), C4 (Prämisse falsch, Default 1000).

## Umsetzungsstatus Re-Audit (2026-07-02)

Alle Punkte der empfohlenen Reihenfolge sind umgesetzt (Syntax-Lint über alle Dateien + 23 Verhaltens-Tests der CalDAV-Parser-/Escaping-/Konflikt-Logik grün):

- [x] **R1** — `MicrosoftFetchTasks` folgt dem `nextLink` jetzt verbatim (wie die Delta-Probe; Gateway akzeptiert Absolut-URLs); unbrauchbarer nextLink → `return null` statt stiller Trunkierung (= Trunkierungsgate aus R11).
- [x] **R2** — `CalDAVParseMultiStatus` liefert bei Parse-Fehler `null` (Fetch bricht ab) statt `[]`; leeres, aber valides 207 bleibt `[]`.
- [x] **R3** — `CalDAVBuildVTodo` schreibt die gespeicherte `caldavUid` unverändert ins VTODO (keine Zufalls-UUID-Regeneration mehr). ⚠️ Altbestand: bereits mit divergenter UID hochgeladene Tasks durchlaufen einmalig den alten Drop+Reimport (nicht migrierbar, da der alte Code die Server-UID nie gespeichert hat).
- [x] **R4** — `pending_`-Items werden im Merge wieder in `toUpload` gequeued (MS+Google); `GoogleHasPendingWork` erkennt `pending_`; die Synced-Stempel-Schleifen (MS+Google) überspringen `pending_`.
- [x] **R5** — OAuth-`state` wird beim Authorize persistiert (10-Min-TTL) und im Callback einmalig via `hash_equals` validiert; neue Attribute `Google-/MicrosoftOAuthState`; Übersetzungen ergänzt.
- [x] **R6** — Probe mit `updatedMin = Cursor + 1 s`; neues Attribut `GoogleLastFullSync` erzwingt alle 6 h einen vollen Reconcile (Reset bei ResetSync/Listenwechsel).
- [x] **R7** — `CalDAVResolveConflict` übernimmt bei local-wins/newest-wins-lokal den Server-ETag+Href (`CalDAVAdoptServerEtag`) → kein Endlos-412.
- [x] **R8** — `CalDAVEscapeText` escapt `\` zuerst (RFC-konformes Wire-Format); `CalDAVUnescapeText` = ein Links-nach-rechts-Pass; `CalDAVMaybeUnescapeText` (Unescape auf Lokaltext) komplett entfernt.
- [x] **R9** — VTODO-Parser überspringt verschachtelte Komponenten (VALARM etc.) mit Tiefenzähler; zusätzlich: quoted TZIDs werden entquotet.
- [x] **R10** — `UpdateItem`-Delete-on-Complete legt jetzt auch den CalDAV-Tombstone an (identisch zu ToggleDone/DeleteItem).
- [x] **R12** — Google-Quota-403 (`rateLimitExceeded`/`quotaExceeded`/`usageLimits`/…) armiert das A2-Backoff-Fenster; andere 403 (Berechtigung) unverändert.
- [x] **R13** — Google-Server-Tombstone respektiert `local_wins`: lokal geänderte Items werden als neuer Task re-uploaded statt still gelöscht.
- [x] **R14 (teilweise)** — `SyncOnChange` re-armt den Timer (3 s), wenn der Sync-Semaphor belegt ist (wie CalDAV) → Änderung während laufendem Sync strandet nicht mehr. *Offen bleibt* das Load-Modify-Save-Fenster (UI-Edit während `LoadItems…SaveItems` des Syncs kann überschrieben werden) — Fix erfordert Items-Lock oder Re-Merge vor SaveItems.
- [x] **R15** — `follow_location => 0` in allen drei HTTP-Kontexten (kein Doppel-PUT/DELETE, Status = finale Response); CalDAV-Redirects nur noch same-host (kein Basic-Credential-Leak).
- [x] **R16** — `CalDAVNormalizeEtag`/`CalDAVEtagHeaderValue`: Weak-ETags (`W/"…"`) bleiben intakt und werden verbatim gesendet; starke ETags kompatibel zum Altbestand normalisiert.
- [x] **R17** — Refresh-Semaphor pro Gateway+Provider (`TGW_Refresh_<id>_<prefix>`, 10 s Wartezeit) + Erkennung „anderer Caller hat bereits refresht" (Access-Token-Vergleich vor/nach Lock).
- [x] **R18** — `Google-/MicrosoftTestConnection` und `Google-/MicrosoftFetchLists`/`FetchTaskLists` laufen über `Google-/MicrosoftApiRequest` (401-Retry + Backoff-Fenster inkl. Fenster-Armierung).
- [x] **R19** — `METHOD:PUBLISH` entfernt (RFC 4791 §4.1); iCloud-Sonderbehandlung + überflüssiger Credentials-Abruf pro Build entfallen.
- [x] **R20** — `!Ymd`-Formate im Date-Parse (deterministische Mitternacht statt Wall-Clock-Fill).
- [x] **R21** — `MicrosoftBuildRecurrence`: Due-Datum wird in `range.recurrenceTimeZone` (Windows→IANA) formatiert und erst dann mit `startDate` verglichen.
- [x] **R22** — Tote Datei `ToDoList/libs/OAuthHelper.php` gelöscht; Legacy-Token-Attribute im Kind werden in `ApplyChanges` einmalig geleert (Attribute bleiben registriert).

**Weiterhin offen:** `@removed`-Verarbeitung (R11-Rest — Löschung bleibt absence-basiert, aber jetzt trunkierungssicher), R14-Locking (s. o.), die NIEDRIG-Befunde des Re-Audits sowie die Alt-Punkte D3–D7/B2/B4/B6/B7/C1/C5/C6/E (XOR, Timeouts).

> ⚠️ **Live-Test vor Produktivfreigabe unverändert erforderlich** — insbesondere If-Match (MS+Google, von beiden APIs undokumentiert), das neue CalDAV-Wire-Escaping gegen einen echten Server und der OAuth-Flow mit state-Validierung.

### Live-Test Microsoft (2026-07-02, Instanz „ToDo Liste Microsoft", 100 generierte Test-Tasks)

- ✅ **R1/Paginierung**: Liste mit 145 Tasks (2 Seiten) — Voll-Fetch, Upload und Merge fehlerfrei; keine Massenlöschung, keine Duplikate (das eine Server-Titel-Duplikat „235" existierte bereits in den Altdaten).
- ✅ **If-Match wird von Graph unterstützt** (trotz fehlender Doku): PATCH (Titel + Status→completed) und DELETE mit `If-Match` wurden akzeptiert und korrekt ausgeführt — der A3-Vorbehalt ist für Microsoft entkräftet.
- ✅ **R4**: keine gestrandeten `pending_`-IDs nach 100 Uploads (inkl. paralleler AutoSyncOnChange-Läufe während der Anlage).
- ✅ **Idle-Skip** (Delta-Probe): wiederholte Syncs ohne Änderungen laufen fehlerfrei durch.
- ✅ **Feld-Treue**: Emoji-/Sonderzeichen-/Backslash-Titel, Priorität (importance 1:1), Reminder exakt `due − Vorlauf`, Recurrence `weekly int=2`/`absoluteMonthly int=2` korrekt; `custom h` (stündlich) wird sauber ohne Server-Recurrence angelegt (dokumentierte Limitation).
- ✅ **A5-Konvergenz**: 36 in MS To Do längst erledigte Alt-Tasks, die die alte uhrzeitbasierte Änderungserkennung nie übernommen hatte, wurden beim ersten Sync korrekt auf „erledigt" konvergiert (lokal ≡ Server: 144/144 nach Testende).
- ⚠️ **B2 empirisch bestätigt**: Graph **floort `dueDateTime` auf UTC-Mitternacht** (Uhrzeit wird serverseitig verworfen — due ist bei MS faktisch date-only). Lokale Fälligkeit 06.07. 00:30 (UTC+2) landet als 05.07. auf dem Server → Vortags-Anzeige. Bestätigt FEATURE-MATRIX-Paket 4 (due/reminder in lokaler IANA-Zone schreiben).
- **Noch ungetestet live**: 412-Konfliktpfad bei MS (benötigt gleichzeitige Fremdänderung); OAuth-Reauth-Warnpfad (invalid_grant).

### Live-Test Google (2026-07-02, Instanz „ToDo Liste Google", 30 generierte Test-Tasks) — inkl. neuem Befund R23 + Fix

- ✅ **If-Match wird von Google unterstützt** (trotz fehlender Doku, per Direktprobe verifiziert): PATCH/DELETE mit falschem ETag → **412**, mit korrektem → 200/204. Der A3-Vorbehalt ist damit für **alle drei Backends** entkräftet.
- 🐞 **Neuer Befund R23 (HOCH, live entdeckt):** Google bumpt den ETag eines Tasks bei **Sibling-Inserts** (Positionsverschiebung), **ohne `updated` anzufassen**. Folge im alten Code: gespeicherter ETag stale bei `serverChanged=false` → der `elseif localChanged`-Upload lief mit altem ETag in eine **Endlos-412-Schleife** (lokale Edits erreichten Google nie); DELETE-Tombstones wurden per 412 verworfen → **gelöschte Tasks erstanden wieder auf**. Empirisch reproduziert (PATCH mit gespeichertem ETag → 412; `updated` identisch zur Baseline).
- 🔧 **Fix „Fresh-ETag" (MS + Google, 2026-07-02):** Bedingte Writes basieren jetzt auf dem ETag aus dem Fetch **desselben Laufs**: (1) der `elseif localChanged`-Merge-Zweig übernimmt den frisch gefetchten Server-ETag vor dem Upload; (2) Pending-Deletes laufen **nach** dem Fetch und überschreiben das Tombstone-ETag mit dem Fetch-ETag; aufgelöste Deletes werden aus dem Fetch-Snapshot gefiltert (kein Reimport im selben Lauf). Optimistic-Locking-Semantik bleibt erhalten (Schutz gegen Änderungen **nach** dem Fetch). CalDAV braucht den Fix nicht (ETag-basierte Änderungserkennung; ETags ändern sich dort nur bei Inhaltänderung).
- ✅ **Nach dem Fix verifiziert:** hängende Edits kamen durch (Titel „GEAENDERT", Status completed), **C2 live bestätigt** (`due: null` löscht die Fälligkeit — „due=KEINS"), Delete-Härtetest mit gleichzeitigem Sibling-Insert: Task bleibt gelöscht (keine Wiederauferstehung), neuer Task hochgeladen, lokal ≡ Server (48/48), 0 `pending_`-Strandungen.
- ✅ **A6**: serverseitige Löschung (`deleted=true`) wird lokal korrekt übernommen. ✅ Import/Upload: 19 Bestands-Tasks importiert, 30 Test-Tasks fehlerfrei hochgeladen; Sonderzeichen/Emoji/Notes-Round-Trip korrekt; date-only-`due` bestätigt, lokale Uhrzeit bleibt erhalten (C3). ✅ Listen-Abruf über den R18-Pfad. ✅ OAuth-Authorize mit state-Validierung durchlaufen (nach Client-Neuanlage). ✅ MS-/CalDAV-Regressions-Syncs nach dem Fresh-ETag-Umbau grün.

### Release-Testrunde (2026-07-02, alle Backends) — 2 weitere Live-Befunde + Fixes

- ✅ **Statik**: `php -l` über alle Moduldateien fehlerfrei; Locale-Vollständigkeitsprüfung (alle `Translate()`-Strings) → 1 fehlende de-Übersetzung ergänzt („Found %d task list(s).").
- ✅ **OAuth-Angriffstest**: Callback mit gefälschtem `code`/`state` → sauber abgewiesen (R5), Code erreicht den Token-Austausch nicht.
- ✅ **Konflikt beidseitig** (newest_wins, MS + Google): Server-Edit + späterer Lokal-Edit → lokal gewinnt (auf Server angekommen); Lokal-Edit + späterer Server-Edit → Server gewinnt (lokal übernommen). Keine 412-Schleifen, beidseitige Konvergenz.
- 🐞 **B3 bestätigt und präzisiert (KRITISCH für Serien-Tasks):** Graph lehnt **jedes** `recurrence`-Objekt im PATCH mit 400 ab — sogar das unveränderte, vom Server selbst gelieferte Muster (per Feld-Bisektion isoliert; Konto: MSA). Da der B1-Raw-Round-Trip die Recurrence bei jedem Edit mitsendete, schlugen **alle Edits an wiederkehrenden Tasks** still fehl (Endlos-Dirty-Loop). Zusatzerkenntnis: `status=completed` auf einer Serie lässt Graph die Serie selbst weiterrollen (separate Completed-Instanz + neue Instanz).
- 🔧 **B3-Fix:** (1) `recurrence` wird im PATCH **weggelassen**, wenn das Muster lokal unverändert ist — PATCH-Semantik erhält das Server-Muster inkl. lokal nicht darstellbarer Details (erfüllt das B1-Ziel besser als das Raw-Zurücksenden); (2) nur echte Muster-Änderungen werden gesendet, mit einmaligem Retry ohne `recurrence` bei Ablehnung (übrige Felder kommen durch, Server-Muster bleibt autoritativ); (3) lokal abgeschlossene Serien-Tasks werden MS-nativ als `notStarted` + neue Fälligkeit übertragen (verhindert Graphs Doppel-Roll). Verifiziert: hängender Serien-Task synct wieder, dirty-Flag abgebaut.
- ✅ **Wiederholungs-Abschluss CalDAV**: Abhaken einer m1-Serie → `STATUS:COMPLETED` + `DUE` +1 Monat korrekt auf dem Server.
- ✅ **Umlaut/Multibyte-Round-Trip** (alle drei Backends): „Süßölgefäß ÄÖÜ / Straße, Größe & Käse" byte-identisch.
- 🐞 **R23-Erweiterung (Google-Deletes):** Auch **Löschungen** bumpen die ETags aller verbleibenden Tasks — beim Massen-Löschen invalidiert jeder erfolgreiche DELETE die vorbereiteten ETags der übrigen (nur 1 von 30 kam durch; Rest 412 → Tombstone weg → Reimport). Selbst das Fetch-frische ETag desselben Laufs ist nicht frisch genug. **Fix:** Google-DELETEs laufen unbedingt (ohne If-Match) — Googles positionsabhängiger ETag trägt für Deletes kein echtes Concurrency-Signal; MS/CalDAV behalten If-Match (inhaltstabile ETags, MS-seitig durch 102 konditionale DELETEs in einem Lauf bestätigt).
- ✅ **Massen-Lösch-Stresstest / Cleanup:** alle ~160 Testdaten über die Modul-Löschpfade entfernt; Baselines exakt wiederhergestellt (MS 45, Google 19, CalDAV 43; lokal ≡ Server auf allen dreien).
- Hinweis: In einem Lauf mit Deletes + Edits können Google-PATCHes wegen der Positions-ETag-Bumps einmalig 412 liefern und konvergieren im Folgelauf (PATCHes selbst bumpen keine Sibling-ETags — nur Inserts/Deletes).
- ✅ **Listenwechsel-Test (2026-07-03, Google A↔B):** `SyncHandleListChange` leert die lokalen Items sofort bei ApplyChanges; der Folgesync importiert ausschließlich die neue Liste. **Keine Übertragung in beide Richtungen** (Marker-Task blieb in der alten Liste, wurde weder hochgeladen noch gelöscht; Ziel-Liste unverändert). Randfall verifiziert: ein beim Wechsel noch offener Lösch-Tombstone wird mit verworfen und feuert gegen keine der Listen. Rückwechsel stellt den alten Stand per Import wieder her. Der Mechanismus ist für MS (`MicrosoftDeltaLink`-Reset) und CalDAV (`CalDAVSyncToken`-Reset) derselbe geteilte Code-Pfad.

### Live-Test CalDAV (2026-07-02, Instanz „ToDo Liste CalDav", ownCloud/SabreDAV @ localhost:8880, 30 generierte Test-Tasks)

> Korrektur 03.07.: Der Testserver ist `owncloud/server:latest` (Docker), nicht Nextcloud — per SabreDAV-Antwort nicht unterscheidbar, per `docker ps` verifiziert. Bekannter Anzeige-Hinweis: Die ownCloud-Kalender/Aufgaben-Web-App rendert UTC-`DUE`-Werte unkonvertiert (zeigt z. B. 07:11 statt 09:11 lokal), obwohl Browser- und Nutzer-Zeitzone korrekt sind — reines Darstellungsproblem der App, Daten und native Clients sind korrekt.

- ✅ **R3/UID-Stabilität**: Alle 30 neuen Tasks mit `UID:symcon-<inst>-<id>` verbatim auf dem Server; lokale IDs+UIDs über zweiten Sync **identisch** (vor dem Fix: Drop+Reimport jedes neuen Tasks mit Verlust aller lokalen Felder).
- ✅ **R8/Escaping**: Wire-Format RFC-5545-konform verifiziert — `SUMMARY:…\, mit\; …` (einfach escaped), Backslash-Titel `C:\\temp\\x`, `DESCRIPTION:Zeile 1\nZeile 2\, …`; lokaler Round-Trip über mehrere Syncs verlustfrei (keine Doppel-Escapes, keine Backslash-Korruption mehr).
- ✅ **R9/VALARM**: Fremd-VTODO mit `VALARM`/`DESCRIPTION:Erinnerung!` importiert → lokale Info = echte Task-DESCRIPTION (Alarm-Zeilen übersprungen); Alarm bleibt auf dem Server intakt, solange lokal nicht editiert wird.
- ✅ **R7/ETag-Adopt**: Serverseitige Fremdänderung + neuerer Lokal-Edit (newest_wins) → bedingter PUT mit übernommenem Server-ETag erfolgreich, Lokal-Edit auf dem Server angekommen (vor dem Fix: Endlos-412, Änderung wäre nie hochgeladen).
- ✅ **PUT/DELETE mit If-Match** (SabreDAV): einfaches Titel-Update und Tombstone-DELETE korrekt; serverseitige Löschung wird lokal übernommen; Bestand lokal ≡ Server (72/72).
- ✅ **CTag-Probe** (`getctag`/`sync-token`, SabreDAV-Namespaces) funktioniert; Idle-Syncs stabil; **D2** bestätigt (`DUE:…Z`), **R19** bestätigt (kein `METHOD`), PRIORITY-Klassen-Mapping korrekt.
- ✅ Lokal-only-Features (Wiederholungen `m1/q1/custom`, Erinnerungen) überleben alle Sync-Zyklen unverändert.
- **Google-Live-Test weiterhin offen** — am Gateway ist kein Google-Konto autorisiert (OAuth-Login im Browser nötig) und keine Instanz auf Google-Backend konfiguriert.

## Empfohlene Reihenfolge (ersetzt die Roadmap vom 01.07.)

1. **R1** (+ Trunkierungsgate aus R11) — MS-Sync ist für Listen >100 Tasks aktuell komplett defekt; Drei-Zeilen-Fix.
2. **R3 + R2** — CalDAV-Datenverlustpfade (UID-Divergenz bei jedem neuen Task, Parse-Fehler-Massenlöschung).
3. **R4** — `pending_`-Requeue (MS + Google; reaktiviert zugleich den A1-Idle-Skip bei gestrandeten Items).
4. **R5** — `state`-Validierung im OAuth-Callback (Sicherheit, klein abgegrenzt).
5. **R6** — Google-Cursor +1 s (macht A1-Google real wirksam).
6. **R7/R8/R9/R10** — CalDAV-Integrität (412-Schleife, Escaping, VALARM, Tombstone-Lücke).
7. **R12–R22** nach Aufwand/Nutzen; **Live-Test der If-Match-Pfade (MS + Google) vor Produktivfreigabe** — die API-Doku beider Anbieter dokumentiert If-Match/412 nicht.

---

# Nachtrag 2026-07-03 — CalDAV Fälligkeit in lokaler Zeitzone (Option 2)

**Anlass:** Die ownCloud-/Nextcloud-Aufgaben-Web-App rendert `DUE` in UTC-Z-Form unkonvertiert (zeigt z. B. 07:11 statt 09:11 lokal), obwohl der gespeicherte Zeitpunkt korrekt ist. Der D2-Fix (immer UTC-Z) war RFC-sicher, aber für diese naiven UIs unschön.

**Umsetzung (`CalDAVBuildVTodo` + neu `CalDAVBuildVTimezone`):** `DUE` wird jetzt als `DUE;TZID=<Host-Zone>:<lokale Wanduhrzeit>` geschrieben, zusammen mit einem **eingebetteten VTIMEZONE**-Block (aus PHP-Transition-Daten generiert: DAYLIGHT/STANDARD mit korrektem `TZOFFSETFROM/TO` und `RRULE`, für Nicht-DST-Zonen eine feste STANDARD-Komponente). Damit ist die Referenz RFC-5545-gültig (behebt D2 nicht durch Rückkehr zum invaliden Zustand, sondern durch die fehlende VTIMEZONE) und naive UIs zeigen die lokale Uhrzeit. `DTSTAMP/CREATED/LAST-MODIFIED/COMPLETED` bleiben UTC-Z (das ist korrekt — echte UTC-Zeitstempel). Fällt die Host-Zone auf `UTC` oder ist sie nicht auflösbar, bleibt die UTC-Z-Form (kein Regressionsrisiko).

**Verifiziert (Unit + Live gegen ownCloud):** VTIMEZONE für Europe/Berlin korrekt (letzter So März 02:00 → +0200, letzter So Okt 03:00 → +0100); Fixed-Offset-Zone (Tokyo) ohne RRULE; Round-Trip Sommer- und Winterzeit instant-stabil; SabreDAV akzeptiert den PUT (VObject-Validierung); Mehrfach-Sync ohne Phantom-Change (localModified bleibt 0) und ohne Duplikate.

**Hinweis:** Bereits zuvor synchronisierte Tasks behalten ihre alte UTC-Z-Form auf dem Server, bis sie das nächste Mal geschrieben werden (lokale Änderung oder „Reset Sync"). Neue und geänderte Tasks nutzen sofort die TZID-Form.

**Offen bleibt** D3 (`VALUE=DATE` für echte Ganztags-Aufgaben) — davon unberührt.

---

# Nachtrag 2026-07-03 — Microsoft Fälligkeit/Erinnerung in lokaler Zeitzone (B2 behoben)

**Problem (B2):** MS To Do behandelt die Fälligkeit als reines Datum und speichert „Mitternacht des Datums in der gesendeten Zone". Das Modul sendete `dueDateTime`/`reminderDateTime` immer als UTC → eine Fälligkeit kurz nach lokaler Mitternacht (z. B. 00:30 Berlin = 22:30 UTC am Vortag) landete auf dem **Vortag** und wurde in MS To Do einen Tag zu früh angezeigt.

**Fix (`MicrosoftBuildDateTimeTimeZone`):** Der Zeitpunkt wird in der Host-Zeitzone ausgedrückt (`dateTime` = lokale Wanduhrzeit, `timeZone` = IANA-Name — Graph akzeptiert IANA direkt, live verifiziert; kein Windows-TZ-Mapping nötig). Fällt der Host auf `UTC` oder ist die Zone nicht auflösbar, bleibt die UTC-Form (kein Regressionsrisiko). Begleitend `MergeDuePreferServerTime`: die „date-only"-Erkennung greift jetzt bei **lokaler** Mitternacht (neues Format) **und** UTC-Mitternacht (Altbestand), damit die lokale Uhrzeit beim Server→lokal-Merge erhalten bleibt.

**Live verifiziert (MS-Konto):** Fälligkeit 11.07. 00:30 Berlin → Server-Datum 11.07. (vorher 10.07.); Tag-Fälligkeit + Erinnerung korrekt (Erinnerung 13:50 = 14:00 − 10 min); Round-Trip bewahrt die lokale Uhrzeit (00:30/14:00), kein Phantom-Change; Fremd-Edit auf dem Server → Titel übernommen und lokale Uhrzeit erhalten. Cleanup beidseitig sauber.

**Hinweis:** Bereits synchronisierte Tasks behalten ihre alte UTC-Form auf dem Server, bis sie das nächste Mal geschrieben werden. Die Uhrzeit selbst zeigt MS To Do weiterhin nicht an (API-Grenze) — der Fix korrigiert das **Datum**.

---

# Nachtrag 2026-07-03 — CalDAV Property-Preserving Merge + VALARM-Zwei-Wege

**Problem:** Beim Upload baute `CalDAVBuildVTodo` das VTODO komplett neu — aus dem schmalen lokalen Modell. Jede lokale Bearbeitung (auch nur der Titel) zerstörte damit alles, was reichere Clients (Apple Erinnerungen, Tasks.org, ownCloud-Web) zusätzlich gespeichert hatten: VALARM, RRULE, CATEGORIES, ATTACH, X-*, `VALUE=DATE`, feine PRIORITY.

**Fix — In-Place-Merge (`CalDAVMergeVTodo`):** Das rohe Server-VTODO wird pro Item gespeichert (`caldavRaw`); beim Upload werden nur die modul-verwalteten Properties ersetzt — und zwar **nur wenn sich der lokale Wert gegenüber dem Import unterscheidet**. Alles andere bleibt byte-genau erhalten. Da unveränderte Felder ihre exakte Server-Form behalten, sind **D3** (`VALUE=DATE`), **D5** (STATUS/PERCENT-COMPLETE inkl. IN-PROCESS) und **D6** (PRIORITY 1-9) automatisch mit behoben. **D4** (Zeilenfaltung nach 75 Oktett, UTF-8-sicher) ist jetzt umgesetzt (`CalDAVFold`). Neu angelegte lokale Tasks bauen weiterhin frisch (`CalDAVBuildVTodo`).

**VALARM-Zwei-Wege:** Import — die erste relative DISPLAY-VALARM wird in `notification` + Vorlaufzeit übersetzt (auf lokale Stufen gerundet); die Alarm-`DESCRIPTION` verschmutzt dank R9 nicht die Task-Beschreibung. Export — eine lokale Erinnerung wird als DISPLAY-VALARM mit relativem Trigger geschrieben. Ändert der Nutzer die Erinnerung lokal, wird nur die modul-artige VALARM ersetzt/entfernt; fremde Alarme (EMAIL, absolute Trigger) bleiben erhalten.

**Verifiziert:** 34 Unit-Tests (Erhaltung VALARM/RRULE/CATEGORIES/X/PRIO2/VALUE=DATE bei Titeländerung; STATUS/PERCENT/COMPLETED bei Abhaken; DUE-TZID bei Fälligkeitsänderung; Reminder an/aus/geändert; Faltung >75 Oktett + Entfaltungs-Round-Trip). Live gegen ownCloud: derselbe Fremd-Task, der zuvor zerstört wurde, bleibt nach einer Symcon-Titeländerung vollständig erhalten; Symcon-Reminder erzeugt serverseitig eine VALARM (`TRIGGER:-PT1H`).

**Hinweis:** Eine importierte Server-Erinnerung treibt jetzt auch Symcons eigenes Benachrichtigungssystem (gewünschter Zwei-Wege-Effekt). `caldavRaw` vergrößert das Items-Attribut (~1-3 KB/Task). Offen bleibt nur noch die Subtask-Abbildung (`RELATED-TO`).

**Adversariales Review (2026-07-03) — behoben:** (1) HOCH — VALARM-Trigger jetzt `TRIGGER;RELATED=END:` (im VTODO ohne DTSTART wäre ein Default-Trigger unverankert → Apple/Tasks.org feuern ihn nicht). (2) MITTEL — VTIMEZONE-Dedup prüft jetzt das ganze Objekt (head+tail), nicht nur vor dem VTODO, sonst Duplikat bei nachgestelltem VTIMEZONE → PUT-Reject. (3) NIEDRIG — nur DISPLAY-Alarme werden zur Symcon-Benachrichtigung (EMAIL/AUDIO bleiben erhalten, erzeugen aber keine Anzeige). (4) NIEDRIG — Abschluss-CRLF nach `END:VCALENDAR` (RFC 5545 §3.1). Alle live gegen ownCloud verifiziert.

**Zweite Review-Runde (2026-07-03, 23 Agenten, alle Befunde gegenverifiziert) — behoben:**
- **HOCH (Datenverlust, älter als der Merge):** Nach einem PUT wurde der neue ETag nicht übernommen → der nächste Sync hielt den alten ETag für eine Fremdänderung und überschrieb bei `server_wins` (Default!) einen zwischenzeitlichen lokalen Edit. Fix: `CalDAVUploadItem` übernimmt den ETag aus der PUT-Antwort (Fallback: gezieltes PROPFIND, da ownCloud/SabreDAV den PUT-ETag weglässt) und speichert den hochgeladenen Body als In-Sync-Basis. Behebt zugleich den Churn (überflüssiger „Server geändert"-Durchlauf pro Upload). Live gegen ownCloud unter `server_wins` verifiziert (später Edit überlebt).
- **HOCH (Alarm-Zerstörung):** Die Heuristik „modul-eigener Alarm" war zu breit (jeder DISPLAY-Alarm mit relativem Trigger) und löschte/kollabierte fremde Alarme bei Erinnerungsänderung. Fix: Marker `X-SYMCON-ALARM` — nur markierte Alarme werden je angefasst, fremde bleiben immer erhalten; Import bevorzugt den markierten Alarm für stabile Round-Trips.
- **MITTEL/NIEDRIG:** RFC-5545-Reihenfolge (alle Properties vor Sub-Komponenten), VTIMEZONE-Dedup über TZID-**Wert** (auch bei parametrisierter TZID), Parameter-Erhalt bei SUMMARY/DESCRIPTION (LANGUAGE/ALTREP), Duplikat-Guard (managed Property nur einmal ausgegeben), nur DISPLAY-Alarme werden zur Benachrichtigung.
- Verifiziert: 18 zusätzliche Unit-Tests (Marker-Semantik, RFC-Ordnung, Param-Erhalt, Dedup) + Live gegen ownCloud (ETag-Fallback, Datenverlust-Schutz, Churn-frei, Marker-Alarm).
- **Bekannte, dokumentierte Grenze:** Ein aus einem Fremd-Alarm importierter Reminder, der lokal ausgeschaltet wird, kommt beim nächsten Import wieder (der Fremd-Alarm wird bewusst nie gelöscht — Vorrang: kein Datenverlust).

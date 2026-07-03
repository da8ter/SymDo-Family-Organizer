# ToDoList — Feature-Matrix & 1:1-Mapping-Vorschlag

**Stand:** 2026-07-02 (Code-Stand nach den Re-Audit-Fixes R1–R22, siehe [SYNC-AUDIT.md](SYNC-AUDIT.md))

**Legende:** ✅ voll synchronisiert · ⚠️ eingeschränkt/verlustbehaftet · ❌ von der Anbieter-API nicht unterstützt · ➖ nur lokal geführt (wird nicht synchronisiert, bleibt lokal funktionsfähig)

---

## 1. Feld-Mapping (Ist-Zustand)

| Lokales Feature | Google Tasks | Microsoft To Do | CalDAV (VTODO) |
|---|---|---|---|
| **Titel** | ✅ `title` | ✅ `title` | ✅ `SUMMARY` |
| **Notiz/Info** | ✅ `notes` | ⚠️ `body` — immer als `text` geschrieben; Outlook-HTML-Notizen werden beim Zurückschreiben zu sichtbarem Markup | ✅ `DESCRIPTION` |
| **Erledigt** | ✅ `status` (needsAction/completed) | ⚠️ `status` — nur completed/notStarted; `inProgress`/`waitingOnOthers`/`deferred` anderer Clients → „offen", bei Upload überschrieben | ⚠️ `STATUS` + `PERCENT-COMPLETE` (0/100) — `IN-PROCESS`/`CANCELLED` und Teilfortschritt anderer Clients gehen bei Upload verloren (D5) |
| **Erledigt-Zeitpunkt** | ✅ `completed` (lesen) | ✅ `completedDateTime` (lesen) | ✅ `COMPLETED` |
| **Fälligkeit (Datum)** | ✅ `due` | ✅ `dueDateTime` | ✅ `DUE` |
| **Fälligkeit (Uhrzeit)** | ❌ API verwirft die Uhrzeit („time portion discarded") — Modul sendet Mitternacht, bewahrt die lokale Uhrzeit über `MergeDueWithLocalTime` | ⚠️ MS To Do zeigt nur das Datum (keine Uhrzeit). Fälligkeit + Erinnerung werden jetzt in lokaler Zeitzone gesendet (Graph akzeptiert IANA-Namen) → korrektes Kalenderdatum auch bei mitternachtsnahen Zeiten (B2 behoben); lokale Uhrzeit wird beim Round-Trip bewahrt | ✅ volle Uhrzeit als `DUE;TZID=<lokale Zone>` mit eingebettetem VTIMEZONE (Option 2, umgesetzt) → naive Web-UIs (ownCloud/Nextcloud) zeigen die lokale Uhrzeit; echte Ganztags-Aufgaben (`VALUE=DATE`) werden weiterhin nicht erzeugt (D3) |
| **Priorität** (hoch/normal/niedrig) | ❌ API kennt keine Priorität | ✅ `importance` (high/normal/low — 1:1) | ✅ `PRIORITY` bleibt beim Property-Preserving Merge byte-genau erhalten, wenn lokal unverändert (feine Werte 3/5 gehen nicht mehr verloren, D6 behoben); nur bei lokaler Änderung wird hoch→1/niedrig→9 geschrieben |
| **Erinnerung + Vorlauf** | ❌ API bietet keine Reminder → bleibt lokal erhalten (Server-Wins fasst `notification` nicht an) | ⚠️ `isReminderOn` + `reminderDateTime`; Vorlauf auf nächsten lokalen Wert gerundet (0/5/10/30 min, 1/5/12 h) | ✅ `VALARM` Zwei-Wege: relative DISPLAY-Alarme werden als `notification`+Vorlauf importiert und aus lokalen Erinnerungen als `TRIGGER:-PT…` geschrieben; fremde/andersartige Alarme bleiben beim Merge erhalten |
| **Wiederholung** | ❌ API stellt keine Recurrence bereit (auch Googles UI-Wiederholungen sind API-unsichtbar) → läuft rein lokal, neue Fälligkeit wird nach Abhaken hochgeladen | ✅/⚠️ `recurrence` — Details in Abschnitt 2 | ✅ fremde `RRULE` bleibt beim Property-Preserving Merge erhalten (wird nicht mehr zerstört); das Modul erzeugt weiterhin keine eigene RRULE (lokale Wiederholung läuft lokal) |
| **Menge (quantity)** | ➖ | ➖ | ➖ |
| **Wiedereröffnungs-Vorlauf** (recurrenceResetLeadTime) | ➖ | ➖ | ➖ |
| **Subtasks** | ❌ nicht gemappt (API hätte `parent`/`position`) | ❌ nicht gemappt (API hätte `checklistItems`) | ❌ nicht gemappt (RFC hätte `RELATED-TO`) |
| **Erstellt am** | — (lokal `time()` beim Import) | — (lokal `time()` beim Import) | ✅ `CREATED` |

---

## 2. Wiederholungen im Detail (Ist)

Lokales Modell: `w1/w2/w3` (1/2/3-wöchentlich), `m1` (monatlich), `q1` (quartalsweise), `y1` (jährlich), `custom` = h/d/w/m/y × 1–1000. Wiederholung setzt eine Fälligkeit voraus; beim Abhaken schiebt das Modul die Fälligkeit lokal weiter (Monats-/Jahressprünge mit Tages-Clamping, z. B. 31.01. → 28.02.).

| Lokal | → Microsoft | ← Microsoft | Google / CalDAV |
|---|---|---|---|
| w1/w2/w3 | `weekly` interval 1/2/3 (Wochentag der Fälligkeit) | `weekly` 1/2/3 → w1/w2/w3 | nur lokal |
| m1 / q1 | `absoluteMonthly` interval 1 / 3 | `absoluteMonthly` 1/3 → m1/q1 | nur lokal |
| y1 | `absoluteYearly` | `absoluteYearly` 1 → y1 | nur lokal |
| custom d/w/m/y × n | `daily`/`weekly`/`absoluteMonthly`/`absoluteYearly` × n | umgekehrt → custom × n | nur lokal |
| custom **h** (stündlich) | ⚠️ nicht abbildbar (Graph-Minimum: daily) — Task wird ohne Recurrence geschrieben, Serie läuft nur lokal | — | nur lokal (CalDAV könnte: `FREQ=HOURLY`) |
| — | — | ⚠️ `relativeMonthly`/`relativeYearly` („2. Dienstag") → lokal als m1/q1/y1 genähert; ⚠️ mehrtägige `weekly` (Mo+Mi+Fr) → als Einzel-Wochentag genähert. **Beides bleibt serverseitig exakt erhalten**, solange Fälligkeit/Muster lokal nicht geändert werden (B1-Raw-Round-Trip) | — |
| Serien-Ende | ⚠️ Modul schreibt immer `noEnd`; serverseitiges `endDate`/`numbered` wird lokal ignoriert, via Raw-Round-Trip aber bewahrt | | — |

> ⚠️ **Live-Befund (02.07.2026, B3):** Graph akzeptiert `recurrence` nur beim **Anlegen** (POST) — jeder PATCH mit einem recurrence-Objekt wird mit 400 abgelehnt (getestet mit MSA-Konto, sogar beim unveränderten Server-Muster). Das Modul lässt das Feld daher bei unverändertem Muster im PATCH weg (PATCH-Semantik erhält das Server-Muster — erfüllt das B1-Ziel besser als Raw-Zurücksenden). **Lokale Muster-Änderungen an bestehenden MS-Tasks erreichen den Server nicht** (Retry ohne recurrence liefert die übrigen Felder; das Server-Muster bleibt maßgeblich und wird reimportiert). Abgeschlossene Serien werden MS-nativ als `notStarted` + nächste Fälligkeit übertragen. Details: [SYNC-AUDIT.md](SYNC-AUDIT.md), Release-Testrunde.

---

## 3. Sync-Mechanik

| | Google | Microsoft | CalDAV |
|---|---|---|---|
| Inkrementell | `updatedMin`-Probe (Cursor+1 s) + 6h-Voll-Reconcile | `/tasks/delta`-Probe (nur Änderungssignal) | CTag/sync-token-Probe (Depth:0 PROPFIND) |
| Löschsignal | ✅ `deleted=true` (autoritativ) + Absence-Fallback | ⚠️ Abwesenheit im Voll-Fetch (`@removed` ungenutzt, trunkierungssicher) | ⚠️ Abwesenheit im vollen REPORT |
| Nebenläufigkeit | If-Match/ETag (⚠️ API-undokumentiert) | If-Match/ETag (⚠️ API-undokumentiert) | ✅ If-Match + If-None-Match (Standard) |
| Konfliktmodi | server/local/newest wins | server/local/newest wins | server/local/newest wins |
| Uhrzeit-Genauigkeit Fälligkeit | nur Datum | Minute (Anzeige nur Datum) | Sekunde |

---

## 4. Vorschlag: 1:1-Abbildung je Anbieter

**Vorgaben:** `quantity` (Anzahl) bleibt wie bisher rein lokal. Erinnerungen müssen bei **allen** Backends erhalten bleiben.

### 4.0 Leitprinzip: Raw-Round-Trip statt Rekonstruktion

Der Kern aller Verluste ist derselbe: Das Modul **rekonstruiert** die Server-Darstellung aus seinem schmaleren lokalen Modell, statt die Original-Darstellung zu bewahren. Das für die MS-Recurrence bereits etablierte B1-Muster („Raw speichern, unverändert zurücksenden, solange lokal nicht editiert") ist die generelle Lösung:

> **Pro Item die rohe Server-Repräsentation des jeweiligen Features speichern. Beim Upload nur die Teile neu erzeugen, die der Nutzer lokal tatsächlich geändert hat — alles andere verbatim zurückschreiben.**

Für CalDAV geht das noch einen Schritt weiter (Abschnitt 4.1): dort liefert der REPORT ohnehin das komplette `.ics` — statt einzelner Raw-Felder wird das ganze Objekt bewahrt und nur eigene Properties ersetzt (**Property-Preserving Merge**). Das ist die Standard-Arbeitsweise nativer CalDAV-Clients.

### 4.1 CalDAV — größter Hebel (Property-Preserving Merge)

Der REPORT liefert bereits die vollständige `calendar-data` pro Item. Vorschlag:

1. **Rohes VTODO speichern** (`caldavRaw` pro Item, beim Merge aus der REPORT-Antwort; ~1–3 KB pro Task im Items-Attribut).
2. **Upload = Merge statt Neubau:** gespeichertes Raw parsen, **nur die vom Modul verwalteten Properties ersetzen** (`SUMMARY`, `DESCRIPTION`, `DUE`, `PRIORITY`-Klasse, `STATUS`/`PERCENT-COMPLETE`/`COMPLETED` nur bei geändertem Done-Zustand, `LAST-MODIFIED`, `DTSTAMP`, `SEQUENCE`+1) — alles andere (**`VALARM`, `RRULE`, `CATEGORIES`, `ATTACH`, `X-*`, `RECURRENCE-ID`-Overrides, `VTIMEZONE`**) bleibt byte-genau erhalten. Neu angelegte Tasks bauen weiterhin frisch.
3. Damit erledigen sich D5 (STATUS/PERCENT lossy) und D6 (PRIORITY lossy) automatisch: fremde Werte werden nie mehr überschrieben, nur bei lokaler Änderung klassifiziert gesetzt.

Darauf aufbauend die Feature-Schließungen:

| Feature | Maßnahme |
|---|---|
| **Erinnerungen (Pflicht)** | `VALARM` lesen + schreiben: beim Import erstes `ACTION:DISPLAY`-VALARM mit relativem `TRIGGER` (`-PT…`, `RELATED=END` = relativ zur Fälligkeit) → `notification=true` + Vorlauf (auf lokale Stufen gerundet, **Original-Trigger im Raw erhalten**). Beim Upload: eigenes VALARM nur anfassen, wenn der Nutzer Erinnerung/Vorlauf lokal geändert hat; fremde/weitere VALARMs bleiben durch den Merge unangetastet. |
| **Wiederholungen** | `RRULE` schreiben: `w1/w2/w3`→`FREQ=WEEKLY;INTERVAL=n`, `m1`→`FREQ=MONTHLY`, `q1`→`FREQ=MONTHLY;INTERVAL=3`, `y1`→`FREQ=YEARLY`, `custom h/d/w/m/y`→`FREQ=HOURLY/DAILY/WEEKLY/MONTHLY/YEARLY;INTERVAL=n` — CalDAV ist das **einzige** Backend, das auch „stündlich" abbilden kann. Lesen: einfache RRULEs (FREQ+INTERVAL) → lokales Modell; komplexe (BYDAY-Listen, COUNT/UNTIL) → wie bei MS als Näherung anzeigen, Original via Merge erhalten. Semantik: beim Abhaken weiterhin `DUE` verschieben und Task wieder öffnen (kompatibel mit Apple Reminders/Tasks.org-Verhalten für wiederkehrende VTODOs). |
| **Ganztags-Fälligkeit (D3)** | Merkmal `dueIsAllDay` aus dem Import (`DUE;VALUE=DATE`) übernehmen; beim Upload dann wieder `VALUE=DATE` schreiben (lokale Mitternacht = Ganztag). Setzt der Nutzer lokal eine Uhrzeit, wird auf DATE-TIME gewechselt. |
| **Voraussetzung** | Zeilenfaltung nach 75 Oktetten beim Serialisieren (D4) — Pflicht, sobald Raw-Blöcke und RRULE/VALARM geschrieben werden. Escaping ist seit R8 RFC-konform. |

### 4.2 Microsoft — kleine Lücken schließen

| Feature | Maßnahme |
|---|---|
| **Erinnerungen (Pflicht)** | Bereits synchronisiert. 1:1-Verbesserung: rohes `reminderDateTime` als `microsoftReminderRaw` speichern und verbatim zurücksenden, solange Erinnerung/Vorlauf/Fälligkeit lokal unverändert sind (B1-Muster) → die Rundung auf lokale Vorlauf-Stufen verfälscht dann keine fremd gesetzten Erinnerungszeiten mehr. |
| **Status-Vielfalt** | Rohen `status` speichern (`microsoftStatusRaw`); beim Upload verbatim zurücksenden, wenn der Done-Zustand lokal unverändert ist. Nur bei lokalem Abhaken/Reopen `completed`/`notStarted` schreiben → `inProgress`/`waitingOnOthers`/`deferred` überleben. |
| **Fälligkeits-Anzeige (B2)** | `dueDateTime`/`reminderDateTime` in der lokalen IANA-Zone schreiben (`{'dateTime': lokale Wanduhrzeit, 'timeZone': '<IANA>'}` — Graph akzeptiert IANA-Namen) statt UTC → kein Anzeige-Tagessprung bei mitternachtsnahen Zeiten mehr. Fallback UTC, falls die Zone nicht ermittelbar ist. |
| **Notizen** | `body.contentType` vom Server merken (`microsoftBodyType`); HTML-Bodies bei lokal unveränderter Notiz verbatim (als `html`) zurücksenden statt als `text` zu degradieren. |
| **Serien-Ende / relative Muster** | Erhalt läuft seit dem B3-Fix über **Weglassen** von `recurrence` im PATCH (Graph lehnt recurrence-Writes auf Updates ohnehin ab, s. Live-Befund oben) — verlustfrei für Enddatum, „2. Dienstag", Mehrfach-Wochentage. Eine echte lokale Abbildung hieße das lokale Recurrence-Modell erweitern — nur sinnvoll, wenn die UI das auch anbieten soll. |
| **Stündlich (`custom h`)** | Von Graph nicht abbildbar (Minimum daily) → bleibt dokumentiert lokal. Alternative wäre nur eine verfälschende Näherung — nicht empfohlen. |

### 4.3 Google — API-Decke ist niedrig, Erhalt statt Mapping

Die Tasks-API kennt **weder** Uhrzeit, Priorität, Erinnerung **noch** Wiederholung. „1:1" heißt hier: lokale Werte dürfen durch den Sync nie verloren gehen — das ist heute bereits der Fall (`GoogleApplyServerToLocal` fasst `priority`, `notification`, `recurrence`, `quantity` nicht an) und wird als Garantie festgeschrieben:

| Feature | Maßnahme |
|---|---|
| **Erinnerungen (Pflicht)** | Bleiben lokal persistent über alle Konfliktmodi (Ist-Zustand, per Test absichern). In der Google-App sind sie prinzipbedingt unsichtbar — dokumentieren. **Nicht empfohlen:** Metadaten-Marker in `notes` einbetten (verschmutzt die Notiz in der Google-UI und ist fragil). |
| **Uhrzeit der Fälligkeit** | `MergeDueWithLocalTime` (Ist) ist bereits das Maximum: Datum vom Server, Uhrzeit lokal konserviert. |
| **Wiederholung/Priorität** | Rein lokal (Ist). Kein API-Weg vorhanden. |
| **Optional: Subtasks** | Einziges realistisches Zusatz-Feature der Google-API (`parent`/`position`, schreiben via `tasks.move`) — nur relevant, falls das lokale Modell je Subtasks bekommt. |

### 4.4 Bleibt bewusst lokal

- **Menge (`quantity`)** — per Vorgabe lokal; kein Anbieter hat ein passendes Feld (Missbrauch von Titel/Notiz wäre verlustanfällig).
- **Wiedereröffnungs-Vorlauf (`recurrenceResetLeadTime`)** — Symcon-spezifische Semantik ohne Gegenstück bei allen drei Anbietern.

### 4.5 Priorisierung & Aufwand

| # | Paket | Nutzen | Status |
|---|---|---|---|
| 1 | **CalDAV Property-Preserving Merge** + Zeilenfaltung | Beendet jede Fremddaten-Zerstörung (VALARM/RRULE/CATEGORIES/X-Props), erledigt D4/D5/D6 | ✅ umgesetzt (v2.7) |
| 2 | **CalDAV VALARM** lesen/schreiben (Zwei-Wege) | Erinnerungs-Pflicht für CalDAV erfüllt | ✅ umgesetzt (v2.7) |
| 3 | **MS Raw-Erhalt** für Reminder, Status, Body-Typ | Erinnerungs-/Status-/Notiz-Treue bei Fremd-Edits | offen (klein, B1-Muster) |
| 4 | **MS lokale Zeitzone** für due/reminder (B2) | korrekte Datumsanzeige in MS To Do | ✅ umgesetzt (v2.6) |
| 5 | **CalDAV RRULE** schreiben/lesen | eigene Wiederholungen serverseitig sichtbar (inkl. stündlich); fremde bleiben schon jetzt erhalten | offen (mittel) |
| 6 | **CalDAV VALUE=DATE** (D3) | echte Ganztags-Aufgaben erzeugen; fremde bleiben schon jetzt erhalten | teilweise (Erhalt via #1; Neuanlage offen) |
| 7 | Google-Erhalt-Garantien testen + dokumentieren | Erinnerungs-Pflicht für Google abgesichert | offen (minimal) |

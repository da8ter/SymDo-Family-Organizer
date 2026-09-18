# SymDo (List) — Regeln für den Sicherheits-Review

Kontext: Symcon-PHP-Module (`IPSModuleStrict`, Traits unter `SymDoGateway/libs`)
plus Web-App und Kacheln (`module.html`, Vanilla JS). Läuft als root auf dem
Hausserver; die Hooks unter `/hook/lists/…` sind aus dem LAN und über Symcon
Connect erreichbar. Deutsche Kommentare sind Hausstil; ein Kommentar an der
Zeile, der erklärt, warum etwas sicher ist, gilt als Ausnahme.

## Vertrauensgrenzen
- Authentifiziert: `/hook/lists/app/v1/*` (Bearer-Token je Gerät, `ApiRouter.php`).
  Jede neue Route muss durch denselben Router und dieselbe Geräteprüfung.
  Auftragsabholung (`ai/jobs/{id}`) ist gerätegebunden: fremdes Gerät → 404
  `job_not_found`, nie ein Hinweis, dass die Kennung existiert.
- Tokenlos: `lists/webapp` (nur die Seite), `lists/ws` (nur `{"t":"dirty"}` /
  `{"t":"job","id"}`), `lists/pwa`. Dort dürfen NIE Bestandsdaten, Namen oder
  Kennungen außer Zufalls-IDs hinaus.
- Mail-Webhook `/mail/hook/<geheimnis>`: HMAC-Signatur über `timestamp.token`
  prüfen UND Empfänger muss in `MailAddresses` stehen (sonst 406). Nichts
  verarbeiten, wenn eines fehlt.
- Kachel-Relay (`AiRelayBody`): nur Instanzen, die `IsSymDoWebAppInstance`
  bestätigt, dürfen `AiResult` bekommen — bei jeder Antwort erneut prüfen.

## HTML / XSS
- `EduHtml()` ist die EINZIGE Weißliste für HTML im Klassenseiten-Bestand
  (Feld `html`). Jeder Schreibweg, der `html` setzt, ohne durch `EduHtml` zu
  gehen, liefert fremden Code (Schulseiten, Moodle, Mail) ins `innerHTML` der
  App → HIGH.
- Nutzer- und Fremdtext kommt nur über `escapeHtml()` (SymDoWebApp) bzw.
  `escHtml()` (Kacheln) in Markup. `innerHTML` mit Template-Strings ist
  Hausstil, solange JEDE Variable darin durch den Escaper geht.
- Payloads aus dem eigenen Gateway (`handleMessage`) sind vertrauenswürdig;
  Inhalte aus Klassenseiten, Moodle, Mails und KI-Antworten sind es NICHT.

## Ausgehende Anfragen (SSRF)
- Jede URL aus Formular, App, Mail, Klassenseite oder KI-Antwort geht nur über
  `AiRecipePage::istOeffentlich()` / `AiRecipePage::holen()` /
  `AiFetchPublicPage()` hinaus (private Netze, Loopback, Redirects,
  Größendeckel). Push-Endpunkte zusätzlich mit `CURLOPT_RESOLVE` gegen
  DNS-Rebinding. `curl_init($url)` mit fremder URL ohne diese Prüfung → HIGH.
- Traits aus `SymDoGateway/libs` laufen AUCH in `SymDoScanner`-Instanzen; dort
  gibt es Gateway-Hilfen wie `AiIsPublicUrl` nicht. Prüfung statisch aus
  `AiRecipePage` nehmen, sonst stirbt der Scanner still an einem Fatal.
- `MoodleDatei` hängt den Token an die URL — diese URL nie protokollieren.

## Geheimnisse
- `settings.json` ist weltlesbar und Klartext, Properties UND Attribute;
  `PasswordTextBox` maskiert nur die Anzeige. NEUE Zugangsdaten oder Tokens in
  Property/Attribut → Finding. Bekannt und nicht erneut melden: `MoodleTokens`,
  KI-Anbieterschlüssel, IMAP über das Kernmodul.
- Kennwörter laufen einmalig durch ein Formularfeld und werden sofort geleert
  (Muster `MoodleTokenHolen`). Nie in `SendDebug`/`LogMessage`: Kennwörter,
  Tokens, Bearer, Mailinhalte, Klassenseiten-URLs — das Protokoll ist weltlesbar.
- KI-Schlüssel werden zur Laufzeit per `IPS_GetConfiguration(KonfigID())`
  gelesen und nie in einen Spool geschrieben (`symdo_aijobs`, `symdo_scankanal`).
  Spool: Verzeichnisse 0700, Dateien 0600, `tmp` + `rename()`; Nutzlast nach
  dem Anbieteraufruf löschen.

## Fremde Systeme — nur lesend
- Moodle/LOGINEO: nur Funktionen aus `MoodleCalc::ERLAUBT` dürfen gerufen
  werden; `MoodleRest` weist alles andere ab. Neue Funktionen müssen lesend
  sein und auf die Liste. `MoodleRest` umgehen → HIGH (Schreibzugriff auf ein
  Schulkonto).
- WebUntis: drei Fehlanmeldungen sperren das Schulkonto. Keine Login-Wieder-
  holung in Schleifen; Fehlerzähler `UntisFails` respektieren.
- IMAP (`MailFetch`): nur `EXAMINE`, nie `SELECT`/`STORE`; CRLF in
  Zugangsdaten abweisen (Befehlsinjektion); kein Login ohne SSL.
- Anhänge: die BYTES bestimmen den Typ (`%PDF-`, JPEG/PNG-Magic), nie die
  deklarierte MIME; Deckel `MAIL_ATTACH_TOTAL_B64` und `OutputLimit()`.

## Datenverlust zählt wie eine Schwachstelle
- Eine ausgebliebene Antwort ist keine leere Liste: Abgleiche
  (`HomeworkImportieren`, `EduArchivAbgleichen`, CalDAV-Multistatus,
  Quellwechsel Alexa/Bring) dürfen nur bei BESTÄTIGT leerer Antwort löschen
  (`null` ≠ `[]`; Statuscode am `response`, nicht am `propstat`).
- Unumkehrbares zuletzt: Mail löschen, Merker setzen, Medien freigeben erst
  NACH erfolgreichem Speichern — und den Rückgabewert lesen.
- Netz-Rückfall wiederholt nur Lesen und Aktionen mit `clientActionId`; alles
  andere meldet den Fehler nach oben (sonst doppelte Notizen, zweiter KI-Auftrag).
- Widerruf der KI-Einwilligung: Wächter sitzt in `AiProvider::post()`, der
  einzigen Transportstelle — ein neuer Anbieterweg daran vorbei → HIGH.

## Symcon-Eigenheiten — KEINE Findings
- `@` vor `IPS_*`-Aufrufen ist Hausstil: Symcon WARNT statt zu werfen, und
  eine Warnung im Hook zerlegt die HTTP-Antwort. Nicht als Fehlerunter-
  drückung melden.
- `IPS_SemaphoreEnter($name, 0)` ist Absicht: eine Instanz ist serialisiert,
  Warten liefe ins Leere.
- `exec()` ist erlaubt (`proc_open` hängt in Symcon); Argumente immer mit
  `escapeshellarg`. Auf Docker/SymBox gibt es keine externen Werkzeuge.
- Symcon läuft als root: Dateirechte schützen nichts. Pfade unter
  `IPS_GetKernelDir()` nur aus Kennungen und Zufall bauen, nie aus
  Nutzereingaben (Path Traversal).
- Richtungsregel: die Gateway-Spur ruft nie synchron in einen Scanner
  (`SDSC_*`, `IPS_RequestAction(<Scanner>)`) — Deadlock. Umgekehrt erlaubt.
- Zahlenartige Kennungen werden als PHP-Array-Schlüssel zu `int`: vor
  `in_array(..., true)` immer `(string)` casten (Mitglieder-IDs wie `57648139`).

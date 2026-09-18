# SymDo (List) — Regeln für den Sicherheits-Review

Symcon-PHP-Module (`IPSModuleStrict`; Traits in `SymDoGateway/libs`, IPS-freie
Kerne in `libs/`) plus Web-App/Kacheln (`module.html`, Vanilla JS). Läuft als
root; Hooks `/hook/lists/…` sind aus LAN und Symcon Connect erreichbar. Ein
Kommentar an der Zeile, warum etwas sicher ist, gilt als Ausnahme.

## Vertrauensgrenzen
- `/hook/lists/app/v1/*` läuft durch `ApiRouter::AuthenticateRequest` (Token je
  Gerät: `Authorization: Bearer`, `X-Symdo-Token` oder `?t=` für Assets/Medien).
  Vor der Prüfung liegen NUR `POST v1/pair`, `GET v1/ping`, Mail-Webhook
  `v1/mail/hook/<geheimnis>` — jede weitere Route davor → HIGH.
- `ai/jobs/{id}`: 404 `job_not_found` für fremdes Gerät. Bekannt: Aufträge ohne
  Gerät (Kachel, Post, Briefing, `device = ''`) bekommt jedes gepaarte Gerät,
  das die 24-Hex-Kennung kennt.
- Tokenlos: `lists/webapp` (Seite + `window.__SYMDO__`: Schalter, eigene
  Adressen wie `localBase`, VAPID-Key, Weckwort — keine Mitglieder-, Bestands-
  oder Instanzdaten), `lists/ws` (nur `{"t":"dirty"}`/`{"t":"job","id"}`),
  `lists/pwa`, OAuth-Rückrufe `todogateway_google/_microsoft` (nur `state`),
  `shoppinglist/assets/<id>` (eigener `WebHookToken` in `?t=`).
- Mail-Webhook: Pfadgeheimnis (`hash_equals`) UND HMAC über `timestamp.token`
  UND Empfänger in `MailAddresses` (406) — vorher wird nichts gespeichert.
- Kachel-Relay (`AiRelayBody`): `AiResult` nur an Instanzen, die
  `IsSymDoWebAppInstance` bestätigt — bei jeder Antwort erneut prüfen.

## HTML / XSS
- `EduHtml()` ist die EINZIGE Weißliste für HTML im Klassenseiten-Bestand
  (Feld `html`; Erzeuger `EduLesen`, `MoodleLesen`, `EduEinpflegen`). Ein neuer
  Schreibweg daran vorbei → HIGH. Kopien geprüfter Karten in `EduStoreCalc`
  zählen nicht.
- Fremd-/Nutzertext nur über den Escaper der Datei: `escapeHtml()` (SymDoWebApp,
  ToDoList, ShoppingList, Notes, Homework, Edumaps, ShoppingListOverview),
  `esc()` (Stundenplan, VRR), `escHtml()` (Chores); MealPlan, Routines,
  ToDoOverview setzen `textContent`. `innerHTML` mit Template-Strings ist
  Hausstil, solange JEDE Variable durch den Escaper geht.
- `handleMessage`-Payloads sind STRUKTURELL vertrauenswürdig (eigenes Gateway),
  ihr Inhalt nicht (WebUntis, Moodle, Mail, KI): escapen, nur `html` aus
  `EduHtml` roh. `message`-Listener müssen `event.source` gegen `window`/
  `window.parent` prüfen (SDWA-Familie ja; Chores, Voice, MealPlan, Routines,
  ToDoOverview noch nicht).

## Ausgehende Anfragen (SSRF)
- **Empfangene** URLs (App, Mail, Klassenseite, Moodle-Karte, Produkt-API,
  KI-Antwort) nur über `AiRecipePage::istOeffentlich()`/`::holen()` bzw.
  `AiFetchPublicPage()` (private Netze, Loopback, Redirects von Hand, 2-MB-
  Deckel, `CURLOPT_RESOLVE`). `curl_init($url)` mit empfangener URL ohne
  Prüfung oder mit `CURLOPT_FOLLOWLOCATION` → HIGH. **Vom Admin eingestellte
  Ziele** (`LocalUrl`, `AiLocalBaseUrl` = LM Studio im LAN via `AiHttp::post`,
  `UntisServer`, `CalDAVServerURL`, OAuth-Token-URLs) dürfen privat sein —
  kein Finding.
- Push: `PushZiel::pruefen()/erlaubt()` (`libs/PushZiel.php`: https, 443, keine
  IP-Literale) ist der Riegel. Mailgun-Anhänge: Host-Allowlist
  `*.mailgun.net/.org` + https — akzeptierte Alternative.
- Traits aus `SymDoGateway/libs` laufen AUCH in `SymDoScanner`; Gateway-Hilfen
  wie `AiIsPublicUrl` fehlen dort → statisch `AiRecipePage::istOeffentlich`.
- Moodle-Datei-URLs tragen den Token (`MoodleCalc::MitToken`) — nie protokollieren.

## Geheimnisse
- `settings.json` ist weltlesbar, Klartext, Properties UND Attribute;
  `PasswordTextBox` maskiert nur die Anzeige. **Bestand, nicht erneut melden:**
  `UntisPassword`, `CalDAVPassword`, `MoodlePassword` (Property; geleert nach
  dem Tokenholen, gespeichert falls vorher „Übernehmen"), `MoodleTokens`,
  KI-Anbieterschlüssel, `Tts*Key/Secret`, `Google/MicrosoftClientSecret`,
  `MailHookSecret/SigningKey/ApiKey`, OAuth-Attribute `*AccessToken`/
  `*RefreshToken` (XOR mit vorhersagbarem `TDL_<Instanz>_<Prefix>` = Klartext).
  IMAP-Zugang aus dem Kernmodul. **Jedes NEUE** Geheimnis in Property/Attribut
  → Finding; ebenso eine Kopie eines bestehenden an eine weitere Stelle.
- Nie in `SendDebug`/`LogMessage`: Kennwörter, Tokens, Bearer, Antwortkörper
  von Token-Tauschen, Mailinhalte, Klassenseiten-/Moodle-Datei-URLs.
- KI-Schlüssel nur zur Laufzeit per `AiProp()`, nie in einer Spool-Datei.
  Spools `symdo_aijobs`, `symdo_scankanal`, `symdo_mailhook`: 0700/0600,
  `tmp` + `rename()`. Akzeptiert: Moodle-Token in Datei-URLs der Karten im
  Scan-Kanal. KI-Nutzlast fällt nach dem ENDGÜLTIGEN Ergebnis; bei Vertagung
  (`ai_busy`, `ai_rate_limited`, `ai_unreachable`) bleibt sie absichtlich.

## Fremde Systeme — nur lesend
- Moodle: nur `MoodleCalc::ERLAUBT`, `MoodleRest` weist Rest ab; neue Funktionen
  lesend und auf die Liste. `MoodleRest` umgehen → HIGH.
- WebUntis: drei Fehlanmeldungen sperren das Schulkonto — keine Login-Schleifen,
  `UntisFails`/`UntisGesperrt()` respektieren.
- Eigener IMAP-Zugriff (`MailFetch`): nur `EXAMINE` + `UID FETCH`, nie
  `SELECT`/`STORE`; CRLF in Zugangsdaten abweisen; kein LOGIN ohne SSL.
  Gelöscht wird nur über das Kernmodul `IMAP_DeleteMail` (`MailDeleteAfter`).
- Anhänge: `kind` aus dem deklarierten Typ, die BYTES müssen passen (`%PDF-`,
  JPEG/PNG-Magic), sonst verworfen. Deckel `MAIL_ATTACH_TOTAL_B64`, `OutputLimit()`.

## Datenverlust zählt wie eine Schwachstelle
- Ausgebliebene Antwort ≠ leere Liste. Die Unterscheidung liegt bei den LESERN
  (`null`/`false`: `UntisLesen`, `MoodleLesen` `?array`, `ListSource::Read():
  array|false`, `EduSeitenLesen` lässt Unlesbares weg). `HomeworkImportieren`,
  `EduArchivAbgleichen`, `ExternalListSync` räumen bei `[]` legitim auf — wer
  einen Fehler VOR dem Aufruf in `[]` verwandelt, löscht Bestand → HIGH.
- CalDAV-Multistatus: am `response` heißt 404/410 „fort"; ab 400 am `propstat`
  bricht der Abruf ab und ist NIE eine Löschung.
- Merker (`MailSeenUIDs`, `EduSeen`, `MoodleSeen`) werden ABSICHTLICH beim
  Einreihen gesetzt und bei Fehlschlag via `MailMerkerZuruecknehmen` zurück-
  genommen (nach `MAIL_FAIL_MAX` bleibt er). Unumkehrbares ZULETZT, nach
  geprüftem Speichern: Mail löschen (`MailAuftragAbschliessen`), Medien freigeben.
- Netz-Rückfall wiederholt nur Lesen und Aktionen mit `clientActionId`.
- KI-Widerruf: der Transport-Wächter (`AiProvider::post` wirft `AiWiderrufen`)
  ist nur in der Auftragsspur scharf (`AiJobRunner` → `abbruchWaechter`);
  synchrone Wege prüfen vor dem Aufruf. Direkte `AiHttp::post`/`AiHttpPost`-Wege
  (TTS, Voice, Gerichtsbilder, Doku-Einbettung) sind Bestand; ein NEUER
  Anbieterweg ohne Einwilligungsprüfung → Finding.

## Symcon-Eigenheiten — KEINE Findings
- `@` ist Hausstil bei `IPS_*`, `$this->Read/WriteAttribute*`, `SetTimerInterval`,
  `RegisterOnceTimer`, `PREFIX_*`-Aufrufen: Symcon WARNT statt zu werfen, eine
  Warnung im Hook zerlegt die HTTP-Antwort. `@` an Netz-, `openssl_*`- und
  Datei-Aufrufen bleibt prüfbar, wenn der Rückgabewert nicht gelesen wird.
- `IPS_SemaphoreEnter($name, 0)` ist Absicht bei Sperren mit `InstanceID` im
  Namen (eine Instanz ist serialisiert). Instanzübergreifende Sperren
  (`SDSC_AiJob_<gateway>`, ShoppingList 500 ms) warten — ein neues 0-Warten
  auf einer geteilten Sperre wäre ein still übersprungener Schreibvorgang.
- `exec()` ist erlaubt (`proc_open` hängt); Argumente mit `escapeshellarg`.
- root: Dateirechte schützen nichts. Pfade unter `IPS_GetKernelDir()` nur aus
  Kennungen/Zufall, nie aus Nutzereingaben.
- Richtungsregel: die Gateway-Spur ruft nie synchron in einen Scanner
  (`SDSC_*`, `IPS_RequestAction(<Scanner>)`) — Deadlock. Umgekehrt erlaubt.
- Zahlenartige Kennungen werden als Array-SCHLÜSSEL zu `int`: Schlüssel aus
  `array_keys()`/`foreach ($a as $k => …)` mit `(string)`/`array_map('strval', …)`
  zurückwandeln — den Needle zu casten reicht nicht (`in_array(…, true)`).

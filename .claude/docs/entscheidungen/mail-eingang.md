# Mail-Eingang über Mailgun

Eingehende Mails (Elternbriefe, Schulpost) erreichen das Gateway über einen Mailgun-Webhook und werden zu KI-Vorschlägen. Die Einrichtung macht ein Knopf im Gateway-Formular per API-Schlüssel. Formate, Fristen und Antwortcodes stehen ausführlich in `SymDoGateway/libs/MailgunSetup.php` und am Webhook in `MailScan.php`; hier die Entscheidungen dahinter.

## Entscheidungen

- **Route `forward`, nicht `store(notify=…)`.** Bei Sandbox-Domains verweigert Mailgun den Abruf gespeicherter Nachrichten; über `store` kämen Anhänge nur als Metadaten. `forward` liefert die ganze Mail als `multipart/form-data` samt Anhang; dafür genügt der Webhook-Signaturschlüssel.
- **Eine Route je Domain, nicht `catch_all`** (`match_recipient(".*@domain$")`, dann `forward(...)` und `stop()`). Das Konto kann weitere Domains haben. Eine vorhandene catch_all-Handroute auf dieses System wird zur Domain-Route umgebaut.
- **Der Knopf schreibt nur die eigene Route und löscht nie** (kein DELETE). Das ist die einzige Ausnahme von der Hausregel „Fremde Systeme — nur lesend" (`.claude/claude-security-guidance.md`).
- **Signaturschlüssel per `GET /v5/accounts/http_signing_key`, nie POST** — ein POST würde ihn neu erzeugen und jede andere Anbindung des Kontos brechen.
- **Region:** zuerst die gemerkte, sonst beide durchsuchen. Routen gelten je Region, Schlüssel in beiden.
- **Der API-Schlüssel wird dauerhaft gespeichert** (Entscheidung des Betreibers). Er dient heute dazu, die Route beim Tokenwechsel nachzuführen; neue Mitglieder brauchen ihn nicht, das erledigen die Standardadressen. Wie alle Gateway-Schlüssel steht er im Klartext in der `settings.json` — das Formular empfiehlt deshalb einen eigenen, auf Mailgun beschränkten Schlüssel.
- **Standardadressen:** ein Mitglied ohne eigene Zeile in `MailAddresses` bekommt automatisch eine Adresse (`MailHookAddressFor`), sichtbar im Formular und in der App (`intake`). Eine gespeicherte leere Zeile heißt „aus"; eine ganz leere Liste heißt NICHT „alle aus". Dubletten bekommen einen Kennungsanhang.
- **Region des Kontos:** für Post mit Kindernamen ist die EU-Region vorzuziehen. Ein Wechsel geht nur durch Neuanlegen der Domain in der anderen Region.

## Fallen/gemessen

- **Antwortsemantik:** 200 = erledigt, 406 = endgültig verworfen (kein neuer Versuch), alles andere = Mailgun wiederholt über mehrere Stunden. Ein 406 auf ein unerwartetes Format kostet die Mail — so ging die erste verloren (Hook erwartete JSON, Mailgun schickte Formulardaten).
- **Symcons Webserver zerlegt multipart vollständig** (Felder in `$_POST`, Datei in `$_FILES`, `php://input` dann leer), reicht aber `CONTENT_TYPE`/`CONTENT_LENGTH` nicht durch. Eine Größenwache muss `$_FILES[...]['size']` summieren. Die hochgeladene Datei verschwindet mit der Anfrage und muss im Hook sofort gelesen werden.
- **Eine Region ohne Domains antwortet `{"items":null,"total_count":0}`**, nicht mit einer leeren Liste. Das galt zunächst als Störung und brach die Suche ab (behoben in `7ebc576`); die Attrappe liefert seitdem ebenfalls `null`.
- **Testwerte nie im Format echter Schlüssel.** GitHubs Push-Schutz blockierte einen erfundenen Testschlüssel im Mailgun-Format (`key-` + 32 Hex).
- Der Mail-WEBHOOK bleibt bewusst im Gateway, nicht im Scanner: sein Zustand ist die Spool-Datei (voller Text und Wiederholungsvermerk in einem). Neue Mail-Vorschläge melden per Push nur Zahlen, keinen Betreff — der Webhook ist von außen erreichbar.

## Offen

Nur am lebenden System zu prüfen (nicht am Code prüfbar):
- ApplyChanges aus einer RequestAction heraus.
- Ob der per v5 gelesene Signaturschlüssel dem im Dashboard angezeigten entspricht.
- Ob `/v3/routes/match` eine eben angelegte Route sofort sieht.
- Groß-/Kleinschreibung bei `match_recipient`.
- Darstellung des aufklappbaren Bereichs „Manual setup" in vierter Formularebene.

Stand: geprüft gegen den Code am 08.10.2026

# KI: Originale der Vorschläge

Seit Build 203 legt das Gateway zu jedem KI-Vorschlag das Original ab — den ausgewerteten Text und die Anhänge, die durch die Auswertung liefen. Ablauf und Aufbau stehen im Kopf von `SymDoGateway/libs/Originale.php` und `OriginalStore.php`; hier die Entscheidungen, deren Begründung dort fehlt oder leicht übersehen wird.

## Entscheidungen

- **Ordner im Kernel-Verzeichnis statt Medienobjekte** (`symdo_originale/<Instanz>/<Kennung>/`, Ordner 0700, Dateien 0600). Symcons Tagessicherung kopiert nur die `settings.json`, Medien gehen nur als Base64 hinein und heraus, und ein Ordner ist ein einziger Löschvorgang für Text und Anhänge.
- **„Original speichern" ist eine Nutzerwahl** in allen Hinzufügen-Dialogen, Vorgabe aus, Anhänge einzeln per Häkchen (Logos nicht vorgewählt). Notiz-Anhänge werden nicht mehr auf Vorrat übernommen; die Eigenschaft `MailNoteAttachments` ist seitdem wirkungslos und nur noch registriert.
- **Abgeleitete Originale statt Bitmasken.** „Original speichern" legt je Eintrag ein eigenes Original mit genau den gewählten Anhängen an (`originalBehalten`). So bleibt das Aufräumen rein verweisbasiert.
- **Die Kennung ist deterministisch** (sha1 über Vorschlagskennung und Textanfang) und reist beim Einreihen zusätzlich im Auftragskopf (`original`). Grund: der Text im Kopf läuft durch `json_encode` und käme sonst unter Umständen anders heraus — die nachgerechnete Kennung träfe dann ein anderes Original.
- **Aufräumen bricht ab, wenn eine Verweisquelle nicht lesbar ist.** Gesammelt wird aus Vorschlägen, KI-Aufträgen, Hausaufgaben, Notizen, Kalender-Verweisen und den App-Ständen aller ToDo-Instanzen. Ist eine davon nicht lesbar, wird NICHTS gelöscht — lieber ein Original zu viel als eines, auf das ein Eintrag noch zeigt. Schonfrist 1 h, Speicherdeckel als Experteneinstellung.
- **Anhänge in Stücken ausliefern.** Symcon gibt je Hook-Antwort rund 1 MB aus; deshalb `GET /v1/original/<id>/<n>?teil=k` mit Kopf `X-SymDo-Teile`, für die Kachel die Aktion `originalTeil` (Base64).
- Die Experteneinstellungen für Anhangsgrenzen ersetzen die früheren Konstanten von Mail, Klassenseiten und LOGINEO. „MB" heißt überall MiB; die Klassenseiten dürfen damit bewusst 5 % mehr als vorher.

## Fallen/gemessen

- Im Web-App-Prüfstand (`SymDoWebApp/tests/OriginalAnsichtTest.php`) läuft `sdCall`/`AddItem` im Standalone-Adapter über `window.requestAction`, nicht über `__symdoApiPost`. Beide müssen umhüllt werden (Setter per `defineProperty`, weil der Adapter sie später setzt), dazu `fetch` für `/original/`.
- Headless-Chrome braucht `--force-prefers-reduced-motion`, sonst gelten geschlossene Blätter während der Animation noch als offen.

## Offen

- Die iOS-App zeigt die Originale noch nicht (Entscheidung „erst Web-App"; nicht am Code prüfbar).

Stand: geprüft gegen den Code am 08.10.2026

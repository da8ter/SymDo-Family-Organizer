<?php

declare(strict_types=1);

/**
 * Aufgaben aus weitergeleiteten E-Mails.
 *
 * Der Nutzer leitet Post an ein Postfach weiter — je Familienmitglied eine
 * Adresse. Symcon liest die Mail über das Kernmodul „E-Mail, Empfangen (IMAP)",
 * laesst dieselbe KI wie beim Foto-Scan Aufgaben daraus ableiten und legt die
 * Vorschlaege ab. Angelegt wird NICHTS von allein: die Web-App zeigt sie ueber
 * der Aufgabenliste, ein Tipp uebernimmt einen davon.
 *
 * Am Kernmodul gemessen (Symcon 9.0), nicht der Doku entnommen:
 *  - IMAP_GetCachedMails($id) → SenderName, SenderAddress, Recipient, Subject,
 *    Date (String!), UID, Flags — hoechstens `CacheSize` Eintraege.
 *  - IMAP_GetMailEx($id, $uid) → zusaetzlich ContentType, CharSet, Text. Der Text
 *    ist EIN fertig dekodierter Teil (text/plain oder text/html), kein rohes MIME.
 *  - Das Lesen setzt \Seen NICHT. Deshalb fuehrt dieser Trait seine eigene Liste
 *    erledigter UIDs; das Postfach bleibt unberuehrt.
 *  - Anhaenge kommen nicht mit. Steckt die Information im PDF, bleibt nur der
 *    Mailtext — dafuer gibt es in der App weiterhin „Datei analysieren".
 */
trait MailScan
{
    /** Konfiguration je PHP-Aufruf, siehe MailProp(). */
    private ?array $mailConfigCache = null;

    /** Der Absender einer WEITERGELEITETEN Mail ist der Weiterleitende, nicht die Quelle. */
    private const MAIL_TRIGGER_IDENTS = ['LastMessage', 'UnreadMessages'];

    /** Das Kernmodul kappt seinen Cache; die Vorgabe 10 verliert Mails unbemerkt. */
    private const MAIL_MIN_CACHE_SIZE = 50;

    /** Ein Anbieter-Aufruf darf 45 s dauern — deshalb eine Mail pro Timerlauf. */
    private const MAIL_TIMER_MS = 1500;

    private const MAIL_TEXT_MAX      = 12000;
    private const MAIL_PROPOSALS_MAX = 50;
    private const MAIL_RETENTION_DAYS = 21;
    private const MAIL_SEEN_MAX      = 500;
    /** Nach so vielen fehlgeschlagenen Analysen gilt eine Mail als erledigt —
     *  sonst blockiert eine dauerhaft scheiternde Mail alle neueren und kostet
     *  bei jedem Lauf erneut Geld beim Anbieter. */
    private const MAIL_FAIL_MAX      = 3;
    /** Wartezeit vor dem naechsten Versuch nach einem Fehlschlag; waechst je Versuch. */
    private const MAIL_RETRY_MS      = 120000;
    /** Schluessel der Fehlversuchs-Zaehler im MailSeenUIDs-Attribut ("imapID:uid" → Anzahl).
     *  Kollidiert nie mit einer Instanz-ID, weil die immer numerisch ist. */
    private const MAIL_FAIL_KEY      = '#fehl';
    /** Topf der ueber den Webhook eingegangenen Mails — kollidiert nie mit einer
     *  Instanz-ID, weil die immer numerisch ist (gleiche Ueberlegung wie oben). */
    private const MAIL_HOOK_KEY      = '#hook';
    /** Verzeichnis der Warteschlange, relativ zum Kernel-Verzeichnis. */
    private const MAIL_HOOK_DIR      = 'symdo_mailhook';
    /** Mehr wartende Mails heisst: etwas stimmt nicht — dann lieber verzoegern. */
    private const MAIL_HOOK_QUEUE_MAX = 50;
    /** Aelter als das wird beim naechsten Lauf weggeraeumt (Notbremse gegen Muell). */
    private const MAIL_HOOK_TTL      = 7 * 86400;
    /** Zeitfenster fuer Mailguns Signatur — aelteres ist ein Wiedereinspiel-Versuch. */
    private const MAIL_HOOK_SIG_AGE  = 900;
    /** So viele Zeilen weit wird nach dem zitierten Kopf gesucht (siehe MailDetectOrigin). */
    private const MAIL_ORIGIN_LINES  = 40;

    private function MailCreate(): void
    {
        $this->RegisterPropertyBoolean('MailEnabled', false);
        // Das Haushalts-Postfach: was hier liegt, gehoert niemandem bestimmten und
        // wird ohne Mitglied vorgeschlagen.
        $this->RegisterPropertyInteger('MailBoxGeneral', 0);
        // Liste: je Zeile EIN Mitglied, dazu freiwillig ein eigenes Postfach und
        // eine eigene Absenderliste. Ueber das Postfach laeuft die Zuordnung des
        // IMAP-Weges; wer sein eigenes hat, braucht dafuer keine Adressliste.
        $this->RegisterPropertyString('MailBoxes', '[]');
        // Liste: Empfaengeradresse → Mitglied. Gilt nur fuer den Webhook-Weg: dort
        // kommt alles auf derselben Domain an und nur der Plus-Tag verraet, wer
        // gemeint ist. Zugleich die Wache des oeffentlichen Endpunkts.
        $this->RegisterPropertyString('MailAddresses', '[]');
        // Freigegebene Absender, einer pro Zeile; auch „@domain.tld". Leer = alle.
        $this->RegisterPropertyString('MailSenderAllow', '');
        $this->RegisterPropertyInteger('MailDailyLimit', 20);
        // Ohne Formularfeld: Post loeschen zu lassen passt nicht zu einem Postfach,
        // in das der Nutzer selbst hineinsieht. Die Eigenschaft bleibt, damit eine
        // bestehende Einstellung weiter wirkt.
        $this->RegisterPropertyBoolean('MailDeleteAfter', false);
        // Anhaenge holt ein eigener, schmaler IMAP-Zugriff (Trait MailFetch) —
        // das Kernmodul liefert sie nicht. Abschaltbar, weil es eine zweite
        // Verbindung ins Postfach aufbaut.
        $this->RegisterPropertyBoolean('MailReadAttachments', true);
        // Vorgabe AUS: Anhaenge dauerhaft abzulegen ist das ueberraschende Verhalten,
        // und der Datenschutzhinweis sagt bisher das Gegenteil. Wer es will, schaltet
        // es ein. Gelesen wird die Eigenschaft ueber PushProp, damit sie vor dem
        // naechsten Kernel-Neustart nicht warnt.
        $this->RegisterPropertyBoolean('MailNoteAttachments', false);

        // ── Zweiter Eingang: Mail per Webhook (Mailgun) ──────────────────
        $this->RegisterPropertyBoolean('MailHookEnabled', false);
        // Teil des Hook-Pfades. Mailgun kann keine eigenen Kopfzeilen setzen,
        // deshalb reist das erste Geheimnis in der Adresse; die Signatur unten
        // ist die zweite, eigentliche Wache.
        $this->RegisterPropertyString('MailHookSecret', '');
        // Mailguns „HTTP webhook signing key" — damit wird die Signatur geprueft.
        $this->RegisterPropertyString('MailHookSigningKey', '');
        // Basisadresse bei Mailgun, nur fuer die Anzeige und den Eintrag-Knopf.
        $this->RegisterPropertyString('MailHookBase', '');
        $this->RegisterPropertyInteger('MailHookMaxKB', 1024);
        // Nur zum Nachladen der Anhaenge (Basic-Auth „api:<key>").
        // Ohne Formularfeld: Er zaehlt nur fuer den Weg „Store and notify", und der
        // scheitert bei Sandbox-Domains ohnehin am Abruf. Die Eigenschaft bleibt,
        // damit ein bestehender Schluessel weiter wirkt.
        $this->RegisterPropertyString('MailHookApiKey', '');

        $this->RegisterAttributeString('MailProposals', '[]');
        // Je Instanz die bereits verarbeiteten UIDs: {"26939":["1","2"]}
        $this->RegisterAttributeString('MailSeenUIDs', '{}');
        $this->RegisterAttributeString('MailDayCount', '{}');

        $this->RegisterTimer('MailScan', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'MailScan\', 0);');
    }

    private function MailApplyChanges(): void
    {
        foreach ($this->MailBoxIDs() as $imapID) {
            $this->MailEnsureCacheSize($imapID);
            foreach ($this->MailTriggerVariables($imapID) as $varID) {
                $this->UnregisterMessage($varID, VM_UPDATE);
                $this->RegisterMessage($varID, VM_UPDATE);
            }
        }
        // Nach einem Uebernehmen einmal nachsehen: waehrend der Konfiguration kann
        // Post eingetroffen sein, ohne dass ein Abo bestand — und in der
        // Webhook-Warteschlange kann etwas aus der Zeit vor einem Neustart liegen.
        if ($this->MailIsEnabled() || $this->MailHookIsEnabled()) {
            $this->MailArm();
        }
        // Der Datenschutzhinweis verspricht: Deaktivieren verwirft wartende
        // Mails. Ohne aktiven Hook bliebe die Warteschlange sonst liegen,
        // denn der Verfallsputz laeuft nur im dann abgeschalteten Timer.
        if (!$this->MailHookIsEnabled()) {
            $entfernt = $this->MailHookClearQueue();
            if ($entfernt > 0) {
                $this->LogMessage(sprintf('SymDo: %d wartende E-Mail(s) nach Abschalten des Mail-Empfangs verworfen', $entfernt), KL_NOTIFY);
            }
        }
    }

    /**
     * `CacheSize` fehlt im Formular des Kernmoduls und steht auf 10 — mehr als zehn
     * Mails auf einmal fielen hinten aus dem Cache, bevor die Analyse sie sieht.
     * Der Nutzer kann das nicht selbst korrigieren, also tun wir es.
     */
    private function MailEnsureCacheSize(int $imapID): void
    {
        $cfg = json_decode((string)@IPS_GetConfiguration($imapID), true);
        if (!is_array($cfg) || !array_key_exists('CacheSize', $cfg)) {
            return;
        }
        if ((int)$cfg['CacheSize'] >= self::MAIL_MIN_CACHE_SIZE) {
            return;
        }
        try {
            IPS_SetProperty($imapID, 'CacheSize', self::MAIL_MIN_CACHE_SIZE);
            IPS_ApplyChanges($imapID);
            $this->SendDebug('MailScan', sprintf(
                'CacheSize von #%d auf %d angehoben (war %d)',
                $imapID, self::MAIL_MIN_CACHE_SIZE, (int)$cfg['CacheSize']
            ), 0);
        } catch (Throwable $e) {
            $this->SendDebug('MailScan', 'CacheSize nicht setzbar: ' . $e->getMessage(), 0);
        }
    }

    /** true, wenn die Nachricht zu einem Postfach gehoerte und behandelt wurde. */
    private function MailMessageSink(int $Message, int $SenderID): bool
    {
        if ($Message !== VM_UPDATE || !$this->MailIsEnabled()) {
            return false;
        }
        foreach ($this->MailBoxIDs() as $imapID) {
            if (in_array($SenderID, $this->MailTriggerVariables($imapID), true)) {
                $this->MailArm();
                return true;
            }
        }
        return false;
    }

    private function MailRequestAction(string $Ident, mixed $Value): bool
    {
        if ($Ident === 'MailScan') {
            $this->MailScanRun();
            return true;
        }
        if ($Ident === 'MailForget') {
            // Nach einer Fehlkonfiguration (falsche Adresse, gesperrter Absender)
            // liegen die Mails noch im Postfach, gelten hier aber als erledigt.
            $this->MailWriteAttr('MailSeenUIDs', '{}');
            $this->LogMessage('SymDo: bereits gelesene E-Mails vergessen', KL_NOTIFY);
            $this->MailArm();
            return true;
        }
        if ($Ident === 'MailHookNewSecret') {
            // Das Pfad-Geheimnis erzeugen wir selbst; den Signaturschluessel traegt
            // der Nutzer aus Mailgun ein. Die Anleitung wird gleich mitgezogen, damit
            // die fertige Adresse ohne Umweg dasteht.
            $geheim = bin2hex(random_bytes(24));
            $teile = $this->MailHookSetupParts($geheim);
            $this->UpdateFormField('MailHookSecret', 'value', $geheim);
            $this->UpdateFormField('MailHookSetup', 'caption', $teile['hinweis']);
            $this->UpdateFormField('MailHookNotifyUrl', 'value', $teile['url']);
            $this->UpdateFormField('MailHookStatus', 'caption', $this->Translate('New token generated — press Apply to save it.'));
            return true;
        }
        if ($Ident === 'MailHookFillAddresses') {
            $this->MailHookFillAddresses();
            return true;
        }
        if ($Ident === 'MailScanNow') {
            foreach ($this->MailBoxIDs() as $imapID) {
                @IMAP_UpdateCache($imapID);
            }
            $this->MailArm();
            return true;
        }
        return false;
    }

    /**
     * Die Einrichtungsanleitung fuer Mailgun zusammensetzen.
     *
     * Steht hier und nicht im Formular, weil sie zweimal gebraucht wird: beim
     * Aufbau des Panels und direkt nach dem Erzeugen eines neuen Geheimnisses.
     */
    /**
     * Die Mailgun-Domain aus dem Feld lesen — egal, wie der Nutzer es gefuellt hat.
     *
     * Mailgun zeigt im Dashboard die DOMAIN (sandbox….mailgun.org), und genau die
     * traegt man ab; eine feste Postfachadresse gibt es dort nicht, weil eine eigene
     * Domain jede Adresse annimmt. Wer trotzdem eine volle Adresse eintraegt, soll
     * aber nicht auflaufen — deshalb hier beides.
     */
    private function MailHookDomain(): string
    {
        $basis = trim((string)$this->MailProp('MailHookBase', ''));
        if ($basis === '') {
            return '';
        }
        $domain = str_contains($basis, '@') ? (string)(explode('@', $basis, 2)[1] ?? '') : $basis;
        return trim(strtolower($domain), " \t<>");
    }

    /**
     * Die Einzelteile der Mailgun-Einrichtung.
     *
     * Mailgun fuehrt durch ein Formular mit Schaltern — dort gibt es kein Feld
     * fuer die API-Schreibweise („store(notify=…)"), sondern nur eines fuer die
     * blosse Adresse. Genau die liefert diese Funktion, damit sie sich markieren
     * und hinueberkopieren laesst.
     *
     * @return array{hinweis: string, url: string, bereit: bool}
     */
    private function MailHookSetupParts(string $geheim): array
    {
        $connect = $this->GetConnectUrl();
        if ($connect === '') {
            return [
                'hinweis' => $this->Translate('No Symcon Connect address found — without it Mailgun cannot reach this system. Set up Connect first.'),
                'url'     => '', 'bereit' => false,
            ];
        }
        if (strlen($geheim) < 24) {
            return [
                'hinweis' => $this->Translate('Press "Generate new token" and then Apply — the address for Mailgun appears here afterwards.'),
                'url'     => '', 'bereit' => false,
            ];
        }
        return [
            'hinweis' => $this->Translate('In Mailgun: Receiving → Create Route. Leave "Expression type" on "Catch all". Turn ON "Forward" and paste the address below into its "Destination" field — that way attachments come along. Turn ON "Stop" as well. Leave "Store and notify" OFF: for sandbox domains Mailgun refuses to hand out stored messages, so attachments would be lost. Priority stays 0. The signing key is under API Security → "HTTP webhook signing key".'),
            'url'     => rtrim($connect, '/') . '/hook/' . self::HOOK_PATH . '/v' . self::API_VERSION . '/mail/hook/' . $geheim,
            'bereit'  => true,
        ];
    }

    /**
     * Traegt fuer jedes Familienmitglied eine Plus-Adresse in die Zuordnungsliste ein.
     *
     * Rein oertlich: Bei Mailgun muss keine Adresse angelegt werden, die eine
     * Auffang-Route nimmt ohnehin jede an. Der Knopf erspart nur das Abtippen —
     * bestehende Zeilen bleiben unberuehrt, damit eine von Hand vergebene Adresse
     * nicht ueberschrieben wird.
     */
    /**
     * Die Zeilen der Adresstabelle: zuerst die allgemeine Familienadresse, dann
     * je Familienmitglied eine.
     *
     * Quelle sind die Mitglieder, nicht das Gespeicherte — sonst zeigte die
     * Tabelle Zeilen, die niemandem mehr gehoeren. Zugeordnet wird ueber die
     * Mitglieds-ID; die leere ID ist die allgemeine Adresse (Vorschlaege ohne
     * Mitglied), das Gegenstueck zum Haushalts-Postfach des IMAP-Weges.
     *
     * @return list<array{UserID: string, Name: string, Address: string, SenderAllow: string}>
     */
    private function MailAddressRows(): array
    {
        $vorhanden = [];
        foreach ((array)json_decode((string)$this->MailProp('MailAddresses', '[]'), true) as $zeile) {
            if (!is_array($zeile)) {
                continue;
            }
            $id = trim((string)($zeile['UserID'] ?? ''));
            // Die erste Adresse gewinnt: je Zeile ist genau eine vorgesehen.
            if (!array_key_exists($id, $vorhanden)) {
                $vorhanden[$id] = [
                    'Address'     => trim((string)($zeile['Address'] ?? '')),
                    'SenderAllow' => (string)($zeile['SenderAllow'] ?? '')
                ];
            }
        }
        $zelle = static fn(?array $alt, string $feld): string => (string)($alt[$feld] ?? '');

        $zeilen = [[
            'UserID'      => '',
            'Name'        => $this->Translate('Family (general)'),
            'Address'     => $zelle($vorhanden[''] ?? null, 'Address'),
            'SenderAllow' => $zelle($vorhanden[''] ?? null, 'SenderAllow')
        ]];
        foreach ($this->LoadUsers() as $u) {
            $id = (string)($u['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $zeilen[] = [
                'UserID'      => $id,
                'Name'        => (string)($u['name'] ?? ''),
                'Address'     => $zelle($vorhanden[$id] ?? null, 'Address'),
                'SenderAllow' => $zelle($vorhanden[$id] ?? null, 'SenderAllow')
            ];
        }
        return $zeilen;
    }

    /**
     * Adresse fuer eine Zeile bauen.
     *
     * Zwei Schreibweisen, beide sinnvoll: Wer nur die Domain eintraegt, bekommt je
     * Mitglied eine eigene Adresse (lena@…) — eine eigene Domain nimmt ohnehin
     * jede an, und das liest sich besser. Wer einen festen lokalen Teil vorgibt
     * (post@…), bekommt Plus-Adressen darunter (post+lena@…); dessen Grundadresse
     * ist dann die allgemeine.
     */
    private function MailHookAddressFor(string $lokal, string $domain, string $name, string $id): string
    {
        if ($id === '') {
            return ($lokal === '' ? 'familie' : $lokal) . '@' . $domain;
        }
        // Umlaute und alles Ungewohnte raus: die Adresse muss durch jeden
        // Mailserver passen, und der Tag ist ohnehin nur ein Erkennungszeichen.
        $tag = strtolower($name);
        $tag = strtr($tag, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $tag = preg_replace('/[^a-z0-9]/', '', $tag) ?? '';
        if ($tag === '') {
            $tag = substr($id, 0, 6);
        }
        return $lokal === '' ? $tag . '@' . $domain : $lokal . '+' . $tag . '@' . $domain;
    }

    private function MailHookFillAddresses(): void
    {
        $domain = $this->MailHookDomain();
        if ($domain === '') {
            $this->UpdateFormField('MailHookStatus', 'caption', $this->Translate('Please enter your Mailgun domain above first and press Apply.'));
            return;
        }
        $basis = trim((string)$this->MailProp('MailHookBase', ''));
        $lokal = str_contains($basis, '@') ? trim(explode('+', explode('@', $basis, 2)[0])[0]) : '';

        $zeilen = [];
        $neu    = 0;
        foreach ($this->MailAddressRows() as $zeile) {
            // Eingetragenes bleibt: der Knopf fuellt Luecken, er ueberschreibt nicht.
            if ($zeile['Address'] === '') {
                $zeile['Address'] = $this->MailHookAddressFor($lokal, $domain, $zeile['Name'], $zeile['UserID']);
                $neu++;
            }
            $zeilen[] = $zeile;
        }
        if ($neu === 0) {
            $this->UpdateFormField('MailHookStatus', 'caption', $this->Translate('Every row already has an address.'));
            return;
        }
        $this->UpdateFormField('MailAddresses', 'values', json_encode($zeilen, JSON_UNESCAPED_UNICODE));
        $this->UpdateFormField('MailHookStatus', 'caption', sprintf($this->Translate('%d address(es) added — press Apply to save.'), $neu));
    }

    /** Setzt den One-Shot-Timer. Die Arbeit gehoert nicht in den Nachrichten-Thread. */
    private function MailArm(int $ms = self::MAIL_TIMER_MS): void
    {
        try {
            $this->SetTimerInterval('MailScan', $ms);
        } catch (Throwable $e) {
            // Timer nach einem Modul-Reload ohne Kernel-Neustart noch nicht
            // registriert. Bewusst kein Ersatzlauf von hier: ein 45-s-Anbieteraufruf
            // im Nachrichten-Thread haette den Kernel blockiert.
            $this->SendDebug('MailScan', 'Timer fehlt, Lauf entfaellt', 0);
        }
    }

    /**
     * Genau EINE Mail pro Lauf. Bleibt danach noch etwas offen, setzt sich der
     * Timer selbst erneut — so bleibt jeder Lauf kurz und der Kernel frei.
     */
    private function MailScanRun(): void
    {
        try {
            $this->SetTimerInterval('MailScan', 0);
        } catch (Throwable $e) {
            // egal, siehe MailArm()
        }
        if (!$this->MailIsEnabled() && !$this->MailHookIsEnabled()) {
            return;
        }

        // Nur EIN Lauf gleichzeitig. Ohne das greifen sich zwei ueberlappende Laeufe
        // dieselbe Mail, bevor der erste sie vermerkt hat — gemessen an zwei
        // Handanstoessen kurz hintereinander: dieselbe Mail zweimal analysiert, also
        // zweimal beim Anbieter bezahlt. Der KI-Riegel verhindert nur den
        // gleichzeitigen Aufruf, nicht die Doppelarbeit.
        $riegel = 'SymDo_MailScan_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($riegel, 0)) {
            $this->SendDebug('MailScan', 'Lauf laeuft bereits, dieser entfaellt', 0);
            return;
        }
        try {
            $this->MailScanSchritt();
        } finally {
            IPS_SemaphoreLeave($riegel);
        }
    }

    /** Der eigentliche Lauf; von MailScanRun gegen Ueberlappung geschuetzt. */
    private function MailScanSchritt(): void
    {
        // Erst pruefen, ob das Ergebnis ueberhaupt ablegbar ist. Ein Anbieter-Aufruf
        // ins Leere kostet Geld und laesst den Vorschlag verschwinden.
        if (!$this->MailWriteAttr('MailSeenUIDs', $this->MailAttr('MailSeenUIDs', '{}'))) {
            return;
        }

        // Der Webhook zuerst: was schon im Haus ist, soll nicht hinter einem
        // Postfach-Durchlauf warten. Beides bleibt bei EINER Mail pro Lauf.
        // Jeder Weg laeuft nur mit SEINER Freigabe — wer nur den Webhook nutzt,
        // dessen Warteschlange darf nicht am Postfach-Schalter haengen (und
        // umgekehrt darf der Postfach-Weg nicht ueber den Webhook-Schalter laufen).
        if ($this->MailHookIsEnabled() && $this->MailHookSchritt()) {
            $this->WsPushDirty();
            $this->MailArm();
            return;
        }
        if (!$this->MailIsEnabled()) {
            return;
        }

        $offen = false;
        foreach ($this->MailBoxIDs() as $imapID) {
            $liste = @IMAP_GetCachedMails($imapID);
            if (!is_array($liste)) {
                $this->SendDebug('MailScan', sprintf('#%d liefert keine Mailliste', $imapID), 0);
                continue;
            }
            // Aelteste zuerst, damit die Reihenfolge der Vorschlaege dem Postfach folgt.
            usort($liste, static fn(array $a, array $b): int => (int)($a['Date'] ?? 0) <=> (int)($b['Date'] ?? 0));

            foreach ($liste as $mail) {
                if (!is_array($mail)) {
                    continue;
                }
                $uid = (string)($mail['UID'] ?? '');
                if ($uid === '' || $this->MailSeen((string)$imapID, $uid)) {
                    continue;
                }
                $urteil = $this->MailJudge($mail, $this->MailBoxSenderAllow($imapID), $this->MailBoxMember($imapID));
                if ($urteil['skip']) {
                    // Verworfene Mail trotzdem vermerken, sonst wird sie bei jedem
                    // Signal erneut geprueft.
                    $this->MailRemember((string)$imapID, $uid);
                    $this->SendDebug('MailScan', sprintf('UID %s uebersprungen: %s', $uid, $urteil['reason']), 0);
                    continue;
                }
                if ($this->MailDayLimitReached()) {
                    // NICHT vermerken: morgen soll sie analysiert werden.
                    $this->SendDebug('MailScan', 'Tageslimit erreicht, Analyse verschoben', 0);
                    return;
                }
                // Nur eine ABGESCHLOSSENE Analyse verbraucht die Mail. Ein
                // voruebergehender Anbieter-Fehler darf sie nicht verbrennen —
                // sonst ist die Aufgabe fuer immer verloren. Nach MAIL_FAIL_MAX
                // Fehlversuchen gilt sie trotzdem als erledigt: eine dauerhaft
                // scheiternde Mail (zu grosses PDF, kaputter Inhalt) darf nicht
                // alle neueren blockieren.
                if ($this->MailAnalyse($imapID, $mail, $urteil['userId'])) {
                    $this->MailRemember((string)$imapID, $uid);
                    $offen = true;
                } else {
                    $versuche = $this->MailCountFailure((string)$imapID, $uid);
                    if ($versuche >= self::MAIL_FAIL_MAX) {
                        $this->MailRemember((string)$imapID, $uid);
                        $betreff = trim((string)($mail['Subject'] ?? ''));
                        $this->LogMessage(sprintf(
                            'SymDo: E-Mail „%s" nach %d fehlgeschlagenen Analysen uebersprungen — sie bleibt im Postfach, wird aber nicht mehr versucht',
                            $betreff !== '' ? $betreff : 'UID ' . $uid,
                            $versuche
                        ), KL_ERROR);
                        $offen = true; // die naechste Mail ist jetzt frei
                    } else {
                        // Mit wachsendem Abstand erneut versuchen, statt auf das
                        // naechste Postfach-Ereignis zu warten.
                        $this->MailArm(self::MAIL_RETRY_MS * $versuche);
                    }
                }
                break 2; // eine Mail pro Lauf
            }
        }

        if ($offen) {
            $this->WsPushDirty();
            $this->MailArm();
        }
    }

    /**
     * Darf diese Mail analysiert werden? Liefert zugleich das zustaendige Mitglied.
     *
     * @param ?string $userId Bereits bekanntes Mitglied ('' = keines). Der IMAP-Weg
     *        gibt es mit, weil dort das POSTFACH zuordnet; nur der Webhook laesst es
     *        offen und sucht die Empfaengeradresse in der Zuordnungsliste. Diese
     *        Liste ist dort zugleich die Wache: der Endpunkt ist oeffentlich, also
     *        wird nur angenommen, was einer eingetragenen Adresse gilt. Beim
     *        Postfach braucht es diese Wache nicht — dort ist schon das Einrichten
     *        des Kontos die Entscheidung, und die Absenderliste filtert weiter.
     *
     * @return array{skip: bool, reason: string, userId: string}
     */
    private function MailJudge(array $mail, ?string $senderAllow = null, ?string $userId = null): array
    {
        $nein = static fn(string $grund): array => ['skip' => true, 'reason' => $grund, 'userId' => ''];

        if ($userId === null) {
            $empfaenger = strtolower(trim((string)($mail['Recipient'] ?? '')));
            $karte      = $this->MailAddressMap();
            if ($karte === []) {
                return $nein('keine Adress-Zuordnung konfiguriert');
            }
            $treffer = null;
            foreach ($this->MailRecipientCandidates($empfaenger) as $kandidat) {
                if (array_key_exists($kandidat, $karte)) {
                    $treffer = $karte[$kandidat];
                    break;
                }
            }
            if ($treffer === null) {
                return $nein('Empfaenger ' . $empfaenger . ' nicht zugeordnet');
            }
            $userId = $treffer['UserID'];
            // Erst die Adresse verraet, wessen Filter gilt — deshalb wird er hier
            // geholt und nicht vom Aufrufer mitgegeben.
            if ($senderAllow === null) {
                $senderAllow = $treffer['SenderAllow'];
            }
        }

        $absender = strtolower(trim((string)($mail['SenderAddress'] ?? '')));
        if (!$this->MailSenderAllowed($absender, $senderAllow)) {
            return $nein('Absender ' . $absender . ' nicht freigegeben');
        }
        if ($this->MailLooksLikeBulk($absender, (string)($mail['Subject'] ?? ''))) {
            return $nein('sieht nach Massenmail aus');
        }
        return ['skip' => false, 'reason' => '', 'userId' => $userId];
    }

    /**
     * Adressen, unter denen ein Empfaenger in der Zuordnung stehen kann. Das Feld
     * kann mehrere Adressen tragen („Multiple recipients" im Kernmodul), und eine
     * Plus-Adresse soll auch auf die Grundadresse fallen koennen.
     *
     * @return list<string>
     */
    private function MailRecipientCandidates(string $empfaenger): array
    {
        $raus = [];
        foreach (preg_split('/[,;]/', $empfaenger) ?: [] as $teil) {
            $teil = trim($teil);
            // „Name <adresse>" → adresse
            if (preg_match('/<([^>]+)>/', $teil, $m) === 1) {
                $teil = trim($m[1]);
            }
            if ($teil === '') {
                continue;
            }
            $raus[] = $teil;
            // Plus-Adresse zusaetzlich als Grundadresse anbieten
            if (preg_match('/^([^+@]+)\+[^@]*(@.+)$/', $teil, $m) === 1) {
                $raus[] = $m[1] . $m[2];
            }
        }
        return array_values(array_unique($raus));
    }

    /**
     * @param ?string $senderAllow Freigegebene Absender fuer DIESEN Weg. Der
     *        Postfach-Weg gibt sie mit (Postfach des Mitglieds oder Haushalt);
     *        null heisst „die der getroffenen Empfangsadresse" und gilt fuer den
     *        Webhook, wo erst die Adresse verraet, wer gemeint ist. Leer laesst
     *        alles durch.
     */
    private function MailSenderAllowed(string $absender, ?string $liste = null): bool
    {
        $roh = trim($liste ?? (string)$this->MailProp('MailSenderAllow', ''));
        if ($roh === '') {
            return true; // keine Liste = keine Einschraenkung
        }
        // \s deckt Zeilenumbrueche und Leerzeichen ab: keine Schreibweise soll falsch
        // sein. In einer Adresse kommt kein Leerzeichen vor, also trennt es gefahrlos.
        foreach (preg_split('/[\s,;]+/', strtolower($roh)) ?: [] as $eintrag) {
            $eintrag = trim($eintrag);
            if ($eintrag === '') {
                continue;
            }
            if ($eintrag[0] === '@') {
                if (str_ends_with($absender, $eintrag)) {
                    return true;
                }
                continue;
            }
            if ($absender === $eintrag) {
                return true;
            }
        }
        return false;
    }

    /** Grobe Massenmail-Erkennung. Rohe Kopfzeilen liefert das Kernmodul nicht. */
    private function MailLooksLikeBulk(string $absender, string $betreff): bool
    {
        $lokal = explode('@', $absender)[0] ?? '';
        foreach (['noreply', 'no-reply', 'donotreply', 'newsletter', 'bounce', 'mailer-daemon', 'postmaster'] as $muster) {
            if (str_contains($lokal, $muster)) {
                return true;
            }
        }
        return (bool)preg_match('/\b(newsletter|abmelden|unsubscribe|werbung)\b/i', $betreff);
    }

    /**
     * @return bool true, wenn die Mail fertig behandelt ist (auch bei 0 Aufgaben).
     *              false bei einem Fehler, der einen zweiten Versuch verdient.
     */
    private function MailAnalyse(int $imapID, array $kopf, string $userId): bool
    {
        $uid  = (string)($kopf['UID'] ?? '');
        $voll = @IMAP_GetMailEx($imapID, $uid);
        if (!is_array($voll)) {
            // Kann am Server liegen — noch einmal versuchen.
            $this->SendDebug('MailScan', sprintf('UID %s nicht ladbar', $uid), 0);
            return false;
        }
        $text = $this->MailPrepareText($voll);
        if ($text === '') {
            // Endgueltig: eine Mail ohne Text wird auch beim naechsten Mal keinen haben.
            $this->SendDebug('MailScan', sprintf('UID %s hat keinen brauchbaren Text', $uid), 0);
            return true;
        }

        // Anhang dazuholen. Das Kernmodul liefert ihn nicht — MailFetch schlaegt
        // lesend im selben Postfach nach. Gerade Elternbriefe tragen die Termine
        // ausschliesslich im PDF; ohne das bliebe nur „siehe Anhang" uebrig.
        // Symcons php.ini setzt memory_limit auf 32 MB. Ein 5-MB-PDF liegt auf dieser
        // Strecke mehrfach gleichzeitig im Speicher — ohne Luft bricht der Lauf ab.
        // Das `finally` ist wichtig: ohne es bliebe die Anhebung stehen, wenn
        // MailFetchAttachment wirft.
        $anhang = null;
        if ((bool)$this->MailProp('MailReadAttachments', true)) {
            $speicherVorher = (string)@ini_get('memory_limit');
            @ini_set('memory_limit', '192M');
            try {
                $anhang = $this->MailFetchAttachment($imapID, $uid);
            } finally {
                if ($speicherVorher !== '') {
                    @ini_set('memory_limit', $speicherVorher);
                }
            }
        }

        $fertig = $this->MailAnalyseRecord($imapID . ':' . $uid, $kopf, $text, $anhang, $userId);
        // Loeschen nur auf dem IMAP-Weg und nur nach einer abgeschlossenen Analyse:
        // eine Mail, die noch einen zweiten Versuch verdient, muss im Postfach bleiben.
        if ($fertig && (bool)$this->MailProp('MailDeleteAfter', false)) {
            @IMAP_DeleteMail($imapID, $uid);
        }
        return $fertig;
    }

    /**
     * Der quellenneutrale Teil der Analyse: KI befragen, Ergebnis vermerken, Vorschlag ablegen.
     *
     * Bewusst getrennt von der Beschaffung, damit derselbe Weg fuer beide Eingaenge
     * gilt — die IMAP-Abholung und den Webhook. Alles, was hier steht, kennt weder
     * Postfach noch UID; es bekommt fertigen Text und hoechstens EINEN Anhang.
     *
     * @param string     $vorschlagsId eindeutig je Quelle: „<imapID>:<uid>" bzw. „hook:<key>"
     * @param array      $kopf         Subject, SenderAddress, SenderName, Recipient, Date
     * @param ?array     $anhang       ['kind' => 'pdf'|'image', 'base64' => …, 'name' => …]
     * @param string     $quelle       nur fuers Statusprotokoll
     * @return bool true, wenn die Mail fertig behandelt ist (auch bei 0 Aufgaben).
     *              false bei einem Fehler, der einen zweiten Versuch verdient.
     */
    private function MailAnalyseRecord(
        string $vorschlagsId,
        array $kopf,
        string $text,
        ?array $anhang,
        string $userId,
        string $quelle = 'IMAP'
    ): bool {
        $betreff = trim((string)($kopf['Subject'] ?? ''));
        $eingabe = ($betreff !== '' ? 'Betreff: ' . $betreff . "\n\n" : '') . $text;
        $bild = ($anhang !== null && $anhang['kind'] === 'image') ? $anhang['base64'] : null;
        $pdf  = ($anhang !== null && $anhang['kind'] === 'pdf') ? $anhang['base64'] : null;
        if ($anhang !== null && ($anhang['name'] ?? '') !== '') {
            $eingabe .= "\n\n(Beigefuegte Datei: " . $anhang['name'] . ')';
        }
        // Auch hier Luft fuer den Anbieter-Aufruf: das base64 des Anhangs liegt im
        // JSON-Rumpf ein zweites Mal. Zurueckgesetzt wird in jedem Fall (finally).
        $speicherVorher = (string)@ini_get('memory_limit');
        if ($anhang !== null) {
            @ini_set('memory_limit', '192M');
        }
        try {

            $r = $this->AiRunCompletion(
                $this->AiMailSystemPrompt(date('Y-m-d'), $anhang !== null),
                $eingabe,
                $bild,
                $pdf
            );
            // Kann der eingestellte Anbieter kein PDF (lokaler Server), scheitert der
            // Aufruf dauerhaft. Dann lieber der Text allein als eine Mail, die bei jedem
            // Lauf erneut ins Leere greift.
            if (($r['ok'] ?? false) !== true && (string)($r['code'] ?? '') === 'ai_pdf_unsupported') {
                $this->SendDebug('MailScan', 'Anbieter kann kein PDF — zweiter Versuch ohne Anhang', 0);
                $anhang = null;
                $r = $this->AiRunCompletion($this->AiMailSystemPrompt(date('Y-m-d')), $eingabe, null);
            }
            $this->MailCountDay();
            if (($r['ok'] ?? false) !== true) {
                $meldung = (string)($r['message'] ?? $r['code'] ?? '?');
                $this->SendDebug('MailScan', 'KI-Fehler: ' . $meldung, 0);
                $this->LogMessage('SymDo: E-Mail-Analyse fehlgeschlagen — ' . $meldung, KL_ERROR);
                return false;
            }
            $aufgaben = $this->AiParseTodos((string)$r['text'], ['task', 'event', 'note']);
            // Bewusst ins Statusprotokoll und nicht nur ins Debug: die Analyse laeuft
            // unbeobachtet im Timer und kostet Geld beim Anbieter. Ohne diese Zeile
            // waere im Nachhinein nicht feststellbar, welche Mail wann verarbeitet wurde.
            // Mit Datumsangabe: daran erkennt man, ob der Anhang wirklich ausgewertet
            // wurde — Fristen und Termine stehen fast immer nur dort. Bewusst nur
            // Zahlen, keine Aufgabentitel: das Protokoll ist kein Ort fuer Inhalte.
            // Aufgaben und Termine getrennt zaehlen, dazu wie viele ein Datum tragen.
            // Daran erkennt man ohne Blick in die App, ob der Anhang ausgewertet wurde
            // und ob die Unterscheidung greift. Bewusst nur Zahlen, keine Titel.
            $mitDatum = 0;
            $termine = 0;
            $notizen = 0;
            foreach ($aufgaben as $a) {
                if (($a['due'] ?? null) !== null) {
                    $mitDatum++;
                }
                if (($a['kind'] ?? 'task') === 'event') {
                    $termine++;
                }
                if (($a['kind'] ?? 'task') === 'note') {
                    $notizen++;
                }
            }
            $this->LogMessage(sprintf(
                'SymDo: E-Mail „%s" von %s analysiert%s%s → %d Aufgabe(n), %d Termin(e), %d Notiz(en), davon %d mit Datum',
                $betreff !== '' ? $betreff : '(ohne Betreff)',
                (string)($kopf['SenderAddress'] ?? '?'),
                // Der IMAP-Weg bleibt wortgleich wie bisher; nur ein anderer Eingang
                // nennt sich, damit im Protokoll unterscheidbar ist, woher die Mail kam.
                $quelle === 'IMAP' ? '' : ' (' . $quelle . ')',
                $anhang !== null ? ' (mit Anhang ' . (($anhang['name'] ?? '') !== '' ? $anhang['name'] : $anhang['kind']) . ')' : '',
                count($aufgaben) - $termine - $notizen,
                $termine,
                $notizen,
                $mitDatum
            ), KL_NOTIFY);
            if ($aufgaben === []) {
                return true; // sauber analysiert, nur nichts zu tun gefunden
            }

            // Anhang dauerhaft ablegen, damit die Notiz ihn spaeter tragen kann. Muss
            // HIER stehen, innerhalb des try: das base64 liegt noch im Speicher und
            // IPS_SetMediaContent dekodiert intern, die Spitze liegt also bei etwa
            // 2,3x der Dateigroesse. Nach dem finally waere das erhoehte memory_limit
            // schon zurueckgesetzt und der Aufruf ein Abbruch.
            //
            // Nur die Medien-ID reist im Vorschlag mit, NIEMALS das base64 — der
            // Vorschlagsbestand ist ein Attribut mit bis zu 50 Datensaetzen.
            if ($notizen > 0 && $anhang !== null && (bool)$this->PushProp('MailNoteAttachments', false)) {
                $ablage = $this->NotesSaveAttachment((string)$anhang['base64'], (string)($anhang['name'] ?? ''));
                if (($ablage['ok'] ?? false) === true) {
                    foreach ($aufgaben as $k => $a) {
                        if (($a['kind'] ?? '') === 'note') {
                            $aufgaben[$k]['mediaId'] = (int)$ablage['id'];
                        }
                    }
                } else {
                    // Kein Abbruch: die Notiz ohne Anhang ist besser als keine.
                    $this->SendDebug('MailScan', 'Anhang nicht ablegbar: '
                        . (string)($ablage['error']['code'] ?? '?'), 0);
                }
            }

            $this->MailStoreProposal([
                'id'        => $vorschlagsId,
                'at'        => (int)($kopf['Date'] ?? time()),
                'from'      => (string)($kopf['SenderAddress'] ?? ''),
                'fromName'  => (string)($kopf['SenderName'] ?? ''),
                'subject'   => $betreff,
                'recipient' => (string)($kopf['Recipient'] ?? ''),
                'userId'    => $userId,
                // Wer die Mail urspruenglich geschrieben hat und wie sie damals hiess.
                // Der aeussere Kopf nennt immer nur das weiterleitende Familienmitglied und ein
                // „Fwd:" davor — beides sagt dem Nutzer nichts.
                'origin'    => $this->MailDetectOrigin($text),
                'items'     => array_map(static fn(array $a): array => $a + ['taken' => false], $aufgaben),
            ]);
            $this->MailNotifyProposal(count($aufgaben) - $termine - $notizen, $termine, $notizen, $userId);
            return true;

        } finally {
            if ($speicherVorher !== '' && $anhang !== null) {
                @ini_set('memory_limit', $speicherVorher);
            }
        }
    }

    /**
     * Absender und Betreff der WEITERGELEITETEN Mail aus dem zitierten Kopf lesen.
     *
     * Bei einer Weiterleitung steht im aeusseren Kopf nur, wer sie weitergeleitet hat
     * — in der Familie also immer dieselbe Person — und im Betreff ein „Fwd:" davor. Wer
     * die Mail geschrieben hat und wie sie hiess, steht im zitierten Kopf im Text:
     *
     *     Von: "Michailidou, Sofia" <Sofia.Michailidou@awo-duesseldorf.de>
     *     Betreff: Ferienabfrage Herbstferien
     *     Datum: 5. August 2026 um 14:09:25 MESZ
     *
     * Gelesen wird nur der Anfang: weiter unten im Verlauf koennen aeltere Kopfzeilen
     * derselben Bauart stehen, und die aelteste waere die falsche. Findet sich nichts,
     * bleiben die Felder leer und die Oberflaeche zeigt weiter den aeusseren Kopf —
     * lieber der weiterleitende Absender als ein geratener.
     *
     * @return array{name: string, address: string, subject: string}
     */
    private function MailDetectOrigin(string $text): array
    {
        $raus = ['name' => '', 'address' => '', 'subject' => ''];
        $zeilen = preg_split('/\r\n|\r|\n/', $text) ?: [];
        foreach (array_slice($zeilen, 0, self::MAIL_ORIGIN_LINES) as $zeile) {
            // Zitatzeichen entfernt MailPrepareText bereits; bei Text aus anderen
            // Quellen hier noch einmal, damit „> Von:" ebenfalls trifft.
            $zeile = trim((string)preg_replace('/^(\s*>)+\s?/', '', $zeile));
            if ($zeile === '') {
                continue;
            }
            if ($raus['address'] === '' && $raus['name'] === ''
                && preg_match('/^(?:Von|From)\s*:\s*(.+)$/iu', $zeile, $m) === 1) {
                $wert = trim($m[1]);
                if (preg_match('/^(.*?)\s*<([^>]+)>\s*$/u', $wert, $mm) === 1) {
                    $raus['name']    = trim($mm[1], " \t\"'");
                    $raus['address'] = trim($mm[2]);
                } elseif (filter_var($wert, FILTER_VALIDATE_EMAIL) !== false) {
                    $raus['address'] = $wert;
                } else {
                    $raus['name'] = trim($wert, " \t\"'");
                }
                continue;
            }
            if ($raus['subject'] === ''
                && preg_match('/^(?:Betreff|Subject)\s*:\s*(.+)$/iu', $zeile, $m) === 1) {
                // Auch der zitierte Betreff kann ein „Fwd:" tragen, wenn die Mail
                // mehrfach gereist ist — Praefixe deshalb abschneiden.
                $raus['subject'] = $this->MailStripReplyPrefix(trim($m[1]));
            }
        }
        return $raus;
    }

    /** „Fwd: WG: Titel“ → „Titel“. Auch mehrfach und in beiden Sprachen. */
    private function MailStripReplyPrefix(string $betreff): string
    {
        $vorher = '';
        while ($vorher !== $betreff) {
            $vorher = $betreff;
            $betreff = trim((string)preg_replace('/^\s*(?:fwd?|wg|aw|re|antw)\s*:\s*/iu', '', $betreff));
        }
        return $betreff;
    }

    /**
     * Mailtext fuer das Modell aufbereiten. Bei HTML greift derselbe Wandler wie
     * bei Rezeptseiten; Zitatzeichen einer Weiterleitung fliegen raus, weil sie
     * jede Zeile verunreinigen und Modellqualitaet kosten.
     */
    private function MailPrepareText(array $voll): string
    {
        $text = (string)($voll['Text'] ?? '');
        if ($text === '') {
            return '';
        }
        // Der Text muss gueltiges UTF-8 sein, bevor er zum Anbieter geht — sonst
        // liefert json_encode dort `false` und der Aufruf bricht mit TypeError ab.
        // Das Kernmodul dekodiert meist selbst nach UTF-8, traegt aber den
        // urspruenglichen Zeichensatz in `CharSet`; darauf verlassen wir uns nicht.
        if (!mb_check_encoding($text, 'UTF-8')) {
            $charset = strtoupper(trim((string)($voll['CharSet'] ?? '')));
            try {
                $text = mb_convert_encoding($text, 'UTF-8', ($charset !== '' && $charset !== 'UTF-8') ? $charset : 'ISO-8859-1');
            } catch (Throwable $e) {
                // Unbekannter Zeichensatz: ISO-8859-1 bildet jedes Byte ab.
                $text = mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
            }
        }
        $typ = strtolower((string)($voll['ContentType'] ?? ''));
        if (str_contains($typ, 'html') || preg_match('/<(html|body|div|table)\b/i', $text) === 1) {
            $text = $this->AiHtmlToText($text);
        }
        $zeilen = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $sauber = [];
        foreach ($zeilen as $zeile) {
            // „> “, „>> “ … am Zeilenanfang entfernen
            $sauber[] = rtrim((string)preg_replace('/^(\s*>)+\s?/', '', $zeile));
        }
        $text = preg_replace("/\n{3,}/", "\n\n", implode("\n", $sauber)) ?? '';
        $text = trim($text);
        if (strlen($text) > self::MAIL_TEXT_MAX) {
            // mb_strcut statt substr: ein byteweiser Schnitt mitten durch eine
            // UTF-8-Sequenz macht den Text fuer json_encode unbrauchbar.
            $text = mb_strcut($text, 0, self::MAIL_TEXT_MAX, 'UTF-8');
        }
        return $text;
    }

    // ──────────────────── Zweiter Eingang: Webhook (Mailgun) ────────────────────
    //
    // Mailgun nimmt die Mail an und meldet sie uns per HTTPS. Der Weg ist bewusst
    // zweigeteilt: Der Webhook nimmt nur an und legt ab, die Analyse macht wie
    // gehabt der Timer. Ein Anbieter-Aufruf dauert am eigenen Rechner 20 bis 60
    // Sekunden — so lange darf keine Webanfrage offen stehen.
    //
    // Mailgun wiederholt selbst: bei allem ausser 200 und 406 nach 10 min, 15 min,
    // 30 min, 1 h, 2 h, 4 h. Deshalb bedeutet hier 200 „erledigt", 406 „endgueltig
    // verworfen" und JEDER interne Fehler 5xx — dann kommt die Mail spaeter wieder
    // und geht nicht verloren.

    /**
     * Einstieg aus dem Hook. Antwortet selbst und gibt nichts zurueck.
     *
     * @param string $pfadGeheimnis letzter Pfadteil der Ziel-Adresse
     */
    private function MailHookHandle(string $pfadGeheimnis): void
    {
        $antwort = function (int $code, string $text): void {
            http_response_code($code);
            header('Content-Type: text/plain; charset=utf-8');
            echo $text;
            // Abgelehnte Zustellungen gehoeren ins Statusprotokoll, nicht nur ins
            // Debug: Wer eine Testmail schickt und nichts passiert, sucht sonst an
            // der falschen Stelle. Angenommene sind ohnehin an der Analysezeile
            // erkennbar, die bleibt hier still.
            if ($code !== 200) {
                $this->LogMessage(sprintf('SymDo: Mail-Webhook hat eine Zustellung abgewiesen (%d): %s', $code, $text), KL_WARNING);
            }
        };

        if (!$this->MailHookIsEnabled()) {
            // Auch bei abgeschalteter KI oder widerrufener Einwilligung: 403 ist
            // endgueltig, der Absender bekommt einen Bounce statt tagelanger
            // Zustellversuche an ein System, das nicht verarbeiten darf.
            $antwort(403, 'mail hook disabled');
            return;
        }
        $geheim = trim((string)$this->MailProp('MailHookSecret', ''));
        // Zu kurz gilt wie „nicht eingerichtet": ein geratenes Geheimnis waere sonst
        // die einzige Huerde vor der Signaturpruefung.
        if (strlen($geheim) < 24 || !hash_equals($geheim, $pfadGeheimnis)) {
            $this->SendDebug('MailHook', 'Pfad-Geheimnis passt nicht', 0);
            $antwort(403, 'forbidden');
            return;
        }

        // Groesse pruefen, so gut es geht: Symcons Webserver reicht CONTENT_LENGTH
        // NICHT durch (gemessen — der Wert fehlt im $_SERVER-Feld), deshalb zaehlt
        // hier die Summe der hochgeladenen Dateien und weiter unten die Laenge des
        // gelesenen Rumpfs.
        $maxBytes = max(64, (int)$this->MailProp('MailHookMaxKB', 8192)) * 1024;
        $hochgeladen = 0;
        foreach ($_FILES as $f) {
            $hochgeladen += (int)($f['size'] ?? 0);
        }
        $laenge = max((int)($_SERVER['CONTENT_LENGTH'] ?? 0), $hochgeladen);
        if ($laenge > $maxBytes) {
            $this->SendDebug('MailHook', sprintf('Zustellung zu gross: %d kB', (int)round($laenge / 1024)), 0);
            $antwort(406, 'payload too large');
            return;
        }

        $roh = (string)@file_get_contents('php://input');
        if (strlen($roh) > $maxBytes) {
            $antwort(406, 'payload too large');
            return;
        }
        // Mailgun schickt bei „store and notify" FORMULARDATEN, kein JSON — die
        // JSON-Form kommt nur von anderen Diensten. Beides annehmen: erst als JSON
        // versuchen, sonst als Formular lesen. (Am 19.08.2026 gemessen: Die
        // Nachricht war bei Mailgun „stored", unser Hook antwortete 406 „expected
        // json", und weil 406 endgueltig ist, gab es keinen zweiten Versuch.)
        $daten = json_decode($roh, true);
        if (!is_array($daten)) {
            $daten = [];
            parse_str($roh, $daten);
            if ($daten === [] && $_POST !== []) {
                $daten = $_POST;   // falls der Webserver den Rumpf schon zerlegt hat
            }
        }
        unset($roh);
        if (!is_array($daten) || $daten === []) {
            $this->SendDebug('MailHook', 'Rumpf weder JSON noch Formulardaten', 0);
            $antwort(406, 'unusable payload');
            return;
        }
        if (!$this->MailHookVerify($daten)) {
            $antwort(403, 'bad signature');
            return;
        }

        $satz = $this->MailHookParse($daten);
        unset($daten);
        if ($satz === null) {
            $antwort(406, 'unusable message');
            return;
        }

        // Dieselbe Wache wie beim IMAP-Weg: Empfaenger einem Mitglied zuordnen,
        // Absender pruefen, Massenmail erkennen. Die Freigabeliste steht an der
        // getroffenen Empfangsadresse — deshalb ohne zweites Argument.
        $urteil = $this->MailJudge($satz['kopf']);
        if ($urteil['skip']) {
            $this->SendDebug('MailHook', 'verworfen: ' . $urteil['reason'], 0);
            $antwort(406, 'rejected: ' . $urteil['reason']);
            return;
        }
        if ($this->MailSeen(self::MAIL_HOOK_KEY, $satz['key']) || $this->MailHookSpooled($satz['key'])) {
            $this->SendDebug('MailHook', 'schon bekannt: ' . $satz['key'], 0);
            $antwort(200, 'duplicate');
            return;
        }

        $satz['userId'] = $urteil['userId'];
        if (!$this->MailHookSpool($satz)) {
            // 5xx, damit Mailgun es spaeter erneut versucht — die Mail ist sonst weg.
            $antwort(503, 'queue unavailable');
            return;
        }
        $this->MailArm();
        $this->SendDebug('MailHook', sprintf(
            'angenommen: %s von %s (%d Zeichen Text, %s)',
            $satz['key'],
            (string)($satz['kopf']['SenderAddress'] ?? '?'),
            strlen($satz['text']),
            $satz['anhang'] === null ? 'ohne Anhang' : 'Anhang ' . $satz['anhang']['name']
        ), 0);
        $antwort(200, 'accepted');
    }

    /**
     * Mailguns Signatur pruefen: timestamp und token verkettet, HMAC-SHA256 mit
     * dem Signaturschluessel des Kontos.
     *
     * Ohne hinterlegten Schluessel wird abgelehnt statt durchgewunken — sonst
     * bliebe nur das Pfad-Geheimnis, und das steht in jedem Zugriffsprotokoll.
     *
     * @param array<string, mixed> $daten
     */
    private function MailHookVerify(array $daten): bool
    {
        $key = trim((string)$this->MailProp('MailHookSigningKey', ''));
        if ($key === '') {
            $this->SendDebug('MailHook', 'kein Signaturschluessel hinterlegt', 0);
            return false;
        }
        // Mailgun schickt die drei Felder je nach Route-Aktion flach oder unter
        // „signature" gebuendelt — beide Formen bedienen.
        $sig = is_array($daten['signature'] ?? null) ? $daten['signature'] : $daten;
        $zeit  = (string)($sig['timestamp'] ?? '');
        $token = (string)($sig['token'] ?? '');
        $unterschrift = (string)($sig['signature'] ?? '');
        if ($zeit === '' || $token === '' || $unterschrift === '') {
            $this->SendDebug('MailHook', 'Signaturfelder fehlen', 0);
            return false;
        }
        if (abs(time() - (int)$zeit) > self::MAIL_HOOK_SIG_AGE) {
            $this->SendDebug('MailHook', 'Signatur ist zu alt', 0);
            return false;
        }
        return hash_equals(hash_hmac('sha256', $zeit . $token, $key), $unterschrift);
    }

    /**
     * Die Meldung auf die Formen bringen, die der Bestand kennt.
     *
     * Der Kopf entsteht genau so, wie ihn IMAP_GetCachedMails liefert, der Text
     * genau so, wie ihn IMAP_GetMailEx liefert. Dadurch laufen MailJudge und
     * MailPrepareText unveraendert — auch die Plus-Adressen kennen sie schon.
     *
     * @param array<string, mixed> $daten
     * @return array{key: string, kopf: array, text: string, anhang: ?array, userId: string}|null
     */
    private function MailHookParse(array $daten): ?array
    {
        $feld = static function (array $q, string ...$namen): string {
            foreach ($namen as $n) {
                if (isset($q[$n]) && is_scalar($q[$n]) && (string)$q[$n] !== '') {
                    return trim((string)$q[$n]);
                }
            }
            return '';
        };

        // „Name <adresse>" trennen — dasselbe Muster wie in MailDetectOrigin.
        $vonRoh = $feld($daten, 'from', 'From', 'sender');
        $name = '';
        $adresse = $vonRoh;
        if (preg_match('/^(.*?)\s*<([^>]+)>\s*$/u', $vonRoh, $m) === 1) {
            $name    = trim($m[1], " \t\"'");
            $adresse = trim($m[2]);
        }
        $empfaenger = $feld($daten, 'recipient', 'To', 'to');
        if ($empfaenger === '') {
            $this->SendDebug('MailHook', 'kein Empfaenger in der Meldung', 0);
            return null;
        }

        // Bei Formulardaten stecken die Kopfzeilen in „message-headers" als
        // JSON-Liste von [Name, Wert]-Paaren — Message-Id und Datum kommen von dort.
        $kopfzeilen = [];
        $roheKopf = $daten['message-headers'] ?? null;
        if (is_string($roheKopf)) {
            $roheKopf = json_decode($roheKopf, true);
        }
        foreach (is_array($roheKopf) ? $roheKopf : [] as $paar) {
            if (is_array($paar) && isset($paar[0], $paar[1]) && is_scalar($paar[1])) {
                $kopfzeilen[strtolower((string)$paar[0])] = (string)$paar[1];
            }
        }
        $datum = $feld($daten, 'Date', 'date');
        if ($datum === '') {
            $datum = $kopfzeilen['date'] ?? '';
        }
        $kopf = [
            'Recipient'     => $empfaenger,
            'SenderAddress' => $adresse !== '' ? $adresse : $feld($daten, 'sender'),
            'SenderName'    => $name,
            'Subject'       => $feld($daten, 'subject', 'Subject'),
            'Date'          => $datum !== '' ? (int)(strtotime($datum) ?: time()) : time(),
        ];

        // Text: bevorzugt die reine Fassung. Manche Absender legen dort nur einen
        // Stummel ab („Diese Nachricht ist in HTML"), deshalb die Mindestlaenge.
        $plain = (string)($daten['body-plain'] ?? $daten['stripped-text'] ?? '');
        $html  = (string)($daten['body-html'] ?? '');
        $voll  = (strlen(trim($plain)) >= 40 || $html === '')
            ? ['Text' => $plain, 'ContentType' => 'text/plain', 'CharSet' => 'UTF-8']
            : ['Text' => $html,  'ContentType' => 'text/html',  'CharSet' => 'UTF-8'];
        $text = $this->MailPrepareText($voll);
        if ($text === '') {
            $this->SendDebug('MailHook', 'kein brauchbarer Text', 0);
            return null;
        }

        $messageId = $feld($daten, 'Message-Id', 'message-id', 'Message-ID');
        if ($messageId === '') {
            $messageId = $kopfzeilen['message-id'] ?? '';
        }
        $key = $messageId !== ''
            ? substr(sha1(strtolower($messageId)), 0, 16)
            : substr(sha1($empfaenger . '|' . $adresse . '|' . $kopf['Subject'] . '|' . $kopf['Date'] . '|' . strlen($text)), 0, 16);

        return [
            'key'    => $key,
            'kopf'   => $kopf,
            'text'   => $text,
            'anhang' => $this->MailHookPickAttachment($daten),
            'userId' => '',
        ];
    }

    /**
     * Anhang aus der Meldung waehlen — mit genau denselben Regeln wie beim IMAP-Weg.
     *
     * Mailgun nennt die Anhaenge nur mit Kopfdaten und einer Abrufadresse. Genau
     * das passt: Aus den Kopfdaten wird eine Teileliste in der Form gebaut, die
     * MailPickAttachment kennt, und geladen wird erst der EINE Gewinner. Ein
     * Signaturlogo kostet damit nicht ein Byte Uebertragung.
     *
     * @param array<string, mixed> $daten
     * @return array{kind: string, name: string, url: string}|null
     */
    private function MailHookPickAttachment(array $daten): ?array
    {
        if ((bool)$this->MailProp('MailReadAttachments', true) !== true) {
            return null;
        }
        // Zwei Wege, je nach Mailgun-Route:
        //  - „forward" liefert die Datei mit; sie liegt dann in $_FILES und muss
        //    SOFORT gelesen werden, denn nach dem Request ist sie weg.
        //  - „store and notify" nennt nur Kopfdaten und eine Abrufadresse. Bei
        //    Sandbox-Domains laeuft der Abruf allerdings ins Leere („Message
        //    retrieval disabled for domain", am 19.08.2026 gemessen) — deshalb ist
        //    „forward" der empfohlene Weg.
        if ($_FILES !== []) {
            return $this->MailHookPickUploaded();
        }
        $liste = $daten['attachments'] ?? null;
        if (is_string($liste)) {
            $liste = json_decode($liste, true);   // Mailgun schickt das Feld als JSON-Text
        }
        if (!is_array($liste) || $liste === []) {
            return null;
        }
        $teile = [];
        $urls  = [];
        foreach (array_values($liste) as $i => $a) {
            if (!is_array($a)) {
                continue;
            }
            $typRoh = strtolower(trim((string)($a['content-type'] ?? $a['content_type'] ?? '')));
            $stueck = explode('/', $typRoh);
            $teile[] = [
                'part'       => (string)($i + 1),
                'type'       => trim($stueck[0] ?? ''),
                'subtype'    => trim($stueck[1] ?? ''),
                'encoding'   => 'base64',
                // Achtung: Mailgun nennt die ECHTE Groesse, BODYSTRUCTURE die kodierte.
                // Der 8-MB-Deckel wirkt hier also etwas strenger, die 40-kB-Untergrenze
                // fuer Bilder etwas grosszuegiger. Bewusst nicht umgerechnet — sonst
                // stuende in der Debug-Zeile eine Zahl, die nirgends herkommt.
                'size'       => (int)($a['size'] ?? 0),
                'name'       => trim((string)($a['name'] ?? $a['file-name'] ?? $a['filename'] ?? '')),
                'attachment' => strtolower((string)($a['disposition'] ?? 'attachment')) !== 'inline',
                'inline'     => strtolower((string)($a['disposition'] ?? '')) === 'inline',
                'cid'        => trim((string)($a['content-id'] ?? $a['content_id'] ?? ''), '<>'),
            ];
            $urls[(string)($i + 1)] = trim((string)($a['url'] ?? ''));
        }
        $wahl = $this->MailPickAttachment($teile);
        if ($wahl === null) {
            return null;
        }
        $url = $urls[$wahl['part']] ?? '';
        if ($url === '') {
            $this->SendDebug('MailHook', 'Anhang ohne Abrufadresse — wird uebergangen', 0);
            return null;
        }
        return ['kind' => $wahl['kind'], 'name' => $wahl['name'], 'url' => $url];
    }

    /**
     * Den brauchbarsten der mitgelieferten Anhaenge waehlen und einlesen.
     *
     * Die Auswahl trifft dasselbe MailPickAttachment wie beim Postfach-Weg, damit
     * Signaturlogos und zu kleine Bilder auch hier fallen. Gelesen wird nur der
     * Gewinner — und zwar gleich hier, weil Symcons Ablage der hochgeladenen Datei
     * nach dieser Anfrage verschwindet.
     *
     * @return array{kind: string, name: string, base64: string}|null
     */
    private function MailHookPickUploaded(): ?array
    {
        $teile = [];
        $pfade = [];
        $nr = 0;
        foreach ($_FILES as $f) {
            if (!is_array($f) || (int)($f['error'] ?? 1) !== 0) {
                continue;
            }
            $nr++;
            $name = trim((string)($f['name'] ?? ''));
            $typRoh = strtolower(trim((string)($f['type'] ?? '')));
            $stueck = explode('/', $typRoh);
            $teile[] = [
                'part'       => (string)$nr,
                'type'       => trim($stueck[0] ?? ''),
                'subtype'    => trim($stueck[1] ?? ''),
                'encoding'   => 'base64',
                'size'       => (int)($f['size'] ?? 0),
                'name'       => $name,
                // Mailgun liefert Inline-Bilder ueber content-id-map; ohne die
                // Zuordnung gilt eine mitgelieferte Datei als echter Anhang. Die
                // uebrigen Wachen (Groesse, Name, Pixel) tragen den Rest.
                'attachment' => true,
                'inline'     => false,
                'cid'        => '',
            ];
            $pfade[(string)$nr] = (string)($f['tmp_name'] ?? '');
        }
        if ($teile === []) {
            return null;
        }
        $wahl = $this->MailPickAttachment($teile);
        if ($wahl === null) {
            return null;
        }
        $pfad = $pfade[$wahl['part']] ?? '';
        if ($pfad === '' || !is_file($pfad)) {
            $this->SendDebug('MailHook', 'Anhang liegt nicht mehr vor', 0);
            return null;
        }
        $roh = (string)@file_get_contents($pfad);
        if ($roh === '') {
            return null;
        }
        // Der Absender bestimmt den gemeldeten Typ — die Bytes entscheiden.
        $istPdf  = str_starts_with($roh, '%PDF-');
        $istBild = str_starts_with($roh, "\xFF\xD8\xFF") || str_starts_with($roh, "\x89PNG\r\n\x1a\n");
        if (($wahl['kind'] === 'pdf' && !$istPdf) || ($wahl['kind'] === 'image' && !$istBild)) {
            $this->SendDebug('MailHook', 'Anhang ' . $wahl['name'] . ' passt nicht zum gemeldeten Typ', 0);
            return null;
        }
        $base64 = base64_encode($roh);
        unset($roh);
        if ($wahl['kind'] === 'image' && !$this->MailImageUsable($base64, $wahl['name'])) {
            return null;
        }
        $this->SendDebug('MailHook', sprintf('Anhang %s uebernommen (%d kB base64)', $wahl['name'], (int)round(strlen($base64) / 1024)), 0);
        return ['kind' => $wahl['kind'], 'name' => $wahl['name'], 'base64' => $base64];
    }

    // ─────────────────────────── Warteschlange ───────────────────────────
    //
    // Bewusst Dateien und kein Attribut: Ein Attribut existiert nach einem
    // Modul-Reload ohne Kernel-Neustart nicht (siehe MailWriteAttr), und ein
    // stiller Schreibfehler haette die Mail verschluckt. Dateien ueberleben beides
    // und der Bestand ist mit glob() ohne weiteren Zustand ablesbar.

    /**
     * @param bool $anlegen Nur der schreibende Weg legt das Verzeichnis an — das
     *        blosse Anzeigen des Formulars soll nichts auf der Platte hinterlassen.
     * @return string Verzeichnis mit Schrägstrich am Ende; '' wenn nicht nutzbar.
     */
    private function MailHookDir(bool $anlegen = false): string
    {
        $pfad = IPS_GetKernelDir() . self::MAIL_HOOK_DIR . DIRECTORY_SEPARATOR;
        if (!is_dir($pfad)) {
            if (!$anlegen) {
                return '';
            }
            if (!@mkdir($pfad, 0700, true) && !is_dir($pfad)) {
                $this->LogMessage('SymDo: Warteschlange fuer den Mail-Webhook nicht anlegbar: ' . $pfad, KL_ERROR);
                return '';
            }
        }
        return is_writable($pfad) ? $pfad : '';
    }

    private function MailHookSpooled(string $key): bool
    {
        $dir = $this->MailHookDir();
        return $dir !== '' && glob($dir . '*_' . $key . '.json') !== [];
    }

    /**
     * Einen angenommenen Satz ablegen. Der Dateiname traegt die Zeit (Reihenfolge)
     * und den Schluessel (Doppelerkennung).
     *
     * @param array<string, mixed> $satz
     */
    private function MailHookSpool(array $satz): bool
    {
        $dir = $this->MailHookDir(true);
        if ($dir === '') {
            return false;
        }
        if (count($this->MailHookQueueFiles()) >= self::MAIL_HOOK_QUEUE_MAX) {
            $this->LogMessage('SymDo: Warteschlange des Mail-Webhooks ist voll — Zustellung wird verzoegert', KL_WARNING);
            return false;
        }
        $datei = $dir . sprintf('%d_%s.json', time(), $satz['key']);
        // Liegt der Anhang schon vor (Weg „forward"), wandert er in eine eigene
        // Datei: im JSON zusammen mit dem Text laege er beim Kodieren doppelt im
        // Speicher, und der Timer soll ihn getrennt lesen koennen.
        if (isset($satz['anhang']['base64'])) {
            $attDatei = substr($datei, 0, -5) . '.att';
            if (@file_put_contents($attDatei, $satz['anhang']['base64'], LOCK_EX) === false) {
                $this->LogMessage('SymDo: Anhang konnte nicht in die Warteschlange geschrieben werden', KL_ERROR);
                return false;
            }
            @chmod($attDatei, 0600);
            $satz['anhang']['datei'] = basename($attDatei);
            unset($satz['anhang']['base64']);
        }
        $json = json_encode($satz, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || @file_put_contents($datei, $json, LOCK_EX) === false) {
            $this->LogMessage('SymDo: Mail konnte nicht in die Warteschlange geschrieben werden', KL_ERROR);
            return false;
        }
        @chmod($datei, 0600);
        return true;
    }

    /**
     * Warteschlange vollstaendig leeren — bei Einwilligungs-Widerruf und Abschalten.
     *
     * Die Dateien sind rohe Mailinhalte, deren einziger Zweck die Analyse war;
     * faellt die Einwilligung weg, gibt es keinen Grund, sie noch sieben Tage
     * liegen zu lassen.
     *
     * @return int Anzahl entfernter Nachrichten
     */
    private function MailHookClearQueue(): int
    {
        $dir = $this->MailHookDir();
        if ($dir === '') {
            return 0;
        }
        $n = 0;
        foreach (glob($dir . '*.json') ?: [] as $datei) {
            @unlink($datei);
            @unlink(substr($datei, 0, -5) . '.att');
            $n++;
        }
        return $n;
    }

    /** @return list<string> aelteste zuerst; raeumt dabei Liegengebliebenes weg. */
    private function MailHookQueueFiles(): array
    {
        $dir = $this->MailHookDir();
        if ($dir === '') {
            return [];
        }
        $treffer = glob($dir . '*.json') ?: [];
        $raus = [];
        $grenze = time() - self::MAIL_HOOK_TTL;
        foreach ($treffer as $datei) {
            if ((int)@filemtime($datei) < $grenze) {
                @unlink($datei);
                @unlink(substr($datei, 0, -5) . '.att');
                continue;
            }
            $raus[] = $datei;
        }
        sort($raus, SORT_STRING);
        return $raus;
    }

    /**
     * EINE wartende Mail verarbeiten. Gleiches Muster wie der IMAP-Schritt: kurz
     * bleiben, der Timer holt sich den Rest.
     *
     * @return bool true, wenn etwas getan wurde
     */
    private function MailHookSchritt(): bool
    {
        $dateien = $this->MailHookQueueFiles();
        if ($dateien === []) {
            return false;
        }
        $datei = $dateien[0];
        $satz = json_decode((string)@file_get_contents($datei), true);
        if (!is_array($satz) || ($satz['key'] ?? '') === '') {
            @unlink($datei);
            return true;
        }
        $key = (string)$satz['key'];

        if ($this->MailDayLimitReached()) {
            // Nicht loeschen: morgen ist sie wieder dran. Der Timer wird durch das
            // Postfach oder den naechsten Webhook ohnehin erneut geweckt.
            $this->SendDebug('MailHook', 'Tageslimit erreicht, Analyse verschoben', 0);
            return false;
        }

        // Anhang jetzt erst holen — im Webhook waere die Zeit dafuer nicht gewesen.
        $anhang = null;
        $besch = is_array($satz['anhang'] ?? null) ? $satz['anhang'] : null;
        if ($besch !== null) {
            $base64 = null;
            if (($besch['datei'] ?? '') !== '') {
                // Weg „forward": liegt schon neben der Warteschlange.
                $base64 = (string)@file_get_contents($this->MailHookDir() . basename((string)$besch['datei']));
                if ($base64 === '') {
                    $base64 = null;
                }
            } elseif (($besch['url'] ?? '') !== '') {
                $base64 = $this->MailHookFetchAttachment((string)$besch['url'], (string)$besch['kind'], (string)$besch['name']);
            }
            if ($base64 !== null) {
                $anhang = ['kind' => (string)$besch['kind'], 'base64' => $base64, 'name' => (string)$besch['name']];
            }
        }

        $fertig = $this->MailAnalyseRecord(
            'hook:' . $key,
            (array)$satz['kopf'],
            (string)$satz['text'],
            $anhang,
            (string)($satz['userId'] ?? ''),
            'Webhook'
        );
        if ($fertig) {
            @unlink($datei);
            @unlink(substr($datei, 0, -5) . '.att');
            $this->MailRemember(self::MAIL_HOOK_KEY, $key);
            return true;
        }

        $versuche = $this->MailCountFailure(self::MAIL_HOOK_KEY, $key);
        if ($versuche >= self::MAIL_FAIL_MAX) {
            @unlink($datei);
            @unlink(substr($datei, 0, -5) . '.att');
            $this->MailRemember(self::MAIL_HOOK_KEY, $key);
            $this->LogMessage(sprintf(
                'SymDo: E-Mail „%s" aus dem Webhook nach %d fehlgeschlagenen Analysen uebersprungen',
                (string)($satz['kopf']['Subject'] ?? $key),
                $versuche
            ), KL_ERROR);
        } else {
            $this->MailArm(self::MAIL_RETRY_MS * $versuche);
        }
        return true;
    }

    /**
     * Den gewaehlten Anhang bei Mailgun abholen.
     *
     * Basic-Auth mit dem API-Schluessel, Host-Wache gegen fremde Ziele, harte
     * Zeit- und Groessengrenze. Scheitert es, wird ohne Anhang analysiert statt es
     * ewig zu wiederholen: die Adressen laufen ab, und der Systemtext des Modells
     * kommt mit „Anhang liegt nicht vor" ohnehin zurecht.
     */
    private function MailHookFetchAttachment(string $url, string $kind, string $name): ?string
    {
        $schluessel = trim((string)$this->MailProp('MailHookApiKey', ''));
        if ($url === '' || $schluessel === '') {
            return null;
        }
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        if (parse_url($url, PHP_URL_SCHEME) !== 'https'
            || !(str_ends_with($host, '.mailgun.net') || str_ends_with($host, '.mailgun.org'))) {
            $this->SendDebug('MailHook', 'Anhang-Adresse abgelehnt: ' . $host, 0);
            return null;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => 'api:' . $schluessel,
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXFILESIZE    => (int)(self::MAIL_ATTACH_MAX_B64 * 3 / 4),
        ]);
        $roh = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $fehler = curl_error($ch);
        curl_close($ch);
        if (!is_string($roh) || $roh === '' || $status !== 200) {
            $this->SendDebug('MailHook', sprintf('Anhang %s nicht ladbar (HTTP %d) %s', $name, $status, $fehler), 0);
            return null;
        }
        // Der Absender bestimmt den gemeldeten Typ — die Bytes entscheiden.
        $istPdf   = str_starts_with($roh, '%PDF-');
        $istBild  = str_starts_with($roh, "\xFF\xD8\xFF") || str_starts_with($roh, "\x89PNG\r\n\x1a\n");
        if (($kind === 'pdf' && !$istPdf) || ($kind === 'image' && !$istBild)) {
            $this->SendDebug('MailHook', 'Anhang ' . $name . ' passt nicht zum gemeldeten Typ', 0);
            return null;
        }
        $base64 = base64_encode($roh);
        unset($roh);
        if ($kind === 'image' && !$this->MailImageUsable($base64, $name !== '' ? $name : 'Anhang')) {
            return null;
        }
        $this->SendDebug('MailHook', sprintf('Anhang %s geladen (%d kB base64)', $name, (int)round(strlen($base64) / 1024)), 0);
        return $base64;
    }

    /**
     * Kurze Nachricht ueber neue Vorschlaege aus einer analysierten Mail.
     *
     * Bewusst NUR Zahlen: Betreff und Absender bleiben draussen. Der Webhook ist
     * nicht authentifiziert — wer die Adresse kennt, koennte sonst fremden Text auf
     * die Sperrbildschirme der Familie schreiben. Die Ratenbegrenzung je Geraet in
     * PushBroadcast deckelt zusaetzlich, wie oft das ueberhaupt gehen kann.
     */
    private function MailNotifyProposal(int $aufgaben, int $termine, int $notizen, string $userId): void
    {
        if (!(bool)$this->PushProp('PushOnMailProposal', false)) {
            return;
        }
        $teile = [];
        if ($notizen > 0) {
            $teile[] = $notizen === 1
                ? $this->Translate('1 note')
                : sprintf($this->Translate('%d notes'), $notizen);
        }
        if ($termine > 0) {
            $teile[] = $termine === 1
                ? $this->Translate('1 appointment')
                : sprintf($this->Translate('%d appointments'), $termine);
        }
        if ($aufgaben > 0) {
            $teile[] = $aufgaben === 1
                ? $this->Translate('1 task')
                : sprintf($this->Translate('%d tasks'), $aufgaben);
        }
        if ($teile === []) {
            return;
        }
        $this->PushBroadcast(
            $this->Translate('New from your mail'),
            sprintf($this->Translate('%s waiting to be accepted'), implode(', ', $teile)),
            // Gehoert die Mail einem Mitglied, geht die Nachricht auch nur an dessen
            // Geraete — die Haushaltsadresse dagegen betrifft alle.
            $userId,
            'todos'
        );
    }

    // ────────────────────────────── Ablage ──────────────────────────────

    private function MailStoreProposal(array $satz): void
    {
        $alle = $this->MailProposals();
        // Gleiche Mail nie zweimal: der Datensatz gewinnt, der neu kommt.
        $alle = array_values(array_filter($alle, static fn(array $p): bool => ($p['id'] ?? '') !== $satz['id']));
        $alle[] = $satz;
        $this->MailWriteProposals($alle);
    }

    /** @return list<array> */
    private function MailProposals(): array
    {
        $alle = json_decode($this->MailAttr('MailProposals', '[]'), true);
        if (!is_array($alle)) {
            return [];
        }
        $grenze = time() - self::MAIL_RETENTION_DAYS * 86400;
        $raus = [];
        foreach ($alle as $p) {
            if (is_array($p) && (int)($p['at'] ?? 0) >= $grenze) {
                $raus[] = $p;
            }
        }
        return $raus;
    }

    private function MailWriteProposals(array $alle): void
    {
        usort($alle, static fn(array $a, array $b): int => (int)($a['at'] ?? 0) <=> (int)($b['at'] ?? 0));
        if (count($alle) > self::MAIL_PROPOSALS_MAX) {
            $alle = array_slice($alle, -self::MAIL_PROPOSALS_MAX);
        }
        $this->MailWriteAttr('MailProposals', json_encode(array_values($alle), JSON_UNESCAPED_UNICODE));
    }

    /** Vorschlaege fuer die API: erledigte Eintraege und leere Mails fallen raus. */
    private function MailProposalsPublic(): array
    {
        $raus = [];
        foreach ($this->MailProposals() as $p) {
            $offen = [];
            foreach ((array)($p['items'] ?? []) as $i => $it) {
                if (is_array($it) && ($it['taken'] ?? false) !== true) {
                    $offen[] = ['i' => $i] + $it;
                }
            }
            if ($offen === []) {
                continue;
            }
            $p['items'] = $offen;
            $raus[] = $p;
        }
        // Neueste oben — in der Oberflaeche steht die frische Post vorn.
        usort($raus, static fn(array $a, array $b): int => (int)($b['at'] ?? 0) <=> (int)($a['at'] ?? 0));
        return $raus;
    }

    /**
     * Gemeinsamer Verteiler fuer REST und Kachel-Relay.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function MailHandleAction(array $body): array
    {
        $aktion = strtolower(trim((string)($body['action'] ?? 'list')));
        $id     = trim((string)($body['id'] ?? ''));
        switch ($aktion) {
            case 'list':
                return ['ok' => true, 'proposals' => $this->MailProposalsPublic()];
            case 'dismiss':
                return ['ok' => $id !== '' && $this->MailDismiss($id)];
            case 'taken':
                return ['ok' => $id !== '' && $this->MailMarkTaken($id, (int)($body['i'] ?? -1))];
        }
        return ['ok' => false, 'error' => ['code' => 'invalid_payload', 'message' => $this->Translate('Unknown action.')]];
    }

    private function MailDismiss(string $id): bool
    {
        $alle = $this->MailProposals();
        $neu  = array_values(array_filter($alle, static fn(array $p): bool => (string)($p['id'] ?? '') !== $id));
        if (count($neu) === count($alle)) {
            return false;
        }
        $this->MailWriteProposals($neu);
        return true;
    }

    private function MailMarkTaken(string $id, int $index): bool
    {
        $alle = $this->MailProposals();
        $treffer = false;
        foreach ($alle as &$p) {
            if ((string)($p['id'] ?? '') !== $id) {
                continue;
            }
            if (isset($p['items'][$index]) && is_array($p['items'][$index])) {
                $p['items'][$index]['taken'] = true;
                $treffer = true;
            }
        }
        unset($p);
        if ($treffer) {
            $this->MailWriteProposals($alle);
        }
        return $treffer;
    }

    // ────────────────────────────── Kleinteile ──────────────────────────────

    /**
     * Attribut lesen, das es vielleicht noch nicht gibt.
     *
     * Ein Modul-Reload fuehrt Create() nicht erneut aus — bis zum naechsten
     * Kernel-Neustart fehlt das Attribut. ReadAttribute* wirft dann bzw. liefert
     * `false`; ohne diesen Schutz braeche die API mitten im Aufruf ab.
     */
    private function MailAttr(string $name, string $vorgabe): string
    {
        try {
            $wert = @$this->ReadAttributeString($name);
            return is_string($wert) && $wert !== '' ? $wert : $vorgabe;
        } catch (Throwable $e) {
            return $vorgabe;
        }
    }

    /**
     * Attribut schreiben — mit Rueckleseprobe.
     *
     * `WriteAttribute*` auf ein noch nicht existierendes Attribut **wirft nicht**,
     * es tut still nichts. Ohne diese Probe verschwaende die Analyse Geld beim
     * Anbieter und der Vorschlag waere danach weg, ohne dass es jemand merkt.
     */
    private function MailWriteAttr(string $name, string $wert): bool
    {
        @$this->WriteAttributeString($name, $wert);
        if ($this->MailAttr($name, '') === $wert) {
            return true;
        }
        $this->LogMessage(sprintf(
            'SymDo: Attribut %s ist nicht speicherbar — der Symcon-Kernel muss nach dem Modul-Update einmal neu gestartet werden. Die E-Mail-Analyse bleibt bis dahin aus.',
            $name
        ), KL_ERROR);
        return false;
    }

    /**
     * Liest eine Eigenschaft, die es vielleicht noch nicht gibt.
     *
     * Die Properties dieses Traits entstehen in Create() und existieren erst nach
     * einem Kernel-Neustart. Bis dahin wirft ReadProperty* bzw. liefert `false` —
     * IPS_GetConfiguration plus array_key_exists ist der einzige verlaessliche Weg.
     * Das Ergebnis wird je PHP-Aufruf gehalten; MailScanRun liest mehrfach.
     */
    private function MailProp(string $name, mixed $vorgabe): mixed
    {
        if ($this->mailConfigCache === null) {
            $cfg = json_decode((string)@IPS_GetConfiguration($this->InstanceID), true);
            $this->mailConfigCache = is_array($cfg) ? $cfg : [];
        }
        return array_key_exists($name, $this->mailConfigCache)
            ? $this->mailConfigCache[$name]
            : $vorgabe;
    }

    private function MailIsEnabled(): bool
    {
        return (bool)$this->MailProp('MailEnabled', false)
            && $this->ReadPropertyBoolean('AiEnabled')
            && $this->AiPrivacyAccepted();
    }

    /**
     * Der Webhook haengt an DERSELBEN Einwilligung wie der Postfach-Weg: Die
     * Analyse ist der einzige Zweck der Annahme — ohne Einwilligung darf hier
     * gar keine Mail erst angenommen und abgelegt werden.
     */
    private function MailHookIsEnabled(): bool
    {
        return (bool)$this->MailProp('MailHookEnabled', false)
            && $this->ReadPropertyBoolean('AiEnabled')
            && $this->AiPrivacyAccepted();
    }

    /** @return list<int> Allgemeines Postfach zuerst, dann die der Mitglieder. */
    private function MailBoxIDs(): array
    {
        $raus = [];
        $allgemein = (int)$this->MailProp('MailBoxGeneral', 0);
        if ($allgemein > 0 && IPS_InstanceExists($allgemein)) {
            $raus[] = $allgemein;
        }
        $zeilen = json_decode((string)$this->MailProp('MailBoxes', '[]'), true);
        foreach (is_array($zeilen) ? $zeilen : [] as $zeile) {
            $id = (int)($zeile['InstanceID'] ?? 0);
            if ($id > 0 && IPS_InstanceExists($id)) {
                $raus[] = $id;
            }
        }
        return array_values(array_unique($raus));
    }

    /**
     * Freigegebene Absender fuer ein Postfach.
     *
     * Jedes Mitglied kann eine eigene Liste fuehren — bei ihm schreibt die Schule
     * direkt, waehrend im Haushalts-Postfach oft weitergeleitete Post liegt, wo
     * der eigene Haushalt der Absender ist. Eine leere Liste laesst alles durch.
     * Immer ein String, nie null: null bedeutet in MailJudge etwas anderes
     * (die Liste der Empfangsadresse).
     */
    private function MailBoxSenderAllow(int $imapID): string
    {
        $zeilen = json_decode((string)$this->MailProp('MailBoxes', '[]'), true);
        foreach (is_array($zeilen) ? $zeilen : [] as $zeile) {
            // Nur eine Zeile MIT Mitglied fuehrt eine eigene Liste. Eine Zeile ohne
            // gehoert niemandem — sie faellt auf die des Haushalts-Postfachs
            // zurueck, statt mit ihrem leeren Feld jeden Absender freizugeben.
            if ((int)($zeile['InstanceID'] ?? 0) === $imapID
                && trim((string)($zeile['UserID'] ?? '')) !== '') {
                return (string)($zeile['SenderAllow'] ?? '');
            }
        }
        return (string)$this->MailProp('MailSenderAllow', '');
    }

    /**
     * Mitglied eines Postfachs — die Zuordnung des IMAP-Weges.
     *
     * Das Postfach entscheidet, nicht die Empfaengeradresse: wer ein eigenes
     * Postfach hat, ist damit erkannt, und im Haushalts-Postfach ist ohnehin
     * niemand bestimmter gemeint. Ein Postfach ohne Mitglied liefert '' — die
     * Vorschlaege erscheinen dann ohne Zuordnung.
     */
    private function MailBoxMember(int $imapID): string
    {
        $zeilen = json_decode((string)$this->MailProp('MailBoxes', '[]'), true);
        foreach (is_array($zeilen) ? $zeilen : [] as $zeile) {
            if ((int)($zeile['InstanceID'] ?? 0) === $imapID) {
                return trim((string)($zeile['UserID'] ?? ''));
            }
        }
        return '';
    }

    /**
     * @return array<string, array{UserID: string, SenderAllow: string}> Adresse (klein) → Zeile.
     *         Jede Empfangsadresse traegt ihr Mitglied und ihre eigene Absenderliste.
     */
    private function MailAddressMap(): array
    {
        $zeilen = json_decode((string)$this->MailProp('MailAddresses', '[]'), true);
        $karte = [];
        foreach (is_array($zeilen) ? $zeilen : [] as $zeile) {
            $adresse = strtolower(trim((string)($zeile['Address'] ?? '')));
            if ($adresse === '') {
                continue;
            }
            $karte[$adresse] = [
                'UserID'      => trim((string)($zeile['UserID'] ?? '')),
                'SenderAllow' => (string)($zeile['SenderAllow'] ?? '')
            ];
        }
        return $karte;
    }

    /** @return list<int> */
    private function MailTriggerVariables(int $imapID): array
    {
        $raus = [];
        foreach (self::MAIL_TRIGGER_IDENTS as $ident) {
            $varID = @IPS_GetObjectIDByIdent($ident, $imapID);
            if (is_int($varID) && $varID > 0 && IPS_VariableExists($varID)) {
                $raus[] = $varID;
            }
        }
        return $raus;
    }

    /**
     * Wurde dieser Eintrag schon verarbeitet?
     *
     * Der „Topf" trennt die Quellen: fuer den IMAP-Weg ist es die Instanz-ID (rein
     * numerisch, wie bisher), fuer den Webhook MAIL_HOOK_KEY. Damit bleiben alte
     * Eintraege im Attribut unveraendert gueltig — kein Migrationsschritt noetig.
     */
    private function MailSeen(string $topf, string $schluessel): bool
    {
        $karte = json_decode($this->MailAttr('MailSeenUIDs', '{}'), true);
        $liste = is_array($karte) ? (array)($karte[$topf] ?? []) : [];
        return in_array($schluessel, array_map('strval', $liste), true);
    }

    private function MailRemember(string $topf, string $schluessel): void
    {
        $karte = json_decode($this->MailAttr('MailSeenUIDs', '{}'), true);
        $karte = is_array($karte) ? $karte : [];
        $liste = array_map('strval', (array)($karte[$topf] ?? []));
        $liste[] = $schluessel;
        if (count($liste) > self::MAIL_SEEN_MAX) {
            $liste = array_slice($liste, -self::MAIL_SEEN_MAX);
        }
        $karte[$topf] = array_values(array_unique($liste));
        // Eine erledigte Mail braucht ihren Fehlversuchs-Zaehler nicht mehr.
        if (isset($karte[self::MAIL_FAIL_KEY][$topf . ':' . $schluessel])) {
            unset($karte[self::MAIL_FAIL_KEY][$topf . ':' . $schluessel]);
            if ($karte[self::MAIL_FAIL_KEY] === []) {
                unset($karte[self::MAIL_FAIL_KEY]);
            }
        }
        $this->MailWriteAttr('MailSeenUIDs', json_encode($karte, JSON_UNESCAPED_UNICODE));
    }

    /** Fehlversuch einer Mail vermerken; liefert den neuen Stand des Zaehlers. */
    private function MailCountFailure(string $topf, string $schluessel): int
    {
        $karte = json_decode($this->MailAttr('MailSeenUIDs', '{}'), true);
        $karte = is_array($karte) ? $karte : [];
        $fehl = is_array($karte[self::MAIL_FAIL_KEY] ?? null) ? $karte[self::MAIL_FAIL_KEY] : [];
        $eintrag = $topf . ':' . $schluessel;
        $n = (int)($fehl[$eintrag] ?? 0) + 1;
        $fehl[$eintrag] = $n;
        // Der Zaehlerbestand bleibt klein: mehr als 50 gleichzeitig scheiternde
        // Mails gibt es nicht, ohne dass laengst etwas Groesseres kaputt ist.
        if (count($fehl) > 50) {
            $fehl = array_slice($fehl, -50, null, true);
        }
        $karte[self::MAIL_FAIL_KEY] = $fehl;
        $this->MailWriteAttr('MailSeenUIDs', json_encode($karte, JSON_UNESCAPED_UNICODE));
        return $n;
    }

    private function MailDayLimitReached(): bool
    {
        $grenze = (int)$this->MailProp('MailDailyLimit', 20);
        if ($grenze <= 0) {
            return false; // 0 = ohne Begrenzung
        }
        $stand = json_decode($this->MailAttr('MailDayCount', '{}'), true);
        $stand = is_array($stand) ? $stand : [];
        if ((string)($stand['d'] ?? '') !== date('Y-m-d')) {
            return false;
        }
        return (int)($stand['n'] ?? 0) >= $grenze;
    }

    private function MailCountDay(): void
    {
        $stand = json_decode($this->MailAttr('MailDayCount', '{}'), true);
        $stand = is_array($stand) ? $stand : [];
        $heute = date('Y-m-d');
        $n = ((string)($stand['d'] ?? '') === $heute) ? (int)($stand['n'] ?? 0) : 0;
        $this->MailWriteAttr('MailDayCount', json_encode(['d' => $heute, 'n' => $n + 1]));
    }
}

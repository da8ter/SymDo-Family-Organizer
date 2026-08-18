<?php

declare(strict_types=1);

/**
 * Aufgaben aus weitergeleiteten E-Mails.
 *
 * Der Nutzer leitet Post an ein Postfach weiter — je Haushaltsmitglied eine
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
    /** So viele Zeilen weit wird nach dem zitierten Kopf gesucht (siehe MailDetectOrigin). */
    private const MAIL_ORIGIN_LINES  = 40;

    private function MailCreate(): void
    {
        $this->RegisterPropertyBoolean('MailEnabled', false);
        // Liste: je Zeile eine IMAP-Instanz.
        $this->RegisterPropertyString('MailBoxes', '[]');
        // Liste: Empfaengeradresse → Mitglied. Bewusst eine Tabelle und kein Feld
        // am Nutzer: es gibt Adressen ohne eigenes Mitglied (die allgemeine) und
        // mehrere Adressen pro Mitglied.
        $this->RegisterPropertyString('MailAddresses', '[]');
        // Freigegebene Absender, einer pro Zeile; auch „@domain.tld". Leer = alle.
        $this->RegisterPropertyString('MailSenderAllow', '');
        $this->RegisterPropertyInteger('MailDailyLimit', 20);
        $this->RegisterPropertyBoolean('MailDeleteAfter', false);
        // Anhaenge holt ein eigener, schmaler IMAP-Zugriff (Trait MailFetch) —
        // das Kernmodul liefert sie nicht. Abschaltbar, weil es eine zweite
        // Verbindung ins Postfach aufbaut.
        $this->RegisterPropertyBoolean('MailReadAttachments', true);

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
        // Post eingetroffen sein, ohne dass ein Abo bestand.
        if ($this->MailIsEnabled()) {
            $this->MailArm();
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
        if ($Ident === 'MailScanNow') {
            foreach ($this->MailBoxIDs() as $imapID) {
                @IMAP_UpdateCache($imapID);
            }
            $this->MailArm();
            return true;
        }
        return false;
    }

    /** Setzt den One-Shot-Timer. Die Arbeit gehoert nicht in den Nachrichten-Thread. */
    private function MailArm(): void
    {
        try {
            $this->SetTimerInterval('MailScan', self::MAIL_TIMER_MS);
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
        if (!$this->MailIsEnabled()) {
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
                if ($uid === '' || $this->MailSeen($imapID, $uid)) {
                    continue;
                }
                $urteil = $this->MailJudge($mail);
                if ($urteil['skip']) {
                    // Verworfene Mail trotzdem vermerken, sonst wird sie bei jedem
                    // Signal erneut geprueft.
                    $this->MailRemember($imapID, $uid);
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
                // sonst ist die Aufgabe fuer immer verloren.
                if ($this->MailAnalyse($imapID, $mail, $urteil['userId'])) {
                    $this->MailRemember($imapID, $uid);
                    $offen = true;
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
     * Darf diese Mail analysiert werden? Liefert zugleich das Mitglied, dem die
     * Empfaengeradresse gehoert.
     *
     * @return array{skip: bool, reason: string, userId: string}
     */
    private function MailJudge(array $mail): array
    {
        $nein = static fn(string $grund): array => ['skip' => true, 'reason' => $grund, 'userId' => ''];

        $empfaenger = strtolower(trim((string)($mail['Recipient'] ?? '')));
        $karte      = $this->MailAddressMap();
        if ($karte === []) {
            return $nein('keine Adress-Zuordnung konfiguriert');
        }
        $userId = null;
        foreach ($this->MailRecipientCandidates($empfaenger) as $kandidat) {
            if (array_key_exists($kandidat, $karte)) {
                $userId = $karte[$kandidat];
                break;
            }
        }
        if ($userId === null) {
            return $nein('Empfaenger ' . $empfaenger . ' nicht zugeordnet');
        }

        $absender = strtolower(trim((string)($mail['SenderAddress'] ?? '')));
        if (!$this->MailSenderAllowed($absender)) {
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

    private function MailSenderAllowed(string $absender): bool
    {
        $roh = trim((string)$this->MailProp('MailSenderAllow', ''));
        if ($roh === '') {
            return true; // keine Liste = keine Einschraenkung
        }
        foreach (preg_split('/[\r\n,;]+/', strtolower($roh)) ?: [] as $eintrag) {
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

        $betreff = trim((string)($kopf['Subject'] ?? ''));
        $eingabe = ($betreff !== '' ? 'Betreff: ' . $betreff . "\n\n" : '') . $text;

        // Anhang dazuholen. Das Kernmodul liefert ihn nicht — MailFetch schlaegt
        // lesend im selben Postfach nach. Gerade Elternbriefe tragen die Termine
        // ausschliesslich im PDF; ohne das bliebe nur „siehe Anhang" uebrig.
        // Symcons php.ini setzt memory_limit auf 32 MB. Ein 5-MB-PDF liegt auf dieser
        // Strecke mehrfach gleichzeitig im Speicher (Rohpuffer, base64, data:-URL,
        // JSON-Rumpf) — ohne Luft bricht der Lauf mitten im Anbieter-Aufruf ab.
        // Nur fuer diesen Abschnitt, danach zurueck auf den Ausgangswert.
        $speicherVorher = (string)@ini_get('memory_limit');
        $anhang = null;
        if ((bool)$this->MailProp('MailReadAttachments', true)) {
            @ini_set('memory_limit', '192M');
            $anhang = $this->MailFetchAttachment($imapID, $uid);
        }
        $bild = ($anhang !== null && $anhang['kind'] === 'image') ? $anhang['base64'] : null;
        $pdf  = ($anhang !== null && $anhang['kind'] === 'pdf') ? $anhang['base64'] : null;
        if ($anhang !== null && $anhang['name'] !== '') {
            $eingabe .= "\n\n(Beigefuegte Datei: " . $anhang['name'] . ')';
        }

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
        if ($speicherVorher !== '') {
            @ini_set('memory_limit', $speicherVorher);
        }
        $aufgaben = $this->AiParseTodos((string)$r['text']);
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
        foreach ($aufgaben as $a) {
            if (($a['due'] ?? null) !== null) {
                $mitDatum++;
            }
            if (($a['kind'] ?? 'task') === 'event') {
                $termine++;
            }
        }
        $this->LogMessage(sprintf(
            'SymDo: E-Mail „%s" von %s analysiert%s → %d Aufgabe(n), %d Termin(e), davon %d mit Datum',
            $betreff !== '' ? $betreff : '(ohne Betreff)',
            (string)($kopf['SenderAddress'] ?? '?'),
            $anhang !== null ? ' (mit Anhang ' . ($anhang['name'] !== '' ? $anhang['name'] : $anhang['kind']) . ')' : '',
            count($aufgaben) - $termine,
            $termine,
            $mitDatum
        ), KL_NOTIFY);
        if ($aufgaben === []) {
            return true; // sauber analysiert, nur nichts zu tun gefunden
        }

        $this->MailStoreProposal([
            'id'        => $imapID . ':' . $uid,
            'at'        => (int)($kopf['Date'] ?? time()),
            'from'      => (string)($kopf['SenderAddress'] ?? ''),
            'fromName'  => (string)($kopf['SenderName'] ?? ''),
            'subject'   => $betreff,
            'recipient' => (string)($kopf['Recipient'] ?? ''),
            'userId'    => $userId,
            // Wer die Mail urspruenglich geschrieben hat und wie sie damals hiess.
            // Der aeussere Kopf nennt immer nur den weiterleitenden Haushalt und ein
            // „Fwd:" davor — beides sagt dem Nutzer nichts.
            'origin'    => $this->MailDetectOrigin($text),
            'items'     => array_map(static fn(array $a): array => $a + ['taken' => false], $aufgaben),
        ]);

        if ((bool)$this->MailProp('MailDeleteAfter', false)) {
            @IMAP_DeleteMail($imapID, $uid);
        }
        return true;
    }

    /**
     * Absender und Betreff der WEITERGELEITETEN Mail aus dem zitierten Kopf lesen.
     *
     * Bei einer Weiterleitung steht im aeusseren Kopf nur, wer sie weitergeleitet hat
     * — im Haushalt also immer dieselbe Person — und im Betreff ein „Fwd:" davor. Wer
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
            $text = substr($text, 0, self::MAIL_TEXT_MAX);
        }
        return $text;
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

    /** @return list<int> */
    private function MailBoxIDs(): array
    {
        $zeilen = json_decode((string)$this->MailProp('MailBoxes', '[]'), true);
        $raus = [];
        foreach (is_array($zeilen) ? $zeilen : [] as $zeile) {
            $id = (int)($zeile['InstanceID'] ?? 0);
            if ($id > 0 && IPS_InstanceExists($id)) {
                $raus[] = $id;
            }
        }
        return array_values(array_unique($raus));
    }

    /** @return array<string, string> Adresse (klein) → Mitglieds-ID */
    private function MailAddressMap(): array
    {
        $zeilen = json_decode((string)$this->MailProp('MailAddresses', '[]'), true);
        $karte = [];
        foreach (is_array($zeilen) ? $zeilen : [] as $zeile) {
            $adresse = strtolower(trim((string)($zeile['Address'] ?? '')));
            if ($adresse === '') {
                continue;
            }
            $karte[$adresse] = trim((string)($zeile['UserID'] ?? ''));
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

    private function MailSeen(int $imapID, string $uid): bool
    {
        $karte = json_decode($this->MailAttr('MailSeenUIDs', '{}'), true);
        $liste = is_array($karte) ? (array)($karte[(string)$imapID] ?? []) : [];
        return in_array($uid, array_map('strval', $liste), true);
    }

    private function MailRemember(int $imapID, string $uid): void
    {
        $karte = json_decode($this->MailAttr('MailSeenUIDs', '{}'), true);
        $karte = is_array($karte) ? $karte : [];
        $liste = array_map('strval', (array)($karte[(string)$imapID] ?? []));
        $liste[] = $uid;
        if (count($liste) > self::MAIL_SEEN_MAX) {
            $liste = array_slice($liste, -self::MAIL_SEEN_MAX);
        }
        $karte[(string)$imapID] = array_values(array_unique($liste));
        $this->MailWriteAttr('MailSeenUIDs', json_encode($karte, JSON_UNESCAPED_UNICODE));
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

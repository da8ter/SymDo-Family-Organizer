<?php

declare(strict_types=1);

/**
 * Sprachdialog (SymDo Voice) — Sitzungsseite.
 *
 * Der Ton läuft NICHT über diesen Server: die Kachel spricht per WebRTC direkt
 * mit dem Anbieter. Hier passiert nur dreierlei:
 *   1. Marke prägen (kurzlebiger Zugang, der Dauerschlüssel bleibt im Haus),
 *   2. Buch führen (offene Anrufe, Sekunden, Tagesdeckel) und auflegen —
 *      der Wachhund-Timer ist die Kostensicherung, nicht der Browser,
 *   3. Werkzeugaufrufe ausführen (siehe VoiceTools.php).
 *
 * Einwilligung: eigener, zweiter Riegel neben AiPrivacyAccepted — Raumton in
 * Echtzeit ist eine andere Kategorie als eine gewählte Datei.
 */
trait Voice
{
    /** Ablauf der geprägten Marke: kurz, denn eine Marke kann laut Doku mehrere Sitzungen eröffnen. */
    private static int $VOICE_SECRET_TTL = 60;

    /** Herzschlag-Frist: meldet sich eine Sitzung so lange nicht, legt der Wachhund auf. */
    private static int $VOICE_PING_MAX = 45;

    /** Takt des Wachhunds in Millisekunden. */
    private static int $VOICE_TICK_MS = 15000;

    /** Bestätigungsmarke fürs Löschen: kurzlebig, einmal verwendbar. Serien enger. */
    private static int $VOICE_MARKE_TTL = 90;
    private static int $VOICE_MARKE_TTL_SERIE = 60;

    /** Löschdeckel: so viele erfolgreiche Löschungen je Stunde — ein durchdrehendes Modell räumt keine Liste leer. */
    private static int $VOICE_LOESCH_MAX = 10;

    /** Schaltdeckel: erfolgreiche Geräteschaltungen je Stunde. Höher als beim
     *  Löschen, weil Schalten umkehrbar ist und ein Abend in der Familie 20–30
     *  Schaltungen bringt; eine Schleife des Modells träfe die 60 in einer Minute. */
    private static int $VOICE_GERAETE_MAX = 60;

    private function VoiceCreate(): void
    {
        $this->RegisterPropertyBoolean('VoiceEnabled', false);
        $this->RegisterPropertyString('VoiceModel', 'gpt-realtime-mini');
        $this->RegisterPropertyString('VoiceVoice', 'marin');
        $this->RegisterPropertyInteger('VoiceDailyMinutes', 15);
        $this->RegisterPropertyInteger('VoiceMaxSessionSeconds', 180);
        // Reserve für den unified-Handschlag (Etappe 0 hat ihn nicht gebraucht).
        $this->RegisterPropertyString('VoiceHandshake', 'ephemeral');
        /* Freisprechen ist ein gekennzeichneter Versuch: es laeuft NUR, wo der
           Browser deutsche Spracherkennung auf dem Geraet rechnen kann
           (Chrome/Edge ab 139). Standard aus. */
        $this->RegisterPropertyBoolean('VoiceHandsFreeAllowed', false);
        /* Wer die Handbuch-Fundstellen liest und die Antwort formuliert. Leer =
           niemand, dann bleibt es beim Satzanfang plus Auszug (Verhalten vor
           dem 02.09.2026). Messwerte stehen in SymconDoku.php. */
        $this->RegisterPropertyString('VoiceDocModel', 'gpt-4.1');
        /* Gerätesteuerung per Sprache. Standard AUS: der Assistent war bis zum
           07.09.2026 ausdrücklich eingezäunt („Du steuerst NICHTS im Haus"),
           und wer das ändert, soll es wissentlich tun. Die Reichweite sind
           WURZEL-Kategorien — alles darunter, das sichtbar ist und eine Aktion
           hat; die zweite Liste nennt, was nur nach gesprochener Rückfrage
           geschaltet wird (Schloss, Alarm, Garage) — und von Kindern gar nicht. */
        $this->RegisterPropertyBoolean('VoiceDevicesEnabled', false);
        $this->RegisterPropertyString('VoiceDeviceRoots', '[]');     // [{"objectID": 12345}, …]
        $this->RegisterPropertyString('VoiceDeviceConfirm', '[]');   // [{"objectID": 456}, …]

        $this->RegisterAttributeBoolean('VoicePrivacyAccepted', false);
        $this->RegisterAttributeString('VoicePrivacyAcceptedAt', '');
        /* DRITTE Einwilligung, mit Absicht getrennt: wer dem Knopf zugestimmt
           hat, hat nicht dem Dauerlauschen zugestimmt. */
        $this->RegisterAttributeBoolean('VoiceHandsFreeAccepted', false);
        $this->RegisterAttributeString('VoiceHandsFreeAcceptedAt', '');
        /* VIERTE Einwilligung, wieder getrennt: wer dem Reden zugestimmt hat,
           hat nicht zugestimmt, dass jeder im Raum Licht, Rollläden oder Türen
           schaltet. */
        $this->RegisterAttributeBoolean('VoiceDevicesAccepted', false);
        $this->RegisterAttributeString('VoiceDevicesAcceptedAt', '');
        $this->RegisterAttributeString('VoiceOpenCalls', '{}');
        $this->RegisterAttributeString('VoiceDayCount', '{}');
        $this->RegisterAttributeString('VoiceLog', '[]');
        // Reserven der späteren Etappen (Auflöser-Merkzettel, Löschmarken).
        $this->RegisterAttributeString('VoiceRefs', '{}');
        $this->RegisterAttributeString('VoiceMarks', '{}');
        // Kurzzeitgedaechtnis gegen doppelte Ausfuehrung (siehe VoiceDoppelt).
        $this->RegisterAttributeString('VoiceDedup', '{}');

        $this->RegisterTimer('VoiceWatchdog', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'VoiceTick\', 0);');
    }

    private function VoiceApplyChanges(): void
    {
        /* Nach einem Kernel-Start kann kein vermerkter Anruf überlebt haben —
           auflegen ist harmlos, offen stehen lassen kostet Geld.
           ABER: ApplyChanges läuft nicht nur beim Start. Es läuft auch, wenn
           jemand das Formular speichert oder die App ein Profil ändert
           (AppCore::UpdateAppUser) — und legte bis hierher ein LAUFENDES Gespräch
           mitten im Satz auf. Deshalb wird der Herzschlag befragt: Wer innerhalb
           der Ping-Frist noch da war, lebt und bleibt; alles Ältere ist eine
           Leiche aus der Zeit vor dem Neustart. */
        try {
            $offen = json_decode((string)@$this->ReadAttributeString('VoiceOpenCalls'), true);
            $offen = is_array($offen) ? $offen : [];
            $jetzt = time();
            $tot   = 0;
            foreach ($offen as $callId => $c) {
                if (($jetzt - (int)($c['lastPing'] ?? 0)) <= self::$VOICE_PING_MAX) {
                    continue;   // spricht noch
                }
                $this->VoiceHangup((string)$callId);
                unset($offen[$callId]);
                $tot++;
            }
            if ($tot > 0) {
                $this->WriteAttributeString('VoiceOpenCalls', (string)json_encode($offen));
                $this->LogMessage('SymDo Sprache: ' . $tot . ' Altsitzung(en) aufgelegt', KL_NOTIFY);
            }
            /* Der Wachhund muss weiterlaufen, solange noch jemand spricht: er
               bucht die Sekunden und zieht den Sitzungsdeckel. Ihn hier blind
               abzustellen hiess, ein laufendes Gespräch ungezählt und ungedeckelt
               weiterlaufen zu lassen. */
            @$this->SetTimerInterval('VoiceWatchdog', $offen === [] ? 0 : self::$VOICE_TICK_MS);
        } catch (\Throwable $e) {
            // Attribute/Timer existieren vor dem ersten Kernel-Neustart noch nicht.
        }
    }

    /** Glied der RequestAction-Kette des Gateways. */
    private function VoiceRequestAction(string $Ident, mixed $Value): bool
    {
        if ($Ident === 'VoiceTick') {
            $this->VoiceWatchdogTick();
            return true;
        }
        if ($Ident === 'VoicePrivacyConsent') {
            $accepted = ($Value === true || $Value === 1 || $Value === '1' || $Value === 'true');
            $this->WriteAttributeBoolean('VoicePrivacyAccepted', $accepted);
            $this->WriteAttributeString('VoicePrivacyAcceptedAt', $accepted ? date('c') : '');
            if (!$accepted) {
                // Widerruf beendet laufende Gespräche sofort — eine Einwilligung
                // zurückzunehmen, während der Ton weiterläuft, wäre genau der
                // Fehler, den der Riegel verhindern soll.
                $this->VoiceHangupAll('Einwilligung widerrufen');
                /* Und das Dauerlauschen faellt mit: es ist die WEITERGEHENDE
                   Einwilligung, sie kann die engere nicht ueberleben. */
                @$this->WriteAttributeBoolean('VoiceHandsFreeAccepted', false);
                @$this->WriteAttributeString('VoiceHandsFreeAcceptedAt', '');
                // Und die Gerätesteuerung ebenso — aus demselben Grund.
                @$this->WriteAttributeBoolean('VoiceDevicesAccepted', false);
                @$this->WriteAttributeString('VoiceDevicesAcceptedAt', '');
            }
            $this->ReloadForm();
            return true;
        }
        if ($Ident === 'VoiceDevicesConsent') {
            $ja = ($Value === true || $Value === 1 || $Value === '1' || $Value === 'true');
            // Ohne die Sprach-Einwilligung gibt es die weitergehende nicht.
            if ($ja && !(bool)@$this->ReadAttributeBoolean('VoicePrivacyAccepted')) {
                $ja = false;
            }
            @$this->WriteAttributeBoolean('VoiceDevicesAccepted', $ja);
            @$this->WriteAttributeString('VoiceDevicesAcceptedAt', $ja ? date('c') : '');
            if (!$ja) {
                /* Widerruf legt auf: Anweisungen und Werkzeuge werden je Gespräch
                   EINMAL geprägt (VoiceMintSecret) — eine laufende Sitzung böte
                   die Gerätewerkzeuge sonst bis zu ihrem Ende weiter an. Der
                   Riegel je Aufruf hielte das ungefährlich, aber Auflegen ist
                   billig und sagt dasselbe wie beim Widerruf der Sprache. */
                $this->VoiceHangupAll('Geräte-Einwilligung widerrufen');
            }
            $this->ReloadForm();
            return true;
        }
        if ($Ident === 'VoiceHandsFreeConsent') {
            $ja = ($Value === true || $Value === 1 || $Value === '1' || $Value === 'true');
            // Ohne die Sprach-Einwilligung gibt es die weitergehende nicht.
            if ($ja && !(bool)@$this->ReadAttributeBoolean('VoicePrivacyAccepted')) {
                $ja = false;
            }
            @$this->WriteAttributeBoolean('VoiceHandsFreeAccepted', $ja);
            @$this->WriteAttributeString('VoiceHandsFreeAcceptedAt', $ja ? date('c') : '');
            $this->ReloadForm();
            return true;
        }
        if ($Ident === 'VoiceGeraeteKatalogZeigen') {
            $zeilen = [];
            foreach (array_slice($this->VoiceGeraeteKatalog(), 0, 40) as $e) {
                $zeilen[] = sprintf('%s / %s / %s%s', $e['raum'] !== '' ? $e['raum'] : '—', $e['name'],
                    $e['typ'] === 'var' ? ($e['akt'] ? $this->Translate('device') : $this->Translate('read-only')) : $e['typ'],
                    $this->VoiceGeraetMitRueckfrage((int)$e['id']) ? ' (' . $this->Translate('confirmation') . ')' : '');
            }
            echo $zeilen === [] ? $this->Translate('No devices found below the root categories.') : implode("\n", $zeilen);
            return true;
        }
        if ($Ident === 'VoiceTest') {
            // Testverbindung: 10-Sekunden-Marke prägen und verwerfen. Beweist
            // Schlüssel und Modellfreigabe, ohne eine Sekunde Ton zu bezahlen.
            $r = $this->VoiceMintSecret(10);
            echo ($r['ok'] ?? false)
                ? sprintf($this->Translate('Test connection OK — model %s, token expires in %d s.'),
                    (string)($r['model'] ?? '?'), max(0, (int)($r['expiresAt'] ?? 0) - time()))
                : (string)($r['error']['message'] ?? 'Fehler');
            return true;
        }
        if ($Ident === 'VoiceHangupAll') {
            $n = $this->VoiceHangupAll('von Hand beendet');
            echo sprintf($this->Translate('%d session(s) ended.'), $n);
            return true;
        }
        return false;
    }

    // ------------------------------------------------------------------
    // Die eine Eintrittstür (Relay der Kachel UND HTTP-Route der Web-App)
    // ------------------------------------------------------------------

    /** @param array<string,mixed> $body @return array<string,mixed> */
    /** Anfragen je Stunde und Geraet auf dem Sprachweg (ohne `open`). Eine
     *  Stunde ununterbrochenes Reden sind rund 120 Herzschlaege plus
     *  Werkzeuge; alles darueber ist keine Unterhaltung mehr. */
    private const VOICE_RATE_MAX = 900;

    private function VoiceHandleAction(array $body, ?array $device): array
    {
        $action = (string)($body['action'] ?? '');
        switch ($action) {
            case 'open':
                return $this->VoiceOpen($body);
            case 'opened':
                return $this->VoiceOpened($body);
            case 'ping':
                return $this->VoicePing($body);
            case 'tool':
                return $this->VoiceTool($body);
            case 'handsfree':
                /* Der Lauscher fragt VOR dem Anschalten, ob er darf. Die
                   Prüfung gehört hierher, nicht in den Browser: dort ließe sich
                   die Einwilligung umgehen, und das Attribut ist von außen
                   ohnehin nicht lesbar. */
                return $this->VoiceHandsFreeOk()
                    ? ['ok' => true, 'erlaubt' => true]
                    : ['ok' => true, 'erlaubt' => false,
                       'grund' => $this->Translate('Hands-free is not enabled for this household.')];
            case 'fehler':
                /* Der Browser meldet, was ihm der Anbieter geantwortet hat. Ohne
                   diesen Weg endet die Begruendung im Browser und ist beim
                   Nachsehen weg — genau der Fall „429 Verbindungsstatus", bei
                   dem hinterher niemand sagen konnte, warum. */
                $this->VoiceLogEintrag(
                    'Verbindung ' . mb_substr((string)($body['stelle'] ?? '?'), 0, 40),
                    'HTTP ' . (int)($body['status'] ?? 0) . ' — '
                        . mb_substr((string)($body['grund'] ?? ''), 0, 300),
                    false
                );
                return ['ok' => true];
            case 'close':
                return $this->VoiceClose($body);
            case 'log':
                $log = json_decode((string)@$this->ReadAttributeString('VoiceLog'), true);
                return ['ok' => true, 'log' => array_slice(is_array($log) ? $log : [], 0, 20)];
        }
        return $this->VoiceErr('invalid_payload', $this->Translate('Invalid call'));
    }

    /** @return array<string,mixed> */
    /**
     * Ist der Sprachdialog überhaupt benutzbar? Dieselben Riegel wie in
     * VoiceOpen, nur als Ja/Nein — die Web-App blendet ihre Kachel danach ein
     * oder aus. Der Tagesdeckel zählt hier NICHT mit: ein aufgebrauchtes Budget
     * ist morgen wieder da, die Kachel soll deshalb nicht verschwinden.
     */
    private function VoiceUsable(): bool
    {
        return $this->VoiceEnabledProp()
            && $this->AiPrivacyAccepted()
            && (bool)@$this->ReadAttributeBoolean('VoicePrivacyAccepted')
            && trim($this->ReadPropertyString('AiOpenAIKey')) !== '';
    }

    /**
     * Darf dieses Haus freisprechen? Property UND die dritte Einwilligung UND
     * alles, was der Knopf ohnehin braucht. Ob das GERAET es kann, entscheidet
     * erst der Browser (voice-wake.js) — hier steht nur die Erlaubnis.
     */
    private function VoiceHandsFreeOk(): bool
    {
        $cfg = json_decode((string)@IPS_GetConfiguration($this->InstanceID), true);
        $erlaubt = is_array($cfg) && ($cfg['VoiceHandsFreeAllowed'] ?? false) === true;
        return $erlaubt
            && $this->VoiceUsable()
            && (bool)@$this->ReadAttributeBoolean('VoiceHandsFreeAccepted');
    }

    /** @return array<string,mixed> */
    /**
     * Der gemeinsame Riegel des Sprachwegs: Schalter und Einwilligung.
     *
     * @return array<string,mixed>|null Fehlerantwort, oder null wenn offen
     */
    private function VoiceTorZu(): ?array
    {
        if (!$this->VoiceEnabledProp()) {
            return $this->VoiceErr('voice_disabled', $this->Translate('The voice dialog is disabled.'));
        }
        if (!$this->AiPrivacyAccepted() || !(bool)@$this->ReadAttributeBoolean('VoicePrivacyAccepted')) {
            return $this->VoiceErr('voice_privacy_required', $this->Translate('The privacy consent for the voice dialog is missing — open the gateway settings.'));
        }
        return null;
    }

    /**
     * Der Riegel der Gerätesteuerung: Sprachdialog offen UND Schalter an UND
     * die vierte Einwilligung. Gelesen wird die Property über
     * IPS_GetConfiguration wie in VoiceHandsFreeOk — vor dem Kernel-Neustart
     * gibt es sie nicht, und ReadPropertyBoolean würfe.
     *
     * Er sitzt an ZWEI Stellen: beim Prägen der Sitzung (VoiceToolSpec und
     * VoiceInstructions blenden die Werkzeuge aus) und je Aufruf in
     * VoiceRunTool — nur die zweite hält eine Sitzung auf, die geprägt wurde,
     * bevor jemand den Schalter umlegte.
     *
     * @return array<string,mixed>|null Fehlerantwort, oder null wenn offen
     */
    private function VoiceGeraeteTorZu(): ?array
    {
        $zu = $this->VoiceTorZu();
        if ($zu !== null) {
            return $zu;
        }
        $cfg = json_decode((string)@IPS_GetConfiguration($this->InstanceID), true);
        if (!is_array($cfg) || ($cfg['VoiceDevicesEnabled'] ?? false) !== true) {
            return $this->VoiceErr('geraete_aus', $this->Translate('Device control by voice is switched off.'));
        }
        if (!(bool)@$this->ReadAttributeBoolean('VoiceDevicesAccepted')) {
            return $this->VoiceErr('geraete_einwilligung_fehlt', $this->Translate('The consent for device control is missing — open the gateway settings.'));
        }
        return null;
    }

    private function VoiceGeraeteOk(): bool
    {
        return $this->VoiceGeraeteTorZu() === null;
    }

    /**
     * Die Rolle des Sprechenden aus der Mitgliederliste (father, mother, child,
     * …) — '' wenn unbekannt. Die Kachel setzt die userId serverseitig aus
     * ihrer Property (SymDoVoice/module.php), die Web-App schickt sie mit;
     * eine LEERE userId zählt als Erwachsener: die Kopplung eines Geräts ist
     * ein Erwachsenenakt, und die Kachel liefert immer eine.
     */
    private function VoicePersona(string $userId): string
    {
        if ($userId === '') {
            return '';
        }
        try {
            foreach ($this->LoadUsers() as $u) {
                if ((string)($u['id'] ?? '') === $userId) {
                    return (string)($u['persona'] ?? '');
                }
            }
        } catch (\Throwable $e) {
            // ohne Mitgliederliste eben ohne Rolle
        }
        return '';
    }

    /** @param array<string,mixed> $ctx */
    private function VoiceIstKind(array $ctx): bool
    {
        return $this->VoicePersona((string)($ctx['userId'] ?? '')) === 'child';
    }

    /**
     * Objektnummern aus einer Formularliste ([{"objectID": n}, …]). Über
     * IPS_GetProperty statt ReadPropertyString — dieselbe Begründung wie in
     * LoadUsers: sieht auch, was gerade erst gespeichert wurde.
     *
     * @return list<int>
     */
    private function VoiceObjektListe(string $property): array
    {
        $roh = json_decode((string)@IPS_GetProperty($this->InstanceID, $property), true);
        $ids = [];
        foreach (is_array($roh) ? $roh : [] as $zeile) {
            $id = (int)(is_array($zeile) ? ($zeile['objectID'] ?? 0) : $zeile);
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /** Steht dieses Objekt auf der Liste „nur mit Rückfrage"? */
    private function VoiceGeraetMitRueckfrage(int $objectID): bool
    {
        return in_array($objectID, $this->VoiceObjektListe('VoiceDeviceConfirm'), true);
    }

    /** Ist der Schaltdeckel dieser Stunde noch offen? (Muster: VoiceLoeschDeckelOffen) */
    private function VoiceGeraeteDeckelOffen(): bool
    {
        if (self::$VOICE_GERAETE_MAX <= 0) {
            return true;
        }
        $stand = json_decode((string)@$this->ReadAttributeString('VoiceDayCount'), true);
        $stunde = date('Y-m-d-H');
        $n = (is_array($stand) && ($stand['gerh'] ?? '') === $stunde) ? (int)($stand['gern'] ?? 0) : 0;
        return $n < self::$VOICE_GERAETE_MAX;
    }

    /** Eine erfolgreiche Schaltung auf die laufende Stunde buchen. */
    private function VoiceGeraeteZaehlen(): void
    {
        $lock = 'SymDo_VoiceDay_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 1000)) {
            return;
        }
        try {
            $stand = json_decode((string)@$this->ReadAttributeString('VoiceDayCount'), true);
            $stand = is_array($stand) ? $stand : [];
            $stunde = date('Y-m-d-H');
            if (($stand['gerh'] ?? '') !== $stunde) {
                $stand['gerh'] = $stunde;
                $stand['gern'] = 0;
            }
            $stand['gern'] = (int)($stand['gern'] ?? 0) + 1;
            $this->WriteAttributeString('VoiceDayCount', (string)json_encode($stand));
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    /** Schaltungen dieser Stunde — für das Formular. */
    private function VoiceGeraeteStand(): int
    {
        $stand = json_decode((string)@$this->ReadAttributeString('VoiceDayCount'), true);
        return (is_array($stand) && ($stand['gerh'] ?? '') === date('Y-m-d-H')) ? (int)($stand['gern'] ?? 0) : 0;
    }

    private function VoiceOpen(array $body): array
    {
        // Riegel in fester Reihenfolge — alle VOR dem Prägen der Marke.
        $zu = $this->VoiceTorZu();
        if ($zu !== null) {
            return $zu;
        }
        if (trim($this->ReadPropertyString('AiOpenAIKey')) === '') {
            return $this->VoiceErr('ai_not_configured', $this->Translate('No OpenAI API key configured.'));
        }
        $rest = $this->VoiceBudgetLeft();
        if ($rest <= 0) {
            return $this->VoiceErr('voice_quota', $this->Translate('The daily talk time is used up.'));
        }

        // Je Kachel höchstens EIN frisches Gespräch; ein verwaistes wird erst aufgelegt.
        $tile = (int)($body['tile'] ?? 0);
        $offen = $this->VoiceCalls();
        foreach ($offen as $callId => $c) {
            if ((int)($c['tile'] ?? 0) !== $tile) {
                continue;
            }
            if (time() - (int)($c['lastPing'] ?? 0) <= self::$VOICE_PING_MAX) {
                return $this->VoiceErr('voice_busy', $this->Translate('A conversation is already running on this tile.'));
            }
            $this->VoiceEndCall((string)$callId, 'verwaist beim Neustart des Gesprächs');
        }

        $sitzung = min($this->ReadPropertyInteger('VoiceMaxSessionSeconds'), $rest, 3540);
        $r = $this->VoiceMintSecret(self::$VOICE_SECRET_TTL, (string)($body['userId'] ?? ''));
        if (!($r['ok'] ?? false)) {
            return $r;
        }
        $r['sessionSeconds'] = max(30, $sitzung);
        /* 30 statt 15 Sekunden: der Herzschlag zaehlt die Minuten mit und haelt
           die Sitzung wach — die Genauigkeit leidet nicht, denn gezaehlt wird
           die tatsaechlich vergangene Zeit. Er halbiert damit die Anfragen. */
        $r['pingSeconds']    = 30;
        return $r;
    }

    /**
     * Eigenes Stundenfenster fuer den Sprachweg. false = die 429 ist gesendet.
     *
     * Der Sprachdialog ist von Natur aus gespraechig: der Browser meldet sich
     * waehrend eines Gespraechs alle paar Sekunden (Herzschlag, zaehlt die
     * Minuten mit), dazu kommen die Werkzeugaufrufe. Am KI-Fenster (60 je
     * Stunde) war deshalb nach einer Viertelstunde Reden Schluss — der Nutzer
     * sah „Verbindungsstatus 429". Der eigene Topf faengt eine SCHLEIFE, nicht
     * ein Gespraech; `open` bleibt am KI-Fenster, denn nur dort entsteht eine
     * bezahlte Sitzung.
     */
    private function VoiceRateLimitOk(?array $device): bool
    {
        if ($this->DeviceRateAllows((string)($device['id'] ?? ''), 'voice', self::VOICE_RATE_MAX, 3600)) {
            return true;
        }
        $this->SendApiError('rate_limited',
            $this->Translate('Too many voice requests — please wait a moment.'), 429);
        return false;
    }

    /** @return array<string,mixed> */
    private function VoiceOpened(array $body): array
    {
        $callId = $this->VoiceCallId((string)($body['callId'] ?? ''));
        if ($callId === '') {
            return $this->VoiceErr('invalid_payload', $this->Translate('Invalid call'));
        }
        $offen = $this->VoiceCalls();
        /* Dieselbe Kennung ein zweites Mal? Dann NUR den Herzschlag auffrischen.
           Frueher wurde der Eintrag ueberschrieben — mit startedAt und accrued
           auf jetzt. Damit fielen die Sekunden seit der letzten Buchung
           ersatzlos weg, der Sitzungsdeckel ($jetzt - startedAt) wurde nie
           erreicht und das Tagesbudget wuchs nie: ein Client, der alle 20
           Sekunden „opened" schickt, telefonierte unbegrenzt auf Rechnung des
           Hauses. Auch der Sitzungszaehler stieg bei jedem Aufruf. */
        if (isset($offen[$callId])) {
            $offen[$callId]['lastPing'] = time();
            $this->VoiceCallsSchreiben($offen);
            @$this->SetTimerInterval('VoiceWatchdog', self::$VOICE_TICK_MS);
            return ['ok' => true];
        }
        $offen[$callId] = [
            'tile'      => (int)($body['tile'] ?? 0),
            'userId'    => (string)($body['userId'] ?? ''),
            'defaults'  => is_array($body['defaults'] ?? null) ? $body['defaults'] : [],
            'startedAt' => time(),
            'lastPing'  => time(),
            'accrued'   => time(),
        ];
        $this->VoiceCallsSchreiben($offen);
        $this->VoiceZaehlen(0, 1);
        @$this->SetTimerInterval('VoiceWatchdog', self::$VOICE_TICK_MS);
        return ['ok' => true];
    }

    /** @return array<string,mixed> */
    private function VoicePing(array $body): array
    {
        $callId = $this->VoiceCallId((string)($body['callId'] ?? ''));
        $offen = $this->VoiceCalls();
        if ($callId === '' || !isset($offen[$callId])) {
            return ['ok' => true, 'stop' => true, 'grund' => 'unbekannte Sitzung'];
        }
        $jetzt = time();
        $this->VoiceZaehlen(max(0, $jetzt - (int)$offen[$callId]['accrued']), 0);
        $offen[$callId]['accrued'] = $jetzt;
        $offen[$callId]['lastPing'] = $jetzt;
        $this->VoiceCallsSchreiben($offen);

        $laufzeit = $jetzt - (int)$offen[$callId]['startedAt'];
        $deckel   = min($this->ReadPropertyInteger('VoiceMaxSessionSeconds'), 3540);
        $rest     = min($deckel - $laufzeit, $this->VoiceBudgetLeft());
        if ($rest <= 0) {
            $this->VoiceEndCall($callId, 'Zeitdeckel erreicht');
            return ['ok' => true, 'stop' => true, 'grund' => $this->Translate('The daily talk time is used up.')];
        }
        return ['ok' => true, 'stop' => false, 'secondsLeft' => $rest];
    }

    /** @return array<string,mixed> */
    private function VoiceTool(array $body): array
    {
        /* DERSELBE Riegel wie beim Verbinden. Er fehlte hier: Werkzeuge legen
           Aufgaben an, streichen Artikel und schreiben Termine — wer den
           Endpunkt erreichte, konnte das auch dann, wenn der Sprachdialog
           abgeschaltet oder die Einwilligung nie erteilt war. Ein abgeschalteter
           Dialog muss auch die Hand abschalten, nicht nur den Ton. */
        $zu = $this->VoiceTorZu();
        if ($zu !== null) {
            return $zu;
        }
        $callId = $this->VoiceCallId((string)($body['callId'] ?? ''));
        $offen  = $this->VoiceCalls();
        $anruf  = $offen[$callId] ?? null;
        // Werkzeuge auch ohne registrierte Sitzung zulassen (Prüfstand, Web-App
        // später) — dann gelten die mitgegebenen Vorgaben.
        $ctx = [
            'userId'   => (string)($anruf['userId'] ?? ($body['userId'] ?? '')),
            'defaults' => is_array($anruf['defaults'] ?? null) ? $anruf['defaults']
                        : (is_array($body['defaults'] ?? null) ? $body['defaults'] : []),
        ];
        /* Die call_id des MODELLAUFRUFS (nicht die der Sitzung): sie ist der
           Schluessel gegen eine doppelt zugestellte Anfrage. */
        $ctx['fnId'] = (string)($body['fnId'] ?? '');
        $name = (string)($body['name'] ?? '');
        $args = $body['arguments'] ?? '{}';
        return $this->VoiceRunTool($name, is_string($args) ? $args : (string)json_encode($args), $ctx);
    }

    /** @return array<string,mixed> */
    private function VoiceClose(array $body): array
    {
        $callId = $this->VoiceCallId((string)($body['callId'] ?? ''));
        if ($callId !== '') {
            $this->VoiceEndCall($callId, 'vom Nutzer beendet');
        }
        return ['ok' => true];
    }

    // ------------------------------------------------------------------
    // Marke und Auflegen
    // ------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function VoiceMintSecret(int $seconds, string $userId = ''): array
    {
        $key = trim($this->ReadPropertyString('AiOpenAIKey'));
        if ($key === '') {
            return $this->VoiceErr('ai_not_configured', $this->Translate('No OpenAI API key configured.'));
        }
        $modell = trim($this->ReadPropertyString('VoiceModel'));
        if (!in_array($modell, ['gpt-realtime-mini', 'gpt-realtime', 'gpt-realtime-2.1', 'gpt-realtime-2.1-mini'], true)) {
            $modell = 'gpt-realtime-mini';
        }
        $body = [
            'expires_after' => ['anchor' => 'created_at', 'seconds' => max(10, min(600, $seconds))],
            'session' => [
                'type'  => 'realtime',
                'model' => $modell,
                'instructions' => $this->VoiceInstructions($userId),
                'output_modalities' => ['audio'],
                'audio' => [
                    'input' => [
                        'noise_reduction' => ['type' => 'near_field'],
                        'transcription'   => ['model' => 'gpt-4o-mini-transcribe', 'language' => 'de'],
                        'turn_detection'  => ['type' => 'semantic_vad', 'eagerness' => 'medium',
                                              'create_response' => true, 'interrupt_response' => true],
                    ],
                    'output' => ['voice' => trim($this->ReadPropertyString('VoiceVoice')) ?: 'marin'],
                ],
                'tools'       => $this->VoiceToolSpec(),
                'tool_choice' => 'auto',
                'max_output_tokens' => 1024,
                'truncation'  => ['type' => 'retention_ratio', 'retention_ratio' => 0.8],
            ],
        ];
        $resp = $this->AiHttpPost(
            'https://api.openai.com/v1/realtime/client_secrets',
            ['Authorization: Bearer ' . $key, 'Content-Type: application/json',
             'OpenAI-Safety-Identifier: symdo-' . substr(hash('sha256', self::MODULE_GUID . '|' . $userId), 0, 24)],
            (string)json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            20
        );
        $daten = json_decode((string)($resp['body'] ?? ''), true);
        if ((int)($resp['status'] ?? 0) !== 200 || !is_string($daten['value'] ?? null)) {
            $this->SendDebug('Voice', 'client_secrets HTTP ' . (string)($resp['status'] ?? '?') . ': '
                . mb_substr((string)($resp['body'] ?? $resp['err'] ?? ''), 0, 300), 0);
            return $this->VoiceErr('voice_mint_failed',
                $this->Translate('Could not create a voice session.') . ' (HTTP ' . (string)($resp['status'] ?? 0) . ')');
        }
        return [
            'ok'        => true,
            'value'     => (string)$daten['value'],
            'expiresAt' => (int)($daten['expires_at'] ?? 0),
            'model'     => (string)($daten['session']['model'] ?? $modell),
        ];
    }

    /** Auflegen beim Anbieter — best effort, ein toter Anruf antwortet mit Fehler und ist trotzdem tot. */
    private function VoiceHangup(string $callId): void
    {
        $key = trim($this->ReadPropertyString('AiOpenAIKey'));
        if ($key === '' || $callId === '') {
            return;
        }
        try {
            $this->AiHttpPost(
                'https://api.openai.com/v1/realtime/calls/' . rawurlencode($callId) . '/hangup',
                ['Authorization: Bearer ' . $key, 'Content-Type: application/json'],
                '{}',
                10
            );
        } catch (\Throwable $e) {
            // best effort
        }
    }

    /** Buchen, auflegen, austragen — die eine Stelle fürs Sitzungsende. */
    private function VoiceEndCall(string $callId, string $grund): void
    {
        $offen = $this->VoiceCalls();
        if (isset($offen[$callId])) {
            $this->VoiceZaehlen(max(0, time() - (int)$offen[$callId]['accrued']), 0);
            unset($offen[$callId]);
            $this->VoiceCallsSchreiben($offen);
        }
        $this->VoiceHangup($callId);
        if ($offen === []) {
            @$this->SetTimerInterval('VoiceWatchdog', 0);
        }
        $this->SendDebug('Voice', 'Sitzung beendet (' . $grund . '): ' . $callId, 0);
    }

    private function VoiceHangupAll(string $grund): int
    {
        $offen = $this->VoiceCalls();
        foreach (array_keys($offen) as $callId) {
            $this->VoiceEndCall((string)$callId, $grund);
        }
        return count($offen);
    }

    /** Timer-Ziel: erst buchen, dann auflegen — sonst verschenkt man die Sekunden des sterbenden Anrufs. */
    private function VoiceWatchdogTick(): void
    {
        $offen = $this->VoiceCalls();
        if ($offen === []) {
            @$this->SetTimerInterval('VoiceWatchdog', 0);
            return;
        }
        $jetzt  = time();
        $deckel = min($this->ReadPropertyInteger('VoiceMaxSessionSeconds'), 3540);
        foreach ($offen as $callId => $c) {
            $this->VoiceZaehlen(max(0, $jetzt - (int)$c['accrued']), 0);
            $offen[$callId]['accrued'] = $jetzt;
        }
        $this->VoiceCallsSchreiben($offen);
        foreach ($offen as $callId => $c) {
            $zuAlt   = ($jetzt - (int)($c['lastPing'] ?? 0)) > self::$VOICE_PING_MAX;
            $zuLang  = ($jetzt - (int)($c['startedAt'] ?? $jetzt)) >= $deckel;
            $leer    = $this->VoiceBudgetLeft() <= 0;
            if ($zuAlt || $zuLang || $leer) {
                $this->VoiceEndCall((string)$callId,
                    $zuAlt ? 'Herzschlag ausgeblieben' : ($zuLang ? 'Sitzungsdeckel' : 'Tagesbudget'));
            }
        }
    }

    // ------------------------------------------------------------------
    // Zähler und Kleinkram
    // ------------------------------------------------------------------

    private function VoiceEnabledProp(): bool
    {
        // Über die Konfiguration, nicht ReadPropertyBoolean: die Eigenschaft
        // entsteht erst beim Kernel-Neustart, und bis dahin warnt der Zugriff.
        $cfg = json_decode((string)@IPS_GetConfiguration($this->InstanceID), true);
        return is_array($cfg) && ($cfg['VoiceEnabled'] ?? false) === true;
    }

    /** Restsekunden des Tages (Tagesdeckel in Minuten; 0 = unbegrenzt). */
    private function VoiceBudgetLeft(): int
    {
        $minuten = (int)$this->ReadPropertyInteger('VoiceDailyMinutes');
        if ($minuten <= 0) {
            return PHP_INT_MAX;
        }
        $stand = json_decode((string)@$this->ReadAttributeString('VoiceDayCount'), true);
        $heute = (is_array($stand) && ($stand['d'] ?? '') === date('Y-m-d')) ? (int)($stand['secs'] ?? 0) : 0;
        return $minuten * 60 - $heute;
    }

    /** Sekunden/Sitzungen auf den Tag buchen — unter eigenem Wächter, wie MailCountDay. */
    private function VoiceZaehlen(int $sekunden, int $sitzungen): void
    {
        if ($sekunden <= 0 && $sitzungen <= 0) {
            return;
        }
        $lock = 'SymDo_VoiceDay_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 1000)) {
            return; // Nicht zählen ist besser als falsch zählen.
        }
        try {
            $stand = json_decode((string)@$this->ReadAttributeString('VoiceDayCount'), true);
            if (!is_array($stand) || ($stand['d'] ?? '') !== date('Y-m-d')) {
                $stand = ['d' => date('Y-m-d'), 'secs' => 0, 'sessions' => 0];
            }
            $stand['secs']     = (int)($stand['secs'] ?? 0) + $sekunden;
            $stand['sessions'] = (int)($stand['sessions'] ?? 0) + $sitzungen;
            $this->WriteAttributeString('VoiceDayCount', (string)json_encode($stand));
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    // ------------------------------------------------------------------
    // Löschen: Bestätigungsmarke (servererzeugt, einmal verwendbar) + Deckel
    // ------------------------------------------------------------------

    /**
     * Prägt eine Bestätigungsmarke für ein aufgelöstes Löschziel und legt sie im
     * Briefkasten ab (überlebt die RequestAction-Objektgrenze — der zweite Aufruf
     * läuft auf einem anderen PHP-Objekt). Das Modell kann die Marke nicht erraten,
     * also hat der Nutzer die Rückfrage zwingend gehört. Gibt die Marken-ID zurück.
     *
     * @param array<string,mixed> $ziel
     */
    private function VoiceMarkeErzeugen(array $ziel, int $ttl): string
    {
        $lock = 'SymDo_VoiceMarks_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 1000)) {
            return '';
        }
        try {
            $marken = json_decode((string)@$this->ReadAttributeString('VoiceMarks'), true);
            $marken = is_array($marken) ? $this->VoiceMarkenAufraeumen($marken) : [];
            $id = bin2hex(random_bytes(4));
            $marken[$id] = ['ziel' => $ziel, 'exp' => time() + max(15, $ttl)];
            $this->WriteAttributeString('VoiceMarks', (string)json_encode($marken, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return $id;
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    /**
     * Löst eine Marke ein: prüft Ablauf, entfernt sie (EINMAL verwendbar) und gibt
     * das gespeicherte Ziel zurück — oder null, wenn unbekannt/abgelaufen. Das Ziel
     * ist beim Prägen festgeschrieben; ein zweiter Hörfehler beim „was" kann es
     * nicht mehr umlenken.
     *
     * @return array<string,mixed>|null
     */
    private function VoiceMarkeEinloesen(string $id): ?array
    {
        $id = trim($id);
        if ($id === '') {
            return null;
        }
        $lock = 'SymDo_VoiceMarks_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 1000)) {
            return null;
        }
        try {
            $marken = json_decode((string)@$this->ReadAttributeString('VoiceMarks'), true);
            $marken = is_array($marken) ? $marken : [];
            $eintrag = $marken[$id] ?? null;
            if (isset($marken[$id])) {
                unset($marken[$id]);   // einmal verwendbar — vor der Ausführung entwerten
            }
            $marken = $this->VoiceMarkenAufraeumen($marken);
            $this->WriteAttributeString('VoiceMarks', (string)json_encode($marken, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if (!is_array($eintrag) || (int)($eintrag['exp'] ?? 0) < time()) {
                return null;
            }
            return is_array($eintrag['ziel'] ?? null) ? $eintrag['ziel'] : null;
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    /**
     * @param array<string,mixed> $marken
     * @return array<string,mixed>
     */
    private function VoiceMarkenAufraeumen(array $marken): array
    {
        $jetzt = time();
        foreach ($marken as $k => $v) {
            if (!is_array($v) || (int)($v['exp'] ?? 0) < $jetzt) {
                unset($marken[$k]);
            }
        }
        return $marken;
    }

    /** Ist der Löschdeckel dieser Stunde noch offen? */
    private function VoiceLoeschDeckelOffen(): bool
    {
        if (self::$VOICE_LOESCH_MAX <= 0) {
            return true;
        }
        $stand = json_decode((string)@$this->ReadAttributeString('VoiceDayCount'), true);
        $stunde = date('Y-m-d-H');
        $n = (is_array($stand) && ($stand['delh'] ?? '') === $stunde) ? (int)($stand['deln'] ?? 0) : 0;
        return $n < self::$VOICE_LOESCH_MAX;
    }

    /** Eine erfolgreiche Löschung auf die laufende Stunde buchen (nach Erfolg, wie MailCountDay). */
    private function VoiceLoeschZaehlen(): void
    {
        $lock = 'SymDo_VoiceDay_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 1000)) {
            return;
        }
        try {
            $stand = json_decode((string)@$this->ReadAttributeString('VoiceDayCount'), true);
            $stand = is_array($stand) ? $stand : [];
            $stunde = date('Y-m-d-H');
            if (($stand['delh'] ?? '') !== $stunde) {
                $stand['delh'] = $stunde;
                $stand['deln'] = 0;
            }
            $stand['deln'] = (int)($stand['deln'] ?? 0) + 1;
            $this->WriteAttributeString('VoiceDayCount', (string)json_encode($stand));
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    /** @return array<string,array<string,mixed>> */
    private function VoiceCalls(): array
    {
        $offen = json_decode((string)@$this->ReadAttributeString('VoiceOpenCalls'), true);
        return is_array($offen) ? $offen : [];
    }

    private function VoiceCallsSchreiben(array $offen): void
    {
        @$this->WriteAttributeString('VoiceOpenCalls', (string)json_encode($offen, JSON_UNESCAPED_SLASHES));
    }

    /** call_ids kommen von außen: Form eng prüfen, sonst leer. */
    private function VoiceCallId(string $roh): string
    {
        return preg_match('/^[A-Za-z0-9_\-]{6,80}$/', $roh) === 1 ? $roh : '';
    }

    /** @return array<string,mixed> */
    private function VoiceErr(string $code, string $message): array
    {
        return ['ok' => false, 'error' => ['code' => $code, 'message' => $message], 'sag' => $message];
    }

    // ------------------------------------------------------------------
    // Formular-Panel
    // ------------------------------------------------------------------

    /** @return array<string,mixed> */
    /**
     * Der Geräteblock des Sprach-Panels. Eigener Neustart-Guard: die Properties
     * sind jünger als die des Sprachdialogs und fehlen bis zum nächsten
     * Kernelstart — ohne Guard zeigte das Formular Felder, die ins Leere
     * schreiben.
     *
     * @param array<string,mixed> $cfg
     * @return list<array<string,mixed>>
     */
    private function VoiceGeraetePanel(array $cfg, bool $zustimmung): array
    {
        if (!array_key_exists('VoiceDevicesEnabled', $cfg)) {
            return [['type' => 'Label',
                     'caption' => $this->Translate('Device control by voice appears after the next Symcon restart — these are new settings.')]];
        }
        $geraeteOk  = (bool)@$this->ReadAttributeBoolean('VoiceDevicesAccepted');
        $stand      = $this->VoiceGeraeteKatalogStand();
        $selectObj  = (float)IPS_GetKernelVersion() > 8.1;   // SelectObject erst ab 8.1 (RoomTile-Erfahrung)
        $items = [
            ['type' => 'Label', 'caption' => '—'],
            ['type' => 'CheckBox', 'name' => 'VoiceDevicesEnabled',
             'caption' => $this->Translate('Allow device control by voice')],
            ['type' => 'List', 'name' => 'VoiceDeviceRoots', 'rowCount' => 4, 'add' => true, 'delete' => true,
             'caption' => $this->Translate('Released areas (root categories) — everything below that is visible and has an action'),
             'columns' => [[
                 'caption' => $this->Translate('Category'), 'name' => 'objectID', 'width' => 'auto', 'add' => 0,
                 'edit' => ['type' => $selectObj ? 'SelectObject' : 'SelectCategory'],
             ]]],
            ['type' => 'List', 'name' => 'VoiceDeviceConfirm', 'rowCount' => 4, 'add' => true, 'delete' => true,
             'caption' => $this->Translate('Only after a spoken confirmation (children: never) — locks, alarm, garage'),
             'columns' => [[
                 'caption' => $this->Translate('Object'), 'name' => 'objectID', 'width' => 'auto', 'add' => 0,
                 'edit' => ['type' => $selectObj ? 'SelectObject' : 'SelectVariable'],
             ]]],
            ['type' => 'Label', 'name' => 'VoiceDevicesStatus', 'caption' => $geraeteOk
                ? sprintf($this->Translate('Device control consent given (%s).'),
                    (string)@$this->ReadAttributeString('VoiceDevicesAcceptedAt'))
                : $this->Translate('Own consent required: EVERYONE who talks to the tile — guests and children too — can then switch and read the released devices. Devices on the confirmation list are only switched after a spoken yes, never by children. Every switching command is logged in the Symcon message window.')],
            ['type' => 'RowLayout', 'items' => [
                ['type' => 'Button', 'caption' => $this->Translate('I consent to device control'),
                 'enabled' => $zustimmung && !$geraeteOk,
                 'onClick' => 'IPS_RequestAction($id, "VoiceDevicesConsent", true);'],
                ['type' => 'Button', 'caption' => $this->Translate('Revoke device control'), 'enabled' => $geraeteOk,
                 'onClick' => 'IPS_RequestAction($id, "VoiceDevicesConsent", false);'],
                ['type' => 'Button', 'caption' => $this->Translate('Show catalog'),
                 'onClick' => 'echo IPS_RequestAction($id, "VoiceGeraeteKatalogZeigen", "");'],
                ['type' => 'Button', 'caption' => $this->Translate('Show schedules'),
                 'onClick' => 'echo IPS_RequestAction($id, "VoiceZeitplaeneZeigen", "");'],
            ]],
            ['type' => 'Label', 'caption' => sprintf(
                $this->Translate('%1$d devices and %2$d scenes/scripts in %3$d rooms released. This hour: %4$d of %5$d switching commands.'),
                (int)$stand['geraete'], (int)$stand['skripte'], count($stand['raeume']),
                $this->VoiceGeraeteStand(), self::$VOICE_GERAETE_MAX)],
            ['type' => 'Label', 'caption' => sprintf(
                $this->Translate('%d voice schedules set up — hidden events at the devices themselves; they can be deleted there.'),
                count($this->VoiceZeitplaene()))],
        ];
        if ($stand['rueckfrageAusserhalb'] !== []) {
            $items[] = ['type' => 'Label', 'caption' => sprintf(
                $this->Translate('%d object(s) of the confirmation list lie outside the released areas and cannot be controlled.'),
                count($stand['rueckfrageAusserhalb']))];
        }
        if ($stand['gedeckelt']) {
            $items[] = ['type' => 'Label', 'caption' => $this->Translate('The device catalog is capped at 500 entries — choose narrower root categories.')];
        }
        return $items;
    }

    private function GetVoicePanel(): array
    {
        $cfg = json_decode((string)@IPS_GetConfiguration($this->InstanceID), true);
        if (!is_array($cfg) || !array_key_exists('VoiceEnabled', $cfg)) {
            return [
                'type' => 'ExpansionPanel', 'caption' => $this->Translate('Voice dialog'), 'expanded' => false,
                'items' => [[
                    'type'    => 'Label',
                    'caption' => $this->Translate('The voice dialog settings appear after the next Symcon restart — they are new settings, and those only exist once the kernel has loaded the module again.')
                ]],
            ];
        }
        $zustimmung = (bool)@$this->ReadAttributeBoolean('VoicePrivacyAccepted');
        $freihand = (bool)@$this->ReadAttributeBoolean('VoiceHandsFreeAccepted');
        $stand = json_decode((string)@$this->ReadAttributeString('VoiceDayCount'), true);
        $heute = (is_array($stand) && ($stand['d'] ?? '') === date('Y-m-d')) ? $stand : ['secs' => 0, 'sessions' => 0];
        $offen = $this->VoiceCalls();
        return [
            'type' => 'ExpansionPanel', 'caption' => $this->Translate('Voice dialog'), 'expanded' => false,
            'items' => [
                ['type' => 'CheckBox', 'name' => 'VoiceEnabled',
                 'caption' => $this->Translate('Enable voice dialog (SymDo Voice tile)')],
                ['type' => 'Select', 'name' => 'VoiceModel', 'caption' => $this->Translate('Model'),
                 'options' => [
                     ['caption' => 'gpt-realtime-mini (Standard)', 'value' => 'gpt-realtime-mini'],
                     ['caption' => 'gpt-realtime-2.1-mini',        'value' => 'gpt-realtime-2.1-mini'],
                     ['caption' => 'gpt-realtime',                 'value' => 'gpt-realtime'],
                     ['caption' => 'gpt-realtime-2.1',             'value' => 'gpt-realtime-2.1'],
                 ]],
                ['type' => 'Select', 'name' => 'VoiceVoice', 'caption' => $this->Translate('Voice'),
                 'options' => array_map(
                     static fn(string $v): array => ['caption' => $v, 'value' => $v],
                     ['marin', 'cedar', 'alloy', 'ash', 'ballad', 'coral', 'echo', 'sage', 'shimmer', 'verse']
                 )],
                ['type' => 'NumberSpinner', 'name' => 'VoiceDailyMinutes', 'minimum' => 0, 'maximum' => 480,
                 'caption' => $this->Translate('Talk time per day (minutes, 0 = unlimited)'), 'suffix' => ' min'],
                ['type' => 'NumberSpinner', 'name' => 'VoiceMaxSessionSeconds', 'minimum' => 30, 'maximum' => 3540,
                 'caption' => $this->Translate('Maximum length per conversation'), 'suffix' => ' s'],
                ['type' => 'Label', 'caption' => sprintf(
                    $this->Translate('Today: %d min talked in %d conversation(s), %d open right now.'),
                    (int)floor(((int)($heute['secs'] ?? 0)) / 60), (int)($heute['sessions'] ?? 0), count($offen))],
                ['type' => 'Label', 'name' => 'VoicePrivacyStatus', 'caption' => $zustimmung
                    ? $this->Translate('Voice privacy consent given.')
                    : $this->Translate('Consent required: during a conversation the room audio streams directly from the device to the AI provider (WebRTC), including voices of anyone present. Tool answers can carry excerpts of lists, appointments and plans. No audio is stored on this server.')],
                ['type' => 'Select', 'name' => 'VoiceDocModel',
                 'caption' => $this->Translate('Reader for manual questions'),
                 'options' => [
                     ['caption' => 'gpt-4.1 (Standard, ~1,1 s)', 'value' => 'gpt-4.1'],
                     ['caption' => 'gpt-5 (~1,5 s, genauer)',    'value' => 'gpt-5'],
                     ['caption' => 'gpt-4.1-mini (~2 s)',        'value' => 'gpt-4.1-mini'],
                     ['caption' => 'gpt-5-mini (~1,7 s)',        'value' => 'gpt-5-mini'],
                     ['caption' => $this->Translate('— none: read out excerpt —'), 'value' => ''],
                 ]],
                ['type' => 'CheckBox', 'name' => 'VoiceHandsFreeAllowed',
                 'caption' => $this->Translate('Allow hands-free with the wake word "Hey SymDo" (experiment)')],
                ['type' => 'Label', 'name' => 'VoiceHandsFreeStatus', 'caption' => $freihand
                    ? $this->Translate('Hands-free consent given. The wake word is recognised ON the device; no audio leaves it until the conversation starts.')
                    : $this->Translate('Hands-free needs its own consent: the microphone then stays open and listens for the wake word. Recognition runs on the device (Chrome or Edge 139 and newer, German speech pack required) — nothing is sent anywhere until the wake word is heard. On devices without local recognition the tile says so instead of listening; there is no fallback to cloud recognition.')],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Button', 'caption' => $this->Translate('I consent to hands-free'),
                     'enabled' => $zustimmung && !$freihand,
                     'onClick' => 'IPS_RequestAction($id, "VoiceHandsFreeConsent", true);'],
                    ['type' => 'Button', 'caption' => $this->Translate('Revoke hands-free'), 'enabled' => $freihand,
                     'onClick' => 'IPS_RequestAction($id, "VoiceHandsFreeConsent", false);'],
                ]],
                ...$this->VoiceGeraetePanel($cfg, $zustimmung),
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Button', 'caption' => $this->Translate('I consent'), 'enabled' => !$zustimmung,
                     'onClick' => 'IPS_RequestAction($id, "VoicePrivacyConsent", true);'],
                    ['type' => 'Button', 'caption' => $this->Translate('Revoke'), 'enabled' => $zustimmung,
                     'onClick' => 'IPS_RequestAction($id, "VoicePrivacyConsent", false);'],
                    ['type' => 'Button', 'caption' => $this->Translate('Test connection'),
                     'onClick' => 'IPS_RequestAction($id, "VoiceTest", "");'],
                    ['type' => 'Button', 'caption' => $this->Translate('End all sessions'),
                     'onClick' => 'IPS_RequestAction($id, "VoiceHangupAll", "");'],
                ]],
            ],
        ];
    }
}

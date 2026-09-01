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

    private function VoiceCreate(): void
    {
        $this->RegisterPropertyBoolean('VoiceEnabled', false);
        $this->RegisterPropertyString('VoiceModel', 'gpt-realtime-mini');
        $this->RegisterPropertyString('VoiceVoice', 'marin');
        $this->RegisterPropertyInteger('VoiceDailyMinutes', 15);
        $this->RegisterPropertyInteger('VoiceMaxSessionSeconds', 180);
        // Reserve für den unified-Handschlag (Etappe 0 hat ihn nicht gebraucht).
        $this->RegisterPropertyString('VoiceHandshake', 'ephemeral');

        $this->RegisterAttributeBoolean('VoicePrivacyAccepted', false);
        $this->RegisterAttributeString('VoicePrivacyAcceptedAt', '');
        $this->RegisterAttributeString('VoiceOpenCalls', '{}');
        $this->RegisterAttributeString('VoiceDayCount', '{}');
        $this->RegisterAttributeString('VoiceLog', '[]');
        // Reserven der späteren Etappen (Auflöser-Merkzettel, Löschmarken).
        $this->RegisterAttributeString('VoiceRefs', '{}');
        $this->RegisterAttributeString('VoiceMarks', '{}');

        $this->RegisterTimer('VoiceWatchdog', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'VoiceTick\', 0);');
    }

    private function VoiceApplyChanges(): void
    {
        // Nach einem Kernel-Start kann kein vermerkter Anruf überlebt haben —
        // auflegen ist harmlos, offen stehen lassen kostet Geld.
        try {
            $offen = json_decode($this->ReadAttributeString('VoiceOpenCalls'), true);
            foreach (is_array($offen) ? array_keys($offen) : [] as $callId) {
                $this->VoiceHangup((string)$callId);
            }
            if (is_array($offen) && $offen !== []) {
                $this->WriteAttributeString('VoiceOpenCalls', '{}');
                $this->LogMessage('SymDo Sprache: ' . count($offen) . ' Altsitzung(en) nach Neustart aufgelegt', KL_NOTIFY);
            }
            @$this->SetTimerInterval('VoiceWatchdog', 0);
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
            }
            $this->ReloadForm();
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
            case 'close':
                return $this->VoiceClose($body);
            case 'log':
                $log = json_decode($this->ReadAttributeString('VoiceLog'), true);
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

    /** @return array<string,mixed> */
    private function VoiceOpen(array $body): array
    {
        // Riegel in fester Reihenfolge — alle VOR dem Prägen der Marke.
        if (!$this->VoiceEnabledProp()) {
            return $this->VoiceErr('voice_disabled', $this->Translate('The voice dialog is disabled.'));
        }
        if (!$this->AiPrivacyAccepted() || !(bool)@$this->ReadAttributeBoolean('VoicePrivacyAccepted')) {
            return $this->VoiceErr('voice_privacy_required', $this->Translate('The privacy consent for the voice dialog is missing — open the gateway settings.'));
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
        $r['pingSeconds']    = 15;
        return $r;
    }

    /** @return array<string,mixed> */
    private function VoiceOpened(array $body): array
    {
        $callId = $this->VoiceCallId((string)($body['callId'] ?? ''));
        if ($callId === '') {
            return $this->VoiceErr('invalid_payload', $this->Translate('Invalid call'));
        }
        $offen = $this->VoiceCalls();
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
        $stand = json_decode($this->ReadAttributeString('VoiceDayCount'), true);
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
            $stand = json_decode($this->ReadAttributeString('VoiceDayCount'), true);
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

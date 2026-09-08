<?php

declare(strict_types=1);

/**
 * Sprachdialog — Zeitpläne für Geräte („schalte in 55 Minuten das Wasser aus",
 * „jeden Tag um 11 Uhr die Lampe an").
 *
 * Jeder Zeitplan ist ein zyklisches Symcon-EREIGNIS unter der Gateway-Instanz:
 *   - er überlebt Kernel-Neustarts, ohne dass hier ein Timer nachgezogen wird,
 *   - er steht sichtbar in der Konsole und lässt sich dort löschen oder pausieren,
 *   - er trägt seine Nutzlast (Ziel, Wert, Person, Bestätigung) als JSON im
 *     Info-Feld — kein Attribut, das aus dem Tritt geraten kann, und keine
 *     Registrierung, die einen Kernel-Neustart bräuchte.
 * Das Ereignisskript ruft per IPS_RequestAction(<Gateway>, 'VoiceZeitplan',
 * <EreignisID>) zurück; die Instanznummer steht fest im Skript, weil
 * $_IPS['TARGET'] bei Ereignissen nicht verlässlich gesetzt ist.
 *
 * Beim FEUERN gilt dieselbe Prüfung wie beim gesprochenen Befehl: Riegel
 * (Schalter, Einwilligung), Umfang, Kinder und Rückfrage-Liste, Stundendeckel,
 * Gegenlesen, Protokoll. Ein Zeitplan kann nie mehr als ein sofortiger Aufruf.
 *
 * Kinder dürfen EINMALIGE Aufträge für freigegebene Geräte einplanen (das
 * Nachtlicht in zehn Minuten aus), aber keine dauerhaften: ein täglicher Plan
 * ist eine bleibende Änderung am Haus, und die legen Erwachsene an.
 */
trait VoiceZeitplan
{
    /** Höchstzahl offener Zeitpläne je Gateway. */
    private static int $VOICE_ZEITPLAN_MAX = 20;
    /** Einmalige Aufträge: höchstens so weit voraus (Sekunden). */
    private static int $VOICE_ZEITPLAN_HORIZONT = 366 * 86400;
    /** Relativ („in n Minuten"): höchstens 7 Tage. */
    private static int $VOICE_ZEITPLAN_MINUTEN_MAX = 7 * 1440;

    /** Bitmaske der Wochentage wie in IPS_SetEventCyclic. */
    private static array $VOICE_ZEITPLAN_TAGE = [
        'mo' => 1, 'montag' => 1, 'montags' => 1,
        'di' => 2, 'dienstag' => 2, 'dienstags' => 2,
        'mi' => 4, 'mittwoch' => 4, 'mittwochs' => 4,
        'do' => 8, 'donnerstag' => 8, 'donnerstags' => 8,
        'fr' => 16, 'freitag' => 16, 'freitags' => 16,
        'sa' => 32, 'samstag' => 32, 'samstags' => 32, 'sonnabend' => 32,
        'so' => 64, 'sonntag' => 64, 'sonntags' => 64,
    ];

    /** Glied der RequestAction-Kette des Gateways. */
    private function VoiceZeitplanRequestAction(string $Ident, mixed $Value): bool
    {
        if ($Ident === 'VoiceZeitplan') {
            $this->VoiceZeitplanFeuern((int)$Value);
            return true;
        }
        if ($Ident === 'VoiceZeitplaeneZeigen') {
            $zeilen = array_map(fn(array $z): string => $this->VoiceZeitplanZeile($z), $this->VoiceZeitplaene());
            echo $zeilen === [] ? $this->Translate('No voice schedules.') : implode("\n", $zeilen);
            return true;
        }
        return false;
    }

    // ------------------------------------------------------------------
    // Bestand
    // ------------------------------------------------------------------

    /**
     * Alle Zeitpläne des Sprachdialogs: die Ereignisse unter der Instanz, deren
     * Info-Feld unsere Nutzlast trägt. Fremde Ereignisse bleiben unangetastet.
     *
     * @return list<array{id:int,auftrag:array<string,mixed>,naechstes:int,aktiv:bool}>
     */
    private function VoiceZeitplaene(): array
    {
        $liste = [];
        foreach ((array)@IPS_GetChildrenIDs($this->InstanceID) as $kind) {
            $o = @IPS_GetObject((int)$kind);
            if (!is_array($o) || (int)($o['ObjectType'] ?? -1) !== 4) {
                continue;
            }
            $auftrag = json_decode((string)($o['ObjectInfo'] ?? ''), true);
            if (!is_array($auftrag) || ($auftrag['symdo'] ?? '') !== 'zeitplan' || !is_array($auftrag['ziel'] ?? null)) {
                continue;
            }
            $ev = @IPS_GetEvent((int)$kind);
            $liste[] = ['id' => (int)$kind, 'auftrag' => $auftrag,
                        'naechstes' => (int)($ev['NextRun'] ?? 0), 'aktiv' => (bool)($ev['EventActive'] ?? false)];
        }
        usort($liste, static fn(array $a, array $b): int => $a['naechstes'] <=> $b['naechstes']);
        return $liste;
    }

    /** Eine Zeile für Konsole und Werkzeugantwort: „Prüfraum Prüfschalter → An, täglich um 11:00 (nächstes Mal morgen um 11:00)". */
    private function VoiceZeitplanZeile(array $z): string
    {
        $a = $z['auftrag'];
        $ziel = $a['ziel'];
        $was = ($ziel['typ'] ?? '') === 'var'
            ? sprintf('%s → %s', (string)$ziel['titel'], (string)($ziel['text'] ?? ''))
            : sprintf($this->Translate('start %s'), (string)$ziel['titel']);
        $zeile = $was . ', ' . $this->VoiceZeitplanWann($a);
        if (($a['art'] ?? '') !== 'einmal' && (int)$z['naechstes'] > 0) {
            $zeile .= ' (' . sprintf($this->Translate('next time %s'), $this->VoiceZeitplanZeitpunkt((int)$z['naechstes'])) . ')';
        }
        if (!$z['aktiv']) {
            $zeile .= ' — ' . $this->Translate('paused');
        }
        if (($a['wer'] ?? '') !== '') {
            $zeile .= ' — ' . sprintf($this->Translate('by %s'), (string)$a['wer']);
        }
        return $zeile;
    }

    /** „täglich um 11:00", „werktags um 6:30", „montags und mittwochs um 7:00", „in 55 Minuten", „morgen um 7:00". */
    private function VoiceZeitplanWann(array $a): string
    {
        $uhr = $this->VoiceUhr((string)($a['uhrzeit'] ?? ''));
        switch ((string)($a['art'] ?? '')) {
            case 'taeglich':
                return sprintf($this->Translate('daily at %s'), $uhr);
            case 'woechentlich':
                $bits = (int)($a['tage'] ?? 0);
                if ($bits === 31) {
                    return sprintf($this->Translate('on weekdays at %s'), $uhr);
                }
                if ($bits === 96) {
                    return sprintf($this->Translate('at the weekend at %s'), $uhr);
                }
                return sprintf($this->Translate('%1$s at %2$s'), $this->VoiceZeitplanTageText($bits), $uhr);
            default:
                return $this->VoiceZeitplanZeitpunkt((int)($a['faellig'] ?? 0));
        }
    }

    /** Ein Zeitpunkt in Worten, relativ zu jetzt: „in 55 Minuten", „heute um 22:00", „morgen um 7:00", „am 12.09. um 7:00". */
    private function VoiceZeitplanZeitpunkt(int $ts): string
    {
        $jetzt = time();
        $diff = $ts - $jetzt;
        if ($diff > 0 && $diff < 3600) {
            $min = max(1, (int)floor($diff / 60 + 0.5));
            return $min === 1 ? $this->Translate('in one minute') : sprintf($this->Translate('in %d minutes'), $min);
        }
        $uhr = $this->VoiceUhr(date('H:i', $ts));
        $tag = date('Y-m-d', $ts);
        if ($tag === date('Y-m-d', $jetzt)) {
            return sprintf($this->Translate('today at %s'), $uhr);
        }
        if ($tag === date('Y-m-d', $jetzt + 86400)) {
            return sprintf($this->Translate('tomorrow at %s'), $uhr);
        }
        return sprintf($this->Translate('on %1$s at %2$s'), date('d.m.', $ts), $uhr);
    }

    /** Bitmaske → „montags, mittwochs und freitags". */
    private function VoiceZeitplanTageText(int $bits): string
    {
        $worte = [1 => 'Mondays', 2 => 'Tuesdays', 4 => 'Wednesdays', 8 => 'Thursdays',
                  16 => 'Fridays', 32 => 'Saturdays', 64 => 'Sundays'];
        $teile = [];
        foreach ($worte as $bit => $wort) {
            if (($bits & $bit) !== 0) {
                $teile[] = $this->Translate($wort);
            }
        }
        if (count($teile) <= 1) {
            return (string)($teile[0] ?? '');
        }
        $letzter = array_pop($teile);
        return implode(', ', $teile) . ' ' . $this->Translate('and') . ' ' . $letzter;
    }

    // ------------------------------------------------------------------
    // Zeitangabe deuten
    // ------------------------------------------------------------------

    /**
     * Aus den Werkzeugfeldern die Zeitform: einmal (faellig), taeglich oder
     * woechentlich (tage, uhrzeit). Der Server rechnet — das Modell reicht nur
     * durch, was der Nutzer gesagt hat.
     *
     * @return array<string,mixed>  ok:true + art/tage/uhrzeit/faellig | Fehler
     */
    private function VoiceZeitplanZeit(array $args): array
    {
        $nach  = $args['nach_minuten'] ?? null;
        $uhr   = trim((string)($args['uhrzeit'] ?? ''));
        $datum = trim((string)($args['datum'] ?? ''));
        $wdh   = strtolower(trim((string)($args['wiederholung'] ?? 'keine')));
        if ($wdh === '' || $wdh === 'null') {
            $wdh = 'keine';
        }

        // ---- relativ: „in 55 Minuten" ----
        if ($nach !== null && $nach !== '' && is_numeric($nach)) {
            $n = (int)round((float)$nach);
            if ($n < 1 || $n > self::$VOICE_ZEITPLAN_MINUTEN_MAX) {
                return $this->VoiceErr('ungueltige_eingabe', $this->Translate('I can plan between one minute and seven days ahead.'));
            }
            if ($wdh !== 'keine') {
                return $this->VoiceErr('ungueltige_eingabe', $this->Translate('A repeating schedule needs a time of day, not a duration.'));
            }
            $ts = time() + $n * 60;
            return ['ok' => true, 'art' => 'einmal', 'tage' => 0, 'uhrzeit' => date('H:i', $ts), 'faellig' => $ts];
        }

        // ---- Uhrzeit ----
        if ($uhr === '' || !preg_match('/^\s*(\d{1,2})(?:\s*[:.]\s*(\d{1,2}))?\s*(?:uhr)?\s*$/iu', $uhr, $m)) {
            return $this->VoiceErr('ungueltige_eingabe', $this->Translate('When should that be? Tell me a time of day or a duration.'));
        }
        $h = (int)$m[1];
        $mi = (int)($m[2] ?? 0);
        if ($h > 23 || $mi > 59) {
            return $this->VoiceErr('ungueltige_eingabe', $this->Translate('I did not understand that time of day.'));
        }
        $uhrzeit = sprintf('%02d:%02d', $h, $mi);

        // ---- einmalig zu einer Uhrzeit ----
        if ($wdh === 'keine') {
            if ($datum !== '') {
                $d = $this->VoiceZeitplanDatum($datum);
                if ($d === null) {
                    return $this->VoiceErr('ungueltige_eingabe', $this->Translate('I did not understand that date.'));
                }
                $ts = mktime($h, $mi, 0, $d[1], $d[2], $d[0]);
                if ($ts <= time()) {
                    return $this->VoiceErr('ungueltige_eingabe', $this->Translate('That moment is already in the past.'));
                }
            } else {
                // ohne Datum: heute, wenn die Zeit noch kommt — sonst morgen
                $ts = mktime($h, $mi, 0, (int)date('n'), (int)date('j'), (int)date('Y'));
                if ($ts <= time()) {
                    $ts += 86400;
                }
            }
            if ($ts > time() + self::$VOICE_ZEITPLAN_HORIZONT) {
                return $this->VoiceErr('ungueltige_eingabe', $this->Translate('That is more than a year away — I cannot plan that far.'));
            }
            return ['ok' => true, 'art' => 'einmal', 'tage' => 0, 'uhrzeit' => $uhrzeit, 'faellig' => (int)$ts];
        }

        // ---- dauerhaft ----
        $bits = match ($wdh) {
            'taeglich', 'täglich', 'jeden tag' => 127,
            'werktags', 'wochentags'            => 31,
            'wochenende', 'am wochenende'       => 96,
            'woechentlich', 'wöchentlich'       => $this->VoiceZeitplanTageBits($args['wochentage'] ?? []),
            default                             => -1,
        };
        if ($bits < 0) {
            return $this->VoiceErr('ungueltige_eingabe', $this->Translate('I did not understand how often that should repeat.'));
        }
        if ($bits === 0) {
            return $this->VoiceErr('ungueltige_eingabe', $this->Translate('On which days of the week?'));
        }
        return $bits === 127
            ? ['ok' => true, 'art' => 'taeglich', 'tage' => 127, 'uhrzeit' => $uhrzeit, 'faellig' => 0]
            : ['ok' => true, 'art' => 'woechentlich', 'tage' => $bits, 'uhrzeit' => $uhrzeit, 'faellig' => 0];
    }

    /** „YYYY-MM-DD" oder „DD.MM.[YYYY]" → [Jahr, Monat, Tag] oder null. */
    private function VoiceZeitplanDatum(string $datum): ?array
    {
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $datum, $m)) {
            [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        } elseif (preg_match('/^(\d{1,2})\.(\d{1,2})\.?(\d{4})?$/', $datum, $m)) {
            [$d, $mo] = [(int)$m[1], (int)$m[2]];
            $y = isset($m[3]) && $m[3] !== '' ? (int)$m[3] : (int)date('Y');
            // ohne Jahr: liegt der Tag schon hinter uns, ist das nächste Jahr gemeint
            if (checkdate($mo, $d, $y) && mktime(23, 59, 59, $mo, $d, $y) < time()) {
                $y++;
            }
        } else {
            return null;
        }
        return checkdate($mo, $d, $y) ? [$y, $mo, $d] : null;
    }

    /** @param mixed $tage  Liste gesprochener Wochentage („Mo", „Montag", „montags") */
    private function VoiceZeitplanTageBits(mixed $tage): int
    {
        $bits = 0;
        foreach (is_array($tage) ? $tage : [] as $t) {
            $k = mb_strtolower(trim((string)$t));
            $bits |= (int)(self::$VOICE_ZEITPLAN_TAGE[$k] ?? 0);
        }
        return $bits;
    }

    // ------------------------------------------------------------------
    // Werkzeuge
    // ------------------------------------------------------------------

    /**
     * zeitplan_anlegen — Ziel und Wert wie geraet_steuern, dazu die Zeitform.
     * Es wird JETZT nichts geschaltet; ungültige Werte scheitern beim Sprechen,
     * nicht in 55 Minuten. Rückfrage-Geräte gehen über dieselbe Marke wie beim
     * sofortigen Schalten (bereich=zeitplan), Kinder dürfen nur Einmaliges.
     *
     * @param array{userId:string,defaults:array} $ctx
     * @return array<string,mixed>
     */
    private function VoiceToolZeitplanAnlegen(array $args, array $ctx): array
    {
        $marke = is_string($args['marke'] ?? null) ? trim((string)$args['marke']) : '';

        // ---- Zweiter Aufruf: Marke einlösen ----
        if ($marke !== '') {
            $ziel = $this->VoiceMarkeEinloesen($marke);
            if ($ziel === null || ($ziel['bereich'] ?? '') !== 'zeitplan' || !is_array($ziel['auftrag'] ?? null)) {
                return $this->VoiceErr('marke_abgelaufen', $this->Translate('The confirmation has expired — please tell me again what to plan.'));
            }
            if ((string)($ziel['userId'] ?? '') !== (string)($ctx['userId'] ?? '') || $this->VoiceIstKind($ctx)) {
                return $this->VoiceErr('nicht_erlaubt', $this->Translate('I may not switch that for you — please ask an adult.'));
            }
            $auftrag = $ziel['auftrag'];
            $auftrag['bestaetigt'] = true;
            return $this->VoiceZeitplanAnlegen($auftrag);
        }

        // ---- Erster Aufruf ----
        if (count($this->VoiceZeitplaene()) >= self::$VOICE_ZEITPLAN_MAX) {
            return $this->VoiceErr('zeitplan_deckel', sprintf($this->Translate('There are already %d schedules — please delete one first.'), self::$VOICE_ZEITPLAN_MAX));
        }
        $zeit = $this->VoiceZeitplanZeit($args);
        if (($zeit['ok'] ?? false) !== true) {
            return $zeit;
        }
        $wert = trim((string)($args['wert'] ?? ''));
        if ($wert === '' || strtolower($wert) === 'null') {
            // Ohne Wert ist eine Szene oder ein Skript gemeint. Trifft der Name
            // aber ein Gerät, fehlt schlicht der Zielwert — das sagen wir so.
            $gebildet = $this->VoiceGeraetZielBilden(['name' => $args['geraet'] ?? '', 'raum' => $args['raum'] ?? null], 'szene');
            if (($gebildet['ok'] ?? false) !== true && ($gebildet['error']['code'] ?? '') === 'nicht_gefunden') {
                $katalog = array_values(array_filter($this->VoiceGeraeteKatalog(), static fn(array $e): bool => $e['typ'] === 'var' && $e['akt'] === true));
                $erg = $this->VoiceGeraeteAufloesen(trim((string)($args['geraet'] ?? '')), $katalog, trim((string)($args['raum'] ?? '')));
                if (($erg['status'] ?? '') === 'eindeutig') {
                    return $this->VoiceErr('ungueltige_eingabe', sprintf($this->Translate('What should I set %s to at that time?'), (string)$erg['treffer'][0]['titel']));
                }
            }
        } else {
            $gebildet = $this->VoiceGeraetZielBilden(['geraet' => $args['geraet'] ?? '', 'raum' => $args['raum'] ?? null, 'wert' => $wert], 'geraet');
        }
        if (($gebildet['ok'] ?? false) !== true) {
            return $gebildet;
        }
        $ziel = $gebildet['ziel'];
        $rueckfrage = $this->VoiceGeraetMitRueckfrage((int)$ziel['id'])
            || ((int)($ziel['inst'] ?? 0) > 0 && $this->VoiceGeraetMitRueckfrage((int)$ziel['inst']));
        if ($this->VoiceIstKind($ctx)) {
            if ($rueckfrage) {
                return $this->VoiceErr('nicht_erlaubt', $this->Translate('I may not switch that for you — please ask an adult.'));
            }
            if ($zeit['art'] !== 'einmal') {
                return $this->VoiceErr('nicht_erlaubt', $this->Translate('A repeating schedule has to be set up by an adult — I can do it once, though.'));
            }
        }
        $userId = (string)($ctx['userId'] ?? '');
        $auftrag = [
            'symdo' => 'zeitplan', 'v' => 1, 'ziel' => $ziel,
            'art' => (string)$zeit['art'], 'tage' => (int)$zeit['tage'], 'uhrzeit' => (string)$zeit['uhrzeit'],
            'faellig' => (int)$zeit['faellig'], 'userId' => $userId, 'wer' => $this->VoiceNutzerName($userId),
            'bestaetigt' => false, 'angelegt' => time(),
        ];
        if ($rueckfrage) {
            $id = $this->VoiceMarkeErzeugen(['bereich' => 'zeitplan', 'auftrag' => $auftrag, 'userId' => $userId], self::$VOICE_MARKE_TTL);
            if ($id === '') {
                return $this->VoiceErr('intern', $this->Translate('Something went wrong — nothing was changed.'));
            }
            $frage = $ziel['typ'] === 'var'
                ? sprintf($this->Translate('I will set %1$s to %2$s %3$s. Shall I really plan that?'), $ziel['titel'], $ziel['text'], $this->VoiceZeitplanWann($auftrag))
                : sprintf($this->Translate('I will start %1$s %2$s. Shall I really plan that?'), $ziel['titel'], $this->VoiceZeitplanWann($auftrag));
            return ['ok' => false, 'error' => ['code' => 'bestaetigung_noetig', 'message' => 'confirmation required'],
                    'marke' => $id, 'sag' => $frage];
        }
        return $this->VoiceZeitplanAnlegen($auftrag);
    }

    /**
     * Das Ereignis anlegen. Alles, was das Feuern braucht, steht im Info-Feld.
     * @param array<string,mixed> $auftrag
     * @return array<string,mixed>
     */
    private function VoiceZeitplanAnlegen(array $auftrag): array
    {
        $ziel = $auftrag['ziel'];
        $wann = $this->VoiceZeitplanWann($auftrag);
        $ev = 0;
        try {
            $ev = IPS_CreateEvent(1);   // zyklisch
            IPS_SetParent($ev, $this->InstanceID);
            IPS_SetName($ev, mb_substr(sprintf('SymDo %s: %s%s, %s', $this->Translate('voice schedule'), (string)$ziel['titel'],
                ($ziel['typ'] ?? '') === 'var' ? ' → ' . (string)($ziel['text'] ?? '') : '', $wann), 0, 120));
            IPS_SetInfo($ev, (string)json_encode($auftrag, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            IPS_SetEventScript($ev, sprintf("IPS_RequestAction(%d, 'VoiceZeitplan', \$_IPS['EVENT']);", $this->InstanceID));
            [$h, $mi] = array_map('intval', explode(':', (string)$auftrag['uhrzeit']));
            switch ((string)$auftrag['art']) {
                case 'taeglich':
                    IPS_SetEventCyclic($ev, 2, 1, 0, 0, 0, 0);
                    IPS_SetEventCyclicDateFrom($ev, (int)date('j'), (int)date('n'), (int)date('Y'));
                    IPS_SetEventCyclicTimeFrom($ev, $h, $mi, 0);
                    break;
                case 'woechentlich':
                    IPS_SetEventCyclic($ev, 3, 1, (int)$auftrag['tage'], 0, 0, 0);
                    IPS_SetEventCyclicDateFrom($ev, (int)date('j'), (int)date('n'), (int)date('Y'));
                    IPS_SetEventCyclicTimeFrom($ev, $h, $mi, 0);
                    break;
                default:
                    $ts = (int)$auftrag['faellig'];
                    IPS_SetEventCyclic($ev, 1, 0, 0, 0, 0, 0);   // Datum einmalig, Zeit einmalig
                    IPS_SetEventCyclicDateFrom($ev, (int)date('j', $ts), (int)date('n', $ts), (int)date('Y', $ts));
                    IPS_SetEventCyclicTimeFrom($ev, (int)date('G', $ts), (int)date('i', $ts), (int)date('s', $ts));
            }
            IPS_SetEventActive($ev, true);
        } catch (\Throwable $e) {
            $this->SendDebug('Voice', 'Zeitplan anlegen warf: ' . $e->getMessage(), 0);
            if ($ev > 0 && @IPS_EventExists($ev)) {
                @IPS_DeleteEvent($ev);
            }
            return $this->VoiceErr('intern', $this->Translate('I could not save the schedule.'));
        }
        $einmal = (string)$auftrag['art'] === 'einmal';
        $sag = ($ziel['typ'] ?? '') === 'var'
            ? sprintf($this->Translate($einmal ? 'I will set %1$s to %2$s %3$s.' : 'From now on I set %1$s to %2$s %3$s.'),
                (string)$ziel['titel'], (string)($ziel['text'] ?? ''), $wann)
            : sprintf($this->Translate($einmal ? 'I will start %1$s %2$s.' : 'From now on I start %1$s %2$s.'), (string)$ziel['titel'], $wann);
        $this->LogMessage(sprintf('SymDo Sprachdialog: Zeitplan #%d angelegt — %s%s', $ev, $sag,
            ($auftrag['wer'] ?? '') !== '' ? ' von ' . (string)$auftrag['wer'] : ''), KL_NOTIFY);
        return ['ok' => true, 'id' => $ev, 'geraet' => (string)$ziel['titel'], 'wert' => (string)($ziel['text'] ?? ''),
                'wann' => $wann, 'dauerhaft' => !$einmal, 'sag' => $sag];
    }

    /**
     * zeitplaene_lesen — alle, die eines Geräts oder eines Raums.
     * @return array<string,mixed>
     */
    private function VoiceToolZeitplaeneLesen(array $args, array $ctx): array
    {
        $alle = $this->VoiceZeitplaene();
        if ($alle === []) {
            return ['ok' => true, 'zeitplaene' => [], 'sag' => $this->Translate('Nothing is planned.')];
        }
        $gefiltert = $this->VoiceZeitplaeneFiltern($alle, $args);
        if (($gefiltert['ok'] ?? true) === false) {
            return $gefiltert;
        }
        $treffer = $gefiltert['liste'];
        if ($treffer === []) {
            return ['ok' => true, 'zeitplaene' => [], 'sag' => $this->Translate('Nothing is planned for that.')];
        }
        $zeilen = array_map(fn(array $z): array => ['id' => $z['id'], 'text' => $this->VoiceZeitplanZeile($z)], array_slice($treffer, 0, 15));
        $sag = count($treffer) <= 3
            ? implode(' ', array_map(static fn(array $z): string => $z['text'] . '.', $zeilen))
            : sprintf($this->Translate('%d schedules are set up. The next ones: %s.'), count($treffer),
                implode('; ', array_map(static fn(array $z): string => $z['text'], array_slice($zeilen, 0, 3))));
        return ['ok' => true, 'zeitplaene' => $zeilen, 'anzahl' => count($treffer), 'sag' => $sag];
    }

    /**
     * zeitplan_loeschen — eine bestimmte (id), die eines Geräts oder alle eines
     * Geräts. Bei mehreren Treffern ohne "alle" kommt die Liste als Rückfrage.
     * Kinder löschen nur, was sie selbst angelegt haben.
     *
     * @return array<string,mixed>
     */
    private function VoiceToolZeitplanLoeschen(array $args, array $ctx): array
    {
        $alle = $this->VoiceZeitplaene();
        if ($alle === []) {
            return $this->VoiceErr('nicht_gefunden', $this->Translate('Nothing is planned.'));
        }
        $id = (int)($args['id'] ?? 0);
        if ($id > 0) {
            $treffer = array_values(array_filter($alle, static fn(array $z): bool => $z['id'] === $id));
            if ($treffer === []) {
                return $this->VoiceErr('nicht_gefunden', $this->Translate('I cannot find that schedule any more.'));
            }
        } else {
            if (trim((string)($args['geraet'] ?? '')) === '' && trim((string)($args['raum'] ?? '')) === '') {
                return $this->VoiceErr('ungueltige_eingabe', $this->Translate('Which schedule shall I delete — for which device?'));
            }
            $gefiltert = $this->VoiceZeitplaeneFiltern($alle, $args);
            if (($gefiltert['ok'] ?? true) === false) {
                return $gefiltert;
            }
            $treffer = $gefiltert['liste'];
            if ($treffer === []) {
                return $this->VoiceErr('nicht_gefunden', $this->Translate('Nothing is planned for that.'));
            }
            if (count($treffer) > 1 && strtolower(trim((string)($args['umfang'] ?? 'einer'))) !== 'alle') {
                $zeilen = array_map(fn(array $z): array => ['id' => $z['id'], 'text' => $this->VoiceZeitplanZeile($z)], array_slice($treffer, 0, 8));
                return ['ok' => false, 'error' => ['code' => 'mehrdeutig', 'message' => 'nicht eindeutig'], 'zeitplaene' => $zeilen,
                        'sag' => sprintf($this->Translate('There are %1$d schedules for that: %2$s. Which one shall I delete — or all of them?'),
                            count($treffer), implode('; ', array_map(static fn(array $z): string => $z['text'], $zeilen)))];
            }
        }
        $kind = $this->VoiceIstKind($ctx);
        $userId = (string)($ctx['userId'] ?? '');
        $geloescht = [];
        foreach ($treffer as $z) {
            if ($kind && (string)($z['auftrag']['userId'] ?? '') !== $userId) {
                return $this->VoiceErr('nicht_erlaubt', $this->Translate('That schedule was set up by someone else — please ask an adult.'));
            }
            if (@IPS_DeleteEvent((int)$z['id'])) {
                $geloescht[] = $this->VoiceZeitplanZeile($z);
                $this->LogMessage(sprintf('SymDo Sprachdialog: Zeitplan #%d gelöscht — %s', (int)$z['id'], end($geloescht)), KL_NOTIFY);
            }
        }
        if ($geloescht === []) {
            return $this->VoiceErr('intern', $this->Translate('I could not delete the schedule.'));
        }
        return ['ok' => true, 'geloescht' => $geloescht, 'sag' => count($geloescht) === 1
            ? sprintf($this->Translate('Deleted: %s.'), $geloescht[0])
            : sprintf($this->Translate('%d schedules deleted.'), count($geloescht))];
    }

    /**
     * Zeitpläne nach Gerät (Auflöser über die betroffenen Gerätetitel), Raum und
     * Art („einmal"/„dauerhaft") eingrenzen.
     *
     * @param list<array<string,mixed>> $alle
     * @return array{ok?:bool,liste?:list<array<string,mixed>>}|array<string,mixed>
     */
    private function VoiceZeitplaeneFiltern(array $alle, array $args): array
    {
        $liste = $alle;
        $nur = strtolower(trim((string)($args['nur'] ?? 'alle')));
        if ($nur === 'einmal' || $nur === 'einmalig') {
            $liste = array_values(array_filter($liste, static fn(array $z): bool => ($z['auftrag']['art'] ?? '') === 'einmal'));
        } elseif ($nur === 'dauerhaft') {
            $liste = array_values(array_filter($liste, static fn(array $z): bool => ($z['auftrag']['art'] ?? '') !== 'einmal'));
        }
        $raum = trim((string)($args['raum'] ?? ''));
        $such = trim((string)($args['geraet'] ?? ''));
        if ($raum !== '' && $such === '') {
            $liste = array_values(array_filter($liste, fn(array $z): bool => $this->VoiceRaumPasst((string)($z['auftrag']['ziel']['raum'] ?? ''), $raum)));
        }
        if ($such !== '') {
            // Ein Kandidat je betroffenem Gerät, dann alle Zeitpläne dieses Geräts.
            $kandidaten = [];
            foreach ($liste as $z) {
                $ziel = $z['auftrag']['ziel'];
                $k = (int)$ziel['id'];
                $kandidaten[$k] ??= ['id' => $k, 'titel' => (string)$ziel['titel'], 'name' => (string)$ziel['titel'],
                                     'raum' => (string)($ziel['raum'] ?? ''), 'alias' => []];
            }
            $erg = $this->VoiceGeraeteAufloesen($such, array_values($kandidaten), $raum);
            $fehler = $this->VoiceAufloeseFehler($erg, $such, $this->Translate('planned devices'));
            if ($fehler !== null) {
                return $fehler;
            }
            $zielId = (int)$erg['treffer'][0]['schluessel']['id'];
            $liste = array_values(array_filter($liste, static fn(array $z): bool => (int)$z['auftrag']['ziel']['id'] === $zielId));
        }
        return ['ok' => true, 'liste' => $liste];
    }

    // ------------------------------------------------------------------
    // Feuern
    // ------------------------------------------------------------------

    /**
     * Das Ereignis ist fällig. Dieselben Riegel wie beim gesprochenen Befehl —
     * ein Zeitplan, der vor Wochen angelegt wurde, darf heute nicht mehr, als
     * ein Aufruf heute dürfte. Einmaliges räumt sich danach selbst weg.
     */
    private function VoiceZeitplanFeuern(int $ev): void
    {
        if ($ev <= 0 || !@IPS_EventExists($ev)) {
            return;
        }
        $auftrag = json_decode((string)(@IPS_GetObject($ev)['ObjectInfo'] ?? ''), true);
        if (!is_array($auftrag) || ($auftrag['symdo'] ?? '') !== 'zeitplan' || !is_array($auftrag['ziel'] ?? null)) {
            $this->LogMessage(sprintf('SymDo Sprachdialog: Ereignis #%d trägt keinen Zeitplan — nichts geschaltet.', $ev), KL_WARNING);
            return;
        }
        $einmal = (string)($auftrag['art'] ?? '') === 'einmal';
        $ziel = $auftrag['ziel'];
        $ctx = ['userId' => (string)($auftrag['userId'] ?? ''), 'defaults' => []];
        $zeile = $this->VoiceZeitplanZeile(['id' => $ev, 'auftrag' => $auftrag, 'naechstes' => 0, 'aktiv' => true]);
        try {
            $zu = $this->VoiceGeraeteTorZu();
            if ($zu !== null) {
                $this->LogMessage(sprintf('SymDo Sprachdialog: Zeitplan #%d nicht ausgeführt (%s) — %s', $ev,
                    (string)($zu['error']['code'] ?? '?'), $zeile), KL_WARNING);
                $this->VoiceLogEintrag('zeitplan', 'nicht ausgeführt: ' . (string)($zu['error']['code'] ?? '?'), false);
                return;
            }
            $rueckfrage = $this->VoiceGeraetMitRueckfrage((int)$ziel['id'])
                || ((int)($ziel['inst'] ?? 0) > 0 && $this->VoiceGeraetMitRueckfrage((int)$ziel['inst']));
            if ($rueckfrage && ($this->VoiceIstKind($ctx) || ($auftrag['bestaetigt'] ?? false) !== true)) {
                // Das Gerät kam NACH dem Anlegen auf die Rückfrage-Liste (oder der
                // Anleger ist inzwischen Kind): ohne gesprochenes Ja wird nichts geschaltet.
                $this->LogMessage(sprintf('SymDo Sprachdialog: Zeitplan #%d nicht ausgeführt (Rückfrage-Gerät ohne Bestätigung) — %s', $ev, $zeile), KL_WARNING);
                $this->VoiceLogEintrag('zeitplan', 'nicht ausgeführt: Rückfrage-Gerät', false);
                return;
            }
            $r = $this->VoiceGeraetSchalten($ziel, $ctx, (bool)($auftrag['bestaetigt'] ?? false), 'Zeitplan');
            $ok = ($r['ok'] ?? false) === true;
            if (!$ok) {
                $this->LogMessage(sprintf('SymDo Sprachdialog: Zeitplan #%d fehlgeschlagen — %s: %s', $ev, $zeile,
                    (string)($r['sag'] ?? ($r['error']['code'] ?? '?'))), KL_WARNING);
            }
            $this->VoiceLogEintrag('zeitplan', (string)($r['sag'] ?? $zeile), $ok);
            if (!$einmal && @IPS_EventExists($ev)) {
                $auftrag['letzte'] = ['t' => time(), 'ok' => $ok, 'text' => mb_substr((string)($r['sag'] ?? ''), 0, 120)];
                @IPS_SetInfo($ev, (string)json_encode($auftrag, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        } catch (\Throwable $e) {
            $this->LogMessage(sprintf('SymDo Sprachdialog: Zeitplan #%d warf: %s', $ev, $e->getMessage()), KL_ERROR);
        } finally {
            if ($einmal && @IPS_EventExists($ev)) {
                @IPS_DeleteEvent($ev);   // erledigt oder verworfen — einmal ist einmal
            }
        }
    }

    /** Anzeigename eines Mitglieds, '' wenn unbekannt. */
    private function VoiceNutzerName(string $userId): string
    {
        if ($userId === '') {
            return '';
        }
        try {
            foreach ($this->LoadUsers() as $u) {
                if ((string)($u['id'] ?? '') === $userId) {
                    return (string)($u['name'] ?? '');
                }
            }
        } catch (\Throwable $e) {
            // ohne Mitgliederliste eben ohne Namen
        }
        return '';
    }
}

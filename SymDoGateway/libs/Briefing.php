<?php

declare(strict_types=1);

/**
 * Taegliches Briefing — ein kurzer Text, der den Tag zusammenfasst.
 *
 * Die Daten liegen alle schon im Haus: heutige Termine kommen aus dem
 * Kalender-Trait, faellige und liegengebliebene Aufgaben aus den Listen,
 * Geburtstage und Rollen aus den Stammdaten der Familienmitglieder. Neu ist nur,
 * sie zusammenzutragen, einmal am Tag durch die KI zu schicken und das Ergebnis
 * abzulegen.
 *
 * Warum abgelegt und nicht beim Oeffnen erzeugt: Ein Anbieteraufruf dauert
 * gemessen 3 bis 60 Sekunden. Wuerde die App ihn selbst anstossen, wartete der
 * erste Blick aufs Handy jeden Morgen darauf — und bei drei Geraeten dreimal.
 * So kostet das Briefing genau einen Aufruf pro Tag, und die Oberflaechen holen
 * nur fertigen Text.
 *
 * Es ist der erste FREITEXT-Aufruf des Moduls; alle anderen erwarten JSON und
 * laufen durch einen Parser. AiRunCompletion selbst ist schema-agnostisch, an
 * der Anbieterseite war deshalb nichts zu aendern.
 */
trait Briefing
{
    private const BRIEFING_TIMER      = 'Briefing';
    /** Nach einem gescheiterten Anbieteraufruf: erneut versuchen statt bis morgen warten. */
    private const BRIEFING_RETRY_MS   = 1800000;
    private const BRIEFING_FAIL_MAX   = 3;
    /** Deckel fuer den Prompt. Ein Tag mit 200 Terminen soll kein Vermoegen kosten. */
    private const BRIEFING_MAX_EVENTS = 30;
    private const BRIEFING_MAX_TASKS  = 30;
    /** Deckel fuer die Antwort: aus „zwei bis vier Saetzen" wird sonst gelegentlich ein Aufsatz. */
    private const BRIEFING_TEXT_MAX   = 1500;

    private ?array $briefingConfigCache = null;

    private function BriefingCreate(): void
    {
        $this->RegisterPropertyBoolean('BriefingEnabled', false);
        // Leer = „wie in der Web-App eingestellt" (Property DefaultUserID der
        // SymDoWebApp-Kachel). Das Briefing entsteht hier, braucht also einen
        // eigenen Bezugspunkt — im Gateway gibt es keinen Standard-Benutzer.
        $this->RegisterPropertyString('BriefingUserID', '');
        // SelectTime traegt seinen Wert als JSON, genau wie SelectDate.
        $this->RegisterPropertyString('BriefingTime', '{"hour":5,"minute":30,"second":0}');
        $this->RegisterPropertyString('BriefingTone', 'neutral');
        // {"d":"YYYY-MM-DD","text":"…","at":ts,"userId":"…","failDay":"…","fails":n}
        $this->RegisterAttributeString('Briefing', '{}');
        $this->RegisterTimer(self::BRIEFING_TIMER, 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'Briefing\', 0);');
    }

    private function BriefingApplyChanges(): void
    {
        if (!$this->BriefingIsEnabled()) {
            $this->BriefingArm(0);
            return;
        }
        $this->BriefingArm();
    }

    private function BriefingRequestAction(string $ident, mixed $value): bool
    {
        if ($ident === 'Briefing') {
            $this->BriefingRun();
            return true;
        }
        if ($ident === 'BriefingNow') {
            $this->BriefingNow();
            return true;
        }
        return false;
    }

    // ────────────────────────────── Freigabe ──────────────────────────────

    /**
     * Dieselbe Kette wie beim Mail-Weg: eigener Schalter, KI-Schalter und
     * Einwilligung. Das Briefing schickt Termine, Aufgaben und Namen an einen
     * Anbieter — ohne Einwilligung darf davon nichts entstehen und nichts
     * ausgeliefert werden.
     */
    private function BriefingIsEnabled(): bool
    {
        return (bool)$this->BriefingProp('BriefingEnabled', false)
            && $this->ReadPropertyBoolean('AiEnabled')
            && $this->AiPrivacyAccepted();
    }

    /** Siehe MailProp: Properties dieses Traits gibt es erst nach einem Kernel-Neustart. */
    private function BriefingProp(string $name, mixed $vorgabe): mixed
    {
        if ($this->briefingConfigCache === null) {
            $cfg = json_decode((string)@IPS_GetConfiguration($this->InstanceID), true);
            $this->briefingConfigCache = is_array($cfg) ? $cfg : [];
        }
        return array_key_exists($name, $this->briefingConfigCache)
            ? $this->briefingConfigCache[$name]
            : $vorgabe;
    }

    // ────────────────────────────── Ablage ──────────────────────────────

    private function BriefingRaw(): string
    {
        try {
            return (string)@$this->ReadAttributeString('Briefing');
        } catch (Throwable $e) {
            return '';
        }
    }

    /** @return array<string, mixed> */
    private function BriefingStore(): array
    {
        $stand = json_decode($this->BriefingRaw(), true);
        return is_array($stand) ? $stand : [];
    }

    /**
     * Ist das Attribut ueberhaupt beschreibbar?
     *
     * `WriteAttribute*` auf ein noch nicht existierendes Attribut wirft nicht, es
     * tut still nichts — und danach waere der bezahlte Anbieteraufruf verloren.
     * Die Probe schreibt deshalb einen Wert, der sich vom Bestand garantiert
     * unterscheidet: Ein Vergleich „geschrieben gleich gelesen" mit dem echten
     * Wert kann einen stillen Fehlschlag nicht erkennen, wenn Bestand und neuer
     * Wert zufaellig gleich aussehen (frische Instanz, beides leer).
     */
    private function BriefingStorable(): bool
    {
        $vorher = $this->BriefingRaw();
        $probe  = '{"probe":' . time() . '}';
        @$this->WriteAttributeString('Briefing', $probe);
        $ok = $this->BriefingRaw() === $probe;
        // Bestand zurueck — auch wenn die Probe scheiterte, dann ohnehin folgenlos.
        @$this->WriteAttributeString('Briefing', $vorher);
        if (!$ok) {
            $this->LogMessage(
                'SymDo: Attribut Briefing ist nicht speicherbar — der Symcon-Kernel muss nach dem Modul-Update einmal neu gestartet werden. Das Briefing bleibt bis dahin aus.',
                KL_ERROR
            );
        }
        return $ok;
    }

    private function BriefingWriteStore(array $stand): bool
    {
        $wert = json_encode($stand, JSON_UNESCAPED_UNICODE);
        @$this->WriteAttributeString('Briefing', $wert);
        return $this->BriefingRaw() === $wert;
    }

    /** Beim Einwilligungs-Widerruf: der Text ist aus denselben Daten entstanden. */
    private function BriefingClear(): bool
    {
        if ($this->BriefingStore() === []) {
            return false;
        }
        @$this->WriteAttributeString('Briefing', '{}');
        return true;
    }


    // ────────────────────────────── Timer ──────────────────────────────

    private function BriefingArm(?int $ms = null): void
    {
        $ms ??= $this->BriefingMsUntilNext();
        try {
            $this->SetTimerInterval(self::BRIEFING_TIMER, $ms);
        } catch (Throwable $e) {
            // Timer nach einem Modul-Reload ohne Kernel-Neustart noch nicht
            // registriert — wie in MailArm bewusst ohne Ersatzlauf von hier.
            $this->SendDebug('Briefing', 'Timer fehlt, Lauf entfaellt', 0);
        }
    }

    /** @return array{0: int, 1: int} Stunde, Minute der eingestellten Zeit. */
    private function BriefingTargetTime(): array
    {
        $zeit = json_decode((string)$this->BriefingProp('BriefingTime', ''), true);
        $std  = is_array($zeit) ? (int)($zeit['hour'] ?? 5) : 5;
        $min  = is_array($zeit) ? (int)($zeit['minute'] ?? 30) : 30;
        return [max(0, min(23, $std)), max(0, min(59, $min))];
    }

    /**
     * Millisekunden bis zur naechsten Zielzeit — je Lauf neu gerechnet.
     *
     * Bewusst nicht fest 24 Stunden: Sonst wandert die Uhrzeit bei jeder Zeitum-
     * stellung um eine Stunde, und eine im Formular geaenderte Zeit griffe erst
     * am naechsten Tag.
     */
    private function BriefingMsUntilNext(): int
    {
        [$std, $min] = $this->BriefingTargetTime();
        $jetzt = time();
        $heute = mktime($std, $min, 0, (int)date('n'), (int)date('j'), (int)date('Y'));
        if ($heute === false) {
            return 3600000;
        }
        $ziel = $heute > $jetzt ? $heute : (int)strtotime('+1 day', $heute);
        // Mindestabstand, damit ein Grenzfall (Zielzeit genau jetzt) den Timer
        // nicht in eine Schleife aus Sofortlaeufen schickt.
        return max(60000, ($ziel - $jetzt) * 1000);
    }

    // ────────────────────────────── Lauf ──────────────────────────────

    /** Der Timer-Lauf. Ergebnis interessiert hier niemanden, nur der Folgetermin. */
    private function BriefingRun(): void
    {
        $ergebnis = $this->BriefingErzeugen(false);
        // Fehlversuch: in einer halben Stunde erneut, hoechstens BRIEFING_FAIL_MAX
        // mal. Ein dauerhaft nicht erreichbarer Anbieter soll nicht alle 30 Minuten
        // einen Fehler ins Protokoll schreiben.
        $wiederholen = !$ergebnis['ok']
            && ($ergebnis['retry'] ?? false)
            && (int)($this->BriefingStore()['fails'] ?? 0) < self::BRIEFING_FAIL_MAX;
        $this->BriefingArm($wiederholen ? self::BRIEFING_RETRY_MS : null);
    }

    /** Der Knopf im Formular: erzeugt sofort neu und meldet das Ergebnis zurueck. */
    private function BriefingNow(): void
    {
        $meldung = function (string $text): void {
            // Bewusst UpdateFormField und kein echo: eine Ausgabe aus RequestAction
            // meldet Symcon als Skriptfehler samt Dateiname und Zeilennummer.
            $this->UpdateFormField('BriefingStatus', 'caption', $text);
        };
        if (!(bool)$this->BriefingProp('BriefingEnabled', false)) {
            $meldung($this->Translate('Switch the briefing on first and press Apply.'));
            return;
        }
        if (!$this->BriefingIsEnabled()) {
            $meldung($this->Translate('The AI is off or the consent under "AI features" is missing.'));
            return;
        }
        $ergebnis = $this->BriefingErzeugen(true);
        if ($ergebnis['ok']) {
            $meldung($this->BriefingText());
            return;
        }
        $meldung(sprintf($this->Translate('Briefing failed: %s'), (string)$ergebnis['message']));
    }

    /**
     * @return array{ok: bool, message: string, retry: bool}
     */
    private function BriefingErzeugen(bool $handanstoss): array
    {
        if (!$this->BriefingIsEnabled()) {
            return ['ok' => false, 'message' => 'briefing_disabled', 'retry' => false];
        }
        $riegel = 'SymDo_Briefing_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($riegel, 0)) {
            $this->SendDebug('Briefing', 'Lauf laeuft bereits, dieser entfaellt', 0);
            return ['ok' => false, 'message' => 'busy', 'retry' => true];
        }
        try {
            return $this->BriefingSchritt($handanstoss);
        } finally {
            IPS_SemaphoreLeave($riegel);
        }
    }

    /** @return array{ok: bool, message: string, retry: bool} */
    private function BriefingSchritt(bool $handanstoss): array
    {
        $stand = $this->BriefingStore();
        $heute = date('Y-m-d');

        // Erst pruefen, ob das Ergebnis ueberhaupt ablegbar ist — ein Anbieter-
        // aufruf ins Leere kostet Geld und der Text waere danach weg.
        if (!$this->BriefingStorable()) {
            return ['ok' => false, 'message' => 'attribute_unwritable', 'retry' => false];
        }
        if (!$handanstoss && ($stand['d'] ?? '') === $heute && trim((string)($stand['text'] ?? '')) !== '') {
            $this->SendDebug('Briefing', 'fuer heute liegt schon eines vor', 0);
            return ['ok' => true, 'message' => 'already_done', 'retry' => false];
        }

        $daten   = $this->BriefingCollect();
        $antwort = $this->AiRunCompletion($this->BriefingSystemPrompt(), $this->BriefingUserText($daten), null);
        if (!(bool)($antwort['ok'] ?? false)) {
            $code = (string)($antwort['code'] ?? 'ai_error');
            $this->SendDebug('Briefing', 'Anbieter meldet ' . $code, 0);
            $stand['failDay'] = $heute;
            $stand['fails']   = (($stand['failDay'] ?? '') === $heute ? (int)($stand['fails'] ?? 0) : 0) + 1;
            $this->BriefingWriteStore($stand);
            return ['ok' => false, 'message' => $code, 'retry' => true];
        }

        $text = $this->BriefingTidy((string)($antwort['text'] ?? ''));
        if ($text === '') {
            $stand['failDay'] = $heute;
            $stand['fails']   = (int)($stand['fails'] ?? 0) + 1;
            $this->BriefingWriteStore($stand);
            return ['ok' => false, 'message' => 'ai_empty', 'retry' => true];
        }

        $neu = [
            'd'      => $heute,
            'text'   => $text,
            'at'     => time(),
            'userId' => (string)$daten['userId'],
        ];
        if (!$this->BriefingWriteStore($neu)) {
            return ['ok' => false, 'message' => 'attribute_unwritable', 'retry' => false];
        }
        // Die Oberflaechen holen den Text beim naechsten „irgendetwas hat sich
        // geaendert" — ein eigener Kanal waere fuer einen Text pro Tag zu viel.
        $this->WsPushDirty();
        $this->SendDebug('Briefing', sprintf('erzeugt, %d Zeichen', strlen($text)), 0);
        return ['ok' => true, 'message' => 'created', 'retry' => false];
    }

    /** Fliesstext erwartet: Aufzaehlungszeichen und Zierrat raus, Laenge deckeln. */
    private function BriefingTidy(string $text): string
    {
        $text = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text) ?? '');
        $text = trim(preg_replace('/^\s*[-*•]\s*/mu', '', $text) ?? '');
        $text = trim(preg_replace('/\n{3,}/u', "\n\n", $text) ?? '');
        if (mb_strlen($text) > self::BRIEFING_TEXT_MAX) {
            $text = rtrim(mb_substr($text, 0, self::BRIEFING_TEXT_MAX)) . '…';
        }
        return $text;
    }

    // ────────────────────────────── Daten ──────────────────────────────

    /**
     * Alles, was das Briefing braucht, in einer Form, die sich kurz in den Prompt
     * schreiben laesst.
     *
     * @return array{userId: string, name: string, termine: list<string>, aufgaben: list<string>, ueberfaellig: list<string>, geburtstage: list<string>, rollen: list<string>}
     */
    private function BriefingCollect(): array
    {
        $mitglieder = $this->BriefingMembers();
        $userId     = $this->BriefingUserId();

        return [
            'userId'       => $userId,
            'name'         => (string)($mitglieder[$userId]['name'] ?? ''),
            'termine'      => $this->BriefingEventLines($mitglieder),
            'aufgaben'     => $this->BriefingTaskLines($mitglieder, false),
            'ueberfaellig' => $this->BriefingTaskLines($mitglieder, true),
            'geburtstage'  => $this->BriefingBirthdayLines($mitglieder),
            'rollen'       => $this->BriefingRoleLines($mitglieder),
        ];
    }

    /**
     * Familienmitglieder mit allem, was in den Stammdaten steht. LoadUsers()
     * liefert absichtlich nur das, was die Apps sehen duerfen — Nachname,
     * Geburtsdatum und Rolle stehen nur in der Property.
     *
     * @return array<string, array{name: string, lastName: string, persona: string, birthday: array{y: int, m: int, d: int}}>
     */
    private function BriefingMembers(): array
    {
        $roh = json_decode((string)@IPS_GetProperty($this->InstanceID, 'Users'), true);
        $raus = [];
        foreach (is_array($roh) ? $roh : [] as $zeile) {
            if (!is_array($zeile)) {
                continue;
            }
            $id   = trim((string)($zeile['id'] ?? ''));
            $name = trim((string)($zeile['name'] ?? ''));
            if ($id === '' || $name === '') {
                continue;
            }
            $raus[$id] = [
                'name'     => $name,
                'lastName' => trim((string)($zeile['lastName'] ?? '')),
                'persona'  => trim((string)($zeile['persona'] ?? '')),
                'birthday' => $this->BriefingParseDate((string)($zeile['birthday'] ?? '')),
            ];
        }
        return $raus;
    }

    /** SelectDate legt {"year":…,"month":…,"day":…} ab; alles auf 0 heisst „nicht angegeben". */
    private function BriefingParseDate(string $roh): array
    {
        $d = json_decode($roh, true);
        if (!is_array($d)) {
            return ['y' => 0, 'm' => 0, 'd' => 0];
        }
        return [
            'y' => max(0, (int)($d['year'] ?? 0)),
            'm' => max(0, min(12, (int)($d['month'] ?? 0))),
            'd' => max(0, min(31, (int)($d['day'] ?? 0))),
        ];
    }

    /**
     * Fuer wen das Briefing geschrieben wird.
     *
     * Vorgabe ist der Standard-Benutzer der Web-App-Kachel — dort steht er, und
     * die Erwartung des Nutzers hoert nicht an der Modulgrenze auf. Der Zugriff
     * ist die EINZIGE Stelle, an der das Gateway eine Nachbar-Property liest,
     * deshalb defensiv und ohne Folgen, wenn die Kachel fehlt.
     */
    private function BriefingUserId(): string
    {
        $gewaehlt = trim((string)$this->BriefingProp('BriefingUserID', ''));
        if ($gewaehlt !== '') {
            return $gewaehlt;
        }
        try {
            foreach (IPS_GetInstanceListByModuleID(self::WEBAPP_MODULE_GUID) as $id) {
                $wert = trim((string)@IPS_GetProperty($id, 'DefaultUserID'));
                if ($wert !== '') {
                    return $wert;
                }
            }
        } catch (Throwable $e) {
            // Kachel nicht vorhanden oder Property unbekannt: dann ohne Anrede.
        }
        return '';
    }

    /** @return list<string> */
    private function BriefingEventLines(array $mitglieder): array
    {
        $von = (int)strtotime('today');
        $bis = (int)strtotime('tomorrow');
        try {
            $termine = $this->CalEvents($von, $bis)['events'];
        } catch (Throwable $e) {
            $this->SendDebug('Briefing', 'Kalender nicht lesbar: ' . $e->getMessage(), 0);
            return [];
        }

        $raus = [];
        foreach ($termine as $e) {
            if (count($raus) >= self::BRIEFING_MAX_EVENTS) {
                break;
            }
            $start = (int)($e['start'] ?? 0);
            // Ganztaegig oder aus einem frueheren Tag herueberlaufend: keine Uhrzeit,
            // die waere sonst irrefuehrend („00:00" bzw. der Beginn von vorgestern).
            $zeit = ((bool)($e['allDay'] ?? false) || $start < $von)
                ? 'ganztaegig'
                : date('H:i', $start);
            $zeile = $zeit . ' ' . trim((string)($e['title'] ?? ''));
            $wer = $this->BriefingNames((array)($e['members'] ?? []), $mitglieder);
            if ($wer !== '') {
                $zeile .= ' (' . $wer . ')';
            }
            $ort = trim((string)($e['location'] ?? ''));
            if ($ort !== '') {
                $zeile .= ', Ort: ' . $ort;
            }
            $raus[] = $zeile;
        }
        return $raus;
    }

    /**
     * Aufgaben aus allen Aufgabenlisten.
     *
     * Die Einteilung folgt Zeile fuer Zeile UpdateStatistics() im ToDoList-Modul:
     * All-Day-Aufgaben gegen die Tagesgrenze, terminierte gegen die Uhr. Waere sie
     * hier anders, widersprachen Briefing und Kachel-Zaehler einander.
     *
     * @return list<string>
     */
    private function BriefingTaskLines(array $mitglieder, bool $ueberfaellig): array
    {
        $jetzt      = time();
        $heuteStart = (int)strtotime('today');
        $heuteEnde  = (int)strtotime('tomorrow');

        $raus = [];
        foreach ($this->GetListInstances() as $instanz) {
            if ($instanz['kind'] !== 'todo') {
                continue; // Einkaufslisten haben keine Faelligkeit
            }
            $zustand = json_decode((string)$this->CallInstanceGetAppState((int)$instanz['id'], 'todo'), true);
            // TDL_GetAppState huellt den Zustand ein: {revision, kind, state:{items}}.
            // Der Rueckfall auf die flache Form kostet nichts und traegt eine
            // aeltere Listen-Version, falls eine solche noch antwortet.
            $items = (array)($zustand['state']['items'] ?? $zustand['items'] ?? []);
            foreach ($items as $it) {
                if (!is_array($it) || !empty($it['done'])) {
                    continue;
                }
                $due = (int)($it['due'] ?? 0);
                if ($due <= 0) {
                    continue;
                }
                $istAllDay = (bool)($it['dueAllDay'] ?? false);
                if ($istAllDay) {
                    $passt = $ueberfaellig ? ($due < $heuteStart) : ($due >= $heuteStart && $due < $heuteEnde);
                } else {
                    $passt = $ueberfaellig
                        ? ($due < $jetzt)
                        : ($due >= $jetzt && $due >= $heuteStart && $due < $heuteEnde);
                }
                if (!$passt || count($raus) >= self::BRIEFING_MAX_TASKS) {
                    continue;
                }
                $zeile = trim((string)($it['title'] ?? ''));
                if ($zeile === '') {
                    continue;
                }
                if (!$istAllDay && !$ueberfaellig) {
                    $zeile = date('H:i', $due) . ' ' . $zeile;
                }
                if ($ueberfaellig) {
                    $tage = (int)floor(($heuteStart - (int)strtotime(date('Y-m-d', $due))) / 86400);
                    $zeile .= $tage > 0 ? sprintf(' (seit %d Tag(en))', $tage) : ' (heute schon vorbei)';
                }
                $wer = $this->BriefingNames((array)($it['assignedTo'] ?? []), $mitglieder);
                if ($wer !== '') {
                    $zeile .= ' (' . $wer . ')';
                }
                if ((string)($it['priority'] ?? '') === 'high') {
                    $zeile .= ' [wichtig]';
                }
                $raus[] = $zeile;
            }
        }
        return $raus;
    }

    /**
     * Wer heute Geburtstag hat — von PHP gerechnet, nicht von der KI.
     * Datumsarithmetik ist die klassische Schwachstelle eines Sprachmodells.
     *
     * @return list<string>
     */
    private function BriefingBirthdayLines(array $mitglieder): array
    {
        $m = (int)date('n');
        $t = (int)date('j');
        $j = (int)date('Y');

        $raus = [];
        foreach ($mitglieder as $mitglied) {
            $g = $mitglied['birthday'];
            if ($g['m'] !== $m || $g['d'] !== $t || $g['m'] === 0) {
                continue;
            }
            $raus[] = $g['y'] > 0 && $g['y'] < $j
                ? sprintf('%s wird heute %d', $mitglied['name'], $j - $g['y'])
                : sprintf('%s hat heute Geburtstag', $mitglied['name']);
        }
        return $raus;
    }

    /** @return list<string> */
    private function BriefingRoleLines(array $mitglieder): array
    {
        $rollen = [
            'father'      => 'Vater',
            'mother'      => 'Mutter',
            'child'       => 'Kind',
            'grandmother' => 'Oma',
            'grandfather' => 'Opa',
            'uncle'       => 'Onkel',
            'aunt'        => 'Tante',
        ];
        $raus = [];
        foreach ($mitglieder as $mitglied) {
            $rolle = $rollen[$mitglied['persona']] ?? '';
            if ($rolle !== '') {
                $raus[] = $mitglied['name'] . ' = ' . $rolle;
            }
        }
        return $raus;
    }

    /** Mitglieds-IDs zu Namen; unbekannte Kennungen fallen weg. */
    private function BriefingNames(array $ids, array $mitglieder): string
    {
        $namen = [];
        foreach ($ids as $id) {
            $name = (string)($mitglieder[trim((string)$id)]['name'] ?? '');
            if ($name !== '' && !in_array($name, $namen, true)) {
                $namen[] = $name;
            }
        }
        return implode(', ', $namen);
    }

    // ────────────────────────────── Prompt ──────────────────────────────

    /**
     * Deutschsprachig wie die uebrigen Prompts des Moduls (siehe AiSystemPrompt):
     * Anbieter antworten in der Sprache der Anweisung, und die Daten selbst —
     * Termintitel, Aufgaben, Namen — sind ohnehin deutsch.
     */
    private function BriefingSystemPrompt(): string
    {
        return 'Du schreibst das Tagesbriefing fuer eine Familie in einer Haushalts-App. '
            . 'Fasse den heutigen Tag in zwei bis vier Saetzen zusammen — durchgehender '
            . 'Fliesstext, KEINE Aufzaehlung, keine Zwischentitel, kein Markdown. '
            . 'Sprich die angesprochene Person mit ihrem Vornamen an, wenn einer genannt ist. '
            . 'Nenne die Namen der beteiligten Familienmitglieder, wenn Termine oder Aufgaben '
            . 'ihnen gehoeren. Uhrzeiten uebernimmst du unveraendert. '
            . 'Erfinde NICHTS: keine Termine, keine Aufgaben, keine Uhrzeiten, die unten nicht '
            . 'stehen. Steht nichts an, sag das in einem Satz. '
            . 'Hat jemand Geburtstag, gratuliere ihm zuerst. '
            . 'Schliesse mit einem kurzen Wunsch fuer einen erfolgreichen Tag. '
            . $this->BriefingToneRule();
    }

    /** Der Tonfall aus den Einstellungen — ein Satz, der den Rest des Prompts einfaerbt. */
    private function BriefingToneRule(): string
    {
        switch ((string)$this->BriefingProp('BriefingTone', 'neutral')) {
            case 'formal':
                return 'TONFALL: Foermlich und zurueckhaltend, wie ein Butler. Siez die Person, '
                    . 'keine Ausrufezeichen, keine Emojis.';
            case 'buddy':
                return 'TONFALL: Wie ein guter Kumpel — locker, duzend, kurze Saetze, ruhig mal '
                    . 'ein umgangssprachlicher Ausdruck. Keine Emojis.';
            case 'funny':
                return 'TONFALL: Humorvoll mit einem Augenzwinkern, gern ein trockener Kommentar '
                    . 'zum Tag — aber die Angaben bleiben korrekt und vollstaendig.';
            case 'drill':
                return 'TONFALL: Wie ein Drillsergeant beim Wecken — knappe Befehlssaetze, '
                    . 'Grossbuchstaben fuer ein bis zwei Worte erlaubt, militaerischer Drill mit '
                    . 'einem Augenzwinkern. Niemals beleidigend.';
            case 'coach':
                return 'TONFALL: Wie ein Motivationstrainer — anfeuernd, positiv, du-Form, '
                    . 'ein aufbauender Halbsatz zum Schluss.';
            default:
                return 'TONFALL: Sachlich und freundlich, du-Form, ohne Ueberschwang.';
        }
    }

    /** Der Nutzer-Teil: die gesammelten Daten, knapp und maschinennah aufgelistet. */
    private function BriefingUserText(array $daten): string
    {
        $wochentage = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
        $teile = [
            'HEUTE: ' . $wochentage[(int)date('w')] . ', ' . date('d.m.Y'),
            'BRIEFING FUER: ' . ($daten['name'] !== '' ? $daten['name'] : '(niemand bestimmter — schreibe ohne Anrede)'),
        ];
        $block = static function (string $titel, array $zeilen, string $leer): string {
            return $titel . ': ' . ($zeilen === [] ? $leer : "\n- " . implode("\n- ", $zeilen));
        };
        $teile[] = $block('TERMINE HEUTE', $daten['termine'], 'keine');
        $teile[] = $block('AUFGABEN HEUTE FAELLIG', $daten['aufgaben'], 'keine');
        $teile[] = $block('LIEGENGEBLIEBENE AUFGABEN', $daten['ueberfaellig'], 'keine');
        if ($daten['geburtstage'] !== []) {
            $teile[] = $block('GEBURTSTAG HEUTE', $daten['geburtstage'], '');
        }
        if ($daten['rollen'] !== []) {
            $teile[] = $block('ROLLEN IM HAUSHALT', $daten['rollen'], '');
        }
        return implode("\n\n", $teile);
    }

    // ────────────────────────────── Ausgabe ──────────────────────────────

    /** Der Text von heute, sonst leer. */
    private function BriefingText(): string
    {
        $stand = $this->BriefingStore();
        if (($stand['d'] ?? '') !== date('Y-m-d')) {
            return '';
        }
        return trim((string)($stand['text'] ?? ''));
    }

    /**
     * Antwort der Route /v1/briefing.
     *
     * `briefing` ist null, solange abgeschaltet, noch nichts erzeugt oder der
     * Stand von einem anderen Tag ist — die Oberflaechen blenden dann aus, statt
     * das Briefing von gestern als heutiges zu zeigen.
     *
     * @return array<string, mixed>
     */
    private function BriefingPublic(): array
    {
        if (!$this->BriefingIsEnabled()) {
            return ['ok' => true, 'briefing' => null];
        }
        $text = $this->BriefingText();
        if ($text === '') {
            return ['ok' => true, 'briefing' => null];
        }
        $stand = $this->BriefingStore();
        return ['ok' => true, 'briefing' => [
            'text'        => $text,
            'date'        => (string)($stand['d'] ?? ''),
            'generatedAt' => (int)($stand['at'] ?? 0),
            'userId'      => (string)($stand['userId'] ?? ''),
        ]];
    }
}

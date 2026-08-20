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
    /**
     * Ab so vielen offenen Artikeln lohnt der Hinweis auf eine Einkaufstour.
     * Darunter ist eine Liste normaler Alltag und der Hinweis nur Geplauder —
     * deshalb steht die Zahl unterhalb der Schwelle gar nicht erst im Prompt.
     */
    private const BRIEFING_SHOP_HINT  = 10;
    /**
     * Obergrenze fuer den vorgelesenen Text. OpenAI nimmt 4096 Zeichen je Aufruf;
     * der Abstand ist Absicht, denn das Briefing selbst endet schon bei
     * BRIEFING_TEXT_MAX.
     */
    private const BRIEFING_TTS_MAX    = 3500;
    /**
     * Bis hierher wird EINE Datei erzeugt, darueber zwei.
     *
     * Gemessen am 20.08.2026: 1309 Zeichen ergaben als AAC 859 kB — die Abholung
     * endet aber bei 1 MB (TTS_MAX_BYTES). Der Schwellwert laesst also Luft, statt
     * knapp darunter zu zielen: Eine zu grosse Aufnahme waere gar keine, und eine
     * Naht in der Mitte ist besser als Stille.
     */
    private const BRIEFING_TTS_ONE    = 1250;

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
            // Der Ton entsteht JETZT, nicht beim Tippen auf Vorlesen: Acht
            // Schnipsel brauchen gemessen um zehn Sekunden — die soll niemand
            // vor einem stummen Knopf abwarten.
            'clips'  => $this->BriefingAudio($text),
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
     * @return array{userId: string, name: string, termine: list<string>, aufgaben: list<string>, ueberfaellig: list<string>, geburtstage: list<string>, rollen: list<string>, einkauf: array{anzahl: int, liste: string}}
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
            'einkauf'      => $this->BriefingShopping(),
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
     * Offene Artikel auf den Einkaufslisten.
     *
     * Gezaehlt wird wie in beiden Oberflaechen: alles, was nicht im Wagen liegt
     * (`inCart`), und nur aus Listen, die nicht ausgeblendet sind — was niemand
     * sieht, soll auch niemanden zum Einkaufen schicken.
     *
     * @return array{anzahl: int, liste: string}
     */
    private function BriefingShopping(): array
    {
        $versteckt = $this->GetHiddenInstances();
        $anzahl = 0;
        $groesste = ['n' => 0, 'name' => ''];
        foreach ($this->GetListInstances() as $instanz) {
            $id = (int)$instanz['id'];
            if ($instanz['kind'] !== 'shopping' || in_array($id, $versteckt, true)) {
                continue;
            }
            $zustand = json_decode((string)$this->CallInstanceGetAppState($id, 'shopping'), true);
            $items = (array)($zustand['state']['items'] ?? $zustand['items'] ?? []);
            $offen = 0;
            foreach ($items as $it) {
                if (is_array($it) && empty($it['inCart'])) {
                    $offen++;
                }
            }
            $anzahl += $offen;
            if ($offen > $groesste['n']) {
                $groesste = ['n' => $offen, 'name' => (string)($zustand['state']['listName'] ?? IPS_GetName($id))];
            }
        }
        return ['anzahl' => $anzahl, 'liste' => $groesste['name']];
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
            . 'Fasse den heutigen Tag in zwei bis fuenf Saetzen zusammen — durchgehender '
            . 'Fliesstext, KEINE Aufzaehlung, keine Zwischentitel, kein Markdown. '
            . 'Sprich die angesprochene Person mit ihrem Vornamen an, wenn einer genannt ist. '
            . 'Das Briefing ist der Ueberblick fuer den GANZEN Haushalt: Sage auch, was bei den '
            . 'anderen Familienmitgliedern ansteht, nicht nur bei der angesprochenen Person. '
            . 'In Klammern hinter einem Eintrag stehen die Familienmitglieder, zu denen er '
            . 'gehoert. Nenne diese Namen im Text. Stehen dort MEHRERE, nenne sie ALLE und '
            . 'verbinde sie mit „und" (aus „Fussballturnier (Max, Tim)" wird also '
            . '„das Fussballturnier von Max und Tim"). Lass keinen Namen weg und ordne keinen '
            . 'Eintrag jemandem zu, der nicht dahinter steht. '
            . 'Uhrzeiten uebernimmst du unveraendert. Steht eine Uhrzeit vor einer Aufgabe, ist '
            . 'das ihre Faelligkeit. '
            . 'Erfinde NICHTS: keine Termine, keine Aufgaben, keine Uhrzeiten, die unten nicht '
            . 'stehen — und nichts fuer morgen oder spaeter, es geht nur um HEUTE. '
            . 'Steht nichts an, sag das in einem Satz. '
            . 'Hat jemand Geburtstag, gratuliere ihm zuerst. '
            . 'Steht unten eine Einkaufsliste mit Artikelzahl, weise am Ende darauf hin, '
            . 'dass sich eine Einkaufstour lohnen wuerde, und nenne die Zahl. '
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
            case 'butler':
                // Nicht dasselbe wie „foermlich": Der ist knapp und sachlich, der Butler
                // ist ausgesucht umstaendlich — das ist der Witz daran.
                return 'TONFALL: Du bist der Butler des Hauses und sprichst ausgesucht '
                    . 'gehoben. Siez die Familie und rede sie mit „die Herrschaften", '
                    . '„der junge Herr" oder „die gnaedige Frau" an. Gewaehlte, leicht '
                    . 'altmodische Wendungen und Hoeflichkeitsfloskeln: „wenn ich mir die '
                    . 'Bemerkung erlauben darf", „sehr wohl", „es wuerde mich freuen, wenn", '
                    . '„ich habe mir erlaubt, darauf hinzuweisen". Nichts wird angetrieben, '
                    . 'es wird respektvoll in Erinnerung gerufen. Kein Ausrufezeichen, keine '
                    . 'Umgangssprache, keine Emojis. Die Angaben bleiben vollstaendig und '
                    . 'korrekt — Umstaendlichkeit ersetzt keine Uhrzeit.';
            case 'buddy':
                return 'TONFALL: Wie ein guter Kumpel — locker, duzend, kurze Saetze, ruhig mal '
                    . 'ein umgangssprachlicher Ausdruck. Keine Emojis.';
            case 'funny':
                return 'TONFALL: Humorvoll mit einem Augenzwinkern, gern ein trockener Kommentar '
                    . 'zum Tag — aber die Angaben bleiben korrekt und vollstaendig.';
            case 'drill':
                // Bewusst grob, aber im Rahmen: Klischee-Kaserne aus dem Kino, nicht
                // echte Herabsetzung. Was Aussehen, Herkunft oder Person angreift,
                // bleibt draussen — den Rest hat der Nutzer ausdruecklich so gewollt.
                return 'TONFALL: Du bist ein Drill-Sergeant aus einem Hollywood-Armeefilm und '
                    . 'brüllst die Truppe aus den Federn. Knallharte Befehlssaetze, kurz und '
                    . 'laut, Grossbuchstaben fuer einzelne Worte, gern eine Anrede wie '
                    . '„Rekrut", „Truppe" oder „Maden". Vorwurfsvoll und respektlos bis an die '
                    . 'Grenze des Klischees: „Stell dich nicht so an", „du Lusche", „das ist '
                    . 'ja jaemmerlich", „bewegt euch" gehoeren ausdruecklich dazu. Uebertreib '
                    . 'ruhig — es ist ein Gag, den sich die Familie selbst ausgesucht hat. '
                    . 'Verboten bleibt nur, was wirklich verletzt: nichts ueber Aussehen, '
                    . 'Gewicht, Herkunft, Geschlecht oder Faehigkeiten eines Menschen, keine '
                    . 'Schimpfwoerter unter der Guertellinie, keine Drohungen. Die Angaben '
                    . '(Termine, Aufgaben, Uhrzeiten) bleiben trotz allem vollstaendig und '
                    . 'korrekt.';
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
            'LESEHILFE: Klammern hinter einem Eintrag = zugeordnete Familienmitglieder.',
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
        // Unterhalb der Schwelle bleibt die Zahl draussen: Was nicht im Prompt
        // steht, kann die KI auch nicht zum Thema machen.
        if ((int)$daten['einkauf']['anzahl'] >= self::BRIEFING_SHOP_HINT) {
            $teile[] = sprintf(
                'EINKAUFSLISTE: %d offene Artikel%s',
                (int)$daten['einkauf']['anzahl'],
                $daten['einkauf']['liste'] !== '' ? ' (' . $daten['einkauf']['liste'] . ')' : ''
            );
        }
        return implode("\n\n", $teile);
    }

    // ────────────────────────────── Sprachausgabe ──────────────────────────────

    /**
     * Erzeugt EINE Tondatei zum ganzen Briefing und liefert ihre Kennung.
     *
     * Ein durchgehendes Stueck und nicht mehrere: Zwischen zwei Dateien entsteht
     * beim Abspielen eine hoerbare Naht, und der Vortrag verliert seinen Bogen —
     * die Stimme faengt jedes Mal neu an. Die 200-Zeichen-Grenze des Einkaufs-
     * Weges (TTS_MAX_CHARS, dort sinnvoll: viele kurze Ansagen) gilt hier nicht,
     * OpenAI nimmt bis 4096 Zeichen.
     *
     * Stimme und Vortragsweise kommen aus dem Tonfall: ein Drillsergeant, der
     * freundlich und ohne Eile vorgelesen wird, ist kein Drillsergeant.
     *
     * Bewusst still, wenn die Sprachausgabe nicht bereitsteht (anderer Anbieter,
     * fehlender Schluessel, Kernel noch nicht neu gestartet): Das Briefing selbst
     * ist wertvoll genug, es soll nicht am Ton scheitern — die Oberflaeche erzeugt
     * dann beim Tippen selbst.
     *
     * @return list<string> eine Kennung, oder leer
     */
    private function BriefingAudio(string $text): array
    {
        if (!$this->TtsEnabled()) {
            $this->SendDebug('Briefing', 'Sprachausgabe nicht verfuegbar, kein Ton erzeugt', 0);
            return [];
        }
        $vorlesen = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($vorlesen === '') {
            return [];
        }
        if (mb_strlen($vorlesen) > self::BRIEFING_TTS_MAX) {
            $vorlesen = rtrim(mb_substr($vorlesen, 0, self::BRIEFING_TTS_MAX));
        }
        $stimme    = $this->BriefingVoice();
        $anweisung = $this->BriefingSpeechStyle();

        $kennungen = [];
        foreach ($this->BriefingSpeechParts($vorlesen) as $teil) {
            // AAC und nicht MP3: Die Abholung endet bei 1 MB, und ein ganzes
            // Briefing als MP3 lag gemessen bei 1,3 MB — als AAC bleibt derselbe
            // Text darunter. Jeder Browser und iOS spielen AAC ohne Zutun.
            $hash = $this->TtsHash($teil, $stimme, $anweisung);
            $mid  = $this->TtsLookup($hash);
            if ($mid <= 0) {
                $mid = $this->TtsProduce($hash, $teil, $stimme, $anweisung, 'aac');
            }
            if ($mid <= 0) {
                $this->SendDebug('Briefing', 'Ton konnte nicht erzeugt werden', 0);
                return [];   // halber Ton ist schlechter als keiner
            }
            $kennungen[] = $hash;
        }
        $this->SendDebug('Briefing', sprintf('Ton erzeugt (%s, %d Zeichen, %d Datei(en))',
            $stimme, mb_strlen($vorlesen), count($kennungen)), 0);
        return $kennungen;
    }

    /**
     * Der Text als eine Aufnahme — oder als zwei, wenn er zu lang ist.
     *
     * Geteilt wird am Satzende nahe der Mitte, damit die Naht dort liegt, wo eine
     * Sprechpause ohnehin hingehoert. Bewusst nicht in viele kleine Stuecke: Jede
     * Naht kostet den Bogen des Vortrags, weil die Stimme neu anfaengt.
     *
     * @return list<string>
     */
    private function BriefingSpeechParts(string $text): array
    {
        if (mb_strlen($text) <= self::BRIEFING_TTS_ONE) {
            return [$text];
        }
        $mitte = (int)round(mb_strlen($text) / 2);
        $bester = 0;
        // Satzende, das der Mitte am naechsten liegt
        if (preg_match_all('/[.!?]\s+/u', $text, $treffer, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($treffer[0] as $t) {
                // preg gibt Byte-Positionen; in Zeichen umrechnen
                $pos = mb_strlen(substr($text, 0, (int)$t[1])) + 1;
                if ($bester === 0 || abs($pos - $mitte) < abs($bester - $mitte)) {
                    $bester = $pos;
                }
            }
        }
        if ($bester <= 0 || $bester >= mb_strlen($text)) {
            $bester = $mitte;   // kein Satzende gefunden: hart in der Mitte
        }
        $eins = trim(mb_substr($text, 0, $bester));
        $zwei = trim(mb_substr($text, $bester));
        return $zwei === '' ? [$eins] : [$eins, $zwei];
    }

    /** Die Stimme zum Tonfall. Namen des Modells gpt-4o-mini-tts. */
    private function BriefingVoice(): string
    {
        switch ((string)$this->BriefingProp('BriefingTone', 'neutral')) {
            case 'formal': return 'sage';
            // „fable" ist die britisch gefaerbte Stimme des Modells — beim Butler
            // traegt sie den Akzent, den die Anweisung unten verlangt.
            case 'butler': return 'fable';
            case 'buddy':  return 'nova';
            // Weicht auf eine ausdrucksstarke Stimme aus, weil „fable" jetzt dem
            // Butler gehoert.
            case 'funny':  return 'ash';
            case 'drill':  return 'onyx';
            // Deutlich weiblich; „coral" klang dafuer zu neutral.
            case 'coach':  return 'shimmer';
            default:       return 'alloy';
        }
    }

    /** Wie vorgetragen wird — das Gegenstueck zu BriefingToneRule fuer die Stimme. */
    private function BriefingSpeechStyle(): string
    {
        $basis = 'Sprich Deutsch. Lies eine Tagesuebersicht fuer eine Familie vor. '
            . 'Uhrzeiten und Namen deutlich, keine Satzzeichen vorlesen. ';
        switch ((string)$this->BriefingProp('BriefingTone', 'neutral')) {
            case 'formal':
                return $basis . 'Vortrag: zurueckhaltend und hoeflich wie ein Butler, '
                    . 'ruhiges Tempo, klare Aussprache, keine Ausrufe.';
            case 'butler':
                return 'Sprich Deutsch mit einem deutlich hoerbaren britisch-englischen '
                    . 'Akzent, wie ein englischer Butler, der seit Jahren in einem deutschen '
                    . 'Haus dient: gemessenes Tempo, sehr hoefliche, fast singende Betonung, '
                    . 'jedes Wort sauber artikuliert, kleine Pausen vor Hoeflichkeitsfloskeln. '
                    . 'Niemals laut, niemals hastig. Uhrzeiten und Namen deutlich.';
            case 'buddy':
                return $basis . 'Vortrag: locker und beilaeufig, wie zu einem Freund am '
                    . 'Kuechentisch, mittleres Tempo, freundlich.';
            case 'funny':
                return $basis . 'Vortrag: mit Augenzwinkern, leicht spoettisch, kleine Pausen '
                    . 'vor den Pointen.';
            case 'drill':
                return 'Sprich Deutsch und BRUELLE wie ein Drill-Sergeant auf dem Kasernenhof: '
                    . 'sehr laut, hart, abgehackt, hohes Tempo, scharfe Kommandobetonung, '
                    . 'Grossbuchstaben schreist du heraus. Keine Freundlichkeit, kein Laecheln '
                    . 'in der Stimme, keine Pausen zum Verschnaufen. Uhrzeiten und Namen '
                    . 'trotzdem deutlich.';
            case 'coach':
                return $basis . 'Sprich mit WEIBLICHER Stimme. Vortrag: energisch und '
                    . 'anfeuernd wie eine Motivationstrainerin, hohes Tempo, aufbauende '
                    . 'Betonung, waermer werdend zum Schluss.';
            default:
                return $basis . 'Vortrag: sachlich und freundlich, mittleres Tempo, ohne Ueberschwang.';
        }
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
        $clips = [];
        foreach ((array)($stand['clips'] ?? []) as $h) {
            if (is_string($h) && preg_match('/^[a-f0-9]{32}$/', $h) === 1) {
                $clips[] = $h;
            }
        }
        return ['ok' => true, 'briefing' => [
            'text'        => $text,
            'date'        => (string)($stand['d'] ?? ''),
            'generatedAt' => (int)($stand['at'] ?? 0),
            'userId'      => (string)($stand['userId'] ?? ''),
            // Fertige Tonschnipsel in Spielreihenfolge; leer = die Oberflaeche
            // muss selbst erzeugen (Sprachausgabe war beim Schreiben nicht bereit).
            'clips'       => $clips,
        ]];
    }
}

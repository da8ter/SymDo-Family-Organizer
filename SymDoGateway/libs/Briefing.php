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
    /**
     * Vorlesetempo, 1.0 waere die normale Sprechgeschwindigkeit des Modells.
     * Schneller, weil man ein Briefing morgens im Vorbeigehen hoert und nicht als
     * Hoerbuch — aber nicht zu schnell: 1.2 war einen Hauch gehetzt, deshalb 5 %
     * zurueck. Der Wert reist im Cache-Schluessel mit, damit nach einer Aenderung
     * nicht die alte Aufnahme im alten Tempo weiterspielt.
     */
    private const BRIEFING_TTS_SPEED  = 1.14;

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
        // Abendliche Vorschau auf morgen: eigener Schalter, eigene Uhrzeit. Ab dieser
        // Zeit zeigen die Oberflaechen den morgigen Text statt des heutigen — der Tag
        // ist dann gelaufen, und was zaehlt, ist der naechste.
        // Sprachausgabe des Briefings: an, weil sie den Nutzen ausmacht — aber
        // abschaltbar, denn sie kostet je Tag ein Mehrfaches des Textes.
        $this->RegisterPropertyBoolean('BriefingAudioEnabled', true);
        $this->RegisterPropertyBoolean('BriefingPreviewEnabled', false);
        $this->RegisterPropertyString('BriefingPreviewFrom', '{"hour":18,"minute":0,"second":0}');
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
        if ($ident === 'BriefingPreviewTomorrow') {
            $ergebnis = $this->BriefingPreview(1);
            $this->UpdateFormField('BriefingStatus', 'caption', $ergebnis['ok']
                ? $ergebnis['text']
                : sprintf($this->Translate('Preview failed: %s'), (string)$ergebnis['message']));
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

    /**
     * Der ganze Stand: zwei Faecher plus Fehlerzaehler.
     *
     * `heute` traegt das Briefing des laufenden Tages, `vorschau` das auf morgen.
     * Beide in derselben Form: d (Tag), text, at, userId, clips.
     *
     * @return array<string, mixed>
     */
    private function BriefingStore(): array
    {
        $stand = json_decode($this->BriefingRaw(), true);
        if (!is_array($stand)) {
            return [];
        }
        // Alter, flacher Stand aus der Zeit vor der Vorschau: als Fach „heute" lesen,
        // statt ihn zu verwerfen — sonst waere die Karte bis zum naechsten Lauf leer.
        if (array_key_exists('text', $stand) && !array_key_exists('heute', $stand)) {
            $stand = [
                'heute'   => $stand,
                'failDay' => (string)($stand['failDay'] ?? ''),
                'fails'   => (int)($stand['fails'] ?? 0),
            ];
        }
        return $stand;
    }

    /**
     * Ein Fach. `$tage` 0 = heute, 1 = die Vorschau auf morgen.
     *
     * @return array{d: string, text: string, at: int, userId: string, clips: list<string>}
     */
    private function BriefingSlot(int $tage): array
    {
        $fach = $this->BriefingStore()[$tage === 0 ? 'heute' : 'vorschau'] ?? [];
        $clips = [];
        foreach ((array)($fach['clips'] ?? []) as $h) {
            if (is_string($h) && preg_match('/^[a-f0-9]{32}$/', $h) === 1) {
                $clips[] = $h;
            }
        }
        return [
            'd'      => (string)($fach['d'] ?? ''),
            'text'   => trim((string)($fach['text'] ?? '')),
            'at'     => (int)($fach['at'] ?? 0),
            'userId' => (string)($fach['userId'] ?? ''),
            'clips'  => $clips,
        ];
    }

    private function BriefingWriteSlot(int $tage, array $inhalt): bool
    {
        $stand = $this->BriefingStore();
        $stand[$tage === 0 ? 'heute' : 'vorschau'] = $inhalt;
        $ok = $this->BriefingWriteStore($stand);

        // Benachrichtigung nur fuer das Fach HEUTE: Die Vorschau entsteht am
        // Vorabend, ein „Dein Tag" um 18 Uhr waere verkehrt. Und nur, wenn der Text
        // wirklich abgelegt wurde — eine Meldung ueber etwas, das die Oberflaechen
        // nicht zeigen koennen, waere schlimmer als keine.
        if ($ok && $tage === 0 && (string)($inhalt['text'] ?? '') !== '') {
            $this->BriefingNotify((string)$inhalt['text']);
        }
        return $ok;
    }

    /**
     * Kurze Nachricht, wenn das Briefing des Tages geschrieben ist. Titel wie die
     * Karte in den Oberflaechen, Text der erste Satz.
     */
    private function BriefingNotify(string $text): void
    {
        if (!(bool)$this->PushProp('PushOnBriefing', false)) {
            return;
        }
        $viele = count($this->BriefingMembers()) > 1;
        $titel = $viele ? $this->Translate('Your day (plural)') : $this->Translate('Your day');
        // Ganze Saetze, nicht die ersten 256 Zeichen: Ein Schnitt mitten im Wort
        // liest sich auf dem Sperrbildschirm wie ein Fehler. Ein Satz allein genuegt
        // aber nicht — der erste ist oft nur „Guten Morgen, Max!" und sagt nichts.
        // Deshalb weitersammeln, bis der Text etwas traegt.
        $kurz = '';
        foreach ((preg_split('/(?<=[.!?])\s+/u', trim($text)) ?: []) as $satz) {
            $kurz = $kurz === '' ? (string)$satz : $kurz . ' ' . (string)$satz;
            // 60 Zeichen: Ein inhaltsvoller Satz steht damit allein, ein blosser
            // Gruss („Guten Morgen, Max!") bekommt den naechsten dazu.
            if (mb_strlen($kurz) >= 60) {
                break;
            }
        }
        // Bewusst an alle: Das Briefing erzaehlt vom ganzen Haushalt, auch wenn es
        // eine Person anspricht. Wer nur seine eigenen Nachrichten will, ordnet sein
        // Geraet einem Mitglied zu — dann kommt diese hier nicht an.
        $this->PushBroadcast($titel, $kurz === '' ? $text : $kurz, '', 'dashboard');
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
    /** Beim Einwilligungs-Widerruf: beide Faecher, heute und Vorschau. */
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
        return $this->BriefingTimeOf('BriefingTime', 5, 30);
    }

    /** Zeit der abendlichen Vorschau. */
    private function BriefingPreviewTime(): array
    {
        return $this->BriefingTimeOf('BriefingPreviewFrom', 18, 0);
    }

    /** @return array{0: int, 1: int} */
    private function BriefingTimeOf(string $property, int $stdVorgabe, int $minVorgabe): array
    {
        $zeit = json_decode((string)$this->BriefingProp($property, ''), true);
        $std  = is_array($zeit) ? (int)($zeit['hour'] ?? $stdVorgabe) : $stdVorgabe;
        $min  = is_array($zeit) ? (int)($zeit['minute'] ?? $minVorgabe) : $minVorgabe;
        return [max(0, min(23, $std)), max(0, min(59, $min))];
    }

    private function BriefingPreviewIsEnabled(): bool
    {
        return $this->BriefingIsEnabled() && (bool)$this->BriefingProp('BriefingPreviewEnabled', false);
    }

    /**
     * Das Fach, das die Oberflaechen JETZT zeigen: 1 = die Vorschau auf morgen,
     * 0 = heute. Eine Stelle fuer die Bedingung, damit Timer, Route und der Knopf
     * im Formular nicht auseinanderlaufen.
     */
    private function BriefingShownSlot(): int
    {
        return ($this->BriefingPreviewIsEnabled() && time() >= $this->BriefingPreviewStart()) ? 1 : 0;
    }

    /** Zeitpunkt heute, ab dem die Vorschau gilt. */
    private function BriefingPreviewStart(): int
    {
        [$std, $min] = $this->BriefingPreviewTime();
        return (int)mktime($std, $min, 0, (int)date('n'), (int)date('j'), (int)date('Y'));
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
        $jetzt = time();

        // Nachholen: Ist ein Fach ueberfaellig und leer, nicht bis zur naechsten
        // Zielzeit warten. Sonst blieb die Karte bis morgen leer, wenn der Schalter
        // ERST NACH der Zielzeit gesetzt wurde — genau so gesehen, als die Vorschau
        // um 16:00 eingeschaltet wurde und der Timer auf 5:30 des Folgetags stand.
        //
        // Der Tages-Fehlerzaehler ist die Bremse: Ohne ihn wuerde ein nicht
        // erreichbarer Anbieter daraus eine Schleife im Minutentakt machen, denn
        // das Fach bliebe leer und waere damit dauerhaft „faellig".
        $stand   = $this->BriefingStore();
        $fehlerHeute = ((string)($stand['failDay'] ?? '') === date('Y-m-d')) ? (int)($stand['fails'] ?? 0) : 0;
        if ($fehlerHeute < self::BRIEFING_FAIL_MAX && $this->BriefingDueSlot() !== null) {
            return 60000;
        }

        $ziele = [$this->BriefingNextAt($this->BriefingTargetTime(), $jetzt)];
        if ($this->BriefingPreviewIsEnabled()) {
            $ziele[] = $this->BriefingNextAt($this->BriefingPreviewTime(), $jetzt);
        }
        $ziel = min($ziele);
        // Mindestabstand, damit ein Grenzfall (Zielzeit genau jetzt) den Timer
        // nicht in eine Schleife aus Sofortlaeufen schickt.
        return max(60000, ($ziel - $jetzt) * 1000);
    }

    /** Naechstes Auftreten einer Tageszeit, heute oder morgen. */
    private function BriefingNextAt(array $zeit, int $jetzt): int
    {
        [$std, $min] = $zeit;
        $heute = mktime($std, $min, 0, (int)date('n'), (int)date('j'), (int)date('Y'));
        if ($heute === false) {
            return $jetzt + 3600;
        }
        // strtotime rechnet ueber die Zeitumstellung richtig, +86400 nicht.
        return $heute > $jetzt ? (int)$heute : (int)strtotime('+1 day', (int)$heute);
    }

    // ────────────────────────────── Lauf ──────────────────────────────

    /**
     * Der Timer-Lauf. EIN Fach pro Lauf, danach der Folgetermin.
     *
     * Die Vorschau hat Vorrang, sobald ihre Zeit erreicht ist: Von da an zeigen die
     * Oberflaechen sie, ein dann noch fehlendes Briefing fuer heute waere umsonst
     * bezahlt.
     */
    private function BriefingRun(): void
    {
        $tage = $this->BriefingDueSlot();
        if ($tage === null) {
            $this->BriefingArm();
            return;
        }
        $ergebnis = $this->BriefingErzeugen(false, $tage);
        // Fehlversuch: in einer halben Stunde erneut, hoechstens BRIEFING_FAIL_MAX
        // mal. Ein dauerhaft nicht erreichbarer Anbieter soll nicht alle 30 Minuten
        // einen Fehler ins Protokoll schreiben.
        $wiederholen = !$ergebnis['ok']
            && ($ergebnis['retry'] ?? false)
            && (int)($this->BriefingStore()['fails'] ?? 0) < self::BRIEFING_FAIL_MAX;
        $this->BriefingArm($wiederholen ? self::BRIEFING_RETRY_MS : null);
    }

    /**
     * Welches Fach ist jetzt zu erzeugen? null = nichts zu tun.
     *
     * Selbstheilend: Gepruefft wird nicht „ist die Uhrzeit gerade jetzt", sondern
     * „ist die Zeit vorbei und das Fach leer". Ein verpasster Lauf (Neustart,
     * Anbieter weg) wird so beim naechsten Anlauf nachgeholt.
     */
    private function BriefingDueSlot(): ?int
    {
        $jetzt = time();
        if ($this->BriefingShownSlot() === 1) {
            $fach = $this->BriefingSlot(1);
            if ($fach['d'] !== date('Y-m-d', $this->BriefingDay(1)) || $fach['text'] === '') {
                return 1;
            }
        }
        if (!$this->BriefingIsEnabled()) {
            return null;
        }
        [$std, $min] = $this->BriefingTargetTime();
        $heuteZiel = (int)mktime($std, $min, 0, (int)date('n'), (int)date('j'), (int)date('Y'));
        if ($jetzt >= $heuteZiel) {
            $fach = $this->BriefingSlot(0);
            if ($fach['d'] !== date('Y-m-d') || $fach['text'] === '') {
                return 0;
            }
        }
        return null;
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
        // Am Abend zeigen die Oberflaechen die Vorschau auf morgen. Dann muss der
        // Knopf genau die erneuern — das Briefing von heute liest um 18 Uhr niemand
        // mehr, und fuer die Vorschau gab es sonst keinen Weg, der auch ablegt
        // (BriefingPreviewTomorrow zeigt nur an).
        $tage     = $this->BriefingShownSlot();
        $ergebnis = $this->BriefingErzeugen(true, $tage);
        if ($ergebnis['ok']) {
            $text = (string)$this->BriefingSlot($tage)['text'];
            $meldung($tage === 1
                ? sprintf($this->Translate('Preview for tomorrow: %s'), $text)
                : $text);
            return;
        }
        $meldung(sprintf($this->Translate('Briefing failed: %s'), (string)$ergebnis['message']));
    }

    /**
     * @return array{ok: bool, message: string, retry: bool}
     */
    private function BriefingErzeugen(bool $handanstoss, int $tage = 0): array
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
            return $this->BriefingSchritt($handanstoss, $tage);
        } finally {
            IPS_SemaphoreLeave($riegel);
        }
    }

    /** @return array{ok: bool, message: string, retry: bool} */
    private function BriefingSchritt(bool $handanstoss, int $tage = 0): array
    {
        $heute   = date('Y-m-d');
        $zielTag = date('Y-m-d', $this->BriefingDay($tage));

        // Erst pruefen, ob das Ergebnis ueberhaupt ablegbar ist — ein Anbieter-
        // aufruf ins Leere kostet Geld und der Text waere danach weg.
        if (!$this->BriefingStorable()) {
            return ['ok' => false, 'message' => 'attribute_unwritable', 'retry' => false];
        }
        $fach = $this->BriefingSlot($tage);
        if (!$handanstoss && $fach['d'] === $zielTag && $fach['text'] !== '') {
            $this->SendDebug('Briefing', 'fuer ' . $zielTag . ' liegt schon eines vor', 0);
            return ['ok' => true, 'message' => 'already_done', 'retry' => false];
        }

        $daten   = $this->BriefingCollect($tage);
        $antwort = $this->AiRunCompletion(
            $this->BriefingSystemPrompt($this->BriefingDayWord($tage)),
            $this->BriefingUserText($daten),
            null
        );
        $fehlschlag = function (string $code) use ($heute): array {
            $stand = $this->BriefingStore();
            $stand['fails']   = (($stand['failDay'] ?? '') === $heute ? (int)($stand['fails'] ?? 0) : 0) + 1;
            $stand['failDay'] = $heute;
            $this->BriefingWriteStore($stand);
            return ['ok' => false, 'message' => $code, 'retry' => true];
        };
        if (!(bool)($antwort['ok'] ?? false)) {
            $code = (string)($antwort['code'] ?? 'ai_error');
            $this->SendDebug('Briefing', 'Anbieter meldet ' . $code, 0);
            return $fehlschlag($code);
        }

        $text = $this->BriefingTidy((string)($antwort['text'] ?? ''));
        if ($text === '') {
            return $fehlschlag('ai_empty');
        }

        $neu = [
            'd'      => $zielTag,
            'text'   => $text,
            'at'     => time(),
            'userId' => (string)$daten['userId'],
            // Der Ton entsteht JETZT, nicht beim Tippen auf Vorlesen: Die Aufnahme
            // braucht gemessen um zehn Sekunden — die soll niemand vor einem
            // stummen Knopf abwarten.
            'clips'  => $this->BriefingAudio($text),
        ];
        if (!$this->BriefingWriteSlot($tage, $neu)) {
            return ['ok' => false, 'message' => 'attribute_unwritable', 'retry' => false];
        }
        // Die Oberflaechen holen den Text beim naechsten „irgendetwas hat sich
        // geaendert" — ein eigener Kanal waere fuer einen Text pro Tag zu viel.
        $this->WsPushDirty();
        $this->SendDebug('Briefing', sprintf('erzeugt fuer %s, %d Zeichen', $zielTag, mb_strlen($text)), 0);
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
    private function BriefingCollect(int $tage = 0): array
    {
        $mitglieder = $this->BriefingMembers();
        $userId     = $this->BriefingUserId();

        return [
            'userId'       => $userId,
            'name'         => (string)($mitglieder[$userId]['name'] ?? ''),
            'tage'         => $tage,
            'termine'      => $this->BriefingEventLines($mitglieder, $tage),
            'aufgaben'     => $this->BriefingTaskLines($mitglieder, false, $tage),
            'ueberfaellig' => $this->BriefingTaskLines($mitglieder, true, $tage),
            'geburtstage'  => $this->BriefingBirthdayLines($mitglieder, $tage),
            'rollen'       => $this->BriefingRoleLines($mitglieder),
            'einkauf'      => $this->BriefingShopping(),
        ];
    }

    /** Der betrachtete Tag als Zeitstempel (0 = heute). */
    private function BriefingDay(int $tage): int
    {
        return (int)strtotime(($tage === 0 ? 'today' : ($tage > 0 ? "+$tage days" : "$tage days")) . ' 00:00');
    }

    /** „heute", „morgen" — oder das Datum, wenn es weiter weg liegt. */
    private function BriefingDayWord(int $tage): string
    {
        if ($tage === 0) {
            return 'heute';
        }
        if ($tage === 1) {
            return 'morgen';
        }
        return 'am ' . date('d.m.', $this->BriefingDay($tage));
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
    private function BriefingEventLines(array $mitglieder, int $tage = 0): array
    {
        $von = $this->BriefingDay($tage);
        $bis = (int)strtotime('+1 day', $von);
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
            $start    = (int)($e['start'] ?? 0);
            $ende     = (int)($e['end'] ?? 0);
            $ganztags = (bool)($e['allDay'] ?? false);

            // Gehoert der Termin ueberhaupt zu DIESEM Tag? OpenCalendar liefert alles,
            // was das Tagesfenster irgendwie beruehrt, und das sind zwei Sorten
            // Fehlalarm.
            //
            // Ganztaegige Termine enden ausschliesslich: Der „Wandertag" am 20.08. traegt
            // als Ende den Zeitstempel 21.08. 00:00 und gehoert trotzdem nicht zum 21.
            //
            // Termine mit Uhrzeit gehoeren zu dem Tag, an dem sie BEGINNEN. Ohne diese
            // Regel wandert jeder mehrtaegige Block in jedes Briefing: am 20.08.2026
            // lieferte OpenCalendar „Powerfit 3" als EINEN Datensatz von Do 20.08. 18:45
            // bis Do 03.09. 19:45 — drei Donnerstagstermine zu einem Block verschmolzen
            // (die Einzeltermine am 27.08. und 03.09. fehlten dafuer). Der Kurs ist
            // donnerstags; im Briefing fuer Freitag hatte er nichts zu suchen.
            if ($ganztags) {
                if ($ende <= $von || $start >= $bis) {
                    continue;
                }
                $zeit = 'ganztägig';
            } else {
                if ($start < $von || $start >= $bis) {
                    continue;
                }
                if ($ende <= $start) {
                    $zeit = 'um ' . date('H:i', $start) . ' Uhr';
                } elseif ((int)date('Ymd', $ende) === (int)date('Ymd', $start)) {
                    $zeit = 'von ' . date('H:i', $start) . ' bis ' . date('H:i', $ende) . ' Uhr';
                } else {
                    // Endet der Datensatz an einem anderen Tag, waere „bis 19:45 Uhr"
                    // eine Erfindung — dann nur der Beginn und das Enddatum.
                    $zeit = 'ab ' . date('H:i', $start) . ' Uhr, bis ' . date('j.n.', $ende);
                }
            }

            // Jede Zeile steht fuer sich: Titel in Anfuehrungszeichen, dann die
            // Zeitangabe, dann die Personen ausgeschrieben. Standen die Angaben nur
            // nebeneinander, zog das Modell Attribute von einer Zeile auf die naechste.
            $zeile = 'Termin „' . trim((string)($e['title'] ?? '')) . '", ' . $zeit;
            $wer = $this->BriefingNames((array)($e['members'] ?? []), $mitglieder);
            if ($wer !== '') {
                $zeile .= ', für ' . $wer;
            }
            $ort = trim((string)($e['location'] ?? ''));
            if ($ort !== '') {
                $zeile .= ', Ort ' . $ort;
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
    private function BriefingTaskLines(array $mitglieder, bool $ueberfaellig, int $tage = 0): array
    {
        $heuteStart = $this->BriefingDay($tage);
        $heuteEnde  = (int)strtotime('+1 day', $heuteStart);
        // Fuer heute zaehlt die Uhr: eine Frist um 9 Uhr ist mittags abgelaufen.
        // Fuer einen kuenftigen Tag zaehlt der Tagesbeginn — dort ist noch nichts
        // abgelaufen, was an diesem Tag erst faellig wird.
        $jetzt = $tage === 0 ? time() : $heuteStart;

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
                $titel = trim((string)($it['title'] ?? ''));
                if ($titel === '') {
                    continue;
                }
                $zeile = 'Aufgabe „' . $titel . '"';
                // Die Uhrzeit einer Aufgabe ist eine FRIST, kein Beginn. Vorangestellt
                // („16:30 Formular abgeben") liest das Modell sie als Termin und schreibt
                // „um 16:30 Uhr das Formular abgeben" — deshalb steht sie ausgeschrieben
                // dahinter.
                if ($ueberfaellig) {
                    $abgelaufen = (int)floor(($heuteStart - (int)strtotime(date('Y-m-d', $due))) / 86400);
                    $zeile .= $abgelaufen > 0
                        ? sprintf(', Frist vor %d Tag(en) abgelaufen', $abgelaufen)
                        : ', Frist vorhin abgelaufen';
                } else {
                    // Das Tageswort MUSS mitwandern: „Frist heute bis 08:00" liess das
                    // Modell in der Vorschau auf morgen „heute noch" schreiben.
                    $wort = $this->BriefingDayWord($tage);
                    $zeile .= $istAllDay
                        ? ', Frist ' . $wort
                        : ', Frist ' . $wort . ' bis ' . date('H:i', $due) . ' Uhr';
                }
                $wer = $this->BriefingNames((array)($it['assignedTo'] ?? []), $mitglieder);
                if ($wer !== '') {
                    $zeile .= ', für ' . $wer;
                }
                if ((string)($it['priority'] ?? '') === 'high') {
                    $zeile .= ', wichtig';
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
    private function BriefingBirthdayLines(array $mitglieder, int $tage = 0): array
    {
        $tag = $this->BriefingDay($tage);
        $m = (int)date('n', $tag);
        $t = (int)date('j', $tag);
        $j = (int)date('Y', $tag);

        $raus = [];
        foreach ($mitglieder as $mitglied) {
            $g = $mitglied['birthday'];
            if ($g['m'] !== $m || $g['d'] !== $t || $g['m'] === 0) {
                continue;
            }
            $wort = $this->BriefingDayWord($tage);
            $raus[] = $g['y'] > 0 && $g['y'] < $j
                ? sprintf('%s wird %s %d', $mitglied['name'], $wort, $j - $g['y'])
                : sprintf('%s hat %s Geburtstag', $mitglied['name'], $wort);
        }
        return $raus;
    }

    /**
     * Rollen im Haushalt. Wer keine hat, wird ausdruecklich als „ohne Angabe"
     * gemeldet: Der Butler spricht Eltern und Kinder verschieden an, und ohne
     * diesen Hinweis raet das Modell die Rolle aus dem Namen.
     *
     * @return list<string>
     */
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
            $raus[] = $mitglied['name'] . ' = ' . ($rolle !== '' ? $rolle : 'ohne Angabe');
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
    private function BriefingSystemPrompt(string $tagWort = 'heute'): string
    {
        return 'Du schreibst das Tagesbriefing für eine Familie in einer Haushalts-App. '
            . 'Fasse den Tag (' . $tagWort . ') in zwei bis fünf Sätzen zusammen — durchgehender '
            . 'Fließtext, KEINE Aufzählung, keine Zwischentitel, kein Markdown. '
            . 'Schreibe korrektes Deutsch mit Umlauten und ß: „Fußballtraining", nicht '
            . '„Fussballtraining". Die Angaben unten sind teils ohne Umlaute geschrieben — '
            . 'setze sie in deinem Text richtig. '
            . 'Sprich die angesprochene Person mit ihrem Vornamen an, wenn einer genannt ist. '
            . 'Das Briefing ist der Überblick für den GANZEN Haushalt: Sage auch, was bei den '
            . 'anderen Familienmitgliedern ansteht, nicht nur bei der angesprochenen Person. '
            . 'Hinter „für" stehen die Familienmitglieder, zu denen ein Eintrag gehört. '
            . 'Nenne diese Namen IM SATZ und niemals in Klammern nachgestellt: aus '
            . '„Aufgabe „Vokabeln üben", Frist heute bis 18:00 Uhr, für Mia und Tim" wird '
            . '„Mia und Tim üben bis 18 Uhr Vokabeln". Stehen dort MEHRERE Namen, nenne sie '
            . 'ALLE. Lass keinen Namen weg und ordne keinen Eintrag jemandem zu, der nicht '
            . 'dahinter steht. Jede Zeile ist für sich zu lesen — übertrage keine Uhrzeit '
            . 'und kein „ganztägig" von einem Eintrag auf einen anderen. '
            . 'Uhrzeiten übernimmst du unverändert. UNTERSCHEIDE dabei streng: Bei einem '
            . 'TERMIN ist die Uhrzeit der Beginn („um 15:00 Uhr ist Fußballtraining"). Bei '
            . 'einer AUFGABE ist die Uhrzeit eine FRIST — bis wann etwas fertig sein muss, '
            . 'nicht wann man damit anfängt. Formuliere sie deshalb als Frist: „bis 16:30 '
            . 'Uhr", „spätestens um 16:30 Uhr", „heute noch". Schreibe NIE „um 16:30 Uhr '
            . 'den Zahnarzttermin bestätigen", als wäre die Aufgabe ein Termin. '
            . 'VOLLSTÄNDIGKEIT: Jeder Termin und jede Aufgabe aus den Angaben muss im Text '
            . 'vorkommen — zusammenfassen ist erlaubt, WEGLASSEN nicht. Nenne dabei jede '
            . 'Angabe nur einmal. '
            . 'Übernimm auch die Art genau: Was als „ganztägig" dasteht, ist ganztägig; '
            . 'was eine Uhrzeit hat, ist es nicht — und dessen Uhrzeit MUSS im Text stehen. '
            . 'Schreibe also nie „den ganzen Tag", wo eine Uhrzeit angegeben ist. '
            . 'Erfinde NICHTS: keine Termine, keine Aufgaben, keine Uhrzeiten, die unten nicht '
            . 'stehen — und nichts für andere Tage, es geht ausschließlich um ' . $tagWort . '. '
            . 'Steht nichts an, sag das in einem Satz. '
            . 'Hat jemand Geburtstag, gratuliere ihm zuerst. '
            . 'Steht unten eine Einkaufsliste mit Artikelzahl, weise am Ende darauf hin, '
            . 'dass sich eine Einkaufstour lohnen würde, und nenne die Zahl. '
            . 'Schließe mit einem kurzen Wunsch für einen erfolgreichen Tag. '
            // Die Vorschau wird am Vorabend gelesen. „es geht ausschliesslich um morgen"
            // allein genuegte nicht: Beim Ausprobieren wurde aus einer Frist fuer morgen
            // ein „heute unbedingt unterschreiben".
            . ($tagWort !== 'heute'
                ? 'ZEITBEZUG: Dieser Text wird am Abend VORHER gelesen. Schreibe deshalb '
                    . '„' . $tagWort . '" und niemals „heute" — was ' . $tagWort . ' ansteht, '
                    . 'steht nicht heute an. '
                : '')
            . $this->BriefingToneRule();
    }

    /** Der Tonfall aus den Einstellungen — ein Satz, der den Rest des Prompts einfaerbt. */
    private function BriefingToneRule(): string
    {
        switch ((string)$this->BriefingProp('BriefingTone', 'neutral')) {
            case 'formal':
                return 'TONFALL: Förmlich und zurückhaltend, wie ein Butler. Siez die Person, '
                    . 'keine Ausrufezeichen, keine Emojis.';
            case 'butler':
                // Nicht dasselbe wie „foermlich": Der ist knapp und sachlich, der Butler
                // ist ausgesucht umstaendlich — das ist der Witz daran. Die Anreden haengen
                // an den Rollen, die unter ROLLEN IM HAUSHALT im Nutzer-Teil stehen; ohne
                // Rolle muss eine neutrale Form her, sonst raet das Modell.
                return 'TONFALL: Du bist der Butler des Hauses und sprichst ausgesucht '
                    . 'gehoben. Siez jeden. Gewählte, leicht altmodische Wendungen und '
                    . 'Höflichkeitsfloskeln: „wenn ich mir die Bemerkung erlauben darf", '
                    . '„sehr wohl", „es würde mich freuen, wenn", „ich habe mir erlaubt, '
                    . 'darauf hinzuweisen". Nichts wird angetrieben, es wird respektvoll in '
                    . 'Erinnerung gerufen. Kein Ausrufezeichen, keine Umgangssprache, keine '
                    . 'Emojis. '
                    . 'ANREDEN richten sich nach der Rolle, die unter „ROLLEN IM HAUSHALT" '
                    . 'steht, und die Eltern werden höher angesprochen als die Kinder: '
                    . 'Vater und Mutter sind „Durchlaucht", „Eure Durchlaucht", „der Herr '
                    . 'des Hauses" bzw. „die gnädige Frau" — mit Ehrfurcht. Kinder sind '
                    . '„der junge Herr <Name>", „das gnädige Fräulein <Name>" oder „die '
                    . 'junge Herrschaft". Oma und Opa sind „die verehrte Frau Großmutter" '
                    . 'und „der ehrwürdige Herr Großvater", Onkel und Tante „der Herr '
                    . 'Onkel" und „die Frau Tante". Steht bei einem Namen KEINE Rolle, '
                    . 'bleibt es beim neutral höflichen „Herr <Name>" oder „Frau <Name>" — '
                    . 'rate nicht. Alle gemeinsam sind „die Herrschaften". '
                    . 'Die Angaben bleiben vollständig und korrekt — Umständlichkeit '
                    . 'ersetzt keine Uhrzeit.';
            case 'buddy':
                return 'TONFALL: Wie ein guter Kumpel — locker, duzend, kurze Sätze, ruhig mal '
                    . 'ein umgangssprachlicher Ausdruck. Keine Emojis.';
            case 'funny':
                // Ausdruecklich so gewollt (Wunsch vom 20.08.2026): richtig lustig,
                // nicht bloss augenzwinkernd. Die Beispiele sind Beispiele — steht
                // „Deutsche Bahn" im Prompt, kommt sie sonst jeden Tag vor.
                return 'TONFALL: Du bist der Haus-Komiker und dein Briefing soll die Familie '
                    . 'zum Lachen bringen. Frech, schlagfertig, respektlos-liebevoll — du '
                    . 'darfst die Leute aufziehen und veräppeln. '
                    . 'ERLAUBT UND ERWÜNSCHT: mit den Namen spielen (Wortwitz, Reim, '
                    . 'Spitzname, „Turnbeutel-Tim"), übertriebene Vergleiche für die Lage '
                    . 'ziehen (überfällige Aufgaben wie ein Zug, der noch nie pünktlich war; '
                    . 'ein Faultier, das dagegen hektisch wirkt; ein Termin, der schon '
                    . 'Anspruch auf Rente hat), kleine Spitzen gegen den Alltagswahnsinn, '
                    . 'ironische Übertreibung, ein trockener Rausschmeißer am Ende. '
                    . 'Denk dir die Vergleiche jedes Mal NEU aus — die genannten sind nur '
                    . 'Muster, nicht die Liste. Ein Gag pro Punkt genügt, gehetzt ist nicht '
                    . 'komisch. '
                    . 'GRENZE: Es wird gefrotzelt, nicht getreten. Nichts über Aussehen, '
                    . 'Gewicht, Herkunft, Geschlecht, Krankheit oder Fähigkeiten eines '
                    . 'Menschen, keine Schimpfwörter, nichts, was ein Kind verletzen würde. '
                    . 'Und der Witz baut auf den Angaben auf, statt sie zu ersetzen: Termine, '
                    . 'Aufgaben, Namen und Uhrzeiten bleiben vollständig und korrekt. Auch die '
                    . 'Rollen bleiben, wie sie dastehen — wer einem Termin zugeordnet ist, ist '
                    . 'dabei und wird nicht zum Zuschauer umgedichtet (beim Ausprobieren wurde '
                    . 'aus einem Turnierteilnehmer der „offizielle Anfeuerer").';
            case 'drill':
                // Bewusst grob, aber im Rahmen: Klischee-Kaserne aus dem Kino, nicht
                // echte Herabsetzung. Was Aussehen, Herkunft oder Person angreift,
                // bleibt draussen — den Rest hat der Nutzer ausdruecklich so gewollt.
                return 'TONFALL: Du bist ein Drill-Sergeant aus einem Hollywood-Armeefilm und '
                    . 'brüllst die Truppe aus den Federn. Knallharte Befehlssätze, kurz und '
                    . 'laut, Großbuchstaben für einzelne Worte, gern eine Anrede wie '
                    . '„Rekrut", „Truppe" oder „Maden". Vorwurfsvoll und respektlos bis an die '
                    . 'Grenze des Klischees: „Stell dich nicht so an", „du Lusche", „das ist '
                    . 'ja jämmerlich", „bewegt euch" gehören ausdrücklich dazu. Übertreib '
                    . 'ruhig — es ist ein Gag, den sich die Familie selbst ausgesucht hat. '
                    . 'Verboten bleibt nur, was wirklich verletzt: nichts über Aussehen, '
                    . 'Gewicht, Herkunft, Geschlecht oder Fähigkeiten eines Menschen, keine '
                    . 'Schimpfwörter unter der Gürtellinie, keine Drohungen. Die Angaben '
                    . '(Termine, Aufgaben, Uhrzeiten) bleiben trotz allem vollständig und '
                    . 'korrekt.';
            case 'coach':
                return 'TONFALL: Du bist Motivationstrainerin und feuerst die Familie an. '
                    . 'Du-Form, kurze Sätze mit Schwung, ruhig ein Ausrufezeichen. Beginne '
                    . 'mit einem Lob und schließe mit einem Ansporn. Lobe konkret und nur, '
                    . 'was in den Angaben steht: wenig Liegengebliebenes, ein voller Tag, den '
                    . 'die Familie gemeinsam stemmt, jemand, der gleich zwei Sachen übernimmt. '
                    . 'Erfinde KEINE Erfolge und keine erledigten Aufgaben. Sprich schwierige '
                    . 'Dinge als machbar an („das räumst du heute weg"), statt sie '
                    . 'vorzuwerfen. Ein aufbauender Halbsatz je Person, wo es passt, und ein '
                    . 'Schlusssatz, der Lust auf den Tag macht.';
            case 'jammerlappen':
                // Gegenstueck zur Trainerin: die sieht alles als machbar, dieser Ton sieht
                // alles als zu viel. Der Witz ist das Uebertreiben, nicht das Herabsetzen —
                // bemitleidet wird die Familie, angegriffen wird niemand. Und die Grenze
                // ist eng gezogen: ein Briefing, das echte Aussichtslosigkeit verbreitet,
                // waere morgens das Letzte, was jemand hoeren will.
                return 'TONFALL: Du bist ein Jammerlappen. Dich deprimiert der Tag, und die '
                    . 'anstehenden Aufgaben überfordern dich vollkommen — schon das '
                    . 'Aufzählen fällt dir schwer. Du-Form, seufzende, ausschweifende '
                    . 'Sätze. '
                    // Ohne Deckel setzte das Modell „Ach" an jeden zweiten Satzanfang
                    // (gemessen: dreimal in einem Briefing von 900 Zeichen). Die
                    // Klage soll aus dem Satzbau kommen, nicht aus einem Flickwort.
                    . 'WICHTIG: Das Wort „ach" benutzt du HÖCHSTENS EINMAL im ganzen '
                    . 'Briefing, und niemals als Satzanfang zweimal hintereinander. '
                    . 'Verwende stattdessen abwechslungsreiche Klagen: „auch das noch", '
                    . '„wie soll das bloß gehen", „ich weiß ja nicht", „als ob das nicht '
                    . 'reichte", „und dann kommt noch dazu", „mir wird schon beim '
                    . 'Vorlesen schwer", „das schaffe ich nie alles aufzuzählen", '
                    . '„wenn ich das nur ansehe". Wiederhole keine dieser Wendungen. '
                    . 'Vor allem aber BEMITLEIDEST DU DIE FAMILIE: Jedes '
                    . 'Familienmitglied ist dir aufrichtig leid, und du sagst das auch — '
                    . '„der arme Max, schon wieder Training", „und die Mia muss das auch '
                    . 'noch alles schaffen", „ihr Ärmsten". Ein Halbsatz Mitleid je Person, '
                    . 'wo es passt. '
                    . 'ERLAUBT: Klagen über die Menge, die Uhrzeiten, das Wetter, das '
                    . 'Liegengebliebene, über dich selbst. Ein resignierter Schlusssatz. '
                    . 'VERBOTEN: echte Schwarzmalerei. Nichts über Krankheit, Geld­sorgen, '
                    . 'Tod, Trennung, Sinnlosigkeit des Lebens; keine Verzweiflung, die '
                    . 'jemandem den Tag verdirbt, kein Vorwurf an eine Person, niemand ist '
                    . 'schuld. Es ist ein Gag, den sich die Familie selbst ausgesucht hat — '
                    . 'jammerig, nicht hoffnungslos. '
                    . 'Und trotz allen Jammerns bleiben die Angaben vollständig und korrekt: '
                    . 'Termine, Aufgaben, Namen und Uhrzeiten stehen alle drin. Wer einem '
                    . 'Termin zugeordnet ist, bleibt zugeordnet.';
            default:
                return 'TONFALL: Sachlich und freundlich, du-Form, ohne Überschwang.';
        }
    }

    /** Der Nutzer-Teil: die gesammelten Daten, knapp und maschinennah aufgelistet. */
    private function BriefingUserText(array $daten): string
    {
        $wochentage = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
        $tag = $this->BriefingDay((int)($daten['tage'] ?? 0));
        $teile = [
            'TAG: ' . $wochentage[(int)date('w', $tag)] . ', ' . date('d.m.Y', $tag)
                . ' (' . $this->BriefingDayWord((int)($daten['tage'] ?? 0)) . ')',
            'BRIEFING FUER: ' . ($daten['name'] !== '' ? $daten['name'] : '(niemand bestimmter — schreibe ohne Anrede)'),
            'LESEHILFE: Jede Zeile beschreibt EINEN Eintrag vollständig. „für <Namen>" '
                . 'nennt die zugeordneten Familienmitglieder. Nimm keine Angabe von einer '
                . 'Zeile in eine andere mit.',
        ];
        $block = static function (string $titel, array $zeilen, string $leer): string {
            return $titel . ': ' . ($zeilen === [] ? $leer : "\n- " . implode("\n- ", $zeilen));
        };
        $teile[] = $block('TERMINE AN DIESEM TAG (Uhrzeit = Beginn)', $daten['termine'], 'keine');
        $teile[] = $block('AUFGABEN MIT FRIST AN DIESEM TAG (Uhrzeit = bis wann)', $daten['aufgaben'], 'keine');
        $teile[] = $block('AUFGABEN MIT ABGELAUFENER FRIST', $daten['ueberfaellig'], 'keine');
        if ($daten['geburtstage'] !== []) {
            $teile[] = $block('GEBURTSTAG AN DIESEM TAG', $daten['geburtstage'], '');
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
        if (!$this->BriefingAudioIsEnabled()) {
            $this->SendDebug('Briefing', 'Sprachausgabe aus oder nicht verfuegbar, kein Ton erzeugt', 0);
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
            $hash = $this->TtsHash($teil, $stimme, $anweisung, self::BRIEFING_TTS_SPEED);
            $mid  = $this->TtsLookup($hash);
            if ($mid <= 0) {
                $mid = $this->TtsProduce($hash, $teil, $stimme, $anweisung, 'aac', self::BRIEFING_TTS_SPEED);
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

    /**
     * Darf zum Briefing Ton entstehen und ausgeliefert werden?
     *
     * Zwei Bedingungen: der eigene Schalter und die Sprachausgabe selbst. Letztere
     * haengt am Anbieter OpenAI — mit Claude oder einem lokalen Modell gibt es
     * keine Stimme, und dann soll auch kein Knopf erscheinen, der nichts kann.
     */
    private function BriefingAudioIsEnabled(): bool
    {
        return (bool)$this->BriefingProp('BriefingAudioEnabled', true) && $this->TtsEnabled();
    }

    /** Die Stimme zum Tonfall. Namen des Modells gpt-4o-mini-tts. */
    private function BriefingVoice(): string
    {
        switch ((string)$this->BriefingProp('BriefingTone', 'neutral')) {
            case 'formal': return 'sage';
            case 'butler': return 'ash';
            case 'buddy':  return 'nova';
            case 'funny':  return 'fable';
            case 'drill':  return 'onyx';
            // Deutlich weiblich; „coral" klang dafuer zu neutral.
            case 'coach':  return 'shimmer';
            // Ebenfalls weiblich. Von den 13 Stimmen des Modells sind nur „nova" und
            // „shimmer" durchgaengig als weiblich beschrieben — die OpenAI-Doku selbst
            // sagt zum Geschlecht nichts. „nova" gilt als hell und energisch, „shimmer"
            // als weich und sanft; fuer einen muede-seufzenden Vortrag passt letztere.
            // Dass sie sich die Stimme mit der Trainerin teilt, faellt nicht auf: die
            // Anweisung in BriefingSpeechStyle macht daraus zwei ganz andere Vortraege,
            // und der Ton-Zwischenspeicher unterscheidet ohnehin nach Anweisung.
            case 'jammerlappen': return 'shimmer';
            default:       return 'alloy';
        }
    }

    /** Wie vorgetragen wird — das Gegenstueck zu BriefingToneRule fuer die Stimme. */
    private function BriefingSpeechStyle(): string
    {
        $basis = 'Sprich Deutsch. Lies eine Tagesübersicht für eine Familie vor. '
            . 'Uhrzeiten und Namen deutlich, keine Satzzeichen vorlesen. ';
        switch ((string)$this->BriefingProp('BriefingTone', 'neutral')) {
            case 'formal':
                return $basis . 'Vortrag: zurückhaltend und höflich wie ein Butler, '
                    . 'ruhiges Tempo, klare Aussprache, keine Ausrufe.';
            case 'butler':
                // Englisch formuliert und wörtlich so vom Nutzer vorgegeben: Die
                // Anweisung beschreibt die Sprechweise, nicht die Sprache — gelesen
                // wird weiter der deutsche Text.
                return "Speak as a classic, refined English butler.\n\n"
                    . "Use a polished British Received Pronunciation accent. The voice should "
                    . "sound male, mature, calm, intelligent, discreet, and highly professional.\n\n"
                    . "Speak at a measured, unhurried pace with precise articulation and "
                    . "controlled intonation. Keep the pitch moderately low and the delivery "
                    . "warm but reserved.\n\n"
                    . "Convey quiet confidence, impeccable manners, and subtle dry British wit. "
                    . "Sound attentive and helpful, never submissive or exaggerated.\n\n"
                    . "Use natural pauses between sentences. Emphasize important information "
                    . "gently rather than dramatically.\n\n"
                    . "Avoid sounding theatrical, cartoonish, aristocratic, overly posh, "
                    . "robotic, or like a movie trailer.\n\n"
                    . "The overall impression should be that of an experienced English household "
                    . "butler speaking personally to the owner of the house.";
            case 'buddy':
                return $basis . 'Vortrag: locker und beiläufig, wie zu einem Freund am '
                    . 'Küchentisch, mittleres Tempo, freundlich.';
            case 'funny':
                return $basis . 'Vortrag: gut gelaunt und spöttisch, mit hörbarer Freude an '
                    . 'der eigenen Pointe. Kleine Pausen vor den Gags, die Spitzen leicht '
                    . 'betont, am Ende ein Grinsen in der Stimme.';
            case 'drill':
                return 'Sprich Deutsch und BRÜLLE wie ein Drill-Sergeant auf dem Kasernenhof: '
                    . 'sehr laut, hart, abgehackt, hohes Tempo, scharfe Kommandobetonung, '
                    . 'Großbuchstaben schreist du heraus. Keine Freundlichkeit, kein Lächeln '
                    . 'in der Stimme, keine Pausen zum Verschnaufen. Uhrzeiten und Namen '
                    . 'trotzdem deutlich.';
            case 'coach':
                return $basis . 'Sprich mit WEIBLICHER Stimme. Vortrag: energisch und '
                    . 'anfeuernd wie eine Motivationstrainerin, hohes Tempo, aufbauende '
                    . 'Betonung, wärmer werdend zum Schluss.';
            case 'jammerlappen':
                return $basis . 'Sprich mit WEIBLICHER Stimme. Vortrag: müde und '
                    . 'niedergeschlagen, langsames Tempo, hörbare Seufzer zwischen den '
                    . 'Sätzen, die Stimme sinkt am Satzende ab und läuft aus. Klagend und '
                    . 'mitleidig, nie schrill und nie weinerlich übertrieben. Uhrzeiten und '
                    . 'Namen trotzdem deutlich.';
            default:
                return $basis . 'Vortrag: sachlich und freundlich, mittleres Tempo, ohne Überschwang.';
        }
    }

    /**
     * Vorschau für einen anderen Tag — erzeugt nur Text, legt NICHTS ab.
     *
     * Bewusst ohne Ablage und ohne Ton: Der abgelegte Stand gilt für heute, und die
     * Oberflächen zeigen ihn als das Briefing des Tages. Eine Vorschau, die ihn
     * überschreibt, würde morgen als heute ausgegeben.
     *
     * @return array{ok: bool, text: string, message: string}
     */
    private function BriefingPreview(int $tage): array
    {
        if (!$this->BriefingIsEnabled()) {
            return ['ok' => false, 'text' => '', 'message' => 'briefing_disabled'];
        }
        $daten   = $this->BriefingCollect($tage);
        $antwort = $this->AiRunCompletion(
            $this->BriefingSystemPrompt($this->BriefingDayWord($tage)),
            $this->BriefingUserText($daten),
            null
        );
        if (!(bool)($antwort['ok'] ?? false)) {
            return ['ok' => false, 'text' => '', 'message' => (string)($antwort['code'] ?? 'ai_error')];
        }
        $text = $this->BriefingTidy((string)($antwort['text'] ?? ''));
        return [
            'ok'      => $text !== '',
            'text'    => $text,
            'message' => $text !== '' ? 'ok' : 'ai_empty',
            // Die gesammelten Zeilen mitgeben: Weicht der Text von den Angaben ab,
            // ist die erste Frage immer, was ueberhaupt im Prompt stand.
            'daten'   => $daten,
        ];
    }

    // ────────────────────────────── Ausgabe ──────────────────────────────

    /** Der Text des heutigen Fachs, sonst leer. */
    private function BriefingText(): string
    {
        $fach = $this->BriefingSlot(0);
        return $fach['d'] === date('Y-m-d') ? $fach['text'] : '';
    }

    /**
     * Was die Oberflaechen zeigen sollen — und ob es heute oder morgen betrifft.
     *
     * Drei Faelle, in dieser Reihenfolge:
     *  1. Vorschau eingeschaltet, ihre Uhrzeit erreicht, Text fuer morgen da → morgen.
     *     Der Tag ist gelaufen; was zaehlt, ist der naechste.
     *  2. Nach Mitternacht traegt die Vorschau von gestern das Datum von HEUTE. Sie
     *     gilt dann als das heutige Briefing, bis der Morgenlauf sie ersetzt — sonst
     *     waere die Karte zwischen 0 und 5:30 Uhr leer, obwohl ein passender Text da ist.
     *  3. Sonst das Fach von heute.
     *
     * @return array<string, mixed>
     */
    private function BriefingPublic(): array
    {
        if (!$this->BriefingIsEnabled()) {
            return ['ok' => true, 'briefing' => null];
        }
        $heute  = date('Y-m-d');
        $morgen = date('Y-m-d', $this->BriefingDay(1));

        $vorschau = $this->BriefingSlot(1);
        if ($this->BriefingShownSlot() === 1
            && $vorschau['d'] === $morgen
            && $vorschau['text'] !== '') {
            return $this->BriefingAntwort($vorschau, 'tomorrow', true);
        }

        $fach = $this->BriefingSlot(0);
        if (($fach['d'] !== $heute || $fach['text'] === '')
            && $vorschau['d'] === $heute && $vorschau['text'] !== '') {
            return $this->BriefingAntwort($vorschau, 'today', false);
        }
        if ($fach['d'] !== $heute || $fach['text'] === '') {
            return ['ok' => true, 'briefing' => null];
        }
        return $this->BriefingAntwort($fach, 'today', false);
    }

    /** @return array<string, mixed> */
    private function BriefingAntwort(array $fach, string $tag, bool $vorschau): array
    {
        return ['ok' => true, 'briefing' => [
            'text'        => $fach['text'],
            'date'        => $fach['d'],
            'generatedAt' => $fach['at'],
            'userId'      => $fach['userId'],
            // Fertige Tonschnipsel in Spielreihenfolge; leer = die Oberflaeche
            // muss selbst erzeugen (Sprachausgabe war beim Schreiben nicht bereit).
            // Bei abgeschaltetem Ton bleiben sie draussen, auch wenn noch welche
            // von vorher liegen — sonst spielte ein Knopf, den es nicht geben soll.
            'clips'       => $this->BriefingAudioIsEnabled() ? $fach['clips'] : [],
            // Sagt der Oberflaeche, ob es einen Vorlese-Knopf geben darf.
            'audio'       => $this->BriefingAudioIsEnabled(),
            // Damit die Karte nicht den morgigen Text als heutigen ausgibt.
            'day'         => $tag,
            'preview'     => $vorschau,
        ]];
    }
}

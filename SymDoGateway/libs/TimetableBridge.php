<?php

declare(strict_types=1);

/**
 * Der Stundenplan fuer die Apps.
 *
 * Das Gateway pflegt hier nichts — es holt den fertigen Plan aus den
 * Stundenplan-Instanzen und reicht die ganze Woche weiter. Gerechnet wird dort,
 * damit Kachel, Web-App und iOS dieselben Zahlen zeigen.
 */
trait TimetableBridge
{
    private const TIMETABLE_MODULE_GUID = '{C22E0A96-1BC7-4029-B8C5-7E94E4F2A9D9}';

    private function TimetableCreate(): void
    {
        // Der Schalter stand einmal in JEDER Stundenplan-Instanz, dann als EIN
        // Haekchen hier. Beides war falsch: im Modul sucht ihn niemand, und ein
        // Haekchen fuer alle zeigte bei zwei Instanzen beide Plaene — dieselben
        // Kinder standen doppelt in der App.
        //
        // Jetzt eine Zeile je Instanz. Eigenschaften registriert Symcon FEST in
        // Create(), eine je Instanz ist damit unmoeglich; deshalb eine Liste, die
        // ihre Zeilen beim Aufbau des Formulars aus den vorhandenen Instanzen
        // bekommt und beim Uebernehmen zurueckgeschrieben wird.
        /* Bleibt registriert, obwohl die Einstellung in die SymDo Web App
           gewandert ist: hier steht der ALTE Stand, und TimetableChoiceMap liest
           ihn als Rueckfall. Ein Entfernen haette die Wahl beim Verschieben
           stillschweigend geloescht. */
        $this->RegisterPropertyString('TimetableChoice', '[]');
    }

    /**
     * Instanz-Kennung => anzeigen? aus der gespeicherten Liste.
     *
     * @return array<int,bool>
     */
    private function TimetableChoiceMap(): array
    {
        /* Die Wahl steht in der SymDo-Web-App-Instanz, nicht mehr hier: sichtbare
           Bereiche werden dort eingestellt, und niemand sucht sie im Gateway.
           Kennt die Web-App eine Instanz noch nicht (weil dort noch niemand
           uebernommen hat), gilt der alte Stand aus dieser Instanz — sonst waere
           die Einstellung beim Verschieben verloren gegangen. */
        $karte = [];
        foreach (@IPS_GetInstanceListByModuleID(self::SDWA_MODULE_GUID) as $id) {
            $cfg = json_decode((string)@IPS_GetConfiguration((int)$id), true);
            foreach ($this->TimetableChoiceRows((string)($cfg['TimetableChoice'] ?? '[]')) as $inst => $an) {
                $karte[$inst] = $an;
            }
        }
        // IPS_GetConfiguration statt ReadPropertyString: die Eigenschaft entsteht
        // in Create() und existiert erst beim naechsten Kernel-Start.
        $eigen = json_decode((string)@IPS_GetConfiguration($this->InstanceID), true);
        foreach ($this->TimetableChoiceRows((string)($eigen['TimetableChoice'] ?? '[]')) as $inst => $an) {
            if (!array_key_exists($inst, $karte)) {
                $karte[$inst] = $an;
            }
        }
        return $karte;
    }

    /**
     * Die gespeicherte Liste als Karte.
     *
     * @return array<int,bool>
     */
    private function TimetableChoiceRows(string $roh): array
    {
        $karte = [];
        foreach ((array)json_decode($roh, true) as $z) {
            if (is_array($z) && (int)($z['id'] ?? 0) > 0) {
                $karte[(int)$z['id']] = (bool)($z['show'] ?? false);
            }
        }
        return $karte;
    }

    /**
     * @return array{ok:bool,timetable:array|null}
     *         timetable = null heisst „nichts einzublenden" — kein Modul, keine
     *         Instanz, nirgends eingeschaltet oder nichts gepflegt.
     */
    private function TimetablePublic(): array
    {
        if (!function_exists('STPL_GetPlan')) {
            return ['ok' => true, 'timetable' => null];
        }
        $kinder  = [];
        $spanne  = null;
        $ferien  = null;
        $jetzt   = null;
        $datiert = false;
        foreach ($this->TimetableInstances() as $id) {
            $plan = json_decode((string)@STPL_GetPlan($id), true);
            if (!is_array($plan) || !is_array($plan['children'] ?? null)) {
                continue;
            }
            if ($ferien === null && is_array($plan['holiday'] ?? null)) {
                $ferien = $plan['holiday'];
            }
            // Eine Instanz mit Import genuegt: dann sind Daten im Spiel.
            $datiert = $datiert || (($plan['dated'] ?? false) === true);
            // Die aktuelle Minute fuer den Jetzt-Strich. Sie kommt aus der
            // Instanz und nicht aus der App: die Uhr des Betrachters muss nicht
            // die des Servers sein. Alle Instanzen stehen auf derselben Uhr,
            // die erste genuegt.
            if ($jetzt === null && ($plan['now'] ?? null) !== null) {
                $jetzt = (int)$plan['now'];
            }
            // Die Spanne ist der gemeinsame Massstab aller Balken. Bei mehreren
            // Instanzen die aeussersten Werte, sonst haetten zwei Kinder aus
            // verschiedenen Instanzen verschiedene Massstaebe und ihre Balken
            // waeren nicht vergleichbar — genau das soll die Zeile ja leisten.
            if (is_array($plan['span'] ?? null) && count($plan['span']) === 2) {
                $spanne = $spanne === null
                    ? [(int)$plan['span'][0], (int)$plan['span'][1]]
                    : [min($spanne[0], (int)$plan['span'][0]), max($spanne[1], (int)$plan['span'][1])];
            }
            $stunde = static fn(array $s): array => [
                'name'  => (string)($s['name'] ?? ''),
                'icon'  => (string)($s['icon'] ?? ''),
                'color' => (string)($s['color'] ?? ''),
                'start' => (string)($s['start'] ?? ''),
                'end'   => (string)($s['end'] ?? ''),
                'from'  => (int)($s['from'] ?? 0),
                'to'    => (int)($s['to'] ?? 0),
                'care'  => (bool)($s['care'] ?? false),
                // Freitext aus dem Stundenplan-Modul. Ohne diese zwei Zeilen
                // kaeme beides nie in der App an: die Projektion hier ist eine
                // Weissliste, kein Durchreichen.
                'room'    => (string)($s['room'] ?? ''),
                'teacher' => (string)($s['teacher'] ?? ''),
                /* normal | vertretung | entfall. Ohne diese Zeile stuende die
                   entfallene Stunde in der App wie eine normale da — und der
                   Ganztagsblock daneben sähe aus wie eine zweite Stunde zur
                   selben Zeit. */
                'status'  => (string)($s['status'] ?? ''),
            ];
            /* Die Abbildung EINER Woche. Sie steht als Funktion da, weil es
               zwei sind: die laufende und — mit Import — die kommende, die der
               Wochenplan im Stundenplan-Bereich zeigt. */
            $wocheAbbilden = function (array $rohTage) use ($stunde): array {
                $tage = [];
                foreach ($rohTage as $tag) {
                    if (!is_array($tag)) {
                        continue;
                    }
                    // Ferien JE TAG: die Karte blaettert durch die Woche, und die
                    // Auskunft haengt am Datum. Der Wert oben (holiday) gilt nur
                    // fuer heute und taugt nicht zum Blaettern.
                    $frei = is_array($tag['holiday'] ?? null) ? [
                        'name'   => (string)($tag['holiday']['name'] ?? ''),
                        'until'  => (string)($tag['holiday']['until'] ?? ''),
                        'public' => (bool)($tag['holiday']['public'] ?? false),
                    ] : null;
                    /* Termin-Marker: diese Liste ist eine WEISSLISTE, kein
                       Durchreicher — ohne diese Zeilen kaeme der Schluessel nie
                       in der App an. Dieselbe schlanke Form wie im Plan. */
                    $marker = [];
                    foreach ((array)($tag['events'] ?? []) as $e) {
                        if (!is_array($e)) {
                            continue;
                        }
                        $marker[] = [
                            'title' => (string)($e['title'] ?? ''),
                            'time'  => (string)($e['time'] ?? ''),
                            'at'    => (int)($e['at'] ?? 0),
                            // Ende und „ohne Endzeit" gehoeren mit: der Termin
                            // wird als Balken gezeichnet, und ohne Endzeit
                            // laeuft er aus statt hart abzubrechen.
                            'bis'   => (int)($e['bis'] ?? 0),
                            'open'  => ($e['open'] ?? false) === true,
                        ];
                    }
                    $tage[] = [
                        'weekday' => (int)($tag['weekday'] ?? 0),
                        'label'   => (string)($tag['label'] ?? ''),
                        /* Das DATUM dieses Wochentags. Das Wochenraster in der
                           App schreibt es hinter das Kuerzel („Mo 08.09."), und
                           mit Import ist es die halbe Auskunft: ohne Datum sieht
                           ein datierter Plan wie eine Vorlage aus. Leer, solange
                           das Stundenplan-Modul aelter ist als c295585. */
                        'date'    => (string)($tag['date'] ?? ''),
                        'today'   => (bool)($tag['today'] ?? false),
                        'minutes' => (int)($tag['minutes'] ?? 0),
                        'holiday' => $frei,
                        'slots'   => array_values(array_map($stunde,
                            array_filter((array)($tag['slots'] ?? []), 'is_array'))),
                        'events'  => $marker,
                    ];
                }
                return $tage;
            };

            foreach ($plan['children'] as $kind) {
                if (!is_array($kind)) {
                    continue;
                }
                // Kinder ohne Unterricht bleiben DRIN, aber mit leerer Liste:
                // „Mia hat heute frei" ist eine Auskunft, ein fehlender Name
                // dagegen sieht nach einem Fehler aus.
                $satz = [
                    'name'   => (string)($kind['name'] ?? ''),
                    'color'  => (string)($kind['color'] ?? '#1E88E5'),
                    'userId' => (string)($kind['userId'] ?? ''),
                    'next'   => (string)($kind['next'] ?? ''),
                    'days'   => $wocheAbbilden((array)($kind['days'] ?? [])),
                ];
                /* Die kommende Woche, wenn das Modul sie liefert — das tut es
                   nur mit Import (siehe FolgewocheAnhaengen dort). Ohne Import
                   waere sie dieselbe Vorlage noch einmal, und der Wechsler im
                   Stundenplan-Bereich zeigte zweimal dasselbe Bild. */
                if (is_array($kind['nextDays'] ?? null) && $kind['nextDays'] !== []) {
                    $satz['nextDays'] = $wocheAbbilden((array)$kind['nextDays']);
                }
                $kinder[] = $satz;
            }
        }
        if ($kinder === []) {
            return ['ok' => true, 'timetable' => null];
        }
        return ['ok' => true, 'timetable' => [
            'span'     => $spanne ?? [8 * 60, 16 * 60],
            'now'      => $jetzt,
            'holiday'  => $ferien,
            // Liegt ein Import vor? Daran haengt in der App dasselbe wie in der
            // Kachel: das Datum im Spaltenkopf und der Wochenwechsler.
            'dated'    => $datiert,
            'children' => $kinder,
        ]];
    }

    /**
     * Alle Instanzen mit EIGENEN Daten. Spiegel-Instanzen (die ihre Daten aus
     * einer anderen holen) bleiben draussen — sonst stuende jedes Kind zweimal.
     *
     * @return list<int>
     */
    private function TimetableOwnInstances(): array
    {
        $ids = [];
        foreach (@IPS_GetInstanceListByModuleID(self::TIMETABLE_MODULE_GUID) as $id) {
            $cfg = json_decode((string)@IPS_GetConfiguration($id), true);
            if (!is_array($cfg) || (int)($cfg['SourceInstanceID'] ?? 0) > 0) {
                continue;
            }
            $ids[] = (int)$id;
        }
        return $ids;
    }

    /**
     * Instanzen, die ihren Plan in der APP zeigen sollen. Das Briefing hat einen
     * eigenen Schalter und richtet sich nicht danach.
     *
     * Eine Zeile je Instanz. Ein Haekchen fuer alle stand hier zuerst, mit der
     * Begruendung, zwei unabhaengige Stundenplaene gehoerten derselben Familie —
     * das war ein Fehlschluss: zwei Instanzen mit denselben Kindern schoben jedes
     * Kind DOPPELT in die App.
     *
     * @return list<int>
     */
    private function TimetableInstances(): array
    {
        $wahl = $this->TimetableChoiceMap();
        $ids  = [];
        foreach ($this->TimetableOwnInstances() as $id) {
            // Unbekannte Instanz = AUS. Eine neue soll nicht ungefragt den
            // Stundenplan der Kinder auf jedes gekoppelte Geraet schieben.
            if ($wahl[$id] ?? false) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * Eine Zeile je Kind fuer das Briefing: wie lange an diesem Tag Schule ist.
     *
     * Das Datum wird durchgereicht, weil die Abendvorschau ueber MORGEN spricht —
     * mit „heute" gerechnet behauptete sie mitten in den Ferien Unterricht.
     *
     * Kinder ohne Schule an dem Tag bleiben DRIN („keine Schule"): dass ein Kind
     * frei hat, ist fuer die Familienplanung genauso eine Auskunft wie eine
     * Uhrzeit — und ein fehlender Name sieht aus wie ein Fehler.
     *
     * @return list<string>
     */
    private function TimetableSchoolLines(string $datum): array
    {
        if (!function_exists('STPL_GetPlanForDate')) {
            return [];
        }
        /* JE KIND gesammelt, nicht je Instanz: dasselbe Kind kann in mehreren
           Stundenplan-Instanzen stehen (etwa in einer zweiten zum Ausprobieren).
           Ohne diese Zusammenfassung stuende es zweimal im Briefing — und im
           schlechteren Fall einmal mit „Ferien" und einmal mit Unterricht, weil
           die eine Instanz eine Ferienquelle hat und die andere nicht. */
        $kinder = [];
        // Entfall und Vertretung des Tages kommen aus WebUntis, nicht aus dem
        // Wochenplan: nur sie sind auf den TAG bezogen.
        $meldungen = $this->UntisTagesmeldungen($datum);
        foreach ($this->TimetableOwnInstances() as $id) {
            $plan = json_decode((string)@STPL_GetPlanForDate($id, $datum), true);
            if (!is_array($plan) || !is_array($plan['children'] ?? null)) {
                continue;
            }
            $planFerien = is_array($plan['holiday'] ?? null) ? $plan['holiday'] : null;
            foreach ($plan['children'] as $kind) {
                if (!is_array($kind)) {
                    continue;
                }
                $name = trim((string)($kind['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $tag = null;
                foreach ((array)($kind['days'] ?? []) as $t) {
                    if (is_array($t) && (bool)($t['today'] ?? false)) {
                        $tag = $t;
                        break;
                    }
                }
                // Ferien stehen am Tag; kennt der Tag keine (ältere Fassung oder
                // gar kein Tag für dieses Datum), gilt die Angabe des Plans.
                $ferien = is_array($tag['holiday'] ?? null) ? $tag['holiday'] : $planFerien;
                if (!isset($kinder[$name])) {
                    $kinder[$name] = ['ferien' => null, 'zeile' => '', 'datiert' => false];
                }
                if (is_array($ferien)) {
                    /* Ferien schlagen den Unterricht: die Angabe ist eine Aussage
                       ueber den Tag, ein Stundenplan nur eine ueber die Woche. Eine
                       Instanz ohne Ferienquelle meldet an einem Ferientag ganz
                       normal Unterricht — die darf das Ergebnis nicht kippen. */
                    $kinder[$name]['ferien'] = $ferien;
                    continue;
                }
                /* Steht dasselbe Kind in zwei Instanzen, gewinnt die mit den
                   DATIERTEN Stunden: dort hat ein Import gesagt, was an diesem
                   Tag wirklich ist. Gemessen am 03.09.2026 — die eine Instanz
                   meldete „bis 14:05 Schule", waehrend die andere den
                   Projekttag und vier Ausfaelle kannte. */
                $datiert = false;
                foreach ((array)($tag['slots'] ?? []) as $s) {
                    if (is_array($s) && ($s['status'] ?? '') !== '' && ($s['status'] ?? '') !== 'normal') {
                        $datiert = true;
                        break;
                    }
                }
                if ($kinder[$name]['zeile'] !== '' && !($datiert && !$kinder[$name]['datiert'])) {
                    continue;
                }
                $kinder[$name]['datiert'] = $datiert;
                $kinder[$name]['zeile'] = $this->TimetableSchoolLine($name, is_array($tag) ? $tag : [], $meldungen[$name] ?? []);
            }
        }
        return $this->TimetableSchoolText($kinder);
    }

    /**
     * Eine Zeile fuer ein Kind an einem Schultag.
     *
     * @param array<string, mixed> $tag
     */
    private function TimetableSchoolLine(string $name, array $tag, array $meldung = []): string
    {
        $stunden = is_array($tag['slots'] ?? null) ? $tag['slots'] : [];
        if ($stunden === []) {
            return $name . ': keine Schule';
        }
        /* Zur Schulzeit zaehlt weder die Betreuung noch, was ausfaellt:
           „bis 16 Uhr Schule" waere falsch, wenn davon dreieinhalb Stunden
           Hort sind — und „bis 14:05" ebenso, wenn die letzten beiden Stunden
           entfallen. Letzteres steht erst seit den datierten Tagen wirklich
           im Tag. */
        $unterricht = array_values(array_filter($stunden,
            static fn(array $s): bool => !(bool)($s['care'] ?? false)
                && (string)($s['status'] ?? '') !== 'entfall'));
        $betreuung  = array_values(array_filter($stunden,
            static fn(array $s): bool => (bool)($s['care'] ?? false)));
        /* Was ausfaellt und was STATTDESSEN laeuft, kommt aus dem Tag selbst —
           seit er datiert ist, steht beides dort mit Uhrzeit. Der Merker aus
           WebUntis ist nur noch der Rueckfall fuer Plaene ohne datierte Tage. */
        $entfallSlots = array_values(array_filter($stunden,
            static fn(array $s): bool => (string)($s['status'] ?? '') === 'entfall'));
        $ersatzSlots  = array_values(array_filter($stunden,
            static fn(array $s): bool => (string)($s['status'] ?? '') === 'vertretung'));
        /* Mit Uhrzeit nur, solange es ein oder zwei sind — bei vieren wird die
           Zeile sonst zur Aufzaehlung von Zahlen. Welche Faecher ausfallen,
           steht IMMER da: „4 Stunden entfallen" sagt niemandem, ob das Buch
           eingepackt werden muss. */
        $entfall = $entfallSlots !== []
            ? array_map(static fn(array $s): string => trim((string)($s['name'] ?? ''))
                . (count($entfallSlots) <= 2 ? ' ' . (string)($s['start'] ?? '') : ''), $entfallSlots)
            : array_map('strval', (array)($meldung['entfall'] ?? []));
        /* Die Zeit bleibt weg, wenn der Ersatz GENAU die Schulzeit fuellt: sie
           stuende sonst zweimal im selben Satz („Schule von 08:00 bis 13:00 …
           dafuer Projekttag von 08:00 bis 13:00"). */
        $ganzerTag = count($ersatzSlots) === 1 && $unterricht !== []
            && (string)$ersatzSlots[0]['start'] === (string)$unterricht[0]['start']
            && (string)$ersatzSlots[0]['end'] === (string)$unterricht[count($unterricht) - 1]['end'];
        $ersatz = $ersatzSlots !== []
            ? array_map(static fn(array $s): string => trim((string)($s['name'] ?? ''))
                . ($ganzerTag ? '' : ' von ' . (string)($s['start'] ?? '') . ' bis ' . (string)($s['end'] ?? '')),
                $ersatzSlots)
            : array_map('strval', (array)($meldung['vertretung'] ?? []));

        if ($unterricht === []) {
            // Alles abgesagt ist etwas anderes als schulfrei.
            $zeile = $entfall !== []
                ? $name . ': heute kein regulärer Unterricht'
                : $name . ': keine Schule';
        } else {
            $zeile = sprintf('%s: Schule von %s bis %s', $name,
                (string)$unterricht[0]['start'],
                (string)$unterricht[count($unterricht) - 1]['end']);
            if ($betreuung !== []) {
                $zeile .= ', danach Betreuung bis ' . (string)$betreuung[count($betreuung) - 1]['end'];
            }
        }

        if ($entfall !== []) {
            /* Ab sechs wird auch die schoenste Aufzaehlung unzumutbar — dann
               die ersten vier und der Rest als Zahl. */
            $liste = $entfall;
            if (count($liste) > 5) {
                $rest  = count($liste) - 4;
                $liste = array_slice($liste, 0, 4);
                $liste[] = sprintf('%d weitere', $rest);
            }
            $zeile .= count($liste) === 1
                ? ', es entfällt ' . $liste[0]
                : ', ' . $this->TimetableUnd($liste) . ' entfallen';
        }
        if ($ersatz !== []) {
            /* „dafür" nur, wenn der Ersatz die ausgefallenen Stunden auch
               wirklich abdeckt. Sonst sind es zwei Dinge am selben Tag, und
               ein „dafür" behauptete einen Zusammenhang, den es nicht gibt.

               Frueher stand hier, dass eine Vertretung wegbleibt, wenn sie
               ohnehin als Stunde im Plan steht. Im BILD stimmt das — dort
               sieht man den Block. Im Briefing gibt es kein Bild: „bis 13 Uhr
               Schule, 4 Stunden entfallen" klang nach einer Luecke statt nach
               einem Projekttag. Vom Nutzer gemeldet. */
            $deckt = false;
            foreach ($ersatzSlots as $e) {
                foreach ($entfallSlots as $a) {
                    if ((int)($a['from'] ?? -1) >= (int)($e['from'] ?? 0)
                        && (int)($a['from'] ?? -1) < (int)($e['to'] ?? 0)) {
                        $deckt = true;
                        break 2;
                    }
                }
            }
            $zeile .= ($deckt ? ', dafür ' : ', vertreten wird ') . $this->TimetableUnd($ersatz);
        }
        return $zeile;
    }

    /**
     * „A, B und C" — eine Aufzaehlung, wie man sie spricht. Das Briefing wird
     * auch VORGELESEN, und „A, B, C" klingt dort wie eine abgebrochene Liste.
     *
     * @param list<string> $teile
     */
    private function TimetableUnd(array $teile): string
    {
        if (count($teile) < 2) {
            return implode('', $teile);
        }
        $letztes = array_pop($teile);
        return implode(', ', $teile) . ' und ' . $letztes;
    }

    /**
     * Aus dem Sammelergebnis die Zeilen fuers Briefing.
     *
     * In den Ferien wird NUR die Ferienlage genannt und kein Stundenplan: der
     * interessiert dann niemanden. Haben alle Kinder dieselben Ferien, steht eine
     * einzige Zeile da statt derselben Aussage je Kind.
     *
     * @param array<string, array{ferien: array<string,mixed>|null, zeile: string, datiert: bool}> $kinder
     * @return list<string>
     */
    private function TimetableSchoolText(array $kinder): array
    {
        if ($kinder === []) {
            return [];
        }
        $namen = array_keys($kinder);
        $alleFerien = true;
        $einName = null;
        foreach ($kinder as $k) {
            if (!is_array($k['ferien'])) {
                $alleFerien = false;
                break;
            }
            $n = trim((string)($k['ferien']['name'] ?? ''));
            $einName = $einName === null ? $n : ($einName === $n ? $n : false);
        }
        if ($alleFerien && is_string($einName)) {
            $wort = $einName !== '' ? $einName : 'Ferien';
            $bis  = $this->TimetableHolidayEnd($kinder[$namen[0]]['ferien']);
            // Das Datum endet selbst auf einen Punkt („01.09."). Ein weiterer waere
            // einer zu viel.
            return ['Keine Schule, ' . $wort . ($bis !== '' ? ' bis ' . $bis : '.')];
        }
        $zeilen = [];
        foreach ($kinder as $name => $k) {
            if (is_array($k['ferien'])) {
                $wort = trim((string)($k['ferien']['name'] ?? ''));
                $bis  = $this->TimetableHolidayEnd($k['ferien']);
                $zeilen[] = $name . ': keine Schule' . ($wort !== '' ? ' (' . $wort . ')' : '')
                    . ($bis !== '' ? ', bis ' . $bis : '');
                continue;
            }
            if ($k['zeile'] !== '') {
                $zeilen[] = $k['zeile'];
            }
        }
        return $zeilen;
    }

    /**
     * Das Ende der Ferien als „01.09." — leer, wenn keins genannt ist. Ein
     * Feiertag traegt oft dasselbe Datum wie der Tag selbst; dann sagt „bis" nichts
     * und bleibt weg.
     *
     * @param array<string, mixed>|null $ferien
     */
    private function TimetableHolidayEnd(?array $ferien): string
    {
        $bis = trim((string)($ferien['until'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $bis) !== 1) {
            return '';
        }
        if (($ferien['public'] ?? false) === true) {
            return '';   // Ein Feiertag ist EIN Tag; „bis heute" waere sinnlos.
        }
        return date('d.m.', (int)strtotime($bis));
    }
}

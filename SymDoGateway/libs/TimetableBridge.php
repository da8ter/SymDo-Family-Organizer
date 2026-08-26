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
        foreach ($this->TimetableInstances() as $id) {
            $plan = json_decode((string)@STPL_GetPlan($id), true);
            if (!is_array($plan) || !is_array($plan['children'] ?? null)) {
                continue;
            }
            if ($ferien === null && is_array($plan['holiday'] ?? null)) {
                $ferien = $plan['holiday'];
            }
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
            ];
            foreach ($plan['children'] as $kind) {
                if (!is_array($kind)) {
                    continue;
                }
                // Die GANZE Woche, nicht nur heute: die Karte in der App laesst
                // sich durch die Wochentage blaettern. Der Aufwand ist gering —
                // ein Stundenplan hat ein paar Dutzend Eintraege.
                $tage = [];
                foreach ((array)($kind['days'] ?? []) as $tag) {
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
                    $tage[] = [
                        'weekday' => (int)($tag['weekday'] ?? 0),
                        'label'   => (string)($tag['label'] ?? ''),
                        'today'   => (bool)($tag['today'] ?? false),
                        'minutes' => (int)($tag['minutes'] ?? 0),
                        'holiday' => $frei,
                        'slots'   => array_values(array_map($stunde,
                            array_filter((array)($tag['slots'] ?? []), 'is_array'))),
                    ];
                }
                // Kinder ohne Unterricht bleiben DRIN, aber mit leerer Liste:
                // „Mia hat heute frei" ist eine Auskunft, ein fehlender Name
                // dagegen sieht nach einem Fehler aus.
                $kinder[] = [
                    'name'   => (string)($kind['name'] ?? ''),
                    'color'  => (string)($kind['color'] ?? '#1E88E5'),
                    'userId' => (string)($kind['userId'] ?? ''),
                    'next'   => (string)($kind['next'] ?? ''),
                    'days'   => $tage,
                ];
            }
        }
        if ($kinder === []) {
            return ['ok' => true, 'timetable' => null];
        }
        return ['ok' => true, 'timetable' => [
            'span'     => $spanne ?? [8 * 60, 16 * 60],
            'now'      => $jetzt,
            'holiday'  => $ferien,
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
                    $kinder[$name] = ['ferien' => null, 'zeile' => ''];
                }
                if (is_array($ferien)) {
                    /* Ferien schlagen den Unterricht: die Angabe ist eine Aussage
                       ueber den Tag, ein Stundenplan nur eine ueber die Woche. Eine
                       Instanz ohne Ferienquelle meldet an einem Ferientag ganz
                       normal Unterricht — die darf das Ergebnis nicht kippen. */
                    $kinder[$name]['ferien'] = $ferien;
                    continue;
                }
                if ($kinder[$name]['zeile'] !== '') {
                    continue;
                }
                $kinder[$name]['zeile'] = $this->TimetableSchoolLine($name, is_array($tag) ? $tag : []);
            }
        }
        return $this->TimetableSchoolText($kinder);
    }

    /**
     * Eine Zeile fuer ein Kind an einem Schultag.
     *
     * @param array<string, mixed> $tag
     */
    private function TimetableSchoolLine(string $name, array $tag): string
    {
        $stunden = is_array($tag['slots'] ?? null) ? $tag['slots'] : [];
        if ($stunden === []) {
            return $name . ': keine Schule';
        }
        // Unterricht und Betreuung getrennt nennen: „bis 16 Uhr Schule"
        // waere falsch, wenn davon dreieinhalb Stunden Hort sind.
        $unterricht = array_values(array_filter($stunden,
            static fn(array $s): bool => !(bool)($s['care'] ?? false)));
        $betreuung  = array_values(array_filter($stunden,
            static fn(array $s): bool => (bool)($s['care'] ?? false)));
        if ($unterricht === []) {
            return $name . ': keine Schule';
        }
        $zeile = sprintf('%s: Schule von %s bis %s', $name,
            (string)$unterricht[0]['start'],
            (string)$unterricht[count($unterricht) - 1]['end']);
        if ($betreuung !== []) {
            $zeile .= ', danach Betreuung bis ' . (string)$betreuung[count($betreuung) - 1]['end'];
        }
        return $zeile;
    }

    /**
     * Aus dem Sammelergebnis die Zeilen fuers Briefing.
     *
     * In den Ferien wird NUR die Ferienlage genannt und kein Stundenplan: der
     * interessiert dann niemanden. Haben alle Kinder dieselben Ferien, steht eine
     * einzige Zeile da statt derselben Aussage je Kind.
     *
     * @param array<string, array{ferien: array<string,mixed>|null, zeile: string}> $kinder
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

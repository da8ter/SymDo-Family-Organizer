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
        // Der Schalter stand bis hierher in JEDER Stundenplan-Instanz. Er gehoert
        // aber dorthin, wo man die App einrichtet: wer die Karte sucht, sucht sie
        // im SymDo-Backend und nicht im Formular eines anderen Moduls.
        $this->RegisterPropertyBoolean('TimetableEnabled', false);
    }

    /**
     * Der Bereich im Formular. Faellt WEG, wenn es keinen Stundenplan mit eigenen
     * Daten gibt — ein Schalter fuer etwas, das gar nicht existiert, ist Laerm.
     *
     * @return list<array<string,mixed>> leer oder genau ein Klapp-Bereich
     */
    private function GetTimetablePanel(): array
    {
        $eigene = $this->TimetableOwnInstances();
        if ($eigene === []) {
            return [];
        }
        // Wie bei den Briefing-Feldern: auf eine Eigenschaft, die es vor dem
        // naechsten Kernel-Start nicht gibt, laesst „Uebernehmen" das Formular
        // scheitern. Bis dahin ein Hinweis statt eines Feldes, das nichts tut.
        $cfg = json_decode((string)@IPS_GetConfiguration($this->InstanceID), true);
        if (!is_array($cfg) || !array_key_exists('TimetableEnabled', $cfg)) {
            return [[
                'type'     => 'ExpansionPanel',
                'caption'  => $this->Translate('Timetable'),
                'expanded' => false,
                'items'    => [[
                    'type'    => 'Label',
                    'caption' => $this->Translate('The timetable setting appears after the next Symcon restart — it is a new setting, and those only exist once the kernel has loaded the module again.')
                ]],
            ]];
        }
        $namen = [];
        foreach ($eigene as $id) {
            $namen[] = sprintf('%s (#%d)', IPS_GetName($id), $id);
        }
        return [[
            'type'     => 'ExpansionPanel',
            'caption'  => $this->Translate('Timetable'),
            'expanded' => false,
            'items'    => [
                [
                    'type'    => 'Label',
                    'caption' => $this->Translate("The card shows one bar per child with the school day, a marker for the current time and a switcher through the weekdays. The plan itself is kept in the timetable module — here you only decide whether the app shows it.")
                ],
                [
                    'type'    => 'CheckBox',
                    'name'    => 'TimetableEnabled',
                    'caption' => $this->Translate('Show the timetable on the dashboard of the app')
                ],
                [
                    'type'    => 'Label',
                    'caption' => sprintf($this->Translate('Data from: %s'), implode(', ', $namen))
                ],
            ],
        ]];
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
                    $tage[] = [
                        'weekday' => (int)($tag['weekday'] ?? 0),
                        'label'   => (string)($tag['label'] ?? ''),
                        'today'   => (bool)($tag['today'] ?? false),
                        'minutes' => (int)($tag['minutes'] ?? 0),
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
     * Ein Schalter fuer alle statt einer je Instanz: Spiegel-Instanzen sind schon
     * draussen, und zwei UNABHAENGIGE Stundenplaene gehoeren derselben Familie —
     * einen davon auszublenden waere ein Fall, den es zu erfinden nicht lohnt.
     *
     * @return list<int>
     */
    private function TimetableInstances(): array
    {
        // IPS_GetConfiguration statt ReadPropertyBoolean: die Eigenschaft entsteht
        // in Create() und existiert erst beim naechsten Kernel-Start.
        $cfg = json_decode((string)@IPS_GetConfiguration($this->InstanceID), true);
        if (!is_array($cfg) || !(bool)($cfg['TimetableEnabled'] ?? false)) {
            return [];
        }
        return $this->TimetableOwnInstances();
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
        $zeilen = [];
        foreach ($this->TimetableOwnInstances() as $id) {
            $plan = json_decode((string)@STPL_GetPlanForDate($id, $datum), true);
            if (!is_array($plan) || !is_array($plan['children'] ?? null)) {
                continue;
            }
            $ferien = is_array($plan['holiday'] ?? null) ? (string)$plan['holiday']['name'] : '';
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
                $stunden = is_array($tag['slots'] ?? null) ? $tag['slots'] : [];
                if ($stunden === []) {
                    $zeilen[] = $name . ': keine Schule' . ($ferien !== '' ? ' (' . $ferien . ')' : '');
                    continue;
                }
                // Unterricht und Betreuung getrennt nennen: „bis 16 Uhr Schule"
                // waere falsch, wenn davon dreieinhalb Stunden Hort sind.
                $unterricht = array_values(array_filter($stunden,
                    static fn(array $s): bool => !(bool)($s['care'] ?? false)));
                $betreuung  = array_values(array_filter($stunden,
                    static fn(array $s): bool => (bool)($s['care'] ?? false)));
                if ($unterricht === []) {
                    $zeilen[] = $name . ': keine Schule';
                    continue;
                }
                $zeile = sprintf('%s: Schule von %s bis %s', $name,
                    (string)$unterricht[0]['start'],
                    (string)$unterricht[count($unterricht) - 1]['end']);
                if ($betreuung !== []) {
                    $zeile .= ', danach Betreuung bis ' . (string)$betreuung[count($betreuung) - 1]['end'];
                }
                $zeilen[] = $zeile;
            }
        }
        return $zeilen;
    }
}

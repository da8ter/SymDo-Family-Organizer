<?php

declare(strict_types=1);

/**
 * Der Stundenplan fuer die Apps.
 *
 * Das Gateway pflegt hier nichts — es holt den fertigen Plan aus den
 * Stundenplan-Instanzen und reicht die heutigen Stunden weiter. Gerechnet wird
 * dort, damit Kachel, Web-App und iOS dieselben Zahlen zeigen.
 */
trait TimetableBridge
{
    private const TIMETABLE_MODULE_GUID = '{C22E0A96-1BC7-4029-B8C5-7E94E4F2A9D9}';

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
        foreach ($this->TimetableInstances() as $id) {
            $plan = json_decode((string)@STPL_GetPlan($id), true);
            if (!is_array($plan) || !is_array($plan['children'] ?? null)) {
                continue;
            }
            if ($ferien === null && is_array($plan['holiday'] ?? null)) {
                $ferien = $plan['holiday'];
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
            foreach ($plan['children'] as $kind) {
                if (!is_array($kind)) {
                    continue;
                }
                $heute = null;
                foreach ((array)($kind['days'] ?? []) as $tag) {
                    if (is_array($tag) && (bool)($tag['today'] ?? false)) {
                        $heute = $tag;
                        break;
                    }
                }
                $stunden = is_array($heute['slots'] ?? null) ? $heute['slots'] : [];
                // Kinder ohne Unterricht heute bleiben DRIN, aber mit leerer
                // Liste: „Mia hat heute frei" ist eine Auskunft, ein fehlender
                // Name dagegen sieht nach einem Fehler aus.
                $kinder[] = [
                    'name'    => (string)($kind['name'] ?? ''),
                    'color'   => (string)($kind['color'] ?? '#1E88E5'),
                    'userId'  => (string)($kind['userId'] ?? ''),
                    'day'     => (string)($kind['todayLabel'] ?? ''),
                    'minutes' => (int)($kind['minutes'] ?? 0),
                    'next'    => (string)($kind['next'] ?? ''),
                    'slots'   => array_values(array_map(static fn(array $s): array => [
                        'name'  => (string)($s['name'] ?? ''),
                        'icon'  => (string)($s['icon'] ?? ''),
                        'color' => (string)($s['color'] ?? ''),
                        'start' => (string)($s['start'] ?? ''),
                        'end'   => (string)($s['end'] ?? ''),
                        'from'  => (int)($s['from'] ?? 0),
                        'to'    => (int)($s['to'] ?? 0),
                        'care'  => (bool)($s['care'] ?? false),
                    ], array_filter($stunden, 'is_array'))),
                ];
            }
        }
        if ($kinder === []) {
            return ['ok' => true, 'timetable' => null];
        }
        return ['ok' => true, 'timetable' => [
            'span'     => $spanne ?? [8 * 60, 16 * 60],
            'holiday'  => $ferien,
            'children' => $kinder,
        ]];
    }

    /**
     * Instanzen, die ihren Plan in der App zeigen sollen.
     *
     * Spiegel-Instanzen (die ihre Daten aus einer anderen holen) bleiben
     * draussen — sonst stuende jedes Kind zweimal in der Liste.
     *
     * @return list<int>
     */
    private function TimetableInstances(): array
    {
        $ids = [];
        foreach (@IPS_GetInstanceListByModuleID(self::TIMETABLE_MODULE_GUID) as $id) {
            $cfg = json_decode((string)@IPS_GetConfiguration($id), true);
            if (!is_array($cfg)) {
                continue;
            }
            if (!(bool)($cfg['ShowInApp'] ?? false)) {
                continue;
            }
            if ((int)($cfg['SourceInstanceID'] ?? 0) > 0) {
                continue;
            }
            $ids[] = (int)$id;
        }
        return $ids;
    }
}

<?php

declare(strict_types=1);

/**
 * Datenhaltung und Aufbereitung: liest die im Formular gepflegten Listen und
 * baut daraus den fertigen Zustand fuer Kachel, Gateway und Web-App.
 *
 * ABWEICHUNG VOM PLAN, bewusst: der Plan sah die Daten in Attributen vor, mit
 * dem Formular als zweiter Ablage. Das waere genau die Verdopplung, die in der
 * ToDo-Liste schon einmal Kennungen verschluckt hat (der Formular-Neuaufbau
 * ueberschrieb den Laufzeitstand). Hier sind die FORMULAR-LISTEN die Wahrheit,
 * einen zweiten Stand gibt es nicht. Der Nutzer richtet den Plan ohnehin im
 * Backend ein — eine Laufzeit-Schnittstelle zum Aendern braucht es dafuer nicht.
 *
 * Kinder und Faecher werden ueber ihren NAMEN verknuepft, nicht ueber eine
 * verborgene Kennung: Symcon schreibt die Werte unsichtbarer Listenspalten
 * nicht mit — eine id-Spalte kaeme leer zurueck. Der Preis ist, dass ein
 * Umbenennen die Zuordnung loest; genau das meldet die Statuszeile.
 */
trait TimetableStore
{
    /** @return list<array<string,mixed>> Zeilen einer Listen-Eigenschaft. */
    private function ListeLesen(string $name): array
    {
        // Ueber IPS_GetProperty und nicht ReadPropertyString: neue Eigenschaften
        // gibt es vor dem naechsten Kernel-Neustart nicht, und das Lesen gaebe
        // eine PHP-Warnung, die kein try/catch faengt (Muster aus dem Gateway).
        $roh = @IPS_GetProperty($this->InstanceID, $name);
        $zeilen = json_decode((string)$roh, true);
        if (!is_array($zeilen)) {
            return [];
        }
        return array_values(array_filter($zeilen, 'is_array'));
    }

    /** @return list<array{name:string,color:mixed,userId:string,saturday:array,care:list}> */
    private function Kinder(): array
    {
        $betreuung = [];
        foreach ($this->ListeLesen('Care') as $z) {
            $kind = trim((string)($z['child'] ?? ''));
            $ende = trim((string)($z['end'] ?? ''));
            if ($kind === '' || TimetableCalc::Minuten($ende) < 0) {
                continue;
            }
            $betreuung[$kind][] = ['weekday' => (int)($z['weekday'] ?? 0), 'end' => $ende];
        }

        $kinder = [];
        foreach ($this->ListeLesen('Children') as $z) {
            $name = trim((string)($z['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $kinder[] = [
                'id'       => $name,   // der Name IST die Kennung, siehe Kopf
                'name'     => $name,
                'color'    => TimetableSubjects::FarbeHex($z['color'] ?? -1) ?? '#1E88E5',
                'userId'   => trim((string)($z['userId'] ?? '')),
                'saturday' => [
                    'enabled'  => (bool)($z['saturday'] ?? false),
                    'biweekly' => (bool)($z['biweekly'] ?? false),
                    'parity'   => (string)($z['parity'] ?? 'even') === 'odd' ? 'odd' : 'even',
                ],
                'care'     => $betreuung[$name] ?? [],
            ];
        }
        return $kinder;
    }

    /** @return list<array{id:string,name:string,icon:string,color:int}> */
    private function Faecher(): array
    {
        $faecher = [];
        foreach ($this->ListeLesen('Subjects') as $z) {
            $name = trim((string)($z['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $faecher[] = [
                'id'    => $name,
                'name'  => $name,
                'icon'  => trim((string)($z['icon'] ?? '')),
                'color' => (int)($z['color'] ?? -1),
            ];
        }
        return $faecher;
    }

    /** @return list<array<string,mixed>> */
    private function Stunden(): array
    {
        $slots = [];
        foreach ($this->ListeLesen('Slots') as $i => $z) {
            $kind = trim((string)($z['child'] ?? ''));
            $von  = trim((string)($z['start'] ?? ''));
            $bis  = trim((string)($z['end'] ?? ''));
            if ($kind === '' || TimetableCalc::Minuten($von) < 0 || TimetableCalc::Minuten($bis) < 0) {
                continue;
            }
            $fach = trim((string)($z['subject'] ?? ''));
            $slots[] = [
                // Die Zeilennummer als Kennung: sie muss nur INNERHALB eines
                // Laufs eindeutig sein (Konfliktpruefung, Vergleich mit sich
                // selbst) und wird nirgends gespeichert.
                'id'        => 'r' . $i,
                'childId'   => $kind,
                'weekday'   => (int)($z['weekday'] ?? 0),
                'subjectId' => $fach,
                'subject'   => $fach,
                'start'     => $von,
                'end'       => $bis,
                'color'     => (int)($z['color'] ?? -1),
            ];
        }
        return $slots;
    }

    // ────────────────────────── Aufbereiteter Zustand ──────────────────────────

    /**
     * Der vollstaendige Zustand fuer die Anzeige. Hoehen und Luecken werden HIER
     * gerechnet, nicht in der Kachel: dieselben Zahlen sollen im Raster, in der
     * Timeline und in der Web-App herauskommen, und die Rechenregeln stehen
     * unter Prueflauf.
     */
    private function PlanAufbauen(): array
    {
        $kinder  = $this->Kinder();
        $faecher = $this->Faecher();
        $slots   = $this->Stunden();
        $heute   = date('Y-m-d');
        [$von, $bis] = TimetableCalc::Wochenspanne($slots, $kinder);
        $ferien  = $this->FerienAmTag($heute);
        $jetzt   = date('H:i');

        $ausgabe = [];
        foreach ($kinder as $kind) {
            $samstag  = $kind['saturday'];
            $heuteTag = $ferien === null ? TimetableCalc::Schultag($heute, $samstag) : null;
            $tage     = [];
            foreach (TimetableCalc::Wochentage((bool)$samstag['enabled']) as $tag) {
                $tages  = TimetableCalc::TagesSlots($tag, $kind, $slots);
                $naechste = $tag === $heuteTag
                    ? (TimetableCalc::NaechsteStunde($tages, $jetzt)['id'] ?? '')
                    : '';
                $vorher = $von;
                $karten = [];
                foreach ($tages as $s) {
                    $stil = TimetableSubjects::Aufloesen($s, $faecher);
                    $beginn = TimetableCalc::Minuten((string)$s['start']);
                    $karten[] = [
                        'name'   => $stil['name'],
                        'icon'   => $stil['icon'],
                        'color'  => (bool)($s['care'] ?? false) ? '#9E9E9E' : $stil['color'],
                        'start'  => $s['start'],
                        'end'    => $s['end'],
                        'from'   => $beginn,
                        'to'     => TimetableCalc::Minuten((string)$s['end']),
                        'gap'    => TimetableCalc::LueckeHoehe($vorher, $beginn),
                        'height' => TimetableCalc::SlotHoehe($s),
                        'care'   => (bool)($s['care'] ?? false),
                        'next'   => $s['id'] === $naechste && $naechste !== '',
                    ];
                    $vorher = TimetableCalc::Minuten((string)$s['end']);
                }
                $tage[] = [
                    'weekday' => $tag,
                    'label'   => TimetableCalc::TagKurz($tag),
                    'today'   => $tag === $heuteTag,
                    'parity'  => $tag === TimetableCalc::SAMSTAG && $samstag['biweekly']
                        ? ($samstag['parity'] === 'odd' ? 'ungerade KW' : 'gerade KW') : '',
                    'slots'   => $karten,
                ];
            }
            $heuteSlots = $heuteTag === null ? [] : TimetableCalc::TagesSlots($heuteTag, $kind, $slots);
            $ausgabe[] = [
                'name'    => $kind['name'],
                'color'   => $kind['color'],
                'userId'  => $kind['userId'],
                'days'    => $tage,
                'today'   => $heuteTag,
                'todayLabel' => $heuteTag === null ? '' : TimetableCalc::TagKurz($heuteTag),
                'minutes' => TimetableCalc::TagesDauer($heuteSlots),
                'next'    => $heuteTag === null
                    ? '' : (TimetableSubjects::Aufloesen(
                        TimetableCalc::NaechsteStunde($heuteSlots, $jetzt) ?? [], $faecher)['name']),
            ];
        }

        return [
            'mode'     => $this->Darstellung(),
            'date'     => $heute,
            'span'     => [$von, $bis],
            'holiday'  => $ferien,
            'children' => $ausgabe,
            'empty'    => $kinder === [] || $slots === [],
        ];
    }

    /**
     * Welche Stunden ohne Kind oder ohne Fach dastehen. Das passiert beim
     * Umbenennen — die Verknuepfung laeuft ueber den Namen (siehe Kopf), und ein
     * geaenderter Name loest sie. Wird in der Statuszeile gemeldet, damit es
     * nicht still im Raster fehlt.
     *
     * @return array{konflikte:list<string>,verwaist:list<string>}
     */
    private function PlanPruefen(): array
    {
        $kinder  = array_column($this->Kinder(), 'name');
        $faecher = array_column($this->Faecher(), 'name');
        $slots   = $this->Stunden();
        $konflikte = [];
        $verwaist  = [];
        foreach ($slots as $s) {
            if (!in_array($s['childId'], $kinder, true)) {
                $verwaist[] = sprintf('%s %s: Kind „%s" gibt es nicht',
                    TimetableCalc::TagKurz((int)$s['weekday']), $s['start'], $s['childId']);
            } elseif ($s['subjectId'] !== '' && !in_array($s['subjectId'], $faecher, true)) {
                $verwaist[] = sprintf('%s %s %s: Fach „%s" gibt es nicht',
                    $s['childId'], TimetableCalc::TagKurz((int)$s['weekday']), $s['start'], $s['subjectId']);
            }
            $gegen = TimetableCalc::Konflikt($s, $slots);
            if ($gegen !== null) {
                $paar = [$s['id'], $gegen['id']];
                sort($paar);
                $konflikte[implode('|', $paar)] = sprintf('%s %s: %s–%s trifft %s–%s',
                    $s['childId'], TimetableCalc::TagKurz((int)$s['weekday']),
                    $s['start'], $s['end'], $gegen['start'], $gegen['end']);
            }
        }
        return ['konflikte' => array_values($konflikte), 'verwaist' => $verwaist];
    }
}

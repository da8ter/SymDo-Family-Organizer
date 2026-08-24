<?php

declare(strict_types=1);

require_once __DIR__ . '/libs/TimetableCalc.php';
require_once __DIR__ . '/libs/TimetableSubjects.php';
require_once __DIR__ . '/libs/TimetableStore.php';
require_once __DIR__ . '/libs/HolidaySource.php';

/**
 * Stundenplan der Kinder: eine Wochenvorlage, gepflegt im Backend, gezeigt als
 * Wochenraster oder als Timeline.
 *
 * Eine Instanz haelt die Daten. Wer beide Darstellungen nebeneinander will,
 * legt eine zweite Instanz an, stellt sie auf „Timeline" und waehlt die erste
 * als Datenquelle — dasselbe Verhaeltnis wie ToDoOverview zu ToDoList, aber
 * ohne zweites Modul.
 */
class Stundenplan extends IPSModuleStrict
{
    use TimetableStore;
    use TimetableHolidays;

    private const EIGENE_GUID = '{C22E0A96-1BC7-4029-B8C5-7E94E4F2A9D9}';
    private const GATEWAY_GUID = '{E677FE7B-28C9-4124-8B58-8A1FE2657E8D}';

    public function Create(): void
    {
        parent::Create();

        // Pflicht, damit Symcon die HTML-Kachel aus GetVisualizationTile rendert.
        $this->SetVisualizationType(1);

        // Die vier Listen SIND die Daten — es gibt keinen zweiten Stand.
        $this->RegisterPropertyString('Children', '[]');
        $this->RegisterPropertyString('Subjects',
            (string)json_encode(TimetableSubjects::Vorgabefaecher(), JSON_UNESCAPED_UNICODE));
        $this->RegisterPropertyString('Slots', '[]');
        $this->RegisterPropertyString('Care', '[]');

        $this->RegisterPropertyString('Display', 'week');
        $this->RegisterPropertyInteger('SourceInstanceID', 0);
        $this->RegisterPropertyInteger('GatewayInstanceID', 0);
        $this->RegisterPropertyBoolean('ShowInApp', false);

        $this->RegisterPropertyString('HolidaySource', 'openholidays');
        $this->RegisterPropertyString('HolidayRegion', 'DE-HE');
        $this->RegisterPropertyInteger('AlmanacInstanceID', 0);

        $this->RegisterAttributeString('Holidays', '[]');
        $this->RegisterAttributeInteger('HolidaysFetched', 0);

        // Ein Takt fuer beides: die Ferien einmal taeglich erneuern und die
        // Kachel weiterstellen, wenn eine Stunde vorbei ist. Der Abstand wird
        // in ApplyChanges gesetzt.
        $this->RegisterTimer('Refresh', 0, 'STPL_Refresh($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }

        // Fuenf Minuten: fein genug, damit „als naechstes" und die Heute-Kapsel
        // nicht veralten, grob genug, um nichts zu kosten. Der Ferien-Abruf
        // haengt am selben Takt, laeuft aber nur einmal am Tag (siehe Refresh).
        $this->SetTimerInterval('Refresh', 5 * 60 * 1000);

        $this->PushState();
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
        }
    }

    public function Destroy(): void
    {
        parent::Destroy();
    }

    // ─────────────────────────── Oeffentliche API ───────────────────────────

    /** Der fertige Zustand als JSON — fuer die Kachel, das Gateway und die App. */
    public function GetPlan(): string
    {
        return (string)json_encode($this->Plan(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Derselbe Plan, aber fuer einen bestimmten Tag („2026-08-25").
     *
     * Gebraucht vom Briefing: die Abendvorschau spricht ueber MORGEN, und Ferien
     * wie die Heute-Marke muessen sich dann auf morgen beziehen. Eigene Funktion
     * statt eines Parameters an GetPlan, damit vorhandene Aufrufer unveraendert
     * bleiben.
     */
    public function GetPlanForDate(string $Date): string
    {
        $quelle = $this->ReadPropertyInteger('SourceInstanceID');
        if ($quelle > 0 && $quelle !== $this->InstanceID && IPS_InstanceExists($quelle)
            && function_exists('STPL_GetPlanForDate')) {
            $roh  = @STPL_GetPlanForDate($quelle, $Date);
            $plan = json_decode((string)$roh, true);
            if (is_array($plan)) {
                $plan['mode'] = $this->Darstellung();
                return (string)json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }
        return (string)json_encode($this->PlanAufbauen($Date),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** Ferien erneuern und die Anzeige nachziehen. Haengt am Timer. */
    public function Refresh(): void
    {
        $letzte = $this->ReadAttributeInteger('HolidaysFetched');
        if ((string)$this->ReadPropertyString('HolidaySource') !== 'none'
            && date('Y-m-d', $letzte) !== date('Y-m-d')) {
            $this->FerienAbrufen();
        }
        $this->PushState();
    }

    /** Ferien von Hand abrufen — der Knopf im Formular. */
    public function FetchHolidays(): string
    {
        return $this->FerienAbrufen();
    }

    // ───────────────────────────── Darstellung ─────────────────────────────

    public function GetVisualizationTile(): string
    {
        $pfad = __DIR__ . '/module.html';
        $html = @file_get_contents($pfad);
        if (!is_string($html)) {
            $this->LogMessage('GetVisualizationTile: module.html nicht lesbar, Pfad=' . $pfad, KL_WARNING);
            return '';
        }
        // Anfangszustand inline: die Kachel zeigt sofort etwas, ohne auf den
        // ersten Push zu warten.
        return $html . '<script>handleMessage(' . $this->GetPlan() . ');</script>';
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'FetchHolidays':
                $this->UpdateFormField('HolidayStatus', 'caption', $this->FerienAbrufen());
                $this->PushState();
                return;
            case 'HolidaySourcePick':
                $this->FerienFelderZeigen((string)$Value);
                return;
            case 'FillSubjects':
                $this->UpdateFormField('SubjectStatus', 'caption', $this->FaecherNachtragen());
                return;
            case 'GetState':
                // Nur zeigen, nichts aendern — die Kachel fragt beim Oeffnen.
                $this->PushState();
                return;
        }
        parent::RequestAction($Ident, $Value);
    }

    private function PushState(): void
    {
        $this->UpdateVisualizationValue($this->GetPlan());
    }

    /**
     * Der anzuzeigende Plan. Zeigt die Instanz auf eine andere, kommt er von
     * dort — die Darstellung bleibt aber die eigene, denn genau dafuer gibt es
     * die zweite Instanz.
     */
    private function Plan(): array
    {
        $quelle = $this->ReadPropertyInteger('SourceInstanceID');
        // function_exists davor: die oeffentliche Funktion entsteht erst, wenn
        // der Kernel das Modul geladen hat. Direkt gerufen waere das ein Fatal,
        // kein Fehlschlag — dieselbe Falle wie seinerzeit bei BRING_RemoveItem.
        if ($quelle > 0 && $quelle !== $this->InstanceID && IPS_InstanceExists($quelle)
            && function_exists('STPL_GetPlan')) {
            $roh = @STPL_GetPlan($quelle);
            $plan = json_decode((string)$roh, true);
            if (is_array($plan)) {
                $plan['mode'] = $this->Darstellung();
                return $plan;
            }
            // Nicht lesbar: lieber der eigene (meist leere) Plan als eine
            // Kachel, die gar nichts zeichnet.
        }
        return $this->PlanAufbauen();
    }

    private function Darstellung(): string
    {
        return (string)$this->ReadPropertyString('Display') === 'timeline' ? 'timeline' : 'week';
    }

    // ─────────────────────────────── Formular ───────────────────────────────

    public function GetConfigurationForm(): string
    {
        $kinder  = array_column($this->Kinder(), 'name');
        $faecher = array_column($this->Faecher(), 'name');
        $pruef   = $this->PlanPruefen();

        $auswahl = static function (array $namen, string $leer): array {
            $optionen = [['caption' => $leer, 'value' => '']];
            foreach ($namen as $n) {
                $optionen[] = ['caption' => $n, 'value' => $n];
            }
            return $optionen;
        };
        $tage = [];
        foreach (TimetableCalc::Wochentage(true) as $t) {
            $tage[] = ['caption' => $this->Translate(TimetableCalc::TagKurz($t)), 'value' => $t];
        }

        $form = [
            'elements' => [
                [
                    'type'    => 'Label',
                    'caption' => $this->Translate("A weekly template for the children's school days: subjects, times, care. It shows as a week grid or as a timeline — and, if you switch it on, as a card in the SymDo app.\n\nIt is a template, not a calendar: substitutions, cancellations and one-off changes are not part of it.")
                ],
                $this->KinderBereich($auswahl),
                $this->FaecherBereich(),
                $this->StundenBereich($auswahl, $kinder, $faecher, $tage),
                $this->BetreuungBereich($auswahl, $kinder, $tage),
                $this->FerienBereich(),
                $this->AnzeigeBereich(),
                [
                    'type'    => 'Label',
                    'name'    => 'PlanStatus',
                    'caption' => $this->StatusText($pruef)
                ],
            ],
            'actions' => [],
            'status'  => [],
        ];
        return (string)json_encode($form, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function KinderBereich(callable $auswahl): array
    {
        // Mitglieder des Gateways, wenn eines gewaehlt ist. Kinder zuerst: die
        // Rolle steht in der Gateway-Eigenschaft und spart das Suchen.
        return [
            'type'     => 'ExpansionPanel',
            'caption'  => $this->Translate('Children'),
            'expanded' => true,
            'items'    => [
                [
                    'type'     => 'List',
                    'name'     => 'Children',
                    'rowCount' => 4,
                    'add'      => true,
                    'delete'   => true,
                    'columns'  => [
                        ['caption' => $this->Translate('Name'), 'name' => 'name', 'width' => 'auto',
                         'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                        ['caption' => $this->Translate('Color'), 'name' => 'color', 'width' => '110px',
                         'add' => 0x1E88E5, 'edit' => ['type' => 'SelectColor']],
                        ['caption' => $this->Translate('Family member'), 'name' => 'userId', 'width' => '190px',
                         'add' => '', 'edit' => ['type' => 'Select', 'options' => $auswahl($this->GatewayMitglieder(), $this->Translate('— none —'))]],
                        ['caption' => $this->Translate('Saturday'), 'name' => 'saturday', 'width' => '110px',
                         'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => $this->Translate('Every other week'), 'name' => 'biweekly', 'width' => '150px',
                         'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => $this->Translate('Calendar weeks'), 'name' => 'parity', 'width' => '150px',
                         'add' => 'even', 'edit' => ['type' => 'Select', 'options' => [
                             ['caption' => $this->Translate('Even weeks'), 'value' => 'even'],
                             ['caption' => $this->Translate('Odd weeks'), 'value' => 'odd'],
                         ]]],
                    ],
                ],
                [
                    'type'    => 'Label',
                    'caption' => $this->Translate('The family member links this child to SymDo — the card in the app then appears under the right name. Saturday only shows in the plan when it is switched on here; "every other week" follows the parity of the ISO calendar week, exactly like a school notice ("lessons in odd weeks").')
                ],
            ],
        ];
    }

    private function FaecherBereich(): array
    {
        return [
            'type'     => 'ExpansionPanel',
            'caption'  => $this->Translate('Subjects'),
            'expanded' => false,
            'items'    => [
                [
                    'type'        => 'List',
                    'name'        => 'Subjects',
                    'rowCount'    => 8,
                    'add'         => true,
                    'delete'      => true,
                    'changeOrder' => true,
                    'columns'     => [
                        ['caption' => $this->Translate('Subject'), 'name' => 'name', 'width' => 'auto',
                         'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                        ['caption' => $this->Translate('Icon'), 'name' => 'icon', 'width' => '120px',
                         'add' => 'book', 'edit' => ['type' => 'SelectIcon']],
                        ['caption' => $this->Translate('Color'), 'name' => 'color', 'width' => '110px',
                         'add' => 0x1E88E5, 'edit' => ['type' => 'SelectColor']],
                    ],
                ],
                [
                    'type'    => 'Button',
                    'caption' => $this->Translate('Add subjects from the lessons'),
                    'onClick' => 'IPS_RequestAction($id, "FillSubjects", 0);'
                ],
                [
                    'type'    => 'Label',
                    'name'    => 'SubjectStatus',
                    'caption' => ' '
                ],
                [
                    'type'    => 'Label',
                    'caption' => $this->Translate('Icon and color belong to the subject and apply everywhere it appears. A single lesson may override the color. The button adds subjects that occur in the lessons but are missing here — with a suggested icon and color that you can change afterwards.')
                ],
            ],
        ];
    }

    private function StundenBereich(callable $auswahl, array $kinder, array $faecher, array $tage): array
    {
        return [
            'type'     => 'ExpansionPanel',
            'caption'  => $this->Translate('Lessons'),
            'expanded' => true,
            'items'    => [
                [
                    'type'     => 'List',
                    'name'     => 'Slots',
                    'rowCount' => 12,
                    'add'      => true,
                    'delete'   => true,
                    'sort'     => ['column' => 'weekday', 'direction' => 'ascending'],
                    'columns'  => [
                        ['caption' => $this->Translate('Child'), 'name' => 'child', 'width' => '150px',
                         'add' => $kinder[0] ?? '', 'edit' => ['type' => 'Select', 'options' => $auswahl($kinder, $this->Translate('— pick —'))]],
                        ['caption' => $this->Translate('Day'), 'name' => 'weekday', 'width' => '90px',
                         'add' => 1, 'edit' => ['type' => 'Select', 'options' => $tage]],
                        ['caption' => $this->Translate('Subject'), 'name' => 'subject', 'width' => 'auto',
                         'add' => $faecher[0] ?? '', 'edit' => ['type' => 'Select', 'options' => $auswahl($faecher, $this->Translate('— pick —'))]],
                        ['caption' => $this->Translate('From'), 'name' => 'start', 'width' => '100px',
                         'add' => '07:45', 'edit' => ['type' => 'ValidationTextBox', 'validate' => '^\\d{1,2}:\\d{2}$']],
                        ['caption' => $this->Translate('To'), 'name' => 'end', 'width' => '100px',
                         'add' => '08:30', 'edit' => ['type' => 'ValidationTextBox', 'validate' => '^\\d{1,2}:\\d{2}$']],
                        ['caption' => $this->Translate('Color'), 'name' => 'color', 'width' => '110px',
                         'add' => -1, 'edit' => ['type' => 'SelectColor']],
                    ],
                ],
                [
                    'type'    => 'Label',
                    'caption' => $this->Translate('Times as HH:MM. Leave the color empty to use the subject color. Lessons that touch (one ends when the next begins) are normal and are not reported as a clash.')
                ],
            ],
        ];
    }

    private function BetreuungBereich(callable $auswahl, array $kinder, array $tage): array
    {
        return [
            'type'     => 'ExpansionPanel',
            'caption'  => $this->Translate('After-school care'),
            'expanded' => false,
            'items'    => [
                [
                    'type'     => 'List',
                    'name'     => 'Care',
                    'rowCount' => 5,
                    'add'      => true,
                    'delete'   => true,
                    'columns'  => [
                        ['caption' => $this->Translate('Child'), 'name' => 'child', 'width' => '150px',
                         'add' => $kinder[0] ?? '', 'edit' => ['type' => 'Select', 'options' => $auswahl($kinder, $this->Translate('— pick —'))]],
                        ['caption' => $this->Translate('Day'), 'name' => 'weekday', 'width' => '90px',
                         'add' => 1, 'edit' => ['type' => 'Select', 'options' => $tage]],
                        ['caption' => $this->Translate('Until'), 'name' => 'end', 'width' => '100px',
                         'add' => '16:00', 'edit' => ['type' => 'ValidationTextBox', 'validate' => '^\\d{1,2}:\\d{2}$']],
                    ],
                ],
                [
                    'type'    => 'Label',
                    'caption' => $this->Translate('A grey block runs from the end of the lessons until this time. It only appears on days that have lessons, and it does not count as teaching time.')
                ],
            ],
        ];
    }

    private function FerienBereich(): array
    {
        $quelle = (string)$this->ReadPropertyString('HolidaySource');
        $regionen = [];
        foreach ([
            'DE-BW' => 'Baden-Württemberg', 'DE-BY' => 'Bayern', 'DE-BE' => 'Berlin',
            'DE-BB' => 'Brandenburg', 'DE-HB' => 'Bremen', 'DE-HH' => 'Hamburg',
            'DE-HE' => 'Hessen', 'DE-MV' => 'Mecklenburg-Vorpommern', 'DE-NI' => 'Niedersachsen',
            'DE-NW' => 'Nordrhein-Westfalen', 'DE-RP' => 'Rheinland-Pfalz', 'DE-SL' => 'Saarland',
            'DE-SN' => 'Sachsen', 'DE-ST' => 'Sachsen-Anhalt', 'DE-SH' => 'Schleswig-Holstein',
            'DE-TH' => 'Thüringen',
        ] as $code => $name) {
            $regionen[] = ['caption' => $name, 'value' => $code];
        }

        return [
            'type'     => 'ExpansionPanel',
            'caption'  => $this->Translate('Holidays'),
            'expanded' => false,
            'items'    => [
                [
                    'type'    => 'Select',
                    'name'    => 'HolidaySource',
                    'width'   => '400px',
                    'caption' => $this->Translate('Source'),
                    'onChange' => 'IPS_RequestAction($id, "HolidaySourcePick", $HolidaySource);',
                    'options' => [
                        ['caption' => $this->Translate('None — the plan always applies'), 'value' => 'none'],
                        ['caption' => $this->Translate('OpenHolidaysAPI (free, no account)'), 'value' => 'openholidays'],
                        ['caption' => $this->Translate('Almanac module (untested)'), 'value' => 'almanac'],
                    ],
                ],
                [
                    'type'    => 'Select',
                    'name'    => 'HolidayRegion',
                    'width'   => '400px',
                    'caption' => $this->Translate('Federal state'),
                    'visible' => $quelle === 'openholidays',
                    'options' => $regionen,
                ],
                [
                    'type'    => 'SelectInstance',
                    'name'    => 'AlmanacInstanceID',
                    'width'   => '400px',
                    'caption' => $this->Translate('Almanac instance'),
                    'visible' => $quelle === 'almanac',
                ],
                [
                    'type'    => 'Label',
                    'name'    => 'AlmanacHint',
                    'visible' => $quelle === 'almanac',
                    'caption' => $this->Translate('UNTESTED: the Almanac module is not installed on this system, so this connection was written against the documentation, not against the real interface. If it does not answer, nothing breaks — the plan then simply knows no holidays. Tell me once the module is installed and I will check it against the real thing.')
                ],
                [
                    'type'    => 'Button',
                    'name'    => 'HolidayFetch',
                    'caption' => $this->Translate('Fetch holidays now'),
                    'visible' => $quelle !== 'none',
                    'onClick' => 'IPS_RequestAction($id, "FetchHolidays", 0);'
                ],
                [
                    'type'    => 'Label',
                    'name'    => 'HolidayStatus',
                    'caption' => $this->FerienStatus()
                ],
            ],
        ];
    }

    private function AnzeigeBereich(): array
    {
        return [
            'type'     => 'ExpansionPanel',
            'caption'  => $this->Translate('Display'),
            'expanded' => false,
            'items'    => [
                [
                    'type'    => 'Select',
                    'name'    => 'Display',
                    'width'   => '400px',
                    'caption' => $this->Translate('This tile shows'),
                    'options' => [
                        ['caption' => $this->Translate('Week grid'), 'value' => 'week'],
                        ['caption' => $this->Translate('Timeline (compact)'), 'value' => 'timeline'],
                    ],
                ],
                [
                    'type'         => 'SelectInstance',
                    'name'         => 'SourceInstanceID',
                    'width'        => '400px',
                    'caption'      => $this->Translate('Data from another timetable (empty = own data)'),
                    'validModules' => [self::EIGENE_GUID],
                ],
                [
                    'type'         => 'SelectInstance',
                    'name'         => 'GatewayInstanceID',
                    'width'        => '400px',
                    'caption'      => $this->Translate('SymDo gateway'),
                    'validModules' => [self::GATEWAY_GUID],
                ],
                [
                    'type'    => 'CheckBox',
                    'name'    => 'ShowInApp',
                    'caption' => $this->Translate('Show the timeline in the SymDo app')
                ],
                [
                    'type'    => 'Label',
                    'caption' => $this->Translate('For both views at once: create a second instance of this module, set it to "Timeline" and pick this instance as its data source. It then shows the same plan in the other shape.')
                ],
            ],
        ];
    }

    // ───────────────────────────── Formular-Hilfen ─────────────────────────────

    private function FerienFelderZeigen(string $quelle): void
    {
        $this->UpdateFormField('HolidayRegion', 'visible', $quelle === 'openholidays');
        $this->UpdateFormField('AlmanacInstanceID', 'visible', $quelle === 'almanac');
        $this->UpdateFormField('AlmanacHint', 'visible', $quelle === 'almanac');
        $this->UpdateFormField('HolidayFetch', 'visible', $quelle !== 'none');
    }

    private function FerienStatus(): string
    {
        $wann = $this->ReadAttributeInteger('HolidaysFetched');
        if ($wann <= 0) {
            return $this->Translate('Not fetched yet.');
        }
        $stand = $this->Ferien();
        $heute = $this->FerienAmTag(date('Y-m-d'));
        $text = sprintf($this->Translate('%d periods, fetched %s.'),
            count($stand), date('d.m.Y H:i', $wann));
        if ($heute !== null) {
            $text .= ' ' . sprintf($this->Translate('Today: %s (until %s).'),
                $heute['name'], date('d.m.Y', (int)strtotime($heute['until'])));
        }
        return $text;
    }

    /**
     * Traegt Faecher nach, die in den Stunden vorkommen, aber nicht in der
     * Faecher-Liste stehen. Geschrieben wird ueber IPS_SetProperty, damit der
     * Nutzer die Vorschlaege danach im Formular anpassen kann.
     */
    private function FaecherNachtragen(): string
    {
        $vorhanden = array_column($this->Faecher(), 'name');
        $neu = [];
        foreach ($this->Stunden() as $s) {
            $name = trim((string)$s['subjectId']);
            if ($name === '' || in_array($name, $vorhanden, true) || isset($neu[$name])) {
                continue;
            }
            $neu[$name] = TimetableSubjects::Vorschlag($name);
        }
        if ($neu === []) {
            return $this->Translate('Nothing to add — every subject in the lessons already exists.');
        }
        $liste = json_decode((string)@IPS_GetProperty($this->InstanceID, 'Subjects'), true);
        $liste = is_array($liste) ? $liste : [];
        foreach ($neu as $name => $v) {
            $liste[] = ['name' => $name, 'icon' => $v['icon'], 'color' => $v['color']];
        }
        @IPS_SetProperty($this->InstanceID, 'Subjects',
            (string)json_encode($liste, JSON_UNESCAPED_UNICODE));
        @IPS_ApplyChanges($this->InstanceID);
        $this->UpdateFormField('Subjects', 'values', (string)json_encode($liste, JSON_UNESCAPED_UNICODE));
        return sprintf($this->Translate('%d subjects added: %s'),
            count($neu), implode(', ', array_keys($neu)));
    }

    private function StatusText(array $pruef): string
    {
        $teile = [];
        if ($pruef['konflikte'] !== []) {
            $teile[] = sprintf($this->Translate('%d clashes: %s'),
                count($pruef['konflikte']), implode(' · ', array_slice($pruef['konflikte'], 0, 4)));
        }
        if ($pruef['verwaist'] !== []) {
            $teile[] = sprintf($this->Translate('%d lessons without a match: %s'),
                count($pruef['verwaist']), implode(' · ', array_slice($pruef['verwaist'], 0, 4)));
        }
        if ($teile === []) {
            $kinder = count($this->Kinder());
            $stunden = count($this->Stunden());
            return sprintf($this->Translate('%d children, %d lessons, nothing to complain about.'),
                $kinder, $stunden);
        }
        return implode('  |  ', $teile);
    }

    /** Namen der Familienmitglieder aus dem Gateway, Kinder zuerst. */
    private function GatewayMitglieder(): array
    {
        $gw = $this->ReadPropertyInteger('GatewayInstanceID');
        if ($gw <= 0 || !IPS_InstanceExists($gw) || !function_exists('TGW_GetUsers')) {
            return [];
        }
        try {
            $roh = json_decode((string)@TGW_GetUsers($gw), true);
        } catch (\Throwable $e) {
            return [];
        }
        if (!is_array($roh)) {
            return [];
        }
        return array_values(array_filter(array_map(
            static fn(array $u): string => trim((string)($u['name'] ?? '')),
            array_filter($roh, 'is_array')), static fn(string $n): bool => $n !== ''));
    }
}

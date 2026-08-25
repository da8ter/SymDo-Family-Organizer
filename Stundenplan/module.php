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
        // Die alte Sammelliste. Bleibt registriert, damit die einmalige
        // Wanderung auf die Tageslisten einen Beleg hat und ein Rueckweg offen
        // bleibt; im Formular steht sie nicht mehr.
        $this->RegisterPropertyString('Slots', '[]');
        $this->RegisterPropertyString('Care', '[]');

        // Je Kind und Wochentag eine eigene Liste. Symcon registriert
        // Eigenschaften FEST in Create() — eine wachsende Zahl von Kindern ist
        // damit unmoeglich, die Hoechstzahl muss hier stehen.
        for ($kind = 1; $kind <= self::MAX_KINDER; $kind++) {
            for ($tag = 1; $tag <= 6; $tag++) {
                $this->RegisterPropertyString(self::SlotProp($kind, $tag), '[]');
                // Die Betreuung steht seit dem Umbau UNTER der Tagesliste statt
                // in einem eigenen Bereich: Schalter plus Endzeit, dort wo der
                // Tag ohnehin gepflegt wird.
                $this->RegisterPropertyBoolean(self::CareProp($kind, $tag), false);
                $this->RegisterPropertyString(self::CareEndProp($kind, $tag),
                    TimetableCalc::ZeitFeld('16:00'));
            }
        }
        // Welcher Name lag beim letzten Speichern auf welchem Index — daran
        // erkennt ApplyChanges, dass die Kinder umsortiert wurden.
        $this->RegisterAttributeString('SlotOwners', '[]');
        // Was der letzte Abgleich verschoben, umbenannt oder geleert hat — steht
        // in der Statuszeile, damit es niemandem entgeht.
        $this->RegisterAttributeString('SlotOwnersReport', '');

        $this->RegisterPropertyString('Display', 'week');
        $this->RegisterPropertyInteger('SourceInstanceID', 0);
        $this->RegisterPropertyInteger('GatewayInstanceID', 0);

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

        // Einmalig: die alte Sammelliste auf die Tageslisten verteilen.
        $this->StundenWandern();
        $this->BetreuungWandern();
        // NACH der Wanderung: sie legt die alten Zeichenketten erst ab.
        $this->ZeitenWandern();
        // Und danach: sind die Kinder umsortiert worden, wandern ihre Stunden mit.
        $this->StundenAbgleichen();

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

    /**
     * Einmalige Umschrift der Zeiten auf die Form des Zeitwaehlers.
     *
     * Die Spalten Von und Bis sind SelectTime. Die Konsole liest eine solche
     * Zelle mit JSON.parse und schreibt „ungültig", wenn das misslingt — und
     * genau das stand ueberall, weil die Wanderung die alten Zeichenketten
     * („07:45") unveraendert uebernommen hatte. Der Plan zeigte trotzdem die
     * richtigen Zeiten, weil ZeitText beide Formen liest; nur im Formular war
     * nichts mehr zu erkennen.
     *
     * Laeuft, solange irgendwo noch eine Zeichenkette steht, und schreibt nur
     * dann. Die Betreuung ist nicht betroffen: sie wurde von Anfang an als
     * Waehler-Objekt abgelegt.
     */
    private function ZeitenWandern(): void
    {
        static $laeuft = false;
        if ($laeuft) {
            return;
        }
        $neu = [];
        for ($kind = 1; $kind <= self::MAX_KINDER; $kind++) {
            for ($tag = 1; $tag <= 6; $tag++) {
                $prop   = self::SlotProp($kind, $tag);
                $zeilen = json_decode((string)@IPS_GetProperty($this->InstanceID, $prop), true);
                if (!is_array($zeilen) || $zeilen === []) {
                    continue;
                }
                $geaendert = false;
                foreach ($zeilen as $i => $z) {
                    if (!is_array($z)) {
                        continue;
                    }
                    foreach (['start', 'end'] as $feld) {
                        $wert = (string)($z[$feld] ?? '');
                        // Schon ein Objekt? Dann nichts tun.
                        if ($wert === '' || str_starts_with(trim($wert), '{')) {
                            continue;
                        }
                        $text = TimetableCalc::ZeitText($wert);
                        $zeilen[$i][$feld] = TimetableCalc::ZeitFeld($text === '' ? '00:00' : $text);
                        $geaendert = true;
                    }
                }
                if ($geaendert) {
                    $neu[$prop] = (string)json_encode($zeilen, JSON_UNESCAPED_UNICODE);
                }
            }
        }
        if ($neu === []) {
            return;
        }
        $laeuft = true;
        try {
            foreach ($neu as $prop => $wert) {
                @IPS_SetProperty($this->InstanceID, $prop, $wert);
            }
            @IPS_ApplyChanges($this->InstanceID);
        } finally {
            $laeuft = false;
        }
        $this->LogMessage(sprintf('Stundenplan: Zeiten in %d Tageslisten auf den Zeitwaehler umgeschrieben',
            count($neu)), KL_NOTIFY);
    }

    /**
     * Einmalige Wanderung der Betreuung von der alten Sammelliste auf die
     * Schalter unter den Tageslisten.
     *
     * Gleiche Bauart wie StundenWandern: laeuft nur, solange in `Care` Zeilen
     * stehen, leert die Liste danach und faellt fuer immer aus. Zeilen ohne
     * passendes Kind gehen verloren — sie hatten schon vorher keine Wirkung.
     */
    private function BetreuungWandern(): void
    {
        static $laeuft = false;
        if ($laeuft) {
            return;
        }
        $alt = json_decode((string)@IPS_GetProperty($this->InstanceID, 'Care'), true);
        if (!is_array($alt) || $alt === []) {
            return;
        }
        $nummer = [];
        foreach (array_values($this->Kinder()) as $i => $kind) {
            $nummer[(string)$kind['name']] = $i + 1;
        }
        $ziel = [];
        $verwaist = 0;
        foreach ($alt as $z) {
            if (!is_array($z)) {
                continue;
            }
            $nr   = $nummer[trim((string)($z['child'] ?? ''))] ?? 0;
            $tag  = (int)($z['weekday'] ?? 0);
            $ende = TimetableCalc::ZeitText($z['end'] ?? '');
            if ($nr < 1 || $nr > self::MAX_KINDER || $tag < 1 || $tag > 6 || $ende === '') {
                $verwaist++;
                continue;
            }
            $ziel[$nr][$tag] = $ende;
        }

        $laeuft = true;
        try {
            foreach ($ziel as $nr => $tage) {
                foreach ($tage as $tag => $ende) {
                    @IPS_SetProperty($this->InstanceID, self::CareProp((int)$nr, (int)$tag), true);
                    @IPS_SetProperty($this->InstanceID, self::CareEndProp((int)$nr, (int)$tag),
                        TimetableCalc::ZeitFeld($ende));
                }
            }
            @IPS_SetProperty($this->InstanceID, 'Care', '[]');
            @IPS_ApplyChanges($this->InstanceID);
        } finally {
            $laeuft = false;
        }
        $this->LogMessage(sprintf('Stundenplan: %d Betreuungszeiten auf die Tage verteilt%s',
            count($alt) - $verwaist,
            $verwaist > 0 ? sprintf(', %d ohne passendes Kind verworfen', $verwaist) : ''), KL_NOTIFY);
    }

    /**
     * Einmalige Wanderung von der alten Sammelliste auf die Tageslisten.
     *
     * Laeuft nur, solange in `Slots` noch Zeilen stehen; danach wird sie geleert
     * und die Wanderung faellt fuer immer aus. Die Eigenschaft bleibt
     * registriert — als Beleg und damit ein Rueckweg offen bleibt.
     *
     * Der Riegel ist noetig, weil das Schreiben ueber IPS_ApplyChanges laeuft und
     * sich ApplyChanges damit selbst ruft (Muster aus EnforceSyncBackend).
     */
    private function StundenWandern(): void
    {
        static $laeuft = false;
        if ($laeuft) {
            return;
        }
        $alt = json_decode((string)@IPS_GetProperty($this->InstanceID, 'Slots'), true);
        if (!is_array($alt) || $alt === []) {
            return;
        }
        $nummer = [];
        foreach (array_values($this->Kinder()) as $i => $kind) {
            $nummer[(string)$kind['name']] = $i + 1;
        }
        $eimer = [];
        $verwaist = 0;
        foreach ($alt as $z) {
            if (!is_array($z)) {
                continue;
            }
            $nr  = $nummer[trim((string)($z['child'] ?? ''))] ?? 0;
            $tag = (int)($z['weekday'] ?? 0);
            if ($nr < 1 || $nr > self::MAX_KINDER || $tag < 1 || $tag > 6) {
                $verwaist++;
                continue;
            }
            $eimer[$nr][$tag][] = [
                'subject' => trim((string)($z['subject'] ?? '')),
                'start'   => trim((string)($z['start'] ?? '')),
                'end'     => trim((string)($z['end'] ?? '')),
            ];
        }

        $laeuft = true;
        try {
            foreach ($eimer as $nr => $tage) {
                foreach ($tage as $tag => $zeilen) {
                    usort($zeilen, static fn(array $a, array $b): int
                        => TimetableCalc::Minuten($a['start']) <=> TimetableCalc::Minuten($b['start']));
                    @IPS_SetProperty($this->InstanceID, self::SlotProp((int)$nr, (int)$tag),
                        (string)json_encode($zeilen, JSON_UNESCAPED_UNICODE));
                }
            }
            @IPS_SetProperty($this->InstanceID, 'Slots', '[]');
            @IPS_ApplyChanges($this->InstanceID);
        } finally {
            $laeuft = false;
        }
        $this->LogMessage(sprintf('Stundenplan: %d Stunden auf die Tageslisten verteilt%s',
            count($alt) - $verwaist,
            $verwaist > 0 ? sprintf(', %d ohne passendes Kind verworfen', $verwaist) : ''), KL_NOTIFY);
    }

    /**
     * Die Tageslisten haengen an der POSITION des Kindes, nicht am Namen. Wer die
     * Kinder umsortiert oder eines in der Mitte loescht, verschoebe sonst alle
     * Stundenplaene dahinter.
     *
     * Genau dieser Fehler ist am 24.08.2026 bei den Personas-Stimmen aufgetreten:
     * neun Zeilen auf acht Personas, ab der vierten alles um eins verschoben, und
     * der Drillsergeant sprach mit der Stimme des Komikers. Deshalb wird hier
     * nicht auf Sichtbarkeit vertraut, sondern abgeglichen.
     */
    private function StundenAbgleichen(): void
    {
        static $laeuft = false;
        if ($laeuft) {
            return;
        }
        $neu = [];
        foreach (array_slice(array_values($this->Kinder()), 0, self::MAX_KINDER) as $kind) {
            $neu[] = (string)$kind['name'];
        }
        $roh = json_decode((string)@$this->ReadAttributeString('SlotOwners'), true);
        $alt = is_array($roh) ? array_values(array_map('strval', $roh)) : [];

        // ERSTER Lauf: es gibt keinen Vorstand, also gibt es auch nichts zu
        // verschieben. Nur merken — sonst faende der Abgleich unten fuer JEDES
        // Kind „kein Treffer" und leerte die eben gewanderten Listen.
        if ($alt === []) {
            @$this->WriteAttributeString('SlotOwners', (string)json_encode($neu, JSON_UNESCAPED_UNICODE));
            return;
        }
        if ($alt === $neu) {
            return;
        }

        // Ein Tag sind DREI Eigenschaften: Stundenliste, Betreuungsschalter und
        // Endzeit. Sie muessen zusammen wandern — zoege nur die Liste um, stuende
        // die Betreuung des Vorgaengers unter dem Plan des Nachfolgers.
        $listen = [];
        for ($i = 1; $i <= self::MAX_KINDER; $i++) {
            for ($t = 1; $t <= 6; $t++) {
                $listen[$i][$t] = array_map(
                    fn(string $prop) => @IPS_GetProperty($this->InstanceID, $prop),
                    self::TagProps($i, $t));
            }
        }
        $leer = array_fill(1, 6, ['[]', false, TimetableCalc::ZeitFeld('16:00')]);
        $ziel = [];
        $bewegt = [];
        $benannt = [];
        $geleert = [];
        foreach ($neu as $j => $name) {
            $nr = $j + 1;
            $altIndex = array_search($name, $alt, true);
            if ($altIndex !== false) {
                $ziel[$nr] = $listen[$altIndex + 1];
                if ((int)$altIndex !== $j) {
                    $bewegt[] = sprintf('%s (%d→%d)', $name, (int)$altIndex + 1, $nr);
                }
                continue;
            }
            // Kein Treffer ueber den Namen. War der fruehere Eigentümer dieses
            // Platzes ebenfalls verschwunden, ist es eine UMBENENNUNG — die
            // Stunden bleiben liegen. Sonst ist es ein neues Kind, und das erbt
            // nicht den Plan eines fremden.
            $frueher = (string)($alt[$j] ?? '');
            if ($frueher !== '' && !in_array($frueher, $neu, true)) {
                $ziel[$nr] = $listen[$nr];
                $benannt[] = sprintf('%s → %s', $frueher, $name);
            } else {
                $ziel[$nr] = $leer;
                $geleert[] = $name;
            }
        }
        for ($nr = count($neu) + 1; $nr <= self::MAX_KINDER; $nr++) {
            $ziel[$nr] = $leer;
        }

        $laeuft = true;
        try {
            $geaendert = false;
            foreach ($ziel as $nr => $tage) {
                foreach ($tage as $tag => $werte) {
                    foreach (self::TagProps((int)$nr, (int)$tag) as $k => $prop) {
                        if (($listen[$nr][$tag][$k] ?? null) === $werte[$k]) {
                            continue;
                        }
                        @IPS_SetProperty($this->InstanceID, $prop, $werte[$k]);
                        $geaendert = true;
                    }
                }
            }
            @$this->WriteAttributeString('SlotOwners', (string)json_encode($neu, JSON_UNESCAPED_UNICODE));
            if ($geaendert) {
                @IPS_ApplyChanges($this->InstanceID);
            }
        } finally {
            $laeuft = false;
        }

        $meldung = [];
        if ($bewegt !== []) {
            $meldung[] = sprintf($this->Translate('moved: %s'), implode(', ', $bewegt));
        }
        if ($benannt !== []) {
            $meldung[] = sprintf($this->Translate('renamed: %s'), implode(', ', $benannt));
        }
        if ($geleert !== []) {
            $meldung[] = sprintf($this->Translate('emptied: %s'), implode(', ', $geleert));
        }
        if ($meldung !== []) {
            $this->LogMessage('Stundenplan: ' . implode(' | ', $meldung), KL_NOTIFY);
            @$this->WriteAttributeString('SlotOwnersReport', implode('  |  ', $meldung));
        }
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
        $form = [
            'elements' => [
                [
                    'type'    => 'Label',
                    'caption' => $this->Translate("A weekly template for the children's school days: subjects, times, care. It shows as a week grid or as a timeline — and, if you switch it on, as a card in the SymDo app.\n\nIt is a template, not a calendar: substitutions, cancellations and one-off changes are not part of it.")
                ],
                $this->KinderBereich($auswahl),
                $this->FaecherBereich(),
                $this->StundenBereich($auswahl, $faecher),
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
                        // Wert ist die KENNUNG, angezeigt wird der Name. Vorher stand hier
                        // der Name auch als Wert — wer ein Mitglied im Formular waehlte,
                        // speicherte damit „Mia" statt „fa0ad897", und das Gesicht in
                        // Kachel und App blieb leer, weil die Avatare nach Kennung
                        // nachgeschlagen werden.
                        ['caption' => $this->Translate('Family member'), 'name' => 'userId', 'width' => '190px',
                         'add' => '', 'edit' => ['type' => 'Select', 'options' => $this->MitgliederOptionen()]],
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

    /**
     * Je Kind ein Klapp-Bereich, darin die Wochentage NEBENEINANDER — eine
     * schmale Liste je Tag.
     *
     * Vorher stand hier EINE Liste mit Kind- und Tag-Spalte. Der Plan ist aber
     * eine Wochenmatrix; wer den Montag eines Kindes sehen wollte, musste ihn
     * zwischen den Zeilen der anderen suchen.
     *
     * Gezeigt werden nur die eingerichteten Kinder. Die Eigenschaften gibt es
     * immer alle (siehe Create), das Formular bleibt trotzdem klein.
     */
    private function StundenBereich(callable $auswahl, array $faecher): array
    {
        $kinder = $this->Kinder();
        if ($kinder === []) {
            return [
                'type'     => 'ExpansionPanel',
                'caption'  => $this->Translate('Lessons'),
                'expanded' => true,
                'items'    => [[
                    'type'    => 'Label',
                    'caption' => $this->Translate('Add a child first — the lessons are entered per child.')
                ]],
            ];
        }

        $bereiche = [];
        foreach (array_slice($kinder, 0, self::MAX_KINDER) as $i => $kind) {
            $bereiche[] = [
                'type'     => 'ExpansionPanel',
                'caption'  => (string)$kind['name'],
                'expanded' => $i === 0,
                'items'    => [$this->TageszeileFuer($i + 1, $kind, $auswahl, $faecher)],
            ];
        }
        if (count($kinder) > self::MAX_KINDER) {
            $bereiche[] = [
                'type'    => 'Label',
                'caption' => sprintf($this->Translate('Only the first %d children can have lessons.'), self::MAX_KINDER)
            ];
        }
        $bereiche[] = [
            'type'    => 'Label',
            'caption' => $this->Translate('The color comes from the subject. Lessons that touch (one ends when the next begins) are normal and are not reported as a clash. Where care is switched on, a grey block runs from the end of the lessons until the end time — only on days that have lessons, and it does not count as teaching time. Seconds in the time pickers are ignored.')
        ];

        return [
            'type'     => 'ExpansionPanel',
            'caption'  => $this->Translate('Lessons'),
            'expanded' => true,
            'items'    => $bereiche,
        ];
    }

    /** Die sechs Tageslisten eines Kindes in einer Reihe. */
    private function TageszeileFuer(int $nr, array $kind, callable $auswahl, array $faecher): array
    {
        // Samstag nur, wenn er fuer dieses Kind eingeschaltet ist — sonst stuende
        // dort eine Spalte, in die niemand etwas eintragen darf.
        $tage = TimetableCalc::Wochentage((bool)($kind['saturday']['enabled'] ?? false));
        $spalten = [];
        foreach ($tage as $tag) {
            $spalten[] = [
                // ColumnLayout, damit unter der Liste noch die Betreuung dieses
                // Tages Platz hat — Schalter und Endzeit nebeneinander.
                'type'  => 'ColumnLayout',
                'items' => [
                    [
                        'type'     => 'List',
                        'name'     => self::SlotProp($nr, $tag),
                        'caption'  => $this->Translate(TimetableCalc::TagKurz($tag)),
                        'rowCount' => 8,
                        'add'      => true,
                        'delete'   => true,
                        'sort'     => ['column' => 'start', 'direction' => 'ascending'],
                        // Breiten in PIXELN: Prozentangaben sind in Symcon 9.0 im
                        // RowLayout fehlerhaft (bestaetigter Bug, Fix erst 9.1).
                        // Von und Bis sind breiter als frueher, weil ein
                        // Zeitwaehler drei Felder zeigt statt eines Textfelds.
                        'columns'  => [
                            ['caption' => $this->Translate('Subject'), 'name' => 'subject', 'width' => '110px',
                             'add' => $faecher[0] ?? '',
                             'edit' => ['type' => 'Select', 'options' => $auswahl($faecher, $this->Translate('— pick —'))]],
                            ['caption' => $this->Translate('From'), 'name' => 'start', 'width' => '105px',
                             'add' => TimetableCalc::ZeitFeld('07:45'), 'edit' => ['type' => 'SelectTime']],
                            ['caption' => $this->Translate('To'), 'name' => 'end', 'width' => '105px',
                             'add' => TimetableCalc::ZeitFeld('08:30'), 'edit' => ['type' => 'SelectTime']],
                        ],
                    ],
                    [
                        'type'  => 'RowLayout',
                        'items' => [
                            [
                                'type'    => 'CheckBox',
                                'name'    => self::CareProp($nr, $tag),
                                'caption' => $this->Translate('After-school care'),
                            ],
                            [
                                'type'    => 'SelectTime',
                                'name'    => self::CareEndProp($nr, $tag),
                                'caption' => $this->Translate('End'),
                            ],
                        ],
                    ],
                ],
            ];
        }
        return ['type' => 'RowLayout', 'items' => $spalten];
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
                    'type'    => 'Label',
                    'caption' => $this->Translate('Whether the app shows the timetable is set in the gateway, under "Timetable" — together with the other things the app displays.')
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
            $teile[] = sprintf($this->Translate('%d lessons with an unknown subject: %s'),
                count($pruef['verwaist']), implode(' · ', array_slice($pruef['verwaist'], 0, 4)));
        }
        // Was der letzte Abgleich mit den Stundenlisten gemacht hat, gehoert
        // ebenfalls hierher — sonst verschiebt sich etwas und niemand erfaehrt es.
        $abgleich = trim((string)@$this->ReadAttributeString('SlotOwnersReport'));
        if ($abgleich !== '') {
            $teile[] = $this->Translate('Lesson lists') . ': ' . $abgleich;
        }
        if ($teile === []) {
            $kinder = count($this->Kinder());
            $stunden = count($this->Stunden());
            return sprintf($this->Translate('%d children, %d lessons, nothing to complain about.'),
                $kinder, $stunden);
        }
        return implode('  |  ', $teile);
    }

    /**
     * Auswahl der Familienmitglieder: Name sichtbar, KENNUNG gespeichert.
     * Ein bereits gespeicherter Wert, der keine bekannte Kennung ist, bleibt als
     * eigene Option erhalten — sonst faende die Auswahl ihn nicht wieder und der
     * naechste Klick auf „Uebernehmen" wuerfe ihn weg.
     */
    private function MitgliederOptionen(): array
    {
        $optionen = [['caption' => $this->Translate('— none —'), 'value' => '']];
        foreach ($this->GatewayMitglieder() as $id => $name) {
            $optionen[] = ['caption' => $name, 'value' => $id];
        }
        return $optionen;
    }

    /** Familienmitglieder aus dem Gateway als Kennung => Name. */
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
        $karte = [];
        foreach (array_filter($roh, 'is_array') as $u) {
            $id   = trim((string)($u['id'] ?? ''));
            $name = trim((string)($u['name'] ?? ''));
            if ($id !== '' && $name !== '') {
                $karte[$id] = $name;
            }
        }
        return $karte;
    }
}

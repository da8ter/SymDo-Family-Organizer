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
class SymDoTimetable extends IPSModuleStrict
{
    use TimetableStore;
    use TimetableHolidays;

    private const EIGENE_GUID = '{C22E0A96-1BC7-4029-B8C5-7E94E4F2A9D9}';
    private const GATEWAY_GUID = '{E677FE7B-28C9-4124-8B58-8A1FE2657E8D}';

    /** Wie lange die Mitgliederliste des Gateways gemerkt wird (Sekunden). */
    private const MITGLIEDER_MERKER_S = 30;

    /**
     * Merker fuer GatewayMitgliederRoh: ['gw' => int, 'zeit' => float,
     * 'liste' => list<array>]. Leer, solange nichts gemerkt ist.
     *
     * @var array<string, mixed>
     */
    private array $mitgliederMerker = [];

    /**
     * Vorschlagsliste der Konsole beim Anlegen: sie bietet ein vorhandenes
     * Gateway an oder legt auf Wunsch eines an. Damit entfaellt das
     * Auswahlfeld im Formular — die Zuordnung steht in der Konsole und laesst
     * sich dort jederzeit aendern.
     *
     * „connect" statt „require": an EINEM Gateway haengen mehrere Kacheln.
     * (ConnectParent/RequireParent gibt es fuer IPSModuleStrict nicht.)
     */
    public function GetCompatibleParents(): string
    {
        return json_encode(['type' => 'connect', 'moduleIDs' => [self::GATEWAY_GUID]]);
    }

    /**
     * Wahr, solange dieser ApplyChanges-Durchlauf von einem Kernelstart kommt.
     * Nicht dauerhaft — er lebt nur fuer diesen einen Aufruf.
     */
    private bool $applyFromKernelStart = false;

    public function Create(): void
    {
        parent::Create();
        $this->RegisterAttributeBoolean('ParentMigrated', false);

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
        // Termine der Kinder als Marker auf der Timeline (Vorgabe aus: die
        // Zeitachse waechst dadurch, und nicht jede Anlage pflegt Zuordnungen).
        $this->RegisterPropertyBoolean('ShowCalendarEvents', false);
        $this->RegisterPropertyInteger('SourceInstanceID', 0);
        $this->RegisterPropertyInteger('GatewayInstanceID', 0);

        $this->RegisterPropertyString('HolidaySource', 'openholidays');
        $this->RegisterPropertyString('HolidayRegion', 'DE-HE');
        $this->RegisterPropertyInteger('AlmanacInstanceID', 0);

        /* Datierte Tage aus einem Import (WebUntis): Ebene UEBER dem
           Wochenplan, nur fuer die Tage, die eine Quelle wirklich gesehen hat.
           Siehe TimetableStore::ImportierteTage(). */
        $this->RegisterAttributeString('ImportedDays', '{}');
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

        $this->GatewayEinmaligVerbinden();

        // Fuenf Minuten: fein genug, damit „als naechstes" und die Heute-Kapsel
        // nicht veralten, grob genug, um nichts zu kosten. Der Ferien-Abruf
        // haengt am selben Takt, laeuft aber nur einmal am Tag (siehe Refresh).
        $this->SetTimerInterval('Refresh', 5 * 60 * 1000);

        // Einmalig: die alte Sammelliste auf die Tageslisten verteilen.
        $this->StundenWandern();
        $this->BetreuungWandern();
        // NACH der Wanderung: sie legt die alten Zeichenketten erst ab.
        $this->ZeitenWandern();
        // Erst die Kinder gegen SymDo ziehen, dann die Stunden nachfuehren: der
        // Abgleich unten arbeitet auf dem Ergebnis von diesem hier.
        $this->KinderAbgleichen();
        // Und danach: sind die Kinder umsortiert worden, wandern ihre Stunden mit.
        $this->StundenAbgleichen();

        $this->PushState();
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->applyFromKernelStart = true;
            try {
                $this->ApplyChanges();
            } finally {
                $this->applyFromKernelStart = false;
            }
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
     * Die Faecher dieser Instanz, fertig aufgeloest: Name, Symbolklasse und
     * Farbe als Hex.
     *
     * Fertig aufgeloest, damit der Aufrufer nichts ueber Symcons
     * Symbol-Schreibweise und die Farbzahl wissen muss — die Hausaufgaben der
     * Web-App brauchen genau diese drei Angaben, und sie sollen sie aus EINER
     * Quelle bekommen.
     */
    public function GetSubjects(): string
    {
        $raus = [];
        foreach ($this->Faecher() as $f) {
            $raus[] = [
                'name'  => (string)$f['name'],
                'icon'  => TimetableSubjects::IconKlasse((string)$f['icon']),
                // -1 heisst keine Farbe und ergibt null; ein leerer String ist
                // fuer die Web-App einfacher, sie faellt dann auf ihre Vorgabe zurueck.
                'color' => TimetableSubjects::FarbeHex((int)$f['color']) ?? '',
            ];
        }
        return (string)json_encode($raus, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
            $this->UebernehmenNachtragen();
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
            $this->UebernehmenNachtragen();
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
                // Die alte Sammelliste kannte beides nicht; wer sie doch
                // gefuellt hat, verliert es hier nicht.
                'room'    => trim((string)($z['room'] ?? '')),
                'teacher' => trim((string)($z['teacher'] ?? '')),
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
            $this->UebernehmenNachtragen();
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
    /**
     * Die Kinderliste gegen SymDo abgleichen.
     *
     * Mit Gateway sind die Kinder die Mitglieder mit der Rolle `child`. Die
     * gespeicherte Liste wird darauf gezogen: fehlende Kinder kommen dazu,
     * Zeilen ohne Mitglied fallen weg, die Reihenfolge folgt dem Gateway. Was
     * der Stundenplan selbst verwaltet — Farbe, Samstag, gerade/ungerade Wochen
     * — bleibt dabei an der KENNUNG haengen und nicht an der Position.
     *
     * Ohne Gateway passiert nichts: dann ist die Liste die Wahrheit.
     */
    private function KinderAbgleichen(): void
    {
        static $laeuft = false;
        if ($laeuft) {
            return;
        }
        $ausGateway = $this->GatewayKinder();
        if ($ausGateway === []) {
            return;
        }
        $vorher = $this->ListeLesen('Children');
        $nachKennung = [];
        $nachName = [];
        foreach ($vorher as $z) {
            $id = trim((string)($z['userId'] ?? ''));
            $n  = trim((string)($z['name'] ?? ''));
            if ($id !== '' && !isset($nachKennung[$id])) {
                $nachKennung[$id] = $z;
            }
            // Bestand aus der Zeit vor der Umstellung: Zeile ohne Kennung, aber
            // mit dem Namen eines Mitglieds. Ihre Einstellungen wandern mit.
            if ($id === '' && $n !== '' && !isset($nachName[$n])) {
                $nachName[$n] = $z;
            }
        }
        $zeile = static function (array $z, string $id, string $name): array {
            return [
                'name'     => $name,
                'color'    => $z['color'] ?? 0x1E88E5,
                'userId'   => $id,
                'saturday' => (bool)($z['saturday'] ?? false),
                'biweekly' => (bool)($z['biweekly'] ?? false),
                'parity'   => (string)($z['parity'] ?? 'even') === 'odd' ? 'odd' : 'even',
                'hidden'   => (bool)($z['hidden'] ?? false),
            ];
        };
        $neu = [];
        foreach ($ausGateway as $id => $name) {
            /* (string) ist PFLICHT: die Kennung ist der Array-SCHLUESSEL, und PHP
               macht aus einem Schluessel, der nur aus Ziffern besteht, still ein
               int. Eine rein numerische Kennung wie „10482137" bekam die Closure
               damit als int statt als string und warf unter strict_types einen
               TypeError, der ApplyChanges des ganzen Moduls abbrach. Eine Kennung
               mit Buchstaben wie „a1b2c3d4" blieb ein string; deshalb traf es nur
               eines der beiden Kinder. */
            $kennung = (string)$id;
            $neu[] = $zeile($nachKennung[$kennung] ?? $nachName[$name] ?? [], $kennung, $name);
        }
        // Vergleich ueber DIESELBE Form, sonst meldete jede Zeile eine Aenderung,
        // nur weil sie frueher andere Schluessel trug.
        $altVergleich = [];
        foreach ($vorher as $z) {
            $altVergleich[] = $zeile($z, trim((string)($z['userId'] ?? '')), trim((string)($z['name'] ?? '')));
        }
        if (json_encode($altVergleich) === json_encode($neu)) {
            return;
        }
        $laeuft = true;
        try {
            @IPS_SetProperty($this->InstanceID, 'Children', (string)json_encode($neu, JSON_UNESCAPED_UNICODE));
            $this->UebernehmenNachtragen();
        } finally {
            $laeuft = false;
        }
    }

    private function StundenAbgleichen(): void
    {
        static $laeuft = false;
        if ($laeuft) {
            return;
        }
        $neu = [];
        $namen = [];   // Kennung => Name, nur fuer die Meldung
        foreach (array_slice(array_values($this->Kinder()), 0, self::MAX_KINDER) as $kind) {
            $neu[] = (string)$kind['id'];
            $namen[(string)$kind['id']] = (string)$kind['name'];
        }
        $roh = json_decode((string)@$this->ReadAttributeString('SlotOwners'), true);
        $alt = is_array($roh) ? array_values(array_map('strval', $roh)) : [];
        /* Wanderung des Bestands: bis hierher standen NAMEN in SlotOwners. Steht
           dort der Name eines Kindes, das heute eine Kennung hat, wird er still
           darauf gedreht — sonst meldete der Abgleich beim ersten Lauf eine
           Umbenennung „Lena → a1b2c3d4" und die Statuszeile spraeche von etwas,
           das gar nicht passiert ist. */
        $nachName = array_flip($namen);
        foreach ($alt as $i => $e) {
            if (!isset($namen[$e]) && isset($nachName[$e])) {
                $alt[$i] = (string)$nachName[$e];
            }
        }

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
        $zeig = static fn(string $k): string => $namen[$k] ?? $k;
        foreach ($neu as $j => $kennung) {
            $nr = $j + 1;
            $altIndex = array_search($kennung, $alt, true);
            if ($altIndex !== false) {
                $ziel[$nr] = $listen[$altIndex + 1];
                if ((int)$altIndex !== $j) {
                    $bewegt[] = sprintf('%s (%d→%d)', $zeig($kennung), (int)$altIndex + 1, $nr);
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
                $benannt[] = sprintf('%s → %s', $zeig($frueher), $zeig($kennung));
            } else {
                $ziel[$nr] = $leer;
                $geleert[] = $zeig($kennung);
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
                $this->UebernehmenNachtragen();
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

    /**
     * Einen Stundenplan von aussen einspielen.
     *
     * Gedacht fuer Zulieferer wie WebUntis, aber ausdruecklich auch fuer eigene
     * Skripte: STPL_ImportSlots($id, $json). Deshalb eine offene Funktion und
     * kein RequestAction — sie ist auffindbar, hat eine gepruefte Signatur und
     * ANTWORTET, statt Fehler zu schlucken.
     *
     * Rumpf:
     *   {"child": "Lena" | 1,
     *    "source": "WebUntis",
     *    "days": {"1": [{"subject":"Mathematik","start":"08:00","end":"09:00",
     *                    "room":"121","teacher":"Gue","status":"normal"}], ...}}
     *
     * „child" darf der NAME oder die Nummer sein: wer die Funktion ruft, soll
     * die interne Reihenfolge der Kinder nicht kennen muessen. Wochentage sind
     * 1 = Montag bis 6 = Samstag. „status": normal | vertretung | entfall |
     * termin (eine Veranstaltung: Ausflug, Projekttag). „insteadOf" nennt bei
     * einer Vertretung die ersetzte Lehrkraft.
     *
     * Geprueft wird ALLES vor dem ersten Schreiben. Ein halber Plan darf einen
     * guten nie ueberschreiben — lieber gar nichts und eine ehrliche Antwort.
     * Tage, die der Rumpf nicht nennt, bleiben unangetastet.
     *
     * @return string JSON: {ok, kind, tage, slots, ersetzt} oder {ok:false, error}
     */
    public function ImportSlots(string $Json): string
    {
        $fehler = function (string $code, string $text): string {
            return (string)json_encode(['ok' => false, 'error' => ['code' => $code, 'message' => $text]],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        };
        $rumpf = json_decode($Json, true);
        if (!is_array($rumpf)) {
            return $fehler('invalid_payload', $this->Translate('The payload is not valid JSON.'));
        }

        // ── Kind bestimmen: Name oder Nummer ──
        $kinder = array_values($this->Kinder());
        $wunsch = $rumpf['child'] ?? '';
        /* Alles ausser Zahl und Zeichenkette wird hier zu ''. Ein Feld „child"
           mit einer Liste darin — die Web-App schickt schon mal ein Objekt —
           landete sonst in (string)$wunsch, und das ist in PHP keine Ausnahme,
           sondern eine WARNUNG in der Ausgabe: sie stand mitten in der
           JSON-Antwort des Hooks und machte sie unlesbar. */
        if (!is_int($wunsch) && !is_string($wunsch)) {
            $wunsch = '';
        }
        $nr = 0;
        if (is_int($wunsch) || (is_string($wunsch) && ctype_digit(trim($wunsch)) && trim($wunsch) !== '')) {
            $nr = (int)$wunsch;
        } else {
            $gesucht = mb_strtolower(trim((string)$wunsch));
            foreach ($kinder as $i => $k) {
                if (mb_strtolower(trim((string)($k['name'] ?? ''))) === $gesucht && $gesucht !== '') {
                    $nr = $i + 1;
                    break;
                }
            }
        }
        if ($nr < 1 || $nr > count($kinder) || $nr > self::MAX_KINDER) {
            return $fehler('unknown_child', sprintf(
                $this->Translate('No child "%s" — known are: %s.'), (string)$wunsch,
                implode(', ', array_map(static fn(array $k): string => (string)($k['name'] ?? '?'), $kinder))));
        }

        // ── Tage und Stunden pruefen, noch nichts schreiben ──
        $tage = $rumpf['days'] ?? null;
        if (!is_array($tage) || $tage === []) {
            return $fehler('no_days', $this->Translate('No days in the payload.'));
        }
        /* Zwei Formen, eine Funktion: Wochentage (1–6) schreiben den
           WOCHENPLAN, Datumsangaben (JJJJ-MM-TT) die datierte Ebene darueber.
           Gemischt waere nur verwirrend — dann lieber eine klare Absage. */
        $datiert = null;
        foreach (array_keys($tage) as $k) {
            $istDatum = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$k) === 1;
            if ($datiert === null) {
                $datiert = $istDatum;
            } elseif ($datiert !== $istDatum) {
                return $fehler('mixed_days', $this->Translate('Either weekdays or dates — not both in one payload.'));
            }
        }

        /* Jedes Feld einer Stunde durch DIESEN Trichter. Steht dort eine Liste
           statt eines Textes — die Web-App schickt schon mal ein Objekt —, ist
           (string)$x in PHP keine Ausnahme, sondern eine WARNUNG in der
           Ausgabe; im Hook stand sie mitten in der JSON-Antwort. Leer heisst
           hier „fehlt", und das faengt die Pruefung darunter ab. */
        $text = static fn(mixed $v): string => is_scalar($v) ? trim((string)$v) : '';

        $fertig = [];
        $anzahl = 0;
        foreach ($tage as $tag => $zeilen) {
            $t = (int)$tag;
            if ($datiert) {
                [$j, $m, $d] = array_map('intval', explode('-', (string)$tag));
                if (!checkdate($m, $d, $j)) {
                    return $fehler('bad_date', sprintf($this->Translate('"%s" is not a date.'), (string)$tag));
                }
            } elseif ($t < 1 || $t > 6) {
                return $fehler('bad_weekday', sprintf($this->Translate('Weekday %s is not between 1 and 6.'), (string)$tag));
            }
            if (!is_array($zeilen)) {
                return $fehler('bad_day', sprintf($this->Translate('Day %s is not a list.'), (string)$tag));
            }
            $slots = [];
            foreach ($zeilen as $z) {
                if (!is_array($z)) {
                    return $fehler('bad_slot', $this->Translate('A lesson is not an object.'));
                }
                $fach = $text($z['subject'] ?? '');
                if ($fach === '') {
                    return $fehler('empty_subject', $this->Translate('A lesson has no subject.'));
                }
                $von = TimetableCalc::Minuten($text($z['start'] ?? ''));
                $bis = TimetableCalc::Minuten($text($z['end'] ?? ''));
                if ($von < 0 || $bis < 0) {
                    return $fehler('bad_time', sprintf(
                        $this->Translate('Lesson "%s" has no valid time (expected HH:MM).'), $fach));
                }
                if ($bis <= $von) {
                    return $fehler('end_before_start', sprintf(
                        $this->Translate('Lesson "%s" ends before it starts.'), $fach));
                }
                $status = mb_strtolower($text($z['status'] ?? 'normal'));
                /* „termin" ist die vierte Art: eine Veranstaltung im Plan —
                   Ausflug, Projekttag, Waldschule. WebUntis kennt sie als
                   eigenen Eintragstyp (EVENT); die alte Schnittstelle konnte
                   sie nicht von einer Vertretung unterscheiden. */
                if (!in_array($status, ['normal', 'vertretung', 'entfall', 'termin'], true)) {
                    $status = 'normal';
                }
                $slots[] = [
                    'subject' => $fach,
                    'start'   => TimetableCalc::ZeitFeld($text($z['start'])),
                    'end'     => TimetableCalc::ZeitFeld($text($z['end'])),
                    'room'    => $text($z['room'] ?? ''),
                    'teacher' => $text($z['teacher'] ?? ''),
                    'status'  => $status,
                    /* Wer ersetzt wurde. WebUntis nennt bei einer Vertretung
                       den weggefallenen Lehrer mit; ohne dieses Feld stuende
                       in der Kachel nur „Vertretung" statt „statt Kais". */
                    'insteadOf' => $text($z['insteadOf'] ?? ''),
                ];
                $anzahl++;
            }
            usort($slots, static fn(array $a, array $b): int
                => TimetableCalc::Minuten(TimetableCalc::ZeitText($a['start']))
                <=> TimetableCalc::Minuten(TimetableCalc::ZeitText($b['start'])));
            $fertig[$datiert ? (string)$tag : $t] = $slots;
        }

        // ── Erst jetzt schreiben ──
        $verworfen = [];
        if ($datiert) {
            $heute = date('Y-m-d');
            foreach (array_keys($fertig) as $datum) {
                if ((string)$datum < $heute) {
                    // Vergangenes wird nicht abgelegt — der Plan zeigt nach vorn.
                    $verworfen[] = (string)$datum;
                    unset($fertig[$datum]);
                }
            }
            [$geschrieben, $angekommen] = $this->ImportierteTageSetzen(
                (string)($kinder[$nr - 1]['name'] ?? ''), $fertig);
            if (!$angekommen) {
                return $fehler('needs_restart', $this->Translate(
                    'The dated days need a kernel restart first — the attribute does not exist yet.'));
            }
            // Attribute loesen kein ApplyChanges aus; die Kachel muss also
            // ausdruecklich neu bespielt werden.
            $this->PushState();
        } else {
            $erwartet = [];
            foreach ($fertig as $t => $slots) {
                $feld = self::SlotProp($nr, (int)$t);
                $text = (string)json_encode($slots, JSON_UNESCAPED_UNICODE);
                @IPS_SetProperty($this->InstanceID, $feld, $text);
                $erwartet[$feld] = $text;
            }
            @IPS_ApplyChanges($this->InstanceID);
            /* GEGENLESEN. Der Rueckgabewert von IPS_SetProperty taugt nicht als
               Beweis: er ist auch dann false, wenn sich der Wert schlicht nicht
               geaendert hat (gemessen an #22469). Gibt es die Eigenschaft nicht
               — etwa weil das Modul nach einer Erweiterung noch nicht neu
               geladen wurde —, schrieb die Schleife ins Nichts, und der Import
               meldete trotzdem „ok". Der Nutzer suchte den Fehler dann in
               WebUntis. */
            $fehlend = [];
            foreach ($erwartet as $feld => $text) {
                try {
                    $ist = (string)IPS_GetProperty($this->InstanceID, $feld);
                } catch (\Throwable $e) {
                    $ist = null;
                }
                if ($ist !== $text) {
                    $fehlend[] = $feld;
                }
            }
            if ($fehlend !== []) {
                return $fehler('not_written', sprintf($this->Translate(
                    '%1$d weekday(s) could not be written (%2$s) — reload the module (MC_ReloadModule).'),
                    count($fehlend), implode(', ', array_slice($fehlend, 0, 3))));
            }
        }

        $quelle = trim((string)($rumpf['source'] ?? ''));
        $this->SendDebug('ImportSlots', sprintf('%s: Kind %d, %d %s, %d Stunde(n)',
            $quelle !== '' ? $quelle : 'unbekannte Quelle', $nr, count($fertig),
            $datiert ? 'Datum/Daten' : 'Wochentag(e)', $anzahl), 0);

        $antwort = [
            'ok'      => true,
            'kind'    => (string)($kinder[$nr - 1]['name'] ?? ''),
            'tage'    => $datiert ? array_map('strval', array_keys($fertig))
                                  : array_map('intval', array_keys($fertig)),
            'slots'   => $anzahl,
            'ersetzt' => true,
            'datiert' => (bool)$datiert,
        ];
        if ($verworfen !== []) {
            // Nicht still schlucken: der Aufrufer soll sehen, dass sein Fenster
            // in der Vergangenheit anfing.
            $antwort['verworfen'] = $verworfen;
        }
        return (string)json_encode($antwort, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
        /* Anfangszustand inline: die Kachel zeigt sofort etwas, ohne auf den
           ersten Push zu warten.
           Das „</" wird dabei maskiert, wie es json_encode OHNE
           JSON_UNESCAPED_SLASHES taete: die Nutzlast steht in einem
           <script>-Block, und ein „</script>" in einem Fach- oder Kindnamen
           beendete ihn woertlich — handleMessage liefe nie, der Rest landete als
           HTML in der Visu. In JSON ist „<\/" ausserhalb von Zeichenketten
           unmoeglich und innerhalb die gueltige Schreibweise fuer „</", die
           Ersetzung ist also verlustfrei. GetPlan() selbst bleibt unberuehrt —
           es ist die oeffentliche Auskunft fuer Skripte. */
        $zustand = str_replace('</', '<\\/', $this->GetTilePlan());
        return $html . '<script>handleMessage(' . $zustand . ');</script>';
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
                $this->UpdateFormField('SubjectStatus', 'caption', $this->FaecherNachtragen((string)$Value));
                return;
            case 'SubjectsSync':
                $this->StundenAuswahlAuffrischen($this->FaecherNamen((string)$Value));
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
        /* Fuer die Kachel ohne UNESCAPED_UNICODE — siehe unten. GetPlan()
           selbst bleibt unveraendert, es ist die oeffentliche Auskunft fuer
           Skripte und soll dort lesbar bleiben. */
        $plan = json_decode($this->GetTilePlan(), true);
        $this->UpdateVisualizationValue(is_array($plan)
            ? (string)json_encode($plan, JSON_UNESCAPED_SLASHES)
            : $this->GetTilePlan());
    }

    /**
     * Der Plan FUER DIE KACHEL: derselbe Plan, dazu die Zahl offener
     * Hausaufgaben je Stunde. Oeffentlich, damit sie pruefbar ist und ein
     * Skript sehen kann, was die Kachel sieht.
     *
     * Bewusst NICHT in GetPlan(): das ist die Funktion, die das Gateway in der
     * Stundenplan-Bruecke aufruft. Hinge das Anhaengen dort, riefe das Gateway
     * das Stundenplan-Modul, und dieses mitten im laufenden Hook das Gateway
     * zurueck (TGW_GetHomework) — ein Rueckruf in die Instanz, die gerade
     * arbeitet. Die Web-App braucht es auch nicht: sie rechnet die Zuordnung
     * selbst, damit ein Abhaken nicht die Signatur des ganzen Plans aendert.
     */
    public function GetTilePlan(): string
    {
        $plan = json_decode($this->GetPlan(), true);
        if (!is_array($plan)) {
            return $this->GetPlan();
        }
        return (string)json_encode($this->HausaufgabenAnhaengen($plan),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Je Stundenkarte die Zahl offener Hausaufgaben, und je Tag, was sich
     * keiner Stunde zuordnen liess.
     *
     * Eine Hausaufgabe, die NIRGENDS erscheint, waere schlimmer als eine an
     * ungenauer Stelle: faellt das Fach aus oder hat es an dem Tag keine
     * Stunde, sammelt der Tag sie in „hwFrei".
     */
    private function HausaufgabenAnhaengen(array $plan): array
    {
        $gw = $this->GatewayInstanz();
        if ($gw <= 0 || !function_exists('TGW_GetHomework')) {
            return $plan;
        }
        try {
            $roh = json_decode((string)@TGW_GetHomework($gw, ''), true);
        } catch (\Throwable $e) {
            return $plan;
        }
        $items = is_array($roh['items'] ?? null) ? $roh['items'] : [];
        if ($items === []) {
            return $plan;
        }
        foreach ((array)($plan['children'] ?? []) as $ki => $kind) {
            $userId = trim((string)($kind['userId'] ?? ''));
            if ($userId === '') {
                continue;
            }
            foreach ((array)($kind['days'] ?? []) as $ti => $tag) {
                $datum = trim((string)($tag['date'] ?? ''));
                if ($datum === '') {
                    continue;
                }
                $offen = [];
                foreach ($items as $i) {
                    if (($i['done'] ?? false) === true) {
                        continue;
                    }
                    if ((string)($i['childId'] ?? '') !== $userId
                        || (string)($i['due'] ?? '') !== $datum) {
                        continue;
                    }
                    $offen[] = $i;
                }
                if ($offen === []) {
                    continue;
                }
                $erste = [];
                $frei = 0;
                foreach ($offen as $i) {
                    $treffer = null;
                    foreach ((array)($tag['slots'] ?? []) as $si => $slot) {
                        if (TimetableSubjects::FachGleich((string)($slot['name'] ?? ''), (string)$i['subject'])) {
                            // Die ERSTE Stunde des Fachs traegt die Zahl: zwei
                            // Stunden desselben Fachs sollen sie nicht verdoppeln.
                            $treffer = $erste[(string)$slot['name']] ?? $si;
                            $erste[(string)$slot['name']] = $treffer;
                            break;
                        }
                    }
                    if ($treffer === null) {
                        $frei++;
                        continue;
                    }
                    $karte = $plan['children'][$ki]['days'][$ti]['slots'][$treffer];
                    $karte['hw'] = (int)($karte['hw'] ?? 0) + 1;
                    $vorher = trim((string)($karte['hwText'] ?? ''));
                    $text = trim((string)$i['note']) !== '' ? trim((string)$i['note']) : (string)$i['subject'];
                    $karte['hwText'] = $vorher === '' ? $text : ($vorher . ' · ' . $text);
                    $plan['children'][$ki]['days'][$ti]['slots'][$treffer] = $karte;
                }
                if ($frei > 0) {
                    $plan['children'][$ki]['days'][$ti]['hwFrei'] = $frei;
                }
            }
        }
        return $plan;
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
                return $this->FolgewocheAnhaengen($plan);
            }
            // Nicht lesbar: lieber der eigene (meist leere) Plan als eine
            // Kachel, die gar nichts zeichnet.
        }
        return $this->FolgewocheAnhaengen($this->PlanAufbauen());
    }

    /**
     * Die kommende Woche an den Plan haengen.
     *
     * NUR mit Import: ohne WebUntis ist der Plan eine Wochenvorlage, die sich
     * jede Woche wiederholt — ein Wechsler zeigte dann zweimal dasselbe Bild.
     * Mit Import lohnt es sich: WebUntis liefert vierzehn Tage im Voraus,
     * Entfaelle und Vertretungen der naechsten Woche stehen also laengst da.
     *
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    private function FolgewocheAnhaengen(array $plan): array
    {
        if (($plan['dated'] ?? false) !== true || !is_array($plan['children'] ?? null)) {
            return $plan;
        }
        $datum  = date('Y-m-d', strtotime('+7 days'));
        $quelle = $this->ReadPropertyInteger('SourceInstanceID');
        /* Beim Spiegel kommt auch die zweite Woche von der QUELLE — sonst
           zeigte er eine Woche aus seinem eigenen (leeren) Bestand. Die
           oeffentliche Funktion dafuer gibt es schon; das Briefing benutzt sie. */
        if ($quelle > 0 && $quelle !== $this->InstanceID && IPS_InstanceExists($quelle)
            && function_exists('STPL_GetPlanForDate')) {
            $naechste = json_decode((string)@STPL_GetPlanForDate($quelle, $datum), true);
        } else {
            // Ohne Termine: das Raster zeigt keine Marker, und ein zweiter
            // Kalenderabruf je Kachelaufbau waere umsonst bezahlt.
            $naechste = $this->PlanAufbauen($datum, false);
        }
        if (!is_array($naechste) || !is_array($naechste['children'] ?? null)) {
            return $plan;
        }
        // Zuordnung ueber den NAMEN, nicht ueber die Reihenfolge: ein
        // ausgeblendetes Kind faellt in beiden Plaenen weg, aber darauf zu
        // bauen hiesse, sich auf eine Nebenwirkung zu verlassen.
        $jeName = [];
        foreach ($naechste['children'] as $kind) {
            $jeName[(string)($kind['name'] ?? '')] = (array)($kind['days'] ?? []);
        }
        foreach ($plan['children'] as $i => $kind) {
            $tage = $jeName[(string)($kind['name'] ?? '')] ?? null;
            if (!is_array($tage)) {
                continue;
            }
            /* Heute-Marke und „naechste Stunde" gehoeren zur laufenden Woche.
               PlanAufbauen setzt beides relativ zu SEINEM Datum — unbesehen
               uebernommen staende in der Folgewoche ein zweites „Heute". */
            foreach ($tage as $j => $tag) {
                $tage[$j]['today'] = false;
                foreach ((array)($tag['slots'] ?? []) as $n => $slot) {
                    $tage[$j]['slots'][$n]['next'] = false;
                }
            }
            $plan['children'][$i]['nextDays'] = array_values($tage);
        }
        $plan['nextDate'] = $datum;
        return $plan;
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
                // Anzeige zuerst: welche Ansicht die Kachel zeigt und woher die
                // Daten kommen, entscheidet man einmal beim Einrichten - und
                // danach steht es dort, wo man es sucht, statt unter vier
                // Bereichen, die man taeglich pflegt.
                $this->AnzeigeBereich(),
                $this->KinderBereich($auswahl),
                $this->FaecherBereich(),
                $this->StundenBereich($auswahl, $faecher),
                $this->FerienBereich(),
                [
                    'type'    => 'Label',
                    'name'    => 'PlanStatus',
                    'caption' => $this->StatusText($pruef)
                ],
            ],
            'actions' => $this->SpendenFormular(),
            'status'  => [],
        ];
        return (string)json_encode($form, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Der Spenden-Block, wie ihn die anderen Module zeigen: Überschrift, Satz
     * und das PayPal-Bild. Er steht EINMAL in ToDoOverview/form.json und wird
     * von dort ab „DonationHeader" (samt der Leerzeile davor) übernommen —
     * dasselbe Vorgehen wie in ToDoList, Essensplan und Gateway.
     *
     * Fehlt die Vorlage (einzeln installiertes Modul), bleibt der schlichte
     * Knopf: lieber schlichter als gar kein Hinweis.
     */
    private function SpendenFormular(): array
    {
        $pfad = __DIR__ . '/../ToDoOverview/form.json';
        $vorlage = is_readable($pfad)
            ? json_decode((string)@file_get_contents($pfad), true)
            : null;
        $elemente = is_array($vorlage) && is_array($vorlage['elements'] ?? null) ? $vorlage['elements'] : [];
        foreach ($elemente as $i => $element) {
            if (is_array($element) && ($element['name'] ?? '') === 'DonationHeader') {
                return array_slice($elemente, max(0, $i - 1));
            }
        }
        return [
            ['type' => 'Label', 'caption' => ''],
            ['type' => 'Label', 'name' => 'DonationHeader', 'caption' => 'Donation / Gift'],
            ['type' => 'Label', 'caption' => 'Say thanks and support the developer of this module:'],
            ['type' => 'Button', 'caption' => 'PayPal', 'onClick' => 'echo \'https://paypal.me/sspkbw25\';'],
        ];
    }

    private function KinderBereich(callable $auswahl): array
    {
        /* Mit Gateway sind die Kinder GENAU die Familienmitglieder mit der Rolle
           `child` — angelegt und benannt wird in SymDo, hier stehen nur noch die
           Einstellungen, die der Stundenplan selbst braucht. Deshalb dann keine
           Namensspalte (der Name folgt jeder Umbenennung von selbst) und kein
           Hinzufuegen oder Loeschen: zwei Kinderlisten, die auseinanderlaufen
           koennen, waren genau das Problem.

           OHNE Gateway bleibt die Liste, wie sie war. Die Kachel soll allein
           lauffaehig bleiben. */
        $ausGateway = $this->GatewayKinder();
        $spalten = [];
        if ($ausGateway === []) {
            $spalten[] = ['caption' => $this->Translate('Name'), 'name' => 'name', 'width' => 'auto',
                          'add' => '', 'edit' => ['type' => 'ValidationTextBox']];
        } else {
            /* MIT Gateway steht der Name hier nur noch da — er kommt aus SymDo und
               wird bei jedem Abgleich in die Zeile geschrieben (KinderAbgleichen).
               Ohne diese Spalte war die Liste eine Reihe gleich aussehender Zeilen,
               in denen erst das Aufklappen der Auswahl verriet, wer gemeint ist.
               KEIN `edit` — hier wird nichts getippt.
               ABER `save` => true: eine Spalte ohne `edit` schreibt Symcon beim
               Speichern NICHT zurueck, und der Name fiele bei jedem Uebernehmen
               lautlos aus der Zeile. */
            $spalten[] = ['caption' => $this->Translate('Name'), 'name' => 'name', 'width' => '150px',
                          'add' => '', 'save' => true];
        }
        // Wer, dann wie: das Kind steht vor seiner Farbe. Wert ist die KENNUNG,
        // angezeigt wird der Name. Vorher stand hier der Name auch als Wert —
        // wer ein Mitglied im Formular waehlte, speicherte damit „Lena" statt
        // „a1b2c3d4", und das Gesicht in Kachel und App blieb leer, weil die
        // Avatare nach Kennung nachgeschlagen werden.
        $spalten[] = ['caption' => $this->Translate($ausGateway === [] ? 'Family member' : 'Child'),
                      'name' => 'userId', 'width' => $ausGateway === [] ? '190px' : 'auto',
                      'add' => '', 'edit' => ['type' => 'Select', 'options' => $this->MitgliederOptionen()]];
        $spalten[] = ['caption' => $this->Translate('Color'), 'name' => 'color', 'width' => '110px',
                      'add' => 0x1E88E5, 'edit' => ['type' => 'SelectColor']];
        $hinweis = $ausGateway === []
            ? $this->Translate('The family member links this child to SymDo — the card in the app then appears under the right name. Saturday only shows in the plan when it is switched on here; "every other week" follows the parity of the ISO calendar week, exactly like a school notice ("lessons in odd weeks").')
            : $this->Translate('The children come from SymDo: every family member with the role "child" gets a row here, in that order, with their name and photo. Add, rename or remove them in the SymDo Gateway — this list follows. Saturday only shows in the plan when it is switched on here; "every other week" follows the parity of the ISO calendar week, exactly like a school notice ("lessons in odd weeks").');

        return [
            'type'     => 'ExpansionPanel',
            'caption'  => $this->Translate('Children'),
            'expanded' => false,
            'items'    => [
                [
                    'type'     => 'List',
                    'name'     => 'Children',
                    'rowCount' => 4,
                    'add'      => $ausGateway === [],
                    'delete'   => $ausGateway === [],
                    'columns'  => [
                        ...$spalten,
                        ['caption' => $this->Translate('Saturday'), 'name' => 'saturday', 'width' => '110px',
                         'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => $this->Translate('Every other week'), 'name' => 'biweekly', 'width' => '150px',
                         'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => $this->Translate('Calendar weeks'), 'name' => 'parity', 'width' => '150px',
                         'add' => 'even', 'edit' => ['type' => 'Select', 'options' => [
                             ['caption' => $this->Translate('Even weeks'), 'value' => 'even'],
                             ['caption' => $this->Translate('Odd weeks'), 'value' => 'odd'],
                         ]]],
                        /* Ausblenden statt loeschen. Mit Gateway ist die Liste die
                           der Familie — ein Kleinkind hat dort eine Zeile, aber
                           keinen Stundenplan. Die Zeile bleibt (und mit ihr die
                           Position, an der die Stundenlisten haengen), sie wird
                           nur nicht mehr gezeigt. */
                        ['caption' => $this->Translate('Hide'), 'name' => 'hidden', 'width' => '110px',
                         'add' => false, 'edit' => ['type' => 'CheckBox']],
                    ],
                ],
                [
                    'type'    => 'Label',
                    'caption' => $hinweis,
                ],
                [
                    'type'    => 'Label',
                    'caption' => $this->Translate('Hidden children disappear from the card, from the app and from the briefing — their lessons stay stored and come back when you switch it off.'),
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
                    /* Jede Aenderung an den Faechern zieht die Auswahl in ALLEN
                       Tageslisten nach. Ohne das ist ein gerade angelegtes Fach
                       erst nach Uebernehmen UND Neuoeffnen des Formulars einer
                       Stunde zuzuordnen. */
                    'onAdd'         => 'IPS_RequestAction($id, "SubjectsSync", json_encode(iterator_to_array($Subjects)));',
                    'onEdit'        => 'IPS_RequestAction($id, "SubjectsSync", json_encode(iterator_to_array($Subjects)));',
                    'onDelete'      => 'IPS_RequestAction($id, "SubjectsSync", json_encode(iterator_to_array($Subjects)));',
                    'onChangeOrder' => 'IPS_RequestAction($id, "SubjectsSync", json_encode(iterator_to_array($Subjects)));',
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
                    'onClick' => 'IPS_RequestAction($id, "FillSubjects", json_encode(iterator_to_array($Subjects)));'
                ],
                [
                    'type'    => 'Label',
                    'name'    => 'SubjectStatus',
                    'caption' => ' '
                ],
                [
                    'type'    => 'Label',
                    'caption' => $this->Translate('Icon and color belong to the subject and apply everywhere it appears — a single lesson cannot differ. The button adds subjects that occur in the lessons but are missing here — with a suggested icon and color that you can change afterwards.')
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
        /* Die Datenquelle steht hier und nicht mehr unter „Anzeige": sie
           entscheidet, ob die Stunden darunter ueberhaupt gelten. Steht dort eine
           andere Instanz, kommt der ganze Plan von dort und alles Weitere in
           diesem Bereich ist ohne Wirkung — das gehoert vor die Listen, nicht in
           einen anderen Bereich. */
        $quelle = [
            'type'         => 'SelectInstance',
            'name'         => 'SourceInstanceID',
            'width'        => '400px',
            'caption'      => $this->Translate('Use data from another timetable instance'),
            'validModules' => [self::EIGENE_GUID],
        ];

        $kinder = $this->Kinder();
        if ($kinder === []) {
            return [
                'type'     => 'ExpansionPanel',
                'caption'  => $this->Translate('Lessons'),
                'expanded' => false,
                'items'    => [$quelle, [
                    'type'    => 'Label',
                    'caption' => $this->Translate('Add a child first — the lessons are entered per child.')
                ]],
            ];
        }

        $bereiche = [$quelle];
        foreach (array_slice($kinder, 0, self::MAX_KINDER) as $i => $kind) {
            // Ausgeblendet: kein Klapp-Bereich. Der Zaehler $i laeuft trotzdem
            // weiter — an ihm haengen die Eigenschaften.
            if (($kind['hidden'] ?? false) === true) {
                continue;
            }
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
            'expanded' => false,
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
                        'columns'  => $this->StundenSpalten($faecher),
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

    /**
     * Die Spalten EINER Tagesliste.
     *
     * Eigene Methode, weil sie im laufenden Formular ersetzt werden: die
     * Fächer-Auswahl steht IN der Spaltendefinition, und wer ein Fach anlegt,
     * soll es sofort einer Stunde geben können — ohne Übernehmen und
     * Neuöffnen.
     *
     * @param list<string> $faecher
     * @return list<array<string,mixed>>
     */
    private function StundenSpalten(array $faecher): array
    {
        $auswahl = [['caption' => $this->Translate('— pick —'), 'value' => '']];
        foreach ($faecher as $n) {
            $auswahl[] = ['caption' => $n, 'value' => $n];
        }
        return [
            ['caption' => $this->Translate('Subject'), 'name' => 'subject', 'width' => '110px',
             'add' => $faecher[0] ?? '',
             'edit' => ['type' => 'Select', 'options' => $auswahl]],
            ['caption' => $this->Translate('From'), 'name' => 'start', 'width' => '105px',
             'add' => TimetableCalc::ZeitFeld('07:45'), 'edit' => ['type' => 'SelectTime']],
            ['caption' => $this->Translate('To'), 'name' => 'end', 'width' => '105px',
             'add' => TimetableCalc::ZeitFeld('08:30'), 'edit' => ['type' => 'SelectTime']],
            /* Raum und Lehrer als Freitext: Schulen kuerzen beides auf eigene
               Weise („121", „Halle L", „Fa", „Frau Fabian"), und eine
               Auswahlliste muesste erst gepflegt werden, bevor man die erste
               Stunde eintragen kann. */
            ['caption' => $this->Translate('Room'), 'name' => 'room', 'width' => '90px',
             'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
            ['caption' => $this->Translate('Teacher'), 'name' => 'teacher', 'width' => '90px',
             'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
        ];
    }

    /**
     * Die Fächer-Auswahl JEDER Tagesliste auffrischen.
     *
     * Eine Liste je Kind und Wochentag — bis zu sechs Tage mal sechs Kinder.
     * Die Konsole nimmt ein Feld, das sie nicht kennt, stillschweigend hin,
     * deshalb schadet eine Liste zu viel nicht; eine zu wenig fiele dagegen
     * erst auf, wenn jemand dort ein Fach sucht.
     *
     * @param list<string> $faecher
     */
    private function StundenAuswahlAuffrischen(array $faecher): void
    {
        $spalten = (string)json_encode($this->StundenSpalten($faecher), JSON_UNESCAPED_UNICODE);
        foreach (array_slice($this->Kinder(), 0, self::MAX_KINDER) as $i => $kind) {
            foreach (TimetableCalc::Wochentage((bool)($kind['saturday']['enabled'] ?? false)) as $tag) {
                $this->UpdateFormField(self::SlotProp($i + 1, $tag), 'columns', $spalten);
            }
        }
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
                        ['caption' => $this->Translate("Almanac module (Jahreskalender)"), 'value' => 'almanac'],
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
                    'caption' => $this->Translate("Module \"Jahreskalender (Almanac)\" by Wilkware (@Pitti), available in the Symcon Module Store: https://github.com/Wilkware/Almanac — it must be installed and set up for school holidays.")
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
                    'type'    => 'Label',
                    'name'    => 'GatewayHint',
                    'caption' => $this->GatewayHinweis(),
                    'visible' => $this->GatewayHinweis() !== '',
                ],
                // Neue Eigenschaft: vor dem Kernel-Neustart existiert sie nicht,
                // und ein Feld, dessen Wert nicht gespeichert werden kann, ist
                // schlimmer als ein Hinweis (Muster aus der SymDo Web App).
                ...$this->TerminSchalter(),
                [
                    'type'    => 'Label',
                    'caption' => $this->Translate('Whether the app shows the timetable is set in the SymDo Web App, under "Visible sections" — together with the other things the app displays.')
                ],
                [
                    'type'    => 'Label',
                    'caption' => $this->Translate('For both views at once: create a second instance of this module, set it to "Timeline" and pick this instance as its data source. It then shows the same plan in the other shape.')
                ],
            ],
        ];
    }

    // ───────────────────────────── Formular-Hilfen ─────────────────────────────

    /**
     * Sagt, woher die Familienmitglieder wirklich kommen — leer, solange die
     * Auswahl stimmt. Ohne diesen Satz sieht ein verwaister Eintrag genau so aus
     * wie ein leerer: die Spalte „Familienmitglied" zeigt nur „— keins —", und
     * warum, steht nirgends.
     */
    /**
     * Der Termin-Schalter — oder der Neustart-Hinweis, solange es die
     * Eigenschaft noch nicht gibt (Eigenschaften entstehen nur in Create,
     * und Create laeuft erst nach einem Kernel-Neustart erneut).
     *
     * @return list<array<string, mixed>>
     */
    private function TerminSchalter(): array
    {
        $cfg = json_decode((string)@IPS_GetConfiguration($this->InstanceID), true);
        if (!is_array($cfg) || !array_key_exists('ShowCalendarEvents', $cfg)) {
            return [[
                'type'    => 'Label',
                'caption' => $this->Translate('New in this version: appointment markers on the timeline. Restart Symcon once, then the switch appears here.'),
            ]];
        }
        return [
            [
                'type'    => 'CheckBox',
                'name'    => 'ShowCalendarEvents',
                'caption' => $this->Translate("Show the children's appointments on the timeline"),
            ],
            [
                'type'    => 'Label',
                'caption' => $this->Translate('Markers follow the member assignment from SymDo: an appointment appears on the timeline of every child assigned to it. All-day appointments and appointments without an assignment stay away. On a mirror instance the switch of the SOURCE decides — the plan arrives from there, markers included.'),
            ],
        ];
    }

    private function GatewayHinweis(): string
    {
        $genutzt = $this->GatewayInstanz();
        $app     = $this->GatewayAppSeite();

        if ($genutzt <= 0) {
            return $this->Translate('No SymDo gateway found. Without one the "Family member" column stays empty and the card shows initials instead of photos.');
        }
        /* Die gefaehrliche Lage zuerst: die Instanz haengt an einem Gateway, das
           die App gar nicht bedient. Dessen Mitgliederliste ist eine andere —
           meist eine leere. Seit der Elternanschluss das Auswahlfeld ersetzt
           hat, kommt diese Lage aus der Verbindung in der Konsole. */
        if ($app > 0 && $genutzt !== $app) {
            return sprintf(
                $this->Translate('Careful: this instance uses "%s" (#%d), but the app is served by "%s" (#%d). Every gateway keeps its own list of family members — the children here then come from an instance that the app does not use. Connect the instance to the other gateway in the console unless you know you want this.'),
                IPS_GetName($genutzt), $genutzt, IPS_GetName($app), $app
            );
        }
        return sprintf(
            $this->Translate('"%s" (#%d) is used: the gateway that serves the app. Which gateway an instance belongs to is set as its parent in the console — that only matters in setups with several gateways.'),
            IPS_GetName($genutzt),
            $genutzt
        );
    }

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
        /* Ohne Quelle sagt die Zeile das — und NICHT „0 Abschnitte". Der
           abgerufene Stand liegt weiter da, er wirkt nur nicht; „0" haette
           behauptet, er sei weg. */
        if ((string)$this->ReadPropertyString('HolidaySource') === 'none') {
            return $this->Translate('No holiday source selected — the plan always applies. A fetched set stays stored and takes effect again as soon as a source is chosen.');
        }
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
     * Die Zeilen der Fächerliste aus dem Formular, auf die eigenen Spalten
     * zurechtgestutzt.
     *
     * Die Konsole schickt je Zeile mehr, als die Liste an Spalten hat
     * (`index_`, `editable`, `rowColor`). Was nicht namentlich hier steht, geht
     * nicht zurück ins Formular.
     *
     * @return list<array{name:string,icon:string,color:int}>
     */
    private function FaecherZeilen(string $nutzlast): array
    {
        $roh = json_decode($nutzlast, true);
        if (!is_array($roh)) {
            // Ohne Nutzlast (Aufruf aus einem Skript) gilt der gespeicherte Stand.
            $roh = json_decode((string)@IPS_GetProperty($this->InstanceID, 'Subjects'), true);
        }
        $raus = [];
        foreach ((array)$roh as $z) {
            if (!is_array($z)) {
                continue;
            }
            $raus[] = [
                'name'  => trim((string)($z['name'] ?? '')),
                'icon'  => (string)($z['icon'] ?? 'book'),
                'color' => (int)($z['color'] ?? 0x1E88E5),
            ];
        }
        return $raus;
    }

    /** @return list<string> die Fächernamen, leere Zeilen fallen weg. */
    private function FaecherNamen(string $nutzlast): array
    {
        return array_values(array_filter(
            array_column($this->FaecherZeilen($nutzlast), 'name'),
            static fn(string $n): bool => $n !== ''
        ));
    }

    /**
     * Trägt Fächer nach, die in den Stunden vorkommen, aber nicht in der
     * Fächer-Liste stehen.
     *
     * Gearbeitet wird auf der LEBENDEN Liste des Formulars und nichts wird
     * gespeichert. Vorher schrieb diese Stelle die Eigenschaft und rief
     * IPS_ApplyChanges — das warf dem Nutzer jede offene Änderung im ganzen
     * Formular weg, auch in den Tageslisten, und die neuen Fächer standen
     * trotzdem erst nach einem Neuöffnen in der Auswahl der Stunden.
     *
     * Die STUNDEN kommen weiter aus dem gespeicherten Stand: sie werden aus
     * WebUntis übernommen, nicht in derselben Sitzung getippt, und sie alle
     * (bis zu sechs Kinder mal sechs Tage) durch den Knopf zu reichen wäre ein
     * Skript von einigen Kilobyte je Klick.
     */
    private function FaecherNachtragen(string $nutzlast = ''): string
    {
        $liste     = $this->FaecherZeilen($nutzlast);
        $vorhanden = array_column($liste, 'name');
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
        foreach ($neu as $name => $v) {
            $liste[] = ['name' => $name, 'icon' => $v['icon'], 'color' => $v['color']];
        }
        $this->UpdateFormField('Subjects', 'values', (string)json_encode($liste, JSON_UNESCAPED_UNICODE));
        $this->StundenAuswahlAuffrischen(array_values(array_filter(
            array_column($liste, 'name'), static fn(string $n): bool => $n !== '')));
        return sprintf($this->Translate('%d subjects added: %s — press Apply to keep them.'),
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
        // Mit Gateway stehen nur die KINDER zur Wahl: die Zeilen werden ohnehin
        // gegen sie abgeglichen, ein gewaehlter Vater verschwaende nur einen Klick.
        $kinder = $this->GatewayKinder();
        $optionen = [['caption' => $this->Translate('— none —'), 'value' => '']];
        foreach ($kinder !== [] ? $kinder : $this->GatewayMitglieder() as $id => $name) {
            $optionen[] = ['caption' => $name, 'value' => $id];
        }
        return $optionen;
    }

    /**
     * Die KINDER der Familie: Mitglieder mit der Rolle `child`, in der
     * Reihenfolge des Gateways.
     *
     * Sie sind die Kinder des Stundenplans, sobald ein Gateway da ist — getippt
     * wird dann nichts mehr. Die Rolle steht in der Mitgliederliste des
     * Gateways; ohne sie muesste hier jemand von Hand sagen, wer ein Kind ist,
     * und genau diese zweite Liste soll weg.
     *
     * @return array<string,string> Kennung => Name
     */
    private function GatewayKinder(): array
    {
        $kinder = [];
        foreach ($this->GatewayMitgliederRoh() as $u) {
            if (strtolower(trim((string)($u['persona'] ?? ''))) !== 'child') {
                continue;
            }
            $id   = trim((string)($u['id'] ?? ''));
            $name = trim((string)($u['name'] ?? ''));
            if ($id !== '' && $name !== '') {
                $kinder[$id] = $name;
            }
        }
        return $kinder;
    }

    /** Familienmitglieder aus dem Gateway als Kennung => Name. */
    private function GatewayMitglieder(): array
    {
        $karte = [];
        foreach ($this->GatewayMitgliederRoh() as $u) {
            $id   = trim((string)($u['id'] ?? ''));
            $name = trim((string)($u['name'] ?? ''));
            if ($id !== '' && $name !== '') {
                $karte[$id] = $name;
            }
        }
        return $karte;
    }

    /**
     * Die Mitgliederliste des Gateways, roh — mit Rolle.
     *
     * Mit Merker, weil der Aufbau EINES Plans die Liste dutzendfach braucht:
     * je Kind fuer die Kennung, noch einmal fuer die Fotos, noch einmal beim
     * Zusammenstellen der Kinder. Am 11.09.2026 im Hook gezaehlt: 62 Aufrufe
     * fuer einen Abruf, jeder liest im Gateway die Mitglieder samt Bildern.
     *
     * Sicher ist der Merker nur fuer die Dauer EINES Aufrufs — laenger lebt das
     * Objekt nicht zwangslaeufig. Haelt die Laufzeit es doch (Timer, Kachel),
     * begrenzen 30 Sekunden den Schaden: ein neu angelegtes oder umbenanntes
     * Mitglied hinkt hoechstens so lange hinterher, und der Kachel-Takt ist
     * ohnehin zehnmal so lang.
     *
     * GEMERKT WIRD NUR EINE ANTWORT MIT INHALT. Laeuft der Abruf im Hook des
     * Gateways, laesst Symcon den Rueckruf nicht in die beschaeftigte Instanz
     * und die Antwort ist leer. Diesen Fehlschlag festzuhalten hiesse, ihn an
     * den naechsten Kachelaufbau weiterzureichen, dem dann die Fotos fehlten —
     * und ein abgewiesener Aufruf kostet nichts: alle 62 lagen in derselben
     * Sekunde.
     */
    private function GatewayMitgliederRoh(): array
    {
        $gw = $this->GatewayInstanz();
        if ($gw <= 0 || !function_exists('TGW_GetUsers')) {
            return [];
        }
        // Das Gateway MIT im Merker: wechselt die Eltern-Instanz, ist die
        // gemerkte Liste die einer fremden Familie.
        if ((int)($this->mitgliederMerker['gw'] ?? 0) === $gw
            && (microtime(true) - (float)($this->mitgliederMerker['zeit'] ?? 0.0))
               < self::MITGLIEDER_MERKER_S) {
            return (array)$this->mitgliederMerker['liste'];
        }
        try {
            $roh = json_decode((string)@TGW_GetUsers($gw), true);
        } catch (\Throwable $e) {
            return [];
        }
        $liste = is_array($roh) ? array_values(array_filter($roh, 'is_array')) : [];
        if ($liste !== []) {
            $this->mitgliederMerker = ['gw' => $gw, 'zeit' => microtime(true), 'liste' => $liste];
        }
        return $liste;
    }

    /**
     * Uebernehmen NACH dem laufenden Aufruf anstossen.
     *
     * Eine Selbstheilung, die eine Eigenschaft schreibt, muss sie auch wirksam
     * machen — und das geht nur ueber IPS_ApplyChanges. Steht der Aufruf aber
     * INNERHALB von ApplyChanges, ist es ein Aufruf in sich selbst: Symcon 9.1
     * lehnt ihn ab und bricht das Uebernehmen ab („Instanz ist durch eine selbst
     * gestartete Operation belegt", Code -32603). Bis 9.0 lief es stillschweigend
     * durch, deshalb ist es lange nicht aufgefallen.
     *
     * Der Einmal-Timer legt das Uebernehmen hinter den laufenden Aufruf. Beim
     * zweiten Durchgang ist die Selbstheilung erledigt, sie schreibt nichts mehr
     * und stoesst auch nichts mehr an — es bleibt bei genau einer Wiederholung.
     *
     * Ein Ident fuer alle Aufrufer: laufen mehrere Selbstheilungen im selben
     * Durchgang, genuegt EIN nachgelagertes Uebernehmen fuer alle.
     *
     * Der Timer ruft IPS_ApplyChanges unmittelbar und nicht ueber eine eigene
     * Funktion — so braucht es dafuer keine oeffentliche Schnittstelle.
     */
    private function UebernehmenNachtragen(): void
    {
        $this->RegisterOnceTimer('UebernehmenNachtragen', 'IPS_ApplyChanges($_IPS[\'TARGET\']);');
    }


    /**
     * Einmalig nach dem Update: die Gateway-Zuordnung, die dieses Modul ohnehin
     * benutzt, als Eltern-Instanz eintragen.
     *
     * Ohne sie muesste sie nach dem Update von Hand in der Konsole gesetzt
     * werden — bei jeder Instanz einzeln. Am Verhalten aendert sich nichts: Es
     * wird genau das Gateway verbunden, das die Instanz vorher schon gefragt
     * hat. Deshalb laeuft es still, ohne Meldung.
     *
     * Das Flag steht VOR dem Verbinden: IPS_ConnectInstance loest ApplyChanges
     * erneut aus. Wer die Verbindung spaeter bewusst loest, behaelt es so —
     * die Migration greift genau einmal.
     */
    private function GatewayEinmaligVerbinden(): void
    {
        /* NUR beim Kernelstart. Beim ANLEGEN einer Instanz laeuft ApplyChanges
           ebenfalls — und zwar bevor die Konsole den vom Nutzer gewaehlten
           Elternknoten eintraegt. Verbinden wir hier, faende die Konsole eine
           Instanz vor, die schon einen Vater hat, und meldete „Konnte nicht zur
           Instanz verbinden / Instanz #… hat bereits ein uebergeordnetes
           Objekt". Dieser Umzug gilt ALTinstanzen; eine neue verbindet die
           Konsole selbst, und wer den Dialog wegklickt, wird trotzdem bedient:
           das Gateway wird ohnehin ueber die niedrigste Kennung gefunden. */
        if (!$this->applyFromKernelStart) {
            return;
        }
        // Nie waehrend des Hochlaufs: IPS_ConnectInstance braucht fertige Objekte.
        // Das Flag bleibt dann ungesetzt, der naechste Anlauf holt es nach.
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }
        /* Ohne Kernel-Neustart nach dem Update ist NICHTS von beidem da: weder die
           Elternangabe des Moduls (module.json wird nur beim Start gelesen) noch
           das Attribut (entsteht in Create()). Dann hier aussteigen, bevor der
           Attribut-Zugriff eine Warnung ins Protokoll schreibt — verbinden liesse
           sich ohnehin nicht, IPS_ConnectInstance antwortet dann nur false. */
        $eigeneGuid = (string)(@IPS_GetInstance($this->InstanceID)['ModuleInfo']['ModuleID'] ?? '');
        $modulInfo  = $eigeneGuid !== '' ? @IPS_GetModule($eigeneGuid) : null;
        if (!is_array($modulInfo) || empty($modulInfo['ParentRequirements'])) {
            return;
        }
        if ((bool)@$this->ReadAttributeBoolean('ParentMigrated')) {
            return;
        }
        @$this->WriteAttributeBoolean('ParentMigrated', true);
        if ((int)(@IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0) > 0) {
            return;
        }
        $gateway = $this->GatewayInstanz();
        if ($gateway > 0 && @IPS_InstanceExists($gateway)) {
            @IPS_ConnectInstance($this->InstanceID, $gateway);
        }
    }

}

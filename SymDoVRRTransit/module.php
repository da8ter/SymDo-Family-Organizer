<?php

declare(strict_types=1);

require_once __DIR__ . '/libs/TransitStore.php';

/**
 * SymDo VRR Transit — Abfahrten, Strecken und der Schulweg als HTML-Kachel.
 *
 * Die Rheinbahn hat keine eigene öffentliche Schnittstelle; die
 * Fahrplanauskunft des VRR (EFA) liefert beides mit Echtzeit und ohne
 * Schlüssel. Zwei Ansichten in einer Kachel: die Abfahrtstafel einer
 * Haltestelle und die Zeitachse einer Verbindung.
 *
 * Das Stück, das eine Fahrplan-App nicht kann: **SymDo kennt den Stundenplan.**
 * Eine Strecke mit dem Modus „Schulweg" fragt ihn nach Beginn und Ende des
 * Schultags und zeigt von selbst die richtige Richtung — morgens die
 * Verbindung, mit der das Kind pünktlich ankommt, ab Unterrichtsbeginn die für
 * den Rückweg. Gezählt wird dabei nur, was wirklich stattfindet: fällt die
 * erste Stunde aus, darf es später los.
 *
 * ABGERUFEN WIRD IN DIESER INSTANZ, nicht im Gateway. Symcon führt je Instanz
 * genau eine Sache zur Zeit aus — ein Abruf von einer halben Sekunde im Gateway
 * ließe jede Anfrage der App so lange warten.
 */
class SymDoVRRTransit extends IPSModuleStrict
{
    use TransitStore;

    private const GATEWAY_GUID = '{E677FE7B-28C9-4124-8B58-8A1FE2657E8D}';

    /**
     * Der Startwert einer Kartenspalte. Symcon legt eine Position als
     * `{"latitude":…,"longitude":…}` ab; 0/0 ist die Nullinsel im Atlantik und
     * heisst hier „nie gesetzt" — TransitCalc::Punkt() prueft genau darauf.
     */
    /** Wie weit die Umkreissuche um eine Adresse schaut. */
    private const UMKREIS_M = 1200;

    private const KARTE_LEER = '{"latitude":0,"longitude":0}';

    /**
     * „connect" statt „require": an EINEM Gateway hängen mehrere Kacheln.
     * (ConnectParent/RequireParent gibt es für IPSModuleStrict nicht.)
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

        // Pflicht, damit Symcon die HTML-Kachel aus GetVisualizationTile() rendert
        $this->SetVisualizationType(1);

        $this->TransitCreate();
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }

        $this->GatewayEinmaligVerbinden();
        // Nach einem Kernelstart ist der Timer aus, der gemerkte Wert aber noch da.
        $this->TransitTaktVergessen();
        $this->TransitZeitenWandern();
        $this->TransitTaktSetzen(time());
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

    public function RequestAction(string $Ident, mixed $Value): void
    {
        $jetzt = time();
        switch ($Ident) {
            case 'Refresh':
                $this->TransitAbrufen($jetzt);
                /* Den Takt NEU setzen: der Zuschauer kann inzwischen weg sein,
                   und ein Timer, der einmal feuert und dann steht, holt nie
                   wieder etwas. */
                $this->TransitTaktSetzen($jetzt);
                $this->PushState();
                return;

            case 'GetState':
                /* Die Kachel fragt beim Öffnen, und das Gateway stößt seine
                   Kacheln hiermit an. Beides heißt: jemand sieht hin. */
                $this->TransitTaktSetzen($jetzt, $this->TransitGesehen($jetzt));
                $this->PushState();
                return;

            case 'StopSearch':
                $this->HaltestellenSuchen((string)$Value);
                return;

            case 'StopTours':
                $this->TourenHolen((string)$Value);
                return;

            case 'StopAdd':
                $this->HaltestelleUebernehmen((string)$Value);
                return;
        }
        parent::RequestAction($Ident, $Value);
    }

    // ------------------------------------------------------------------
    // Öffentliche Auskunft
    // ------------------------------------------------------------------

    /**
     * Der Zustand für andere Module — das Gateway holt ihn sich hier.
     *
     * Rein lesend und ohne Rückruf nach draußen: die Funktion läuft auch dann
     * vollständig, wenn sie IM Hook des Gateways aufgerufen wird, wo kein
     * Aufruf mehr in die beschäftigte Gateway-Instanz hineinkäme.
     */
    public function GetBoard(): string
    {
        $jetzt = time();
        $this->TransitTaktSetzen($jetzt, $this->TransitGesehen($jetzt));
        return (string)json_encode($this->TransitPayload($jetzt),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** Von Hand abrufen — für Skripte und für den Knopf im Formular. */
    public function Refresh(): void
    {
        $jetzt = time();
        $this->TransitGesehen($jetzt);
        $this->TransitAbrufen($jetzt);
        $this->TransitTaktSetzen($jetzt);
        $this->PushState();
    }

    // ------------------------------------------------------------------
    // Kachel
    // ------------------------------------------------------------------

    public function GetVisualizationTile(): string
    {
        $pfad = __DIR__ . '/module.html';
        $html = @file_get_contents($pfad);
        if (!is_string($html)) {
            $this->LogMessage('GetVisualizationTile: module.html nicht lesbar, Pfad=' . $pfad, KL_WARNING);
            return '';
        }
        /* JSON_HEX_TAG ist PFLICHT: die Nutzlast steht in einem <script>-Block,
           und ein „</script>" in einem Haltestellennamen beendete ihn —
           handleMessage liefe nie, der Rest landete als HTML in der Visu. */
        $zustand = json_encode($this->TransitPayload(time()),
            JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        return $html . '<script>handleMessage(' . $zustand . ');</script>';
    }

    private function PushState(): void
    {
        $this->UpdateVisualizationValue((string)json_encode($this->TransitPayload(time()),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    // ------------------------------------------------------------------
    // Formular
    // ------------------------------------------------------------------

    public function GetConfigurationForm(): string
    {
        $kinder = $this->MitgliederOptionen();
        $orte   = $this->OrtOptionen();

        $haltestellen = [
            'type'    => 'List',
            'name'    => 'Stops',
            'caption' => $this->Translate('Stops'),
            'rowCount' => 5,
            'add'     => true,
            'delete'  => true,
            'columns' => $this->HaltestellenSpalten($kinder),
            'values' => [],
        ];

        $strecken = [
            'type'    => 'List',
            'name'    => 'Routes',
            'caption' => $this->Translate('Routes'),
            'rowCount' => 5,
            'add'     => true,
            'delete'  => true,
            'columns' => $this->StreckenSpalten($kinder, $orte),
            'values' => [],
        ];

        $form = [
            'elements' => [
                ['type' => 'Label', 'caption' =>
                    $this->Translate('Departures and journeys from the VRR journey planner (EFA). ')
                    . $this->Translate('It covers Rheinbahn and every other operator in the network ')
                    . $this->Translate('and delivers real-time data.')],

                /* Die Quellenangabe. Der VRR gibt die Fahrplanauskunft als offene
                   Daten heraus (CC BY 4.0) — Namensnennung gehört dazu, und zwar
                   dort, wo die Daten zu sehen sind: hier, in der Kachel und im
                   Handbuch. */
                ['type' => 'Label', 'caption' =>
                    $this->Translate('Timetable data: Verkehrsverbund Rhein-Ruhr (VRR), open data under CC BY 4.0.')],

                ['type' => 'ExpansionPanel', 'caption' => $this->Translate('Find a stop'), 'expanded' => false,
                 'items' => [
                     ['type' => 'Label', 'caption' =>
                         $this->Translate('Include the town — it narrows the hits a lot: "Düsseldorf Benrath" ')
                         . $this->Translate('instead of just "Benrath".')],
                     ['type' => 'RowLayout', 'items' => [
                         ['type' => 'ValidationTextBox', 'name' => 'StopQuery', 'caption' => $this->Translate('Search'), 'width' => '320px'],
                         ['type' => 'Button', 'caption' => $this->Translate('Find'),
                          'onClick' => 'IPS_RequestAction($id, "StopSearch", $StopQuery);'],
                     ]],
                     ['type' => 'Select', 'name' => 'StopHit', 'caption' => $this->Translate('Hits'), 'width' => '520px',
                      'options' => [['caption' => $this->Translate('— nothing searched yet —'), 'value' => '']]],
                     /* $Stops ist die LEBENDE Haltestellenliste: die Konsole legt
                        vor jedem onClick jedes benannte Formularfeld als PHP-Variable
                        an, Listen als IPSList. json_encode() darauf gäbe nur die
                        ausgewählte Zeile (jsonSerialize), deshalb iterator_to_array. */
                     ['type' => 'Button', 'caption' => $this->Translate('Add as a stop'),
                      'onClick' => 'IPS_RequestAction($id, "StopAdd", json_encode(["hit" => $StopHit, "rows" => iterator_to_array($Stops)]));'],
                     ['type' => 'Label', 'name' => 'StopStatus', 'caption' => ' '],
                     ['type' => 'Label', 'caption' =>
                         $this->Translate('Every stop from this list can be picked as "From" or "To" in a route. ')
                         . $this->Translate('For your own front door choose "Map" there instead and set the marker.')],
                 ]],

                $haltestellen,
                ['type' => 'Label', 'caption' =>
                    $this->Translate('Lines and directions: one tick per line and destination. ')
                    . $this->Translate('Without a tick that one stays off the board; what is not listed keeps running.')],
                /* Der Knopf fuellt die Spalte mit dem, was dort wirklich faehrt.
                   Er arbeitet auf der LEBENDEN Liste ($Stops) und schreibt sie
                   ueber UpdateFormField zurueck — nichts wird gespeichert, bis
                   der Nutzer „Uebernehmen" drueckt. */
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Button', 'caption' => $this->Translate('Fetch lines and directions'),
                     'onClick' => 'IPS_RequestAction($id, "StopTours", json_encode(iterator_to_array($Stops)));'],
                    ['type' => 'Label', 'name' => 'DirStatus', 'caption' => ' '],
                ]],
                $strecken,

                ['type' => 'ExpansionPanel', 'caption' => $this->Translate('School run'), 'expanded' => false, 'items' => [
                    ['type' => 'Label', 'caption' =>
                        $this->Translate('A route in "school run" mode needs no time: it comes from the ')
                        . $this->Translate('child\'s timetable. Before lessons start it shows the journey to ')
                        . $this->Translate('school, afterwards the one home — start and destination swap over. ')
                        . $this->Translate('Only what actually takes place counts: if the first lesson is cancelled, ')
                        . $this->Translate('the child may leave later; if the last one is, it goes home earlier.')],
                    ['type' => 'NumberSpinner', 'name' => 'SchoolBuffer', 'caption' => $this->Translate('Buffer (minutes)'),
                     'minimum' => 0, 'maximum' => 60],
                    ['type' => 'Label', 'caption' =>
                        $this->Translate('On the way there it means "arrive this many minutes before lessons start", ')
                        . $this->Translate('on the way back "this many minutes from the classroom to the stop". ')
                        . $this->Translate('Each route may deviate from it.')],
                    ['type' => 'SelectInstance', 'name' => 'TimetableInstanceID',
                     'caption' => $this->Translate('Timetable (empty = automatic)')],
                ]],

                ['type' => 'ExpansionPanel', 'caption' => $this->Translate('Appearance'), 'expanded' => false, 'items' => [
                    ['type' => 'Select', 'name' => 'DefaultView', 'caption' => $this->Translate('View when opening'),
                     'options' => [
                         ['caption' => $this->Translate('Departures'), 'value' => 'departures'],
                         ['caption' => $this->Translate('Routes'), 'value' => 'routes'],
                     ]],
                    ['type' => 'Label', 'caption' =>
                        $this->Translate('Fetched once a minute, but only while the tile or the app is ')
                        . $this->Translate('open. The school run is also fetched in the morning without anyone watching, so ')
                        . $this->Translate('it is already there at the first glance.')],
                ]],
            ],
            'actions' => [
                ['type' => 'Button', 'caption' => $this->Translate('Fetch now'),
                 'onClick' => 'SDVT_Refresh($id);'],
            ],
            'status' => [],
        ];

        return (string)json_encode($form, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Die Familienmitglieder als Auswahl.
     *
     * Im FORMULAR ist der Rückruf ins Gateway unbedenklich: es läuft in der
     * Konsole und nicht im Hook. Kommt nichts, bleibt die Auswahl bei „alle" —
     * eine leere Antwort heißt „keine Auskunft", nicht „keine Mitglieder".
     *
     * @return list<array{caption:string,value:string}>
     */
    private function MitgliederOptionen(): array
    {
        $raus = [['caption' => $this->Translate('Whole family'), 'value' => '']];
        foreach ($this->TransitMitglieder() as $id => $m) {
            $raus[] = ['caption' => (string)$m['name'], 'value' => $id];
        }
        return $raus;
    }

    /**
     * Die Spalten der Haltestellenliste.
     *
     * Als eigene Methode, weil sie nicht nur beim Bauen des Formulars
     * gebraucht wird: nach dem Übernehmen einer Haltestelle werden die Spalten
     * der STRECKENLISTE im laufenden Formular ersetzt, damit die neue
     * Haltestelle sofort in der Auswahl steht.
     *
     * @param list<array{caption:string,value:string}> $kinder
     * @return list<array<string,mixed>>
     */
    private function HaltestellenSpalten(array $kinder): array
    {
        return [
                ['caption' => $this->Translate('Name'), 'name' => 'name', 'width' => '180px',
                 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                /* Die Kennung der Auskunft (`de:05111:18235`) braucht niemand zu
                   sehen — sie kommt aus der Suche und wird nie getippt.
                   `save` MUSS dabei stehen: es gilt von Haus aus nur fuer
                   sichtbare, bearbeitbare Spalten, und ohne den Eintrag faellt
                   der Wert beim Uebernehmen lautlos weg. Dann stuende die
                   Haltestelle ohne Kennung in der Liste und wuerde nie wieder
                   abgerufen. */
                ['caption' => $this->Translate('Stop id'), 'name' => 'stopId', 'width' => '200px',
                 'visible' => false, 'save' => true,
                 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                /* Der Haken entscheidet ueber die Abfahrtstafel UND ueber den
                   Abruf: eine Haltestelle, die nur als Start oder Ziel einer
                   Strecke gebraucht wird, kostet so keine Anfrage je Minute. */
                ['caption' => $this->Translate('Show in the tile'), 'name' => 'show', 'width' => '150px',
                 'add' => true, 'edit' => ['type' => 'CheckBox']],
                ['caption' => $this->Translate('For whom'), 'name' => 'member', 'width' => '160px',
                 'add' => '', 'edit' => ['type' => 'Select', 'options' => $kinder]],
                /* Die Touren als LISTE IN DER ZEILE. Eine Spalte darf `edit`
                   vom Typ List tragen; ihr Wert wird dann nicht noch einmal
                   JSON-kodiert, die Zelle ist also direkt ein Array.
                   JEDE innere Spalte braucht ein `edit`, auch die, die niemand
                   aendern soll. Ohne `edit` bleibt die Zelle im Zeilen-Editor
                   nicht nur leer — die Konsole schickt sie beim Speichern gar
                   nicht erst zurueck, und was dann ankommt, sind Zeilen, die
                   NUR den Haken tragen. Am 15.09.2026 genau so passiert: vier
                   Zeilen mit Haken, Linie und Richtung weg. */
                ['caption' => $this->Translate('Lines and directions'), 'name' => 'tours', 'width' => '300px',
                 'add' => [],
                 'edit' => ['type' => 'List', 'rowCount' => 10, 'add' => false, 'delete' => true,
                            'columns' => [
                                ['caption' => $this->Translate('Line'), 'name' => 'line', 'width' => '90px',
                                 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                                ['caption' => $this->Translate('Direction'), 'name' => 'direction', 'width' => 'auto',
                                 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                                ['caption' => $this->Translate('Show'), 'name' => 'show', 'width' => '90px',
                                 'add' => true, 'edit' => ['type' => 'CheckBox']],
                            ]]],
                ['caption' => $this->Translate('Walk (min)'), 'name' => 'walk', 'width' => '110px',
                 'add' => 0, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 0, 'maximum' => 60]],
                ['caption' => $this->Translate('Count'), 'name' => 'limit', 'width' => '90px',
                 'add' => 6, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 1, 'maximum' => 20]],

        ];
    }

    /**
     * Die Spalten der Streckenliste.
     *
     * @param list<array{caption:string,value:string}> $kinder
     * @param list<array{caption:string,value:string}> $orte
     * @return list<array<string,mixed>>
     */
    private function StreckenSpalten(array $kinder, array $orte): array
    {
        return [
                ['caption' => $this->Translate('Name'), 'name' => 'name', 'width' => '150px',
                 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                ['caption' => $this->Translate('For whom'), 'name' => 'member', 'width' => '150px',
                 'add' => '', 'edit' => ['type' => 'Select', 'options' => $kinder]],
                /* Auswahl statt Textfeld: die Kennungen der EFA („de:05111:18235")
                   tippt niemand ab, und ein Zahlendreher darin führt zu einer
                   Verbindung von irgendwo. Was hier steht, ist eingerichtet.

                   Die Optionen stehen IN der Spaltendefinition. Deshalb ersetzt
                   HaltestelleUebernehmen() nach dem Uebernehmen die ganze
                   Spaltenliste im laufenden Formular — sonst kaeme die neue
                   Haltestelle hier erst nach einem Neuoeffnen an. */
                ['caption' => $this->Translate('From'), 'name' => 'from', 'width' => '190px',
                 'add' => '', 'edit' => ['type' => 'Select', 'options' => $orte]],
                ['caption' => $this->Translate('From (map)'), 'name' => 'fromGeo', 'width' => '170px',
                 'add' => self::KARTE_LEER, 'edit' => ['type' => 'SelectLocation']],
                /* Eigener Name fuer das Ende der Verbindung. Leer = wie bisher,
                   also das, was die Auskunft nennt — bei einer Koordinate ist
                   das die Adresse, und „Zuhause" liest sich besser. */
                ['caption' => $this->Translate('From (label)'), 'name' => 'fromName', 'width' => '130px',
                 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                ['caption' => $this->Translate('To'), 'name' => 'to', 'width' => '190px',
                 'add' => '', 'edit' => ['type' => 'Select', 'options' => $orte]],
                ['caption' => $this->Translate('To (map)'), 'name' => 'toGeo', 'width' => '170px',
                 'add' => self::KARTE_LEER, 'edit' => ['type' => 'SelectLocation']],
                ['caption' => $this->Translate('To (label)'), 'name' => 'toName', 'width' => '130px',
                 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                ['caption' => $this->Translate('When'), 'name' => 'mode', 'width' => '150px',
                 'add' => 'school', 'edit' => ['type' => 'Select', 'options' => [
                     ['caption' => $this->Translate('School run (from the timetable)'), 'value' => 'school'],
                     ['caption' => $this->Translate('Leave now'), 'value' => 'dep'],
                     ['caption' => $this->Translate('Arrive by …'), 'value' => 'arr'],
                 ]]],
                /* Zeitwaehler statt Textfeld: „8" oder „08.00" waren erlaubt,
                   verstanden hat das Modul nur „08:00" — und schwieg sonst.
                   Gilt nur fuer „Ankommen bis …"; 00:00 heisst „nicht gesetzt". */
                ['caption' => $this->Translate('Time'), 'name' => 'time', 'width' => '110px',
                 'add' => TransitCalc::ZeitFeld('08:00'), 'edit' => ['type' => 'SelectTime']],
                ['caption' => $this->Translate('Buffer (min)'), 'name' => 'buffer', 'width' => '100px',
                 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                ['caption' => $this->Translate('Suggestions'), 'name' => 'count', 'width' => '100px',
                 'add' => 4, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 1, 'maximum' => 4]],
                /* Steuert NICHT mehr den Abruf — geholt wird ohnehin beides.
                   Sie entscheidet, was die Karte dieser Strecke auf der
                   UEBERSICHT zeigt: dort gibt es keinen Schalter, die Karte
                   soll ohne Bedienung das Richtige zeigen. Im Bereich selbst
                   waehlt weiter der Betrachter. */
                ['caption' => $this->Translate('Overview without changes'), 'name' => 'direct',
                 'width' => '190px', 'add' => false, 'edit' => ['type' => 'CheckBox']],

        ];
    }

    /**
     * Die eingerichteten Haltestellen als Auswahl für eine Strecke.
     *
     * Drei Gruppen stehen darin, und die dritte ist die wichtige: Werte, die in
     * einer Strecke stehen, aber nicht (mehr) in der Haltestellenliste. Ohne
     * sie hätte die Auswahl für so eine Zeile keinen passenden Eintrag — die
     * Konsole zeigte ein leeres Feld, und beim nächsten Speichern wäre das
     * Ziel lautlos weg. Eine Auswahlliste darf nie weniger können als der
     * Bestand, den sie bearbeitet.
     *
     * @param list<array<string,mixed>>|null $zeilen  die Haltestellen; null =
     *        die gespeicherten. Beim Übernehmen im laufenden Formular werden
     *        die LEBENDEN Zeilen übergeben — die gespeicherten kennen die
     *        soeben hinzugefügte noch nicht.
     *
     * @return list<array{caption:string,value:string}>
     */
    private function OrtOptionen(?array $zeilen = null): array
    {
        $raus    = [['caption' => $this->Translate('— please choose —'), 'value' => '']];
        $bekannt = [];
        foreach ($zeilen ?? $this->TransitZeilen('Stops') as $z) {
            $id = trim((string)($z['stopId'] ?? ''));
            if ($id === '' || isset($bekannt[$id])) {
                continue;
            }
            $bekannt[$id] = true;
            $name = trim((string)($z['name'] ?? ''));
            $raus[] = ['caption' => $name !== '' ? $name : $id, 'value' => $id];
        }
        foreach ($this->TransitZeilen('Routes') as $z) {
            foreach (['from', 'to'] as $feld) {
                $id = trim((string)($z[$feld] ?? ''));
                if ($id === '' || $id === TransitCalc::PUNKT_KARTE || isset($bekannt[$id])) {
                    continue;
                }
                $bekannt[$id] = true;
                $raus[] = ['caption' => $id . $this->Translate(' (not in the list of stops)'), 'value' => $id];
            }
        }
        $raus[] = ['caption' => $this->Translate('Map (own coordinate)'), 'value' => TransitCalc::PUNKT_KARTE];
        return $raus;
    }

    private function HaltestellenSuchen(string $suche): void
    {
        $suche = trim($suche);
        if ($suche === '') {
            $this->UpdateFormField('StopStatus', 'caption', $this->Translate('Please enter a name.'));
            return;
        }
        $antwort = Efa::Stopfinder($suche);
        if (($antwort['ok'] ?? false) !== true) {
            $this->UpdateFormField('StopStatus', 'caption',
                $this->Translate('The journey planner is not answering: ') . (string)($antwort['message'] ?? ''));
            return;
        }
        $daten   = (array)($antwort['data'] ?? []);
        $treffer = TransitCalc::Haltestellen($daten, 20);
        $hinweis = count($treffer) . $this->Translate(' hits, the best one is at the top.');

        if ($treffer === []) {
            /* Keine Haltestelle dieses Namens — aber vielleicht eine ADRESSE.
               Die EFA kennt „Duesseldorf, Kissbergweg" als Strasse mit
               Koordinate; was dort haelt, sagt erst die Umkreissuche. Genau
               das erwartet, wer seine eigene Strasse eintippt. */
            $ort = TransitCalc::Orte($daten, 1)[0] ?? null;
            if ($ort !== null) {
                $nah = Efa::Umkreis($ort['lat'], $ort['lon'], self::UMKREIS_M, 10);
                if (($nah['ok'] ?? false) === true) {
                    $treffer = TransitCalc::Umkreis((array)($nah['data'] ?? []), 10);
                }
                $hinweis = $treffer === []
                    ? $this->Translate('Nothing stops near ') . $ort['name'] . '.'
                    : $this->Translate('No stop of that name. Nearest to ') . $ort['name']
                      . $this->Translate(', closest first.');
            }
        }

        if ($treffer === []) {
            $this->UpdateFormField('StopStatus', 'caption', $this->Translate('No stop found.'));
            $this->UpdateFormField('StopHit', 'options',
                json_encode([['caption' => $this->Translate('— nothing found —'), 'value' => '']]));
            return;
        }
        $optionen = [];
        foreach ($treffer as $t) {
            // Die Entfernung nur bei der Umkreissuche — sonst gibt es keine.
            $weite = isset($t['distance']) && (int)$t['distance'] > 0
                ? '  (' . (int)$t['distance'] . ' m)' : '';
            $optionen[] = ['caption' => $t['name'] . $weite, 'value' => $t['id']];
        }
        $this->UpdateFormField('StopHit', 'options', json_encode($optionen));
        $this->UpdateFormField('StopHit', 'value', $treffer[0]['id']);
        $this->UpdateFormField('StopStatus', 'caption', $hinweis);
    }

    /**
     * Den gewählten Treffer als Zeile in die Haltestellenliste eintragen —
     * im LAUFENDEN Formular, ohne Speichern und ohne Neuöffnen.
     *
     * Das geht, weil die Konsole vor jedem `onClick` jedes benannte
     * Formularfeld als PHP-Variable bereitstellt: `$Stops` ist der Stand, den
     * der Nutzer gerade vor sich hat, samt seiner ungespeicherten Änderungen.
     * Genau der geht hier um eine Zeile ergänzt zurück.
     *
     * Zwei Dinge werden aktualisiert, und das zweite ist der eigentliche
     * Grund für diesen Umbau:
     *
     * 1. `Stops` → `values`: die Liste selbst.
     * 2. `Routes` → `columns`: die Spalten der Streckenliste werden komplett
     *    ersetzt, damit die Auswahl „Von"/„Nach" die neue Haltestelle sofort
     *    kennt. Die Optionen einer Spalte stehen in ihrer Definition, und die
     *    entsteht sonst nur beim Bauen des Formulars.
     *
     * Vorher wurde die EIGENSCHAFT geschrieben und angewendet — das überschrieb
     * dem Nutzer seine offenen Änderungen und zwang ihn, das Formular zu
     * schließen und neu zu öffnen. Jetzt bleibt das Speichern, wo es hingehört:
     * beim Knopf „Übernehmen".
     */
    private function HaltestelleUebernehmen(string $nutzlast): void
    {
        $roh    = json_decode($nutzlast, true);
        $stopId = trim((string)(is_array($roh) ? ($roh['hit'] ?? '') : $nutzlast));
        /* Kommt die Liste nicht mit (ein Aufruf von aussen, ein Skript), gilt
           der gespeicherte Stand — dann ist er auch der einzige. */
        $zeilen = is_array($roh) && is_array($roh['rows'] ?? null)
            ? $this->HaltestellenZeilen($roh['rows'])
            : $this->TransitZeilen('Stops');

        if ($stopId === '') {
            $this->UpdateFormField('StopStatus', 'caption', $this->Translate('Search first and pick a hit.'));
            return;
        }
        foreach ($zeilen as $z) {
            if (trim((string)($z['stopId'] ?? '')) === $stopId) {
                $this->UpdateFormField('StopStatus', 'caption', $this->Translate('This stop is already in the list.'));
                return;
            }
        }
        // Den Namen aus dem Treffer selbst holen — er steht in der Auswahl.
        $name = $stopId;
        $antwort = Efa::Stopfinder($stopId);
        if (($antwort['ok'] ?? false) === true) {
            foreach (TransitCalc::Haltestellen((array)($antwort['data'] ?? []), 5) as $t) {
                if ($t['id'] === $stopId) {
                    $name = $t['name'];
                    break;
                }
            }
        }
        /* Die Touren gleich mit — sie kosten denselben einen Abruf, den der
           Nutzer sonst gleich darauf mit dem Knopf ausloesen wuerde. Bleibt die
           Liste leer (Haltestelle gerade ohne Abfahrten, Nachtstunde, Stoerung),
           ist das kein Fehlschlag: der Knopf holt es spaeter nach. */
        [$touren, $knapp] = $this->TourenZelle($stopId);
        $zeilen[] = ['name' => $name, 'stopId' => $stopId, 'show' => true, 'member' => '',
                     'tours' => $touren, 'walk' => 0, 'limit' => 6];

        $this->UpdateFormField('Stops', 'values', (string)json_encode($zeilen, JSON_UNESCAPED_UNICODE));
        $this->UpdateFormField('Routes', 'columns', (string)json_encode(
            $this->StreckenSpalten($this->MitgliederOptionen(), $this->OrtOptionen($zeilen)),
            JSON_UNESCAPED_UNICODE));
        $hinweis = $this->Translate('Added: ') . $name;
        if ($touren !== []) {
            $hinweis .= sprintf($this->Translate(', %d line(s) and direction(s) found'), count($touren));
            if ($knapp) {
                $hinweis .= ' ' . sprintf(
                    $this->Translate('(cut off at %d — there are more)'), TransitCalc::TOUREN_MAX);
            }
            $hinweis .= $this->Translate('. Untick what you do not want to see');
        }
        $this->UpdateFormField('StopStatus', 'caption',
            $hinweis . $this->Translate('. Press "Apply" to keep it.'));
    }

    /**
     * Eine Tourenzelle auf ihre drei Felder zurechtstutzen.
     *
     * @param mixed $roh der Zellwert, wie er aus Formular oder Bestand kommt
     * @return list<array{line:string,direction:string,show:bool}>
     */
    private function TourenZeilen(mixed $roh): array
    {
        $raus = [];
        foreach ((is_array($roh) ? $roh : []) as $t) {
            if (!is_array($t)) {
                continue;
            }
            $linie = trim((string)($t['line'] ?? ''));
            $ziel  = trim((string)($t['direction'] ?? ''));
            /* Eine Zeile ohne Linie UND ohne Ziel ist keine Tour, sondern ein
               Rest. Genau so sah es am 15.09.2026 aus, als die inneren Spalten
               noch kein `edit` hatten: die Konsole schickte beim Speichern nur
               den Haken zurueck, und uebrig blieben vier leere Zeilen. Der
               `edit`-Fehler ist behoben; diese Wache bleibt, damit so etwas
               nicht noch einmal still in den Bestand wandert. */
            if ($linie === '' && $ziel === '') {
                continue;
            }
            $raus[] = [
                'line'      => $linie,
                'direction' => $ziel,
                /* Fehlt der Haken, gilt „sichtbar" — dieselbe Lesart wie in
                   TransitCalc::TourVersteckt. Eine Zeile ohne Haken darf nichts
                   verstecken, sonst verschwaende eine Linie, die niemand
                   abgewaehlt hat. */
                'show'      => !array_key_exists('show', $t) || (bool)$t['show'],
            ];
        }
        return $raus;
    }

    /**
     * Die Touren EINER Haltestelle, fertig fuer die Zelle.
     *
     * Eine Stelle fuer beide Wege — den Knopf und das Uebernehmen einer neuen
     * Haltestelle.
     *
     * @return array{0:list<array<string,mixed>>,1:bool} die Touren, und ob gekuerzt wurde
     */
    private function TourenZelle(string $stopId): array
    {
        /* Vierzig statt der sechs der Tafel: mit sechs Abfahrten saehe man an
           einer belebten Haltestelle nur EINE Richtung, und genau die andere
           sucht man meistens. */
        $antwort = Efa::Abfahrten($stopId, 40);
        if (($antwort['ok'] ?? false) !== true) {
            return [[], false];
        }
        $touren = TransitCalc::Touren((array)($antwort['data'] ?? []));
        return [$touren, count($touren) >= TransitCalc::TOUREN_MAX];
    }

    /**
     * Hoechstens so viele Haltestellen je Druck.
     *
     * Jede kostet einen Abruf von bis zu funfzehn Sekunden, und der laeuft in
     * der Spur DIESER Instanz — solange steht die Kachel. Acht sind
     * ertraeglich; wer mehr Haltestellen hat, drueckt zweimal.
     */
    private const RICHTUNGEN_STOPS_MAX = 8;

    /**
     * Die Tourenlisten fuellen — was an der Haltestelle wirklich faehrt.
     *
     * Gefuellt werden nur LEERE Listen. Eine gepflegte Liste ist eine
     * Entscheidung (dort stecken die entfernten Haken); sie zu ueberschreiben
     * waere der Datenverlust, den niemand erwartet. Wer neu holen will, loescht
     * die Zeilen der Liste.
     *
     * Wie bei „Als Haltestelle uebernehmen" wird die LEBENDE Liste bearbeitet
     * und ueber `UpdateFormField` zurueckgegeben: die ungespeicherten
     * Aenderungen des Nutzers bleiben erhalten, und gespeichert wird erst mit
     * „Uebernehmen".
     */
    private function TourenHolen(string $nutzlast): void
    {
        $roh = json_decode($nutzlast, true);
        /* Kommt die Liste nicht mit (ein Aufruf von aussen), gilt der
           gespeicherte Stand — dann ist er auch der einzige. */
        $zeilen = is_array($roh) ? $this->HaltestellenZeilen($roh) : $this->TransitZeilen('Stops');
        if ($zeilen === []) {
            $this->UpdateFormField('DirStatus', 'caption', $this->Translate('No stop in the list yet.'));
            return;
        }

        $gefuellt  = 0;
        $behalten  = 0;
        $ohne      = 0;
        $gekuerzt  = 0;
        $abgefragt = 0;
        foreach ($zeilen as $i => $z) {
            $stopId = trim((string)($z['stopId'] ?? ''));
            if ($stopId === '') {
                continue;
            }
            if (is_array($z['tours'] ?? null) && $z['tours'] !== []) {
                $behalten++;
                continue;
            }
            if ($abgefragt >= self::RICHTUNGEN_STOPS_MAX) {
                break;
            }
            $abgefragt++;
            [$touren, $knapp] = $this->TourenZelle($stopId);
            if ($touren === []) {
                $ohne++;
                continue;
            }
            /* Am Hauptbahnhof gibt es mehr Touren, als in den Zeilen-Editor
               gehoeren. Gekuerzt wird — aber SICHTBAR: eine still gekappte
               Liste sieht vollstaendig aus, und wer sich darauf verlaesst,
               vermisst eine Linie, die er nie zu sehen bekam. */
            if ($knapp) {
                $gekuerzt++;
            }
            $zeilen[$i]['tours'] = $touren;
            $gefuellt++;
        }

        $this->UpdateFormField('Stops', 'values', (string)json_encode($zeilen, JSON_UNESCAPED_UNICODE));
        $text = sprintf(
            $this->Translate('%1$d filled in, %2$d already set, %3$d without an answer. Untick what you do not want to see, then press "Apply".'),
            $gefuellt, $behalten, $ohne);
        if ($gekuerzt > 0) {
            $text .= ' ' . sprintf(
                $this->Translate('At %1$d stop(s) the list was cut off at %2$d entries — there are more.'),
                $gekuerzt, TransitCalc::TOUREN_MAX);
        }
        $this->UpdateFormField('DirStatus', 'caption', $text);
    }

    /**
     * Die Zeilen aus dem Formular auf die eigenen Spalten zurechtstutzen.
     *
     * Die Konsole schickt je Zeile mehr, als die Liste an Spalten hat
     * (`index_`, `editable`, `rowColor` …). Was nicht namentlich hier steht,
     * geht nicht zurück ins Formular — dieselbe weisse Liste wie überall im
     * Modul, und der Schutz davor, Konsolen-Innenleben in einer Eigenschaft zu
     * verewigen.
     *
     * @param array<mixed> $roh
     * @return list<array<string,mixed>>
     */
    private function HaltestellenZeilen(array $roh): array
    {
        $felder = ['name' => '', 'stopId' => '', 'show' => true, 'member' => '',
                   'tours' => [], 'walk' => 0, 'limit' => 6];
        $raus = [];
        foreach ($roh as $z) {
            if (!is_array($z)) {
                continue;
            }
            $zeile = [];
            foreach ($felder as $feld => $vorgabe) {
                $zeile[$feld] = array_key_exists($feld, $z) ? $z[$feld] : $vorgabe;
            }
            /* Die Mitgliederkennung ist eine ZEICHENKETTE. Kommt sie als Zahl
               (so stand sie in einer gespeicherten Zeile), findet die Konsole
               keine passende Option — ihr Vergleich ist strikt — und zeigt die
               Auswahl leer, obwohl das Modul die Zeile richtig zuordnet. */
            $zeile['member'] = (string)$zeile['member'];
            /* Die Tourenliste ist eine Zelle vom Typ List — ihr Wert kommt als
               Array. Was anderes darin steht (eine alte Zeile, ein Skript),
               waere im Formular eine kaputte Liste. */
            $zeile['tours'] = $this->TourenZeilen($zeile['tours']);
            $raus[] = $zeile;
        }
        return $raus;
    }

    // ------------------------------------------------------------------

    /**
     * Einmalig das Gateway als Eltern-Instanz eintragen. Das Flag steht VOR dem
     * Verbinden: IPS_ConnectInstance löst ApplyChanges erneut aus.
     */
    private function GatewayEinmaligVerbinden(): void
    {
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }
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
        if ((bool)@$this->ReadAttributeBoolean('ParentMigrated')) {
            return;
        }
        @$this->WriteAttributeBoolean('ParentMigrated', true);
        if ((int)(@IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0) > 0) {
            return;
        }
        $gateway = $this->TransitGateway();
        if ($gateway > 0 && @IPS_InstanceExists($gateway)) {
            @IPS_ConnectInstance($this->InstanceID, $gateway);
        }
    }
}

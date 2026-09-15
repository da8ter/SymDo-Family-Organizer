<?php

declare(strict_types=1);

require_once __DIR__ . '/libs/ChoreStore.php';

/**
 * SymDo Chores — der Ämtchenplan als HTML-Kachel.
 *
 * Haushaltsaufgaben, die WOCHENWEISE zwischen Familienmitgliedern wechseln:
 * wer diese Woche den Müll rausbringt, wer den Tisch abräumt. Die Routinen
 * sind das Gegenstück für den Tag (Häkchenlisten, die sich abends
 * zurücksetzen); hier geht es um die Woche und um die Reihe, wer dran ist.
 *
 * Punkte für erledigte Ämtchen landen im Münzbeutel der Routinen — ein Konto,
 * ein Guthaben, aus dem die Eltern auszahlen.
 */
class SymDoChores extends IPSModuleStrict
{
    use ChoreStore;

    private const GATEWAY_GUID  = '{E677FE7B-28C9-4124-8B58-8A1FE2657E8D}';
    private const ROUTINES_GUID = '{B1DF065E-80F5-49DF-B2B8-3CE657ED23BB}';

    public function Create(): void
    {
        parent::Create();

        // Pflicht, damit Symcon die HTML-Kachel aus GetVisualizationTile() rendert
        $this->SetVisualizationType(1);

        $this->RegisterPropertyString('Members', '[]');
        $this->RegisterPropertyString('Chores', '[]');
        $this->RegisterPropertyInteger('WeekStart', 1);
        // SelectTime trägt seinen Wert als JSON, wie im Briefing und in den Routinen.
        $this->RegisterPropertyString('ResetTime', '{"hour":3,"minute":0,"second":0}');
        $this->RegisterPropertyBoolean('CarryOver', false);
        $this->RegisterPropertyBoolean('PointsEnabled', false);
        $this->RegisterPropertyInteger('RoutinesInstanceID', 0);
        $this->RegisterPropertyBoolean('ShowNextWeek', true);
        $this->RegisterPropertyBoolean('ShowLastWeek', true);

        // Die laufende Woche steht eingefroren hier, genau eine Vorwoche daneben.
        $this->RegisterAttributeString('Week', '{}');
        $this->RegisterAttributeString('LastWeek', '{}');
        // Handkurbel: verschiebt die Rotation um Personen, ab nächster Woche.
        $this->RegisterAttributeInteger('Shift', 0);
        $this->RegisterAttributeString('PurseMirror', '{}');
        $this->RegisterAttributeString('KnownVarIdents', '[]');
        $this->RegisterAttributeBoolean('ParentMigrated', false);

        $this->RegisterTimer('WeeklyReset', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'WeeklyReset\', 0);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Kernel-Check: Kein Heavy Work vor KR_READY
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }

        $this->ElternanschlussLoesen();

        // Neuen Zeilen ihre Kennung geben; schreibt zurück und wendet danach erneut an.
        $this->AemtchenNachtragen();

        // Woche einfrieren bzw. umrollen, dann Variablen und Timer nachziehen.
        $this->WocheSicherstellen(time());
        $this->VariablenPflegen();
        $this->SetTimerInterval('WeeklyReset', $this->NaechsterWechselMs(time()));

        $this->PushState();
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'Check':
                $daten = is_array($Value) ? $Value : json_decode((string)$Value, true);
                if (is_array($daten)) {
                    $this->Abhaken(
                        trim((string)($daten['week'] ?? '')),
                        trim((string)($daten['chore'] ?? '')),
                        trim((string)($daten['slot'] ?? '')),
                        ($daten['done'] ?? false) === true,
                        time()
                    );
                    $this->VariablenNachziehen();
                    $this->PushState();
                }
                return;

            case 'WeeklyReset':
                $this->WocheSicherstellen(time());
                $this->VariablenPflegen();
                /* Den Timer NEU setzen: ohne das feuert er genau einmal und die
                   Woche wechselt nie wieder von selbst. */
                $this->SetTimerInterval('WeeklyReset', $this->NaechsterWechselMs(time()));
                $this->PushState();
                return;

            case 'GetState':
                // Das Gateway stößt seine Kacheln nach dem Speichern hiermit an.
                $this->PushState();
                return;
        }
        parent::RequestAction($Ident, $Value);
    }

    public function Destroy(): void
    {
        parent::Destroy();
    }

    // ------------------------------------------------------------------
    // Öffentliche Funktionen (Präfix CHR_)
    // ------------------------------------------------------------------

    /**
     * GetVisualizationTile bekommt von Symcon keinen Präfix-Wrapper, deshalb
     * dieser Getter (dasselbe Muster wie GetAppState in den Listen).
     */
    public function GetState(): string
    {
        return (string)json_encode($this->PayloadBauen(time()),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Die Rotation um Personen weiterdrehen. Wirkt AB DER NÄCHSTEN WOCHE — die
     * laufende steht eingefroren, damit gesetzte Häkchen und gebuchte Punkte
     * nicht plötzlich an einer anderen Person hängen.
     */
    public function Rotate(int $Personen): string
    {
        $neu = $this->Verschiebung() + $Personen;
        @$this->WriteAttributeInteger('Shift', $neu);
        $this->PushState();
        $vor = $this->Vorschau(time(), 1);
        $namen = [];
        $mitglieder = $this->Mitglieder();
        foreach ($this->AemtchenLesen() as $a) {
            $id = (string)($vor[0]['assign'][$a['id']] ?? '');
            $namen[] = $a['name'] . ': ' . ($id === '' ? '—' : (string)($mitglieder[$id]['name'] ?? $id));
        }
        return sprintf('%s (%+d): %s', $vor[0]['week'] ?? '', $neu, implode(' · ', $namen));
    }

    /** Vorschau als Text, für den Knopf im Formular. */
    public function Preview(int $Wochen): string
    {
        $mitglieder = $this->Mitglieder();
        $zeilen = [];
        foreach ($this->Vorschau(time(), $Wochen) as $w) {
            $teile = [];
            foreach ($this->AemtchenLesen() as $a) {
                $id = (string)($w['assign'][$a['id']] ?? '');
                $teile[] = $a['name'] . ': ' . ($id === '' ? '—' : (string)($mitglieder[$id]['name'] ?? $id));
            }
            $zeilen[] = $w['week'] . '  ' . implode(' · ', $teile);
        }
        return $zeilen === [] ? '—' : implode("\n", $zeilen);
    }

    /**
     * Der Münzstand eines Mitglieds, wie ihn die Kachel zeigt. Für Skripte und
     * den Sprachdialog.
     */
    public function GetPoints(string $MemberID): int
    {
        $stand = $this->Muenzstaende();
        return (int)($stand[trim($MemberID)] ?? 0);
    }

    // ------------------------------------------------------------------
    // Kachel
    // ------------------------------------------------------------------

    public function GetVisualizationTile(): string
    {
        $path = __DIR__ . '/module.html';
        $html = @file_get_contents($path);
        if (!is_string($html)) {
            $this->LogMessage('GetVisualizationTile: module.html nicht lesbar, Pfad=' . $path, KL_WARNING);
            return '';
        }
        /* JSON_HEX_TAG ist PFLICHT: die Nutzlast steht in einem <script>-Block,
           und ein „</script>" in einem Ämtchennamen beendete ihn — handleMessage
           liefe nie, der Rest landete als HTML in der Visu. */
        $payload = json_encode($this->PayloadBauen(time()),
            JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        return $html . '<script>handleMessage(' . $payload . ');</script>';
    }

    private function PushState(): void
    {
        $this->UpdateVisualizationValue(json_encode($this->PayloadBauen(time()),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    // ------------------------------------------------------------------
    // Formular
    // ------------------------------------------------------------------

    public function GetConfigurationForm(): string
    {
        $mitglieder = $this->MitgliederOptionen();
        $punkteAn = $this->EinstellungJa('PointsEnabled');

        // Wer-Spalte: Gruppen zuerst, dann jedes Mitglied einzeln (festes Ämtchen).
        $wer = [
            ['caption' => $this->Translate('All participants'), 'value' => 'all'],
            ['caption' => $this->Translate('Children only'), 'value' => 'child'],
            ['caption' => $this->Translate('Adults only'), 'value' => 'adult'],
        ];
        foreach ($mitglieder as $m) {
            if ($m['value'] !== '') {
                $wer[] = $m;
            }
        }

        $aemtchenSpalten = [
            // Die Kennung trägt die Zuordnung — unsichtbar, aber mit save,
            // sonst verwirft die Konsole sie beim Übernehmen.
            ['caption' => 'ID', 'name' => 'id', 'width' => '0px',
             'visible' => false, 'save' => true, 'add' => ''],
            ['caption' => $this->Translate('Emoji'), 'name' => 'emoji', 'width' => '80px',
             'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
            ['caption' => $this->Translate('Chore'), 'name' => 'name', 'width' => 'auto',
             'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
            ['caption' => $this->Translate('Per week'), 'name' => 'perWeek', 'width' => '110px',
             'add' => 1, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 1, 'maximum' => 7]],
            ['caption' => $this->Translate('Who'), 'name' => 'circle', 'width' => '180px',
             'add' => 'all', 'edit' => ['type' => 'Select', 'options' => $wer]],
        ];
        if ($punkteAn) {
            $aemtchenSpalten[] = ['caption' => $this->Translate('Points'), 'name' => 'points', 'width' => '90px',
                'add' => 5, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 0]];
        }

        $tage = [
            ['caption' => $this->Translate('Monday'), 'value' => 1],
            ['caption' => $this->Translate('Tuesday'), 'value' => 2],
            ['caption' => $this->Translate('Wednesday'), 'value' => 3],
            ['caption' => $this->Translate('Thursday'), 'value' => 4],
            ['caption' => $this->Translate('Friday'), 'value' => 5],
            ['caption' => $this->Translate('Saturday'), 'value' => 6],
            ['caption' => $this->Translate('Sunday'), 'value' => 7],
        ];

        $form = [
            'elements' => [
                [
                    'type' => 'ExpansionPanel', 'caption' => $this->Translate('Participants'), 'expanded' => true,
                    'items' => [
                        [
                            'type' => 'List', 'name' => 'Members', 'rowCount' => 5,
                            'add' => true, 'delete' => true, 'changeOrder' => true,
                            'columns' => [
                                ['caption' => $this->Translate('Member'), 'name' => 'memberId', 'width' => '220px',
                                 'add' => '', 'edit' => ['type' => 'Select', 'options' => $mitglieder]],
                                ['caption' => $this->Translate('Load'), 'name' => 'share', 'width' => '90px',
                                 'add' => 1, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 1, 'maximum' => 5]],
                                ['caption' => $this->Translate('Pause'), 'name' => 'pause', 'width' => '90px',
                                 'add' => false, 'edit' => ['type' => 'CheckBox']],
                            ],
                            'values' => [],
                        ],
                        ['type' => 'Label', 'caption' => $this->Translate('The order here is the order of the rotation. Pause lets somebody sit out WITHOUT shifting the order of the others — deleting a row does shift it. Load 2 means twice as many chores.')],
                    ],
                ],
                [
                    'type' => 'ExpansionPanel', 'caption' => $this->Translate('Chores'), 'expanded' => true,
                    'items' => [
                        [
                            'type' => 'List', 'name' => 'Chores', 'rowCount' => 8,
                            'add' => true, 'delete' => true, 'changeOrder' => true,
                            'columns' => $aemtchenSpalten,
                            'values' => [],
                        ],
                        ['type' => 'Label', 'caption' => $this->Translate('The order here decides who gets which chore first. Reordering takes effect from next week.')],
                    ],
                ],
                [
                    'type' => 'ExpansionPanel', 'caption' => $this->Translate('Behavior'), 'expanded' => false,
                    'items' => [
                        ['type' => 'Select', 'name' => 'WeekStart', 'caption' => $this->Translate('Week starts on'),
                         'options' => $tage],
                        ['type' => 'SelectTime', 'name' => 'ResetTime', 'caption' => $this->Translate('Week changes at')],
                        ['type' => 'Label', 'caption' => $this->Translate('Sunday evening still belongs to the old week — the change happens at the set time.')],
                        ['type' => 'CheckBox', 'name' => 'CarryOver', 'caption' => $this->Translate('Carry unfinished chores into the new week')],
                        ['type' => 'CheckBox', 'name' => 'PointsEnabled', 'caption' => $this->Translate('Book points into the coin purse of the routines')],
                        ['type' => 'Select', 'name' => 'RoutinesInstanceID', 'caption' => $this->Translate('Routines instance'),
                         'options' => $this->RoutinenOptionen()],
                        ['type' => 'Label', 'caption' => $this->Translate('Points land in the coin purse of the chosen routines instance — the same account the parents pay out from. Without a routines instance the chores work, just without points.')],
                        ['type' => 'CheckBox', 'name' => 'ShowNextWeek', 'caption' => $this->Translate('Show next week as a preview')],
                        ['type' => 'CheckBox', 'name' => 'ShowLastWeek', 'caption' => $this->Translate('Show last week')],
                        ['type' => 'Label', 'caption' => $this->Translate('The gateway provides the family members with photos. Which gateway is used is decided by the parent instance, to be set in the console.')],
                    ],
                ],
            ],
            'actions' => array_merge([
                [
                    'type' => 'RowLayout',
                    'items' => [
                        ['type' => 'Button', 'caption' => $this->Translate('Turn one person forward (from next week)'),
                         'onClick' => 'IPS_RequestAction($id, "GetState", ""); echo CHR_Rotate($id, 1);'],
                        ['type' => 'Button', 'caption' => $this->Translate('Preview of the next four weeks'),
                         'onClick' => 'echo CHR_Preview($id, 4);'],
                    ],
                ],
            ], $this->SpendenFormular()),
        ];

        // Gespeicherte Zeilen einsetzen (Listen ohne values zeigen sonst nichts)
        $cfg = $this->Konfiguration();
        $form['elements'][0]['items'][0]['values'] = (array)json_decode((string)($cfg['Members'] ?? '[]'), true);
        $form['elements'][1]['items'][0]['values'] = (array)json_decode((string)($cfg['Chores'] ?? '[]'), true);

        return (string)json_encode($form, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Auswahl der Routinen-Instanzen.
     *
     * Select und NICHT SelectInstance (Hausmuster aus dem Essensplan): eine
     * gespeicherte, nicht mehr auffindbare Wahl wird so ehrlich angezeigt
     * statt still auf „bitte wählen" zu fallen.
     */
    private function RoutinenOptionen(): array
    {
        $optionen = [['caption' => $this->Translate('— please select —'), 'value' => 0]];
        $ids = (array)@IPS_GetInstanceListByModuleID(self::ROUTINES_GUID);
        foreach ($ids as $id) {
            $optionen[] = ['caption' => sprintf('%s (#%d)', (string)@IPS_GetName((int)$id), (int)$id), 'value' => (int)$id];
        }
        usort($optionen, static fn(array $a, array $b): int => ((int)$a['value'] === 0 ? -1 : strcmp((string)$a['caption'], (string)$b['caption'])));
        $gewaehlt = $this->EinstellungZahl('RoutinesInstanceID');
        if ($gewaehlt > 0 && !in_array($gewaehlt, array_map('intval', array_column($optionen, 'value')), true)) {
            $optionen[] = ['caption' => sprintf($this->Translate('%s (not found)'), '#' . $gewaehlt), 'value' => $gewaehlt];
        }
        return $optionen;
    }

    /**
     * Der Spenden-Block, wie ihn die anderen Module zeigen. Er steht EINMAL in
     * ToDoOverview/form.json und wird von dort ab „DonationHeader" übernommen —
     * dasselbe Vorgehen wie in den Routinen und im Essensplan.
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

    // ------------------------------------------------------------------
    // Statusvariablen
    // ------------------------------------------------------------------

    /**
     * Je Ämtchen „erledigt" und „ist dran", je Mitglied die Punkte dieser
     * Woche, dazu der Fortschritt der Woche.
     *
     * Nur MaintainVariable, KEINE Variablenprofile (nur Presentation-Arrays),
     * und kein EnableAction: abgehakt wird über die Kachel. Ein zweiter
     * Schreibweg über die Variable würde die Punktebuchung umgehen.
     */
    private function VariablenPflegen(): void
    {
        $gewollt = [];

        $this->MaintainVariable('PROGRESS', $this->Translate('Week completed'), VARIABLETYPE_INTEGER, [
            'PRESENTATION'     => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'             => 'Ok',
            'SUFFIX'           => ' %',
            'DIGITS'           => 0,
            'INTERVALS_ACTIVE' => true,
            'INTERVALS'        => json_encode([
                $this->Intervall(0, 33, $this->Translate('Let\'s go'), 0xFF8000),
                $this->Intervall(34, 66, $this->Translate('Running'), 0xFFFF00),
                $this->Intervall(67, 99, $this->Translate('Almost'), 0x9ACD32),
                $this->Intervall(100, 100, $this->Translate('All done'), 0x00C767),
            ], JSON_UNESCAPED_UNICODE),
        ], 10, true);
        $gewollt[] = 'PROGRESS';

        $pos = 20;
        foreach ($this->AemtchenLesen() as $a) {
            $identD = 'DONE_' . $a['id'];
            $identW = 'WHO_' . $a['id'];
            $this->MaintainVariable($identD, $a['name'], VARIABLETYPE_BOOLEAN, [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                'OPTIONS'      => json_encode([
                    ['Value' => false, 'Caption' => $this->Translate('Open'), 'IconActive' => false, 'IconValue' => '',
                     'ColorActive' => true, 'ColorValue' => 0x808080, 'ContentColorActive' => false, 'ContentColorValue' => -1],
                    ['Value' => true, 'Caption' => $this->Translate('Done'), 'IconActive' => false, 'IconValue' => '',
                     'ColorActive' => true, 'ColorValue' => 0x00C767, 'ContentColorActive' => false, 'ContentColorValue' => -1],
                ], JSON_UNESCAPED_UNICODE),
            ], $pos, true);
            $this->MaintainVariable($identW, sprintf($this->Translate('%s is on duty'), $a['name']), VARIABLETYPE_STRING, [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                'ICON'         => 'Information',
            ], $pos + 1, true);
            $gewollt[] = $identD;
            $gewollt[] = $identW;
            $pos += 10;
        }

        if ($this->EinstellungJa('PointsEnabled')) {
            foreach ($this->TeilnehmerLesen() as $z) {
                $ident = 'POINTS_' . $z['memberId'];
                $this->MaintainVariable($ident, $this->Translate('Points this week'), VARIABLETYPE_INTEGER, [
                    'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                    'ICON'         => 'coins',
                    'DIGITS'       => 0,
                ], $pos, true);
                $gewollt[] = $ident;
                $pos += 10;
            }
        }

        /* Was nicht mehr gewollt ist, wird gelöscht. Der Typ ist dabei
           gleichgültig, weil $keep = false direkt löscht — wortgleich aus den
           Routinen übernommen. */
        $bekannt = json_decode((string)@$this->ReadAttributeString('KnownVarIdents'), true);
        foreach (is_array($bekannt) ? $bekannt : [] as $alt) {
            if (!in_array((string)$alt, $gewollt, true)) {
                $this->MaintainVariable((string)$alt, '', VARIABLETYPE_BOOLEAN, '', 0, false);
            }
        }
        @$this->WriteAttributeString('KnownVarIdents', (string)json_encode($gewollt));

        $this->VariablenNachziehen();
    }

    /** Ein Intervall der Wertanzeige, Helfer wie im Programmierstandard. */
    private function Intervall(int $min, int $max, string $text, int $farbe): array
    {
        return [
            'IntervalMinValue' => $min, 'IntervalMaxValue' => $max,
            'ConstantActive' => true, 'ConstantValue' => $text,
            'ConversionFactor' => 1,
            'PrefixActive' => false, 'PrefixValue' => '',
            'SuffixActive' => false, 'SuffixValue' => '',
            'DigitsActive' => false, 'DigitsValue' => 0,
            'IconActive' => false, 'IconValue' => '',
            'ColorActive' => true, 'ColorValue' => $farbe,
            'ContentColorActive' => false, 'ContentColorValue' => -1,
        ];
    }

    /** Die Werte der Variablen auf den Stand der laufenden Woche bringen. */
    private function VariablenNachziehen(): void
    {
        $stand = $this->PayloadBauen(time());
        @$this->SetValue('PROGRESS', (int)$stand['progress']);
        $mitglieder = $this->Mitglieder();
        foreach ($stand['chores'] as $a) {
            $alle = $a['total'] > 0 && $a['doneCount'] >= $a['total'];
            @$this->SetValue('DONE_' . $a['id'], $alle);
            $wer = (string)$a['memberId'];
            @$this->SetValue('WHO_' . $a['id'], $wer === ''
                ? $this->Translate('— none —')
                : (string)($mitglieder[$wer]['name'] ?? $wer));
        }
        if ($this->EinstellungJa('PointsEnabled')) {
            foreach ($this->TeilnehmerLesen() as $z) {
                @$this->SetValue('POINTS_' . $z['memberId'], (int)($stand['earned'][$z['memberId']] ?? 0));
            }
        }
    }

    // ------------------------------------------------------------------
    // Eltern-Instanz
    // ------------------------------------------------------------------

    /** Übernehmen aus laufendem Übernehmen heraus: erst NACH diesem Aufruf. */
    private function UebernehmenNachtragen(): void
    {
        $this->RegisterOnceTimer('UebernehmenNachtragen', 'IPS_ApplyChanges($_IPS[\'TARGET\']);');
    }

    /**
     * Einen bestehenden Elternanschluss wieder aufloesen.
     *
     * Seit dem 15.09.2026 haengt keine Kachel mehr am Gateway. Der Grund ist
     * kein Schoenheitsfehler, sondern ein Datenverlust: die Konsole bietet beim
     * LOESCHEN einer Instanz ihre uebergeordnete mit an. So ist am 15.09.2026
     * mit einer VRR-Instanz das Gateway mitgegangen — mit allen Notizen,
     * gekoppelten Geraeten und Zugangsdaten. Im Protokoll stehen beide
     * „Entferne..." in derselben Sekunde; sechs weitere Kacheln hingen daran,
     * es half nichts.
     *
     * Einen Anschluss OHNE diese Gefahr gibt es nicht: ohne
     * `parentRequirements` weist Symcon jedes `IPS_ConnectInstance` mit
     * „Datenfluss ist inkompatibel" ab — auch dann, wenn zusaetzlich die
     * `childRequirements` des Gateways leer sind (beides gemessen). Also faellt
     * der Anschluss weg. Gefunden wird das Gateway ueber die niedrigste
     * Kennung, so wie die beiden Uebersichts-Kacheln es immer schon tun.
     *
     * Ohne Merker und bei jedem Uebernehmen: Trennen ist idempotent, und beim
     * zweiten Mal ist schon nichts mehr da. Wer von Hand wieder anschliesst,
     * bekommt es wieder geloest — genau das ist gewollt.
     */
    private function ElternanschlussLoesen(): void
    {
        if ((int)(@IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0) > 0) {
            @IPS_DisconnectInstance($this->InstanceID);
        }
    }
}

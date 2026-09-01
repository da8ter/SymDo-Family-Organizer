<?php

declare(strict_types=1);

require_once __DIR__ . '/libs/RoutineStore.php';

/**
 * SymDo Routines — tägliche Häkchenlisten für Kinder als HTML-Kachel.
 *
 * Eine Instanz hält beliebig viele Routinen (Morgen-, Abend-, Hausaufgaben…),
 * jede mit eigenen Schritten, optionalem SymDo-Mitglied (Avatar) und einem
 * Anzeigefenster (Von/Bis). Die Kachel zeigt zur Uhrzeit die passende Routine;
 * ist keine dran, springen die heutigen Aufgaben der Kinder ein. Häkchen
 * setzen sich täglich zur Reset-Zeit zurück; optional gibt es Münzen.
 */
class SymDoRoutines extends IPSModuleStrict
{
    use RoutineStore;

    private const GATEWAY_GUID = '{E677FE7B-28C9-4124-8B58-8A1FE2657E8D}';
    private const TODO_GUID    = '{E0E38D9B-31BC-4F5E-A6CA-91A2A60C7C46}';

    // Änderungen an diesen Variablen der ToDo-Listen stoßen den Kachel-Push an
    private const TODO_TRIGGER_IDENTS = ['OpenTasks', 'OverdueTasks', 'DueTodayTasks'];

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

    public function Create(): void
    {
        parent::Create();
        $this->RegisterAttributeBoolean('ParentMigrated', false);

        // Pflicht, damit Symcon die HTML-Kachel aus GetVisualizationTile() rendert
        $this->SetVisualizationType(1);

        $this->RegisterPropertyString('Routines', '[]');
        $this->RegisterPropertyString('Steps', '[]');
        // SelectTime trägt seinen Wert als JSON, genau wie im Briefing.
        $this->RegisterPropertyString('ResetTime', '{"hour":3,"minute":0,"second":0}');
        $this->RegisterPropertyBoolean('CoinsEnabled', false);
        $this->RegisterPropertyBoolean('IdleTodos', true);
        $this->RegisterPropertyInteger('GatewayInstanceID', 0);

        $this->RegisterAttributeString('State', '{}');
        $this->RegisterAttributeString('Coins', '{}');
        // Für sauberes Abräumen: welche Variablen-Idents dieses Modul angelegt hat
        $this->RegisterAttributeString('KnownVarIdents', '[]');
        $this->RegisterAttributeString('SubscribedVarIDs', '[]');

        $this->RegisterTimer('DailyReset', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'DailyReset\', 0);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Kernel-Check: Kein Heavy Work vor KR_READY
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }

        $this->GatewayEinmaligVerbinden();

        // Neuen Zeilen ihre Kennung geben (unsichtbare id-Spalte, add liefert '').
        // Schreibt zurück und wendet über den Einmal-Timer erneut an.
        $this->RoutinenNachtragen();

        // 1. Alte Abos/Referenzen sauber lösen (kein Leak bei Konfig-Wechsel)
        $vorher = json_decode($this->ReadAttributeString('SubscribedVarIDs'), true);
        foreach (is_array($vorher) ? $vorher : [] as $altID) {
            if ((int)$altID > 0) {
                $this->UnregisterMessage((int)$altID, VM_UPDATE);
            }
        }
        foreach ($this->GetReferenceList() as $refID) {
            $this->UnregisterReference($refID);
        }

        // 2. Für die Leerlauf-ToDos die Stat-Variablen ALLER ToDo-Listen
        // abonnieren — jede Mutation dort läuft durch diese Variablen.
        $abos = [];
        if ((bool)@IPS_GetProperty($this->InstanceID, 'IdleTodos')) {
            foreach ((array)@IPS_GetInstanceListByModuleID(self::TODO_GUID) as $listID) {
                $this->RegisterReference((int)$listID);
                foreach (self::TODO_TRIGGER_IDENTS as $ident) {
                    $varID = @IPS_GetObjectIDByIdent($ident, (int)$listID);
                    if (is_int($varID) && $varID > 0) {
                        $this->RegisterMessage($varID, VM_UPDATE);
                        $abos[] = $varID;
                    }
                }
            }
        }
        $this->WriteAttributeString('SubscribedVarIDs', json_encode($abos));

        // 3. Status-Variablen pflegen: je Routine „erledigt", je Geldbeutel Münzen
        $this->VariablenPflegen();

        // 4. Tagesreset zur eingestellten Uhrzeit
        $this->SetTimerInterval('DailyReset', $this->NaechsteResetMs(time()));

        $this->PushState();
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        switch ($Message) {
            case IPS_KERNELSTARTED:
                $this->ApplyChanges();
                return;
            case VM_UPDATE:
                $this->PushState();
                return;
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'Check':
                $daten = is_array($Value) ? $Value : json_decode((string)$Value, true);
                if (is_array($daten)) {
                    $this->Abhaken(
                        trim((string)($daten['routine'] ?? '')),
                        (int)($daten['step'] ?? -1),
                        (bool)($daten['done'] ?? false),
                        time()
                    );
                    $this->PushState();
                }
                return;

            case 'TodoCheck':
                // Abhaken einer Heute-Aufgabe: durchgereicht an die ToDo-Liste,
                // mit Zielzustand. Die GUID-Probe verhindert, dass die Kachel
                // eine beliebige Instanz anspricht.
                $daten = is_array($Value) ? $Value : json_decode((string)$Value, true);
                $liste = is_array($daten) ? (int)($daten['list'] ?? 0) : 0;
                $id    = is_array($daten) ? (int)($daten['id'] ?? 0) : 0;
                if ($liste > 0 && $id > 0 && IPS_InstanceExists($liste)
                    && (IPS_GetInstance($liste)['ModuleInfo']['ModuleID'] ?? '') === self::TODO_GUID) {
                    try {
                        IPS_RequestAction($liste, 'ToggleDone',
                            json_encode(['id' => $id, 'done' => (bool)($daten['done'] ?? true)]));
                    } catch (\Throwable $e) {
                        $this->SendDebug('TodoCheck', $e->getMessage(), 0);
                    }
                }
                // Der Push kommt gleich über das VM_UPDATE-Abo; zur Sicherheit
                // trotzdem einmal direkt, falls die Liste keine Stat-Variablen hat.
                $this->PushState();
                return;

            case 'DailyReset':
                $this->TagesReset(time());
                $this->SetTimerInterval('DailyReset', $this->NaechsteResetMs(time()));
                $this->PushState();
                return;
        }
        parent::RequestAction($Ident, $Value);
    }

    /**
     * Geldbeutel von Hand anpassen — der Eltern-Hebel zum Einlösen
     * („−50 für ein Eis"). Beutel-Kennung ist die Mitglieds-Kennung des Kindes,
     * bei Routinen ohne Kind die Routine-Kennung. Liefert den neuen Stand.
     */
    public function AdjustCoins(string $PurseID, int $Delta): int
    {
        $neu = $this->MuenzenAnpassen(trim($PurseID), $Delta);
        $this->PushState();
        return $neu;
    }

    /**
     * Der Kachel-Zustand als JSON — für Diagnose und Skripte.
     * GetVisualizationTile bekommt von Symcon keinen Präfix-Wrapper, deshalb
     * dieser Getter (dasselbe Muster wie GetAppState in den Listen).
     */
    public function GetState(): string
    {
        return (string)json_encode($this->PayloadBauen(time()),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public function GetVisualizationTile(): string
    {
        $path = __DIR__ . '/module.html';
        $html = @file_get_contents($path);
        if (!is_string($html)) {
            $this->LogMessage('GetVisualizationTile: module.html nicht lesbar, Pfad=' . $path, KL_WARNING);
            return '';
        }
        // Initial-Payload inline mitgeben, damit die Kachel sofort korrekt zeichnet
        $payload = json_encode($this->PayloadBauen(time()),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        return $html . '<script>handleMessage(' . $payload . ');</script>';
    }

    public function GetConfigurationForm(): string
    {
        $mitglieder = $this->MitgliederOptionen();
        $muenzenAn  = (bool)@IPS_GetProperty($this->InstanceID, 'CoinsEnabled');

        $routinenOptionen = [];
        foreach ($this->RoutinenLesen() as $r) {
            $routinenOptionen[] = ['caption' => $r['name'], 'value' => $r['id']];
        }
        if ($routinenOptionen === []) {
            $routinenOptionen[] = ['caption' => $this->Translate('— first add a routine above —'), 'value' => ''];
        }

        $schrittSpalten = [
            // Die Kennung trägt die Zuordnung — unsichtbar, aber mit save,
            // sonst verwirft die Konsole sie beim Übernehmen.
            ['caption' => $this->Translate('Routine'), 'name' => 'routine', 'width' => '180px',
             'add' => $routinenOptionen[0]['value'],
             'edit' => ['type' => 'Select', 'options' => $routinenOptionen]],
            ['caption' => $this->Translate('Emoji'), 'name' => 'emoji', 'width' => '80px',
             'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
            ['caption' => $this->Translate('Step'), 'name' => 'text', 'width' => 'auto',
             'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
        ];
        if ($muenzenAn) {
            $schrittSpalten[] = ['caption' => $this->Translate('Coins'), 'name' => 'coins', 'width' => '90px',
                'add' => 5, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 0]];
        }

        $form = [
            'elements' => [
                [
                    'type' => 'ExpansionPanel', 'caption' => $this->Translate('Routines'), 'expanded' => true,
                    'items' => [
                        [
                            'type' => 'List', 'name' => 'Routines', 'rowCount' => 4,
                            'add' => true, 'delete' => true,
                            'columns' => [
                                ['caption' => 'ID', 'name' => 'id', 'width' => '0px',
                                 'visible' => false, 'save' => true, 'add' => ''],
                                ['caption' => $this->Translate('Name'), 'name' => 'name', 'width' => 'auto',
                                 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                                ['caption' => $this->Translate('Emoji'), 'name' => 'emoji', 'width' => '80px',
                                 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                                ['caption' => $this->Translate('Child'), 'name' => 'memberId', 'width' => '160px',
                                 'add' => '', 'edit' => ['type' => 'Select', 'options' => $mitglieder]],
                                ['caption' => $this->Translate('From'), 'name' => 'von', 'width' => '110px',
                                 'add' => '', 'edit' => ['type' => 'SelectTime']],
                                ['caption' => $this->Translate('Until'), 'name' => 'bis', 'width' => '110px',
                                 'add' => '', 'edit' => ['type' => 'SelectTime']],
                            ],
                            'values' => [],
                        ],
                        ['type' => 'Label', 'caption' => $this->Translate('From/Until decide when the tile shows a routine. Without times the routine is always visible. A window past midnight (e.g. 20:00–06:00) works.')],
                    ],
                ],
                [
                    'type' => 'ExpansionPanel', 'caption' => $this->Translate('Steps'), 'expanded' => true,
                    'items' => [
                        [
                            'type' => 'List', 'name' => 'Steps', 'rowCount' => 8,
                            'add' => true, 'delete' => true, 'changeOrder' => true,
                            'columns' => $schrittSpalten,
                            'values' => [],
                        ],
                        ['type' => 'Label', 'caption' => $this->Translate('The order here is the order on the tile. Ticks reset daily at the reset time; reordering clears today\'s ticks of the affected routine.')],
                    ],
                ],
                [
                    'type' => 'ExpansionPanel', 'caption' => $this->Translate('Behavior'), 'expanded' => false,
                    'items' => [
                        ['type' => 'SelectTime', 'name' => 'ResetTime', 'caption' => $this->Translate('Daily reset at')],
                        ['type' => 'CheckBox', 'name' => 'CoinsEnabled', 'caption' => $this->Translate('Reward coins (amount per step, default 5)')],
                        ['type' => 'CheckBox', 'name' => 'IdleTodos', 'caption' => $this->Translate('Show today\'s tasks of the children when no routine is active')],
                        ['type' => 'Label', 'caption' => $this->Translate('The gateway provides the family members with photos. Without one, routines work — just without avatars and without today\'s tasks. Which gateway is used is decided by the parent instance, to be set in the console.')],
                    ],
                ],
            ],
            'actions' => $this->SpendenFormular(),
        ];

        // Gespeicherte Zeilen einsetzen (Listen ohne values zeigen sonst nichts)
        $cfg = json_decode((string)@IPS_GetConfiguration($this->InstanceID), true);
        $form['elements'][0]['items'][0]['values'] = (array)json_decode((string)($cfg['Routines'] ?? '[]'), true);
        $form['elements'][1]['items'][0]['values'] = (array)json_decode((string)($cfg['Steps'] ?? '[]'), true);

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

    // ------------------------------------------------------------------
    // Intern
    // ------------------------------------------------------------------

    private function PushState(): void
    {
        $this->UpdateVisualizationValue(json_encode($this->PayloadBauen(time()), JSON_UNESCAPED_SLASHES));
    }

    /**
     * Status-Variablen anlegen/abräumen: je Routine ein „erledigt"-Schalterwert,
     * je Geldbeutel (bei eingeschalteten Münzen) ein Zähler. MaintainVariable,
     * damit alles ohne Kernel-Neustart entsteht.
     */
    private function VariablenPflegen(): void
    {
        $gewollt = [];
        $mitglieder = $this->Mitglieder();
        $muenzenAn = (bool)@IPS_GetProperty($this->InstanceID, 'CoinsEnabled');

        $position = 10;
        foreach ($this->RoutinenLesen() as $r) {
            $ident = 'DONE_' . $r['id'];
            $gewollt[$ident] = true;
            $this->MaintainVariable($ident, sprintf($this->Translate('%s completed'), $r['name']),
                VARIABLETYPE_BOOLEAN, [
                    'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                    'OPTIONS'      => json_encode([
                        ['Value' => false, 'Caption' => $this->Translate('Open'), 'IconActive' => false, 'IconValue' => '',
                         'ColorActive' => true, 'ColorValue' => 0x808080, 'ContentColorActive' => false, 'ContentColorValue' => -1],
                        ['Value' => true, 'Caption' => $this->Translate('Done'), 'IconActive' => false, 'IconValue' => '',
                         'ColorActive' => true, 'ColorValue' => 0x00C767, 'ContentColorActive' => false, 'ContentColorValue' => -1],
                    ], JSON_UNESCAPED_UNICODE),
                ], $position, true);
            $position += 10;

            if ($muenzenAn) {
                $beutel = $r['memberId'] !== '' ? $r['memberId'] : $r['id'];
                $ident  = 'COINS_' . $beutel;
                if (!isset($gewollt[$ident])) {
                    $gewollt[$ident] = true;
                    $name = $r['memberId'] !== '' ? (string)($mitglieder[$r['memberId']]['name'] ?? '') : $r['name'];
                    $this->MaintainVariable($ident, sprintf($this->Translate('Coins %s'), $name !== '' ? $name : $beutel),
                        VARIABLETYPE_INTEGER, [
                            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                            'ICON'         => 'coins',
                            'SUFFIX'       => '',
                        ], $position, true);
                    $position += 10;
                }
            }
        }

        // Was dieses Modul früher angelegt hat und jetzt nicht mehr braucht, fliegt.
        $bekannt = json_decode($this->ReadAttributeString('KnownVarIdents'), true);
        foreach (is_array($bekannt) ? $bekannt : [] as $alt) {
            if (!isset($gewollt[(string)$alt])) {
                $this->MaintainVariable((string)$alt, '', VARIABLETYPE_BOOLEAN, '', 0, false);
            }
        }
        $this->WriteAttributeString('KnownVarIdents', json_encode(array_keys($gewollt)));
    }

    /** Übernehmen aus laufendem Übernehmen heraus: erst NACH diesem Aufruf. */
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

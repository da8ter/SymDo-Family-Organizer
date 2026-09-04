<?php

declare(strict_types=1);

/**
 * SymDoNotes — der Notizbereich der SymDo-App als eigene Kachel.
 *
 * KEINE eigene Codebase: module.html ist die Web-App, wortgleich uebernommen
 * (tools/uebernahme-webapp.py), samt locale.json. Wer am Notizbereich etwas
 * aendern will, aendert ihn in SymDoWebApp/module.html und uebernimmt neu —
 * von Hand wird hier nichts nachgepflegt, sonst laufen die beiden Oberflaechen
 * auseinander.
 *
 * Serverseitig sind nur drei Dinge anders als bei der Web-App-Kachel:
 *
 *  - der Zustand nennt NUR den Notizbereich (tabs), die Leiste faellt damit
 *    ganz weg (html.no-tabs in der Web-App),
 *  - es gibt keine Listen-Instanzen, also auch kein Revisionsspiel,
 *  - die Notizen selbst liegen im Gateway und reisen ueber dessen
 *    AiCall-Relay: aiPost('/notes', …) → RequestAction('AiCall') →
 *    IPS_RequestAction($gateway, 'AiTileRequest', …) → NotesHandleAction().
 *    Einen Token hat die Kachel nicht, dieser Weg ist der einzige.
 */
class SymDoNotes extends IPSModuleStrict
{
    private const GATEWAY_MODULE_GUID = '{E677FE7B-28C9-4124-8B58-8A1FE2657E8D}';

    /**
     * Vorschlagsliste der Konsole beim Anlegen: ein vorhandenes Gateway
     * anbieten oder eines anlegen. „connect" statt „require" — an EINEM
     * Gateway haengen mehrere Kacheln.
     */
    public function GetCompatibleParents(): string
    {
        return json_encode(['type' => 'connect', 'moduleIDs' => [self::GATEWAY_MODULE_GUID]]);
    }

    public function Create(): void
    {
        parent::Create();
        // Pflicht, damit Symcon die HTML-Kachel aus GetVisualizationTile() rendert.
        $this->SetVisualizationType(1);
        /* Wer in dieser Kachel schreibt. Steht in den Notizen als Urheber und
           waehlt beim Anlegen den Mitglieder-Ordner vor — das Pendant zu
           „Wer bist du?" in der App, die es in der Visu nicht fragen kann. */
        $this->RegisterPropertyString('DefaultUserID', '');
        /* Briefkasten fuer den synchronen Rueckruf des Gateways: es pusht sein
           AiResult im selben Aufruf, aber auf einem anderen Objekt — siehe
           NotizenRelay(). */
        $this->RegisterAttributeString('AiSeenTxn', '');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }
        // Offene Kacheln bekommen den neuen Zustand (etwa nach dem Wechsel des
        // Vorgabe-Mitglieds), ohne dass jemand die Seite neu laedt.
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
            case 'GetState':
                $this->PushState();
                return;
            /* Die Web-App fragt beim Wiederverbinden nach Revisionen ihrer
               Listen. Hier gibt es keine — der Zustand ist die Antwort. */
            case 'CheckRevisions':
                $this->PushState();
                return;
            case 'AiCall':
                $this->NotizenRelay((string)$Value);
                return;
            case 'AiResult':
                $this->NotizenAntwort((string)$Value);
                return;
            /* Die Kachel meldet die Farben der Visu unaufgefordert. Sie werden
               ANGENOMMEN und verworfen: ausgeliefert werden sie vom Gateway
               ohnehin nur aus der Web-App-Kachel (ApiRouter), und dieselbe Visu
               liefert dieselben Farben. Der Zweig muss trotzdem hier stehen —
               sonst wirft RequestAction „Ungültiger Ident" bei jedem Öffnen. */
            case 'ReportVisuTheme':
                return;
            /* Aufrufe an eine Listen-Instanz. In dieser Kachel gibt es keine;
               still schlucken statt zu werfen — die Web-App ruft es beim
               Aufraeumen auch dann, wenn nichts offen ist. */
            case 'Call':
                return;
        }
        throw new Exception($this->Translate('Invalid Ident'));
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
        return $html . '<script>handleMessage(' . $this->Zustand() . ');</script>';
    }

    /**
     * Der Zustand, den die Web-App erwartet — nur eben ohne Listen.
     *
     * `gatewayAvailable` ist PFLICHT: visibleTabs() blendet den Notizbereich
     * ohne Gateway aus, und ohne sichtbaren Bereich zeigte die Kachel den
     * Rueckfall (die leere Uebersicht).
     */
    private function Zustand(): string
    {
        $gw = $this->GatewayID();
        return (string)json_encode([
            'type'             => 'state',
            'users'            => $this->Mitglieder(),
            'defaultUserID'    => $this->ReadPropertyString('DefaultUserID'),
            'gatewayAvailable' => $gw > 0,
            // Der Zauberstab in der Notizzeile haengt daran.
            'aiEnabled'        => $gw > 0 && (bool)@IPS_GetProperty($gw, 'AiEnabled'),
            'tabs'             => [
                'dashboard' => false,
                'shopping'  => false,
                'todos'     => false,
                'calendar'  => false,
                'notes'     => true,
                'ki'        => false,
            ],
            'hiddenIDs'        => [],
            'instances'        => [],
            'states'           => (object)[],
            'images'           => (object)[],
            'brands'           => (object)[],
            'shoppingExtras'   => (object)[],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function PushState(): void
    {
        $this->UpdateVisualizationValue($this->Zustand());
    }

    private function Push(array $payload): void
    {
        $this->UpdateVisualizationValue((string)json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    // ────────────────────────────── Das Relay ──────────────────────────────

    /**
     * Ein Aufruf der Kachel ans Gateway. Nutzlast und Pfad bleiben unberuehrt,
     * die Kennung des Aufrufs (txn) reist mit — die Web-App wartet darauf.
     */
    private function NotizenRelay(string $json): void
    {
        $req = json_decode($json, true);
        if (!is_array($req)) {
            return;
        }
        $txn = (string)($req['txn'] ?? '');
        $gw  = $this->GatewayID();
        /* Bereitschaft pruefen: bei einer noch nicht fertigen Instanz gibt
           IPS_RequestAction nur eine PHP-Warnung aus (kein Throwable) — es kaeme
           also nie eine Antwort, und das Versprechen in der Kachel wartete bis
           zum Zeitablauf. */
        if ($gw > 0 && IPS_GetKernelRunlevel() === KR_READY) {
            @$this->WriteAttributeString('AiSeenTxn', '');
            try {
                IPS_RequestAction($gw, 'AiTileRequest', (string)json_encode([
                    'path'    => (string)($req['path'] ?? ''),
                    'payload' => $req['payload'] ?? [],
                    'txn'     => $txn,
                    /* Das Gateway prueft den Aufrufer an seiner GUID-Weissliste
                       (IsSymDoWebAppInstance) und ruft danach unser AiResult. */
                    'sdwa'    => $this->InstanceID,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                // Das Relay ist synchron: steht unsere Kennung im Briefkasten,
                // ist die Antwort schon bei der Kachel.
                if ($txn !== '' && (string)@$this->ReadAttributeString('AiSeenTxn') === $txn) {
                    return;
                }
            } catch (Throwable $e) {
                // fällt unten in die Fehlerantwort
            }
        }
        $this->Push(['type' => 'aiResult', 'txn' => $txn, 'status' => 200, 'json' => [
            'ok'    => false,
            'error' => ['code' => 'ai_unavailable', 'message' => $this->Translate('Could not reach the gateway.')],
        ]]);
    }

    /** Rueckkanal des Gateways: Briefkasten fuellen und zur Kachel pushen. */
    private function NotizenAntwort(string $json): void
    {
        $r = json_decode($json, true);
        if (!is_array($r)) {
            return;
        }
        @$this->WriteAttributeString('AiSeenTxn', (string)($r['txn'] ?? ''));
        $this->Push([
            'type'   => 'aiResult',
            'txn'    => (string)($r['txn'] ?? ''),
            'status' => (int)($r['status'] ?? 200),
            'json'   => $r['json'] ?? null,
        ]);
    }

    // ─────────────────────────────── Kleinkram ───────────────────────────────

    /**
     * Das zustaendige Gateway: die verbundene Eltern-Instanz, sonst die
     * niedrigste. Die App-Haelfte des Gateways gibt es nur einmal (ihre
     * Hook-Pfade sind fest) — fragte die Kachel eine andere, zeigte sie andere
     * Mitglieder und andere Notizen als die App.
     */
    private function GatewayID(): int
    {
        $eltern = (int)(IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        if ($eltern > 0 && IPS_InstanceExists($eltern)) {
            return $eltern;
        }
        $ids = @IPS_GetInstanceListByModuleID(self::GATEWAY_MODULE_GUID);
        if (!is_array($ids) || $ids === []) {
            return 0;
        }
        sort($ids);
        return (int)$ids[0];
    }

    /** @return list<array{id:string,name:string,avatar:string}> */
    private function Mitglieder(): array
    {
        $gw = $this->GatewayID();
        if ($gw <= 0 || !function_exists('TGW_GetUsersForTile')) {
            return [];
        }
        $liste = json_decode((string)@TGW_GetUsersForTile($gw), true);
        return is_array($liste) ? $liste : [];
    }

    // ─────────────────────────────── Formular ───────────────────────────────

    public function GetConfigurationForm(): string
    {
        $gw = $this->GatewayID();
        $stand = $gw > 0
            ? sprintf($this->Translate('Gateway: %1$s (#%2$d)'), IPS_GetName($gw), $gw)
            : $this->Translate('No SymDo Gateway found — the notes live there. Create one first.');

        $mitglieder = [['caption' => $this->Translate('— ask nobody —'), 'value' => '']];
        foreach ($this->Mitglieder() as $u) {
            $name = trim((string)($u['name'] ?? ''));
            if ($name !== '') {
                $mitglieder[] = ['caption' => $name, 'value' => (string)($u['id'] ?? '')];
            }
        }

        return (string)json_encode([
            'elements' => [
                ['type' => 'Label', 'caption' => $this->Translate('Shows the notes of the SymDo app as a tile — the same notes, the same view. There is nothing to set up: the notes come from the SymDo Gateway.')],
                ['type' => 'Label', 'caption' => $stand],
                ['type' => 'Select', 'name' => 'DefaultUserID', 'width' => '260px',
                 'caption' => $this->Translate('Write as'), 'options' => $mitglieder],
                ['type' => 'Label', 'caption' => $this->Translate('A note written in this tile is filed under this member — a visualization cannot ask who is standing in front of it.')],
            ],
            'actions' => [
                ['type' => 'Button', 'caption' => $this->Translate('Refresh tile'),
                 'onClick' => 'IPS_RequestAction($id, "GetState", 0);'],
            ],
            'status' => [
                ['code' => 101, 'icon' => 'inactive', 'caption' => $this->Translate('Creating')],
                ['code' => 102, 'icon' => 'active',   'caption' => $this->Translate('Active')],
                ['code' => 104, 'icon' => 'inactive', 'caption' => $this->Translate('Inactive')],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}

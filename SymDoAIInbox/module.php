<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/KachelStand.php';
require_once __DIR__ . '/../libs/KachelApp.php';

/**
 * SymDoAIInbox — der KI-Eingang der SymDo-App als eigene Kachel.
 *
 * KEINE eigene Codebase: module.html ist die Web-App, wortgleich uebernommen
 * (tools/uebernahme-webapp.py), samt locale.json. Wer am KI-Eingang etwas
 * aendern will, aendert ihn in SymDoWebApp/module.html und uebernimmt neu.
 *
 * Die Vorschlaege bleiben im Gateway (MailProposals) — Mailabruf, Mailgun,
 * Tagesdeckel und Originale haengen dort. Die Kachel zeigt sie nur:
 *
 *  - der Zustand nennt NUR den KI-Bereich (tabs), die Leiste faellt weg,
 *  - Vorschlaege, Termine, Notizen und Hausaufgaben reisen ueber das
 *    AiCall-Relay des Gateways (aiPost('/mail/proposals', …) usw.),
 *  - EINE Ausnahme gegenueber der Notiz-Kachel: eine als Aufgabe uebernommene
 *    Zeile geht an eine ToDo-Liste (sdCall(ziel, 'AddItem', …)). Dafuer stehen
 *    die ToDo-Listen im Zustand, und der Call-Zweig reicht genau DIESE eine
 *    Aktion an genau diese Listen weiter — sonst nichts.
 */
class SymDoAIInbox extends IPSModuleStrict
{
    private const GATEWAY_MODULE_GUID = '{E677FE7B-28C9-4124-8B58-8A1FE2657E8D}';
    private const TODO_MODULE_GUID    = '{E0E38D9B-31BC-4F5E-A6CA-91A2A60C7C46}';

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
        // Ab Symcon 9.1 auch in der geöffneten (maximierten) Kachel; ältere
        // Versionen kennen die Konstante nicht und bleiben bei der normalen Kachel.
        $this->SetVisualizationType(defined('INSTANCE_VISUALIZATION_TYPE_HTML_FULLSCREEN') ? INSTANCE_VISUALIZATION_TYPE_HTML_FULLSCREEN : 1);
        /* Wer in dieser Kachel uebernimmt. Steht als Urheber an Aufgabe und Notiz
           — eine Visualisierung kann nicht fragen, wer davor steht. */
        $this->RegisterPropertyString('DefaultUserID', '');
        /* Ziel fuer uebernommene Aufgaben. 0 = alle ToDo-Listen zur Wahl, sonst
           NUR diese eine (die Kachel teilt ihre gemerkte Auswahl mit der
           Web-App-Kachel, eine „Vorwahl" allein hielte also nicht). */
        $this->RegisterPropertyInteger('TodoListID', 0);
        /* Briefkasten fuer den synchronen Rueckruf des Gateways: es pusht sein
           AiResult im selben Aufruf, aber auf einem anderen Objekt — siehe
           Relay(). */
        $this->RegisterAttributeString('AiSeenTxn', '');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }
        // Offene Kacheln bekommen den neuen Zustand, ohne dass jemand neu laedt.
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
                $this->PushState($Value);
                return;
            /* Die Web-App fragt beim Wiederverbinden nach Revisionen ihrer
               Listen. Die Kachel zeigt keine — der Zustand ist die Antwort. */
            case 'CheckRevisions':
                $this->PushState($Value);
                return;
            case 'AiCall':
                $this->Relay((string)$Value);
                return;
            case 'AiResult':
                $this->Antwort((string)$Value);
                return;
            case 'Call':
                $this->ListenAufruf((string)$Value);
                return;
            /* Die Kachel meldet die Farben der Visu unaufgefordert. Angenommen
               und verworfen — sonst wirft RequestAction bei jedem Oeffnen. */
            case 'ReportVisuTheme':
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
        // Das App-Skript kommt als eine gecachte Datei vom Gateway, wenn es gleich ist (libs/KachelApp.php).
        $html = KachelApp::Auslagern($html, KachelApp::GatewaySkript(dirname(__DIR__)));
        return $html . '<script>handleMessage(' . $this->Zustand() . ');</script>';
    }

    /**
     * Der Zustand, den die Web-App erwartet.
     *
     * `gatewayAvailable` ist PFLICHT: visibleTabs() blendet den KI-Bereich ohne
     * Gateway aus (mailAiAvailable), und ohne sichtbaren Bereich zeigte die
     * Kachel den Rueckfall (die leere Uebersicht).
     *
     * `instances` traegt nur die Ziel-Listen fuer „Als Aufgabe" — ohne Zustand
     * (`states` bleibt leer): selectedTodoID() und der Aufgaben-Dialog brauchen
     * nur Kennung und Namen.
     */
    private function Zustand(): string
    {
        $gw = $this->GatewayID();
        $instances = [];
        foreach ($this->ZielListen() as $id) {
            $instances[] = ['id' => $id, 'kind' => 'todo', 'name' => IPS_GetName($id), 'hidden' => false];
        }
        // Mit Pruefwert: die Kachel schickt ihn beim Abgleich zurueck (KachelStand).
        return (string)json_encode(KachelStand::MitPruefwert([
            'type'             => 'state',
            'users'            => $this->Mitglieder(),
            'defaultUserID'    => $this->ReadPropertyString('DefaultUserID'),
            'gatewayAvailable' => $gw > 0,
            // Zauberstab (Foto/Datei/Text analysieren) und Eingabeleiste haengen daran.
            'aiEnabled'        => $gw > 0 && (bool)@IPS_GetProperty($gw, 'AiEnabled'),
            'tabs'             => [
                'dashboard' => false,
                'shopping'  => false,
                'todos'     => false,
                'calendar'  => false,
                'notes'     => false,
                'ki'        => true,
            ],
            'hiddenIDs'        => [],
            'instances'        => $instances,
            'states'           => (object)[],
            'images'           => (object)[],
            'brands'           => (object)[],
            'shoppingExtras'   => (object)[],
        /* JSON_HEX_TAG ist PFLICHT: der Zustand wird in einen <script>-Block
           gehaengt (GetVisualizationTile); ein „</script>" in einem Listen- oder
           Mitgliedsnamen beendete ihn sonst. */
        ]), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** @param mixed $kachel Was die Kachel mitschickt (`{"hash": …}`); ohne = immer senden. */
    private function PushState(mixed $kachel = null): void
    {
        $zustand = $this->Zustand();
        $pruef = json_decode($zustand, true);
        if (KachelStand::Kennt(is_array($pruef) ? (string)($pruef['stateHash'] ?? '') : '', $kachel)) {
            return;
        }
        $this->UpdateVisualizationValue($zustand);
    }

    private function Push(array $payload): void
    {
        /* JSON_INVALID_UTF8_SUBSTITUTE ist Pflicht: ein kaputtes Byte aus einer
           Mail liesse json_encode sonst false liefern, und das Versprechen in der
           Kachel liefe in den Zeitablauf. */
        $this->UpdateVisualizationValue((string)json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        ));
    }

    // ─────────────────────────── Aufgabe uebernehmen ───────────────────────────

    /**
     * Die ToDo-Listen, in die diese Kachel schreiben darf: die eingestellte —
     * wenn es sie noch gibt —, sonst alle.
     *
     * @return list<int>
     */
    private function ZielListen(): array
    {
        $alle = @IPS_GetInstanceListByModuleID(self::TODO_MODULE_GUID);
        $alle = is_array($alle) ? array_map('intval', $alle) : [];
        sort($alle);
        $fest = $this->ReadPropertyInteger('TodoListID');
        if ($fest > 0) {
            return in_array($fest, $alle, true) ? [$fest] : [];
        }
        return $alle;
    }

    /**
     * Call der Web-App an eine Listen-Instanz. Weitergereicht wird NUR
     * `AddItem` an eine der Ziel-Listen; alles andere ist hier kein Weg — die
     * Kachel zeigt keine Listen, also bearbeitet, hakt oder loescht sie auch
     * nichts. Die Antwort kommt als instanceState-Push (ohne Zustand: die Kachel
     * zeigt die Liste nicht), damit ein Fehlschlag als Meldung erscheint.
     */
    private function ListenAufruf(string $json): void
    {
        $data = json_decode($json, true);
        $data = is_array($data) ? $data : [];
        $instanceID = (int)($data['instanceID'] ?? 0);
        $action     = (string)($data['action'] ?? '');
        $txn        = (string)($data['txn'] ?? '');

        $antwort = ['ok' => false, 'error' => 'invalid_call'];
        if ($action === 'AddItem' && in_array($instanceID, $this->ZielListen(), true)
            && function_exists('TDL_AppCall')) {
            $payload = $data['payload'] ?? [];
            $payloadJson = is_string($payload)
                ? $payload
                : (string)json_encode($payload,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            try {
                $r = json_decode((string)TDL_AppCall($instanceID, 'AddItem', $payloadJson), true);
                $antwort = is_array($r)
                    ? ['ok' => (bool)($r['ok'] ?? false), 'error' => $r['error'] ?? null]
                    : ['ok' => false, 'error' => 'invalid_response'];
            } catch (Throwable $e) {
                $antwort = ['ok' => false, 'error' => $e->getMessage()];
            }
        }
        $this->Push([
            'type'       => 'instanceState',
            'instanceID' => $instanceID,
            'kind'       => 'todo',
            'revision'   => 0,
            'state'      => null,
            'ok'         => $antwort['ok'],
            'error'      => $antwort['error'],
            'txn'        => $txn,
        ]);
    }

    // ────────────────────────────── Das Relay ──────────────────────────────

    /**
     * Ein Aufruf der Kachel ans Gateway. Nutzlast und Pfad bleiben unberuehrt,
     * die Kennung des Aufrufs (txn) reist mit — die Web-App wartet darauf.
     */
    private function Relay(string $json): void
    {
        $req = json_decode($json, true);
        if (!is_array($req)) {
            return;
        }
        $txn = (string)($req['txn'] ?? '');
        $gw  = $this->GatewayID();
        /* Bereitschaft pruefen: bei einer noch nicht fertigen Instanz gibt
           IPS_RequestAction nur eine Warnung aus — es kaeme nie eine Antwort. */
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
    private function Antwort(string $json): void
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
     * Das zustaendige Gateway: das mit der NIEDRIGSTEN Instanz-ID — dort liegt
     * die App-Haelfte mit dem KI-Eingang (siehe SymDoNotes::GatewayID).
     */
    private function GatewayID(): int
    {
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
            : $this->Translate('No SymDo Gateway found — the AI inbox lives there. Create one first.');

        $mitglieder = [['caption' => $this->Translate('— ask nobody —'), 'value' => '']];
        foreach ($this->Mitglieder() as $u) {
            $name = trim((string)($u['name'] ?? ''));
            if ($name !== '') {
                $mitglieder[] = ['caption' => $name, 'value' => (string)($u['id'] ?? '')];
            }
        }

        $elemente = [
            ['type' => 'Label', 'caption' => $this->Translate('Shows the AI inbox of the SymDo app as a tile — the same suggestions, the same view. Mail intake and AI are set up in the SymDo Gateway.')],
            ['type' => 'Label', 'caption' => $stand],
        ];
        if ($gw > 0 && !(bool)@IPS_GetProperty($gw, 'AiEnabled')) {
            $elemente[] = ['type' => 'Label', 'bold' => true,
                           'caption' => $this->Translate('The AI is switched off in the gateway — the inbox stays empty until it is switched on there.')];
        }
        $elemente[] = ['type' => 'Select', 'name' => 'DefaultUserID', 'width' => '260px',
                       'caption' => $this->Translate('Adopt as'), 'options' => $mitglieder];
        $elemente[] = ['type' => 'Label', 'caption' => $this->Translate('Tasks and notes adopted in this tile are filed under this member — a visualization cannot ask who is standing in front of it.')];
        $elemente[] = ['type' => 'SelectInstance', 'name' => 'TodoListID', 'width' => '260px',
                       'validModules' => [self::TODO_MODULE_GUID],
                       'caption' => $this->Translate('Task list')];
        $elemente[] = ['type' => 'Label', 'caption' => $this->Translate('Suggestions adopted as a task go into this list. Leave it empty to choose from all lists.')];

        return (string)json_encode([
            'elements' => $elemente,
            'actions'  => [
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

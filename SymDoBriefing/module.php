<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/KachelStand.php';
require_once __DIR__ . '/../libs/KachelApp.php';

/**
 * SymDoBriefing — das Tagesbriefing der SymDo-App als eigene Kachel.
 *
 * KEINE eigene Codebase: module.html ist die Web-App, wortgleich uebernommen
 * (tools/uebernahme-webapp.py), samt locale.json. Der Zustand nennt nur die
 * Uebersicht und setzt `nurBriefing` — dann zeichnet die Web-App dort allein die
 * Briefing-Karte (briefingAllein). Text, Tonschnipsel und Vorlesen kommen ueber
 * das AiCall-Relay des Gateways (/briefing, /tts, /ttsclip), genau wie in der
 * Web-App-Kachel.
 *
 * Abspielen aus Skripten und Ereignissen: SDBR_PlayBriefing(<InstanzID>).
 */
class SymDoBriefing extends IPSModuleStrict
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
        // Ab Symcon 9.1 auch in der geöffneten (maximierten) Kachel; ältere
        // Versionen kennen die Konstante nicht und bleiben bei der normalen Kachel.
        $this->SetVisualizationType(defined('INSTANCE_VISUALIZATION_TYPE_HTML_FULLSCREEN') ? INSTANCE_VISUALIZATION_TYPE_HTML_FULLSCREEN : 1);
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
            // Wie SDWA: auch ohne Kernel-Neustart nutzbar (IPS_RequestAction($id, 'PlayBriefing', 0)).
            case 'PlayBriefing':
                $this->PlayBriefing();
                return;
            /* Aufrufe an eine Listen-Instanz gibt es hier nicht — still schlucken,
               die Web-App ruft es beim Aufraeumen auch dann, wenn nichts offen ist. */
            case 'Call':
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
     * `gatewayAvailable` ist PFLICHT: ohne Gateway holt loadBriefing() nichts.
     * `nurBriefing` macht aus der Uebersicht die Briefing-Karte. Die Mitglieder
     * stehen drin, weil die Ueberschrift davon abhaengt („Euer Tag").
     */
    private function Zustand(): string
    {
        $gw = $this->GatewayID();
        // Mit Pruefwert: die Kachel schickt ihn beim Abgleich zurueck (KachelStand).
        return (string)json_encode(KachelStand::MitPruefwert([
            'type'             => 'state',
            'users'            => $this->Mitglieder(),
            'defaultUserID'    => '',
            'gatewayAvailable' => $gw > 0,
            'aiEnabled'        => false,
            'nurBriefing'      => true,
            'tabs'             => [
                'dashboard' => true,
                'shopping'  => false,
                'todos'     => false,
                'calendar'  => false,
                'notes'     => false,
                'ki'        => false,
            ],
            'hiddenIDs'        => [],
            'instances'        => [],
            'states'           => (object)[],
            'images'           => (object)[],
            'brands'           => (object)[],
            'shoppingExtras'   => (object)[],
        /* JSON_HEX_TAG ist PFLICHT: der Zustand wird in einen <script>-Block
           gehaengt (GetVisualizationTile); ein „</script>" in einem
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

    // ─────────────────────────────── Abspielen ───────────────────────────────

    /**
     * Spielt das Briefing in DIESER Kachel ab — fuer Skripte und Ereignisse:
     * `SDBR_PlayBriefing(<InstanzID>)`.
     *
     * Wie SDWA_PlayBriefing: erreicht nur die Betrachter dieser Kachel, verfaellt,
     * wenn sie niemand offen hat, und der Browser darf Ton ohne Nutzergeste
     * abweisen (die Kachel zeigt dann einen Hinweis).
     *
     * @return bool true = Nachricht ist hinausgegangen (kein Zustellnachweis).
     */
    public function PlayBriefing(): bool
    {
        $this->Push(['type' => 'briefingPlay']);
        return true;
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
     * die App-Haelfte mit dem Briefing (siehe SymDoNotes::GatewayID).
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
            : $this->Translate('No SymDo Gateway found — the briefing is written there. Create one first.');

        return (string)json_encode([
            'elements' => [
                ['type' => 'Label', 'caption' => $this->Translate('Shows the daily briefing of the SymDo app as a tile, with playback. Content, time, tone and voice are set up in the SymDo Gateway.')],
                ['type' => 'Label', 'caption' => $stand],
                ['type' => 'Label', 'caption' => sprintf($this->Translate('Play from a script or event: SDBR_PlayBriefing(%d);'), $this->InstanceID)],
            ],
            'actions'  => [
                ['type' => 'Button', 'caption' => $this->Translate('Play briefing'),
                 'onClick' => 'IPS_RequestAction($id, "PlayBriefing", 0);'],
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

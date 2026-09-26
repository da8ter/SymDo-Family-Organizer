<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/KachelStand.php';

/**
 * SymDoEdumaps — die Klassenseiten der Schule als eigene Kachel.
 *
 * Zwei Quellen speisen sie: Edumaps und LOGINEO NRW LMS (Moodle). Der
 * Ordnername des Moduls und die Klasse heissen weiter „Edumaps" — Symcon
 * instanziiert die Klasse `name` minus Leerzeichen, daran darf nichts wackeln.
 * Der ANZEIGENAME nennt nur die Sache: „SymDo - Klassenseiten".
 *
 * KEINE eigene Codebase: module.html ist die Web-App, wortgleich uebernommen
 * (tools/uebernahme-webapp.py), samt locale.json. Wer an der Kartenansicht
 * etwas aendern will, aendert sie in SymDoWebApp/module.html und uebernimmt neu
 * — von Hand wird hier nichts nachgepflegt, sonst laufen die beiden
 * Oberflaechen auseinander. Dasselbe Verfahren wie bei SymDoNotes.
 *
 * Der Unterschied zur Notizen-Kachel ist EINE Zeile im Zustand: `tabs` nennt
 * nur `edumaps`. Bei einem einzigen Bereich laesst die Web-App die Leiste
 * ohnehin weg (html.no-tabs).
 *
 * Was diese Kachel NICHT hat: einen Urheber. In den Notizen braucht es ihn,
 * weil dort geschrieben wird; hier ist der Bestand ein Spiegel der Klassenseite,
 * und niemand legt etwas an.
 *
 * Die Karten liegen im Gateway und reisen ueber dessen AiCall-Relay:
 * aiPost('/edumaps', …) → RequestAction('AiCall') →
 * IPS_RequestAction($gateway, 'AiTileRequest', …) → EduHandleAction(). Einen
 * Token hat die Kachel nicht, dieser Weg ist der einzige — und dafuer MUSS die
 * GUID dieses Moduls in der Weissliste des Gateways stehen
 * (AppCore::IsSymDoWebAppInstance), sonst wartet die Kachel auf eine Antwort,
 * die nie kommt.
 */
class SymDoEdumaps extends IPSModuleStrict
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
        /* Briefkasten fuer den synchronen Rueckruf des Gateways: es pusht sein
           AiResult im selben Aufruf, aber auf einem anderen Objekt — siehe
           KartenRelay(). */
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
                $this->PushState($Value);
                return;
            /* Die Web-App fragt beim Wiederverbinden nach Revisionen ihrer
               Listen. Hier gibt es keine — der Zustand ist die Antwort. */
            case 'CheckRevisions':
                // Nur senden, wenn die Kachel den Stand nicht schon hat.
                $this->PushState($Value);
                return;
            case 'AiCall':
                $this->KartenRelay((string)$Value);
                return;
            case 'AiResult':
                $this->KartenAntwort((string)$Value);
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
        // Mit Pruefwert: die Kachel schickt ihn beim Abgleich zurueck (KachelStand).
        return (string)json_encode(KachelStand::MitPruefwert([
            'type'             => 'state',
            /* Die Mitglieder MUESSEN mit: die linke Spalte zeichnet je Kind
               einen Ordner mit Foto. */
            'users'            => $this->Mitglieder(),
            'defaultUserID'    => '',
            /* PFLICHT: edumapsMoeglich() haengt daran. Ohne das Feld waere der
               Bereich unsichtbar, und die Kachel fiele auf ein leeres Dashboard
               zurueck. */
            'gatewayAvailable' => $gw > 0,
            /* Kein Zauberstab in dieser Kachel — es gibt keine Eingabezeile,
               also auch nichts zu erkennen. */
            'aiEnabled'        => false,
            'tabs'             => [
                'dashboard' => false,
                'shopping'  => false,
                'todos'     => false,
                'calendar'  => false,
                'notes'     => false,
                'edumaps'   => true,
                'ki'        => false,
            ],
            'hiddenIDs'        => [],
            'instances'        => [],
            'states'           => (object)[],
            'images'           => (object)[],
            'brands'           => (object)[],
            'shoppingExtras'   => (object)[],
        /* JSON_HEX_TAG ist PFLICHT: der Zustand wird in einen <script>-Block
           gehaengt (GetVisualizationTile), und mit JSON_UNESCAPED_SLASHES bliebe
           ein „</script>" in einem Mitgliedsnamen woertlich stehen — der Block
           endete dort. */
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
        /* JSON_INVALID_UTF8_SUBSTITUTE ist Pflicht: ein einziges kaputtes Byte —
           aus einem Dateinamen, einer KI-Antwort — laesst json_encode sonst false
           liefern, und die Kachel bekaeme eine leere Nachricht statt des
           Ergebnisses (das Versprechen dort liefe in den Zeitablauf). */
        $this->UpdateVisualizationValue((string)json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        ));
    }

    // ────────────────────────────── Das Relay ──────────────────────────────

    /**
     * Ein Aufruf der Kachel ans Gateway. Nutzlast und Pfad bleiben unberuehrt,
     * die Kennung des Aufrufs (txn) reist mit — die Web-App wartet darauf.
     */
    private function KartenRelay(string $json): void
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
    private function KartenAntwort(string $json): void
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
     * Das zustaendige Gateway: das mit der NIEDRIGSTEN Instanz-ID. Alles, was
     * dieses Modul tut — Klassenseiten, Mitglieder —, liegt in der App-Haelfte des
     * Gateways, und die gibt es nur einmal (ihre Hook-Pfade sind fest, siehe
     * SymDoGateway::OwnsAppApi). Das eigene Eltern-Gateway ist BEWUSST nicht
     * bevorzugt: wer zwei Gateways fuehrt, kann die Kachel an das zweite gehaengt
     * haben — sie zeigte dann andere Mitglieder und andere Karten als die App.
     * Genauso halten es SymDoWebApp und ToDoList (GetAppGatewayID).
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
            : $this->Translate('No SymDo Gateway found — the class pages live there. Create one first.');

        return (string)json_encode([
            'elements' => [
                ['type' => 'Label', 'caption' => $this->Translate('Shows the class pages of the school as a tile — the same cards, the same view as in the app. There is nothing to set up here: the pages are entered in the SymDo Gateway.')],
                ['type' => 'Label', 'caption' => $stand],
                ['type' => 'Label', 'caption' => $this->Translate('Read only: the cards are a mirror of the class page. Renaming a page and deleting it (which blocks it) work; writing does not.')],
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

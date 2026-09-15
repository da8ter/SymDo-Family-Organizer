<?php

declare(strict_types=1);

/**
 * SymDo Voice — Zweiwege-Sprachdialog mit der KI.
 *
 * Die Kachel spricht per WebRTC direkt mit dem Anbieter; über dieses Modul
 * reisen nur Marke, Herzschlag und Werkzeugaufrufe (Relay ans Gateway, Muster
 * SymDoWebApp::HandleAiCall). Der Benutzer und die Standard-Listen werden HIER
 * erzwungen — was der Browser behauptet, zählt nicht.
 */
class SymDoVoice extends IPSModuleStrict
{
    private const GATEWAY_GUID  = '{E677FE7B-28C9-4124-8B58-8A1FE2657E8D}';
    private const SHOPPING_GUID = '{A5D3F2E1-7B4C-4E8A-9D6F-1C2B3A4E5F6D}';
    private const TODO_GUID     = '{E0E38D9B-31BC-4F5E-A6CA-91A2A60C7C46}';

    /** Höchstens so viele Proben-Berichte werden aufgehoben (je Umgebung einer). */
    private const PROBE_MAX = 8;

    public function Create(): void
    {
        parent::Create();

        // Pflicht, damit Symcon die HTML-Kachel aus GetVisualizationTile() rendert
        $this->SetVisualizationType(1);

        // Der feste Benutzer dieser Kachel: „meine Aufgaben" und neue Einträge
        // gehören ihm (Entscheidung vom 01.09.2026).
        $this->RegisterPropertyString('UserID', '');
        // „die Liste" ist im Haushalt nicht eindeutig (5 ToDo-, 2 Einkaufslisten
        // gemessen) — deshalb je Kachel eine Vorgabe.
        $this->RegisterPropertyInteger('DefaultShoppingID', 0);
        $this->RegisterPropertyInteger('DefaultTodoID', 0);
        // Darstellung der Kachelmitte: Gesprächsverlauf (Vorgabe) oder die
        // tonreagierende Blase. Bedienung und Werkzeuge sind in beiden gleich.
        $this->RegisterPropertyString('Darstellung', 'gespraech');

        // Briefkasten für den synchronen Relay-Rückruf: er läuft auf einem
        // ANDEREN PHP-Objekt dieser Instanz — ein Objektfeld überlebt die
        // Grenze nicht, ein Attribut schon (Muster SymDoWebApp).
        $this->RegisterAttributeString('SeenTxn', '');

        // Berichte der Machbarkeitsprobe (Etappe 0), je Umgebung der jüngste.
        $this->RegisterAttributeString('ProbeResult', '[]');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }
        $this->ElternanschlussLoesen();

        $this->SetStatus(IS_ACTIVE);
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
            case 'VoiceCall':
                $this->HandleVoiceCall((string)$Value);
                return;

            case 'AiResult':
                // Rückkanal des Gateways: Briefkasten füllen und zur Kachel pushen.
                // Der Ident heißt AiResult, weil der AiTileRequest-Zweig des
                // Gateways ihn fest so ruft — für alle Kacheln gleich.
                $daten = json_decode((string)$Value, true);
                if (!is_array($daten)) {
                    return;
                }
                $this->WriteAttributeString('SeenTxn', (string)($daten['txn'] ?? ''));
                $this->Push([
                    'type' => 'voiceResult',
                    'txn'  => (string)($daten['txn'] ?? ''),
                    'json' => $daten['json'] ?? null,
                ]);
                return;

            case 'ProbeReport':
                $this->ProbeAblegen((string)$Value);
                return;

            case 'ProbeReset':
                $this->WriteAttributeString('ProbeResult', '[]');
                $this->ReloadForm();
                return;

            case 'GetState':
                $this->Push($this->PayloadBauen());
                return;
        }
        parent::RequestAction($Ident, $Value);
    }

    /** Diagnose-Getter für Skripte und Prüfstände (SDVC_GetState). */
    public function GetState(): string
    {
        return (string)json_encode($this->PayloadBauen(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** Die gesammelten Proben-Berichte, für Skripte (SDVC_GetProbeResults). */
    public function GetProbeResults(): string
    {
        return $this->ReadAttributeString('ProbeResult');
    }

    /** Der jüngste beantwortete txn — für Prüfstände (SDVC_LastSeenTxn). */
    public function LastSeenTxn(): string
    {
        return $this->ReadAttributeString('SeenTxn');
    }

    /** Not-Aus aus Symcon heraus: beendet alle Gespräche am Gateway (SDVC_Hangup). */
    public function Hangup(): bool
    {
        $gw = $this->GatewayID();
        if ($gw <= 0) {
            return false;
        }
        @IPS_RequestAction($gw, 'VoiceHangupAll', '');
        return true;
    }

    public function GetVisualizationTile(): string
    {
        $html = @file_get_contents(__DIR__ . '/module.html');
        if (!is_string($html)) {
            $this->LogMessage('GetVisualizationTile: module.html nicht lesbar', KL_WARNING);
            return '';
        }
        // Der Gesprächskern liegt beim Gateway (eine Quelle für Kachel und
        // Web-App) und wird VOR das Dokument gehängt.
        $kern = @file_get_contents(__DIR__ . '/../SymDoGateway/libs/voice-core.js');
        $kopf = is_string($kern) ? ('<script>' . $kern . '</script>') : '';
        // Die Blase bringt Markup, Stil und Bewegung selbst mit — dieselbe Quelle
        // wie in der Web-App, damit nichts von Hand nachgezogen werden muss.
        $blase = @file_get_contents(__DIR__ . '/../SymDoGateway/libs/voice-blob.js');
        if (is_string($blase)) {
            $kopf .= '<script>' . $blase . '</script>';
        }
        /* Der Weckwort-Lauscher, wenn das Haus ihn erlaubt hat. Ob das GERAET
           ihn kann, entscheidet er selbst und sagt es. */
        if ($this->FreisprechenErlaubt()) {
            $wake = @file_get_contents(__DIR__ . '/../SymDoGateway/libs/voice-wake.js');
            if (is_string($wake)) {
                $kopf .= '<script>' . $wake . '</script>';
            }
        }
        /* JSON_HEX_TAG ist hier PFLICHT: die Nutzlast steht in einem <script>-Block,
           und mit JSON_UNESCAPED_SLASHES bliebe ein „</script>" in einem Namen oder
           Titel woertlich stehen — der Block endete dort, handleMessage liefe nie, und
           der Rest landete als HTML in der Visu. */
        $payload = json_encode($this->PayloadBauen(),
            JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        return $kopf . $html . '<script>handleMessage(' . $payload . ');</script>';
    }

    public function GetConfigurationForm(): string
    {
        $benutzer = [['caption' => $this->Translate('— none —'), 'value' => '']];
        $gw = $this->GatewayID();
        if ($gw > 0 && function_exists('TGW_GetUsers')) {
            foreach ((array)json_decode((string)@TGW_GetUsers($gw), true) as $u) {
                if (is_array($u) && trim((string)($u['name'] ?? '')) !== '') {
                    $benutzer[] = ['caption' => (string)$u['name'], 'value' => (string)($u['id'] ?? '')];
                }
            }
        }

        $elements = [
            ['type' => 'Label', 'caption' => $this->Translate('Voice dialog with the AI: talk, ask, control SymDo. Enable the voice dialog and give the consent in the SymDo Gateway instance (panel "Voice dialog").')],
        ];
        // Neue Felder erst zeigen, wenn ihre Eigenschaft existiert — sonst lässt
        // „Übernehmen" vor dem Kernel-Neustart das ganze Formular scheitern.
        if ($this->PropertyExistiert('UserID')) {
            $elements[] = ['type' => 'Select', 'name' => 'UserID',
                'caption' => $this->Translate('This tile belongs to'), 'options' => $benutzer];
        }
        if ($this->PropertyExistiert('DefaultShoppingID')) {
            $elements[] = ['type' => 'SelectInstance', 'name' => 'DefaultShoppingID', 'width' => '400px',
                'caption' => $this->Translate('Default shopping list'), 'validModules' => [self::SHOPPING_GUID]];
            $elements[] = ['type' => 'SelectInstance', 'name' => 'DefaultTodoID', 'width' => '400px',
                'caption' => $this->Translate('Default task list'), 'validModules' => [self::TODO_GUID]];
        }
        if ($this->PropertyExistiert('Darstellung')) {
            $elements[] = ['type' => 'Select', 'name' => 'Darstellung', 'width' => '400px',
                'caption' => $this->Translate('Tile view'), 'options' => [
                    ['caption' => $this->Translate('Conversation (text)'), 'value' => 'gespraech'],
                    ['caption' => $this->Translate('Animated blob (reacts to the voice)'), 'value' => 'blob'],
                ]];
        }
        if (!$this->PropertyExistiert('DefaultShoppingID')) {
            $elements[] = ['type' => 'Label',
                'caption' => $this->Translate('More settings appear after the next Symcon restart.')];
        }

        // Die Messergebnisse der Machbarkeitsprobe (Etappe 0) bleiben ablesbar.
        $zeilen = [];
        foreach ((array)json_decode((string)@$this->ReadAttributeString('ProbeResult'), true) as $b) {
            if (is_array($b)) {
                $zeilen[] = ['type' => 'Label', 'caption' => $this->ProbeZeile($b)];
            }
        }
        if ($zeilen !== []) {
            $zeilen[] = ['type' => 'Button', 'caption' => $this->Translate('Clear reports'),
                'onClick' => 'IPS_RequestAction($id, "ProbeReset", "");'];
            $elements[] = ['type' => 'ExpansionPanel', 'caption' => $this->Translate('Probe results'),
                'expanded' => false, 'items' => $zeilen];
        }

        return (string)json_encode(['elements' => $elements, 'actions' => [], 'status' => []],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    // ------------------------------------------------------------------
    // Intern
    // ------------------------------------------------------------------

    /**
     * Relay zum Gateway — mit serverseitig erzwungener Identität: userId,
     * Kachel-Kennung und Standard-Listen kommen aus den Eigenschaften dieser
     * Instanz, niemals aus dem Browser.
     */
    private function HandleVoiceCall(string $json): void
    {
        $req = json_decode($json, true);
        if (!is_array($req)) {
            return;
        }
        $txn = (string)($req['txn'] ?? '');
        $payload = is_array($req['payload'] ?? null) ? $req['payload'] : [];
        $payload['userId'] = $this->ReadPropertyString('UserID');
        $payload['tile']   = $this->InstanceID;
        $payload['defaults'] = [
            'shopping' => $this->ReadPropertyInteger('DefaultShoppingID'),
            'todo'     => $this->ReadPropertyInteger('DefaultTodoID'),
        ];

        $gw = $this->GatewayID();
        if ($gw > 0 && $this->InstanzBereit($gw)) {
            $this->WriteAttributeString('SeenTxn', '');
            try {
                IPS_RequestAction($gw, 'AiTileRequest', json_encode([
                    'path'    => '/voice',
                    'payload' => $payload,
                    'txn'     => $txn,
                    'sdwa'    => $this->InstanceID,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                // Das Relay ist synchron, aber auf einem anderen Objekt — der
                // Briefkasten sagt, ob unsere Antwort wirklich ankam.
                if ($txn !== '' && (string)@$this->ReadAttributeString('SeenTxn') === $txn) {
                    return;
                }
            } catch (Throwable $e) {
                // fällt unten in die Fehlerantwort
            }
        }
        $this->Push(['type' => 'voiceResult', 'txn' => $txn, 'json' => [
            'ok' => false, 'error' => ['code' => 'gateway_unavailable'],
            'sag' => $this->Translate('The gateway is not reachable.'),
        ]]);
    }

    /** Das zuständige Gateway: verbundene Eltern-Instanz, sonst die niedrigste ID. */
    private function GatewayID(): int
    {
        $eltern = (int)(@IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        if ($eltern > 0) {
            return $eltern;
        }
        $ids = @IPS_GetInstanceListByModuleID(self::GATEWAY_GUID);
        if (!is_array($ids) || $ids === []) {
            return 0;
        }
        sort($ids);
        return (int)$ids[0];
    }

    /** IPS_RequestAction auf eine unfertige Instanz warnt nur — vorher prüfen. */
    private function InstanzBereit(int $id): bool
    {
        return IPS_GetKernelRunlevel() === KR_READY && @IPS_InstanceExists($id);
    }

    /** Gibt es die Eigenschaft schon? Neue entstehen erst beim nächsten Kernel-Start. */
    private function PropertyExistiert(string $Name): bool
    {
        $config = json_decode((string)@IPS_GetConfiguration($this->InstanceID), true);
        return is_array($config) && array_key_exists($Name, $config);
    }

    private function PayloadBauen(): array
    {
        return [
            'type'  => 'state',
            'stufe' => 'dialog',
            // Vor dem Modul-Reload gibt es die Eigenschaft noch nicht; ein
            // ReadProperty darauf wäre eine PHP-Warnung MITTEN in der Kachel-
            // Antwort und zerlegte das HTML.
            'darstellung' => $this->PropertyExistiert('Darstellung')
                ? $this->ReadPropertyString('Darstellung') : 'gespraech',
            'freisprechen' => $this->FreisprechenErlaubt(),
            'weckwort'     => $this->WeckwortImHaus(),
        ];
    }

    /** Das Weckwort aus der Gateway-Konfiguration — roh, die Regel wendet der Lauscher an. */
    private function WeckwortImHaus(): string
    {
        $gw = $this->GatewayID();
        $cfg = $gw > 0 ? json_decode((string)@IPS_GetConfiguration($gw), true) : null;
        $w = is_array($cfg) && array_key_exists('VoiceWakeWord', $cfg) ? trim((string)$cfg['VoiceWakeWord']) : '';
        return $w !== '' ? $w : 'Hey SymDo';
    }

    /**
     * Steht der Freisprech-SCHALTER am Gateway? Nur danach entscheidet sich, ob
     * der Lauscher überhaupt mitgeliefert wird.
     *
     * Bewusst über die Konfiguration und nicht über eine neue TGW_-Funktion:
     * öffentliche Modulfunktionen entstehen erst beim Kernel-Start, eine neue
     * wäre bis dahin unbekannt. Die EINWILLIGUNG liegt in einem Attribut und
     * ist von außen nicht lesbar — sie wird beim Anschalten über das Relais
     * geprüft (VoiceHandleAction, action „handsfree"), also serverseitig, wo
     * sie hingehört.
     */
    private function FreisprechenErlaubt(): bool
    {
        $gw = $this->GatewayID();
        if ($gw <= 0) {
            return false;
        }
        $cfg = json_decode((string)@IPS_GetConfiguration($gw), true);
        return is_array($cfg) && ($cfg['VoiceHandsFreeAllowed'] ?? false) === true;
    }

    private function Push(array $daten): void
    {
        $this->UpdateVisualizationValue((string)json_encode($daten,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /** Bericht der Machbarkeitsprobe ablegen — je Umgebungskennung nur der jüngste. */
    private function ProbeAblegen(string $json): void
    {
        $neu = json_decode($json, true);
        if (!is_array($neu)) {
            return;
        }
        $agent = (string)($neu['umgebung']['agent'] ?? '');
        $iframe = ($neu['umgebung']['iframe'] ?? false) === true;
        $kennung = substr(md5($agent . '|' . ($iframe ? '1' : '0')), 0, 8);

        $alle = json_decode((string)@$this->ReadAttributeString('ProbeResult'), true);
        $alle = is_array($alle) ? $alle : [];
        $alle = array_values(array_filter($alle,
            static fn($b): bool => is_array($b) && ($b['kennung'] ?? '') !== $kennung));
        $neu['kennung'] = $kennung;
        $neu['at'] = time();
        array_unshift($alle, $neu);
        $alle = array_slice($alle, 0, self::PROBE_MAX);

        $this->WriteAttributeString('ProbeResult',
            (string)json_encode($alle, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->LogMessage('SymDo Voice: Machbarkeitsprobe gemeldet — ' . (string)($neu['fazit'] ?? '?'), KL_NOTIFY);
        @$this->ReloadForm();
    }

    /** Eine Formularzeile je Proben-Bericht — kompakt, aber vollständig. */
    private function ProbeZeile(array $b): string
    {
        $u = is_array($b['umgebung'] ?? null) ? $b['umgebung'] : [];
        $ice = is_array($b['ice'] ?? null) ? $b['ice'] : [];
        $api = is_array($b['api'] ?? null) ? $b['api'] : [];
        $teile = [];
        foreach ((array)($ice['kandidaten'] ?? []) as $typ => $anzahl) {
            $teile[] = $typ . '×' . (int)$anzahl;
        }
        return sprintf(
            "[%s] %s\n%s | %s | iframe: %s | WebRTC: %s | Mikro: %s\nICE: %s | API: HTTP %s %s | call_id: %s",
            date('d.m. H:i', (int)($b['at'] ?? 0)),
            (string)($b['fazit'] ?? '?'),
            mb_substr((string)($u['agent'] ?? '?'), 0, 60),
            ($u['sicher'] ?? false) ? 'sicherer Kontext' : 'UNSICHERER KONTEXT',
            ($u['iframe'] ?? false) ? 'ja' : 'nein',
            ($u['webrtc'] ?? false) ? 'ja' : 'NEIN',
            ($u['mikro'] ?? false) ? 'ja' : 'NEIN',
            $teile !== [] ? implode(' ', $teile) : ('FEHLER ' . (string)($ice['fehler'] ?? 'keine Kandidaten')),
            (string)($api['status'] ?? '0'),
            (string)($api['code'] ?? ($api['fehler'] ?? '')),
            ($api['location'] ?? '') !== '' ? 'lesbar' : 'NICHT lesbar'
        );
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
     * Diese Kachel hat sich nie selbst angeschlossen — angeschlossen hat sie
     * der Einrichtungs-Assistent. Geloest werden muss er trotzdem.
     */
    private function ElternanschlussLoesen(): void
    {
        if ((int)(@IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0) > 0) {
            @IPS_DisconnectInstance($this->InstanceID);
        }
    }

}

<?php

declare(strict_types=1);

/**
 * SymDo Voice — Zweiwege-Sprachdialog mit der KI.
 *
 * Stand: Etappe 0 (Machbarkeitsprobe). Die Kachel prüft, ob die Umgebung
 * (Browser, Symcon-App iOS/Android) WebRTC zu OpenAI überhaupt trägt, und
 * meldet die Ergebnisse hierher zurück — ablesbar im Konfigurationsformular.
 * Der eigentliche Sprachdialog folgt in den nächsten Etappen; das Gerüst
 * (Gateway-Anschluss, Benutzerzuordnung, Kachel-Anbindung) ist schon das
 * endgültige.
 */
class SymDoVoice extends IPSModuleStrict
{
    private const GATEWAY_GUID = '{E677FE7B-28C9-4124-8B58-8A1FE2657E8D}';

    /** Höchstens so viele Proben-Berichte werden aufgehoben (je Umgebung einer). */
    private const PROBE_MAX = 8;

    /**
     * Vorschlagsliste der Konsole beim Anlegen: sie bietet ein vorhandenes
     * Gateway an oder legt eines an. „connect" statt „require": an EINEM
     * Gateway hängen mehrere Kacheln.
     */
    public function GetCompatibleParents(): string
    {
        return json_encode(['type' => 'connect', 'moduleIDs' => [self::GATEWAY_GUID]]);
    }

    public function Create(): void
    {
        parent::Create();

        // Pflicht, damit Symcon die HTML-Kachel aus GetVisualizationTile() rendert
        $this->SetVisualizationType(1);

        // Der feste Benutzer dieser Kachel: „meine Aufgaben" und neue Einträge
        // gehören ihm (Entscheidung vom 01.09.2026).
        $this->RegisterPropertyString('UserID', '');

        // Berichte der Machbarkeitsprobe, je Umgebung der jüngste.
        $this->RegisterAttributeString('ProbeResult', '[]');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }

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
            case 'ProbeReport':
                // Die Kachel meldet ihr Messergebnis. Aufheben je Umgebung
                // (Kennung aus Browser + Rahmen), damit iPhone, Android und
                // Desktop nebeneinander im Formular stehen.
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

    public function GetVisualizationTile(): string
    {
        $path = __DIR__ . '/module.html';
        $html = @file_get_contents($path);
        if (!is_string($html)) {
            $this->LogMessage('GetVisualizationTile: module.html nicht lesbar, Pfad=' . $path, KL_WARNING);
            return '';
        }
        // Initial-Payload inline mitgeben, damit die Kachel sofort korrekt zeichnet
        $payload = json_encode($this->PayloadBauen(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        return $html . '<script>handleMessage(' . $payload . ');</script>';
    }

    public function GetConfigurationForm(): string
    {
        $zeilen = [];
        $berichte = json_decode($this->ReadAttributeString('ProbeResult'), true);
        foreach (is_array($berichte) ? $berichte : [] as $b) {
            if (!is_array($b)) {
                continue;
            }
            $zeilen[] = ['type' => 'Label', 'caption' => $this->ProbeZeile($b)];
        }
        if ($zeilen === []) {
            $zeilen[] = ['type' => 'Label', 'caption' => $this->Translate('No reports yet — open the tile in each environment (browser, Symcon app on iOS and Android). The probe runs by itself and reports here.')];
        }

        $form = [
            'elements' => [
                ['type' => 'Label', 'caption' => $this->Translate('Feasibility probe: whether this environment supports the voice dialog (WebRTC to the AI provider). The tile measures and reports automatically.')],
                ['type' => 'ExpansionPanel', 'caption' => $this->Translate('Probe results'), 'expanded' => true,
                 'items' => array_merge($zeilen, [
                     ['type' => 'Button', 'caption' => $this->Translate('Clear reports'),
                      'onClick' => 'IPS_RequestAction($id, "ProbeReset", "");'],
                 ])],
            ],
            'actions' => [],
            'status'  => [],
        ];
        return (string)json_encode($form, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    // ------------------------------------------------------------------
    // Intern
    // ------------------------------------------------------------------

    private function PayloadBauen(): array
    {
        return [
            'type'  => 'state',
            'stufe' => 'probe',
        ];
    }

    private function Push(array $daten): void
    {
        $this->UpdateVisualizationValue((string)json_encode($daten,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /** Bericht der Kachel ablegen — je Umgebungskennung nur der jüngste. */
    private function ProbeAblegen(string $json): void
    {
        $neu = json_decode($json, true);
        if (!is_array($neu)) {
            return;
        }
        // Kennung der Umgebung: gekürzter Browser-Stempel plus Rahmen-Frage.
        $agent = (string)($neu['umgebung']['agent'] ?? '');
        $iframe = ($neu['umgebung']['iframe'] ?? false) === true;
        $kennung = substr(md5($agent . '|' . ($iframe ? '1' : '0')), 0, 8);

        $alle = json_decode($this->ReadAttributeString('ProbeResult'), true);
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
        // Formular offen? Dann gleich zeigen. Warnung vermeiden, falls keins offen ist.
        @$this->ReloadForm();
    }

    /** Eine Formularzeile je Bericht — kompakt, aber vollständig. */
    private function ProbeZeile(array $b): string
    {
        $u = is_array($b['umgebung'] ?? null) ? $b['umgebung'] : [];
        $ice = is_array($b['ice'] ?? null) ? $b['ice'] : [];
        $api = is_array($b['api'] ?? null) ? $b['api'] : [];
        $kandidaten = is_array($ice['kandidaten'] ?? null) ? $ice['kandidaten'] : [];
        $teile = [];
        foreach ($kandidaten as $typ => $anzahl) {
            $teile[] = $typ . '×' . (int)$anzahl;
        }
        return sprintf(
            "[%s] %s\n%s | %s | iframe: %s | WebRTC: %s | Mikro: %s | Erkennung: %s\nICE: %s | API: HTTP %s %s | call_id: %s",
            date('d.m. H:i', (int)($b['at'] ?? 0)),
            (string)($b['fazit'] ?? '?'),
            mb_substr((string)($u['agent'] ?? '?'), 0, 60),
            ($u['sicher'] ?? false) ? 'sicherer Kontext' : 'UNSICHERER KONTEXT',
            ($u['iframe'] ?? false) ? 'ja' : 'nein',
            ($u['webrtc'] ?? false) ? 'ja' : 'NEIN',
            ($u['mikro'] ?? false) ? 'ja' : 'NEIN',
            ($u['erkennung'] ?? false) ? 'ja' : 'nein',
            $teile !== [] ? implode(' ', $teile) : ('FEHLER ' . (string)($ice['fehler'] ?? 'keine Kandidaten')),
            (string)($api['status'] ?? '0'),
            (string)($api['code'] ?? ($api['fehler'] ?? '')),
            ($api['location'] ?? '') !== '' ? 'lesbar' : 'NICHT lesbar'
        );
    }
}

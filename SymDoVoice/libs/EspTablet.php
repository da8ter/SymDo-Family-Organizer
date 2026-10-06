<?php

declare(strict_types=1);

/**
 * Ton über das Tablet, Teil der Kachel SymDoVoice: ein SymDo-Sprachgerät
 * (SymDoESPVoice) liefert das Mikrofon, diese Kachel führt das Gespräch.
 * Der Browser meldet sich alle 30 s als bereit (EspHier); ohne frisches
 * Lebenszeichen lehnt die Kachel ab, und das Gerät spricht selbst.
 */
trait EspTablet
{
    private static string $ESP_GUID = '{DDF91F65-36AE-4539-BBFC-6F1F1D943A9E}';

    /** Sprachgeräte, die diese Kachel als Tablet-Ton gewählt haben. */
    private function EspGeraete(): array
    {
        $ids = [];
        foreach (@IPS_GetInstanceListByModuleID(self::$ESP_GUID) ?: [] as $id) {
            $cfg = json_decode((string)@IPS_GetConfiguration($id), true);
            if (is_array($cfg) && (int)($cfg['SpeakerTile'] ?? 0) === $this->InstanceID) {
                $ids[] = (int)$id;
            }
        }
        return $ids;
    }

    /** @return array{nonce:string, gewinner:string, quelle:int} */
    private function EspStand(): array
    {
        $s = json_decode((string)@$this->ReadAttributeString('EspStand'), true);
        return [
            'nonce'    => is_array($s) ? (string)($s['nonce'] ?? '') : '',
            'gewinner' => is_array($s) ? (string)($s['gewinner'] ?? '') : '',
            'quelle'   => is_array($s) ? (int)($s['quelle'] ?? 0) : 0,
        ];
    }

    /** Weckwort am Sprachgerät: offenen Browsern anbieten — oder sofort ablehnen. */
    private function EspWake(string $json): void
    {
        $w = json_decode($json, true);
        $sdev = is_array($w) ? (int)($w['sdev'] ?? 0) : 0;
        $nonce = is_array($w) ? (string)($w['nonce'] ?? '') : '';
        if (!TabletCalc::NonceGueltig($nonce) || !in_array($sdev, $this->EspGeraete(), true)) {
            return;
        }
        if (!TabletCalc::Bereit((int)$this->ReadAttributeInteger('EspBereit'), time())) {
            // Kein Browser mit freigegebenem Ton: das Gerät spricht selbst, ohne zu warten.
            @IPS_RequestAction($sdev, 'Mic', (string)json_encode(['aktion' => 'nein', 'nonce' => $nonce]));
            return;
        }
        $this->WriteAttributeString('EspStand', (string)json_encode(['nonce' => $nonce, 'gewinner' => '', 'quelle' => $sdev]));
        $this->Push(['type' => 'espWake', 'nonce' => $nonce, 'hf' => ($w['hf'] ?? false) === true,
                     'name' => (string)($w['name'] ?? '')]);
    }

    /** Ein Browser sagt zu, hält das Mikrofon offen oder beendet. */
    private function EspMic(string $json): void
    {
        $m = json_decode($json, true);
        if (!is_array($m)) {
            return;
        }
        $aktion = (string)($m['aktion'] ?? '');
        $nonce = (string)($m['nonce'] ?? '');
        $client = (string)($m['client'] ?? '');
        $stand = $this->EspStand();
        if ($aktion === 'start') {
            $z = TabletCalc::Zusage(['nonce' => $stand['nonce'], 'gewinner' => $stand['gewinner']], $nonce, $client);
            if ($z['weiter']) {
                $stand['gewinner'] = $client;
                $this->WriteAttributeString('EspStand', (string)json_encode($stand));
                @IPS_RequestAction($stand['quelle'], 'Mic', (string)json_encode(['aktion' => 'start', 'nonce' => $nonce]));
            }
            // Alle Fenster erfahren, wer das Gespräch führt — die anderen lassen los.
            if ($nonce === $stand['nonce']) {
                $this->Push(['type' => 'espGewaehlt', 'nonce' => $nonce, 'client' => $stand['gewinner']]);
            }
            return;
        }
        if (in_array($aktion, ['stop', 'keep'], true) && TabletCalc::DarSteuern($stand, $nonce, $client)) {
            @IPS_RequestAction($stand['quelle'], 'Mic', (string)json_encode(['aktion' => $aktion, 'nonce' => $nonce]));
            if ($aktion === 'stop') {
                $this->WriteAttributeString('EspStand', '');
            }
        }
    }
}

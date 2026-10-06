<?php

declare(strict_types=1);

/**
 * Ton über das Tablet, Teil der Kachel SymDoVoice: ein SymDo-Sprachgerät
 * (SymDoESPVoice) liefert das Mikrofon, diese Kachel führt das Gespräch.
 * Der Browser meldet sich alle 30 s als bereit (EspHier); ohne frisches
 * Lebenszeichen lehnt die Kachel ab, und das Gerät spricht selbst.
 *
 * Vom Sprachgerät kommt alles über öffentliche Funktionen (SDVC_Geraet*),
 * nicht über RequestAction — dessen Idents kann jeder Browser der Visu rufen.
 * Der Ton geht verschlüsselt mit dem Schlüssel des führenden Browsers hinaus:
 * Kachel-Pushes erreichen jeden offenen Visu-Client.
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
            'schluessel' => is_array($s) ? (string)($s['schluessel'] ?? '') : '',
            'geheim'     => is_array($s) ? (string)($s['geheim'] ?? '') : '',
        ];
    }

    private function MicAnGeraet(int $sdev, string $aktion, string $nonce): void
    {
        if ($sdev > 0 && function_exists('SDEV_MicSteuern')) {
            @SDEV_MicSteuern($sdev, (string)json_encode(['aktion' => $aktion, 'nonce' => $nonce]));
        }
    }

    /** Vom Sprachgerät (SDEV): ein Mikrofon-Paket, Nummer + µ-law als Base64. */
    public function GeraetTon(string $Paket): void
    {
        $stand = $this->EspStand();
        if ($stand['gewinner'] === '') {
            return;   // noch niemand führt — dann hört auch niemand mit
        }
        $v = TabletCalc::Verschluesseln($Paket, $stand['schluessel']);
        if ($v !== null) {
            $this->Push(['type' => 'espAudio', 'n' => $v['n'], 'd' => $v['d']]);
        }
    }

    /** Vom Sprachgerät (SDEV): Taste am Gerät hat das Gespräch beendet. */
    public function GeraetEnde(string $Json): void
    {
        $e = json_decode($Json, true);
        $stand = $this->EspStand();
        if (is_array($e) && ($e['nonce'] ?? '') !== '' && ($e['nonce'] ?? '') === $stand['nonce']) {
            $this->WriteAttributeString('EspStand', '');
            $this->Push(['type' => 'espEnde', 'nonce' => $stand['nonce']]);
        }
    }

    /** Vom Sprachgerät (SDEV): Weckwort — offenen Browsern anbieten oder sofort ablehnen. */
    public function GeraetWeckruf(string $Json): void
    {
        $w = json_decode($Json, true);
        $sdev = is_array($w) ? (int)($w['sdev'] ?? 0) : 0;
        $nonce = is_array($w) ? (string)($w['nonce'] ?? '') : '';
        if (!TabletCalc::NonceGueltig($nonce) || !in_array($sdev, $this->EspGeraete(), true)) {
            return;
        }
        if (!TabletCalc::Bereit((int)$this->ReadAttributeInteger('EspBereit'), time())) {
            // Kein Browser mit freigegebenem Ton: das Gerät spricht selbst, ohne zu warten.
            $this->MicAnGeraet($sdev, 'nein', $nonce);
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
        $this->SendDebug('EspMic', $aktion . ' ' . $nonce . ' von ' . $client . ($client === $stand['gewinner'] ? ' (führt)' : ''), 0);
        if ($aktion === 'start') {
            // Ohne Schlüssel keine Zusage: der Ton ginge sonst lesbar an alle Fenster.
            $schluessel = (string)($m['schluessel'] ?? '');
            $geheim = (string)($m['geheim'] ?? '');
            $z = TabletCalc::SchluesselGueltig($schluessel) && TabletCalc::GeheimGueltig($geheim)
                ? TabletCalc::Zusage(['nonce' => $stand['nonce'], 'gewinner' => $stand['gewinner']], $nonce, $client)
                : ['weiter' => false];
            if ($z['weiter']) {
                $stand['gewinner'] = $client;
                $stand['schluessel'] = $schluessel;
                $stand['geheim'] = $geheim;
                $this->WriteAttributeString('EspStand', (string)json_encode($stand));
                $this->MicAnGeraet($stand['quelle'], 'start', $nonce);
            }
            // Alle Fenster erfahren, wer das Gespräch führt — die anderen lassen los.
            if ($nonce === $stand['nonce']) {
                $this->Push(['type' => 'espGewaehlt', 'nonce' => $nonce, 'client' => $stand['gewinner']]);
            }
            return;
        }
        if (in_array($aktion, ['stop', 'keep'], true) && TabletCalc::DarSteuern($stand, $nonce, $client, (string)($m['geheim'] ?? ''))) {
            $this->MicAnGeraet($stand['quelle'], $aktion, $nonce);
            if ($aktion === 'stop') {
                $this->WriteAttributeString('EspStand', '');
            }
        }
    }
}

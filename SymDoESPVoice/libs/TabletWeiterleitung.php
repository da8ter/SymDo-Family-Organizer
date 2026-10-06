<?php

declare(strict_types=1);

/**
 * Ton über das Tablet, Teil der Instanz SymDoESPVoice: Weckwort, Ende und
 * Mikrofon-Pakete des Geräts gehen an die gewählte SymDo-Voice-Kachel.
 *
 * Zwischen Gerät und Kachel nur über öffentliche Modulfunktionen
 * (SDVC_Geraet*, SDEV_MicSteuern), nicht über RequestAction: dessen Idents
 * einer Kachel kann jeder Browser mit Zugang zur Visu aufrufen.
 */
trait TabletWeiterleitung
{
    private static string $VOICE_TILE_GUID = '{1F413A34-452C-4A8D-BEFC-CA7CB9DBB1BB}';

    /**
     * Von der Voice-Kachel: {aktion: start|nein|stop|keep, nonce}. Geht als
     * signierter Befehl "mic" ans Gerät (SDEV_MicSteuern).
     */
    public function MicSteuern(string $Json): void
    {
        $m = json_decode($Json, true);
        $aktion = is_array($m) ? (string)($m['aktion'] ?? '') : '';
        $wert = TabletCalc::MicWert($aktion, is_array($m) ? (string)($m['nonce'] ?? '') : '');
        if ($wert === null) {
            return;
        }
        if (in_array($aktion, ['stop', 'nein'], true)) {
            // Danach nimmt diese Instanz keine Pakete des Gesprächs mehr an.
            $this->WriteAttributeString('MicNonce', '');
        }
        $this->Senden('mic', $wert);
    }

    /** Weckwort oder Taste am Gerät: Kachel fragen, sonst sofort ablehnen. */
    private function Ereignis(string $json): void
    {
        $e = TabletCalc::Ereignis($json, $this->ReadAttributeString('CmdKey'), (int)floor(microtime(true) * 1000));
        if ($e === null) {
            $this->SendDebug('Ereignis', 'abgewiesen (Signatur oder Zeit): ' . $json, 0);
            return;
        }
        $this->SendDebug('Ereignis', $json, 0);
        $kachel = $this->KachelID();
        if ($e['art'] === 'ende') {
            if ($e['nonce'] === $this->ReadAttributeString('MicNonce')) {
                $this->WriteAttributeString('MicNonce', '');
                if ($kachel !== 0 && function_exists('SDVC_GeraetEnde')) {
                    @SDVC_GeraetEnde($kachel, (string)json_encode(['nonce' => $e['nonce']]));
                }
            }
            return;
        }
        // Wiederholung eines mitgeschnittenen Weckrufs: jede Nonce nur einmal.
        $alt = json_decode($this->ReadAttributeString('MicNoncen'), true);
        $alt = is_array($alt) ? $alt : [];
        if (in_array($e['nonce'], $alt, true)) {
            $this->SendDebug('Ereignis', 'Nonce schon benutzt', 0);
            return;
        }
        $this->WriteAttributeString('MicNoncen', (string)json_encode(array_slice(array_merge($alt, [$e['nonce']]), -32)));
        if ($kachel === 0 || !function_exists('SDVC_GeraetWeckruf')) {
            // Keine Kachel gewählt: das Gerät spricht ohne Wartezeit selbst.
            $this->Senden('mic', (string)TabletCalc::MicWert('nein', $e['nonce']));
            return;
        }
        $this->WriteAttributeString('MicNonce', $e['nonce']);
        // Die Kachel entscheidet (Lebenszeichen eines Browsers) und antwortet über SDEV_MicSteuern.
        @SDVC_GeraetWeckruf($kachel, (string)json_encode([
            'sdev'  => $this->InstanceID,
            'nonce' => $e['nonce'],
            'hf'    => $e['hf'] ?? false,
            'name'  => IPS_GetName($this->InstanceID),
        ], JSON_UNESCAPED_UNICODE));
    }

    /** Mikrofon-Paket: nur mit gültiger HMAC und nur für das laufende Gespräch. */
    private function MikroPaket(string $roh): void
    {
        $nonce = $this->ReadAttributeString('MicNonce');
        if ($nonce === '') {
            return;
        }
        $paket = TabletCalc::Paket($roh, $nonce, $this->ReadAttributeString('CmdKey'));
        $kachel = $this->KachelID();
        if ($paket !== null && $kachel !== 0 && function_exists('SDVC_GeraetTon')) {
            @SDVC_GeraetTon($kachel, $paket);
        }
    }

    /** Die gewählte Voice-Kachel, wenn es sie gibt und sie bereit ist. */
    private function KachelID(): int
    {
        $id = $this->ReadPropertyInteger('SpeakerTile');
        if ($id <= 0 || IPS_GetKernelRunlevel() !== KR_READY || !@IPS_InstanceExists($id)) {
            return 0;
        }
        return (string)(IPS_GetInstance($id)['ModuleInfo']['ModuleID'] ?? '') === self::$VOICE_TILE_GUID ? $id : 0;
    }
}

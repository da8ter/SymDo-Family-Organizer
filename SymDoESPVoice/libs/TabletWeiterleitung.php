<?php

declare(strict_types=1);

/**
 * Ton über das Tablet, Teil der Instanz SymDoESPVoice: Weckwort, Ende und
 * Mikrofon-Pakete des Geräts gehen an die gewählte SymDo-Voice-Kachel.
 */
trait TabletWeiterleitung
{
    private static string $VOICE_TILE_GUID = '{1F413A34-452C-4A8D-BEFC-CA7CB9DBB1BB}';

    /** Weckwort oder Taste am Gerät: Kachel fragen, sonst sofort ablehnen. */
    private function Ereignis(string $json): void
    {
        $e = TabletCalc::Ereignis($json);
        if ($e === null) {
            return;
        }
        $this->SendDebug('Ereignis', $json, 0);
        $kachel = $this->KachelID();
        if ($e['art'] === 'ende') {
            if ($kachel !== 0) {
                @IPS_RequestAction($kachel, 'EspEnde', (string)json_encode(['nonce' => $e['nonce']]));
            }
            return;
        }
        if ($kachel === 0) {
            // Keine Kachel gewählt: das Gerät spricht ohne Wartezeit selbst.
            $this->Senden('mic', (string)TabletCalc::MicWert('nein', $e['nonce']));
            return;
        }
        // Die Kachel entscheidet (Lebenszeichen eines Browsers) und antwortet über "Mic".
        @IPS_RequestAction($kachel, 'EspWake', (string)json_encode([
            'sdev'  => $this->InstanceID,
            'nonce' => $e['nonce'],
            'hf'    => $e['hf'] ?? false,
            'name'  => IPS_GetName($this->InstanceID),
        ], JSON_UNESCAPED_UNICODE));
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

<?php

declare(strict_types=1);

/**
 * Sprachprofil eines gekoppelten Sprachgeraets (ESP32, 06.10.2026).
 *
 * Ein Geraet ohne Bildschirm kann nicht waehlen, fuer wen es spricht — und darf
 * es auch nicht: ein Token in fremder Hand koennte sich sonst jedes Mitglied
 * aussuchen. Deshalb stehen Mitglied, Raum und die Erlaubnis zum Schalten am
 * GERAETEEINTRAG im Gateway und ueberschreiben, was im Rumpf der Anfrage steht.
 *
 * Geraete ohne Profil (Web-App, iOS) bleiben unberuehrt.
 *
 * Rein, ohne Symcon — Pruefstand: tests/VoiceGeraetProfilTest.php
 */
final class VoiceGeraetProfilCalc
{
    /** Kachel-Kennungen der Sprachgeraete liegen oberhalb echter Objekt-IDs (max. 59999). */
    public const TILE_BASIS = 1000000;

    /**
     * Rohes Profil in die feste Form bringen.
     * @return array{userId:string, raum:string, geraete:bool}
     */
    public static function Normalisieren(mixed $roh): array
    {
        $p = is_array($roh) ? $roh : [];
        return [
            'userId'  => mb_substr(trim((string)($p['userId'] ?? '')), 0, 64),
            'raum'    => mb_substr(trim((string)($p['raum'] ?? '')), 0, 60),
            // Vorgabe: schalten erlaubt — es greifen ohnehin die Gateway-Riegel
            // (Geraetesteuerung an, Einwilligung, freigegebene Wurzeln).
            'geraete' => ($p['geraete'] ?? true) !== false,
        ];
    }

    /** Hat dieses Geraet ein Sprachprofil? */
    public static function HatProfil(?array $device): bool
    {
        return is_array($device) && is_array($device['voice'] ?? null);
    }

    /** Feste, positive Kachel-Kennung je Geraet — "ein Gespraech je Kachel" gilt je Geraet. */
    public static function TileVon(string $deviceId): int
    {
        return self::TILE_BASIS + (crc32($deviceId) % 1000000);
    }

    /**
     * Den Anfragerumpf mit dem Profil ueberschreiben. Ohne Profil unveraendert.
     * Ergaenzt `_raum` und `_geraete` fuer den weiteren Weg (Anweisung, Werkzeuge).
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public static function Anwenden(array $body, ?array $device): array
    {
        if (!self::HatProfil($device)) {
            return $body;
        }
        $p = self::Normalisieren($device['voice']);
        $body['userId']   = $p['userId'];
        $body['tile']     = self::TileVon((string)($device['id'] ?? ''));
        $body['_raum']    = $p['raum'];
        $body['_geraete'] = $p['geraete'];
        // Vorgaben fuer Listen bleiben Sache des Gateways, nicht des Geraets.
        unset($body['defaults']);
        return $body;
    }

    /** Satz fuer die Anweisung: wo das Geraet steht. Leer ohne Raum. */
    public static function RaumHinweis(string $raum): string
    {
        $raum = trim($raum);
        if ($raum === '') {
            return '';
        }
        return 'Du sprichst gerade über ein Sprachgerät im Raum „' . $raum . '". '
            . 'Nennt jemand bei einem Gerät keinen Raum (etwa „mach das Licht an"), meint er dieses Zimmer: '
            . 'gib dann raum „' . $raum . '" mit.';
    }
}

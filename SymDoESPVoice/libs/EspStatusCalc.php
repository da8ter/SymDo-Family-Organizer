<?php

declare(strict_types=1);

/**
 * Rechenkern von SymDoESPVoice — ohne Symcon, Prüfstand: tests/EspVoiceTest.php
 *
 * Das Sprachgerät meldet über MQTT auf  symdo/esp/<geraet>/status  ein JSON
 * (auch nur Teile davon) und lauscht auf  symdo/esp/<geraet>/cmd.
 */
final class EspStatusCalc
{
    /** Zustände wie in der Firmware (vs_state_t + Einrichtung) */
    public const ZUSTAENDE = [
        0 => 'Ready', 1 => 'Connecting', 2 => 'Listening', 3 => 'Thinking',
        4 => 'Tool', 5 => 'Speaking', 6 => 'Error', 7 => 'Setup',
    ];

    public const WECKWOERTER = ['hiesp', 'alexa', 'jarvis', 'computer', 'aus', 'eigen'];

    public static function ThemaStatus(string $geraet): string
    {
        return 'symdo/esp/' . $geraet . '/status';
    }

    public static function ThemaBefehl(string $geraet): string
    {
        return 'symdo/esp/' . $geraet . '/cmd';
    }

    /** Geräte-IDs des Gateways sind 8 Hex-Zeichen; nur solche kommen in ein Thema. */
    public static function GeraetGueltig(string $geraet): bool
    {
        return preg_match('/^[0-9a-f]{8}$/', $geraet) === 1;
    }

    /**
     * Statusmeldung lesen. Nur bekannte Felder, in Typ und Bereich geklemmt —
     * die Nutzlast kommt aus dem Netz.
     * @return array<string,mixed>
     */
    public static function Status(string $json): array
    {
        $d = json_decode($json, true);
        if (!is_array($d)) {
            return [];
        }
        $raus = [];
        if (array_key_exists('online', $d)) {
            $raus['online'] = $d['online'] === true;
        }
        foreach (['akku' => [0, 100], 'lautstaerke' => [0, 100], 'helligkeit' => [0, 100]] as $k => [$min, $max]) {
            if (isset($d[$k]) && is_numeric($d[$k])) {
                $raus[$k] = max($min, min($max, (int)$d[$k]));
            }
        }
        if (isset($d['zustand']) && is_numeric($d['zustand']) && isset(self::ZUSTAENDE[(int)$d['zustand']])) {
            $raus['zustand'] = (int)$d['zustand'];
        }
        if (array_key_exists('laedt', $d)) {
            $raus['laedt'] = $d['laedt'] === true;
        }
        foreach (['frage' => 500, 'antwort' => 1000, 'version' => 40, 'weckwort' => 60] as $k => $len) {
            if (isset($d[$k]) && is_string($d[$k])) {
                $raus[$k] = mb_substr($d[$k], 0, $len);
            }
        }
        return $raus;
    }

    /**
     * Signierter Befehl an das Gerät. Alle Geräte teilen sich den MQTT-Zugang;
     * erst die Signatur mit dem gerätegenauen Schlüssel (aus dem Gateway-Profil)
     * macht einen Befehl echt. ts schützt gegen Wiederholung (das Gerät nimmt
     * nur steigende ts im Fenster ±60 s an).
     * Signiert wird  "<cmd>|<value>|<ts>"  (value leer, wenn keiner).
     */
    public static function Befehl(string $cmd, int|null $wert, string $schluesselHex, int $ts): string
    {
        $b = ['cmd' => $cmd];
        if ($wert !== null) {
            $b['value'] = $wert;
        }
        $b['ts'] = $ts;
        $b['mac'] = self::Signatur($cmd, $wert, $schluesselHex, $ts);
        return (string)json_encode($b, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function Signatur(string $cmd, int|null $wert, string $schluesselHex, int $ts): string
    {
        return hash_hmac('sha256', $cmd . '|' . ($wert === null ? '' : (string)$wert) . '|' . $ts, (string)hex2bin($schluesselHex));
    }

    /** Neuer Befehlsschlüssel: 32 zufällige Bytes als Hex. */
    public static function NeuerSchluessel(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Weckwort-Kennung für das Gateway-Profil aus Formularwerten.
     */
    public static function Weckwort(string $wahl, string $eigen): string
    {
        $wahl = strtolower(trim($wahl));
        if ($wahl === 'eigen') {
            return 'eigen:' . trim($eigen);
        }
        return in_array($wahl, self::WECKWOERTER, true) ? $wahl : 'hiesp';
    }

    /**
     * Profil für TGW_SetDeviceVoiceProfile.
     * @param array{port:int, user:string, pass:string}|null $mqtt
     * @return array<string,mixed>
     */
    public static function Profil(string $userId, string $raum, bool $geraete, string $weckwort, ?array $mqtt,
                                  string $cmdKey = '', bool $mitschrift = false): array
    {
        $p = ['userId' => $userId, 'raum' => $raum, 'geraete' => $geraete, 'weckwort' => $weckwort,
              'cmdKey' => $cmdKey, 'mitschrift' => $mitschrift];
        if ($mqtt !== null) {
            $p['mqtt'] = $mqtt;
        }
        return $p;
    }
}

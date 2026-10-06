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
        foreach (['frage' => 500, 'antwort' => 1000, 'version' => 40, 'weckwort' => 60, 'update' => 80] as $k => $len) {
            if (isset($d[$k]) && is_string($d[$k])) {
                $raus[$k] = mb_substr($d[$k], 0, $len);
            }
        }
        return $raus;
    }

    /**
     * Signierter Befehl an das Gerät. Alle Geräte teilen sich den MQTT-Zugang;
     * erst die Signatur mit dem gerätegenauen Schlüssel (aus dem Gateway-Profil)
     * macht einen Befehl echt. ts (Unix-Zeit in MILLISEKUNDEN) schützt gegen
     * Wiederholung: das Gerät nimmt nur strikt steigende ts im Fenster ±60 s an.
     * Signiert wird  "<cmd>|<value>|<ts>"  (value leer, wenn keiner).
     */
    public static function Befehl(string $cmd, int|string|null $wert, string $schluesselHex, int $ts): string
    {
        $b = ['cmd' => $cmd];
        if ($wert !== null) {
            $b['value'] = $wert;
        }
        $b['ts'] = $ts;
        $b['mac'] = self::Signatur($cmd, $wert, $schluesselHex, $ts);
        return (string)json_encode($b, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function Signatur(string $cmd, int|string|null $wert, string $schluesselHex, int $ts): string
    {
        return hash_hmac('sha256', $cmd . '|' . ($wert === null ? '' : (string)$wert) . '|' . $ts, (string)hex2bin($schluesselHex));
    }

    /** Höchstgröße einer Firmware (App-Partition 0x580000). */
    public const FIRMWARE_MAX = 0x580000;

    /**
     * Ist das ein ESP-App-Abbild, das in die Partition passt? Erstes Byte 0xE9
     * (ESP-Image-Kennung), mindestens 64 KB.
     */
    public static function FirmwareGueltig(string $bin): bool
    {
        $n = strlen($bin);
        return $n >= 65536 && $n <= self::FIRMWARE_MAX && ord($bin[0]) === 0xE9;
    }

    /** Befehlswert für ota: "<pfad>|<sha256>" — beides von der Signatur gedeckt. */
    public static function OtaWert(string $pfad, string $sha256): string
    {
        return $pfad . '|' . $sha256;
    }

    /**
     * Befehlswert für speak: "<hash>|<format>|<untertitel>". Hash und Format
     * kommen aus dem Gateway (TGW_TtsClip); der Untertitel steht zuletzt, damit
     * ein „|" im Text nichts verschiebt. Alles von der Signatur gedeckt.
     */
    public static function SpeakWert(string $hash, string $format, string $text): ?string
    {
        return self::SpeakListe([$hash], $format, $text);
    }

    /** Höchstens so viele Schnipsel spielt das Gerät in einer Durchsage. */
    public const MAX_SCHNIPSEL = 8;

    /**
     * Wie SpeakWert, aber mehrere Schnipsel hintereinander:
     * "<hash>,<hash>,…|<format>|<untertitel>". Alle im selben Format — das
     * Gerät öffnet je Schnipsel einen Decoder dieses Typs.
     *
     * @param list<string> $hashes
     */
    public static function SpeakListe(array $hashes, string $format, string $text): ?string
    {
        $format = self::TonFormat($format);
        if ($format === null || $hashes === [] || count($hashes) > self::MAX_SCHNIPSEL) {
            return null;
        }
        foreach ($hashes as $h) {
            if (!is_string($h) || preg_match('/^[0-9a-f]{32}$/', $h) !== 1) {
                return null;
            }
        }
        $text = trim((string)preg_replace('/\s+/u', ' ', $text));
        return implode(',', $hashes) . '|' . $format . '|' . mb_substr($text, 0, 160);
    }

    /**
     * Gateway-Format → Decoder des Geräts. ElevenLabs liefert z. B.
     * "mp3_44100_128", OpenAI fürs Briefing "aac" (ADTS). null = spielt das
     * Gerät nicht.
     */
    public static function TonFormat(string $format): ?string
    {
        $format = strtolower(trim($format));
        if (str_starts_with($format, 'mp3')) {
            return 'mp3';
        }
        return in_array($format, ['wav', 'aac', 'flac'], true) ? $format : null;
    }

    /**
     * Die fertigen Briefing-Schnipsel aus TGW_GetBriefingText als speak-Wert —
     * nur, wenn alle dasselbe spielbare Format haben. Sonst null, und der
     * Aufrufer erzeugt den Ton selbst.
     *
     * @param array<int, mixed> $clips [{hash, format}, …]
     */
    public static function BriefingWert(array $clips, string $untertitel): ?string
    {
        $hashes = [];
        $formate = [];
        foreach ($clips as $c) {
            if (!is_array($c)) {
                return null;
            }
            $hashes[] = (string)($c['hash'] ?? '');
            $formate[self::TonFormat((string)($c['format'] ?? '')) ?? '?'] = true;
        }
        if (count($formate) !== 1) {
            return null;
        }
        return self::SpeakListe($hashes, (string)array_key_first($formate), $untertitel);
    }

    /**
     * Text in Stücke für TGW_TtsClip (dort höchstens 600 Zeichen): an
     * Satzenden, sonst an Wortgrenzen. Mehr als MAX_SCHNIPSEL Stücke werden
     * abgeschnitten.
     *
     * @return list<string>
     */
    public static function Abschnitte(string $text, int $max = 600): array
    {
        $text = trim((string)preg_replace('/\s+/u', ' ', $text));
        if ($text === '') {
            return [];
        }
        $saetze = preg_split('/(?<=[.!?…])\s+/u', $text) ?: [$text];
        $stuecke = [];
        $akt = '';
        foreach ($saetze as $satz) {
            // Ein Satz über der Grenze wird an Wortgrenzen zerlegt.
            while (mb_strlen($satz) > $max) {
                if ($akt !== '') {
                    $stuecke[] = $akt;
                    $akt = '';
                }
                $schnitt = mb_strrpos(mb_substr($satz, 0, $max), ' ');
                $schnitt = $schnitt === false || $schnitt === 0 ? $max : $schnitt;
                $stuecke[] = mb_substr($satz, 0, $schnitt);
                $satz = ltrim(mb_substr($satz, $schnitt));
            }
            $kandidat = $akt === '' ? $satz : $akt . ' ' . $satz;
            if (mb_strlen($kandidat) > $max) {
                $stuecke[] = $akt;
                $akt = $satz;
            } else {
                $akt = $kandidat;
            }
        }
        if ($akt !== '') {
            $stuecke[] = $akt;
        }
        return array_slice(array_values(array_filter($stuecke, static fn(string $s): bool => $s !== '')), 0, self::MAX_SCHNIPSEL);
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

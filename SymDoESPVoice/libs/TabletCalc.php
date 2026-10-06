<?php

declare(strict_types=1);

/**
 * Ton über das Tablet: das Sprachgerät ist nur Mikrofon, das Gespräch führt die
 * SymDo-Voice-Kachel. Reine Funktionen für SymDoESPVoice (Gerät ↔ Symcon) und
 * SymDoVoice (Symcon ↔ Browser), damit Prüfstände ohne Symcon laufen.
 *
 *   Gerät  → symdo/esp/<id>/event  {"wake":"<nonce>","hf":bool} | {"ende":"<nonce>"}
 *   Gerät  → symdo/esp/<id>/mic    2 Byte Paketnummer + 1600 Byte G.711 µ-law (100 ms, 16 kHz)
 *   Symcon → Gerät, signiert: mic = start|<nonce> | nein|<nonce> | stop | keep
 */
class TabletCalc
{
    /** Größtes Mikrofon-Paket: 2 Byte Nummer + 200 ms Ton. Alles darüber ist kein Paket des Geräts. */
    public const PAKET_MAX = 2 + 3200;

    /** So lange gilt eine Kachel nach ihrem letzten Lebenszeichen als bereit. */
    public const BEREIT_SEKUNDEN = 75;

    public static function ThemaEreignis(string $geraet): string
    {
        return 'symdo/esp/' . $geraet . '/event';
    }

    public static function ThemaMikro(string $geraet): string
    {
        return 'symdo/esp/' . $geraet . '/mic';
    }

    /** ReceiveDataFilter für Status, Ereignisse und Mikrofon eines Geräts. */
    public static function Filter(string $geraet): string
    {
        return '.*' . preg_quote('symdo/esp/' . $geraet . '/', '/') . '(status|event|mic).*';
    }

    public static function NonceGueltig(string $nonce): bool
    {
        return preg_match('/^[0-9a-f]{8}$/', $nonce) === 1;
    }

    /** Kennung eines Browser-Fensters (zufällig in der Kachel erzeugt). */
    public static function ClientGueltig(string $client): bool
    {
        return preg_match('/^[a-z0-9]{6,32}$/', $client) === 1;
    }

    /**
     * Ereignis des Geräts lesen.
     * @return array{art:string, nonce:string, hf?:bool}|null
     */
    public static function Ereignis(string $json): ?array
    {
        $e = json_decode($json, true);
        if (!is_array($e)) {
            return null;
        }
        if (is_string($e['wake'] ?? null) && self::NonceGueltig($e['wake'])) {
            return ['art' => 'wake', 'nonce' => $e['wake'], 'hf' => ($e['hf'] ?? false) === true];
        }
        if (is_string($e['ende'] ?? null) && self::NonceGueltig($e['ende'])) {
            return ['art' => 'ende', 'nonce' => $e['ende']];
        }
        return null;
    }

    /** Wert für den signierten Befehl "mic", null bei ungültiger Eingabe. */
    public static function MicWert(string $aktion, string $nonce): ?string
    {
        if (in_array($aktion, ['start', 'nein'], true)) {
            return self::NonceGueltig($nonce) ? $aktion . '|' . $nonce : null;
        }
        return in_array($aktion, ['stop', 'keep'], true) ? $aktion : null;
    }

    /** Mikrofon-Paket prüfen und für die Kachel verpacken (Base64), sonst null. */
    public static function Paket(string $roh): ?string
    {
        $n = strlen($roh);
        return $n > 2 && $n <= self::PAKET_MAX ? base64_encode($roh) : null;
    }

    /**
     * Wer übernimmt? Mehrere offene Browser bekommen dasselbe Weckwort; nur der
     * erste, der für die laufende Anfrage zusagt, führt das Gespräch.
     *
     * @param array{nonce:string, gewinner:string} $stand
     * @return array{stand: array{nonce:string, gewinner:string}, weiter: bool}
     *         weiter = diese Zusage an das Gerät weitergeben
     */
    public static function Zusage(array $stand, string $nonce, string $client): array
    {
        if ($nonce === '' || $nonce !== $stand['nonce'] || !self::ClientGueltig($client)) {
            return ['stand' => $stand, 'weiter' => false];
        }
        if ($stand['gewinner'] !== '') {
            return ['stand' => $stand, 'weiter' => false];
        }
        $stand['gewinner'] = $client;
        return ['stand' => $stand, 'weiter' => true];
    }

    /** Stop/Keep zählen nur vom Fenster, das das Gespräch führt. */
    public static function DarSteuern(array $stand, string $nonce, string $client): bool
    {
        return $nonce !== '' && $nonce === $stand['nonce'] && $client !== '' && $client === $stand['gewinner'];
    }

    public static function Bereit(int $letztesZeichen, int $jetzt): bool
    {
        return $letztesZeichen > 0 && $jetzt - $letztesZeichen <= self::BEREIT_SEKUNDEN;
    }
}

<?php

declare(strict_types=1);

/**
 * Ton über das Tablet: das Sprachgerät ist nur Mikrofon, das Gespräch führt die
 * SymDo-Voice-Kachel. Reine Funktionen für SymDoESPVoice (Gerät ↔ Symcon) und
 * SymDoVoice (Symcon ↔ Browser), damit Prüfstände ohne Symcon laufen.
 *
 *   Gerät  → symdo/esp/<id>/event  {"wake":"<nonce>","hf":bool,"ts":ms,"mac":hex} | {"ende":"<nonce>","ts":ms,"mac":hex}
 *   Gerät  → symdo/esp/<id>/mic    2 Byte Nummer + 8 Byte HMAC + 1600 Byte G.711 µ-law (100 ms, 16 kHz)
 *   Symcon → Gerät, signiert: mic = start|<nonce> | nein|<nonce> | stop | keep
 *
 * Alles vom Gerät trägt eine HMAC mit dem Befehlsschlüssel: alle Sprachgeräte
 * teilen den MQTT-Zugang, ohne Signatur könnte ein anderes Gerät Ton in ein
 * Gespräch einspeisen. Zur Kachel geht der Ton verschlüsselt (XChaCha20) mit
 * dem Schlüssel des Browsers, der das Gespräch führt — Kachel-Pushes erreichen
 * JEDEN offenen Visu-Client.
 */
class TabletCalc
{
    /** Größtes Mikrofon-Paket: 2 Byte Nummer + 8 Byte HMAC + 200 ms Ton. */
    public const PAKET_MAX = 2 + 8 + 3200;

    /** Zeitfenster für Ereignisse des Geräts (ms), wie bei den Befehlen. */
    public const FENSTER_MS = 60000;

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

    /** Signaturtext eines Ereignisses — genau wie tablet.c ihn bildet. */
    public static function EreignisText(string $art, string $nonce, ?bool $hf, int $ts): string
    {
        return $hf === null ? "$art|$nonce|$ts" : "$art|$nonce|" . ($hf ? '1' : '0') . "|$ts";
    }

    /**
     * Ereignis des Geräts lesen und prüfen: Signatur mit dem Befehlsschlüssel,
     * Zeitstempel im Fenster. Ohne gültige Signatur null.
     * @return array{art:string, nonce:string, hf?:bool, ts:int}|null
     */
    public static function Ereignis(string $json, string $schluesselHex, int $jetztMs): ?array
    {
        $e = json_decode($json, true);
        $schluessel = strlen($schluesselHex) === 64 ? hex2bin($schluesselHex) : false;
        if (!is_array($e) || $schluessel === false || !is_int($e['ts'] ?? null) || !is_string($e['mac'] ?? null)
            || abs($e['ts'] - $jetztMs) > self::FENSTER_MS) {
            return null;
        }
        if (is_string($e['wake'] ?? null) && self::NonceGueltig($e['wake'])) {
            $r = ['art' => 'wake', 'nonce' => $e['wake'], 'hf' => ($e['hf'] ?? false) === true, 'ts' => $e['ts']];
            $text = self::EreignisText('wake', $r['nonce'], $r['hf'], $r['ts']);
        } elseif (is_string($e['ende'] ?? null) && self::NonceGueltig($e['ende'])) {
            $r = ['art' => 'ende', 'nonce' => $e['ende'], 'ts' => $e['ts']];
            $text = self::EreignisText('ende', $r['nonce'], null, $r['ts']);
        } else {
            return null;
        }
        return hash_equals(hash_hmac('sha256', $text, $schluessel), strtolower($e['mac'])) ? $r : null;
    }

    /** Wert für den signierten Befehl "mic", null bei ungültiger Eingabe. */
    public static function MicWert(string $aktion, string $nonce): ?string
    {
        if (in_array($aktion, ['start', 'nein'], true)) {
            return self::NonceGueltig($nonce) ? $aktion . '|' . $nonce : null;
        }
        return in_array($aktion, ['stop', 'keep'], true) ? $aktion : null;
    }

    /**
     * Mikrofon-Paket prüfen: HMAC über Nonce des laufenden Gesprächs, Nummer und
     * Ton. Gibt Nummer + Ton (ohne HMAC) als Base64 zurück, sonst null.
     */
    public static function Paket(string $roh, string $nonce, string $schluesselHex): ?string
    {
        $n = strlen($roh);
        $schluessel = strlen($schluesselHex) === 64 ? hex2bin($schluesselHex) : false;
        if ($n <= 10 || $n > self::PAKET_MAX || !self::NonceGueltig($nonce) || $schluessel === false) {
            return null;
        }
        $nr = substr($roh, 0, 2);
        $ton = substr($roh, 10);
        $soll = substr(hash_hmac('sha256', $nonce . $nr . $ton, $schluessel, true), 0, 8);
        return hash_equals($soll, substr($roh, 2, 8)) ? base64_encode($nr . $ton) : null;
    }

    /** Schlüssel, den der führende Browser für den Ton mitschickt (32 Byte Hex). */
    public static function SchluesselGueltig(string $hex): bool
    {
        return preg_match('/^[0-9a-f]{64}$/', $hex) === 1;
    }

    /**
     * Paket (Base64 aus Paket()) für die Kachel verschlüsseln.
     * @return array{n:string, d:string}|null  n = 24-Byte-Nonce, d = Ton, beides Base64
     */
    public static function Verschluesseln(string $paketB64, string $schluesselHex): ?array
    {
        $roh = base64_decode($paketB64, true);
        if ($roh === false || !self::SchluesselGueltig($schluesselHex) || !function_exists('sodium_crypto_stream_xchacha20_xor')) {
            return null;
        }
        $n = random_bytes(24);
        return ['n' => base64_encode($n), 'd' => base64_encode(sodium_crypto_stream_xchacha20_xor($roh, $n, (string)hex2bin($schluesselHex)))];
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

    /** Geheimnis des führenden Fensters (16 Byte Hex). Anders als die Fensterkennung wird es nie gepusht. */
    public static function GeheimGueltig(string $hex): bool
    {
        return preg_match('/^[0-9a-f]{32}$/', $hex) === 1;
    }

    /**
     * Stop/Keep zählen nur vom Fenster, das das Gespräch führt. Die Kennung
     * allein reicht nicht — espGewaehlt nennt sie allen Fenstern —, es braucht
     * das Geheimnis aus der Zusage.
     */
    public static function DarSteuern(array $stand, string $nonce, string $client, string $geheim): bool
    {
        return $nonce !== '' && $nonce === $stand['nonce'] && $client !== '' && $client === $stand['gewinner']
            && ($stand['geheim'] ?? '') !== '' && hash_equals((string)$stand['geheim'], $geheim);
    }

    // ── Kopplung: nur gekoppelte Browser dürfen ein Gespräch übernehmen ──────

    /** So lange gilt ein Kopplungscode. */
    public const CODE_SEKUNDEN = 600;
    /** Danach ist der Code verbraucht (1 zu 200 000 für Raten). */
    public const CODE_VERSUCHE = 5;
    /** Höchstens so viele gekoppelte Browser; der älteste fällt heraus. */
    public const KOPPLUNGEN_MAX = 10;

    /** @return array{code:string, stand:array{hash:string, bis:int, versuche:int}} */
    public static function NeuerCode(int $jetzt): array
    {
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        return ['code' => $code, 'stand' => ['hash' => hash('sha256', $code), 'bis' => $jetzt + self::CODE_SEKUNDEN, 'versuche' => 0]];
    }

    /**
     * Eingegebenen Code prüfen.
     * @param array{hash?:string, bis?:int, versuche?:int}|null $stand
     * @return array{ok:bool, grund:string, stand:?array}  stand null = Code weg (verbraucht, abgelaufen, zu oft falsch)
     */
    public static function CodePruefen(?array $stand, string $eingabe, int $jetzt): array
    {
        if (!is_array($stand) || ($stand['hash'] ?? '') === '' || (int)($stand['bis'] ?? 0) < $jetzt) {
            return ['ok' => false, 'grund' => 'kein_code', 'stand' => null];
        }
        if (preg_match('/^[0-9]{6}$/', $eingabe) === 1 && hash_equals((string)$stand['hash'], hash('sha256', $eingabe))) {
            return ['ok' => true, 'grund' => '', 'stand' => null];   // einmal verwendbar
        }
        $stand['versuche'] = (int)($stand['versuche'] ?? 0) + 1;
        return ['ok' => false, 'grund' => 'falsch', 'stand' => $stand['versuche'] >= self::CODE_VERSUCHE ? null : $stand];
    }

    /** Token, den der Browser bei der Kopplung selbst erzeugt (32 Byte Hex). */
    public static function TokenGueltig(string $hex): bool
    {
        return preg_match('/^[0-9a-f]{64}$/', $hex) === 1;
    }

    /** @param list<array{hash:string}> $liste */
    public static function Gekoppelt(array $liste, string $token): bool
    {
        if (!self::TokenGueltig($token)) {
            return false;
        }
        $h = hash('sha256', $token);
        $treffer = false;
        foreach ($liste as $k) {
            $treffer = (is_array($k) && hash_equals((string)($k['hash'] ?? ''), $h)) || $treffer;
        }
        return $treffer;
    }

    /**
     * @param list<array{hash:string, name:string, at:int}> $liste
     * @return list<array{hash:string, name:string, at:int}>
     */
    public static function Koppeln(array $liste, string $token, string $name, int $jetzt): array
    {
        $name = trim((string)preg_replace('/[^\p{L}\p{N} .,()\/_-]+/u', '', mb_substr($name, 0, 60)));
        $liste[] = ['hash' => hash('sha256', $token), 'name' => $name !== '' ? $name : 'Browser', 'at' => $jetzt];
        return array_values(array_slice($liste, -self::KOPPLUNGEN_MAX));
    }

    public static function Bereit(int $letztesZeichen, int $jetzt): bool
    {
        return $letztesZeichen > 0 && $jetzt - $letztesZeichen <= self::BEREIT_SEKUNDEN;
    }
}

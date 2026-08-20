<?php

declare(strict_types=1);

/**
 * Web Push — Benachrichtigungen an die Web-App, auch wenn sie zu ist.
 *
 * Bis hierher gab es zwei Wege, und beide gehen an der Web-App vorbei:
 * VISU_PostNotification erreicht nur die Kachel-Visualisierung (also die
 * offizielle Symcon-App), und der WebSocket-Klingelton (WsPushDirty) wirkt nur,
 * solange die Seite offen ist. Web Push schliesst die Luecke.
 *
 * Dieser Trait ist der Krypto- und Versandkern. Web Push verlangt zwei Dinge,
 * die beide hier entstehen:
 *
 *  - VAPID (RFC 8292): Der Server weist sich beim Push-Dienst mit einem
 *    ES256-signierten JWT aus. Der oeffentliche Schluessel wandert als
 *    `applicationServerKey` in den Browser, der private bleibt hier.
 *  - Nutzlast-Verschluesselung (RFC 8291): Der Push-Dienst darf den Inhalt NICHT
 *    lesen. Deshalb ECDH mit dem Geraeteschluessel, HKDF, aes128gcm — der Dienst
 *    sieht nur einen Blob.
 *
 * Bewusst OHNE Fremdbibliothek. minishlink/web-push braucht Composer, das ein
 * Store-Modul nicht hat; die noetige Krypto steckt vollstaendig in PHP (openssl,
 * hash_hkdf), gemessen am 20.08.2026: ES256 signiert und verifiziert, ECDH 32
 * Byte, HKDF und aes128gcm im Rundgang bestaetigt.
 *
 * EINE Eigenheit von openssl in PHP: `openssl_pkey_new` mit `curve_name`
 * scheitert auf Systemen ohne auffindbare openssl.cnf mit
 * „configuration file routines::no such file". Deshalb PushOpensslArgs() —
 * erst ohne Konfig, dann OPENSSL_CONF, dann eine kurze Kandidatenliste.
 */
trait WebPush
{
    private const PUSH_VAPID_ATTR = 'PushVapid';

    /** Wie lange der Dienst eine Nachricht aufbewahrt, wenn das Geraet offline ist. */
    private const PUSH_TTL = 86400;

    /** Der Dienst antwortet schnell oder nie — laenger warten hilft niemandem. */
    private const PUSH_TIMEOUT = 10;
    private const PUSH_CONNECT_TIMEOUT = 5;

    /**
     * Deckel fuer die Nutzlast VOR der Verschluesselung. Die Dienste nehmen 4096
     * Byte Datensatzgroesse; abzueglich Kopf (86), Trenner und GCM-Marke bleiben
     * gut 3900. 3072 laesst Luft und ist fuer Titel plus Text reichlich.
     */
    private const PUSH_MAX_BYTES = 3072;

    /** Datensatzgroesse im Kopf des Koerpers. Muss zum Deckel oben passen. */
    private const PUSH_RECORD_SIZE = 4096;

    /** Kappung wie bei den Visu-Nachrichten (ToDoList/module.php): 32 und 256. */
    private const PUSH_TITLE_MAX = 32;
    private const PUSH_BODY_MAX  = 256;

    /** Einmal gefundene openssl-Konfig fuer die Dauer des Laufs. */
    private ?string $pushConf = null;
    private bool $pushConfGeprueft = false;

    // ─────────────────────────── Modul-Haken ───────────────────────────

    private function PushCreate(): void
    {
        $this->RegisterAttributeString(self::PUSH_VAPID_ATTR, '');
    }

    // ────────────────────────── openssl-Ausweg ──────────────────────────

    /**
     * Argumente fuer openssl_pkey_new, inklusive Konfig-Ausweg.
     *
     * Reihenfolge mit Absicht: Auf einem normal eingerichteten System findet
     * openssl seine Konfig selbst, und dann soll nichts festgeschrieben werden.
     * Erst wenn das scheitert, wird gesucht — zuerst dort, wohin die Umgebung
     * zeigt, dann an den ueblichen Orten von Linux, macOS und Homebrew.
     *
     * @return array<string, mixed>|null null = Schluesselerzeugung unmoeglich
     */
    private function PushOpensslArgs(): ?array
    {
        $grund = ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC];

        if ($this->pushConfGeprueft) {
            if ($this->pushConf === '') {
                return $grund;
            }
            return $this->pushConf === null ? null : $grund + ['config' => $this->pushConf];
        }
        $this->pushConfGeprueft = true;

        $kandidaten = array_merge(
            [null],                                  // ohne Konfig — der Normalfall
            [getenv('OPENSSL_CONF') ?: null],
            [
                '/etc/ssl/openssl.cnf',              // Debian, Ubuntu, Docker
                '/usr/lib/ssl/openssl.cnf',
                '/etc/pki/tls/openssl.cnf',          // RHEL, Fedora
                '/private/etc/ssl/openssl.cnf',      // macOS
                '/opt/homebrew/etc/openssl@3/openssl.cnf',
                '/usr/local/etc/openssl@3/openssl.cnf',
            ]
        );
        foreach ($kandidaten as $pfad) {
            $args = $grund;
            if ($pfad !== null) {
                if (!is_string($pfad) || $pfad === '' || !file_exists($pfad)) {
                    continue;
                }
                $args['config'] = $pfad;
            }
            $probe = @openssl_pkey_new($args);
            while (openssl_error_string() !== false) {
                // Fehlerspeicher leeren, sonst tragen spaetere Aufrufe fremde Meldungen.
            }
            if ($probe !== false) {
                $this->pushConf = $pfad ?? '';
                return $args;
            }
        }

        $this->pushConf = null;
        $this->LogMessage(
            'Web Push: OpenSSL kann keinen P-256-Schluessel erzeugen (openssl.cnf nicht gefunden). '
            . 'Bitte OPENSSL_CONF setzen oder einen VAPID-Schluessel hinterlegen.',
            KL_ERROR
        );
        return null;
    }

    // ──────────────────────── Schluessel und Kodierung ────────────────────────

    private function PushB64(string $roh): string
    {
        return rtrim(strtr(base64_encode($roh), '+/', '-_'), '=');
    }

    private function PushUnb64(string $text): string
    {
        $rein = strtr(trim($text), '-_', '+/');
        $roh  = base64_decode($rein . str_repeat('=', (4 - strlen($rein) % 4) % 4), true);
        return is_string($roh) ? $roh : '';
    }

    /**
     * Der oeffentliche Punkt in unkomprimierter Form: 0x04 || X || Y, 65 Byte.
     *
     * X und Y werden auf 32 Byte aufgefuellt: openssl liefert sie ohne fuehrende
     * Nullen, ein 31-Byte-X ergaebe einen Schluessel, den der Browser ablehnt.
     */
    private function PushRawPublic(mixed $schluessel): string
    {
        $det = @openssl_pkey_get_details($schluessel);
        if (!is_array($det) || !isset($det['ec']['x'], $det['ec']['y'])) {
            return '';
        }
        return "\x04"
            . str_pad((string)$det['ec']['x'], 32, "\x00", STR_PAD_LEFT)
            . str_pad((string)$det['ec']['y'], 32, "\x00", STR_PAD_LEFT);
    }

    /**
     * Rohen 65-Byte-Punkt in ein PEM giessen, damit openssl damit rechnen kann.
     *
     * Der Browser liefert seinen Schluessel (`p256dh`) genau so — als rohen Punkt.
     * openssl_pkey_derive will dagegen einen Schluessel. Der Vorsatz ist die
     * feste SubjectPublicKeyInfo fuer prime256v1; nur der Punkt dahinter wechselt.
     */
    private function PushPemFromRawPublic(string $punkt): string
    {
        if (strlen($punkt) !== 65 || $punkt[0] !== "\x04") {
            return '';
        }
        $vorsatz = hex2bin('3059301306072a8648ce3d020106082a8648ce3d03010703420000');
        // Das letzte Byte des Vorsatzes ist das „unbenutzte Bits"-Byte des BIT STRING.
        $der = substr((string)$vorsatz, 0, -1) . $punkt;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /**
     * Rohen 32-Byte-Skalar in ein PEM giessen (SEC1, mit oeffentlichem Teil).
     *
     * Zwei Zwecke: der Harness prueft damit den RFC-Testvektor, und wer seinen
     * VAPID-Schluessel woanders erzeugt hat (weil openssl hier nichts erzeugen
     * kann), kann ihn so hinterlegen.
     */
    private function PushPemFromRawPrivate(string $skalar, string $punkt): string
    {
        if (strlen($skalar) !== 32 || strlen($punkt) !== 65) {
            return '';
        }
        $der = hex2bin('3077020101' . '0420') . $skalar
            . hex2bin('a00a06082a8648ce3d030107' . 'a144034200') . $punkt;
        return "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
    }

    /**
     * Das VAPID-Schluesselpaar. Beim ersten Aufruf erzeugt, danach aus dem Attribut.
     *
     * Der private Schluessel verlaesst diese Ablage nie — nicht ins Formular,
     * nicht ins Protokoll, nicht in eine Debug-Ausgabe.
     *
     * @return array{priv: string, pub: string}|null
     */
    private function PushVapid(): ?array
    {
        $stand = json_decode($this->ReadAttributeStringSafe(self::PUSH_VAPID_ATTR), true);
        if (is_array($stand) && ($stand['priv'] ?? '') !== '' && ($stand['pub'] ?? '') !== '') {
            return ['priv' => (string)$stand['priv'], 'pub' => (string)$stand['pub']];
        }

        $args = $this->PushOpensslArgs();
        if ($args === null) {
            return null;
        }
        $schluessel = @openssl_pkey_new($args);
        if ($schluessel === false) {
            return null;
        }
        $pem = '';
        if (!@openssl_pkey_export($schluessel, $pem, null, $args)) {
            $this->LogMessage('Web Push: VAPID-Schluessel nicht exportierbar.', KL_ERROR);
            return null;
        }
        $paar = ['priv' => $pem, 'pub' => $this->PushB64($this->PushRawPublic($schluessel))];
        if (!$this->PushWriteVapid($paar)) {
            return null;
        }
        return $paar;
    }

    /**
     * Schluesselpaar ablegen, mit Rücklese-Probe.
     *
     * `WriteAttribute*` auf ein noch nicht registriertes Attribut wirft nicht, es
     * tut still nichts (Hauskonvention, siehe BriefingStorable). Ohne die Probe
     * erzeugte jeder Versand ein neues Schluesselpaar — und alle Abos, die noch
     * auf das alte lauten, waeren tot.
     *
     * @param array{priv: string, pub: string} $paar
     */
    private function PushWriteVapid(array $paar): bool
    {
        $text = json_encode($paar, JSON_UNESCAPED_SLASHES);
        try {
            $this->WriteAttributeString(self::PUSH_VAPID_ATTR, (string)$text);
        } catch (Throwable $e) {
            $this->LogMessage('Web Push: Attribut ' . self::PUSH_VAPID_ATTR . ' nicht beschreibbar: ' . $e->getMessage(), KL_ERROR);
            return false;
        }
        if ($this->ReadAttributeStringSafe(self::PUSH_VAPID_ATTR) !== $text) {
            $this->LogMessage(
                'Web Push: Attribut ' . self::PUSH_VAPID_ATTR . ' existiert noch nicht — '
                . 'nach dem naechsten Neustart von IP-Symcon greift es.',
                KL_ERROR
            );
            return false;
        }
        return true;
    }

    /** Der oeffentliche Schluessel fuer den Browser (`applicationServerKey`). */
    private function PushPublicKey(): string
    {
        $paar = $this->PushVapid();
        return $paar === null ? '' : $paar['pub'];
    }

    // ──────────────────────────── VAPID-JWT ────────────────────────────

    /**
     * ES256-Signatur in der Form, die JWS verlangt: r || s, je 32 Byte.
     *
     * openssl signiert als DER-Sequenz zweier INTEGER — mit fuehrender Null, wenn
     * das oberste Bit gesetzt ist, und ohne fuehrende Nullen sonst. Beides muss
     * raus, sonst weist der Push-Dienst das Token ab.
     */
    private function PushDerToRaw(string $der): string
    {
        $pos = 2;
        if (strlen($der) < 8 || $der[0] !== "\x30") {
            return '';
        }
        if (ord($der[1]) > 0x80) {
            $pos = 2 + (ord($der[1]) & 0x7f);
        }
        $teile = [];
        for ($i = 0; $i < 2; $i++) {
            if (($der[$pos] ?? '') !== "\x02") {
                return '';
            }
            $len  = ord($der[$pos + 1]);
            $wert = ltrim(substr($der, $pos + 2, $len), "\x00");
            if (strlen($wert) > 32) {
                return '';
            }
            $teile[] = str_pad($wert, 32, "\x00", STR_PAD_LEFT);
            $pos += 2 + $len;
        }
        return $teile[0] . $teile[1];
    }

    /**
     * Das VAPID-Token fuer EINEN Push-Dienst.
     *
     * `aud` ist die Herkunft des Endpunkts (nicht der ganze Endpunkt!) — ein Token
     * fuer Google gilt nicht bei Apple. `sub` muss eine Kontaktadresse sein, an
     * die sich der Dienst bei Problemen wenden kann.
     */
    private function PushJwt(string $endpunkt, string $kontakt): string
    {
        $paar = $this->PushVapid();
        if ($paar === null) {
            return '';
        }
        $teile = parse_url($endpunkt);
        if (!is_array($teile) || ($teile['host'] ?? '') === '') {
            return '';
        }
        $aud = ($teile['scheme'] ?? 'https') . '://' . $teile['host'];

        $kopf = $this->PushB64((string)json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $satz = $this->PushB64((string)json_encode([
            'aud' => $aud,
            // Zwoelf Stunden: die Dienste erlauben hoechstens 24, und ein knappes
            // Fenster wuerde bei falsch gestellter Uhr sofort scheitern.
            'exp' => time() + 12 * 3600,
            'sub' => $kontakt,
        ], JSON_UNESCAPED_SLASHES));

        $schluessel = @openssl_pkey_get_private($paar['priv']);
        if ($schluessel === false) {
            return '';
        }
        $der = '';
        if (!@openssl_sign($kopf . '.' . $satz, $der, $schluessel, OPENSSL_ALGO_SHA256)) {
            return '';
        }
        $roh = $this->PushDerToRaw($der);
        return $roh === '' ? '' : $kopf . '.' . $satz . '.' . $this->PushB64($roh);
    }

    // ─────────────────────── Nutzlast verschluesseln ───────────────────────

    /**
     * Nutzlast nach RFC 8291 verschluesseln (Inhaltskodierung aes128gcm).
     *
     * Aufbau des Koerpers: Salz (16) | Datensatzgroesse (4) | Laenge des
     * Schluessels (1) | unser Einmalschluessel (65) | Geheimtext. Der Klartext
     * traegt hinten den Trenner 0x02 — ohne ihn verwirft der Browser still.
     *
     * $salz und $eigen sind Prueffugen: Damit spielt der Harness den Testvektor
     * aus RFC 8291 Anhang A nach. Im Betrieb bleiben beide leer, dann entsteht je
     * Nachricht ein frisches Salz und ein frischer Einmalschluessel — Pflicht,
     * denn zweimal dieselbe Kombination aus Schluessel und Nonce bricht GCM.
     *
     * @return array{ok: bool, body?: string, error?: string}
     */
    private function PushEncrypt(string $klartext, string $p256dh, string $auth, ?string $salz = null, mixed $eigen = null): array
    {
        $geraetPunkt = strlen($p256dh) === 65 ? $p256dh : $this->PushUnb64($p256dh);
        $geheimnis   = strlen($auth) === 16 ? $auth : $this->PushUnb64($auth);
        if (strlen($geraetPunkt) !== 65 || strlen($geheimnis) < 16) {
            return ['ok' => false, 'error' => 'bad_subscription'];
        }
        if (strlen($klartext) > self::PUSH_MAX_BYTES) {
            return ['ok' => false, 'error' => 'payload_too_large'];
        }

        if ($eigen === null) {
            $args = $this->PushOpensslArgs();
            if ($args === null) {
                return ['ok' => false, 'error' => 'no_openssl_ec'];
            }
            $eigen = @openssl_pkey_new($args);
            if ($eigen === false) {
                return ['ok' => false, 'error' => 'no_openssl_ec'];
            }
        }
        $eigenPunkt = $this->PushRawPublic($eigen);
        if ($eigenPunkt === '') {
            return ['ok' => false, 'error' => 'no_public_point'];
        }

        $gegen = $this->PushPemFromRawPublic($geraetPunkt);
        if ($gegen === '') {
            return ['ok' => false, 'error' => 'bad_subscription'];
        }
        // Ohne Laengenangabe: PHP 8.5 missbilligt den dritten Parameter, und fuer
        // P-256 ist das Geheimnis ohnehin genau 32 Byte lang.
        $gemeinsam = @openssl_pkey_derive($gegen, $eigen);
        if (!is_string($gemeinsam) || strlen($gemeinsam) !== 32) {
            return ['ok' => false, 'error' => 'ecdh_failed'];
        }

        $salz ??= random_bytes(16);

        // Zwei Stufen HKDF: erst das Geraetegeheimnis mit dem ECDH-Ergebnis
        // verbinden, dann daraus Schluessel und Nonce ziehen. Die Info-Strings
        // stehen genau so im RFC — jedes Byte zaehlt.
        $ikm   = hash_hkdf('sha256', $gemeinsam, 32, "WebPush: info\x00" . $geraetPunkt . $eigenPunkt, $geheimnis);
        $cek   = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salz);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salz);

        $marke   = '';
        $geheim  = openssl_encrypt($klartext . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $marke);
        if (!is_string($geheim)) {
            return ['ok' => false, 'error' => 'encrypt_failed'];
        }

        return ['ok' => true, 'body' => $salz . pack('N', self::PUSH_RECORD_SIZE) . chr(65) . $eigenPunkt . $geheim . $marke];
    }

    // ──────────────────────────── Versand ────────────────────────────

    /**
     * Eine Nachricht an EIN Abo. Der Rueckgabewert sagt, was mit dem Abo zu tun ist.
     *
     * @param array{endpoint: string, p256dh: string, auth: string} $abo
     * @param array<string, mixed> $nutzlast
     * @return array{ok: bool, status: int, gone: bool, error: string}
     */
    private function PushSendOne(array $abo, array $nutzlast, string $kontakt, string $dringlichkeit = 'normal'): array
    {
        $endpunkt = (string)($abo['endpoint'] ?? '');
        if (!str_starts_with($endpunkt, 'https://')) {
            return ['ok' => false, 'status' => 0, 'gone' => true, 'error' => 'bad_endpoint'];
        }

        $klartext = (string)json_encode($nutzlast, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ver = $this->PushEncrypt($klartext, (string)($abo['p256dh'] ?? ''), (string)($abo['auth'] ?? ''));
        if (($ver['ok'] ?? false) !== true) {
            // Ein unbrauchbarer Geraeteschluessel ist ein totes Abo, kein Ausfall.
            $tot = in_array($ver['error'] ?? '', ['bad_subscription'], true);
            return ['ok' => false, 'status' => 0, 'gone' => $tot, 'error' => (string)($ver['error'] ?? 'encrypt_failed')];
        }

        $jwt = $this->PushJwt($endpunkt, $kontakt);
        if ($jwt === '') {
            return ['ok' => false, 'status' => 0, 'gone' => false, 'error' => 'no_vapid'];
        }

        $antwort = $this->PushHttp($endpunkt, (string)$ver['body'], [
            'Content-Encoding: aes128gcm',
            'Content-Type: application/octet-stream',
            'TTL: ' . self::PUSH_TTL,
            'Urgency: ' . $dringlichkeit,
            'Authorization: vapid t=' . $jwt . ',k=' . $this->PushPublicKey(),
        ]);

        $status = (int)$antwort['status'];
        // 404/410 heisst: dieses Geraet gibt es nicht mehr. Alles andere ist ein
        // Ausfall — das Abo bleibt, der Fehlerzaehler entscheidet spaeter.
        return [
            'ok'     => $status >= 200 && $status < 300,
            'status' => $status,
            'gone'   => in_array($status, [404, 410], true),
            'error'  => (string)$antwort['err'],
        ];
    }

    /**
     * POST an den Push-Dienst. Eigener Helfer statt AiHttpPost, weil der Koerper
     * hier binaer ist und keine JSON-Kopfzeile tragen darf.
     *
     * @param list<string> $kopfzeilen
     * @return array{status: int, err: string}
     */
    private function PushHttp(string $url, string $koerper, array $kopfzeilen): array
    {
        $c = curl_init($url);
        curl_setopt_array($c, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $koerper,
            CURLOPT_HTTPHEADER     => $kopfzeilen,
            CURLOPT_TIMEOUT        => self::PUSH_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::PUSH_CONNECT_TIMEOUT,
            // Ein Push-Dienst leitet nicht um; einer Umleitung zu folgen hiesse,
            // die verschluesselte Nutzlast irgendwohin zu tragen.
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $ergebnis = curl_exec($c);
        $status   = (int)curl_getinfo($c, CURLINFO_RESPONSE_CODE);
        $fehler   = curl_error($c);
        curl_close($c);
        unset($ergebnis);
        return ['status' => $status, 'err' => $fehler];
    }

    /**
     * Titel und Text auf das Mass der Meldungsflaeche bringen.
     *
     * Dieselben Grenzen wie bei den Visu-Nachrichten (32/256): Was dort passt,
     * passt auch in eine Systembenachrichtigung, und beide Wege sollen dasselbe
     * zeigen.
     *
     * @return array{title: string, body: string}
     */
    private function PushTrim(string $titel, string $text): array
    {
        return [
            'title' => mb_substr(trim($titel), 0, self::PUSH_TITLE_MAX),
            'body'  => mb_substr(trim($text), 0, self::PUSH_BODY_MAX),
        ];
    }
}

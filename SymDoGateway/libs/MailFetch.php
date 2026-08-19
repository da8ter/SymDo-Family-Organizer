<?php

declare(strict_types=1);

/**
 * Anhaenge aus einem IMAP-Postfach holen — der Teil, den Symcons Kernmodul nicht kann.
 *
 * Das Kernmodul „E-Mail, Empfangen (IMAP)" liefert genau vier Funktionen
 * (GetCachedMails, GetMailEx, DeleteMail, UpdateCache) und darunter EINEN
 * dekodierten Textteil. Anhaenge gibt es dort nicht — gemessen an
 * IPS_GetFunctionListByModuleID, nicht der Doku entnommen. Gerade Elternbriefe und
 * Behoerdenpost tragen die eigentliche Information aber im PDF.
 *
 * Deshalb hier ein eigener, absichtlich sehr schmaler IMAP-Zugriff: verbinden,
 * Struktur der EINEN Mail holen, den Anhang-Teil holen, Verbindung zu. Kein Ersatz
 * fuer das Kernmodul — das bleibt fuer Postfach-Ueberwachung und Kopfdaten
 * zustaendig, hier wird nur nachgeschlagen.
 *
 * Zwei Vorsichtsmassnahmen tragen das Ganze:
 *  - **EXAMINE statt SELECT** und **BODY.PEEK statt BODY**: der Zugriff kann das
 *    Postfach nicht veraendern, insbesondere kein \Seen setzen. Das Kernmodul
 *    setzt es auch nicht — beide Wege bleiben damit rueckwirkungsfrei.
 *  - **Groesse VOR dem Holen pruefen**: BODYSTRUCTURE nennt die kodierte
 *    Byte-Zahl. Symcons PHP laeuft mit memory_limit 32 MB; ein 20-MB-Anhang
 *    wuerde den Prozess erledigen, bevor die KI ihn ueberhaupt sieht.
 *
 * Zugangsdaten kommen aus der IMAP-Instanz, die der Nutzer eingerichtet hat. Sie
 * werden nie protokolliert.
 */
trait MailFetch
{
    private const MAIL_IMAP_CONNECT_TIMEOUT = 6;
    private const MAIL_IMAP_TIMEOUT         = 20;

    /**
     * Obergrenze fuer den kodierten Anhang (8 MB base64 ≈ 6 MB Datei).
     *
     * An einer echten Kita-Mail gemessen: das angehaengte Formular wog 5,49 MB
     * base64. Ein Limit von 4 MB haette genau den Hauptfall ausgeschlossen.
     * Weiter unter AI_MAX_PDF_B64 (20 MB), denn dieser Weg laeuft im Timer und
     * traegt gleichzeitig Puffer, bereinigte Kopie und JSON-Rumpf — deshalb hebt
     * MailAnalyse fuer diese Strecke zusaetzlich das memory_limit an.
     */
    private const MAIL_ATTACH_MAX_B64 = 8 * 1024 * 1024;

    /**
     * Untergrenze fuer BILDER. Ein Logo aus einer Signatur wiegt 2 bis 30 kB; eine
     * abfotografierte oder eingescannte A4-Seite liegt weit darueber (gemessen:
     * eine gerenderte Textseite mit 1600 px Breite als JPEG = 147 kB). Darunter
     * kann kein lesbarer Brief stecken, also gar nicht erst laden.
     * PDFs haben bewusst KEINE Untergrenze — in Signaturen stehen keine PDFs.
     */
    private const MAIL_IMAGE_MIN_BYTES = 40 * 1024;

    /** Kleinste Kantenlaenge, in der Fliesstext noch lesbar ist (nach dem Laden geprueft). */
    private const MAIL_IMAGE_MIN_PIXEL = 600;

    /** Dateinamen, die Absender ihren Layout- und Signaturbildern geben. */
    private const MAIL_DECO_NAMES = ['image00', 'logo', 'signatur', 'signature', 'icon', 'spacer', 'footer', 'unnamed', 'banner'];

    /** Kleine Literale (Dateinamen) werden in die Struktur eingesetzt, grosse ausgelagert. */
    private const MAIL_LITERAL_INLINE_MAX = 1024;

    /**
     * Holt den brauchbarsten Anhang einer Mail.
     *
     * @return array{kind: string, base64: string, name: string}|null
     *         kind ist 'pdf' oder 'image'; null, wenn es keinen gibt oder etwas scheitert.
     */
    private function MailFetchAttachment(int $imapID, string $uid): ?array
    {
        $cfg = $this->MailImapConfig($imapID);
        if ($cfg === null) {
            return null;
        }
        // Ohne SSL ginge das Passwort im Klartext ueber die Leitung — STARTTLS
        // handelt dieser schmale Zugriff nicht aus. Dann lieber ohne Anhang
        // analysieren (wie bei MailReadAttachments=false), statt die Zugangsdaten
        // preiszugeben, nur weil die IMAP-Instanz unverschluesselt eingestellt ist.
        if ($cfg['auth'] && !$cfg['ssl']) {
            $this->SendDebug('MailFetch', 'IMAP-Instanz ohne SSL — Anhang-Abruf entfaellt, Analyse nur mit Mailtext', 0);
            return null;
        }
        $fh = $this->MailImapOpen($cfg);
        if ($fh === null) {
            return null;
        }
        try {
            $nr = 0;
            if ($cfg['auth']) {
                // Zeilenumbrueche in Zugangsdaten wuerden als eigene IMAP-Befehle
                // gelesen (Injektion in die eigene Sitzung) — solche Daten sind
                // ohnehin kaputt, also gar nicht erst senden.
                if (preg_match('/[\r\n]/', $cfg['user'] . $cfg['pass']) === 1) {
                    $this->SendDebug('MailFetch', 'Zugangsdaten enthalten Zeilenumbrueche — Anhang-Abruf entfaellt', 0);
                    return null;
                }
                $login = $this->MailImapCmd($fh, $nr, sprintf(
                    'LOGIN "%s" "%s"',
                    $this->MailImapQuote($cfg['user']),
                    $this->MailImapQuote($cfg['pass'])
                ));
                if (!$login['ok']) {
                    // Bewusst ohne Zugangsdaten im Text.
                    $this->SendDebug('MailFetch', 'Anmeldung abgelehnt: ' . $login['status'], 0);
                    return null;
                }
            }
            // EXAMINE, nicht SELECT: nur lesen, keine Flag-Aenderung moeglich.
            if (!$this->MailImapCmd($fh, $nr, 'EXAMINE "INBOX"')['ok']) {
                $this->SendDebug('MailFetch', 'INBOX nicht lesbar', 0);
                return null;
            }

            $struktur = $this->MailImapCmd($fh, $nr, 'UID FETCH ' . $this->MailImapUid($uid) . ' (BODYSTRUCTURE)');
            if (!$struktur['ok']) {
                $this->SendDebug('MailFetch', 'BODYSTRUCTURE fehlgeschlagen: ' . $struktur['status'], 0);
                return null;
            }
            $teile = $this->MailStructureParts($struktur['daten']);
            $wahl  = $this->MailPickAttachment($teile);
            if ($wahl === null) {
                $this->SendDebug('MailFetch', sprintf('UID %s: kein verwertbarer Anhang (%d Teile)', $uid, count($teile)), 0);
                return null;
            }

            $inhalt = $this->MailImapCmd($fh, $nr, sprintf(
                'UID FETCH %s (BODY.PEEK[%s])',
                $this->MailImapUid($uid),
                $wahl['part']
            ));
            if (!$inhalt['ok'] || ($inhalt['literale'][0] ?? '') === '') {
                $this->SendDebug('MailFetch', 'Anhang nicht abholbar: ' . $inhalt['status'], 0);
                return null;
            }
            $base64 = $this->MailToBase64((string)$inhalt['literale'][0], $wahl['encoding']);
            // Rohpuffer sofort freigeben: er ist genauso gross wie die Kopie, und
            // json_encode braucht den Platz gleich selbst.
            unset($inhalt);
            if ($base64 === '') {
                return null;
            }
            // Letzte Wache, und die genaueste: die echten Kantenlaengen. Ein Bild
            // unter MAIL_IMAGE_MIN_PIXEL traegt keinen lesbaren Fliesstext, egal
            // wie schwer die Datei ist — ein grosses, aber winziges Logo (etwa ein
            // wenig komprimiertes Emblem) faellt erst hier auf. Die Bytes liegen
            // ohnehin vor, die Pruefung kostet also nur den Dekodierschritt.
            if ($wahl['kind'] === 'image') {
                $roh = base64_decode($base64, true);
                $masse = is_string($roh) ? @getimagesizefromstring($roh) : false;
                unset($roh);
                if (is_array($masse) && (min((int)$masse[0], (int)$masse[1]) < self::MAIL_IMAGE_MIN_PIXEL)) {
                    $this->SendDebug('MailFetch', sprintf(
                        'Bild %s verworfen: %dx%d Pixel, dafuer zu klein',
                        $wahl['name'] !== '' ? $wahl['name'] : $wahl['part'], (int)$masse[0], (int)$masse[1]
                    ), 0);
                    return null;
                }
            }
            $this->SendDebug('MailFetch', sprintf(
                'UID %s: Anhang %s (%s/%s, Teil %s, %d kB base64)',
                $uid, $wahl['name'] !== '' ? $wahl['name'] : '(ohne Namen)',
                $wahl['type'], $wahl['subtype'], $wahl['part'], (int)round(strlen($base64) / 1024)
            ), 0);
            return ['kind' => $wahl['kind'], 'base64' => $base64, 'name' => $wahl['name']];
        } catch (Throwable $e) {
            $this->SendDebug('MailFetch', 'Fehler: ' . $e->getMessage(), 0);
            return null;
        } finally {
            @$this->MailImapCmd($fh, $nr, 'LOGOUT');
            @fclose($fh);
        }
    }

    // ────────────────────────────── Verbindung ──────────────────────────────

    /** @return array<string, mixed>|null */
    private function MailImapConfig(int $imapID): ?array
    {
        $cfg = json_decode((string)@IPS_GetConfiguration($imapID), true);
        if (!is_array($cfg)) {
            return null;
        }
        $host = trim((string)($cfg['Host'] ?? ''));
        if ($host === '') {
            return null;
        }
        return [
            'host'       => $host,
            'port'       => (int)($cfg['Port'] ?? 993),
            'ssl'        => (bool)($cfg['UseSSL'] ?? true),
            'verifyPeer' => (bool)($cfg['VerifyPeer'] ?? true),
            'verifyHost' => (bool)($cfg['VerifyHost'] ?? true),
            'auth'       => (bool)($cfg['UseAuthentication'] ?? true),
            'user'       => (string)($cfg['Username'] ?? ''),
            'pass'       => (string)($cfg['Password'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $cfg */
    private function MailImapOpen(array $cfg): mixed
    {
        // Zertifikatspruefung genau so, wie sie an der IMAP-Instanz eingestellt ist —
        // hier eigene Regeln zu erfinden waere eine stille Abweichung.
        $ctx = stream_context_create(['ssl' => [
            'verify_peer'       => (bool)$cfg['verifyPeer'],
            'verify_peer_name'  => (bool)$cfg['verifyHost'],
            'SNI_enabled'       => true,
            'peer_name'         => (string)$cfg['host'],
            'allow_self_signed' => !(bool)$cfg['verifyPeer'],
        ]]);
        $url = ((bool)$cfg['ssl'] ? 'ssl://' : 'tcp://') . $cfg['host'] . ':' . (int)$cfg['port'];
        $fh  = @stream_socket_client($url, $errno, $errstr, self::MAIL_IMAP_CONNECT_TIMEOUT, STREAM_CLIENT_CONNECT, $ctx);
        if ($fh === false) {
            $this->SendDebug('MailFetch', sprintf('Verbindung zu %s fehlgeschlagen: %s', $url, (string)$errstr), 0);
            return null;
        }
        stream_set_timeout($fh, self::MAIL_IMAP_TIMEOUT);
        // Begruessung abholen, sonst laeuft sie in die erste Antwort.
        fgets($fh, 8192);
        return $fh;
    }

    private function MailImapQuote(string $wert): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $wert);
    }

    /** UIDs kommen vom Kernmodul als String; nur Ziffern durchlassen (Injektionsschutz). */
    private function MailImapUid(string $uid): string
    {
        $sauber = preg_replace('/\D+/', '', $uid) ?? '';
        return $sauber !== '' ? $sauber : '0';
    }

    /**
     * @return array{ok: bool, daten: string, status: string, literale: list<string>}
     */
    private function MailImapCmd(mixed $fh, int &$nr, string $cmd): array
    {
        $tag = 'S' . str_pad((string)(++$nr), 3, '0', STR_PAD_LEFT);
        @fwrite($fh, $tag . ' ' . $cmd . "\r\n");
        return $this->MailImapRead($fh, $tag);
    }

    /**
     * Liest bis zur getaggten Abschlusszeile.
     *
     * Literale ({n} am Zeilenende) werden nach Groesse getrennt behandelt: kleine
     * wandern als Zeichenkette in die Struktur (Dateinamen), grosse in `literale`.
     * Damit existiert die Anhangsnutzlast genau EINMAL im Speicher.
     *
     * @return array{ok: bool, daten: string, status: string, literale: list<string>}
     */
    private function MailImapRead(mixed $fh, string $tag): array
    {
        $daten    = '';
        $status   = '';
        $literale = [];
        $ende     = time() + self::MAIL_IMAP_TIMEOUT;

        while (time() < $ende) {
            $zeile = fgets($fh, 8192);
            if ($zeile === false) {
                break;
            }
            // fgets kappt bei 8191 Bytes mitten in der Zeile — eine sehr lange
            // BODYSTRUCTURE-Zeile koennte sonst den {n}-Literal-Marker zerteilen
            // und die Antwort wuerde als Zeilenfolge fehlgeparst. Bis zum echten
            // Zeilenende weiterlesen, mit Deckel gegen entartete Antworten.
            while (!str_ends_with($zeile, "\n") && strlen($zeile) < 512 * 1024 && time() < $ende) {
                $mehr = fgets($fh, 8192);
                if ($mehr === false) {
                    break;
                }
                $zeile .= $mehr;
            }
            if (preg_match('/\{(\d+)\}\r?\n$/', $zeile, $m) === 1) {
                $daten .= substr($zeile, 0, -strlen($m[0]));
                $laenge = (int)$m[1];
                if ($laenge > self::MAIL_ATTACH_MAX_B64) {
                    return ['ok' => false, 'daten' => '', 'status' => 'literal ' . $laenge . ' zu gross', 'literale' => []];
                }
                $puffer = '';
                $rest   = $laenge;
                // Auch hier gegen die Frist pruefen: ein troepfelnder Server haelt
                // sonst den Timer-Thread weit ueber MAIL_IMAP_TIMEOUT hinaus fest
                // (der Stream-Timeout greift nur je fread, nicht insgesamt).
                while ($rest > 0 && time() < $ende) {
                    $stueck = fread($fh, min(65536, $rest));
                    if ($stueck === false || $stueck === '') {
                        break;
                    }
                    $puffer .= $stueck;
                    $rest   -= strlen($stueck);
                }
                if ($laenge <= self::MAIL_LITERAL_INLINE_MAX) {
                    $daten .= '"' . $this->MailImapQuote($puffer) . '"';
                } else {
                    $daten .= '""';
                    $literale[] = $puffer;
                }
                continue;
            }
            if (str_starts_with($zeile, $tag . ' ')) {
                $status = trim(substr($zeile, strlen($tag) + 1));
                break;
            }
            $daten .= $zeile;
        }

        return [
            'ok'       => str_starts_with(strtoupper($status), 'OK'),
            'daten'    => $daten,
            'status'   => $status !== '' ? $status : 'keine Antwort',
            'literale' => $literale,
        ];
    }

    // ────────────────────────────── BODYSTRUCTURE ──────────────────────────────

    /**
     * Zerlegt die BODYSTRUCTURE-Antwort in eine flache Teileliste mit IMAP-Teilnummern.
     *
     * @return list<array{part: string, type: string, subtype: string, encoding: string, size: int, name: string, attachment: bool}>
     */
    private function MailStructureParts(string $antwort): array
    {
        $pos = strpos($antwort, 'BODYSTRUCTURE');
        if ($pos === false) {
            return [];
        }
        $i = $pos + strlen('BODYSTRUCTURE');
        $baum = $this->MailParseSexp($antwort, $i);
        if (!is_array($baum)) {
            return [];
        }
        $raus = [];
        $this->MailWalkParts($baum, '', $raus);
        return $raus;
    }

    /** IMAP-Klammerausdruck: Listen, Zeichenketten in Anfuehrungszeichen, Atome, NIL. */
    private function MailParseSexp(string $s, int &$i): mixed
    {
        $n = strlen($s);
        while ($i < $n && ($s[$i] === ' ' || $s[$i] === "\r" || $s[$i] === "\n")) {
            $i++;
        }
        if ($i >= $n) {
            return null;
        }
        if ($s[$i] === '(') {
            $i++;
            $liste = [];
            while ($i < $n && count($liste) < 300) {
                while ($i < $n && ($s[$i] === ' ' || $s[$i] === "\r" || $s[$i] === "\n")) {
                    $i++;
                }
                if ($i >= $n || $s[$i] === ')') {
                    $i++;
                    break;
                }
                $liste[] = $this->MailParseSexp($s, $i);
            }
            return $liste;
        }
        if ($s[$i] === '"') {
            $i++;
            $wert = '';
            while ($i < $n) {
                if ($s[$i] === '\\' && $i + 1 < $n) {
                    $wert .= $s[$i + 1];
                    $i += 2;
                    continue;
                }
                if ($s[$i] === '"') {
                    $i++;
                    break;
                }
                $wert .= $s[$i++];
            }
            return $wert;
        }
        $start = $i;
        while ($i < $n && !in_array($s[$i], [' ', '(', ')', "\r", "\n"], true)) {
            $i++;
        }
        $atom = substr($s, $start, $i - $start);
        return strcasecmp($atom, 'NIL') === 0 ? null : $atom;
    }

    /**
     * Ein Knoten ist mehrteilig, wenn sein erstes Element selbst eine Liste ist —
     * dann sind die fuehrenden Listen die Kinder und werden ab 1 durchnummeriert.
     *
     * @param list<array> $raus
     */
    private function MailWalkParts(array $knoten, string $prefix, array &$raus): void
    {
        if (isset($knoten[0]) && is_array($knoten[0])) {
            $nr = 0;
            foreach ($knoten as $kind) {
                if (!is_array($kind)) {
                    break; // ab hier kommen Subtyp und Parameter des Mehrteilers
                }
                $nr++;
                $this->MailWalkParts($kind, $prefix === '' ? (string)$nr : $prefix . '.' . $nr, $raus);
            }
            return;
        }

        $typ    = strtolower((string)($knoten[0] ?? ''));
        $sub    = strtolower((string)($knoten[1] ?? ''));
        $params = is_array($knoten[2] ?? null) ? $knoten[2] : [];
        $name   = (string)$this->MailParamValue($params, 'name');
        $anhang = false;
        $inline = false;
        // Feld 4 ist die Content-ID. Traegt ein Bild eine, wird es vom HTML-Teil
        // ueber „cid:" eingebettet — also Layout oder Signatur und nie der Brief,
        // um den es geht.
        $cid = trim((string)($knoten[3] ?? ''));

        // Disposition steht in den Erweiterungsfeldern; ihr Index haengt am Typ,
        // deshalb wird der Bereich abgesucht statt eine feste Stelle geraten.
        for ($k = 7; $k <= 11; $k++) {
            $e = $knoten[$k] ?? null;
            if (is_array($e) && is_string($e[0] ?? null) && preg_match('/^(attachment|inline)$/i', $e[0]) === 1) {
                $anhang = strcasecmp((string)$e[0], 'attachment') === 0;
                $inline = !$anhang;
                $dp    = is_array($e[1] ?? null) ? $e[1] : [];
                $dname = (string)$this->MailParamValue($dp, 'filename');
                if ($dname === '') {
                    // Apple Mail schickt „filename*" nach RFC 2231:
                    // utf-8''%F0%9F%8C%88%20Abfrage… — Zeichensatz, Sprache, Wert.
                    $stern = (string)$this->MailParamValue($dp, 'filename*');
                    if ($stern !== '') {
                        $st = explode("'", $stern);
                        $dname = rawurldecode(count($st) >= 3 ? implode("'", array_slice($st, 2)) : $stern);
                    }
                }
                if ($dname !== '') {
                    $name = $dname;
                }
                break;
            }
        }

        $raus[] = [
            'part'       => $prefix === '' ? '1' : $prefix,
            'type'       => $typ,
            'subtype'    => $sub,
            'encoding'   => strtolower((string)($knoten[5] ?? '')),
            'size'       => (int)($knoten[6] ?? 0),
            'name'       => $this->MailDecodeHeader($name),
            'attachment' => $anhang,
            'inline'     => $inline,
            'cid'        => $cid,
        ];
    }

    /** Flache Parameterliste [k, v, k, v] durchsuchen. */
    private function MailParamValue(array $params, string $schluessel): ?string
    {
        $anzahl = count($params);
        for ($i = 0; $i + 1 < $anzahl; $i += 2) {
            if (is_string($params[$i]) && strcasecmp($params[$i], $schluessel) === 0) {
                return is_string($params[$i + 1]) ? $params[$i + 1] : null;
            }
        }
        return null;
    }

    /** =?utf-8?B?…?= in Dateinamen auflösen; ohne Kodierung unverändert. */
    private function MailDecodeHeader(string $wert): string
    {
        if ($wert === '' || !str_contains($wert, '=?')) {
            return $wert;
        }
        $klar = @iconv_mime_decode($wert, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        return is_string($klar) && $klar !== '' ? $klar : $wert;
    }

    /**
     * Waehlt den Anhang, mit dem die KI etwas anfangen kann: PDF zuerst, dann Bild.
     *
     * @param list<array> $teile
     * @return array{part: string, type: string, subtype: string, encoding: string, name: string, kind: string}|null
     */
    private function MailPickAttachment(array $teile): ?array
    {
        $kandidaten = [];
        foreach ($teile as $t) {
            $kind = null;
            if ($t['type'] === 'application' && $t['subtype'] === 'pdf') {
                $kind = 'pdf';
            } elseif ($t['type'] === 'application' && preg_match('/\.pdf$/i', $t['name']) === 1) {
                // Manche Absender schicken PDFs als application/octet-stream.
                $kind = 'pdf';
            } elseif ($t['type'] === 'image' && in_array($t['subtype'], ['jpeg', 'jpg'], true)) {
                // Bewusst NUR JPEG: der gemeinsame KI-Pfad deklariert Bilder hart als
                // image/jpeg (AiRunProviderCall). Ein PNG dort einzureichen waere eine
                // falsche Etikettierung, die Anthropic zurueckweist.
                $kind = 'image';
            }
            if ($kind === null) {
                continue;
            }
            $bezeichnung = $t['name'] !== '' ? $t['name'] : $t['part'];
            if ($t['size'] > self::MAIL_ATTACH_MAX_B64) {
                $this->SendDebug('MailFetch', sprintf(
                    'Anhang %s uebersprungen: %d kB ueberschreiten das Limit',
                    $bezeichnung, (int)round($t['size'] / 1024)
                ), 0);
                continue;
            }
            // Ab hier die Aussortierung von Signatur- und Layoutbildern. Alles
            // laeuft VOR dem Laden — ein Firmenlogo soll weder Bandbreite noch
            // Rechenzeit der KI kosten. PDFs sind davon ausgenommen: die stecken
            // nicht in Signaturen.
            if ($kind === 'image') {
                // Eingebettet ins HTML (inline MIT Content-ID) — das ist Layout.
                // Beides zusammen, weil manche Absender auch echte Anhaenge als
                // „inline" deklarieren; die tragen dann aber keine Content-ID.
                if ($t['inline'] === true && ($t['cid'] ?? '') !== '') {
                    $this->SendDebug('MailFetch', sprintf('Bild %s uebersprungen: im Text eingebettet (Content-ID)', $bezeichnung), 0);
                    continue;
                }
                if ($t['size'] < self::MAIL_IMAGE_MIN_BYTES) {
                    $this->SendDebug('MailFetch', sprintf(
                        'Bild %s uebersprungen: nur %d kB, dafuer zu klein',
                        $bezeichnung, (int)round($t['size'] / 1024)
                    ), 0);
                    continue;
                }
            }
            $kandidaten[] = $t + ['kind' => $kind, 'deko' => $kind === 'image' && $this->MailLooksDecorative($t['name'])];
        }
        if ($kandidaten === []) {
            return null;
        }
        // PDF gewinnt gegen Bild; Bilder mit typischem Layout-Namen („image001",
        // „logo") rutschen dahinter — aber sie fliegen nicht raus, denn ein Scan
        // darf „logo_briefkopf.jpg" heissen. Zuletzt entscheidet die Groesse:
        // Signaturbilder sind klein, der eigentliche Brief ist es nicht.
        usort($kandidaten, static function (array $a, array $b): int {
            $rang = static fn(array $x): int => $x['kind'] === 'pdf' ? 0 : ($x['deko'] ? 2 : 1);
            return [$rang($a), -$a['size']] <=> [$rang($b), -$b['size']];
        });
        return $kandidaten[0];
    }

    /** Traegt der Dateiname eines Bildes die Handschrift eines Layout- oder Signaturbildes? */
    private function MailLooksDecorative(string $name): bool
    {
        $klein = strtolower(trim($name));
        if ($klein === '') {
            return true; // namenlose Bilder sind fast immer eingebettete Grafiken
        }
        foreach (self::MAIL_DECO_NAMES as $muster) {
            if (str_contains($klein, $muster)) {
                return true;
            }
        }
        return false;
    }

    /** Rohnutzlast in reines base64 bringen — unabhaengig von der Transportkodierung. */
    private function MailToBase64(string $roh, string $encoding): string
    {
        if ($encoding === 'base64') {
            // Schon base64, nur die Zeilenumbrueche des Transports entfernen.
            return str_replace(["\r", "\n", ' ', "\t"], '', $roh);
        }
        if ($encoding === 'quoted-printable') {
            return base64_encode(quoted_printable_decode($roh));
        }
        // 7bit/8bit/binary: unverändert kodieren.
        return base64_encode($roh);
    }
}

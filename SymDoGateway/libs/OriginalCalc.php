<?php

declare(strict_types=1);

/**
 * Das Original eines KI-Vorschlags — der IPS-freie Teil (24.09.2026).
 *
 * Bis heute war der Text, den die KI gelesen hatte, nach der Auswertung weg.
 * Jetzt bleibt er: als eigener Ordner je Original (Text plus die Anhaenge, die
 * durch die Auswertung liefen), so lange wie der Vorschlag — und mit „Original
 * speichern" so lange wie der Eintrag, der daraus wurde.
 *
 * Nur TEXT, kein HTML: eine neue Strecke vorbei an EduLesen::EduHtml waere
 * fremder Code im innerHTML der App. Angezeigt wird er mit notizTextHtml, das
 * zuerst escaped.
 *
 * Hier stehen die Regeln — Kennung, Satz, Anhangsart, Briefpapier-Erkennung,
 * Aufraeumen —, damit der Pruefstand sie ohne Symcon und ohne Dateisystem
 * fahren kann (tests/OriginalTest.php).
 */
final class OriginalCalc
{
    public const KENNUNG_MUSTER = '/^[0-9a-f]{24}$/';
    public const TEXT_MAX_BYTES = 32768;
    /** Harte Obergrenze je Original; die Einstellung (AttMaxCount) liegt darunter. */
    public const ANHAENGE_MAX = 10;
    public const NAME_MAX = 120;
    public const BILD_KANTE = 1600;
    public const GEDAECHTNIS_MAX = 500;

    /**
     * Die Kennung eines Originals: aus Vorschlag und Textanfang, damit eine
     * wiederverwendete IMAP-UID (Postfach geleert, Konto getauscht) kein fremdes
     * Original ueberschreibt, auf das ein gespeicherter Eintrag zeigt.
     */
    public static function Kennung(string $vorschlagsId, string $text, int $textAnfang): string
    {
        return substr(sha1("symdo-original\0" . $vorschlagsId . "\0" . mb_substr($text, 0, $textAnfang)), 0, 24);
    }

    /** Eine frische Kennung fuer ein abgeleitetes Original (Eintrag mit Auswahl). */
    public static function NeueKennung(): string
    {
        return bin2hex(random_bytes(12));
    }

    public static function KennungGueltig(mixed $id): bool
    {
        return is_string($id) && preg_match(self::KENNUNG_MUSTER, $id) === 1;
    }

    /** Gueltiges UTF-8, einheitliche Zeilenenden, kein NUL, hoechstens TEXT_MAX_BYTES. */
    public static function TextSauber(string $text): string
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            // Alte Mails ohne Zeichensatzangabe sind fast immer Latin-1.
            $text = mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
        }
        $text = str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], $text);
        return trim(mb_strcut($text, 0, self::TEXT_MAX_BYTES, 'UTF-8'));
    }

    /** pdf/jpg/png — ausschliesslich an den Magic Bytes, nie am Namen. */
    public static function AnhangArt(string $roh): string
    {
        if (str_starts_with($roh, '%PDF-')) {
            return 'pdf';
        }
        if (str_starts_with($roh, "\xFF\xD8\xFF")) {
            return 'jpg';
        }
        if (str_starts_with($roh, "\x89PNG\r\n\x1a\n")) {
            return 'png';
        }
        return '';
    }

    public static function Mime(string $art): string
    {
        return match ($art) {
            'pdf'   => 'application/pdf',
            'jpg'   => 'image/jpeg',
            'png'   => 'image/png',
            default => 'application/octet-stream',
        };
    }

    /** Ein Anzeigename ohne Pfad und Steuerzeichen; leer ⇒ „Anhang n.pdf". */
    public static function NameSauber(string $name, string $art, int $n): string
    {
        $name = str_replace(['\\', "\0"], ['/', ''], $name);
        $name = basename($name);
        $name = trim(preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '');
        if (!mb_check_encoding($name, 'UTF-8')) {
            $name = mb_convert_encoding($name, 'UTF-8', 'ISO-8859-1');
        }
        if ($name === '' || $name === '.' || $name === '..') {
            return 'Anhang ' . $n . '.' . ($art !== '' ? $art : 'bin');
        }
        return mb_substr($name, 0, self::NAME_MAX);
    }

    /**
     * Sieht ein Anhang nach Briefpapier oder Logo aus? Dann steht er im Dialog
     * NICHT vorgehakt — rausgeworfen wird er nicht, ein Scan darf
     * „logo_briefkopf.jpg" heissen.
     *
     * - PDFs nie: in Signaturen stehen keine PDFs.
     * - Ein Bild ohne Namen oder mit typischem Layout-Namen (dieselbe Regel wie
     *   MailLooksDecorative).
     * - Ein Bild, das schon in einem anderen Original stand: ab dem
     *   `$wiederholung`-ten Auftreten (0 = diese Regel aus).
     *
     * @param list<string> $decoNamen
     */
    public static function IstDeko(string $name, string $art, int $schonGesehen, array $decoNamen, int $wiederholung): bool
    {
        if ($art === 'pdf') {
            return false;
        }
        $klein = mb_strtolower(trim($name));
        if ($klein === '') {
            return true;
        }
        foreach ($decoNamen as $muster) {
            if ($muster !== '' && str_contains($klein, $muster)) {
                return true;
            }
        }
        return $wiederholung > 0 && $schonGesehen >= $wiederholung - 1;
    }

    /**
     * Das Bildgedaechtnis fortschreiben: Pruefsumme ⇒ [Zaehler, zuletzt gesehen].
     * Gedeckelt auf `$max` Eintraege; es fallen die am laengsten nicht gesehenen.
     *
     * @param array<string,array{0:int,1:int}> $karte
     * @param list<string>                     $hashes einmal je Original
     * @return array<string,array{0:int,1:int}>
     */
    public static function Gedaechtnis(array $karte, array $hashes, int $jetzt, int $max = self::GEDAECHTNIS_MAX): array
    {
        foreach (array_unique($hashes) as $h) {
            if (!is_string($h) || preg_match('/^[0-9a-f]{40}$/', $h) !== 1) {
                continue;
            }
            $alt = is_array($karte[$h] ?? null) ? $karte[$h] : [0, 0];
            $karte[$h] = [(int)($alt[0] ?? 0) + 1, $jetzt];
        }
        if (count($karte) > $max) {
            uasort($karte, static fn($a, $b): int => (int)($b[1] ?? 0) <=> (int)($a[1] ?? 0));
            $karte = array_slice($karte, 0, $max, true);
        }
        return $karte;
    }

    /** Wie oft war ein Bild schon da? */
    public static function SchonGesehen(array $karte, string $hash): int
    {
        return is_array($karte[$hash] ?? null) ? (int)($karte[$hash][0] ?? 0) : 0;
    }

    /** In wie viele Teile zerfaellt eine Datei bei dieser Stueckgroesse? */
    public static function Teile(int $bytes, int $stueck): int
    {
        return $stueck > 0 ? max(1, (int)ceil($bytes / $stueck)) : 1;
    }

    /**
     * Der Datensatz `original.json`.
     *
     * @param array<string,mixed> $e
     * @return array<string,mixed>
     */
    public static function Satz(array $e): array
    {
        $atts = [];
        foreach ((array)($e['atts'] ?? []) as $a) {
            if (!is_array($a) || !in_array((string)($a['kind'] ?? ''), ['pdf', 'jpg', 'png'], true)) {
                continue;
            }
            $n = (int)($a['n'] ?? 0);
            if ($n < 1 || $n > self::ANHAENGE_MAX) {
                continue;
            }
            $atts[] = [
                'n'     => $n,
                'name'  => self::NameSauber((string)($a['name'] ?? ''), (string)$a['kind'], $n),
                'kind'  => (string)$a['kind'],
                'bytes' => max(0, (int)($a['bytes'] ?? 0)),
                'deko'  => ($a['deko'] ?? false) === true,
            ];
        }
        return [
            'v'             => 1,
            'id'            => (string)($e['id'] ?? ''),
            'proposalId'    => (string)($e['proposalId'] ?? ''),
            'abgeleitetVon' => (string)($e['abgeleitetVon'] ?? ''),
            'source'        => (string)($e['source'] ?? ''),
            'from'          => mb_substr((string)($e['from'] ?? ''), 0, 200),
            'fromName'      => mb_substr((string)($e['fromName'] ?? ''), 0, 200),
            'subject'       => mb_substr((string)($e['subject'] ?? ''), 0, 300),
            'date'          => max(0, (int)($e['date'] ?? 0)),
            'origin'        => is_array($e['origin'] ?? null) ? $e['origin'] : null,
            'savedAt'       => max(0, (int)($e['savedAt'] ?? 0)),
            'text'          => self::TextSauber((string)($e['text'] ?? '')),
            'truncated'     => ($e['truncated'] ?? false) === true,
            'atts'          => $atts,
            'skipped'       => array_values(array_slice(array_map(
                static fn($s): string => mb_substr((string)$s, 0, self::NAME_MAX), (array)($e['skipped'] ?? [])), 0, 20)),
        ];
    }

    /**
     * Was die App vom Datensatz sieht. Die Vorschlags-Kennung bleibt drin: die
     * Notiz prueft beim Uebernehmen, dass das Original zu IHREM Vorschlag gehoert.
     *
     * @param array<string,mixed> $satz
     * @return array<string,mixed>
     */
    public static function Oeffentlich(array $satz): array
    {
        return [
            'id'         => (string)($satz['id'] ?? ''),
            'proposalId' => (string)($satz['proposalId'] ?? ''),
            'source'     => (string)($satz['source'] ?? ''),
            'from'       => (string)($satz['from'] ?? ''),
            'fromName'   => (string)($satz['fromName'] ?? ''),
            'subject'    => (string)($satz['subject'] ?? ''),
            'date'       => (int)($satz['date'] ?? 0),
            'origin'     => is_array($satz['origin'] ?? null) ? $satz['origin'] : null,
            'text'       => (string)($satz['text'] ?? ''),
            'truncated'  => ($satz['truncated'] ?? false) === true,
            'atts'       => array_values((array)($satz['atts'] ?? [])),
            'skipped'    => array_values((array)($satz['skipped'] ?? [])),
        ];
    }

    /**
     * Welche Originale fallen weg?
     *
     * - An einem Eintrag gespeichert: bleibt, immer.
     * - Nur an einem lebenden Vorschlag (oder laufenden Auftrag): bleibt — bis
     *   der Speicherdeckel greift; dann fallen die aeltesten zuerst.
     * - An nichts: faellt nach der Schonfrist weg, ein gerade verworfener sofort.
     *
     * @param array<string,array{mtime:int,bytes:int}> $dateien
     * @param array<string,bool> $lebend    an Vorschlaegen/Auftraegen
     * @param array<string,bool> $eintraege an gespeicherten Eintraegen
     * @param array<string,bool> $sofort    eben verworfen
     * @return list<string>
     */
    public static function Wegraeumen(array $dateien, array $lebend, array $eintraege, array $sofort,
        int $jetzt, int $schonfrist, int $deckelBytes): array
    {
        $weg = [];
        $nurVorschlag = [];
        foreach ($dateien as $id => $d) {
            $id = (string)$id;
            if (isset($eintraege[$id])) {
                continue;
            }
            if (isset($lebend[$id])) {
                $nurVorschlag[$id] = $d;
                continue;
            }
            if (isset($sofort[$id]) || (int)($d['mtime'] ?? 0) <= $jetzt - $schonfrist) {
                $weg[] = $id;
            }
        }
        $summe = array_sum(array_map(static fn($d): int => (int)($d['bytes'] ?? 0), $nurVorschlag));
        if ($summe > $deckelBytes) {
            uasort($nurVorschlag, static fn($a, $b): int => (int)($a['mtime'] ?? 0) <=> (int)($b['mtime'] ?? 0));
            foreach ($nurVorschlag as $id => $d) {
                if ($summe <= $deckelBytes) {
                    break;
                }
                $weg[] = (string)$id;
                $summe -= (int)($d['bytes'] ?? 0);
            }
        }
        return $weg;
    }
}

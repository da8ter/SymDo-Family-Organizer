<?php

declare(strict_types=1);

/**
 * Filter und Grenzen fuer Anhaenge — einstellbar als „Experteneinstellungen"
 * (24.09.2026).
 *
 * Bis dahin standen die Werte als Konstanten in MailFetch, EduMaps und Moodle.
 * Die Standardwerte hier SIND diese Konstanten: ohne eigene Einstellung
 * aendert sich nichts. Eine Ausnahme mit Absicht: die Klassenseiten rechneten
 * mit 8 000 000 Zeichen Base64 (6 000 000 Byte), die Mail mit 8 MiB. Hier
 * heisst „MB" ueberall MiB, die Klassenseiten duerfen damit 5 % mehr.
 *
 * Rein: keine Symcon-Aufrufe. Das Gateway liest die Eigenschaften und gibt sie
 * roh herein; alles, was nicht passt, wird hier auf den erlaubten Bereich
 * gebracht — auch wenn ein Skript die Eigenschaft am Formular vorbei setzt.
 */
final class AnhangGrenzenCalc
{
    /** Die Eigenschaften mit ihren Standardwerten (= die frueheren Konstanten). */
    public const STANDARD = [
        'AttImageMinKB'    => 40,
        'AttImageMinPixel' => 600,
        'AttDecoNames'     => 'image00, logo, signatur, signature, icon, spacer, footer, unnamed, banner',
        'AttMaxCount'      => 5,
        'AttMaxFileMB'     => 6,
        'AttMaxTotalMB'    => 9,
        'EduAttMaxTotalMB' => 6,
        'OrigDecoRepeat'   => 2,
        'OrigCapMB'        => 250,
    ];

    /** Erlaubte Bereiche der Zahlen (einschliesslich). */
    public const BEREICH = [
        'AttImageMinKB'    => [0, 1000],
        'AttImageMinPixel' => [0, 4000],
        'AttMaxCount'      => [1, 10],
        'AttMaxFileMB'     => [1, 20],
        'AttMaxTotalMB'    => [1, 50],
        'EduAttMaxTotalMB' => [1, 50],
        'OrigDecoRepeat'   => [0, 10],
        'OrigCapMB'        => [50, 5000],
    ];

    public const NAMEN_MAX = 30;
    public const NAME_LAENGE_MAX = 30;

    private const MIB = 1048576;

    /**
     * Die wirksamen Werte aus den rohen Eigenschaften.
     *
     * @param array<string,mixed> $roh Eigenschaft ⇒ Wert; was fehlt, gilt als Standard
     * @return array{imageMinBytes:int, imageMinPixel:int, decoNames:list<string>,
     *               maxCount:int, maxFileB64:int, maxTotalB64:int, eduTotalB64:int,
     *               decoRepeat:int, capBytes:int}
     */
    public static function Wirksam(array $roh): array
    {
        $zahl = static function (string $k) use ($roh): int {
            $v = $roh[$k] ?? self::STANDARD[$k];
            $v = is_numeric($v) ? (int)$v : (int)self::STANDARD[$k];
            [$min, $max] = self::BEREICH[$k];
            return max($min, min($max, $v));
        };
        $wiederholung = $zahl('OrigDecoRepeat');
        return [
            'imageMinBytes' => $zahl('AttImageMinKB') * 1024,
            'imageMinPixel' => $zahl('AttImageMinPixel'),
            'decoNames'     => self::Namen((string)($roh['AttDecoNames'] ?? self::STANDARD['AttDecoNames'])),
            'maxCount'      => $zahl('AttMaxCount'),
            'maxFileB64'    => self::Base64Laenge($zahl('AttMaxFileMB') * self::MIB),
            'maxTotalB64'   => self::Base64Laenge($zahl('AttMaxTotalMB') * self::MIB),
            'eduTotalB64'   => self::Base64Laenge($zahl('EduAttMaxTotalMB') * self::MIB),
            // 1 hiesse „jedes Bild ist Briefpapier" — das meint niemand; 0 schaltet ab.
            'decoRepeat'    => $wiederholung === 1 ? 2 : $wiederholung,
            'capBytes'      => $zahl('OrigCapMB') * self::MIB,
        ];
    }

    /**
     * Die Layout-Namen aus der Eingabe: getrennt durch Komma, Semikolon oder
     * Zeilenumbruch, getrimmt, klein, ohne Doppelte, jeder hoechstens
     * NAME_LAENGE_MAX Zeichen, hoechstens NAMEN_MAX Stueck. Ein leeres Feld heisst
     * ausdruecklich „keine Namensregel".
     *
     * @return list<string>
     */
    public static function Namen(string $roh): array
    {
        $raus = [];
        foreach (preg_split('/[,;\r\n]+/u', $roh) ?: [] as $teil) {
            $n = mb_strtolower(trim($teil));
            if ($n === '' || mb_strlen($n) > self::NAME_LAENGE_MAX || in_array($n, $raus, true)) {
                continue;
            }
            $raus[] = $n;
            if (count($raus) >= self::NAMEN_MAX) {
                break;
            }
        }
        return $raus;
    }

    /** Wie lang ist eine Datei dieser Groesse als Base64? */
    public static function Base64Laenge(int $bytes): int
    {
        return (int)(4 * ceil($bytes / 3));
    }
}

<?php

declare(strict_types=1);

/**
 * Faecher: Aufloesung eines Slots auf Name, Symbol und Farbe — und ein
 * Vorschlag, wenn ein Fach neu angelegt wird.
 *
 * Die Zuordnung Fach → Symbol/Farbe gehoert dem NUTZER: er pflegt die Liste im
 * Formular, waehlt das Symbol ueber Symcons Icon-Auswahl und die Farbe ueber die
 * Farbauswahl. Was hier steht, ist nur die Vorbelegung — beim Anlegen eines
 * Fachs und beim Nachtragen aus vorhandenen Stunden.
 *
 * Die Erkennung selbst folgt der Vorlage WuselPlan (TimetableLayout.iconName):
 * exakte Kurzformen zuerst, dann spezifische Muster vor allgemeinen. Sonst
 * faengt „Englisch" das Fach „Altgriechisch" ab.
 *
 * Alle Symbolnamen sind gegen Symcons ausgelieferten Satz geprueft (3829 Namen
 * aus /icons.js, Stand 24.08.2026) — geratene Namen ergaeben in der Kachel einen
 * leeren Kasten.
 */
class TimetableSubjects
{
    public const VORGABE_ICON  = 'book';
    public const VORGABE_FARBE = 0x1E88E5;

    /** Farben der Vorlage (SubjectColorPalette.all), als Ganzzahl wie SelectColor. */
    public const PALETTE = [
        0xE53935, 0xFB8C00, 0xFDD835, 0x43A047, 0x00897B,
        0x1E88E5, 0x5E35B1, 0x8E24AA, 0xD81B60, 0x6D4C41,
    ];

    /**
     * Icon-Klasse fuer die Kachel. Symcon speichert den nackten Namen
     * („apple-whole"); gezeichnet wird „fa-light fa-apple-whole".
     *
     * Leer bleibt leer: ein <i> ohne Klasse waere ein leerer Kasten. Dasselbe
     * Verfahren wie in der Web-App (SymDoWebApp/module.html, categoryStyle).
     */
    public static function IconKlasse(string $icon): string
    {
        $icon = trim($icon);
        if ($icon === '') {
            return '';
        }
        return str_starts_with($icon, 'fa-') ? $icon : 'fa-' . $icon;
    }

    /**
     * Farbe fuer die Kachel. SelectColor liefert eine Ganzzahl, -1 heisst
     * „keine Farbe gewaehlt". Ein bereits fertiger #RRGGBB-String bleibt.
     * null heisst: der Aufrufer soll die naechste Ebene fragen.
     */
    public static function FarbeHex(mixed $farbe): ?string
    {
        if (is_string($farbe)) {
            $s = trim($farbe);
            return preg_match('/^#[0-9A-Fa-f]{6}$/', $s) === 1 ? strtoupper($s) : null;
        }
        if (!is_int($farbe) || $farbe < 0 || $farbe > 0xFFFFFF) {
            return null;
        }
        return sprintf('#%06X', $farbe);
    }

    /**
     * Was eine Stunde anzeigt: Name, Symbolklasse, Farbe.
     *
     * Reihenfolge der Farbe: eigene Farbe der Stunde, sonst die des Fachs,
     * sonst die Vorgabe. Das Fach wird ueber die Kennung gesucht; ist es
     * geloescht, bleibt der in der Stunde mitgeschriebene Name stehen — ohne
     * Symbol, aber lesbar. Ohne diesen Rueckfall stuenden nach dem Loeschen
     * eines Fachs leere Karten im Raster.
     *
     * @return array{name:string,icon:string,color:string}
     */
    public static function Aufloesen(array $slot, array $faecher): array
    {
        $kennung = (string)($slot['subjectId'] ?? '');
        $fach    = null;
        foreach ($faecher as $f) {
            if ((string)($f['id'] ?? '') === $kennung && $kennung !== '') {
                $fach = $f;
                break;
            }
        }
        $name = trim((string)($fach['name'] ?? ''));
        if ($name === '') {
            $name = trim((string)($slot['subject'] ?? ''));
        }
        $farbe = self::FarbeHex($slot['color'] ?? null)
            ?? ($fach !== null ? self::FarbeHex($fach['color'] ?? null) : null)
            ?? self::FarbeHex(self::VORGABE_FARBE);

        return [
            'name'  => $name,
            'icon'  => self::IconKlasse((string)($fach['icon'] ?? '')),
            'color' => (string)$farbe,
        ];
    }

    /**
     * Vorschlag fuer ein neu angelegtes Fach.
     *
     * @return array{icon:string,color:int}
     */
    public static function Vorschlag(string $fach): array
    {
        $s = mb_strtolower(trim($fach));
        if ($s === '') {
            return ['icon' => self::VORGABE_ICON, 'color' => self::VORGABE_FARBE];
        }

        // Kurzformen EXAKT: als Teilstring faenden sie zu viel — „ma" steckt in
        // „Mathematik", aber auch in „Deutsch als Zweitsprache".
        $kurz = [
            'ma' => ['calculator', 0x1E88E5],   'mt' => ['calculator', 0x1E88E5],
            'de' => ['book', 0xE53935],         'deu' => ['book', 0xE53935],
            'en' => ['earth-europe', 0x5E35B1], 'eng' => ['earth-europe', 0x5E35B1],
            'fr' => ['earth-europe', 0x5E35B1], 'fra' => ['earth-europe', 0x5E35B1],
            'es' => ['earth-europe', 0x5E35B1], 'spa' => ['earth-europe', 0x5E35B1],
            'it' => ['earth-europe', 0x5E35B1], 'la' => ['scroll', 0x6D4C41],
            'lat' => ['scroll', 0x6D4C41],      'bi' => ['leaf', 0x00897B],
            'bio' => ['leaf', 0x00897B],        'ch' => ['flask', 0x00897B],
            'che' => ['flask', 0x00897B],       'ph' => ['atom', 0x1E88E5],
            'phy' => ['atom', 0x1E88E5],        'if' => ['laptop-code', 0x1E88E5],
            'inf' => ['laptop-code', 0x1E88E5], 'info' => ['laptop-code', 0x1E88E5],
            'ge' => ['hourglass-half', 0xFB8C00], 'ges' => ['hourglass-half', 0xFB8C00],
            'ek' => ['map', 0xFB8C00],          'geo' => ['map', 0xFB8C00],
            'gk' => ['landmark', 0xFB8C00],     'sk' => ['landmark', 0xFB8C00],
            'pol' => ['landmark', 0xFB8C00],    'sowi' => ['landmark', 0xFB8C00],
            'wi' => ['chart-line', 0xFB8C00],   'ku' => ['palette', 0xD81B60],
            'bk' => ['palette', 0xD81B60],      'mu' => ['music', 0x8E24AA],
            'mus' => ['music', 0x8E24AA],       're' => ['hands-praying', 0x6D4C41],
            'reli' => ['hands-praying', 0x6D4C41], 'et' => ['lightbulb', 0x6D4C41],
            'eth' => ['lightbulb', 0x6D4C41],   'phi' => ['lightbulb', 0x6D4C41],
            'psy' => ['brain', 0x5E35B1],       'pa' => ['graduation-cap', 0x5E35B1],
            'ds' => ['masks-theater', 0xD81B60],'hw' => ['utensils', 0xD81B60],
            'tw' => ['hammer', 0x6D4C41],       'wk' => ['hammer', 0x6D4C41],
            'tg' => ['scissors', 0xD81B60],     'spo' => ['person-running', 0x43A047],
            'sport' => ['person-running', 0x43A047], 'sw' => ['person-swimming', 0x43A047],
            'hsu' => ['magnifying-glass', 0x00897B], 'su' => ['magnifying-glass', 0x00897B],
            'ag' => ['wand-magic-sparkles', 0xFDD835],
        ];
        if (isset($kurz[$s])) {
            return ['icon' => $kurz[$s][0], 'color' => $kurz[$s][1]];
        }

        // Spezifisch vor allgemein. Die Reihenfolge ist die Aussage: „altgriech"
        // MUSS vor „griech" stehen und beide vor „englisch", sonst faengt die
        // allgemeinere Regel das speziellere Fach ab.
        $muster = [
            ['betreuung', 'children', 0x9E9E9E], ['hort', 'children', 0x9E9E9E],
            ['altgriech', 'scroll', 0x6D4C41], ['griechisch', 'scroll', 0x6D4C41],
            ['latein', 'scroll', 0x6D4C41], ['latinum', 'scroll', 0x6D4C41],
            ['französisch', 'earth-europe', 0x5E35B1], ['franzoesisch', 'earth-europe', 0x5E35B1],
            ['spanisch', 'earth-europe', 0x5E35B1], ['italienisch', 'earth-europe', 0x5E35B1],
            ['türkisch', 'earth-europe', 0x5E35B1], ['russisch', 'globe', 0x5E35B1],
            ['chinesisch', 'globe', 0x5E35B1], ['niederländisch', 'earth-europe', 0x5E35B1],
            ['portugiesisch', 'earth-europe', 0x5E35B1], ['englisch', 'earth-europe', 0x5E35B1],
            ['deutsch', 'book', 0xE53935],
            ['astronomi', 'telescope', 0x1E88E5], ['informatik', 'laptop-code', 0x1E88E5],
            ['geometr', 'calculator', 0x1E88E5], ['algebra', 'calculator', 0x1E88E5],
            ['biologie', 'leaf', 0x00897B], ['chemie', 'flask', 0x00897B],
            ['physik', 'atom', 0x1E88E5], ['mathemat', 'calculator', 0x1E88E5],
            ['mathe', 'calculator', 0x1E88E5], ['rechnen', 'calculator', 0x1E88E5],
            ['geschichte', 'hourglass-half', 0xFB8C00],
            ['erdkunde', 'map', 0xFB8C00], ['geografie', 'map', 0xFB8C00],
            ['geographie', 'map', 0xFB8C00],
            ['gemeinschaftskunde', 'landmark', 0xFB8C00], ['sozialkunde', 'landmark', 0xFB8C00],
            ['politik', 'landmark', 0xFB8C00], ['wirtschaft', 'chart-line', 0xFB8C00],
            ['pädagog', 'graduation-cap', 0x5E35B1], ['psycholog', 'brain', 0x5E35B1],
            ['philosoph', 'lightbulb', 0x6D4C41],
            ['religion', 'hands-praying', 0x6D4C41], ['reli', 'hands-praying', 0x6D4C41],
            ['ethik', 'lightbulb', 0x6D4C41],
            ['musik', 'music', 0x8E24AA], ['theater', 'masks-theater', 0xD81B60],
            ['darstellendes spiel', 'masks-theater', 0xD81B60],
            ['medien', 'film', 0xD81B60], ['tanz', 'shoe-prints', 0xD81B60],
            ['kunst', 'palette', 0xD81B60], ['zeichnen', 'palette', 0xD81B60],
            ['textil', 'scissors', 0xD81B60], ['nähen', 'scissors', 0xD81B60],
            ['hauswirtschaft', 'utensils', 0xD81B60], ['kochen', 'utensils', 0xD81B60],
            ['werken', 'hammer', 0x6D4C41], ['technik', 'screwdriver-wrench', 0x6D4C41],
            ['schwimmen', 'person-swimming', 0x43A047],
            ['fußball', 'futbol', 0x43A047], ['fussball', 'futbol', 0x43A047],
            ['basketball', 'basketball', 0x43A047], ['volleyball', 'volleyball', 0x43A047],
            ['sport', 'person-running', 0x43A047], ['leichtathletik', 'person-running', 0x43A047],
            ['sachunterricht', 'magnifying-glass', 0x00897B],
            ['sachkunde', 'magnifying-glass', 0x00897B], ['heimat', 'magnifying-glass', 0x00897B],
            ['nawi', 'leaf', 0x00897B], ['naturwissenschaft', 'leaf', 0x00897B],
            ['klassenrat', 'people-group', 0xFDD835], ['klassenlehrer', 'people-group', 0xFDD835],
            ['praktikum', 'briefcase', 0xFDD835], ['förder', 'user-group', 0xFDD835],
            ['foerder', 'user-group', 0xFDD835],
        ];
        foreach ($muster as [$teil, $icon, $farbe]) {
            if (mb_strpos($s, $teil) !== false) {
                return ['icon' => $icon, 'color' => $farbe];
            }
        }
        return ['icon' => self::VORGABE_ICON, 'color' => self::VORGABE_FARBE];
    }

    /**
     * Vorbelegung der Faecher-Liste beim ersten Einrichten. Bewusst kurz: die
     * gaengigen Faecher, damit jemand sofort Stunden anlegen kann, ohne vorher
     * eine Fachliste zu tippen. Alles Weitere traegt der Nutzer nach.
     *
     * @return list<array{id:string,name:string,icon:string,color:int}>
     */
    public static function Vorgabefaecher(): array
    {
        $namen = ['Mathematik', 'Deutsch', 'Englisch', 'Sport', 'Musik', 'Kunst',
                  'Sachunterricht', 'Religion', 'Betreuung'];
        $liste = [];
        foreach ($namen as $i => $name) {
            $v = self::Vorschlag($name);
            $liste[] = [
                'id'    => 'f' . ($i + 1),
                'name'  => $name,
                'icon'  => $v['icon'],
                'color' => $v['color'],
            ];
        }
        return $liste;
    }
}

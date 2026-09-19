<?php

declare(strict_types=1);

/**
 * Die Bereiche der Tab-Leiste: Reihenfolge und Sichtbarkeit aus EINER Liste.
 *
 * Seit dem 19.09.2026 stellt der Nutzer beides im Formular der
 * SymDoWebApp-Instanz ein — eine Liste mit Ziehen zum Sortieren und einem
 * Haken je Zeile (Eigenschaft `Sections`). Vorher waren es acht einzelne
 * Schalter `ShowDashboard` … `ShowKi`; die bleiben als Altbestand registriert
 * und liefern hier die Vorgabe fuer Zeilen, die die Liste noch nicht kennt.
 * So verliert eine Bestandsanlage beim Update keine Einstellung.
 *
 * Liegt in `List/libs/`, weil zwei Module dieselbe Antwort brauchen: die
 * Kachel (Zustand und Meta-Push) und das Gateway (window.__SYMDO__ und die
 * Discovery-Auskunft fuer die App). Eine Regel, ein Ort.
 *
 * Kennt keine IPS-Funktion: bekommt die Konfiguration als Array und rechnet.
 */
final class Bereiche
{
    /** Die elf Bereiche in der Vorgabereihenfolge — sie ist die heutige Leiste. */
    public const SCHLUESSEL = ['dashboard', 'school', 'plan', 'transit', 'ki', 'shopping',
                               'todos', 'calendar', 'notes', 'edumaps', 'homework'];

    /** Bereich => Uebersetzungsschluessel; alle stehen schon in der locale.json. */
    public const NAMEN = [
        'dashboard' => 'Overview',
        'school'    => 'School',
        'plan'      => 'Timetable',
        'transit'   => 'Transit',
        'ki'        => 'AI',
        'shopping'  => 'Shopping',
        'todos'     => 'ToDos',
        'calendar'  => 'Calendar',
        'notes'     => 'Notes',
        'edumaps'   => 'Class pages',
        'homework'  => 'Homework',
    ];

    /** Altbestand: Bereich => alter Schalter. school/plan/transit hatten keinen. */
    public const ALT = [
        'dashboard' => 'ShowDashboard',
        'shopping'  => 'ShowShopping',
        'todos'     => 'ShowTodos',
        'calendar'  => 'ShowCalendar',
        'notes'     => 'ShowNotes',
        'edumaps'   => 'ShowEdumaps',
        'homework'  => 'ShowHomework',
        'ki'        => 'ShowKi',
    ];

    /**
     * Die Zeilen der Liste — geordnet, vollstaendig, ohne Doppel und Unbekanntes.
     *
     * Reihenfolge: erst die gespeicherten Zeilen, wie der Nutzer sie gezogen
     * hat; dann alles, was fehlt, in der Vorgabereihenfolge. Ein fehlender
     * Haken gilt als „an", ein alter Schalter (falls die Konfiguration ihn
     * kennt) gibt fuer eine noch nicht gespeicherte Zeile den Ausschlag.
     *
     * @param array<string,mixed>|null $cfg IPS_GetConfiguration der Kachel-Instanz
     * @return list<array{key:string,show:bool}>
     */
    public static function Zeilen(?array $cfg): array
    {
        $roh = json_decode((string)($cfg['Sections'] ?? ''), true);
        $raus = [];
        $gesehen = [];
        foreach (is_array($roh) ? $roh : [] as $z) {
            $key = is_array($z) ? trim((string)($z['key'] ?? '')) : '';
            if ($key === '' || isset($gesehen[$key]) || !in_array($key, self::SCHLUESSEL, true)) {
                continue;
            }
            $gesehen[$key] = true;
            $raus[] = ['key' => $key, 'show' => self::Ja($z['show'] ?? true)];
        }
        foreach (self::SCHLUESSEL as $key) {
            if (isset($gesehen[$key])) {
                continue;
            }
            $alt = self::ALT[$key] ?? '';
            $show = ($alt !== '' && is_array($cfg) && array_key_exists($alt, $cfg)) ? self::Ja($cfg[$alt]) : true;
            $raus[] = ['key' => $key, 'show' => $show];
        }
        return $raus;
    }

    /**
     * Was die Oberflaeche bekommt: `tabs` (Bereich => an?) und `tabOrder`.
     *
     * @param list<array{key:string,show:bool}> $zeilen
     * @return array{tabs: array<string,bool>, tabOrder: list<string>}
     */
    public static function Tabs(array $zeilen): array
    {
        $tabs = [];
        $order = [];
        foreach ($zeilen as $z) {
            $tabs[$z['key']] = $z['show'];
            $order[] = $z['key'];
        }
        return ['tabs' => $tabs, 'tabOrder' => $order];
    }

    /**
     * Bequemlichkeit fuer beide Nutzlast-Erzeuger.
     *
     * @param array<string,mixed>|null $cfg
     * @return array{tabs: array<string,bool>, tabOrder: list<string>}
     */
    public static function AusKonfiguration(?array $cfg): array
    {
        return self::Tabs(self::Zeilen($cfg));
    }

    /** Ein Haken aus dem Formular — als bool, als 1/0 oder als Text. */
    private static function Ja(mixed $wert): bool
    {
        if (is_bool($wert)) {
            return $wert;
        }
        if (is_string($wert)) {
            return in_array(strtolower(trim($wert)), ['true', '1', 'yes', 'ja'], true);
        }
        return (bool)$wert;
    }
}

<?php

declare(strict_types=1);

/**
 * Verlaufsfragen im Sprachdialog (29.09.2026) — die REINE Rechnung, ohne Symcon.
 *
 * „Wie warm war es gestern Nacht im Kinderzimmer?", „Wie viel Strom diese
 * Woche?": aus einer gesprochenen Zeitraum-Art wird ein Fenster mit passender
 * Archiv-Stufe, aus den Aggregaten des Symcon-Archivs eine Zusammenfassung.
 *
 * Archiv-Stufen (AC_GetAggregatedValues): 0 Stunde, 1 Tag, 3 Monat. Bei einer
 * Zählervariablen (AggregationType 1) ist `Avg` laut Referenz die SUMME der
 * positiven Zuwächse im Abschnitt — der Verbrauch ist also die Summe, nicht
 * der Mittelwert.
 */
final class VoiceVerlaufCalc
{
    public const ARTEN = ['letzte_stunde', 'heute', 'gestern', 'letzte_nacht', 'diese_woche', 'letzte_woche',
                          'letzte_7_tage', 'dieser_monat', 'letzter_monat', 'letzte_30_tage', 'dieses_jahr', 'tag'];
    public const STUFE_STUNDE = 0;
    public const STUFE_TAG    = 1;
    public const STUFE_MONAT  = 3;
    /** „Letzte Nacht": von 22 Uhr gestern bis 6 Uhr heute. */
    public const NACHT_VON = 22;
    public const NACHT_BIS = 6;

    /**
     * Das Fenster zu einer Zeitraum-Art.
     *
     * `bis` liegt nie in der Zukunft: „heute" endet jetzt. `wort` ist der
     * Übersetzungs-SCHLÜSSEL der gesprochenen Form („last night" → „letzte
     * Nacht"); nur ein bestimmter Tag kommt fertig („am Dienstag, 22. September"),
     * mit den deutschen Namen wie VoiceGesprochenesDatum.
     *
     * @return array{ok:bool, von?:int, bis?:int, stufe?:int, wort?:string, fehler?:string}
     */
    public static function Zeitraum(string $art, ?string $datum, int $jetzt): array
    {
        $tag = static fn(int $ts): int => (int)strtotime(date('Y-m-d 00:00:00', $ts));
        $heute = $tag($jetzt);
        $montag = (int)strtotime('monday this week', $heute);
        $monatErster = (int)strtotime(date('Y-m-01 00:00:00', $jetzt));
        [$von, $bis, $wort] = match ($art) {
            'letzte_stunde'  => [$jetzt - 3600, $jetzt, 'in the last hour'],
            'heute'          => [$heute, $jetzt, 'today'],
            'gestern'        => [(int)strtotime('-1 day', $heute), $heute - 1, 'yesterday'],
            'letzte_nacht'   => [(int)strtotime('-1 day', $heute) + self::NACHT_VON * 3600,
                                 min($jetzt, $heute + self::NACHT_BIS * 3600 - 1), 'last night'],
            'diese_woche'    => [$montag, $jetzt, 'this week'],
            'letzte_woche'   => [(int)strtotime('-7 days', $montag), $montag - 1, 'last week'],
            'letzte_7_tage'  => [(int)strtotime('-7 days', $jetzt), $jetzt, 'in the last 7 days'],
            'dieser_monat'   => [$monatErster, $jetzt, 'this month'],
            'letzter_monat'  => [(int)strtotime('-1 month', $monatErster), $monatErster - 1, 'last month'],
            'letzte_30_tage' => [(int)strtotime('-30 days', $jetzt), $jetzt, 'in the last 30 days'],
            'dieses_jahr'    => [(int)strtotime(date('Y-01-01 00:00:00', $jetzt)), $jetzt, 'this year'],
            'tag'            => self::EinTag($datum, $heute, $jetzt),
            default          => [0, 0, ''],
        };
        if ($wort === '' || $von <= 0) {
            return ['ok' => false, 'fehler' => $art === 'tag' ? 'datum' : 'art'];
        }
        if ($von >= $jetzt) {
            return ['ok' => false, 'fehler' => 'zukunft'];
        }
        // Vor 6 Uhr ist die „letzte Nacht" noch nicht vorbei — sie endet dann jetzt.
        $bis = min($bis, $jetzt);
        $dauer = $bis - $von;
        /* Bis drei Tage STUENDLICH: ein Tagesabschnitt zaehlt erst, wenn er
           vorbei ist — „diese Woche" an einem Dienstag verloere sonst den ganzen
           laufenden Tag. 72 Stunden sind fuer das Archiv nichts. */
        $stufe = $dauer <= 3 * 86400 ? self::STUFE_STUNDE : ($dauer <= 32 * 86400 ? self::STUFE_TAG : self::STUFE_MONAT);
        return ['ok' => true, 'von' => $von, 'bis' => $bis, 'stufe' => $stufe, 'wort' => $wort];
    }

    /** @return array{0:int,1:int,2:string} ein bestimmter vergangener Tag („am Dienstag, 22. September") */
    private static function EinTag(?string $datum, int $heute, int $jetzt): array
    {
        if (!is_string($datum) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $datum, $m) !== 1
            || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            return [0, 0, ''];
        }
        $von = (int)strtotime($datum . ' 00:00:00');
        if ($von === $heute) {
            return [$von, $jetzt, 'today'];
        }
        if ($von === (int)strtotime('-1 day', $heute)) {
            return [$von, $heute - 1, 'yesterday'];
        }
        $wochentage = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
        $monate = ['', 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August',
                   'September', 'Oktober', 'November', 'Dezember'];
        $wort = 'am ' . $wochentage[(int)date('w', $von)] . ', ' . (int)date('j', $von) . '. ' . $monate[(int)date('n', $von)];
        return [$von, (int)strtotime('+1 day', $von) - 1, $wort];
    }

    /**
     * Die Aggregate des Fensters zusammenfassen.
     *
     * Standard: gewichteter Mittelwert (nach `Duration`), kleinster und
     * größter Wert samt Zeitpunkt. Zähler: Summe der Abschnitte. Leer = das
     * Archiv hat für dieses Fenster nichts.
     *
     * @param list<array<string,mixed>> $aggregate wie AC_GetAggregatedValues sie liefert (neueste zuerst)
     * @return array{leer:bool, zaehler:bool, summe?:float, mittel?:float, min?:float, minZeit?:int, max?:float, maxZeit?:int, abschnitte:int}
     */
    public static function Zusammenfassen(array $aggregate, bool $zaehler): array
    {
        $zeilen = array_values(array_filter($aggregate, static fn($a): bool => is_array($a) && isset($a['Avg'])));
        if ($zeilen === []) {
            return ['leer' => true, 'zaehler' => $zaehler, 'abschnitte' => 0];
        }
        if ($zaehler) {
            $summe = 0.0;
            foreach ($zeilen as $a) {
                $summe += (float)$a['Avg'];
            }
            return ['leer' => false, 'zaehler' => true, 'summe' => $summe, 'abschnitte' => count($zeilen)];
        }
        $gewicht = 0;
        $flaeche = 0.0;
        $min = null;
        $max = null;
        $minZeit = 0;
        $maxZeit = 0;
        foreach ($zeilen as $a) {
            $d = max(1, (int)($a['Duration'] ?? 1));
            $gewicht += $d;
            $flaeche += (float)$a['Avg'] * $d;
            $lo = (float)($a['Min'] ?? $a['Avg']);
            $hi = (float)($a['Max'] ?? $a['Avg']);
            if ($min === null || $lo < $min) {
                $min = $lo;
                $minZeit = (int)($a['MinTime'] ?? $a['TimeStamp'] ?? 0);
            }
            if ($max === null || $hi > $max) {
                $max = $hi;
                $maxZeit = (int)($a['MaxTime'] ?? $a['TimeStamp'] ?? 0);
            }
        }
        return ['leer' => false, 'zaehler' => false, 'mittel' => $flaeche / max(1, $gewicht),
                'min' => (float)$min, 'minZeit' => $minZeit, 'max' => (float)$max, 'maxZeit' => $maxZeit,
                'abschnitte' => count($zeilen)];
    }

    /**
     * Der gesprochene Satz. `$zahl` formatiert einen Wert mit Einheit (der
     * Aufrufer kennt die Darstellung der Variablen), `$uhr` einen Zeitpunkt.
     *
     * @param array<string,mixed> $z Ergebnis von Zusammenfassen
     * @param callable(float):string $zahl
     * @param callable(int):string $uhr
     * @param callable(string):string $t Translate des Moduls
     */
    public static function Satz(string $titel, string $wort, array $z, bool $bool, callable $zahl, callable $uhr,
                                int $stufe, callable $t): string
    {
        if ($z['leer']) {
            return sprintf($t('There are no recorded values for %1$s %2$s.'), $titel, $wort);
        }
        if ($z['zaehler']) {
            return sprintf($t('%1$s: %2$s %3$s.'), $titel, self::Gross($wort), $zahl((float)$z['summe']));
        }
        if ($bool) {
            // Bei einer Bool-Variablen ist der Mittelwert der Anteil der Zeit, in der sie an war.
            $anteil = max(0.0, min(1.0, (float)$z['mittel']));
            return sprintf($t('%1$s was on %2$s for %3$d percent of the time.'), $titel, $wort, (int)round($anteil * 100));
        }
        $zeit = static fn(int $ts): string => $ts > 0 && $stufe === self::STUFE_STUNDE ? ' (' . $uhr($ts) . ')' : '';
        if (abs((float)$z['max'] - (float)$z['min']) < 1e-9) {
            return sprintf($t('%1$s was %2$s at %3$s.'), $titel, $wort, $zahl((float)$z['mittel']));
        }
        return sprintf($t('%1$s was %2$s between %3$s%4$s and %5$s%6$s, on average %7$s.'), $titel, $wort,
            $zahl((float)$z['min']), $zeit((int)$z['minZeit']),
            $zahl((float)$z['max']), $zeit((int)$z['maxZeit']), $zahl((float)$z['mittel']));
    }

    private static function Gross(string $s): string
    {
        return mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
    }
}

<?php

declare(strict_types=1);

/**
 * Hausaufgaben — das Rechenwerk, ohne Symcon und ohne Zustand.
 *
 * Keine Symcon-Aufrufe, kein Attribut, keine Uhr: alles kommt als Parameter
 * herein. Damit läuft es im Prüfstand (SymDoGateway/tests/HomeworkTest.php),
 * und die Regeln — was gültig ist, was aufbewahrt wird, welche Stunde eine
 * Aufgabe trägt — stehen an genau einer Stelle. Vorbild:
 * Stundenplan/libs/TimetableCalc.php.
 */
class HomeworkCalc
{
    public const NOTE_MAX = 500;
    public const SUBJECT_MAX = 60;
    /** So viele Einträge hält der Bestand. Ältere fallen vorne heraus. */
    public const ITEMS_MAX = 300;
    /** Erledigte bleiben so lange sichtbar, dann räumt der Bestand sie weg. */
    public const KEEP_DONE_DAYS = 14;
    /** Offene, die nie erledigt wurden, verfallen nach dieser Zeit. */
    public const KEEP_OPEN_DAYS = 60;
    /** Weiter als ein Jahr voraus oder zurück ist ein Tipp- oder Modellfehler. */
    public const DUE_WINDOW_DAYS = 365;
    /** Urheber eines Häkchens: hier gesetzt … */
    public const BY_USER = 'user';
    /** … oder aus WebUntis übernommen. */
    public const BY_UNTIS = 'untis';

    /** Ein Urheber, den es gibt. Alles andere gilt als hier gesetzt. */
    public static function UrheberSauber(string $roh): string
    {
        return trim($roh) === self::BY_UNTIS ? self::BY_UNTIS : self::BY_USER;
    }

    /** Ein Datum in der Form JJJJ-MM-TT, das es wirklich gibt. */
    public static function DatumGueltig(string $datum): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $datum, $m) !== 1) {
            return false;
        }
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
    }

    /** Liegt das Datum in einem sinnvollen Fenster um heute? */
    public static function DatumImFenster(string $datum, string $heute): bool
    {
        if (!self::DatumGueltig($datum) || !self::DatumGueltig($heute)) {
            return false;
        }
        $a = new \DateTimeImmutable($datum . ' 12:00:00');
        $b = new \DateTimeImmutable($heute . ' 12:00:00');
        return abs((int)$a->diff($b)->format('%r%a')) <= self::DUE_WINDOW_DAYS;
    }

    /**
     * Zwei Fachnamen vergleichen: ohne Rücksicht auf Groß- und Kleinschreibung,
     * und eine Kurzform trifft den Anfang des langen Namens („Mathe" trifft
     * „Mathematik"). Nach drei Buchstaben wird nicht mehr geraten — „Deu" ist
     * eindeutig, „De" wäre es nicht.
     */
    public static function FachTreffer(string $a, string $b): bool
    {
        $x = mb_strtolower(trim($a));
        $y = mb_strtolower(trim($b));
        if ($x === '' || $y === '') {
            return false;
        }
        if ($x === $y) {
            return true;
        }
        $kurz = mb_strlen($x) < mb_strlen($y) ? $x : $y;
        $lang = $kurz === $x ? $y : $x;
        return mb_strlen($kurz) >= 3 && mb_strpos($lang, $kurz) === 0;
    }

    /**
     * Einen rohen Fachnamen gegen die Fachliste auflösen. Ein unbekannter Name
     * ist KEIN Fehler: er wird nur getrimmt und durchgelassen. Ein Fach ohne
     * Stunde (AG, Ersatzfach) hat trotzdem Hausaufgaben, und ein umbenanntes
     * Fach soll die alte Aufgabe nicht verschwinden lassen.
     *
     * @param list<string> $faecher
     */
    public static function FachAufloesen(string $roh, array $faecher): string
    {
        $name = trim($roh);
        if ($name === '') {
            return '';
        }
        foreach ($faecher as $f) {
            if (self::FachTreffer($name, (string)$f)) {
                return (string)$f;
            }
        }
        return mb_substr($name, 0, self::SUBJECT_MAX);
    }

    /**
     * Einen Eintrag in Form bringen. Gibt null zurück, wenn er nicht taugt —
     * der Aufrufer macht daraus einen Fehler oder überspringt ihn.
     *
     * @param list<string> $faecher
     * @param list<string> $kinder Zulässige Kennungen (Mitglieder mit Rolle Kind)
     */
    public static function Normalisieren(array $roh, array $faecher, array $kinder, string $heute, int $jetzt): ?array
    {
        $kind = trim((string)($roh['childId'] ?? ''));
        if ($kind === '' || !in_array($kind, $kinder, true)) {
            return null;
        }
        $fach = self::FachAufloesen((string)($roh['subject'] ?? ''), $faecher);
        if ($fach === '') {
            return null;
        }
        $due = trim((string)($roh['due'] ?? ''));
        if ($due !== '' && !self::DatumImFenster($due, $heute)) {
            return null;
        }
        $erledigt = ($roh['done'] ?? false) === true;
        return [
            'id'        => trim((string)($roh['id'] ?? '')),
            /* Herkunftskennung: bei einem Eintrag aus WebUntis die Nummer der
               Schule. Sie ist der Schlüssel, an dem der nächste Abruf denselben
               Eintrag wiedererkennt, statt ihn zweimal anzulegen. Ein von Hand
               angelegter Eintrag hat sie nicht — und wird vom Abruf niemals
               angefasst. */
            'srcId'     => max(0, (int)($roh['srcId'] ?? 0)),
            'childId'   => $kind,
            'subject'   => $fach,
            'due'       => $due,
            'done'      => $erledigt,
            'doneAt'    => $erledigt ? max(0, (int)($roh['doneAt'] ?? $jetzt)) : 0,
            /* WER abgehakt hat. Die Oberflaeche zeigt es in der Farbe des
               Haekchens: hier gesetzt oder aus der Schule uebernommen. Ein
               alter Eintrag ohne das Feld gilt als hier gesetzt — das war er
               auch, denn vorher gab es nichts anderes. */
            'doneBy'    => $erledigt ? self::UrheberSauber((string)($roh['doneBy'] ?? self::BY_USER)) : '',
            'note'      => mb_substr(trim((string)($roh['note'] ?? '')), 0, self::NOTE_MAX),
            /* Die Herkunft ist mehr als ein Etikett: die Zuordnung und das
               Loeschen in Zusammenfuehren() laufen JE QUELLE, und die
               Oberflaeche macht daran fest, was aus der Schule kommt und
               deshalb nicht von Hand geaendert werden darf. Eine unbekannte
               Herkunft gilt als „app" — dann gehoert der Eintrag dem Nutzer,
               und kein Abruf fasst ihn an. */
            'source'    => in_array((string)($roh['source'] ?? ''), ['app', 'voice', 'ai', 'edumaps', 'untis', 'moodle'], true)
                ? (string)$roh['source'] : 'app',
            'createdAt' => max(0, (int)($roh['createdAt'] ?? $jetzt)) ?: $jetzt,
            'updatedAt' => max(0, (int)($roh['updatedAt'] ?? $jetzt)) ?: $jetzt,
        ];
    }

    /**
     * Was aufbewahrt wird. Gefiltert wird beim LESEN und nicht per Timer:
     * geschrieben wird der gefilterte Stand erst bei der nächsten Änderung,
     * damit kein Abruf ein Attribut schreibt.
     *
     * Gekappt wird nach Anlagezeit und nicht nach Fälligkeit — sonst fiele
     * die eben eingetragene Nachholaufgabe von letzter Woche als erste weg.
     *
     * @param list<array> $items
     * @return list<array>
     */
    public static function Aufbewahrung(array $items, int $jetzt): array
    {
        $grenzeErledigt = $jetzt - self::KEEP_DONE_DAYS * 86400;
        $grenzeOffen = $jetzt - self::KEEP_OPEN_DAYS * 86400;
        $raus = [];
        foreach ($items as $i) {
            if (!is_array($i)) {
                continue;
            }
            if (($i['done'] ?? false) === true) {
                if ((int)($i['doneAt'] ?? 0) >= $grenzeErledigt) {
                    $raus[] = $i;
                }
                continue;
            }
            $bezug = trim((string)($i['due'] ?? '')) !== '' && self::DatumGueltig((string)$i['due'])
                ? (int)strtotime((string)$i['due'] . ' 23:59:59')
                : (int)($i['createdAt'] ?? 0);
            if ($bezug >= $grenzeOffen) {
                $raus[] = $i;
            }
        }
        if (count($raus) > self::ITEMS_MAX) {
            usort($raus, static fn(array $a, array $b): int => (int)$a['createdAt'] <=> (int)$b['createdAt']);
            $raus = array_slice($raus, count($raus) - self::ITEMS_MAX);
        }
        return array_values($raus);
    }

    /**
     * Offene Hausaufgaben je Kind, Tag und Fach — für das Abzeichen an der
     * Stunde. Die Zuordnung fällt an die ERSTE Stunde des Fachs an diesem Tag;
     * zwei Stunden desselben Fachs sollen die Zahl nicht verdoppeln.
     *
     * @param list<array> $items
     * @param array $plan Ausgabe von STPL_GetPlan()
     */
    public static function FuerPlan(array $items, array $plan): array
    {
        $offen = [];
        foreach ($items as $i) {
            if (!is_array($i) || ($i['done'] ?? false) === true) {
                continue;
            }
            $kind = trim((string)($i['childId'] ?? ''));
            $tag = trim((string)($i['due'] ?? ''));
            if ($kind === '' || $tag === '') {
                continue;
            }
            $offen[] = $i;
        }
        $raus = ['slots' => [], 'frei' => []];
        foreach ($offen as $i) {
            $kind = (string)$i['childId'];
            $tag = (string)$i['due'];
            $treffer = '';
            foreach ((array)($plan['children'] ?? []) as $k) {
                if (trim((string)($k['userId'] ?? '')) !== $kind) {
                    continue;
                }
                foreach ((array)($k['days'] ?? []) as $t) {
                    if (trim((string)($t['date'] ?? '')) !== $tag) {
                        continue;
                    }
                    foreach ((array)($t['slots'] ?? []) as $s) {
                        if (self::FachTreffer((string)($s['name'] ?? ''), (string)$i['subject'])) {
                            $treffer = (string)($s['id'] ?? '');
                            break 3;
                        }
                    }
                }
            }
            if ($treffer !== '') {
                $raus['slots'][$treffer] = ($raus['slots'][$treffer] ?? 0) + 1;
                continue;
            }
            /* Kein passender Slot (Fach fällt aus, hat an dem Tag keine Stunde
               oder heißt anders): die Aufgabe wird am Tag gesammelt. Eine
               Hausaufgabe, die NIRGENDS erscheint, wäre schlimmer als eine an
               ungenauer Stelle. */
            $raus['frei'][$kind . '|' . $tag] = ($raus['frei'][$kind . '|' . $tag] ?? 0) + 1;
        }
        return $raus;
    }

    /**
     * Einen Abruf aus WebUntis in den Bestand einrechnen.
     *
     * Der Abruf ist NICHT der Besitzer des Bestands, er ist eine Quelle unter
     * mehreren. Deshalb drei Regeln, und jede hat einen Fall hinter sich, der
     * sonst wehtut:
     *
     *  1. **Wiedererkannt wird über `srcId`**, die Nummer der Aufgabe in
     *     WebUntis. Ohne sie legte jeder Durchlauf dieselbe Aufgabe erneut an —
     *     stündlich, bis der Deckel greift.
     *  2. **Von Hand angelegtes bleibt unangetastet.** Ein Eintrag ohne
     *     `srcId` wird weder geändert noch gelöscht, auch wenn er dieselbe
     *     Aufgabe meint. Wer etwas selbst eingetragen hat, soll es wiederfinden.
     *  3. **Das Häkchen ist eine Sperrklinke.** Erledigt aus WebUntis setzt
     *     erledigt; ein zu Hause gesetztes Häkchen nimmt der Abruf NIE zurück.
     *     Andernfalls hätte das Kind abgehakt, und eine Stunde später stünde
     *     die Aufgabe wieder offen da, weil die Lehrkraft es in WebUntis nicht
     *     nachgetragen hat.
     *
     * Gelöscht wird nur, was WebUntis im ABGERUFENEN Fenster nicht mehr nennt.
     * Eine Aufgabe vor dem Fenster stand nie in dieser Antwort und ist deshalb
     * kein Beweis dafür, dass die Schule sie zurückgezogen hat.
     *
     * @param list<array> $items Bestand
     * @param list<array> $neu   normalisierte Einträge des Abrufs (mit srcId)
     * @return array{items:list<array>,neu:int,geaendert:int,entfernt:int}
     */
    public static function Zusammenfuehren(array $items, array $neu, string $kind, string $von,
        string $bis, int $jetzt, string $quelle = 'untis', array $faecher = []): array
    {
        /* JE QUELLE. Zwei Schulsysteme fuehren ihre Nummern unabhaengig: die
           Aufgabe 5 aus WebUntis und das Dokument 5 aus LOGINEO sind
           verschiedene Dinge. Ohne die Quelle im Schluessel wuerde das eine das
           andere ueberschreiben — und schlimmer: der Abruf der einen Quelle
           haelt die Einträge der anderen fuer verschwunden und loescht sie. */
        $gesehen = [];
        foreach ($neu as $n) {
            $s = (int)($n['srcId'] ?? 0);
            if ($s > 0) {
                $gesehen[$s] = true;
            }
        }
        $zahlNeu = 0;
        $zahlAend = 0;
        $zahlWeg = 0;

        /* Wo liegt welche fremde Aufgabe? Nur Einträge DIESES Kindes mit einer
           Herkunftsnummer zählen — alles andere gehört dem Nutzer. */
        $stelle = [];
        foreach ($items as $i => $satz) {
            if (!is_array($satz) || (string)($satz['childId'] ?? '') !== $kind) {
                continue;
            }
            // Nur Einträge DIESER Quelle — siehe oben.
            if ((string)($satz['source'] ?? '') !== $quelle) {
                continue;
            }
            $s = (int)($satz['srcId'] ?? 0);
            if ($s > 0) {
                $stelle[$s] = $i;
            }
        }

        foreach ($neu as $n) {
            $s = (int)($n['srcId'] ?? 0);
            if ($s <= 0) {
                continue;
            }
            if (!isset($stelle[$s])) {
                if (count($items) >= self::ITEMS_MAX) {
                    continue;
                }
                $n['id'] = bin2hex(random_bytes(4));
                $n['createdAt'] = $jetzt;
                $n['updatedAt'] = $jetzt;
                $items[] = $n;
                $stelle[$s] = count($items) - 1;
                $zahlNeu++;
                continue;
            }
            $alt = $items[$stelle[$s]];
            $satz = $alt;
            /* Ein KUERZEL darf einen aufgeloesten Fachnamen nicht ueberschreiben.
               WebUntis nennt das Fach als Kuerzel („M", „Bi"); uebersetzt wird
               es ueber den Stundenplan des VORWAERTS-Fensters. Seit die
               Hausaufgaben mit Rueckgriff geholt werden, kann eine Aufgabe an
               einer Stunde haengen, deren Fach in den naechsten vierzehn Tagen
               gar nicht mehr stattfindet — Blockfach, abgewaehlter Kurs, oder
               schlicht Ferien. Dann greift die Uebersetzung nicht, und ohne
               diesen Riegel fiele der Bestandssatz von „Mathematik" auf „M".
               Dauerhaft: eine Woche spaeter liegt er auch ausserhalb des
               Rueckgriffs. Zurueck bliebe eine Zeile ohne Fachsymbol und ohne
               Farbe — `FachTreffer` verlangt drei Zeichen, „M" trifft nie.
               Gilt nur in DIESE Richtung: ein aufgeloester Name ersetzt einen
               unaufgeloesten sehr wohl. */
            $neuFach = (string)$n['subject'];
            $altFach = (string)($satz['subject'] ?? '');
            $neuKennt = $faecher === [] || in_array($neuFach, $faecher, true);
            $altKennt = $altFach !== '' && in_array($altFach, $faecher, true);
            if ($neuKennt || !$altKennt) {
                $satz['subject'] = $neuFach;
            }
            $satz['due'] = (string)$n['due'];
            $satz['note'] = (string)$n['note'];
            /* Die Schule BESTAETIGT ein Haekchen, das hier schon stand.
               Ab jetzt gehoert es ihr: der Urheber wandert auf „untis".
               Vorher blieb er fuer immer auf „user" — die Sperrklinke unten
               greift nur beim Uebergang von offen auf erledigt und feuert hier
               nie mehr. Damit war im Bestand nicht zu sehen, ob das
               Klassenbuch die Aufgabe inzwischen abgeschlossen hat, und die
               Oberflaeche konnte nicht darauf warten.
               Deckt denselben Fall fuer Eintraege aus der Zeit VOR dem
               Urheberfeld ab (doneBy leer). */
            if (($n['done'] ?? false) === true && ($satz['done'] ?? false) === true
                && (string)($satz['doneBy'] ?? '') !== self::BY_UNTIS) {
                $satz['doneBy'] = self::BY_UNTIS;
                /* Und mit dem Urheber wandert das DATUM: gefragt ist, wann die
                   Schule die Aufgabe abgeschlossen hat, nicht wann das Kind den
                   Haken gesetzt hat (Wunsch des Nutzers, 17.09.2026). Genauer
                   als „bei diesem Abruf gesehen" geht es nicht — WebUntis
                   liefert zur Aufgabe nur `completed`, keinen Zeitpunkt. */
                $satz['doneAt'] = $jetzt;
            }
            // Sperrklinke: erledigt bleibt erledigt.
            if (($n['done'] ?? false) === true && ($satz['done'] ?? false) !== true) {
                $satz['done'] = true;
                $satz['doneAt'] = $jetzt;
                /* Dieses Haekchen kommt aus der Schule, nicht vom Kind. Die
                   Oberflaeche faerbt es deshalb anders — sonst haette niemand
                   eine Moeglichkeit zu sehen, wer es gesetzt hat. */
                $satz['doneBy'] = self::BY_UNTIS;
            }
            if ($satz !== $alt) {
                $satz['updatedAt'] = $jetzt;
                $items[$stelle[$s]] = $satz;
                $zahlAend++;
            }
        }

        $raus = [];
        foreach ($items as $satz) {
            if (!is_array($satz)) {
                continue;
            }
            $s = (int)($satz['srcId'] ?? 0);
            $due = trim((string)($satz['due'] ?? ''));
            $fremd = $s > 0 && (string)($satz['childId'] ?? '') === $kind
                && (string)($satz['source'] ?? '') === $quelle;
            if ($fremd && !isset($gesehen[$s]) && $due !== '' && $due >= $von && $due <= $bis) {
                $zahlWeg++;
                continue;
            }
            $raus[] = $satz;
        }

        return ['items' => array_values($raus), 'neu' => $zahlNeu, 'geaendert' => $zahlAend, 'entfernt' => $zahlWeg];
    }

    /**
     * Die Meldung am Vorabend: was für einen Tag noch offen ist, je Kind.
     *
     * @param list<array> $items
     * @return array<string,list<array>> Kindkennung => Einträge
     */
    public static function FuerTag(array $items, string $tag): array
    {
        $raus = [];
        foreach ($items as $i) {
            if (!is_array($i) || ($i['done'] ?? false) === true) {
                continue;
            }
            if (trim((string)($i['due'] ?? '')) !== $tag) {
                continue;
            }
            $kind = trim((string)($i['childId'] ?? ''));
            if ($kind === '') {
                continue;
            }
            $raus[$kind][] = $i;
        }
        return $raus;
    }
}

<?php

declare(strict_types=1);

/**
 * Prüfungen aus WebUntis — das Rechenwerk, ohne Symcon und ohne Zustand.
 *
 * WebUntis führt eine Klassenarbeit in der REST-Ansicht als eigenen
 * Eintragstyp (`type: EXAM`) mit dem Titel in `lessonInfo` (am 24.09.2026
 * gemessen: „KA Briefe schreiben"). Die eigene Prüfungsliste `/api/exams`
 * antwortet dem Konto mit 403 — der Stundenplan ist die EINZIGE Quelle.
 *
 * Hier steht, was sich ohne Symcon rechnen lässt: Doppelstunden
 * zusammenfassen, einen Abruf mit dem gemerkten Stand abgleichen, die Zeilen
 * fürs Briefing und die Lern-Erinnerung. Eingebunden von UntisLesen.php und
 * damit auch in der Scanner-Instanz — deshalb eine eigene Klasse und kein
 * Trait: ihr `$this` braucht niemand.
 *
 * Eine Prüfung hat hier die Form
 *   {ids:list<int>, date:'JJJJ-MM-TT', start:'HH:MM', end:'HH:MM', subject,
 *    title, room, teacher, status: normal|vertretung|entfall}
 * und im gemerkten Stand zusätzlich {key, seit, lern, lernFuer}.
 */
class UntisPruefungCalc
{
    /** Größte Lücke zwischen zwei Stunden, die noch EINE Prüfung sind (Minuten). */
    public const LUECKE_MAX = 20;
    /** So lang darf ein Titel werden — WebUntis kappt selbst nicht. */
    public const TITEL_MAX = 80;
    /** Das Thema einer Klassenarbeit (exam.description). */
    public const THEMA_MAX = 300;
    /** So weit schaut das Briefing voraus (Tage nach dem Briefingtag). */
    public const BRIEFING_TAGE = 7;
    /** Mehr Zeilen verträgt die Ansage nicht. */
    public const BRIEFING_ZEILEN = 4;
    /** Verlegt heißt: derselbe Titel taucht in diesem Abstand wieder auf (Tage). */
    public const VERLEGT_OHNE_TITEL_TAGE = 14;

    private const WOCHENTAGE = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];

    /** „10:35" → 635, alles andere → -1. */
    public static function Minuten(string $zeit): int
    {
        if (preg_match('/^(\d{1,2}):(\d{2})$/', trim($zeit), $m) !== 1) {
            return -1;
        }
        return (int)$m[1] * 60 + (int)$m[2];
    }

    /** Ein Titel zum Vergleichen: klein, ohne Satzzeichen, Leerraum einfach. */
    public static function TitelNorm(string $titel): string
    {
        $t = mb_strtolower(trim($titel));
        $t = (string)preg_replace('/[^\p{L}\p{N}]+/u', ' ', $t);
        return trim((string)preg_replace('/\s+/u', ' ', $t));
    }

    /**
     * Eine Prüfung aus dem Abruf in Form bringen. Null, wenn sie nicht taugt
     * (kein Datum, keine Zeit, kein Fach und kein Titel).
     *
     * @param array<string,mixed> $roh
     */
    public static function Sauber(array $roh): ?array
    {
        $datum = trim((string)($roh['date'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum) !== 1) {
            return null;
        }
        $start = trim((string)($roh['start'] ?? ''));
        $ende  = trim((string)($roh['end'] ?? ''));
        if (self::Minuten($start) < 0 || self::Minuten($ende) < 0) {
            return null;
        }
        $fach  = mb_substr(trim((string)($roh['subject'] ?? '')), 0, 60);
        $titel = mb_substr(trim((string)($roh['title'] ?? '')), 0, self::TITEL_MAX);
        if ($fach === '' && $titel === '') {
            return null;
        }
        $ids = [];
        foreach ((array)($roh['ids'] ?? []) as $id) {
            if (is_numeric($id) && (int)$id > 0 && !in_array((int)$id, $ids, true)) {
                $ids[] = (int)$id;
            }
        }
        $status = (string)($roh['status'] ?? 'normal');
        return [
            'ids'     => $ids,
            'date'    => $datum,
            'start'   => $start,
            'end'     => $ende,
            'subject' => $fach !== '' ? $fach : $titel,
            'title'   => $titel,
            'room'    => mb_substr(trim((string)($roh['room'] ?? '')), 0, 60),
            'teacher' => mb_substr(trim((string)($roh['teacher'] ?? '')), 0, 60),
            'status'  => in_array($status, ['normal', 'vertretung', 'entfall'], true) ? $status : 'normal',
            'topic'    => mb_substr(trim((string)($roh['topic'] ?? '')), 0, self::THEMA_MAX),
            'examType' => mb_substr(trim((string)($roh['examType'] ?? '')), 0, 40),
        ];
    }

    /**
     * Doppelstunden zu EINER Prüfung.
     *
     * Eine Klassenarbeit über zwei Stunden kommt als zwei Einträge: gleiches
     * Datum, gleiches Fach, gleicher Titel, die zweite beginnt kurz nach dem
     * Ende der ersten. Ohne das stünde sie zweimal in der Liste, käme zweimal
     * als Meldung und bekäme zwei Lern-Erinnerungen.
     *
     * Der Status des Ganzen: findet ein Teil statt, findet die Prüfung statt.
     *
     * @param list<array<string,mixed>> $roh
     * @return list<array<string,mixed>>
     */
    public static function Zusammenfassen(array $roh): array
    {
        $saetze = [];
        foreach ($roh as $r) {
            $s = is_array($r) ? self::Sauber($r) : null;
            if ($s !== null) {
                $saetze[] = $s;
            }
        }
        usort($saetze, static fn(array $a, array $b): int
            => [$a['date'], self::Minuten($a['start'])] <=> [$b['date'], self::Minuten($b['start'])]);
        $raus = [];
        foreach ($saetze as $s) {
            $letzte = count($raus) - 1;
            if ($letzte >= 0) {
                $v = $raus[$letzte];
                $luecke = self::Minuten($s['start']) - self::Minuten($v['end']);
                if ($v['date'] === $s['date']
                    && mb_strtolower($v['subject']) === mb_strtolower($s['subject'])
                    && self::TitelNorm($v['title']) === self::TitelNorm($s['title'])
                    && $luecke <= self::LUECKE_MAX
                    && self::Minuten($s['start']) >= self::Minuten($v['start'])) {
                    $v['ids'] = array_values(array_unique(array_merge($v['ids'], $s['ids'])));
                    if (self::Minuten($s['end']) > self::Minuten($v['end'])) {
                        $v['end'] = $s['end'];
                    }
                    $v['status'] = self::StatusVereinen($v['status'], $s['status']);
                    foreach (['room', 'teacher', 'topic', 'examType'] as $f) {
                        if ($v[$f] === '') {
                            $v[$f] = $s[$f];
                        }
                    }
                    $raus[$letzte] = $v;
                    continue;
                }
            }
            $raus[] = $s;
        }
        return $raus;
    }

    /** Findet ein Teil statt, findet das Ganze statt; eine Änderung bleibt sichtbar. */
    private static function StatusVereinen(string $a, string $b): string
    {
        if ($a === 'entfall' && $b === 'entfall') {
            return 'entfall';
        }
        return ($a === 'vertretung' || $b === 'vertretung') ? 'vertretung' : 'normal';
    }

    /**
     * Der dauerhafte Schlüssel einer Prüfung: die erste Nummer aus WebUntis,
     * ohne Nummer eine Prüfsumme über Tag, Zeit, Fach und Titel. Nie 0 — die 0
     * heißt in den Hausaufgaben „von Hand".
     *
     * @param array<string,mixed> $p
     */
    public static function Schluessel(array $p): int
    {
        foreach ((array)($p['ids'] ?? []) as $id) {
            if ((int)$id > 0) {
                return (int)$id;
            }
        }
        $h = crc32((string)($p['date'] ?? '') . '|' . (string)($p['start'] ?? '') . '|'
            . mb_strtolower((string)($p['subject'] ?? '')) . '|' . self::TitelNorm((string)($p['title'] ?? '')))
            & 0x7fffffff;
        return $h === 0 ? 1 : $h;
    }

    /**
     * Einen Abruf mit dem gemerkten Stand EINES Kindes abgleichen.
     *
     * Zuerst über eine gemeinsame Nummer. Bleibt danach ein altes Stück übrig,
     * das verschwunden ist oder jetzt entfällt, und steht eine neue Prüfung
     * desselben Fachs mit demselben Titel da, ist das eine VERLEGUNG: WebUntis
     * streicht beim Verlegen die alte Stunde und legt eine neue an, mit neuer
     * Nummer. Die neue erbt dann Schlüssel und Lern-Erinnerung, der gestrichene
     * Rest fällt aus der Liste.
     *
     * Ohne Titel genügt das Fach nur, wenn beide Termine nah beieinander liegen
     * — zwei Mathearbeiten im Halbjahr sind zwei Prüfungen, keine Verlegung.
     *
     * @param list<array<string,mixed>> $alt gemerkte Prüfungen
     * @param list<array<string,mixed>> $neu zusammengefasste Prüfungen des Abrufs
     * @return array{liste:list<array<string,mixed>>, neu:list<array<string,mixed>>,
     *               verlegt:list<array{vorher:array<string,mixed>,nachher:array<string,mixed>}>,
     *               entfallen:list<array<string,mixed>>, weg:list<array<string,mixed>>}
     */
    public static function Abgleichen(array $alt, array $neu, int $jetzt): array
    {
        $alt = array_values(array_filter($alt, 'is_array'));
        $neu = array_values(array_filter($neu, 'is_array'));

        // 1. Gemeinsame Nummer — oder derselbe Schlüssel bei Prüfungen ohne Nummer.
        $altNachId = [];
        $altNachKey = [];
        foreach ($alt as $i => $a) {
            foreach ((array)($a['ids'] ?? []) as $id) {
                $altNachId[(int)$id] ??= $i;
            }
            $altNachKey[(int)($a['key'] ?? 0)] ??= $i;
        }
        $paar = [];      // neu-Index => alt-Index
        $vergeben = [];  // alt-Index => true
        foreach ($neu as $j => $n) {
            $treffer = null;
            foreach ((array)($n['ids'] ?? []) as $id) {
                if (isset($altNachId[(int)$id]) && !isset($vergeben[$altNachId[(int)$id]])) {
                    $treffer = $altNachId[(int)$id];
                    break;
                }
            }
            if ($treffer === null && ($n['ids'] ?? []) === []) {
                $k = self::Schluessel($n);
                if (isset($altNachKey[$k]) && !isset($vergeben[$altNachKey[$k]])) {
                    $treffer = $altNachKey[$k];
                }
            }
            if ($treffer !== null) {
                $paar[$j] = $treffer;
                $vergeben[$treffer] = true;
            }
        }

        // 2. Verlegungen: eine neue Prüfung ohne Partner, die stattfindet …
        $verlegtVon = [];   // neu-Index => alt-Index
        $rest = [];         // neu-Index des gestrichenen Rests => true
        foreach ($neu as $j => $n) {
            if (isset($paar[$j]) || (string)$n['status'] === 'entfall') {
                continue;
            }
            $bester = null;
            foreach ($alt as $i => $a) {
                if (in_array($i, $verlegtVon, true)) {
                    continue;
                }
                // … und ein altes Stück, das verschwunden ist oder jetzt entfällt.
                $partner = array_search($i, $paar, true);
                $frei = !isset($vergeben[$i]);
                $gestrichen = $partner !== false && (string)$neu[$partner]['status'] === 'entfall'
                    && (string)($a['status'] ?? '') !== 'entfall';
                if (!$frei && !$gestrichen) {
                    continue;
                }
                if (mb_strtolower((string)($a['subject'] ?? '')) !== mb_strtolower((string)$n['subject'])) {
                    continue;
                }
                $ta = self::TitelNorm((string)($a['title'] ?? ''));
                $tn = self::TitelNorm((string)$n['title']);
                if ($ta !== '' || $tn !== '') {
                    if ($ta !== $tn) {
                        continue;
                    }
                } elseif (abs(self::TageZwischen((string)($a['date'] ?? ''), (string)$n['date'])) > self::VERLEGT_OHNE_TITEL_TAGE) {
                    continue;
                }
                $bester = $i;
                if ($gestrichen) {
                    $rest[(int)$partner] = true;
                }
                break;
            }
            if ($bester !== null) {
                $verlegtVon[$j] = $bester;
                $vergeben[$bester] = true;
            }
        }

        // 3. Die neue Liste und was sich geändert hat.
        $liste = [];
        $meldNeu = [];
        $meldVerlegt = [];
        $meldEntfallen = [];
        foreach ($neu as $j => $n) {
            if (isset($rest[$j])) {
                continue;   // der gestrichene Rest einer Verlegung
            }
            $quelle = isset($paar[$j]) ? $alt[$paar[$j]] : (isset($verlegtVon[$j]) ? $alt[$verlegtVon[$j]] : null);
            $satz = $n + [
                'key'      => $quelle !== null ? (int)($quelle['key'] ?? 0) : 0,
                'seit'     => $quelle !== null ? (int)($quelle['seit'] ?? $jetzt) : $jetzt,
                'lern'     => $quelle !== null ? (string)($quelle['lern'] ?? '') : '',
                'lernFuer' => $quelle !== null ? (string)($quelle['lernFuer'] ?? '') : '',
            ];
            $satz['key'] = (int)$satz['key'] > 0 ? (int)$satz['key'] : self::Schluessel($n);
            $liste[] = $satz;
            if ($quelle === null) {
                // Eine Prüfung, die wir nur als gestrichene kennen, ist keine Nachricht.
                if ((string)$n['status'] !== 'entfall') {
                    $meldNeu[] = $satz;
                }
                continue;
            }
            $bewegt = (string)($quelle['date'] ?? '') !== (string)$n['date']
                || (string)($quelle['start'] ?? '') !== (string)$n['start'];
            if (isset($verlegtVon[$j]) || ($bewegt && (string)$n['status'] !== 'entfall')) {
                $meldVerlegt[] = ['vorher' => $quelle, 'nachher' => $satz];
                continue;
            }
            if ((string)$n['status'] === 'entfall' && (string)($quelle['status'] ?? '') !== 'entfall') {
                $meldEntfallen[] = $satz;
            }
        }
        $weg = [];
        foreach ($alt as $i => $a) {
            if (!isset($vergeben[$i])) {
                $weg[] = $a;
            }
        }
        usort($liste, static fn(array $a, array $b): int
            => [$a['date'], self::Minuten($a['start'])] <=> [$b['date'], self::Minuten($b['start'])]);
        return ['liste' => $liste, 'neu' => $meldNeu, 'verlegt' => $meldVerlegt,
                'entfallen' => $meldEntfallen, 'weg' => $weg];
    }

    /** Tage von $a nach $b (negativ, wenn $b davor liegt); 0 bei ungültigem Datum. */
    public static function TageZwischen(string $a, string $b): int
    {
        $x = strtotime($a . ' 12:00:00');
        $y = strtotime($b . ' 12:00:00');
        if ($x === false || $y === false) {
            return 0;
        }
        return (int)round(($y - $x) / 86400);
    }

    /** „heute", „morgen", „übermorgen", „in 5 Tagen" — ab $heute gerechnet. */
    public static function Abstand(string $datum, string $heute): string
    {
        $n = self::TageZwischen($heute, $datum);
        if ($n < 0) {
            return '';
        }
        return match ($n) {
            0 => 'heute',
            1 => 'morgen',
            2 => 'übermorgen',
            default => 'in ' . $n . ' Tagen',
        };
    }

    public static function Wochentag(string $datum): string
    {
        $t = strtotime($datum . ' 12:00:00');
        return $t === false ? '' : self::WOCHENTAGE[(int)date('w', $t)];
    }

    /**
     * Die Zeilen „KOMMENDE PRÜFUNGEN" fürs Briefing.
     *
     * Nur was NACH dem Briefingtag liegt — die Prüfung des Tages selbst steht
     * schon in der Schulzeile. Der Abstand wird ab HEUTE gerechnet, nicht ab dem
     * Briefingtag: die Abendvorschau wird heute gelesen, und „in drei Tagen"
     * muss für den stimmen, der es liest.
     *
     * @param array<string, list<array<string,mixed>>> $jeKind Name => Prüfungen
     * @return list<string>
     */
    public static function BriefingZeilen(array $jeKind, string $nach, string $bis, string $heute): array
    {
        $alle = [];
        foreach ($jeKind as $name => $liste) {
            foreach ((array)$liste as $p) {
                if (!is_array($p)) {
                    continue;
                }
                $d = (string)($p['date'] ?? '');
                if ($d <= $nach || $d > $bis || $d < $heute) {
                    continue;
                }
                $alle[] = ['name' => (string)$name, 'p' => $p];
            }
        }
        usort($alle, static fn(array $a, array $b): int
            => [$a['p']['date'], self::Minuten((string)$a['p']['start']), $a['name']]
            <=> [$b['p']['date'], self::Minuten((string)$b['p']['start']), $b['name']]);
        $zeilen = [];
        foreach (array_slice($alle, 0, self::BRIEFING_ZEILEN) as $e) {
            $p = $e['p'];
            $fach  = (string)($p['subject'] ?? '');
            $titel = (string)($p['title'] ?? '');
            $mitTitel = $titel !== '' && self::TitelNorm($titel) !== self::TitelNorm($fach);
            $thema = trim((string)($p['topic'] ?? ''));
            $zeilen[] = sprintf('%s: Prüfung %s%s%s am %s, %s um %s — %s%s',
                $e['name'], $fach, $mitTitel ? ' („' . $titel . '“)' : '',
                $thema !== '' ? ', Thema: ' . $thema : '',
                self::Wochentag((string)$p['date']), date('d.m.', (int)strtotime((string)$p['date'] . ' 12:00:00')),
                (string)$p['start'], self::Abstand((string)$p['date'], $heute),
                (string)($p['status'] ?? '') === 'entfall' ? ' (entfällt)' : '');
        }
        if (count($alle) > self::BRIEFING_ZEILEN) {
            $zeilen[] = sprintf('und %d weitere Prüfung(en)', count($alle) - self::BRIEFING_ZEILEN);
        }
        return $zeilen;
    }

    /**
     * Soll für diese Prüfung jetzt eine Lern-Erinnerung stehen?
     * Ab $tage Tagen vorher bis zum Vortag, nicht für entfallene.
     *
     * @param array<string,mixed> $p
     */
    public static function LernFaellig(array $p, string $heute, int $tage): bool
    {
        if ($tage <= 0 || (string)($p['status'] ?? '') === 'entfall') {
            return false;
        }
        $n = self::TageZwischen($heute, (string)($p['date'] ?? ''));
        return $n >= 1 && $n <= $tage;
    }

    /** So lang ist die Lernphase der Zeitleiste, wenn die Lern-Erinnerung aus ist. */
    public const LERN_ANZEIGE_TAGE = 7;

    /**
     * Der Lernstart der Zeitleiste: so viele Tage vor der Pruefung, wie die
     * Lern-Erinnerung vorausgeht (UntisExamStudyDays) — ist sie aus, eine Woche.
     * Dieselbe Zahl wie beim Anlegen der Erinnerung, sonst begaenne die Leiste
     * an einem anderen Tag als das Lernen.
     */
    public static function LernStart(string $datum, int $tage): string
    {
        $t = strtotime($datum . ' 12:00:00');
        if ($t === false) {
            return $datum;
        }
        return date('Y-m-d', $t - ($tage > 0 ? $tage : self::LERN_ANZEIGE_TAGE) * 86400);
    }

    /** Fällig ist die Lern-Erinnerung am Vortag — frühestens heute. */
    public static function LernDue(string $datum, string $heute): string
    {
        $t = strtotime($datum . ' 12:00:00');
        if ($t === false) {
            return $heute;
        }
        $vortag = date('Y-m-d', $t - 86400);
        return $vortag < $heute ? $heute : $vortag;
    }
}

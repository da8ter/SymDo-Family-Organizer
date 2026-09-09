<?php

declare(strict_types=1);

/**
 * Datenhaltung und Rechenwerk des Ämtchenplans.
 *
 * Alles Zustandsbehaftete liegt hier, damit ein Prüfstand es ohne Symcon
 * ausführen kann (Muster: Routines/libs/RoutineStore.php und
 * Stundenplan/libs/TimetableStore.php). Die Rotation ist reine Arithmetik —
 * genau der Teil, der einen Prüflauf verdient, und er hat einen:
 * Chores/tests/ChoresRotationTest.php.
 *
 * Die Zuweisung einer Woche wird BERECHNET und dann EINGEFROREN. Ein
 * gespeicherter Zeiger driftet (ein verpasster Timer überspringt eine Woche,
 * eine Rücksicherung springt zurück); eine rein berechnete Zuweisung würde
 * dagegen die laufende Woche mitten in der Woche neu verteilen, sobald jemand
 * eine Zeile ändert — Häkchen und gebuchte Punkte hingen dann an der falschen
 * Person. Also: die Formel ist die Wahrheit für jede Woche, die laufende steht
 * eingefroren im Attribut und wird beim Wechsel verworfen.
 */
trait ChoreStore
{
    /** Bezugspunkt der Wochenzählung. Ein Montag, damit der Index bei 0 anfängt. */
    private const EPOCHE = '2024-01-01';

    // ------------------------------------------------------------------
    // Konfiguration lesen
    // ------------------------------------------------------------------

    /**
     * Die Konfiguration der Instanz.
     *
     * IPS_GetConfiguration und NICHT ReadPropertyString: das sieht gestagte
     * Werte sofort und funktioniert auch, bevor nach einem Update der Kernel
     * neu gestartet wurde (dieselbe Begründung wie in RoutineStore).
     *
     * Der Prüfstand ersetzt genau diese eine Methode — deshalb steht sie hier
     * einmal und wird von allen Lesern benutzt.
     */
    private function Konfiguration(): array
    {
        $cfg = json_decode((string)@IPS_GetConfiguration($this->InstanceID), true);
        return is_array($cfg) ? $cfg : [];
    }

    private function EinstellungText(string $name, string $vorgabe = ''): string
    {
        $cfg = $this->Konfiguration();
        return array_key_exists($name, $cfg) ? (string)$cfg[$name] : $vorgabe;
    }

    private function EinstellungZahl(string $name, int $vorgabe = 0): int
    {
        $cfg = $this->Konfiguration();
        return array_key_exists($name, $cfg) ? (int)$cfg[$name] : $vorgabe;
    }

    private function EinstellungJa(string $name, bool $vorgabe = false): bool
    {
        $cfg = $this->Konfiguration();
        return array_key_exists($name, $cfg) ? (bool)$cfg[$name] : $vorgabe;
    }

    /**
     * Teilnehmer in der Reihenfolge des Formulars — und diese Reihenfolge IST
     * die Reihenfolge der Rotation.
     *
     * @return list<array{memberId:string,share:int,pause:bool}>
     */
    private function TeilnehmerLesen(): array
    {
        $zeilen = json_decode($this->EinstellungText('Members', '[]'), true);
        $raus = [];
        $gesehen = [];
        foreach (is_array($zeilen) ? $zeilen : [] as $z) {
            if (!is_array($z)) {
                continue;
            }
            $id = trim((string)($z['memberId'] ?? ''));
            // Doppelte Kennung wäre eine doppelte Position im Kreis: der
            // Betroffene bekäme zwei Ämtchen, ohne dass es jemand eingestellt hat.
            if ($id === '' || isset($gesehen[$id])) {
                continue;
            }
            $gesehen[$id] = true;
            $raus[] = [
                'memberId' => $id,
                'share'    => max(1, min(5, (int)($z['share'] ?? 1))),
                'pause'    => ($z['pause'] ?? false) === true,
            ];
        }
        return $raus;
    }

    /**
     * Die Ämtchen in der Reihenfolge des Formulars. Diese Reihenfolge ist der
     * Versatz innerhalb des Kreises: das erste Ämtchen geht an den ersten
     * Teilnehmer der Woche, das zweite an den nächsten.
     *
     * @return list<array{id:string,emoji:string,name:string,points:int,perWeek:int,circle:string}>
     */
    private function AemtchenLesen(): array
    {
        $zeilen = json_decode($this->EinstellungText('Chores', '[]'), true);
        $raus = [];
        foreach (is_array($zeilen) ? $zeilen : [] as $z) {
            if (!is_array($z)) {
                continue;
            }
            $id   = trim((string)($z['id'] ?? ''));
            $name = trim((string)($z['name'] ?? ''));
            if ($id === '' || $name === '') {
                continue;
            }
            $raus[] = [
                'id'      => $id,
                'emoji'   => trim((string)($z['emoji'] ?? '')),
                'name'    => $name,
                'points'  => max(0, (int)($z['points'] ?? 5)),
                'perWeek' => max(1, min(7, (int)($z['perWeek'] ?? 1))),
                'circle'  => trim((string)($z['circle'] ?? 'all')) ?: 'all',
            ];
        }
        return $raus;
    }

    /**
     * Fehlende Ämtchen-Kennungen nachtragen und die Liste zurückschreiben.
     *
     * Neue Zeilen kommen ohne Kennung aus dem Formular (die Spalte ist
     * unsichtbar, `add` liefert ''). Ohne Kennung hängt weder ein Häkchen noch
     * eine Zuweisung noch eine Variable am Ämtchen.
     */
    private function AemtchenNachtragen(): void
    {
        $cfg = $this->Konfiguration();
        if (!array_key_exists('Chores', $cfg)) {
            return;
        }
        $zeilen = json_decode((string)$cfg['Chores'], true);
        if (!is_array($zeilen) || $zeilen === []) {
            return;
        }
        $ergaenzt = false;
        foreach ($zeilen as &$z) {
            if (!is_array($z)) {
                continue;
            }
            if (trim((string)($z['id'] ?? '')) === '' && trim((string)($z['name'] ?? '')) !== '') {
                $z['id'] = bin2hex(random_bytes(8));
                $ergaenzt = true;
            }
        }
        unset($z);
        if (!$ergaenzt) {
            return;
        }
        @IPS_SetProperty($this->InstanceID, 'Chores', (string)json_encode($zeilen, JSON_UNESCAPED_UNICODE));
        $this->UebernehmenNachtragen();
    }

    // ------------------------------------------------------------------
    // Wochenrechnung
    // ------------------------------------------------------------------

    /** Der eingestellte erste Wochentag, 1 = Montag … 7 = Sonntag. */
    private function WochenStartTag(): int
    {
        return max(1, min(7, $this->EinstellungZahl('WeekStart', 1)));
    }

    /** Uhrzeit des Wochenwechsels als [Stunde, Minute]. */
    private function WechselZeit(): array
    {
        $roh = json_decode($this->EinstellungText('ResetTime', ''), true);
        if (is_array($roh)) {
            return [max(0, min(23, (int)($roh['hour'] ?? 3))), max(0, min(59, (int)($roh['minute'] ?? 0)))];
        }
        // Ältere oder von Hand gesetzte Werte können „HH:MM" sein.
        if (preg_match('/^(\d{1,2}):(\d{2})$/', trim($this->EinstellungText('ResetTime', '')), $m) === 1) {
            return [max(0, min(23, (int)$m[1])), max(0, min(59, (int)$m[2]))];
        }
        return [3, 0];
    }

    /**
     * Der Wochenstart zu einem Datum — IMMER nach hinten gerechnet.
     *
     * Die Hausmuster DatumInWoche() in MealPlan und TimetableCalc taugen hier
     * NICHT: sie rechnen `$tag - $ist` und springen bei einem späteren
     * Wochenstarttag nach vorn. Mit „Woche beginnt am Sonntag" lieferten sie
     * einen Wochenstart in der ZUKUNFT.
     *
     * 12:00 als Anker ist das Hausmuster gegen die Zeitumstellung.
     */
    private function WochenStart(string $datum, int $startTag): string
    {
        $d = new \DateTimeImmutable($datum . ' 12:00:00');
        $zurueck = ((int)$d->format('N') - max(1, min(7, $startTag)) + 7) % 7;
        return $d->modify(sprintf('-%d days', $zurueck))->format('Y-m-d');
    }

    /**
     * Die Kennung der Woche, zu der ein Zeitpunkt gehört. Die Wechselzeit wird
     * abgezogen: Montag 02:59 gehört bei Wechsel um 03:00 noch zur alten Woche
     * (dasselbe Vorgehen wie TagKennung() in den Routinen).
     */
    private function WochenKennung(int $jetzt): string
    {
        [$std, $min] = $this->WechselZeit();
        $datum = date('Y-m-d', $jetzt - ($std * 3600 + $min * 60));
        return $this->WochenStart($datum, $this->WochenStartTag());
    }

    /**
     * Die laufende Nummer einer Woche seit der Epoche.
     *
     * Bewusst NICHT die ISO-Kalenderwoche: Jahre mit KW 53 legen zwei ungerade
     * Wochen aneinander, und `KW % n` springt bei jedem Jahreswechsel. Der
     * Stundenplan darf die KW benutzen, weil er nur Parität braucht; eine
     * Rotation über vier Personen darf es nicht.
     *
     * floor() statt intdiv(): intdiv schneidet zur Null ab und liefert für
     * zwei verschiedene Wochen vor der Epoche dieselbe Zahl. %r%a statt ->days:
     * ->days ist immer positiv, das Vorzeichen ginge verloren.
     */
    private function WochenIndex(string $wochenStart): int
    {
        $ep = $this->WochenStart(self::EPOCHE, $this->WochenStartTag());
        $a = new \DateTimeImmutable($ep . ' 12:00:00');
        $b = new \DateTimeImmutable($wochenStart . ' 12:00:00');
        return (int)floor((int)$a->diff($b)->format('%r%a') / 7);
    }

    /**
     * Millisekunden bis zum nächsten Wochenwechsel.
     *
     * strtotime('+7 days') statt +604800 und die Uhrzeit frisch angehängt statt
     * gerechnet: sonst trifft der Wechsel in der Umstellungsnacht eine Stunde
     * daneben. max(60000, …) verhindert eine Timer-Schleife am Grenzfall.
     */
    private function NaechsterWechselMs(int $jetzt): int
    {
        [$std, $min] = $this->WechselZeit();
        $ts = (int)strtotime($this->WochenKennung($jetzt) . ' 12:00:00');
        for ($i = 0; $i < 4; $i++) {
            $ts = (int)strtotime('+7 days', $ts);
            $ziel = (int)strtotime(date('Y-m-d', $ts) . sprintf(' %02d:%02d:00', $std, $min));
            if ($ziel > $jetzt) {
                return max(60000, ($ziel - $jetzt) * 1000);
            }
        }
        return 3600000;
    }

    // ------------------------------------------------------------------
    // Rotation
    // ------------------------------------------------------------------

    /**
     * Der Kreis, durch den rotiert wird — in RUNDEN verzahnt, nicht in Blöcken.
     *
     * Bei „Last 2" für A und je 1 für B und C ergibt das A,B,C,A und nicht
     * A,A,B,C: sonst bekäme A beide Ämtchen in derselben Woche, statt in einer
     * Woche zweimal so oft dran zu sein wie die anderen.
     *
     * @return list<string>
     */
    private function Kreis(): array
    {
        $zeilen = $this->TeilnehmerLesen();
        $max = 1;
        foreach ($zeilen as $z) {
            $max = max($max, $z['share']);
        }
        $kreis = [];
        for ($runde = 0; $runde < $max; $runde++) {
            foreach ($zeilen as $z) {
                if ($z['share'] > $runde) {
                    $kreis[] = $z['memberId'];
                }
            }
        }
        return $kreis;
    }

    /**
     * Der Kreis für ein Ämtchen: alle, nur Kinder, nur Erwachsene oder genau
     * eine feste Person.
     *
     * @return list<string>
     */
    private function KreisFuer(string $art, array $rollen): array
    {
        $kreis = $this->Kreis();
        if ($art === 'all' || $art === '') {
            return $kreis;
        }
        if ($art === 'child' || $art === 'adult') {
            $raus = [];
            foreach ($kreis as $id) {
                $istKind = (($rollen[$id] ?? '') === 'child');
                if ($art === 'child' ? $istKind : !$istKind) {
                    $raus[] = $id;
                }
            }
            return $raus;
        }
        // Feste Person: der Kreis hat Länge 1, damit rotiert nichts.
        return in_array($art, $kreis, true) ? [$art] : [];
    }

    /** Kennungen, die diese Woche aussetzen. */
    private function Pausierte(): array
    {
        $raus = [];
        foreach ($this->TeilnehmerLesen() as $z) {
            if ($z['pause']) {
                $raus[$z['memberId']] = true;
            }
        }
        return $raus;
    }

    /**
     * Die erste nicht pausierte Person ab einer Position.
     *
     * Pausierte bleiben IM Kreis und werden nur übersprungen. Nimmt man sie
     * heraus, ändert sich die Kreisgröße und damit die Phase ALLER anderen —
     * nach dem Urlaub ginge die Reihe nicht dort weiter, wo sie war. Das ist
     * der Kern der Konstruktion und steht im Prüfstand als eigener Fall.
     */
    private function NaechsterAktive(array $kreis, int $pos, array $pausiert): string
    {
        $n = count($kreis);
        for ($i = 0; $i < $n; $i++) {
            $id = $kreis[($pos + $i) % $n];
            if (!isset($pausiert[$id])) {
                return $id;
            }
        }
        return '';
    }

    /**
     * Wer in der Woche mit dieser Nummer für welches Ämtchen zuständig ist.
     *
     * @return array<string,string> Ämtchen-Kennung => Mitglieds-Kennung ('' = niemand)
     */
    private function Zuweisung(int $index, array $rollen): array
    {
        $shift = $this->Verschiebung();
        $pausiert = $this->Pausierte();
        $versatz = [];
        $raus = [];
        foreach ($this->AemtchenLesen() as $a) {
            $kreis = $this->KreisFuer($a['circle'], $rollen);
            $n = count($kreis);
            if ($n === 0) {
                // VOR dem Modulo aussteigen — sonst Division durch null.
                $raus[$a['id']] = '';
                continue;
            }
            $v = $versatz[$a['circle']] = ($versatz[$a['circle']] ?? -1) + 1;
            // Doppelt geklemmt, damit ein negativer Shift nicht negativ bleibt.
            $pos = (($index + $shift + $v) % $n + $n) % $n;
            $raus[$a['id']] = $this->NaechsterAktive($kreis, $pos, $pausiert);
        }
        return $raus;
    }

    /** Die Handkurbel: verschiebt die Rotation um Personen, ab nächster Woche. */
    private function Verschiebung(): int
    {
        return (int)@$this->ReadAttributeInteger('Shift');
    }

    /**
     * Vorschau: wer in den kommenden Wochen dran ist. Rein gerechnet, ohne
     * etwas zu schreiben — die Vorschau darf die laufende Woche nicht anfassen.
     *
     * @return list<array{week:string,assign:array<string,string>}>
     */
    private function Vorschau(int $jetzt, int $wochen): array
    {
        $rollen = $this->Rollen();
        $start = $this->WochenKennung($jetzt);
        $raus = [];
        for ($i = 1; $i <= max(1, $wochen); $i++) {
            $woche = (new \DateTimeImmutable($start . ' 12:00:00'))
                ->modify(sprintf('+%d days', 7 * $i))->format('Y-m-d');
            $raus[] = ['week' => $woche, 'assign' => $this->Zuweisung($this->WochenIndex($woche), $rollen)];
        }
        return $raus;
    }

    // ------------------------------------------------------------------
    // Woche: einfrieren, wechseln, abhaken
    // ------------------------------------------------------------------

    private function WocheLesen(): array
    {
        $w = json_decode((string)@$this->ReadAttributeString('Week'), true);
        return is_array($w) ? $w : [];
    }

    private function WocheSchreiben(array $w): void
    {
        @$this->WriteAttributeString('Week', (string)json_encode($w, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Die laufende Woche, notfalls neu gebaut. Der EINZIGE Schreiber des
     * Wochenzustands.
     *
     * Drei Wege führen hierher: der Wochentimer, ApplyChanges und das Zeichnen
     * der Kachel. Der letzte ist der träge Nachhol-Pfad — war die Box über das
     * Wochenende aus, rollt der erste Kachelaufruf um (dasselbe Muster wie
     * ZustandAktuell() in den Routinen).
     */
    private function WocheSicherstellen(int $jetzt): array
    {
        $kennung = $this->WochenKennung($jetzt);
        $rollen  = $this->Rollen();
        $woche   = $this->WocheLesen();
        $aemtchen = $this->AemtchenLesen();
        $ids = [];
        foreach ($aemtchen as $a) {
            $ids[$a['id']] = true;
        }

        if (($woche['week'] ?? '') === $kennung) {
            $geaendert = false;
            $zuweisung = is_array($woche['assign'] ?? null) ? $woche['assign'] : [];
            $erledigt  = is_array($woche['done'] ?? null) ? $woche['done'] : [];
            // Ein mitten in der Woche angelegtes Ämtchen bekommt sofort einen
            // Zuständigen — „eingefroren" darf nicht „unsichtbar" heißen.
            $frisch = $this->Zuweisung((int)($woche['index'] ?? $this->WochenIndex($kennung)), $rollen);
            foreach ($aemtchen as $a) {
                if (!array_key_exists($a['id'], $zuweisung)) {
                    $zuweisung[$a['id']] = $frisch[$a['id']] ?? '';
                    $geaendert = true;
                }
            }
            // Waisen gelöschter Ämtchen wegräumen, sonst wächst das Attribut.
            foreach (array_keys($zuweisung) as $id) {
                if (!isset($ids[$id])) {
                    unset($zuweisung[$id]);
                    $geaendert = true;
                }
            }
            foreach (array_keys($erledigt) as $id) {
                if (!isset($ids[$id])) {
                    unset($erledigt[$id]);
                    $geaendert = true;
                }
            }
            if ($geaendert) {
                $woche['assign'] = $zuweisung;
                $woche['done']   = $erledigt;
                $this->WocheSchreiben($woche);
            }
            return $woche;
        }

        // Wochenwechsel. Es wird NICHTS gebucht und nichts zurückgenommen —
        // nur die Häkchen fallen.
        $alt = $woche;
        $index = $this->WochenIndex($kennung);
        $neu = [
            'week'   => $kennung,
            'index'  => $index,
            'assign' => $this->Zuweisung($index, $rollen),
            'carry'  => [],
            'done'   => [],
        ];
        if ($this->EinstellungJa('CarryOver') && ($alt['week'] ?? '') !== '') {
            $neu['carry'] = $this->UebertragBauen($alt, $aemtchen);
        }
        if (($alt['week'] ?? '') !== '') {
            @$this->WriteAttributeString('LastWeek', (string)json_encode([
                'week'   => $alt['week'],
                'assign' => is_array($alt['assign'] ?? null) ? $alt['assign'] : [],
                'done'   => is_array($alt['done'] ?? null) ? $alt['done'] : [],
            ], JSON_UNESCAPED_UNICODE));
        }
        $this->WocheSchreiben($neu);
        return $neu;
    }

    /**
     * Was aus der Vorwoche offen blieb, als Übertragsplätze — beim ALTEN Halter,
     * denn geschuldet ist geschuldet.
     *
     * Höchstens eine Woche tief: ein Übertrag wird nicht wieder übertragen,
     * sonst wächst einem Kind ein Schuldenberg, den es nie abarbeitet. Eigener
     * Schlüsselraum („c0", „c1"), damit sich Übertrag und Normalplatz im
     * done-Feld nicht überschreiben.
     */
    private function UebertragBauen(array $alt, array $aemtchen): array
    {
        $altDone = is_array($alt['done'] ?? null) ? $alt['done'] : [];
        $altZu   = is_array($alt['assign'] ?? null) ? $alt['assign'] : [];
        $raus = [];
        foreach ($aemtchen as $a) {
            $halter = trim((string)($altZu[$a['id']] ?? ''));
            if ($halter === '') {
                continue;
            }
            $erledigt = is_array($altDone[$a['id']] ?? null) ? $altDone[$a['id']] : [];
            $offen = 0;
            for ($i = 0; $i < $a['perWeek']; $i++) {
                if (!isset($erledigt[(string)$i])) {
                    $offen++;
                }
            }
            if ($offen > 0) {
                $raus[$a['id']] = ['count' => $offen, 'memberId' => $halter];
            }
        }
        return $raus;
    }

    /**
     * Die Plätze eines Ämtchens in dieser Woche: die regulären und die
     * Überträge der Vorwoche.
     *
     * @return list<array{key:string,done:bool,points:int,memberId:string,carried:bool}>
     */
    private function PlaetzeFuer(array $woche, array $a): array
    {
        $erledigt = is_array($woche['done'][$a['id']] ?? null) ? $woche['done'][$a['id']] : [];
        $zustaendig = trim((string)($woche['assign'][$a['id']] ?? ''));
        $raus = [];
        for ($i = 0; $i < $a['perWeek']; $i++) {
            $k = (string)$i;
            $satz = is_array($erledigt[$k] ?? null) ? $erledigt[$k] : null;
            $raus[] = [
                'key'      => $k,
                'done'     => $satz !== null,
                'points'   => $satz !== null ? (int)($satz['p'] ?? 0) : $a['points'],
                'memberId' => $satz !== null ? (string)($satz['m'] ?? '') : $zustaendig,
                'carried'  => false,
            ];
        }
        $uebertrag = is_array($woche['carry'][$a['id']] ?? null) ? $woche['carry'][$a['id']] : null;
        if ($uebertrag !== null) {
            $halter = trim((string)($uebertrag['memberId'] ?? ''));
            for ($i = 0; $i < max(0, (int)($uebertrag['count'] ?? 0)); $i++) {
                $k = 'c' . $i;
                $satz = is_array($erledigt[$k] ?? null) ? $erledigt[$k] : null;
                $raus[] = [
                    'key'      => $k,
                    'done'     => $satz !== null,
                    'points'   => $satz !== null ? (int)($satz['p'] ?? 0) : $a['points'],
                    'memberId' => $satz !== null ? (string)($satz['m'] ?? '') : $halter,
                    'carried'  => true,
                ];
            }
        }
        return $raus;
    }

    /**
     * Ein Häkchen setzen oder zurücknehmen.
     *
     * Immer mit ZIELZUSTAND und nie als Umschalter: eine doppelt zugestellte
     * Anfrage darf das Häkchen nicht zurücknehmen (Lehre aus der
     * Einkaufs-Übersichtskachel). Idempotent, damit ein Doppeltipp nicht
     * zweimal bucht.
     *
     * Punktwert UND Halter werden beim Abhaken gemerkt. Die Routinen merken
     * nur den Wert — dort kann der Beutel nicht wechseln, hier schon (Handkurbel,
     * gelöschte Zeile). Ohne den Halter erstattete eine Rücknahme dem Falschen.
     */
    private function Abhaken(string $wochenKennung, string $choreId, string $platz, bool $ziel, int $jetzt): bool
    {
        $woche = $this->WocheSicherstellen($jetzt);
        /* Eine Kachel, die über den Wochenwechsel offen im Browser stand, würde
           sonst beim nächsten Tippen in die NEUE Woche buchen. */
        if ($wochenKennung !== '' && $wochenKennung !== (string)($woche['week'] ?? '')) {
            return false;
        }
        $treffer = null;
        foreach ($this->AemtchenLesen() as $a) {
            if ($a['id'] === $choreId) {
                $treffer = $a;
                break;
            }
        }
        if ($treffer === null) {
            return false;
        }
        $platzDa = false;
        $halter = '';
        $wert = $treffer['points'];
        foreach ($this->PlaetzeFuer($woche, $treffer) as $p) {
            if ($p['key'] === $platz) {
                $platzDa = true;
                $halter = $p['memberId'];
                if (!$ziel) {
                    $wert = $p['points'];
                }
                break;
            }
        }
        if (!$platzDa) {
            return false;
        }
        $erledigt = is_array($woche['done'][$choreId] ?? null) ? $woche['done'][$choreId] : [];
        $war = isset($erledigt[$platz]);
        if ($war === $ziel) {
            return true;
        }
        if ($ziel) {
            $erledigt[$platz] = ['p' => $wert, 'm' => $halter];
            $this->PunkteBuchen($halter, $wert);
        } else {
            $alt = is_array($erledigt[$platz]) ? $erledigt[$platz] : [];
            $this->PunkteBuchen((string)($alt['m'] ?? $halter), -(int)($alt['p'] ?? $wert));
            unset($erledigt[$platz]);
        }
        if ($erledigt === []) {
            unset($woche['done'][$choreId]);
        } else {
            $woche['done'][$choreId] = $erledigt;
        }
        $this->WocheSchreiben($woche);
        return true;
    }

    // ------------------------------------------------------------------
    // Punkte
    // ------------------------------------------------------------------

    /**
     * Wie es um die Punkte steht: aus, keine Routinen-Instanz, mehrere
     * (unklar) oder buchbar. Der Modus reist in die Kachel, damit sie schweigt
     * statt zu lügen.
     */
    private function PunkteModus(): string
    {
        if (!$this->EinstellungJa('PointsEnabled')) {
            return 'off';
        }
        $gewaehlt = $this->EinstellungZahl('RoutinesInstanceID');
        if ($gewaehlt > 0) {
            return $this->RoutinenInstanz() > 0 ? 'routines' : 'unavailable';
        }
        $ids = (array)@IPS_GetInstanceListByModuleID(self::ROUTINES_GUID);
        if ($ids === []) {
            return 'unavailable';
        }
        return count($ids) === 1 ? 'routines' : 'ambiguous';
    }

    /**
     * Die Routinen-Instanz, in deren Münzbeutel gebucht wird.
     *
     * Automatisch nur bei GENAU EINER. Beim Gateway wählt GatewayInstanz() die
     * niedrigste ID, dort ist eine falsche Wahl kosmetisch (ein falscher
     * Avatar); hier schriebe sie Punkte in den falschen Beutel.
     */
    private function RoutinenInstanz(): int
    {
        $gewaehlt = $this->EinstellungZahl('RoutinesInstanceID');
        if ($gewaehlt > 0 && @IPS_InstanceExists($gewaehlt)
            && (@IPS_GetInstance($gewaehlt)['ModuleInfo']['ModuleID'] ?? '') === self::ROUTINES_GUID) {
            return $gewaehlt;
        }
        $ids = (array)@IPS_GetInstanceListByModuleID(self::ROUTINES_GUID);
        return count($ids) === 1 ? (int)$ids[0] : 0;
    }

    /**
     * Punkte in den Münzbeutel der Routinen buchen.
     *
     * Präfix-Funktion mit function_exists und try/catch — die Bibliothek kennt
     * keine Datenflüsse, und ein fehlendes Routinen-Modul darf nichts werfen.
     *
     * Fehlt die Instanz, wird NICHTS für später vorgemerkt: eine Warteschlange
     * würde nach einer Rücksicherung doppelt buchen, und eine stille
     * Doppelgutschrift ist schlimmer als eine ausgefallene.
     */
    private function PunkteBuchen(string $memberId, int $delta): void
    {
        if ($memberId === '' || $delta === 0 || $this->PunkteModus() !== 'routines') {
            return;
        }
        $inst = $this->RoutinenInstanz();
        if ($inst <= 0 || !function_exists('RTN_AdjustCoins')) {
            return;
        }
        try {
            $neu = (int)RTN_AdjustCoins($inst, $memberId, $delta);
            $spiegel = json_decode((string)@$this->ReadAttributeString('PurseMirror'), true);
            $spiegel = is_array($spiegel) ? $spiegel : [];
            $spiegel[$memberId] = $neu;
            @$this->WriteAttributeString('PurseMirror', (string)json_encode($spiegel));
        } catch (\Throwable $e) {
            $this->SendDebug('PunkteBuchen', $e->getMessage(), 0);
        }
    }

    /**
     * Der Münzstand je Mitglied. Der Spiegel ist die Rückfalllösung; existiert
     * die Variable in der Routinen-Instanz, gilt sie — die Eltern können
     * zwischendurch von Hand eingelöst haben.
     *
     * COINS_<Kennung> gibt es nur für Kinder, die auch in einer Routine
     * vorkommen; für die anderen bleibt nur der Spiegel.
     */
    private function Muenzstaende(): array
    {
        $spiegel = json_decode((string)@$this->ReadAttributeString('PurseMirror'), true);
        $raus = is_array($spiegel) ? $spiegel : [];
        $inst = $this->PunkteModus() === 'routines' ? $this->RoutinenInstanz() : 0;
        if ($inst > 0) {
            foreach ($this->TeilnehmerLesen() as $z) {
                /* try/catch und nicht nur @: fehlt die Variable, WIRFT Symcon
                   hier (nicht nur eine Warnung), und eine Ausnahme mitten im
                   Aufbau der Nutzlast liesse die Kachel leer. Ein Kind ohne
                   Routine hat kein COINS_ — der Spiegel genuegt dann. */
                try {
                    $varID = @IPS_GetObjectIDByIdent('COINS_' . $z['memberId'], $inst);
                    if (is_int($varID) && $varID > 0) {
                        $raus[$z['memberId']] = (int)@GetValue($varID);
                    }
                } catch (\Throwable $e) {
                    // kein Konto sichtbar — der Spiegel bleibt
                }
            }
        }
        return $raus;
    }

    // ------------------------------------------------------------------
    // Mitglieder aus dem Gateway
    // ------------------------------------------------------------------

    /**
     * Das zuständige Gateway: erst die Eltern-Instanz aus der Konsole, sonst
     * das Gateway mit der niedrigsten ID (wie in den Routinen).
     */
    private function GatewayInstanz(): int
    {
        $eltern = (int)(@IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        if ($eltern > 0) {
            return $eltern;
        }
        $ids = @IPS_GetInstanceListByModuleID(self::GATEWAY_GUID);
        if (!is_array($ids) || $ids === []) {
            return 0;
        }
        sort($ids);
        return (int)$ids[0];
    }

    /**
     * Alle Mitglieder mit Namen und Avatar. Kennung => ['name','avatar','persona'].
     */
    private function Mitglieder(): array
    {
        $gw = $this->GatewayInstanz();
        if ($gw <= 0 || !function_exists('TGW_GetUsersForTile')) {
            return [];
        }
        try {
            $roh = json_decode((string)@TGW_GetUsersForTile($gw), true);
        } catch (\Throwable $e) {
            return [];
        }
        $karte = [];
        foreach (is_array($roh) ? $roh : [] as $u) {
            $id = is_array($u) ? trim((string)($u['id'] ?? '')) : '';
            if ($id === '') {
                continue;
            }
            $karte[$id] = [
                'name'    => trim((string)($u['name'] ?? '')),
                'avatar'  => (string)($u['avatar'] ?? ''),
                'persona' => strtolower(trim((string)($u['persona'] ?? ''))),
            ];
        }
        return $karte;
    }

    /** Kennung => Rolle. Eigene Methode, weil der Prüfstand nur sie ersetzen muss. */
    private function Rollen(): array
    {
        $raus = [];
        foreach ($this->Mitglieder() as $id => $m) {
            $raus[$id] = (string)($m['persona'] ?? '');
        }
        return $raus;
    }

    /**
     * Auswahloptionen fürs Formular: ALLE Mitglieder, Kinder zuerst — anders
     * als bei den Routinen, denn Ämtchen machen Eltern mit.
     *
     * @return list<array{caption:string,value:string}>
     */
    private function MitgliederOptionen(): array
    {
        $gw = $this->GatewayInstanz();
        $optionen = [['caption' => $this->Translate('— none —'), 'value' => '']];
        if ($gw <= 0 || !function_exists('TGW_GetUsers')) {
            return $optionen;
        }
        try {
            $roh = json_decode((string)@TGW_GetUsers($gw), true);
        } catch (\Throwable $e) {
            return $optionen;
        }
        $kinder = [];
        $andere = [];
        foreach (is_array($roh) ? $roh : [] as $u) {
            if (!is_array($u)) {
                continue;
            }
            $id = trim((string)($u['id'] ?? ''));
            $name = trim((string)($u['name'] ?? ''));
            if ($id === '' || $name === '') {
                continue;
            }
            if (strtolower(trim((string)($u['persona'] ?? ''))) === 'child') {
                $kinder[$id] = $name;
            } else {
                $andere[$id] = $name;
            }
        }
        foreach ($kinder + $andere as $id => $name) {
            $optionen[] = ['caption' => $name, 'value' => $id];
        }
        /* Eine gespeicherte, im Gateway nicht mehr vorhandene Kennung ehrlich
           anzeigen statt still auf „keins" zu fallen (Muster aus dem
           Essensplan): sonst verschwindet eine Zuordnung unbemerkt. */
        $bekannt = array_column($optionen, 'value');
        foreach ($this->TeilnehmerLesen() as $z) {
            if (!in_array($z['memberId'], $bekannt, true)) {
                $optionen[] = [
                    'caption' => sprintf($this->Translate('%s (not found)'), $z['memberId']),
                    'value'   => $z['memberId'],
                ];
            }
        }
        return $optionen;
    }

    // ------------------------------------------------------------------
    // Nutzlast der Kachel
    // ------------------------------------------------------------------

    /**
     * Alles, was die Kachel braucht — und nur das. Ruft als erstes
     * WocheSicherstellen(): das ist der träge Nachhol-Pfad.
     */
    private function PayloadBauen(int $jetzt): array
    {
        $woche = $this->WocheSicherstellen($jetzt);
        $aemtchen = $this->AemtchenLesen();
        $mitglieder = $this->Mitglieder();
        $modus = $this->PunkteModus();
        $pausiert = $this->Pausierte();

        $reihenfolge = [];
        $leute = [];
        foreach ($this->TeilnehmerLesen() as $z) {
            $reihenfolge[] = $z['memberId'];
            $m = $mitglieder[$z['memberId']] ?? null;
            $leute[$z['memberId']] = [
                'name'    => $m !== null ? (string)$m['name'] : $z['memberId'],
                'avatar'  => $m !== null ? (string)$m['avatar'] : '',
                'persona' => $m !== null ? (string)$m['persona'] : '',
                'paused'  => isset($pausiert[$z['memberId']]),
                'known'   => $m !== null,
            ];
        }

        $liste = [];
        $verdient = [];
        $gesamt = 0;
        $fertig = 0;
        foreach ($aemtchen as $a) {
            $plaetze = $this->PlaetzeFuer($woche, $a);
            $erledigt = 0;
            foreach ($plaetze as $p) {
                $gesamt++;
                if ($p['done']) {
                    $erledigt++;
                    $fertig++;
                    if ($p['memberId'] !== '') {
                        $verdient[$p['memberId']] = ($verdient[$p['memberId']] ?? 0) + $p['points'];
                    }
                }
            }
            $liste[] = [
                'id'        => $a['id'],
                'name'      => $a['name'],
                'emoji'     => $a['emoji'],
                'points'    => $a['points'],
                'memberId'  => (string)($woche['assign'][$a['id']] ?? ''),
                'doneCount' => $erledigt,
                'total'     => count($plaetze),
                'slots'     => $plaetze,
            ];
        }

        $letzte = json_decode((string)@$this->ReadAttributeString('LastWeek'), true);
        $letzteZusammen = null;
        if (is_array($letzte) && ($letzte['week'] ?? '') !== '' && $this->EinstellungJa('ShowLastWeek', true)) {
            $je = [];
            foreach ($aemtchen as $a) {
                $halter = trim((string)($letzte['assign'][$a['id']] ?? ''));
                if ($halter === '') {
                    continue;
                }
                $erledigt = is_array($letzte['done'][$a['id']] ?? null) ? count($letzte['done'][$a['id']]) : 0;
                $je[$halter] = [
                    'done'  => ($je[$halter]['done'] ?? 0) + $erledigt,
                    'total' => ($je[$halter]['total'] ?? 0) + $a['perWeek'],
                ];
            }
            $letzteZusammen = ['week' => (string)$letzte['week'], 'perMember' => $je];
        }

        $naechste = null;
        if ($this->EinstellungJa('ShowNextWeek', true)) {
            $vor = $this->Vorschau($jetzt, 1);
            $naechste = $vor === [] ? null : $vor[0];
        }

        return [
            'type'       => 'state',
            'week'       => (string)($woche['week'] ?? ''),
            'index'      => (int)($woche['index'] ?? 0),
            'start'      => $this->WochenStartTag(),
            'pointsMode' => $modus,
            'carryOver'  => $this->EinstellungJa('CarryOver'),
            'order'      => $reihenfolge,
            'members'    => $leute,
            'chores'     => $liste,
            'progress'   => $gesamt > 0 ? (int)round($fertig * 100 / $gesamt) : 0,
            'doneCount'  => $fertig,
            'totalCount' => $gesamt,
            'earned'     => $verdient,
            'purse'      => $modus === 'routines' ? $this->Muenzstaende() : [],
            'next'       => $naechste,
            'last'       => $letzteZusammen,
            'texts'      => $this->KachelTexte(),
        ];
    }

    /** Die Kachel übersetzt nichts selbst — die Texte reisen mit. */
    private function KachelTexte(): array
    {
        return [
            'title'    => $this->Translate('Chores'),
            'of'       => $this->Translate('%1$d of %2$d'),
            'nobody'   => $this->Translate('— none —'),
            'paused'   => $this->Translate('Pause'),
            'family'   => $this->Translate('All participants'),
            'carried'  => $this->Translate('from last week'),
            'lastWeek' => $this->Translate('Last week'),
            'nextWeek' => $this->Translate('Next week'),
            'thisWeek' => $this->Translate('This week'),
            'points'   => $this->Translate('Points'),
            'empty'    => $this->Translate('No chores configured yet — add participants and chores in the instance settings.'),
            'preview'  => $this->Translate('Preview — ticking starts on Monday'),
            'review'   => $this->Translate('Done and gone — this week is over'),
            'carriedHint' => $this->Translate('Dashed circle: carried over from last week'),
        ];
    }
}

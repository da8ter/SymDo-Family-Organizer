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
 * eine Zeile ändert — die Häkchen hingen dann an der falschen
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
     * @return list<array{id:string,icon:string,name:string,perWeek:int,circle:string,rotate:string,color:string}>
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
            $tage = $this->TageEinesAemtchens($z);
            $raus[] = [
                'id'      => $id,
                /* Der Font-Awesome-Name ohne „fa-" (so legt SelectIcon ab).
                   Leer heisst: die Kachel nimmt ihr Standardsymbol. */
                'icon'    => trim((string)($z['icon'] ?? '')),
                /* Wie oft der Kreis weiterrückt: einmal je WOCHE (Vorgabe, wie
                   bisher) oder an jedem TAG, an dem das Ämtchen ansteht. */
                'rotate'  => (string)($z['rotate'] ?? 'week') === 'day' ? 'day' : 'week',
                /* Die gewählte Farbe als '#rrggbb' — oder leer. */
                'color'   => $this->Farbe($z['color'] ?? -1),
                'name'    => $name,
                /* Wie oft in der Woche — das sagen jetzt die TAGE. Die alte
                   Spalte bleibt nur als Rückfall für Zeilen, die noch keine
                   Tage tragen. */
                'perWeek' => count($tage),
                'days'    => $tage,
                'circle'  => trim((string)($z['circle'] ?? 'all')) ?: 'all',
                /* Die Muenzbelohnung (zurueck seit dem 18.09.2026, jetzt mit
                   eigenem Beutel statt ueber die Routinen): so viele Muenzen
                   bekommt ein KIND, wenn es diesen Platz abhakt. */
                'coins'   => max(0, min(999, (int)($z['coins'] ?? 1))),
            ];
        }
        return $raus;
    }

    /**
     * An welchen Wochentagen ist dieses Ämtchen dran?
     *
     * Die Tage stehen als sieben Haken in der Zeile (`d1` = Montag … `d7` =
     * Sonntag, nach ISO). Zurück kommt eine aufsteigende Liste dieser Nummern,
     * in der REIHENFOLGE DER ANZEIGE — beginnt die Woche am Sonntag, steht die
     * 7 also vorn. Daran hängt, in welcher Spalte ein Häkchen landet.
     *
     * **Zeilen aus der Zeit vor den Tagesspalten** tragen keinen einzigen
     * Haken. Für sie gilt die alte Angabe „n-mal pro Woche": sie bekommen die
     * ersten n Tage der Woche. So sieht ein bestehender Plan nach dem Update
     * genauso aus wie vorher, nur eben in Spalten.
     *
     * @param array<string,mixed> $zeile
     * @return list<int>
     */
    /**
     * Eine gewählte Farbe als Hexwert für die Kachel.
     *
     * `SelectColor` legt eine Zahl ab (0xRRGGBB); -1 heißt „keine gewählt",
     * und ältere Listen haben das Feld gar nicht. Beides ergibt einen leeren
     * String — die Kachel nimmt dann ihre eigene Leiter.
     */
    private function Farbe(mixed $roh): string
    {
        $wert = is_numeric($roh) ? (int)$roh : -1;
        return ($wert < 0 || $wert > 0xFFFFFF) ? '' : sprintf('#%06x', $wert);
    }

    private function TageEinesAemtchens(array $zeile): array
    {
        $start = $this->WochenStartTag();
        $tage = [];
        for ($i = 0; $i < 7; $i++) {
            $iso = (($start - 1 + $i) % 7) + 1;
            if (($zeile['d' . $iso] ?? false) === true) {
                $tage[] = $iso;
            }
        }
        if ($tage !== []) {
            return $tage;
        }
        /* Rückfall für Zeilen ohne Tage. Mindestens einer, sonst hätte das
           Ämtchen gar keinen Platz mehr und verschwände lautlos aus dem Plan. */
        $wie_oft = max(1, min(7, (int)($zeile['perWeek'] ?? 1)));
        for ($i = 0; $i < $wie_oft; $i++) {
            $tage[] = (($start - 1 + $i) % 7) + 1;
        }
        return $tage;
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

    /**
     * Einmalig: aus dem alten Emoji ein Symbol machen.
     *
     * Bis zum 17.09.2026 stand in der Ämtchen-Liste ein Emoji als Text. Die
     * Spalte ist jetzt eine Symbolauswahl (`SelectIcon`, Font-Awesome-Name ohne
     * „fa-"). Ohne diesen Nachtrag stünde in jedem bestehenden Plan derselbe
     * Besen, bis jemand alle Zeilen von Hand anfasst.
     *
     * Die Tabelle deckt ab, was in Haushaltslisten vorkommt; alles andere
     * bleibt leer und bekommt in der Kachel den Besen. Ein Symbol, das die
     * Visu nicht kennt, wäre schlimmer als keins — deshalb stehen hier nur
     * Namen, die in der mitgelieferten Symbolschrift wirklich vorhanden sind
     * (gegen icons.js geprüft, 17.09.2026).
     */
    private function AemtchenSymboleWandern(): void
    {
        $cfg = $this->Konfiguration();
        if (!array_key_exists('Chores', $cfg)) {
            return;
        }
        $zeilen = json_decode((string)$cfg['Chores'], true);
        if (!is_array($zeilen) || $zeilen === []) {
            return;
        }
        $tabelle = [
            '🗑' => 'trash-can', '♻' => 'recycle',
            '🍽' => 'utensils', '🍴' => 'utensils', '🥄' => 'utensils', '🍳' => 'utensils',
            '🧽' => 'soap', '🧼' => 'soap', '🧴' => 'soap',
            '🧹' => 'broom', '🧺' => 'shirt', '👕' => 'shirt', '👚' => 'shirt',
            '🛁' => 'bath', '🚿' => 'shower', '🚽' => 'bath',
            '🌱' => 'seedling', '🌿' => 'seedling', '🪴' => 'seedling', '🌸' => 'seedling',
            '💐' => 'seedling', '🌺' => 'seedling',
            '🐾' => 'paw', '🐶' => 'dog', '🐱' => 'cat', '🐟' => 'fish', '🐠' => 'fish',
            '🛏' => 'bed', '📚' => 'book', '📖' => 'book', '🚗' => 'car', '🪟' => 'window-frame',
            '✨' => 'sparkles', '☕' => 'mug-hot', '🍷' => 'wine-glass',
        ];
        $geaendert = false;
        foreach ($zeilen as &$z) {
            if (!is_array($z) || trim((string)($z['icon'] ?? '')) !== '') {
                continue;
            }
            /* Emojis reisen oft mit einer Variantenwahl (U+FE0F) oder als
               Tastenfolge — verglichen wird deshalb der erste Bildpunkt. */
            $emoji = trim((string)($z['emoji'] ?? ''));
            if ($emoji === '') {
                continue;
            }
            $zeichen = mb_substr($emoji, 0, 1, 'UTF-8');
            if (!isset($tabelle[$zeichen])) {
                continue;
            }
            $z['icon'] = $tabelle[$zeichen];
            $geaendert = true;
        }
        unset($z);
        if (!$geaendert) {
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
                    continue;
                }
                /* Eingefroren heisst „die Reihe steht", nicht „die Regel gilt
                   nicht mehr": wer nicht mehr in den Kreis dieses Aemtchens
                   gehoert, wird ersetzt. Sonst traegt eine Mutter bis Sonntag
                   ein Aemtchen, das auf „Nur Kinder" steht — genau so gemeldet
                   am 17.09.2026, nachdem die Spalte „Wer" mitten in der Woche
                   umgestellt wurde. Schon Abgehaktes bleibt unberuehrt: dort
                   steht am Platz, wer es wirklich getan hat. */
                $halter = trim((string)$zuweisung[$a['id']]);
                $kreis = $this->KreisFuer($a['circle'], $rollen);
                /* Ein LEERER Kreis heisst „ich kann es gerade nicht sagen",
                   nicht „hier gehoert niemand hin". Genau das passiert, wenn die
                   Mitgliederauskunft ausfaellt: `Rollen()` fragt das Gateway, und
                   ein Ruf zurueck in eine gerade beschaeftigte Gateway-Instanz
                   kommt nicht durch (gemessen am 17.09.2026, als der
                   Sprachassistent CHR_GetState aus dem Gateway-Hook heraus rief).
                   Ohne diese Zeile leerte dieser eine Lesezugriff die Zuweisung
                   der ganzen Woche — ein Lesen, das schreibt. */
                if ($kreis === []) {
                    continue;
                }
                if ($halter === '' || !in_array($halter, $kreis, true)) {
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
     * @return list<array{key:string,done:bool,memberId:string,carried:bool}>
     */
    /**
     * Die sieben Wochentage als Kuerzel, beginnend beim eingestellten ersten
     * Tag der Woche.
     *
     * Uebersetzt wie alles andere auch: die Kachel bekommt fertige Woerter und
     * kennt keine Wochentagsnamen.
     *
     * @return list<string>
     */
    private function WochentagKuerzel(): array
    {
        $alle = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        $start = $this->WochenStartTag();
        $raus = [];
        for ($i = 0; $i < 7; $i++) {
            $raus[] = $this->Translate($alle[(($start - 1 + $i) % 7)]);
        }
        return $raus;
    }

    /**
     * Liegt diese Spalte dieser Woche noch in der Zukunft?
     *
     * Vergangene Wochen sind ganz frei — dort wird nachgetragen. Kuenftige
     * Wochen sind ganz gesperrt. In der laufenden Woche zaehlt der Vergleich
     * mit der Spalte von heute.
     */
    private function InDerZukunft(string $wochenKennung, int $spalte, int $jetzt): bool
    {
        if ($wochenKennung === '' || $spalte < 0) {
            return false;
        }
        $heute = $this->WochenKennung($jetzt);
        if ($wochenKennung < $heute) {
            return false;
        }
        if ($wochenKennung > $heute) {
            return true;
        }
        return $spalte > $this->SpalteHeute($wochenKennung, $jetzt);
    }

    /**
     * In welcher Spalte steht der heutige Tag — und steht er ueberhaupt drin?
     *
     * Die Wochenkennung IST das Startdatum der Woche, die Spalten sind die
     * sieben Tage danach. Gehoert „heute" nicht zu dieser Woche, kommt -1:
     * dann ist es eine vergangene oder eine kuenftige Woche.
     */
    private function SpalteHeute(string $wochenKennung, int $jetzt): int
    {
        if ($wochenKennung === '') {
            return -1;
        }
        $heute = $this->WochenKennung($jetzt);
        if ($heute === $wochenKennung) {
            /* Innerhalb der laufenden Woche: der Abstand in Tagen zwischen dem
               Wochenstart und dem heutigen Tag — gerechnet auf derselben
               verschobenen Uhr wie die Wochenkennung, damit Montag 02:59 bei
               Wechsel um 03:00 noch als Sonntag zaehlt. */
            [$std, $min] = $this->WechselZeit();
            $tag = date('Y-m-d', $jetzt - ($std * 3600 + $min * 60));
            $a = new \DateTimeImmutable($wochenKennung . ' 12:00:00');
            $b = new \DateTimeImmutable($tag . ' 12:00:00');
            $abstand = (int)$a->diff($b)->format('%r%a');
            return ($abstand >= 0 && $abstand <= 6) ? $abstand : -1;
        }
        return -1;
    }

    private function PlaetzeFuer(array $woche, array $a): array
    {
        $erledigt = is_array($woche['done'][$a['id']] ?? null) ? $woche['done'][$a['id']] : [];
        $zustaendig = trim((string)($woche['assign'][$a['id']] ?? ''));
        $start = $this->WochenStartTag();
        /* Die Tage eines Ämtchens stehen in der Reihenfolge der Anzeige. Der
           i-te Platz gehört also zum i-ten Tag — und die Spalte ist der
           Abstand dieses Tages zum Wochenstart. Die Platz-SCHLÜSSEL bleiben
           „0", „1", … : an ihnen hängen die gespeicherten Häkchen und die
           Haken vergangener Wochen, die darf ein Umbau nicht
           anfassen. */
        $tage = is_array($a['days'] ?? null) && $a['days'] !== [] ? $a['days'] : [$start];
        /* Taeglicher Wechsel: der Kreis rueckt an JEDEM Tag weiter, an dem das
           Aemtchen ansteht — sonst traegt eine Person den Tischdienst die ganze
           Woche (gemeldet am 17.09.2026).

           Angesetzt wird an der EINGEFRORENEN Person dieser Woche: sie steht
           irgendwo im Kreis, und der i-te Tag gehoert dem i-ten danach. Damit
           bleibt die Woche eingefroren (nichts wird neu gewuerfelt), und der
           Wochenwechsel schiebt die ganze Reihe wie bisher um eins weiter. */
        $folge = null;
        if (($a['rotate'] ?? 'week') === 'day') {
            $kreis = $this->KreisFuer((string)($a['circle'] ?? 'all'), $this->Rollen());
            $ab = array_search($zustaendig, $kreis, true);
            if ($kreis !== [] && $ab !== false) {
                $folge = ['kreis' => $kreis, 'ab' => (int)$ab, 'pausiert' => $this->Pausierte()];
            }
        }
        $raus = [];
        foreach (array_values($tage) as $i => $iso) {
            $k = (string)$i;
            $satz = is_array($erledigt[$k] ?? null) ? $erledigt[$k] : null;
            $dran = $folge === null
                ? $zustaendig
                : $this->NaechsterAktive($folge['kreis'], $folge['ab'] + $i, $folge['pausiert']);
            $raus[] = [
                'key'      => $k,
                'done'     => $satz !== null,
                'memberId' => $satz !== null ? (string)($satz['m'] ?? '') : $dran,
                'carried'  => false,
                'day'      => (int)$iso,
                'col'      => ((int)$iso - $start + 7) % 7,
            ];
        }
        $heuteSpalte = $this->SpalteHeute((string)($woche['week'] ?? ''), time());
        /* Das Gluecksrad: hat es HEUTE fuer dieses Aemtchen jemanden bestimmt,
           steht der am heutigen Platz — solange der nicht abgehakt ist (am
           abgehakten Platz steht, wer es wirklich getan hat). Nur der heutige
           Tag: ein Los von gestern gilt nicht fuer morgen. */
        $los = is_array($woche['wheel'][$a['id']] ?? null) ? $woche['wheel'][$a['id']] : null;
        if ($los !== null && (string)($los['day'] ?? '') === $this->TagKennung(time())) {
            foreach ($raus as $i => $p) {
                if ($p['key'] === (string)($los['slot'] ?? '') && !$p['done']) {
                    $raus[$i]['memberId'] = trim((string)($los['memberId'] ?? ''));
                    $raus[$i]['wheel'] = true;
                }
            }
        }
        if ($heuteSpalte < 0) {
            $vorbei = (string)($woche['week'] ?? '') < $this->WochenKennung(time());
            $heuteSpalte = $vorbei ? 6 : 0;
        }
        /* Der Uebertrag steht in der eingefrorenen Woche — geschrieben wurde er
           beim Wochenwechsel, als der Schalter noch AN war. Wer ihn danach
           ausschaltet, will die alten Kreise nicht mehr sehen (gemeldet am
           17.09.2026); die Zeile hier fragt deshalb bei JEDEM Blick nach der
           Einstellung und nicht nur beim Wochenwechsel. Geloescht wird nichts:
           schaltet man den Schalter wieder ein, ist der Uebertrag wieder da. */
        $uebertrag = $this->EinstellungJa('CarryOver')
            && is_array($woche['carry'][$a['id']] ?? null) ? $woche['carry'][$a['id']] : null;
        if ($uebertrag !== null) {
            $halter = trim((string)($uebertrag['memberId'] ?? ''));
            for ($i = 0; $i < max(0, (int)($uebertrag['count'] ?? 0)); $i++) {
                $k = 'c' . $i;
                $satz = is_array($erledigt[$k] ?? null) ? $erledigt[$k] : null;
                /* Ein Übertrag gehört zu keinem Tag — er wird HEUTE nachgeholt.
                   Ist die gezeigte Woche vorbei, steht er am letzten Tag; liegt
                   sie noch vor uns, am ersten. Irgendwo muss er stehen, und
                   unsichtbar wäre schlechter als an einer erklärbaren Stelle. */
                $raus[] = [
                    'key'      => $k,
                    'done'     => $satz !== null,
                    'memberId' => $satz !== null ? (string)($satz['m'] ?? '') : $halter,
                    'carried'  => true,
                    'day'      => 0,
                    'col'      => $heuteSpalte,
                ];
            }
        }
        return $raus;
    }

    // ------------------------------------------------------------------
    // Der Muenzbeutel — Kinder verdienen, Kinder zahlen
    // ------------------------------------------------------------------

    /** @return array<string,int> Mitglied → Muenzen */
    private function BeutelLesen(): array
    {
        $roh = json_decode((string)@$this->ReadAttributeString('Purse'), true);
        $raus = [];
        foreach (is_array($roh) ? $roh : [] as $id => $n) {
            $raus[(string)$id] = max(0, (int)$n);
        }
        return $raus;
    }

    private function BeutelSchreiben(array $beutel): void
    {
        @$this->WriteAttributeString('Purse', (string)json_encode($beutel, JSON_UNESCAPED_UNICODE));
    }

    private function MuenzenVon(string $id): int
    {
        return (int)($this->BeutelLesen()[$id] ?? 0);
    }

    /** Muenzen gutschreiben oder abziehen — nie unter null. */
    private function MuenzenBuchen(string $id, int $delta): void
    {
        if ($id === '' || $delta === 0) {
            return;
        }
        $beutel = $this->BeutelLesen();
        $beutel[$id] = max(0, (int)($beutel[$id] ?? 0) + $delta);
        $this->BeutelSchreiben($beutel);
    }

    /**
     * Muenzen von Hand buchen — Startguthaben, Belohnung ausserhalb des Plans
     * oder eine Korrektur. Nur fuer Kinder, wie alles am Beutel; fuer andere
     * kommt null zurueck und nichts aendert sich. Ergebnis: der neue Stand.
     */
    private function MuenzenSchenken(string $id, int $delta): ?int
    {
        if ($id === '' || !$this->IstKind($id)) {
            return null;
        }
        $this->MuenzenBuchen($id, $delta);
        return $this->MuenzenVon($id);
    }

    /** Nur Kinder verdienen und zahlen (Regel des Nutzers). */
    private function IstKind(string $id): bool
    {
        return ($this->Rollen()[$id] ?? '') === 'child';
    }

    /** Was ein Dreh am Gluecksrad kostet; 0 = frei. */
    private function DrehPreis(): int
    {
        return max(0, min(999, $this->EinstellungZahl('SpinPrice', 3)));
    }

    /**
     * Der Tag, auf dem „heute" steht — auf derselben verschobenen Uhr wie die
     * Wochenkennung (Montag 02:59 bei Wechsel um 03:00 ist noch Sonntag).
     */
    private function TagKennung(int $jetzt): string
    {
        [$std, $min] = $this->WechselZeit();
        return date('Y-m-d', $jetzt - ($std * 3600 + $min * 60));
    }

    /**
     * Wer beim Gluecksrad fuer dieses Aemtchen mitspielt: der Kreis des
     * Aemtchens ohne die, die aussetzen. Bei einer festen Person ist das
     * genau sie — dann gibt es nichts zu losen, aber auch nichts Falsches.
     *
     * @return list<string>
     */
    private function WuerfelKandidaten(array $a): array
    {
        $pausiert = $this->Pausierte();
        $raus = [];
        foreach ($this->KreisFuer((string)($a['circle'] ?? 'all'), $this->Rollen()) as $id) {
            if (!isset($pausiert[$id]) && !in_array($id, $raus, true)) {
                $raus[] = $id;
            }
        }
        return $raus;
    }

    /**
     * Das Gluecksrad drehen: ein Aemtchen, das HEUTE ansteht, bekommt fuer heute
     * eine ausgeloste Person.
     *
     * Gelost wird HIER, nicht in der Kachel: die Kachel zeigt das Rad, das Modul
     * entscheidet — sonst koennte ein zweites Geraet ein anderes Los zeigen,
     * und ein Tipp auf „nochmal" wuerde zaehlen. Einmal am Tag je Aemtchen
     * (Regel des Nutzers, 18.09.2026): das Los steht in der eingefrorenen
     * Woche unter `wheel`, mit dem Tag; ein zweiter Ruf am selben Tag wird
     * abgewiesen. Der Zufall ist austauschbar (Pruefstand).
     *
     * @param ?callable(int $n): int $zufall liefert einen Index 0..n-1
     * @return array{ok:bool,memberId:string,slot:string,reason:string}
     */
    private function Wuerfeln(string $wochenKennung, string $choreId, int $jetzt, ?callable $zufall = null,
        string $zahler = ''): array
    {
        $nein = static fn(string $grund): array => ['ok' => false, 'memberId' => '', 'slot' => '', 'reason' => $grund];
        /* Ein Dreh kostet Muenzen, und zahlen kann nur ein Kind mit genug im
           Beutel. Geprueft wird VOR allem anderen — und abgebucht erst, wenn
           das Los steht: ein abgewiesener Dreh darf nichts kosten. */
        $preis = $this->DrehPreis();
        if ($preis > 0) {
            if ($zahler === '' || !$this->IstKind($zahler)) {
                return $nein('payer');
            }
            if ($this->MuenzenVon($zahler) < $preis) {
                return $nein('coins');
            }
        }
        $woche = $this->WocheSicherstellen($jetzt);
        if ($wochenKennung !== '' && $wochenKennung !== (string)($woche['week'] ?? '')) {
            return $nein('week');
        }
        $treffer = null;
        foreach ($this->AemtchenLesen() as $a) {
            if ($a['id'] === $choreId) {
                $treffer = $a;
                break;
            }
        }
        if ($treffer === null) {
            return $nein('chore');
        }
        $heute = $this->TagKennung($jetzt);
        $los = is_array($woche['wheel'][$choreId] ?? null) ? $woche['wheel'][$choreId] : null;
        if ($los !== null && (string)($los['day'] ?? '') === $heute) {
            return $nein('spun');
        }
        // Der heutige, noch offene Platz — ohne ihn gibt es nichts zu verlosen.
        $spalte = $this->SpalteHeute((string)($woche['week'] ?? ''), $jetzt);
        $platz = '';
        foreach ($this->PlaetzeFuer($woche, $treffer) as $p) {
            if (($p['carried'] ?? false) !== true && (int)$p['col'] === $spalte && !$p['done']) {
                $platz = (string)$p['key'];
                break;
            }
        }
        if ($spalte < 0 || $platz === '') {
            return $nein('no_slot');
        }
        $kandidaten = $this->WuerfelKandidaten($treffer);
        $n = count($kandidaten);
        if ($n === 0) {
            return $nein('nobody');
        }
        $index = $zufall !== null ? (int)$zufall($n) : random_int(0, $n - 1);
        $gewinner = $kandidaten[(($index % $n) + $n) % $n];
        $woche['wheel'] = is_array($woche['wheel'] ?? null) ? $woche['wheel'] : [];
        $woche['wheel'][$choreId] = ['day' => $heute, 'memberId' => $gewinner, 'slot' => $platz,
                                     'paidBy' => $preis > 0 ? $zahler : '', 'price' => $preis];
        $this->WocheSchreiben($woche);
        if ($preis > 0) {
            $this->MuenzenBuchen($zahler, -$preis);
        }
        return ['ok' => true, 'memberId' => $gewinner, 'slot' => $platz, 'reason' => ''];
    }

    /**
     * Ein Häkchen setzen. Zurücknehmen gibt es nicht mehr (18.09.2026).
     *
     * Immer mit ZIELZUSTAND und nie als Umschalter: eine doppelt zugestellte
     * Anfrage darf nichts anderes bewirken als die erste. Idempotent, damit
     * ein Doppeltipp nicht zweimal bucht.
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
        $spalte = -1;
        $uebertrag = false;
        foreach ($this->PlaetzeFuer($woche, $treffer) as $p) {
            if ($p['key'] === $platz) {
                $platzDa = true;
                $halter = $p['memberId'];
                $spalte = (int)($p['col'] ?? -1);
                $uebertrag = ($p['carried'] ?? false) === true;
                break;
            }
        }
        if (!$platzDa) {
            return false;
        }
        /* Was noch nicht dran war, kann auch nicht erledigt sein. Die Kachel
           sperrt die kuenftigen Tage schon, aber darauf allein ist kein
           Verlass: eine nachgereichte oder wiederholte Anfrage muss hier
           scheitern, sonst haengt ein Haken an einem Tag, der noch aussteht.
           Der Uebertrag ist ausgenommen — er gehoert zu keinem Tag. */
        if (!$uebertrag && $this->InDerZukunft((string)($woche['week'] ?? ''), $spalte, $jetzt)) {
            return false;
        }
        $erledigt = is_array($woche['done'][$choreId] ?? null) ? $woche['done'][$choreId] : [];
        $war = isset($erledigt[$platz]);
        if ($war === $ziel) {
            return true;
        }
        /* Ein Haken bleibt (Regel des Nutzers, 18.09.2026): zuruecknehmen geht
           nicht, damit ist derselbe Platz auch nie zweimal abzuhaken — und die
           Muenzen dafuer fliessen genau einmal. Die Kachel bietet den Haken
           gar nicht an; hier wird es fuer nachgereichte Anfragen abgewiesen. */
        if (!$ziel) {
            return false;
        }
        /* Gespeichert wird, WER es getan hat — und was es ihm gebracht hat:
           nur ein KIND verdient Muenzen (Regel des Nutzers). */
        $muenzen = $this->IstKind($halter) ? (int)($treffer['coins'] ?? 0) : 0;
        $erledigt[$platz] = ['m' => $halter, 'p' => $muenzen];
        $this->MuenzenBuchen($halter, $muenzen);
        $woche['done'][$choreId] = $erledigt;
        $this->WocheSchreiben($woche);
        return true;
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
                /* Die im Gateway gewaehlte Farbe ('#rrggbb' oder leer). Die
                   Kachel faerbt damit den Fortschrittsring; ohne Wahl nimmt
                   sie ihre eigene Leiter. */
                'color'   => (string)($u['color'] ?? ''),
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
        /* (string) ist PFLICHT: PHP macht aus einem Schlüssel, der nur aus
           Ziffern besteht, stillschweigend eine ZAHL — und die Kennungen sind
           acht Hexzeichen, „57648139" ist eine davon. Ohne die Umwandlung trägt
           die Auswahl für dieses eine Mitglied eine Zahl als Wert, der strenge
           Vergleich unten greift nicht, und das Formular hängt eine zweite
           Zeile „57648139 (nicht gefunden)" an (gemeldet am 17.09.2026). */
        foreach ($kinder + $andere as $id => $name) {
            $optionen[] = ['caption' => $name, 'value' => (string)$id];
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
                'color'   => $m !== null ? (string)($m['color'] ?? '') : '',
                'paused'  => isset($pausiert[$z['memberId']]),
                'known'   => $m !== null,
                // Der Beutel — nur Kinder haben einen, alle anderen stehen bei null.
                'coins'   => $this->IstKind($z['memberId']) ? $this->MuenzenVon($z['memberId']) : 0,
            ];
        }

        $liste = [];
        $gesamt = 0;
        $fertig = 0;
        $heuteSpalte = $this->SpalteHeute((string)($woche['week'] ?? ''), $jetzt);
        foreach ($aemtchen as $a) {
            $plaetze = $this->PlaetzeFuer($woche, $a);
            $erledigt = 0;
            foreach ($plaetze as $i => $p) {
                /* Ob ein Platz noch aussteht, entscheidet das MODUL — die
                   Kachel malt nur, was hier steht. Sie kann es gar nicht
                   selbst wissen: die Wechselzeit und der erste Wochentag sind
                   Einstellungen, und die Kachel bekommt nur die Nutzlast. */
                $plaetze[$i]['locked'] = ($p['carried'] ?? false) !== true
                    && $this->InDerZukunft((string)($woche['week'] ?? ''), (int)($p['col'] ?? -1), $jetzt);
            }
            foreach ($plaetze as $p) {
                $gesamt++;
                if ($p['done']) {
                    $erledigt++;
                    $fertig++;
                }
            }
            $los = is_array($woche['wheel'][$a['id']] ?? null) ? $woche['wheel'][$a['id']] : null;
            $liste[] = [
                'id'        => $a['id'],
                'name'      => $a['name'],
                'icon'      => $a['icon'],
                'color'     => $a['color'],
                'coins'     => (int)($a['coins'] ?? 0),
                'memberId'  => (string)($woche['assign'][$a['id']] ?? ''),
                'doneCount' => $erledigt,
                'total'     => count($plaetze),
                'slots'     => $plaetze,
                /* Fuers Gluecksrad: wer mitspielt, und ob heute schon gelost
                   wurde (dann steht der Gewinner da und das Rad bleibt aus). */
                'candidates' => $this->WuerfelKandidaten($a),
                'wheel'      => ($los !== null && (string)($los['day'] ?? '') === $this->TagKennung($jetzt))
                    ? ['memberId' => (string)($los['memberId'] ?? ''), 'slot' => (string)($los['slot'] ?? '')]
                    : null,
            ];
        }

        /* Rueckblick und Vorschau reisen NICHT mehr mit: die Kachel zeigt nur
           noch die laufende Woche (Entscheidung des Nutzers, 17.09.2026). Wer
           in die Zukunft sehen will, fragt CHR_Preview(). */
        return [
            'type'       => 'state',
            'week'       => (string)($woche['week'] ?? ''),
            'index'      => (int)($woche['index'] ?? 0),
            'start'      => $this->WochenStartTag(),
            /* Die Spaltenueberschriften in der Reihenfolge der Anzeige und die
               Spalte, in der heute steht (-1, wenn die gezeigte Woche nicht
               die laufende ist). */
            'dayNames'   => $this->WochentagKuerzel(),
            'todayCol'   => $heuteSpalte,
            'carryOver'  => $this->EinstellungJa('CarryOver'),
            /* Welche Kaesten die Kachel zeigt. Die Tabelle steht nicht darin —
               sie ist der Grund, warum jemand hinsieht. */
            'show'       => [
                'members'  => $this->EinstellungJa('ShowMembers', true),
                'progress' => $this->EinstellungJa('ShowProgress', true),
                'upNext'   => $this->EinstellungJa('ShowUpNext', true),
                'banner'   => $this->EinstellungJa('ShowBanner', true),
                'wheel'    => $this->EinstellungJa('ShowWheel', true),
            ],
            'day'        => $this->TagKennung($jetzt),
            'spinPrice'  => $this->DrehPreis(),
            'order'      => $reihenfolge,
            'members'    => $leute,
            'chores'     => $liste,
            'progress'   => $gesamt > 0 ? (int)round($fertig * 100 / $gesamt) : 0,
            'doneCount'  => $fertig,
            'totalCount' => $gesamt,
            'texts'      => $this->KachelTexte(),
        ];
    }

    /** Die Kachel übersetzt nichts selbst — die Texte reisen mit. */
    private function KachelTexte(): array
    {
        return [
            'of'       => $this->Translate('%1$d of %2$d'),
            'nobody'   => $this->Translate('— none —'),
            'paused'   => $this->Translate('Pause'),
            'family'   => $this->Translate('All participants'),
            'carried'  => $this->Translate('from last week'),
            'later'    => $this->Translate('not due yet'),
            'empty'    => $this->Translate('No chores configured yet — add participants and chores in the instance settings.'),
            'carriedHint' => $this->Translate('Dashed circle: carried over from last week'),
            /* Die Tabellenansicht (17.09.2026): Kopf, Seitenspalte und Banner.
               Die Kachel uebersetzt nichts selbst — auch nicht „heute". */
            'task'        => $this->Translate('Chore'),
            'today'       => $this->Translate('Today'),
            'tomorrow'    => $this->Translate('Tomorrow'),
            'overall'     => $this->Translate('Overall progress'),
            'doneOf'      => $this->Translate('%1$d of %2$d chores done'),
            'upNext'      => $this->Translate('Up next'),
            'nothingOpen' => $this->Translate('Nothing open — everything is done'),
            'done'        => $this->Translate('done'),
            'teamTitle'   => $this->Translate('A strong team!'),
            'teamText'    => $this->Translate('You keep your home in order together.'),
            'praiseAll'   => $this->Translate('All done!'),
            'praiseHigh'  => $this->Translate('Well done!'),
            'praiseMid'   => $this->Translate('Keep it up!'),
            'praiseLow'   => $this->Translate("Let's get started!"),
            /* Das Gluecksrad (18.09.2026). */
            'wheelTitle'  => $this->Translate('Roll the dice'),
            'wheelHint'   => $this->Translate('Let the wheel decide who does a chore today.'),
            'wheelPick'   => $this->Translate('Pick a chore'),
            'wheelSpin'   => $this->Translate('Spin'),
            'wheelGo'     => $this->Translate('Go!'),
            'wheelSpun'   => $this->Translate('already rolled today'),
            'wheelNone'   => $this->Translate('Nothing is due today.'),
            'wheelResult' => $this->Translate('%s does it today!'),
            'wheelWait'   => $this->Translate('The wheel is spinning…'),
            'wheelNoAnswer' => $this->Translate('No answer — please try again.'),
            'wheelNobody' => $this->Translate('Nobody is available for this chore.'),
            'close'       => $this->Translate('Close'),
            /* Die Muenzen (18.09.2026). */
            'coins'       => $this->Translate('coins'),
            'wheelWho'    => $this->Translate('Who pays?'),
            'wheelCost'   => $this->Translate('One spin costs %d coins'),
            'wheelPoor'   => $this->Translate('not enough coins'),
            'wheelNoPayer' => $this->Translate('No child has enough coins.'),
            'wheelAllSpun' => $this->Translate('Everything has been rolled today.'),
            'wheelPays'   => $this->Translate('%s pays'),
            'wheelOnce'   => $this->Translate('1 × spin ='),
        ];
    }
}

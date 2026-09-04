<?php

declare(strict_types=1);

/**
 * Sprachdialog — Titel und Namen auflösen.
 *
 * „Hak Autoputzen ab" braucht die numerische id; das Modell spricht aber nur
 * den Titel. Hier wird frisch gegen die aktuelle Liste aufgelöst (kein
 * Merkzettel — bei Haushaltslisten unter hundert Einträgen ist Frisch-Laden
 * robuster als ein veraltender Zwischenspeicher).
 *
 * Drei Ausgänge: eindeutig (ausführen), mehrdeutig (Auswahl zurück, damit das
 * Modell nachfragt), nichts (mit Beinah-Treffern, damit das Gespräch weitergeht).
 */
trait VoiceResolve
{
    /**
     * Punktevergleich eines Suchbegriffs gegen einen Kandidaten. 0–100.
     * Umlautgefaltet, weil levenshtein byteweise rechnet.
     */
    private function VoicePunkte(string $suchNorm, string $kandNorm): int
    {
        if ($kandNorm === '') {
            return 0;
        }
        if ($suchNorm === $kandNorm) {
            return 100;
        }
        // Präfix (ab 4 Zeichen): „auto put" ~ „auto putzen".
        if (mb_strlen($suchNorm) >= 4 && (str_starts_with($kandNorm, $suchNorm) || str_starts_with($suchNorm, $kandNorm))) {
            return 88;
        }
        // Jedes Wort der Suche steckt im Kandidaten.
        $woerter = preg_split('/\s+/', $suchNorm, -1, PREG_SPLIT_NO_EMPTY);
        $alleDrin = $woerter !== [] && count(array_filter($woerter, static fn(string $w): bool => str_contains($kandNorm, $w))) === count($woerter);
        if ($alleDrin) {
            return 80;
        }
        if (str_contains($kandNorm, $suchNorm)) {
            return 74;
        }
        $d = levenshtein($suchNorm, $kandNorm);
        $grenze = max(1, min(4, (int)floor(mb_strlen($kandNorm) * 0.2)));
        if ($d <= $grenze) {
            return 70;
        }
        similar_text($suchNorm, $kandNorm, $prozent);
        return (int)round($prozent);
    }

    /**
     * Beste Treffer aus einer Kandidatenliste. Jeder Kandidat: ['schluessel'=>…,
     * 'titel'=>…]. @return array{status:string, treffer:list, beinah:list}
     *   status: 'eindeutig' | 'mehrdeutig' | 'nichts'
     */
    private function VoiceAufloesen(string $such, array $kandidaten): array
    {
        $sn = $this->VoiceNorm($such);
        if ($sn === '' || $kandidaten === []) {
            return ['status' => 'nichts', 'treffer' => [], 'beinah' => []];
        }
        $bewertet = [];
        foreach (array_slice($kandidaten, 0, 300) as $k) {
            $titel = (string)($k['titel'] ?? '');
            $p = $this->VoicePunkte($sn, $this->VoiceNorm($titel));
            $bewertet[] = ['punkte' => $p] + $k;
        }
        usort($bewertet, static fn(array $a, array $b): int => $b['punkte'] <=> $a['punkte']);
        $treffer = array_values(array_filter($bewertet, static fn(array $b): bool => $b['punkte'] >= 60));
        if ($treffer === []) {
            // Beinah-Treffer (40–59) für „meintest du …?".
            $beinah = array_values(array_filter($bewertet, static fn(array $b): bool => $b['punkte'] >= 40 && $b['punkte'] < 60));
            return ['status' => 'nichts', 'treffer' => [],
                    'beinah' => array_map(static fn(array $b): string => (string)$b['titel'], array_slice($beinah, 0, 3))];
        }
        // Eindeutig: genau einer, oder der Beste klar vor dem Zweiten.
        if (count($treffer) === 1
            || ($treffer[0]['punkte'] >= 90 && (($treffer[1]['punkte'] ?? 0) < 80))) {
            return ['status' => 'eindeutig', 'treffer' => [$treffer[0]], 'beinah' => []];
        }
        return ['status' => 'mehrdeutig', 'treffer' => array_slice($treffer, 0, 5), 'beinah' => []];
    }

    /**
     * Eine Aufgabe in einer ToDo-Liste finden. @return array{status,treffer,beinah,items?}
     * treffer-Einträge: ['schluessel'=>id, 'titel'=>…]
     *
     * $nurOffen entscheidet, WORIN gesucht wird:
     *   true  offene Aufgaben  — „hak die Mülltonne ab"
     *   false erledigte        — „die Aufgabe ist doch noch offen"
     *   null  alle             — Löschen fragt nicht nach dem Zustand
     *
     * Vorher wurde IMMER nur unter den offenen gesucht. Damit konnte „mach das
     * wieder auf" nichts finden (die Aufgabe ist ja erledigt) und Löschen kam an
     * eine erledigte Aufgabe gar nicht heran — die Antwort lautete jedes Mal
     * „ich finde das nicht", obwohl es dastand.
     */
    private function VoiceAufgabeAufloesen(int $tdlId, string $such, ?bool $nurOffen = true): array
    {
        $st = json_decode((string)@TDL_GetAppState($tdlId), true);
        $items = is_array($st) ? (($st['state'] ?? [])['items'] ?? null) : null;
        $kand = [];
        foreach (is_array($items) ? $items : [] as $it) {
            if (!is_array($it)) {
                continue;
            }
            $erledigt = ($it['done'] ?? false) === true;
            if ($nurOffen !== null && $erledigt === $nurOffen) {
                continue;
            }
            $kand[] = ['schluessel' => (int)($it['id'] ?? 0), 'titel' => (string)($it['title'] ?? '')];
        }
        return $this->VoiceAufloesen($such, $kand);
    }

    /**
     * Einen Artikel in einer Einkaufsliste finden.
     * @return array{status,treffer,beinah}
     *
     * $nurOffen wie bei den Aufgaben:
     *   true  noch zu kaufen — „leg die Milch in den Wagen"
     *   false schon im Wagen — „nimm die Milch wieder raus"
     *   null  alles          — Löschen
     */
    private function VoiceArtikelAufloesen(int $slId, string $such, ?bool $nurOffen = true): array
    {
        $items = json_decode((string)@SL_GetItems($slId), true);
        $kand = [];
        foreach (is_array($items) ? $items : [] as $it) {
            if (!is_array($it)) {
                continue;
            }
            $imWagen = ($it['inCart'] ?? false) === true;
            if ($nurOffen !== null && $imWagen === $nurOffen) {
                continue;
            }
            $kand[] = ['schluessel' => (string)($it['id'] ?? ''), 'titel' => (string)($it['name'] ?? '')];
        }
        return $this->VoiceAufloesen($such, $kand);
    }

    /**
     * Aus einem Auflöse-Ergebnis eine Fehlerantwort bauen, wenn nicht eindeutig.
     * @return array<string,mixed>|null null = eindeutig, sonst die Fehlerform
     */
    private function VoiceAufloeseFehler(array $erg, string $such, string $wasArt): ?array
    {
        if (($erg['status'] ?? '') === 'eindeutig') {
            return null;
        }
        if ($erg['status'] === 'mehrdeutig') {
            $titel = array_map(static fn(array $t): string => (string)$t['titel'], $erg['treffer']);
            return [
                'ok' => false, 'error' => ['code' => 'mehrdeutig', 'message' => 'nicht eindeutig'],
                'sag' => sprintf($this->Translate('Which one do you mean? For example: %s.'), implode(', ', $titel)),
                'treffer' => $titel,
            ];
        }
        $beinah = $erg['beinah'] ?? [];
        $antwort = [
            'ok' => false, 'error' => ['code' => 'nicht_gefunden', 'message' => 'nicht gefunden'],
            'sag' => sprintf($this->Translate('I cannot find "%s" among the %s.'), $such, $wasArt),
        ];
        if ($beinah !== []) {
            $antwort['aehnlich'] = $beinah;
            $antwort['sag'] .= ' ' . sprintf($this->Translate('Did you mean: %s?'), implode(', ', $beinah));
        }
        return $antwort;
    }
}

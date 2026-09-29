<?php

declare(strict_types=1);

/**
 * Laden-Reihenfolge: lernt aus der Abhak-Reihenfolge beim Einkaufen, wie der
 * Markt aufgebaut ist, und sortiert die Kategorien danach.
 *
 * Gelernt wird IMMER (die Daten sind rein lokal und winzig); der Schalter
 * `StoreOrderEnabled` steuert nur, ob die Sortierung angewandt wird — wer ihn
 * einschaltet, profitiert damit sofort von allem, was die Liste längst weiß.
 *
 * Als Signal zählt nur das EINZELNE Abhaken im Einkauf (dort, wo boughtAt zum
 * ersten Mal gesetzt wird). Sammel-Aktionen wie „alles in den Wagen" sind kein
 * Gang durch den Markt und bleiben außen vor.
 */
trait StoreOrder
{
    /** Pause, ab der ein neues Abhaken als neuer Einkauf zählt. */
    private static int $LADEN_PAUSE = 45 * 60;

    /** EMA-Gewicht der jüngsten Tour — jüngere Einkäufe zählen mehr. */
    private static float $LADEN_GEWICHT = 0.3;

    /** Ist die Sortierung eingeschaltet? Property-schonend gelesen: vor dem
     *  ersten Kernel-Neustart nach dem Update existiert sie noch nicht. */
    private function LadenAktiv(): bool
    {
        return (bool)@IPS_GetProperty($this->InstanceID, 'StoreOrderEnabled');
    }

    /**
     * Frisch abgehakte Artikel als Lernsignal vermerken.
     *
     * @param list<array<string,mixed>> $rows Zeilen, die soeben ihr erstes
     *                                        boughtAt bekommen haben
     */
    private function LadenLernen(array $rows, int $jetzt): void
    {
        $kategorien = [];
        foreach ($rows as $row) {
            $kat = trim((string)($row['category'] ?? ''));
            if ($kat !== '') {
                $kategorien[] = $kat;
            }
        }
        if ($kategorien === []) {
            return;
        }
        $stats = $this->LadenStatsLesen();
        if ($stats === null) {
            return;                     // Attribut existiert erst nach dem Kernel-Neustart
        }
        foreach ($kategorien as $kat) {
            $tour = $stats['tour'];
            if ($tour !== null && $jetzt - (int)$tour['letzte'] > self::$LADEN_PAUSE) {
                $this->LadenTourVerbuchen($stats);
                $tour = null;
            }
            if ($tour === null) {
                $stats['tour'] = ['letzte' => $jetzt, 'folge' => []];
            }
            if (!in_array($kat, $stats['tour']['folge'], true)) {
                $stats['tour']['folge'][] = $kat;
            }
            $stats['tour']['letzte'] = $jetzt;
        }
        $this->LadenStatsSchreiben($stats);
    }

    /**
     * Die Kategorien in Laufreihenfolge: erst die gelernten (nach Rang), dann
     * alles Konfigurierte ohne Lerndaten in seiner bisherigen Reihenfolge.
     * Die Oberflächen hängen gänzlich unbekannte Kategorien selbst hinten an.
     *
     * @return list<string>
     */
    private function LadenReihenfolge(int $jetzt): array
    {
        $konfiguriert = $this->GetCategoryOrderFlat();
        $stats = $this->LadenStatsLesen();
        if ($stats === null) {
            return $konfiguriert;
        }
        // Eine liegengebliebene Tour (Einkauf vorbei, nie „abgeschlossen")
        // fließt jetzt ein — sonst lernte der allererste Einkauf nie.
        if ($stats['tour'] !== null && $jetzt - (int)$stats['tour']['letzte'] > self::$LADEN_PAUSE) {
            $this->LadenTourVerbuchen($stats);
            $this->LadenStatsSchreiben($stats);
        }
        if ($stats['rang'] === []) {
            return $konfiguriert;
        }
        $basis = array_flip($konfiguriert);          // Kategorie => konfigurierter Platz
        $gelernt = array_keys($stats['rang']);
        usort($gelernt, function (string $a, string $b) use ($stats, $basis): int {
            $r = $stats['rang'][$a]['r'] <=> $stats['rang'][$b]['r'];
            if ($r !== 0) {
                return $r;
            }
            return ($basis[$a] ?? PHP_INT_MAX) <=> ($basis[$b] ?? PHP_INT_MAX);
        });
        $rest = array_values(array_filter($konfiguriert,
            static fn(string $kat): bool => !isset($stats['rang'][$kat])));
        return array_merge($gelernt, $rest);
    }

    /**
     * Der Formular-Bereich „Laden-Reihenfolge".
     *
     * Solange die Eigenschaft noch nicht existiert (neue Version, Kernel noch
     * nicht neu gestartet), steht hier nur der Hinweis — ein Schalter, den
     * „Übernehmen" ablehnt, wäre schlimmer als keiner.
     *
     * @return list<array<string,mixed>>
     */
    private function LadenFormular(): array
    {
        $cfg = json_decode((string)@IPS_GetConfiguration($this->InstanceID), true);
        if (!is_array($cfg) || !array_key_exists('StoreOrderEnabled', $cfg)) {
            return [[
                'type'    => 'Label',
                'caption' => $this->Translate('The store-order setting appears after the next Symcon restart — it is a new setting, and those only exist once the kernel has loaded the module again.'),
            ]];
        }
        return [
            [
                'type'    => 'CheckBox',
                'name'    => 'StoreOrderEnabled',
                'caption' => $this->Translate('Sort in store order (learned from the check-off order)'),
            ],
            [
                'type'    => 'Label',
                'caption' => $this->Translate('While shopping, the list watches in which order the categories get checked off (a 45-minute pause starts a new trip) and derives the walking order through the store. Recent trips weigh more; categories without data follow in their configured order. Learning is always on — this switch only applies the sorting.'),
            ],
            [
                'type'    => 'Button',
                'caption' => $this->Translate('Forget learned store order'),
                'onClick' => 'IPS_RequestAction($id, "StoreOrderReset", 0); echo '
                    . var_export($this->Translate('Learned store order forgotten.'), true) . ';',
            ],
        ];
    }

    /** Alles Gelernte verwerfen — der Formular-Knopf. */
    private function LadenVergessen(): void
    {
        @$this->WriteAttributeString('StoreOrderStats', '{}');
    }

    /**
     * Die abgeschlossene Tour in die Ränge einarbeiten. Eine Tour mit nur einer
     * Kategorie trägt keine Reihenfolge-Information und wird verworfen.
     */
    private function LadenTourVerbuchen(array &$stats): void
    {
        $folge = $stats['tour']['folge'] ?? [];
        $stats['tour'] = null;
        $n = count($folge);
        if ($n < 2) {
            return;
        }
        foreach ($folge as $i => $kat) {
            $platz = $i / ($n - 1);                  // 0 = vorn im Markt, 1 = hinten
            if (isset($stats['rang'][$kat])) {
                $alt = $stats['rang'][$kat];
                $stats['rang'][$kat] = [
                    'r' => (1 - self::$LADEN_GEWICHT) * (float)$alt['r'] + self::$LADEN_GEWICHT * $platz,
                    'n' => (int)$alt['n'] + 1,
                ];
            } else {
                $stats['rang'][$kat] = ['r' => $platz, 'n' => 1];
            }
        }
    }

    /**
     * @return array{rang: array<string, array{r: float, n: int}>, tour: ?array{letzte: int, folge: list<string>}}|null
     *         null, wenn das Attribut (noch) nicht existiert
     */
    private function LadenStatsLesen(): ?array
    {
        // @ plus Probe: vor dem ersten Kernel-Neustart nach dem Update gibt es
        // das Attribut nicht — Symcon warnt dann nur, statt zu werfen.
        $roh = @$this->ReadAttributeString('StoreOrderStats');
        if (!is_string($roh) || $roh === '') {
            return null;
        }
        $stats = json_decode($roh, true);
        if (!is_array($stats)) {
            $stats = [];
        }
        return [
            'rang' => is_array($stats['rang'] ?? null) ? $stats['rang'] : [],
            'tour' => is_array($stats['tour'] ?? null) ? $stats['tour'] : null,
        ];
    }

    private function LadenStatsSchreiben(array $stats): void
    {
        @$this->WriteAttributeString('StoreOrderStats',
            json_encode($stats, JSON_UNESCAPED_UNICODE));
    }
}

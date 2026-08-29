<?php

declare(strict_types=1);

/**
 * Datenhaltung und Rechenwerk der Essensplan-Kachel.
 *
 * Der Plan ist bewusst klein: je Kalendertag GENAU EIN Gericht — entweder der
 * Verweis auf eine Favoritenliste der Einkaufsliste (dann gibt es Zutaten,
 * Foto und Quelle) oder ein Freitext („Reste", „Pizza bestellen"). Die
 * Rezepte selbst wohnen NICHT hier, sondern bleiben Favoritenlisten — eine
 * Quelle, keine Divergenz.
 */
trait MealStore
{
    // ------------------------------------------------------------------
    // Wochenrechnung (12:00-Anker gegen die Zeitumstellung, Hausmuster)
    // ------------------------------------------------------------------

    /** Datum des Wochentags (1=Mo … 7=So) in der Woche von $datum. */
    private function DatumInWoche(string $datum, int $tag): string
    {
        $d = new \DateTimeImmutable($datum . ' 12:00:00');
        $diff = max(1, min(7, $tag)) - (int)$d->format('N');
        return $d->modify(sprintf('%+d days', $diff))->format('Y-m-d');
    }

    /** Kurzname eines Wochentags (1=Mo … 7=So), übersetzt. */
    private function TagKurz(int $tag): string
    {
        $namen = ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        return $this->Translate($namen[max(1, min(7, $tag))]);
    }

    // ------------------------------------------------------------------
    // Plan
    // ------------------------------------------------------------------

    /** @return array<string, array{listId:string,text:string}> Datum => Gericht */
    private function PlanLesen(): array
    {
        $roh = json_decode($this->ReadAttributeString('Plan'), true);
        $tage = is_array($roh['days'] ?? null) ? $roh['days'] : [];
        $raus = [];
        foreach ($tage as $datum => $g) {
            if (!is_array($g) || preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$datum) !== 1) {
                continue;
            }
            $raus[(string)$datum] = [
                'listId' => trim((string)($g['listId'] ?? '')),
                'text'   => trim((string)($g['text'] ?? '')),
            ];
        }
        return $raus;
    }

    /**
     * Plan schreiben; Vergangenes älter als zwei Wochen fliegt dabei raus —
     * das Attribut bleibt klein, und einen Essens-Rückblick verspricht die
     * Kachel nicht.
     */
    private function PlanSchreiben(array $tage, int $jetzt): void
    {
        $grenze = date('Y-m-d', $jetzt - 14 * 86400);
        foreach (array_keys($tage) as $datum) {
            if ((string)$datum < $grenze) {
                unset($tage[$datum]);
            }
        }
        ksort($tage);
        $this->WriteAttributeString('Plan', json_encode(['days' => $tage], JSON_UNESCAPED_UNICODE));
    }

    /** Gericht setzen (Liste ODER Text) bzw. mit beidem leer: Tag räumen. */
    private function GerichtSetzen(string $datum, string $listId, string $text, int $jetzt): void
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum) !== 1) {
            return;
        }
        $tage = $this->PlanLesen();
        $listId = trim($listId);
        $text   = mb_substr(trim($text), 0, 120);
        if ($listId === '' && $text === '') {
            unset($tage[$datum]);
        } else {
            // Entweder-oder: ein Verweis schlägt den Text, beides zugleich
            // hieße zwei Wahrheiten für einen Tag.
            $tage[$datum] = ['listId' => $listId, 'text' => $listId !== '' ? '' : $text];
        }
        $this->PlanSchreiben($tage, $jetzt);
    }

    // ------------------------------------------------------------------
    // Einkaufsliste (Quelle der Rezepte, Ziel der Zutaten)
    // ------------------------------------------------------------------

    /** Die gewählte Einkaufsliste — 0, wenn keine (gültige) gewählt ist. */
    private function QuellListe(): int
    {
        $id = (int)@IPS_GetProperty($this->InstanceID, 'ShoppingListInstanceID');
        if ($id > 0 && IPS_InstanceExists($id)
            && (IPS_GetInstance($id)['ModuleInfo']['ModuleID'] ?? '') === self::SHOPPING_GUID) {
            return $id;
        }
        return 0;
    }

    /**
     * Die Favoritenlisten der Quelle: id, name, mediaId, Anzahl Zutaten.
     *
     * @return array<string, array{name:string,mediaId:int,items:int}>
     */
    private function Favoriten(): array
    {
        $sl = $this->QuellListe();
        if ($sl <= 0 || !function_exists('SL_GetAppState')) {
            return [];
        }
        try {
            $antwort = json_decode((string)@SL_GetAppState($sl), true);
        } catch (\Throwable $e) {
            return [];
        }
        $raus = [];
        foreach ((array)($antwort['state']['favoriteLists'] ?? []) as $liste) {
            $id = is_array($liste) ? trim((string)($liste['id'] ?? '')) : '';
            if ($id === '') {
                continue;
            }
            $raus[$id] = [
                'name'    => trim((string)($liste['name'] ?? '')),
                'mediaId' => (int)($liste['mediaId'] ?? 0),
                'items'   => count((array)($liste['items'] ?? [])),
            ];
        }
        return $raus;
    }

    /** Zutaten einer Favoritenliste in den Einkaufswagen. */
    private function ZutatenUebernehmen(string $listId): bool
    {
        $sl = $this->QuellListe();
        if ($sl <= 0 || trim($listId) === '') {
            return false;
        }
        try {
            // Öffentlicher Weg der ShoppingList; Nutzlast ist die ROHE listId.
            // Mengen-Zusammenführung und SendState macht die Liste selbst.
            IPS_RequestAction($sl, 'AddFavoriteListToCart', trim($listId));
            return true;
        } catch (\Throwable $e) {
            $this->SendDebug('MealPlan', 'Zutaten-Übernahme fehlgeschlagen: ' . $e->getMessage(), 0);
            return false;
        }
    }

    // ------------------------------------------------------------------
    // Rezeptfoto-Miniaturen
    // ------------------------------------------------------------------

    /**
     * Ein Rezeptfoto als kleine data-URL (~$kante px) — oder ''.
     *
     * Serverseitig statt über den Token-Hook: die Kachel hat keinen Token,
     * und sieben Miniaturen je Woche bleiben weit unter dem Payload-Deckel
     * (Symcon ERSETZT zu große Antworten, deshalb die harte Kante).
     */
    private function MiniBild(int $mediaId, int $kante = 120): string
    {
        if ($mediaId <= 0 || !function_exists('imagecreatefromstring') || !@IPS_MediaExists($mediaId)) {
            return '';
        }
        $roh = base64_decode((string)@IPS_GetMediaContent($mediaId), true);
        if (!is_string($roh) || $roh === '') {
            return '';
        }
        $bild = @imagecreatefromstring($roh);
        if ($bild === false) {
            return '';
        }
        $b = imagesx($bild);
        $h = imagesy($bild);
        $f = min(1.0, $kante / max(1, max($b, $h)));
        $zb = max(1, (int)round($b * $f));
        $zh = max(1, (int)round($h * $f));
        $klein = imagecreatetruecolor($zb, $zh);
        imagecopyresampled($klein, $bild, 0, 0, 0, 0, $zb, $zh, $b, $h);
        imagedestroy($bild);
        ob_start();
        imagejpeg($klein, null, 70);
        $jpeg = (string)ob_get_clean();
        imagedestroy($klein);
        return $jpeg === '' ? '' : 'data:image/jpeg;base64,' . base64_encode($jpeg);
    }

    // ------------------------------------------------------------------
    // Payload und Briefing-Auskunft
    // ------------------------------------------------------------------

    /** Das Gericht eines Tages, aufgelöst gegen die Favoriten. */
    private function GerichtFuer(string $datum, array $tage, array $favoriten): array
    {
        $g = $tage[$datum] ?? null;
        if ($g === null) {
            return ['title' => '', 'listId' => '', 'hasIngredients' => false, 'mediaId' => 0];
        }
        if ($g['listId'] !== '' && isset($favoriten[$g['listId']])) {
            $fav = $favoriten[$g['listId']];
            return ['title' => $fav['name'], 'listId' => $g['listId'],
                'hasIngredients' => $fav['items'] > 0, 'mediaId' => $fav['mediaId']];
        }
        if ($g['listId'] !== '') {
            // Die Favoritenliste wurde inzwischen gelöscht: ehrlich zeigen,
            // statt still einen leeren Tag zu behaupten.
            return ['title' => $this->Translate('(recipe deleted)'), 'listId' => '',
                'hasIngredients' => false, 'mediaId' => 0];
        }
        return ['title' => $g['text'], 'listId' => '', 'hasIngredients' => false, 'mediaId' => 0];
    }

    /** Der komplette Kachel-Zustand: diese und nächste Woche. */
    private function PayloadBauen(int $jetzt): array
    {
        $heute = date('Y-m-d', $jetzt);
        $tage = $this->PlanLesen();
        $favoriten = $this->Favoriten();

        $wochen = [];
        foreach ([0, 1] as $w) {
            $montag = $this->DatumInWoche(date('Y-m-d', $jetzt + $w * 7 * 86400), 1);
            $zeilen = [];
            foreach (range(1, 7) as $t) {
                $datum = $this->DatumInWoche($montag, $t);
                $gericht = $this->GerichtFuer($datum, $tage, $favoriten);
                $zeilen[] = [
                    'date'    => $datum,
                    'weekday' => $this->TagKurz($t),
                    'dayNum'  => (int)date('j', strtotime($datum . ' 12:00:00')),
                    'today'   => $datum === $heute,
                    'title'   => $gericht['title'],
                    'listId'  => $gericht['listId'],
                    'cart'    => $gericht['hasIngredients'],
                    'image'   => $gericht['mediaId'] > 0 ? $this->MiniBild($gericht['mediaId']) : '',
                ];
            }
            $wochen[] = ['monday' => $montag, 'days' => $zeilen];
        }

        $auswahl = [];
        foreach ($favoriten as $id => $fav) {
            $auswahl[] = ['id' => $id, 'name' => $fav['name'], 'items' => $fav['items']];
        }
        usort($auswahl, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return [
            'type'      => 'state',
            'today'     => $heute,
            'weeks'     => $wochen,
            'favorites' => $auswahl,
            'hasSource' => $this->QuellListe() > 0,
            'aiReady'   => $this->GatewayInstanz() > 0,
            'texts'     => [
                'thisWeek'  => $this->Translate('This week'),
                'nextWeek'  => $this->Translate('Next week'),
                'empty'     => $this->Translate('Nothing planned yet — tap a day.'),
                'noSource'  => $this->Translate('Select a shopping list in the instance settings first.'),
                'planned'   => $this->Translate('Plan a meal'),
                'favHead'   => $this->Translate('From the favorite lists'),
                'freeHead'  => $this->Translate('Free text'),
                'freeHint'  => $this->Translate('e.g. leftovers, order pizza'),
                'scanHead'  => $this->Translate('New recipe'),
                'scanUrl'   => $this->Translate('Analyze URL'),
                'scanFile'  => $this->Translate('Analyze photo or file'),
                'analyzing' => $this->Translate('Analyzing…'),
                'save'      => $this->Translate('Save'),
                'remove'    => $this->Translate('Remove meal'),
                'cart'      => $this->Translate('Ingredients to the cart'),
                'cartWeek'  => $this->Translate('Shop for this week'),
                'cartDone'  => $this->Translate('Ingredients added'),
                'noItems'   => $this->Translate('No ingredients found in that.'),
                'nameHint'  => $this->Translate('Dish name'),
                'failed'    => $this->Translate('Action failed'),
                'urlHint'   => $this->Translate('Paste recipe URL'),
                'close'     => $this->Translate('Close'),
            ],
        ];
    }

    /** Auskunft fürs Briefing: das Gericht eines Tages als JSON. */
    private function GerichtAuskunft(string $datum): array
    {
        $gericht = $this->GerichtFuer($datum, $this->PlanLesen(), $this->Favoriten());
        return [
            'title'          => $gericht['title'],
            'listId'         => $gericht['listId'],
            'hasIngredients' => $gericht['hasIngredients'],
        ];
    }

    // ------------------------------------------------------------------
    // Gateway (nur fürs KI-Relay des Rezept-Scans)
    // ------------------------------------------------------------------

    /** Das Gateway, das die App bedient: die Instanz mit der niedrigsten ID. */
    private function GatewayInstanz(): int
    {
        $ids = @IPS_GetInstanceListByModuleID(self::GATEWAY_GUID);
        if (!is_array($ids) || $ids === []) {
            return 0;
        }
        sort($ids);
        return (int)$ids[0];
    }
}

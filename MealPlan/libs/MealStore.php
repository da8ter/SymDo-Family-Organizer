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
     * Die Favoritenlisten der Quelle: id, name, mediaId, Anzahl Zutaten und
     * die Zutatennamen (Kontext für die Gerichtsbild-Erzeugung).
     *
     * @return array<string, array{name:string,mediaId:int,items:int,itemNames:list<string>}>
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
            $namen = [];
            foreach ((array)($liste['items'] ?? []) as $item) {
                $n = is_array($item) ? trim((string)($item['name'] ?? '')) : '';
                if ($n !== '') {
                    $namen[] = $n;
                }
            }
            $raus[$id] = [
                'name'      => trim((string)($liste['name'] ?? '')),
                'mediaId'   => (int)($liste['mediaId'] ?? 0),
                'items'     => count((array)($liste['items'] ?? [])),
                'itemNames' => $namen,
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
        // PNG-Quellen (generierte Gerichtsbilder) behalten ihre Transparenz —
        // der JPEG-Weg würde sie schwarz füllen.
        $mitAlpha = str_starts_with($roh, "\x89PNG");
        $b = imagesx($bild);
        $h = imagesy($bild);
        $f = min(1.0, $kante / max(1, max($b, $h)));
        $zb = max(1, (int)round($b * $f));
        $zh = max(1, (int)round($h * $f));
        $klein = imagecreatetruecolor($zb, $zh);
        if ($mitAlpha) {
            imagealphablending($klein, false);
            imagesavealpha($klein, true);
        }
        imagecopyresampled($klein, $bild, 0, 0, 0, 0, $zb, $zh, $b, $h);
        imagedestroy($bild);
        ob_start();
        $mitAlpha ? imagepng($klein) : imagejpeg($klein, null, 70);
        $bytes = (string)ob_get_clean();
        imagedestroy($klein);
        return $bytes === ''
            ? ''
            : 'data:image/' . ($mitAlpha ? 'png' : 'jpeg') . ';base64,' . base64_encode($bytes);
    }

    // ------------------------------------------------------------------
    // Payload und Briefing-Auskunft
    // ------------------------------------------------------------------

    /** Das Gericht eines Tages, aufgelöst gegen die Favoriten. */
    private function GerichtFuer(string $datum, array $tage, array $favoriten, array $dishImages = []): array
    {
        $g = $tage[$datum] ?? null;
        if ($g === null) {
            return ['title' => '', 'listId' => '', 'hasIngredients' => false, 'mediaId' => 0];
        }
        if ($g['listId'] !== '' && isset($favoriten[$g['listId']])) {
            $fav = $favoriten[$g['listId']];
            // Ein generiertes Gerichtsbild schlägt das Quell-Foto (einheitlicher
            // Stil); das Original bleibt an der Favoritenliste unangetastet.
            return ['title' => $fav['name'], 'listId' => $g['listId'],
                'hasIngredients' => $fav['items'] > 0,
                'mediaId' => (int)($dishImages[$g['listId']] ?? 0) > 0
                    ? (int)$dishImages[$g['listId']] : $fav['mediaId']];
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
        $dishImages = $this->DishMapLesen();

        $wochen = [];
        foreach ([0, 1] as $w) {
            $montag = $this->DatumInWoche(date('Y-m-d', $jetzt + $w * 7 * 86400), 1);
            $zeilen = [];
            foreach (range(1, 7) as $t) {
                $datum = $this->DatumInWoche($montag, $t);
                $gericht = $this->GerichtFuer($datum, $tage, $favoriten, $dishImages);
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
                'favPick'   => $this->Translate('Choose a recipe …'),
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
    // KI-Gerichtsbilder
    //
    // Einheitlicher Stil für alle Rezepte: Teller streng von oben,
    // transparenter Hintergrund. Das generierte Bild wohnt als eigenes
    // Medienobjekt unter dieser Instanz und wird in der Zuordnung
    // DishImages {listId → mediaId} geführt — die mediaId der Favoritenliste
    // (das Quelldokument für „Rezept öffnen") bleibt unangetastet.
    //
    // Bis zum Kernel-Neustart nach dem Modul-Update existieren Property,
    // Attribute und Timer noch nicht (Create() läuft bei einem Reload nicht
    // erneut) — deshalb tragen alle Zugriffe hier @-Wächter und Rückfälle.
    // ------------------------------------------------------------------

    /** Der Backend-Schalter — @IPS_GetProperty trägt auch die Zeit vor dem Neustart. */
    private function DishSchalterAn(): bool
    {
        return (bool)@IPS_GetProperty($this->InstanceID, 'DishImagesEnabled');
    }

    /** @return array<string,int> listId => Medien-ID */
    private function DishMapLesen(): array
    {
        $roh = json_decode((string)@$this->ReadAttributeString('DishImages'), true);
        $raus = [];
        foreach (is_array($roh) ? $roh : [] as $listId => $mid) {
            if (is_string($listId) && $listId !== '' && (int)$mid > 0) {
                $raus[$listId] = (int)$mid;
            }
        }
        return $raus;
    }

    private function DishMapSchreiben(array $map): void
    {
        @$this->WriteAttributeString('DishImages', (string)json_encode($map, JSON_UNESCAPED_SLASHES));
    }

    /** @return list<array{id:string,tries:int}> */
    private function DishQueueLesen(): array
    {
        $roh = json_decode((string)@$this->ReadAttributeString('DishImageQueue'), true);
        $raus = [];
        foreach (is_array($roh) ? $roh : [] as $e) {
            $id = is_array($e) ? trim((string)($e['id'] ?? '')) : '';
            if ($id !== '') {
                $raus[] = ['id' => $id, 'tries' => max(0, (int)($e['tries'] ?? 0))];
            }
        }
        return $raus;
    }

    private function DishQueueSchreiben(array $queue): void
    {
        @$this->WriteAttributeString('DishImageQueue', (string)json_encode($queue, JSON_UNESCAPED_SLASHES));
    }

    /** One-Shot-Timer setzen — vor dem Kernel-Neustart existiert er noch nicht. */
    private function DishTimerStarten(int $ms): void
    {
        try {
            $this->SetTimerInterval('DishImages', $ms);
        } catch (\Throwable $e) {
            // Timer fehlt bis zum Neustart — der Bedarf steht in der Queue und
            // wird beim nächsten ApplyChanges/Anstoß nachgeholt.
        }
    }

    /**
     * Rezept zur Bild-Erzeugung vormerken (dedupliziert, gedeckelt). Der
     * Kachel-Thread meldet, der Timer-Thread arbeitet ab — die Queue wird
     * darum nur unter der Semaphore verändert.
     */
    private function DishBedarfMelden(string $listId): void
    {
        $listId = trim($listId);
        if ($listId === '' || !$this->DishSchalterAn()) {
            return;
        }
        $lock = 'MPL_DishQ_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 500)) {
            return;
        }
        $neu = false;
        try {
            if (isset($this->DishMapLesen()[$listId])) {
                return;
            }
            $queue = $this->DishQueueLesen();
            if (in_array($listId, array_column($queue, 'id'), true) || count($queue) >= 100) {
                return;
            }
            $queue[] = ['id' => $listId, 'tries' => 0];
            $this->DishQueueSchreiben($queue);
            $neu = true;
        } finally {
            IPS_SemaphoreLeave($lock);
        }
        if ($neu) {
            $this->DishTimerStarten(200);
        }
    }

    /**
     * Ein Timer-Tick: EIN Rezept aus der Queue erzeugen. Sequenziell — das
     * Gateway lässt ohnehin nur einen Anbieter-Aufruf zu, und parallele Ticks
     * wären doppelte Kosten.
     */
    private function DishTick(): void
    {
        // Intervall SOFORT stoppen: der Aufruf dauert bis zu zwei Minuten,
        // ein weiterlaufender Timer würde parallel feuern.
        $this->DishTimerStarten(0);
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }
        $run = 'MPL_DishRun_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($run, 0)) {
            return;
        }
        $naechster = 0;
        try {
            $eintrag = $this->DishQueueEntnehmen(!$this->DishSchalterAn());
            if ($eintrag === null) {
                return;
            }
            $fav = $this->Favoriten()[$eintrag['id']] ?? null;
            if ($fav !== null) {
                $naechster = $this->DishErzeugen($eintrag['id'], $eintrag['tries'], $fav);
            }
            // Liste inzwischen gelöscht → Eintrag stillschweigend verfallen.
            if ($this->DishQueueLesen() !== []) {
                $naechster = max($naechster, 200);
            }
        } finally {
            IPS_SemaphoreLeave($run);
        }
        if ($naechster > 0) {
            $this->DishTimerStarten($naechster);
        }
    }

    /** Nächsten Queue-Eintrag entnehmen; $leeren räumt stattdessen alles ab. */
    private function DishQueueEntnehmen(bool $leeren): ?array
    {
        $lock = 'MPL_DishQ_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 500)) {
            return null;
        }
        try {
            if ($leeren) {
                $this->DishQueueSchreiben([]);
                return null;
            }
            $queue = $this->DishQueueLesen();
            $eintrag = array_shift($queue);
            $this->DishQueueSchreiben($queue);
            return $eintrag;
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    /**
     * Ein Rezept erzeugen und das Ergebnis einsortieren.
     * @return int Verzögerung bis zum nächsten Tick in ms (0 = sofort/regulär)
     */
    private function DishErzeugen(string $listId, int $tries, array $fav): int
    {
        $ergebnis = $this->DishAnfrage((string)$fav['name'], (array)($fav['itemNames'] ?? []), $listId);
        if ($ergebnis !== null && $ergebnis['ok']) {
            // Das Bild hat der Rückruf-Zweig schon abgelegt (siehe DishAnfrage).
            $this->PushState();
            return 0;
        }
        $code = (string)($ergebnis['code'] ?? '');
        if (in_array($code, ['ai_busy', 'ai_rate_limited'], true) && $tries < 3) {
            // Vorübergehend belegt: hinten wieder anstellen, mit Abstand.
            $this->DishWiederAnstellen($listId, $tries + 1);
            $this->SendDebug('DishImages', sprintf('%s: %s — neuer Versuch in 30 s', $listId, $code), 0);
            return 30000;
        }
        if (in_array($code, ['ai_disabled', 'ai_not_configured', 'ai_unauthorized', 'ai_quota'], true)) {
            // Aussichtslos (kein Key, abgeschaltet, Budget leer): die ganze
            // Queue leeren statt Anbieter-Fehler im Sekundentakt zu sammeln.
            $this->DishQueueEntnehmen(true);
            $this->SendDebug('DishImages', sprintf('%s: %s — Warteschlange geleert', $listId, $code), 0);
            return 0;
        }
        if ($tries < 2) {
            $this->DishWiederAnstellen($listId, $tries + 1);
        }
        $this->SendDebug('DishImages', sprintf('%s: fehlgeschlagen (%s), Versuch %d', $listId,
            $code !== '' ? $code : 'keine Antwort', $tries + 1), 0);
        return 0;
    }

    /** Eintrag hinten wieder anstellen (dedupliziert). */
    private function DishWiederAnstellen(string $listId, int $tries): void
    {
        $lock = 'MPL_DishQ_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 500)) {
            return;
        }
        try {
            $queue = $this->DishQueueLesen();
            if (!in_array($listId, array_column($queue, 'id'), true)) {
                $queue[] = ['id' => $listId, 'tries' => $tries];
                $this->DishQueueSchreiben($queue);
            }
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    /**
     * Der eigentliche Gateway-Aufruf. Der Rückruf 'AiResult' kommt zwar
     * synchron zurück, landet aber auf einem ANDEREN Objekt derselben Instanz
     * (gemessen: ein Objektfeld überlebt die Grenze nicht). Briefkasten ist
     * deshalb das Attribut DishErgebnis — der Rückruf-Zweig legt das Bild
     * direkt ab und hinterlässt hier nur das kompakte Ergebnis.
     * @return array{ok:bool,code:string}|null null = Gateway nicht erreicht
     */
    private function DishAnfrage(string $name, array $zutaten, string $listId): ?array
    {
        $gw = $this->GatewayInstanz();
        if ($gw <= 0) {
            return null;
        }
        @$this->WriteAttributeString('DishErgebnis', '{}');
        try {
            IPS_RequestAction($gw, 'AiTileRequest', json_encode([
                'path'    => '/ai/dishimage',
                'payload' => ['name' => $name, 'items' => array_values($zutaten)],
                'txn'     => 'dish:' . $listId,
                'sdwa'    => $this->InstanceID,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            $this->SendDebug('DishImages', 'Gateway-Aufruf fehlgeschlagen: ' . $e->getMessage(), 0);
            return null;
        }
        $ergebnis = json_decode((string)@$this->ReadAttributeString('DishErgebnis'), true);
        if (!is_array($ergebnis) || trim((string)($ergebnis['listId'] ?? '')) !== $listId) {
            return null;
        }
        return ['ok' => ($ergebnis['ok'] ?? false) === true, 'code' => (string)($ergebnis['code'] ?? '')];
    }

    /**
     * Verarbeitet den 'dish:'-Rückruf des Gateways — läuft im Objekt des
     * Rückrufs: das Bild wird SOFORT abgelegt (nur hier existiert es), der
     * wartende DishTick bekommt das kompakte Ergebnis über den Briefkasten.
     * @param array $r {txn, status, json}
     */
    private function DishAntwortVerarbeiten(array $r): void
    {
        $listId = substr((string)($r['txn'] ?? ''), strlen('dish:'));
        $json = $r['json'] ?? null;
        $ok = false;
        $code = '';
        if (is_array($json) && ($json['ok'] ?? false) === true && is_string($json['image'] ?? null)) {
            $name = (string)($this->Favoriten()[$listId]['name'] ?? '');
            $ok = $this->DishBildSpeichern($listId, $name, (string)$json['image']);
            if (!$ok) {
                $code = 'save_failed';
            }
        } else {
            $code = is_array($json) ? (string)($json['error']['code'] ?? '') : '';
        }
        @$this->WriteAttributeString('DishErgebnis', (string)json_encode([
            'listId' => $listId, 'ok' => $ok, 'code' => $code,
        ], JSON_UNESCAPED_SLASHES));
    }

    /** Das 1024er-PNG des Anbieters auf 512 px verkleinern und ablegen. */
    private function DishBildSpeichern(string $listId, string $name, string $b64): bool
    {
        $roh = base64_decode($b64, true);
        if (!is_string($roh) || !str_starts_with($roh, "\x89PNG")) {
            return false;
        }
        $klein = $this->DishVerkleinern($roh, 512);
        if ($klein === '') {
            return false;
        }
        $kat = $this->DishKategorie();
        if ($kat <= 0 || count((array)@IPS_GetChildrenIDs($kat)) >= 100) {
            return false;
        }
        try {
            $mid = IPS_CreateMedia(MEDIATYPE_IMAGE);
            IPS_SetParent($mid, $kat);
            IPS_SetName($mid, mb_substr($name !== '' ? $name : $this->Translate('Dish image'), 0, 80));
            // Ein Medienobjekt braucht erst eine Datei, bevor Content gesetzt werden kann.
            IPS_SetMediaFile($mid, 'media/dish_' . $mid . '.png', false);
            IPS_SetMediaContent($mid, base64_encode($klein));
        } catch (\Throwable $e) {
            $this->SendDebug('DishImages', 'Ablage fehlgeschlagen: ' . $e->getMessage(), 0);
            return false;
        }
        $lock = 'MPL_DishQ_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 500)) {
            return true; // Bild existiert; die Zuordnung holt DishBestandPflegen nach
        }
        try {
            $map = $this->DishMapLesen();
            $map[$listId] = $mid;
            $this->DishMapSchreiben($map);
        } finally {
            IPS_SemaphoreLeave($lock);
        }
        return true;
    }

    /** PNG verkleinern, Transparenz erhalten. '' bei jedem Fehler. */
    private function DishVerkleinern(string $roh, int $kante): string
    {
        if (!function_exists('imagecreatefromstring')) {
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
        imagealphablending($klein, false);
        imagesavealpha($klein, true);
        imagecopyresampled($klein, $bild, 0, 0, 0, 0, $zb, $zh, $b, $h);
        imagedestroy($bild);
        ob_start();
        imagepng($klein);
        $bytes = (string)ob_get_clean();
        imagedestroy($klein);
        return $bytes;
    }

    /** Ablage-Kategorie „Gerichtsbilder" unter dieser Instanz — bei Bedarf anlegen. */
    private function DishKategorie(): int
    {
        $kat = (int)@$this->ReadAttributeInteger('DishImageCategory');
        if ($kat > 0 && @IPS_CategoryExists($kat)
            && (int)(@IPS_GetObject($kat)['ParentID'] ?? -1) === $this->InstanceID) {
            return $kat;
        }
        try {
            $kat = IPS_CreateCategory();
            IPS_SetParent($kat, $this->InstanceID);
            IPS_SetName($kat, $this->Translate('Dish images'));
        } catch (\Throwable $e) {
            return 0;
        }
        @$this->WriteAttributeInteger('DishImageCategory', $kat);
        return $kat;
    }

    /**
     * Bestand pflegen (aus ApplyChanges): verwaiste Bilder gelöschter
     * Favoritenlisten entfernen, für geplante Rezepte ohne Bild den Bedarf
     * nachziehen. Läuft NICHT im Payload-Lesepfad — ein Getter löscht nichts.
     */
    private function DishBestandPflegen(): void
    {
        $favoriten = $this->Favoriten();

        // Aufräumen nur mit belastbarer Favoritenliste: eine leere Antwort kann
        // auch ein Reload-Loch der Einkaufsliste sein — dann lieber behalten.
        if ($favoriten !== []) {
            $kat = (int)@$this->ReadAttributeInteger('DishImageCategory');
            $lock = 'MPL_DishQ_' . $this->InstanceID;
            if (IPS_SemaphoreEnter($lock, 500)) {
                try {
                    $map = $this->DishMapLesen();
                    $geaendert = false;
                    foreach ($map as $listId => $mid) {
                        if (isset($favoriten[$listId])) {
                            continue;
                        }
                        // Nur eigene Objekte löschen: Parent muss die eigene
                        // Ablage-Kategorie sein (Schutz vor korrupter Map).
                        if ($kat > 0 && @IPS_MediaExists($mid)
                            && (int)(@IPS_GetObject($mid)['ParentID'] ?? -1) === $kat) {
                            @IPS_DeleteMedia($mid, true);
                        }
                        unset($map[$listId]);
                        $geaendert = true;
                    }
                    if ($geaendert) {
                        $this->DishMapSchreiben($map);
                    }
                } finally {
                    IPS_SemaphoreLeave($lock);
                }
            }
        }

        if (!$this->DishSchalterAn()) {
            return;
        }
        // Nachzug: alle geplanten Rezepte ohne Bild vormerken (deckt auch das
        // frische Einschalten des Schalters ab).
        foreach ($this->PlanLesen() as $g) {
            if ($g['listId'] !== '' && isset($favoriten[$g['listId']])) {
                $this->DishBedarfMelden($g['listId']);
            }
        }
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

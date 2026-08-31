<?php

declare(strict_types=1);

/**
 * KI-Gerichtsbilder für Rezept-Favoritenlisten.
 *
 * Das Gateway ist Erzeuger UND Lagerort: Es nimmt Bedarfsmeldungen der
 * Einkaufslisten entgegen (eine neu angelegte Liste mit Rezept-Haken),
 * arbeitet sie nacheinander ab und legt das fertige Bild als Medienobjekt
 * unter der eigenen Kategorie „Gerichtsbilder" ab. Die Bild-ID meldet es an
 * die Einkaufsliste zurück, wo sie am Favoritenlisten-Datensatz wohnt — von
 * dort reist sie ohne weiteres Zutun in Web-App, Kacheln und iOS-App.
 *
 * Warum sequenziell mit Timer statt sofort: Eine Bilderzeugung dauert bis zu
 * zwei Minuten. Sie darf den Anlege-Aufruf der Einkaufsliste nicht blockieren,
 * und zwei gleichzeitige Läufe wären doppelte Kosten (das Gateway lässt
 * ohnehin nur einen Anbieter-Aufruf zu, siehe Semaphore in AiRunCompletion).
 *
 * Anders als in der früheren MealPlan-Fassung entfällt hier der komplette
 * Relay-Umweg: das Gateway ruft AiGenerateDishImage direkt auf — kein
 * AiTileRequest, kein Attribut-Briefkasten, keine GUID-Weißliste.
 */
trait DishImages
{
    /** GUID der Einkaufsliste — Ziel der Rückmeldung und Quelle der Rezepte. */
    private const DISH_SHOPPING_GUID = '{A5D3F2E1-7B4C-4E8A-9D6F-1C2B3A4E5F6D}';

    /** So viele Bilder passen unter die Ablage-Kategorie. */
    private const DISH_MEDIA_MAX = 200;

    /** So viele Rezepte dürfen gleichzeitig warten. */
    private const DISH_QUEUE_MAX = 100;

    /** Längste Kante des abgelegten Bildes (der Anbieter liefert 1024). */
    private const DISH_EDGE = 512;

    public function DishCreate(): void
    {
        $this->RegisterPropertyBoolean('DishImagesEnabled', false);
        $this->RegisterAttributeString('DishQueue', '[]');
        $this->RegisterAttributeInteger('DishImageCategory', 0);
        // One-Shot-Timer, Hausmuster ohne Präfix-Wrapper.
        $this->RegisterTimer('DishImages', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'DishTick\', 0);');
    }

    /** Aus ApplyChanges: Bestand aufräumen und offene Warteschlange anstoßen. */
    public function DishApplyChanges(): void
    {
        $this->DishAufraeumen();
        if ($this->DishQueueLesen() !== []) {
            $this->DishTimerStarten(200);
        }
    }

    /**
     * Bilder wegräumen, die keine Favoritenliste mehr beansprucht — etwa weil
     * eine Liste über das Konfigurationsformular verschwand oder eine ganze
     * Einkaufslisten-Instanz gelöscht wurde.
     *
     * Vorsichtig: Antwortet KEINE Einkaufsliste, wird nichts angefasst. Ein
     * Modul-Reload liefert kurzzeitig leere Zustände, und daraufhin alle Bilder
     * zu löschen wäre ein teurer Irrtum (jedes kostet Geld und ist nicht
     * wiederherstellbar).
     */
    private function DishAufraeumen(): void
    {
        $kat = (int)@$this->ReadAttributeInteger('DishImageCategory');
        if ($kat <= 0 || !@IPS_CategoryExists($kat)) {
            return;
        }
        $listen = (array)@IPS_GetInstanceListByModuleID(self::DISH_SHOPPING_GUID);
        if ($listen === []) {
            return;
        }
        $beansprucht = [];
        $eineAntwortete = false;
        foreach ($listen as $sl) {
            $favoriten = $this->DishFavoriten((int)$sl);
            if ($favoriten === []) {
                continue;   // stumme Liste zählt nicht als „hat keine Bilder"
            }
            $eineAntwortete = true;
            foreach ($favoriten as $f) {
                $mid = is_array($f) ? (int)($f['imageId'] ?? 0) : 0;
                if ($mid > 0) {
                    $beansprucht[$mid] = true;
                }
            }
        }
        if (!$eineAntwortete) {
            return;
        }
        $weg = 0;
        foreach ((array)@IPS_GetChildrenIDs($kat) as $mid) {
            if (!isset($beansprucht[(int)$mid])) {
                @IPS_DeleteMedia((int)$mid, true);
                $weg++;
            }
        }
        if ($weg > 0) {
            $this->SendDebug('DishImages', sprintf('%d verwaiste(s) Bild(er) entfernt', $weg), 0);
        }
    }

    /**
     * Aus dem RequestAction-Dispatcher.
     * @return bool true, wenn der Ident hier behandelt wurde
     */
    public function DishRequestAction(string $Ident, mixed $Value): bool
    {
        if ($Ident === 'DishRequest') {
            // Bedarfsmeldung einer Einkaufsliste: {instanceId, listId}
            $daten = is_array($Value) ? $Value : json_decode((string)$Value, true);
            if (is_array($daten)) {
                $this->DishBedarfMelden(
                    (int)($daten['instanceId'] ?? 0),
                    trim((string)($daten['listId'] ?? ''))
                );
            }
            return true;
        }
        if ($Ident === 'DishTick') {
            $this->DishTick();
            return true;
        }
        if ($Ident === 'DishAdopt') {
            // Der Essensplan reicht ein Altbild herüber (Einmal-Migration).
            $this->DishUebernehmen($Value);
            return true;
        }
        return false;
    }

    /**
     * Formular-Panel im KI-Bereich. Wie die Nachbar-Panels erst prüfen, ob die
     * Eigenschaft schon existiert — sie entsteht in Create() und damit erst
     * beim nächsten Kernel-Start; ein Feld darauf ließe „Übernehmen" scheitern.
     */
    private function GetDishPanel(): array
    {
        $cfg = json_decode((string)@IPS_GetConfiguration($this->InstanceID), true);
        if (!is_array($cfg) || !array_key_exists('DishImagesEnabled', $cfg)) {
            return [
                'type'     => 'ExpansionPanel',
                'caption'  => $this->Translate('Dish images'),
                'expanded' => false,
                'items'    => [[
                    'type'    => 'Label',
                    'caption' => $this->Translate('The dish image settings appear after the next Symcon restart — they are new settings, and those only exist once the kernel has loaded the module again.')
                ]]
            ];
        }
        return [
            'type'     => 'ExpansionPanel',
            'caption'  => $this->Translate('Dish images'),
            'expanded' => false,
            'items'    => [
                ['type' => 'CheckBox', 'name' => 'DishImagesEnabled',
                 'caption' => $this->Translate('Generate dish images for recipe favorite lists')],
                ['type' => 'Label',
                 'caption' => $this->Translate('Favorite lists marked as a recipe get a uniform dish image (plate seen from above, transparent background), generated once per list and shown instead of the heart. Needs an OpenAI API key above and costs about 4 cents per image.')],
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Warteschlange
    // ------------------------------------------------------------------

    private function DishSchalterAn(): bool
    {
        return (bool)@IPS_GetProperty($this->InstanceID, 'DishImagesEnabled');
    }

    /** @return list<array{sl:int,id:string,tries:int}> */
    private function DishQueueLesen(): array
    {
        $roh = json_decode((string)@$this->ReadAttributeString('DishQueue'), true);
        $raus = [];
        foreach (is_array($roh) ? $roh : [] as $e) {
            $id = is_array($e) ? trim((string)($e['id'] ?? '')) : '';
            $sl = is_array($e) ? (int)($e['sl'] ?? 0) : 0;
            if ($id !== '' && $sl > 0) {
                $raus[] = ['sl' => $sl, 'id' => $id, 'tries' => max(0, (int)($e['tries'] ?? 0))];
            }
        }
        return $raus;
    }

    private function DishQueueSchreiben(array $queue): void
    {
        @$this->WriteAttributeString('DishQueue', (string)json_encode($queue, JSON_UNESCAPED_SLASHES));
    }

    /** One-Shot-Timer setzen — vor dem Kernel-Neustart existiert er noch nicht. */
    private function DishTimerStarten(int $ms): void
    {
        try {
            $this->SetTimerInterval('DishImages', $ms);
        } catch (\Throwable $e) {
            // Timer fehlt bis zum Neustart; der Bedarf steht in der Warteschlange
            // und wird beim nächsten ApplyChanges nachgeholt.
        }
    }

    /**
     * Ein Rezept zur Bild-Erzeugung vormerken (dedupliziert, gedeckelt).
     * Der meldende Thread der Einkaufsliste und der Timer-Thread laufen
     * nebeneinander — die Warteschlange wird darum nur unter Semaphore
     * verändert.
     */
    private function DishBedarfMelden(int $slID, string $listId): void
    {
        $listId = trim($listId);
        if ($listId === '' || $slID <= 0 || !$this->DishSchalterAn()) {
            return;
        }
        $lock = 'TGW_DishQ_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 500)) {
            return;
        }
        $neu = false;
        try {
            $queue = $this->DishQueueLesen();
            foreach ($queue as $e) {
                if ($e['sl'] === $slID && $e['id'] === $listId) {
                    return;
                }
            }
            if (count($queue) >= self::DISH_QUEUE_MAX) {
                return;
            }
            $queue[] = ['sl' => $slID, 'id' => $listId, 'tries' => 0];
            $this->DishQueueSchreiben($queue);
            $neu = true;
        } finally {
            IPS_SemaphoreLeave($lock);
        }
        if ($neu) {
            $this->DishTimerStarten(200);
        }
    }

    /** Nächsten Eintrag entnehmen; $leeren räumt stattdessen alles ab. */
    private function DishQueueEntnehmen(bool $leeren): ?array
    {
        $lock = 'TGW_DishQ_' . $this->InstanceID;
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

    /** Eintrag hinten wieder anstellen (dedupliziert). */
    private function DishWiederAnstellen(int $slID, string $listId, int $tries): void
    {
        $lock = 'TGW_DishQ_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 500)) {
            return;
        }
        try {
            $queue = $this->DishQueueLesen();
            foreach ($queue as $e) {
                if ($e['sl'] === $slID && $e['id'] === $listId) {
                    return;
                }
            }
            $queue[] = ['sl' => $slID, 'id' => $listId, 'tries' => $tries];
            $this->DishQueueSchreiben($queue);
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    // ------------------------------------------------------------------
    // Erzeugung
    // ------------------------------------------------------------------

    /**
     * Ein Timer-Tick: EIN Rezept abarbeiten. Der Timer wird sofort gestoppt —
     * der Aufruf dauert bis zu zwei Minuten, ein weiterlaufendes Intervall
     * würde parallel feuern und doppelt kosten.
     */
    private function DishTick(): void
    {
        $this->DishTimerStarten(0);
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }
        $run = 'TGW_DishRun_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($run, 0)) {
            return;
        }
        $naechster = 0;
        try {
            $eintrag = $this->DishQueueEntnehmen(!$this->DishSchalterAn());
            if ($eintrag === null) {
                return;
            }
            $rezept = $this->DishRezept($eintrag['sl'], $eintrag['id']);
            if ($rezept !== null) {
                $naechster = $this->DishErzeugen($eintrag['sl'], $eintrag['id'], $eintrag['tries'], $rezept);
            }
            // Liste inzwischen gelöscht oder kein Rezept mehr → verfällt still.
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

    /**
     * Name und Zutatennamen einer Favoritenliste — oder null, wenn sie nicht
     * (mehr) existiert, kein Rezept ist oder schon ein Bild hat.
     * @return array{name:string,items:list<string>}|null
     */
    private function DishRezept(int $slID, string $listId): ?array
    {
        foreach ($this->DishFavoriten($slID) as $liste) {
            if (trim((string)($liste['id'] ?? '')) !== $listId) {
                continue;
            }
            if (($liste['isRecipe'] ?? false) !== true || (int)($liste['imageId'] ?? 0) > 0) {
                return null;
            }
            $namen = [];
            foreach ((array)($liste['items'] ?? []) as $item) {
                $n = is_array($item) ? trim((string)($item['name'] ?? '')) : '';
                if ($n !== '') {
                    $namen[] = $n;
                }
            }
            return ['name' => trim((string)($liste['name'] ?? '')), 'items' => $namen];
        }
        return null;
    }

    /** Die Favoritenlisten einer Einkaufsliste (leer bei jedem Fehler). */
    private function DishFavoriten(int $slID): array
    {
        if ($slID <= 0 || !function_exists('SL_GetAppState')) {
            return [];
        }
        try {
            $antwort = json_decode((string)@SL_GetAppState($slID), true);
        } catch (\Throwable $e) {
            return [];
        }
        $listen = $antwort['state']['favoriteLists'] ?? null;
        return is_array($listen) ? $listen : [];
    }

    /**
     * Ein Rezept erzeugen, ablegen und die Bild-ID zurückmelden.
     * @param array{name:string,items:list<string>} $rezept
     * @return int Verzögerung bis zum nächsten Tick in ms (0 = regulär)
     */
    private function DishErzeugen(int $slID, string $listId, int $tries, array $rezept): int
    {
        $r = $this->AiGenerateDishImage($rezept['name'], $rezept['items']);
        if (($r['ok'] ?? false) === true && is_string($r['image'] ?? null)) {
            $this->MailCountDay();   // erst nach Erfolg zählen, wie bei den übrigen KI-Wegen
            $mid = $this->DishBildSpeichern($rezept['name'], (string)$r['image']);
            if ($mid > 0) {
                $this->DishMelden($slID, $listId, $mid);
                return 0;
            }
            $this->SendDebug('DishImages', $listId . ': Ablage fehlgeschlagen', 0);
            return 0;
        }

        $code = (string)($r['code'] ?? '');
        if (in_array($code, ['ai_busy', 'ai_rate_limited'], true) && $tries < 3) {
            // Vorübergehend belegt: hinten wieder anstellen, mit Abstand.
            $this->DishWiederAnstellen($slID, $listId, $tries + 1);
            $this->SendDebug('DishImages', sprintf('%s: %s — neuer Versuch in 30 s', $listId, $code), 0);
            return 30000;
        }
        if (in_array($code, ['ai_disabled', 'ai_not_configured', 'ai_unauthorized', 'ai_quota'], true)) {
            // Aussichtslos (abgeschaltet, kein Key, Organisation nicht verifiziert,
            // Budget leer): die ganze Warteschlange leeren statt Anbieter-Fehler
            // im Sekundentakt zu sammeln.
            $this->DishQueueEntnehmen(true);
            $this->DishAufgeben($slID, $listId);
            $this->SendDebug('DishImages', sprintf('%s: %s — Warteschlange geleert', $listId, $code), 0);
            return 0;
        }
        if ($tries < 2) {
            $this->DishWiederAnstellen($slID, $listId, $tries + 1);
        } else {
            $this->DishAufgeben($slID, $listId);
        }
        $this->SendDebug('DishImages', sprintf('%s: fehlgeschlagen (%s), Versuch %d', $listId,
            $code !== '' ? $code : 'keine Antwort', $tries + 1), 0);
        return 0;
    }

    /** Das 1024er-PNG des Anbieters verkleinern und ablegen; 0 bei Fehler. */
    private function DishBildSpeichern(string $name, string $b64): int
    {
        $roh = base64_decode($b64, true);
        if (!is_string($roh) || !str_starts_with($roh, "\x89PNG")) {
            return 0;
        }
        $klein = $this->DishVerkleinern($roh, self::DISH_EDGE);
        if ($klein === '') {
            return 0;
        }
        $kat = $this->DishKategorie();
        if ($kat <= 0 || count((array)@IPS_GetChildrenIDs($kat)) >= self::DISH_MEDIA_MAX) {
            return 0;
        }
        try {
            $mid = IPS_CreateMedia(MEDIATYPE_IMAGE);
            IPS_SetParent($mid, $kat);
            IPS_SetName($mid, mb_substr($name !== '' ? $name : $this->Translate('Dish image'), 0, 80));
            // Ein Medienobjekt braucht erst eine Datei, bevor Content gesetzt werden kann.
            IPS_SetMediaFile($mid, 'media/dish_' . $mid . '.png', false);
            IPS_SetMediaContent($mid, base64_encode($klein));
            return $mid;
        } catch (\Throwable $e) {
            $this->SendDebug('DishImages', 'Ablage fehlgeschlagen: ' . $e->getMessage(), 0);
            return 0;
        }
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
        ob_start();
        imagepng($klein);
        return (string)ob_get_clean();
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
     * GET /v1/dishimage/{mediaId} — das Bild als PNG. Anders als der
     * Asset-Hook der Einkaufsliste prüft dieser Weg gegen die eigene
     * Ablage-Kategorie: Nur was darunter hängt, geht hinaus.
     */
    private function HandleDishImage(int $mediaId, int $kante): void
    {
        $kat = (int)@$this->ReadAttributeInteger('DishImageCategory');
        if ($mediaId <= 0 || $kat <= 0 || !@IPS_MediaExists($mediaId) || (int)@IPS_GetParent($mediaId) !== $kat) {
            $this->SendApiError('not_found', 'Not a dish image', 404);
            return;
        }
        $roh = base64_decode((string)@IPS_GetMediaContent($mediaId), true);
        if (!is_string($roh) || !str_starts_with($roh, "\x89PNG")) {
            $this->SendApiError('not_found', 'Not a dish image', 404);
            return;
        }
        $kante = max(0, min(512, $kante));
        if ($kante > 0) {
            $klein = $this->DishVerkleinern($roh, $kante);
            if ($klein !== '' && strlen($klein) < strlen($roh)) {
                $roh = $klein;
            }
        }
        header('Content-Type: image/png');
        header('X-Content-Type-Options: nosniff');
        // Ein erzeugtes Bild ändert sich nie — ein neues bekäme eine neue Kennung.
        header('Cache-Control: public, max-age=31536000, immutable');
        echo $roh;
    }

    // ------------------------------------------------------------------
    // Rückmeldung an die Einkaufsliste
    // ------------------------------------------------------------------

    /** Die fertige Bild-ID an der Favoritenliste hinterlegen. */
    private function DishMelden(int $slID, string $listId, int $mid): void
    {
        try {
            IPS_RequestAction($slID, 'SetFavoriteImage', json_encode([
                'listId'  => $listId,
                'imageId' => $mid,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            $this->SendDebug('DishImages', 'Rückmeldung fehlgeschlagen: ' . $e->getMessage(), 0);
        }
    }

    /**
     * Endgültig aufgeben: der Liste den Rezept-Haken nehmen, damit die
     * Oberflächen wieder das Herz zeigen statt dauerhaft einen Platzhalter,
     * den niemand einordnen kann.
     */
    private function DishAufgeben(int $slID, string $listId): void
    {
        try {
            IPS_RequestAction($slID, 'SetFavoriteImage', json_encode([
                'listId'  => $listId,
                'imageId' => 0,
                'failed'  => true,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            $this->SendDebug('DishImages', 'Aufgabe-Meldung fehlgeschlagen: ' . $e->getMessage(), 0);
        }
    }

    // ------------------------------------------------------------------
    // Migration und Bestandspflege
    // ------------------------------------------------------------------

    /**
     * Ein Altbild des Essensplans übernehmen: Medienobjekt unter die eigene
     * Kategorie hängen und die Zuordnung an die Einkaufsliste melden.
     *
     * Den Anstoß gibt der Essensplan selbst (er kennt seine alte Zuordnung),
     * statt dass das Gateway in fremden Attributen stöbert — die stehen nur
     * in der Konfigurationsdatei und dort mit bis zu acht Minuten Verzug.
     * @param mixed $Value {slID, listId, mediaId}
     */
    private function DishUebernehmen(mixed $Value): void
    {
        $daten = is_array($Value) ? $Value : json_decode((string)$Value, true);
        if (!is_array($daten)) {
            return;
        }
        $sl     = (int)($daten['slID'] ?? 0);
        $listId = trim((string)($daten['listId'] ?? ''));
        $mid    = (int)($daten['mediaId'] ?? 0);
        $kat    = $this->DishKategorie();
        if ($sl <= 0 || $listId === '' || $mid <= 0 || $kat <= 0 || !@IPS_MediaExists($mid)) {
            return;
        }
        try {
            IPS_SetParent($mid, $kat);
        } catch (\Throwable $e) {
            $this->SendDebug('DishImages', 'Übernahme fehlgeschlagen: ' . $e->getMessage(), 0);
            return;
        }
        // Rezept-Haken mitsetzen: die Anzeige hängt daran, und ein Bild ohne
        // Haken bliebe unsichtbar.
        try {
            IPS_RequestAction($sl, 'SetFavoriteImage', json_encode([
                'listId'   => $listId,
                'imageId'  => $mid,
                'isRecipe' => true,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            $this->SendDebug('DishImages', 'Übernahme-Meldung fehlgeschlagen: ' . $e->getMessage(), 0);
        }
    }
}

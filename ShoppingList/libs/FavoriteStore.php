<?php

declare(strict_types=1);

trait FavoriteStore
{
    /**
     * Das SymDo-Gateway erzeugt die Gerichtsbilder der Rezept-Favoritenlisten
     * und lagert sie. Ohne Gateway bleibt die Liste voll nutzbar — sie zeigt
     * dann eben das Herz.
     */
    private const DISH_GATEWAY_GUID = '{E677FE7B-28C9-4124-8B58-8A1FE2657E8D}';

    private function LoadFavoriteLists(): array
    {
        $raw  = $this->ReadAttributeString('FavoriteLists');
        $data = json_decode($raw, true);
        return is_array($data) ? array_values(array_filter($data, fn($l) => is_array($l))) : [];
    }

    private function SaveFavoriteLists(array $lists): void
    {
        $this->WriteAttributeString(
            'FavoriteLists',
            json_encode(array_values($lists), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    private function CreateFavoriteListInternal(string $name, bool $isRecipe = false): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }
        $lists   = $this->LoadFavoriteLists();
        $id      = bin2hex(random_bytes(8));
        $eintrag = ['id' => $id, 'name' => $name, 'items' => []];
        // Rezept-Haken: nur damit erzeugt das Gateway ein Gerichtsbild, und nur
        // dann zeigen die Oberflächen es anstelle des Herzens.
        if ($isRecipe) {
            $eintrag['isRecipe'] = true;
        }
        $lists[] = $eintrag;
        $this->SaveFavoriteLists($lists);
        $this->SendState();
        if ($isRecipe) {
            $this->DishAnfordern($id);
        }
        return $id;
    }

    private function AddItemToFavoriteListInternal(string $listId, string $itemName, string $category, string $amount, string $notes = ''): bool
    {
        $name = trim($itemName);
        if ($name === '' || $listId === '') {
            return false;
        }
        $amount = trim($amount);
        if ($amount === '') {
            $amount = '1';
        }
        $lists     = $this->LoadFavoriteLists();
        $nameLower = mb_strtolower($name);
        foreach ($lists as &$list) {
            if ($list['id'] !== $listId) {
                continue;
            }
            foreach ($list['items'] as &$item) {
                if (mb_strtolower($item['name']) === $nameLower) {
                    $item['amount'] = $amount;
                    $item['notes']  = trim($notes);
                    $this->SaveFavoriteLists($lists);
                    $this->SendState();
                    return true;
                }
            }
            unset($item);
            $list['items'][] = ['name' => $name, 'category' => trim($category), 'amount' => $amount, 'notes' => trim($notes)];
            $this->SaveFavoriteLists($lists);
            $this->SendState();
            return true;
        }
        return false;
    }

    /**
     * Fügt mehrere Artikel in einem Rutsch hinzu — entweder in eine bestehende Liste
     * ($listId) oder in eine neu anzulegende ($newListName). Ein Aufruf, ein State-Push.
     * Gibt die (ggf. neue) Listen-ID zurück, '' bei Fehler.
     * @param array  $items   Liste von {name, category?, amount?, notes?}
     * @param string $url      optionale Rezept-URL, die auf der Liste gespeichert wird
     * @param string $mediaId  optionale Rezeptfoto-Medienobjekt-ID (Alternative zur URL)
     */
    private function AddItemsToFavoriteListInternal(string $listId, string $newListName, array $items, string $url = '', string $mediaId = '', bool $isRecipe = false): string
    {
        $lists       = $this->LoadFavoriteLists();
        $targetIndex = -1;
        $isNew       = false;
        if ($listId !== '') {
            foreach ($lists as $i => $list) {
                if (($list['id'] ?? '') === $listId) {
                    $targetIndex = $i;
                    break;
                }
            }
        }
        if ($targetIndex === -1) {
            $name = trim($newListName);
            if ($name === '') {
                return '';
            }
            $listId      = bin2hex(random_bytes(8));
            $lists[]     = ['id' => $listId, 'name' => $name, 'items' => []];
            $targetIndex = count($lists) - 1;
            $isNew       = true;
        }
        if (!isset($lists[$targetIndex]['items']) || !is_array($lists[$targetIndex]['items'])) {
            $lists[$targetIndex]['items'] = [];
        }
        // Rezept-Quelle (URL oder Rezeptfoto-Medien-ID) nur bei NEU angelegten Listen
        // hinterlegen — bestehende Listen behalten ihre Quelle.
        if ($isNew) {
            $url = trim($url);
            if ($url !== '') {
                $lists[$targetIndex]['url'] = $url;
            }
            $mediaId = trim($mediaId);
            if ($mediaId !== '') {
                $lists[$targetIndex]['mediaId'] = (int)$mediaId;
            }
            if ($isRecipe) {
                $lists[$targetIndex]['isRecipe'] = true;
            }
        }

        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }
            $name = trim((string)($it['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $amount = trim((string)($it['amount'] ?? ''));
            if ($amount === '') {
                $amount = '1';
            }
            $category  = trim((string)($it['category'] ?? ''));
            $notes     = trim((string)($it['notes'] ?? ''));
            $nameLower = mb_strtolower($name);
            $found     = false;
            foreach ($lists[$targetIndex]['items'] as &$item) {
                if (mb_strtolower((string)($item['name'] ?? '')) === $nameLower) {
                    $item['amount'] = $amount;
                    $item['notes']  = $notes;
                    $found          = true;
                    break;
                }
            }
            unset($item);
            if (!$found) {
                $lists[$targetIndex]['items'][] = ['name' => $name, 'category' => $category, 'amount' => $amount, 'notes' => $notes];
            }
        }

        $this->SaveFavoriteLists($lists);
        $this->SendState();
        if ($isNew && $isRecipe) {
            $this->DishAnfordern($listId);
        }
        return $listId;
    }

    /**
     * Die vom Gateway erzeugte Bild-ID an der Liste hinterlegen.
     * imageId 0 mit failed=true heißt: die Erzeugung ist endgültig gescheitert
     * — dann fällt der Rezept-Haken weg und die Oberflächen zeigen wieder das
     * Herz, statt dauerhaft einen Platzhalter, den niemand einordnen kann.
     */
    private function SetFavoriteImageInternal(string $listId, int $imageId, bool $failed = false, bool $alsRezept = false): bool
    {
        $listId = trim($listId);
        if ($listId === '') {
            return false;
        }
        $lists = $this->LoadFavoriteLists();
        foreach ($lists as &$l) {
            if (!is_array($l) || trim((string)($l['id'] ?? '')) !== $listId) {
                continue;
            }
            if ($imageId > 0) {
                $l['imageId'] = $imageId;
                if ($alsRezept) {
                    // Übernahme eines Altbestands: ohne Haken bliebe es unsichtbar.
                    $l['isRecipe'] = true;
                }
            } elseif ($failed) {
                unset($l['imageId'], $l['isRecipe']);
            } else {
                unset($l['imageId']);
            }
            unset($l);
            $this->SaveFavoriteLists($lists);
            $this->SendState();
            return true;
        }
        unset($l);
        return false;
    }

    /**
     * Eine bestehende Liste nachträglich als Rezept markieren (oder die
     * Markierung zurücknehmen). Beim Markieren wird gleich ein Bild
     * angefordert; beim Zurücknehmen fällt die Liste aufs Herz zurück und ihr
     * Bild wird mitgelöscht — es hätte sonst niemanden mehr, der es zeigt.
     */
    private function SetFavoriteRecipeInternal(string $listId, bool $isRecipe): bool
    {
        $listId = trim($listId);
        if ($listId === '') {
            return false;
        }
        $lists = $this->LoadFavoriteLists();
        foreach ($lists as &$l) {
            if (!is_array($l) || trim((string)($l['id'] ?? '')) !== $listId) {
                continue;
            }
            if ($isRecipe) {
                $l['isRecipe'] = true;
            } else {
                $this->DeleteDishImage((int)($l['imageId'] ?? 0));
                unset($l['isRecipe'], $l['imageId']);
            }
            unset($l);
            $this->SaveFavoriteLists($lists);
            $this->SendState();
            if ($isRecipe) {
                $this->DishAnfordern($listId);
            }
            return true;
        }
        unset($l);
        return false;
    }

    /**
     * Beim Gateway ein Gerichtsbild anfordern. Es stellt nur in die
     * Warteschlange — die Erzeugung dauert bis zu zwei Minuten und darf den
     * Anlege-Aufruf niemals aufhalten.
     */
    private function DishAnfordern(string $listId): void
    {
        $gw = $this->DishGatewayInstanz();
        if ($gw <= 0 || trim($listId) === '') {
            return;
        }
        try {
            IPS_RequestAction($gw, 'DishRequest', json_encode([
                'instanceId' => $this->InstanceID,
                'listId'     => trim($listId),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            // Ohne Gateway (oder waehrend eines Modul-Reloads) gibt es eben kein
            // Bild — die Liste bleibt nutzbar und zeigt weiter das Herz.
            $this->SendDebug('FavoriteStore', 'Gerichtsbild-Anforderung fehlgeschlagen: ' . $e->getMessage(), 0);
        }
    }

    /** Das Gateway, das die App bedient: die Instanz mit der niedrigsten ID. */
    private function DishGatewayInstanz(): int
    {
        $ids = @IPS_GetInstanceListByModuleID(self::DISH_GATEWAY_GUID);
        if (!is_array($ids) || $ids === []) {
            return 0;
        }
        sort($ids);
        return (int)$ids[0];
    }

    /**
     * Auslieferung eines Gerichtsbildes über den Asset-Hook. Der Token ist
     * schon geprüft; hier zählt nur noch, dass die Kennung wirklich zu einer
     * eigenen Rezeptliste gehört — sonst wäre der Hook ein Leseschlüssel für
     * beliebige Medienobjekte des Systems.
     */
    private function HandleDishImageHook(int $mediaId): void
    {
        $erlaubt = false;
        foreach ($this->LoadFavoriteLists() as $l) {
            if (is_array($l) && (int)($l['imageId'] ?? 0) === $mediaId && $mediaId > 0) {
                $erlaubt = true;
                break;
            }
        }
        if (!$erlaubt || !@IPS_MediaExists($mediaId)) {
            http_response_code(404);
            return;
        }
        $roh = base64_decode((string)@IPS_GetMediaContent($mediaId), true);
        if (!is_string($roh) || !str_starts_with($roh, "\x89PNG")) {
            http_response_code(404);
            return;
        }
        // Auf Wunsch verkleinert ausliefern: das Badge zeigt 34 px, die Ablage
        // hat 512 — ungefragt wären das eine halbe Megabyte je Zeile. Dank der
        // Ewigkeits-Frist unten rechnet das jeder Browser genau einmal.
        $kante = max(0, min(512, (int)($_GET['s'] ?? 0)));
        if ($kante > 0) {
            $klein = $this->DishSkalieren($roh, $kante);
            if ($klein !== '') {
                $roh = $klein;
            }
        }
        header('Content-Type: image/png');
        header('X-Content-Type-Options: nosniff');
        // Ein erzeugtes Gerichtsbild ändert sich nie — die Kennung wechselt,
        // wenn ein neues entsteht.
        header('Cache-Control: public, max-age=31536000, immutable');
        echo $roh;
    }

    /** PNG verkleinern, Transparenz erhalten. '' bei jedem Fehler. */
    private function DishSkalieren(string $roh, int $kante): string
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
        if (max($b, $h) <= $kante) {
            return '';   // schon klein genug
        }
        $f = $kante / max(1, max($b, $h));
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

    /**
     * Ein generiertes Gerichtsbild löschen — nur wenn es wirklich unter der
     * Gateway-Kategorie „Gerichtsbilder" hängt (gleicher Namens-Wächter wie
     * bei den Rezeptfotos, damit eine korrupte Zuordnung keine fremden
     * Objekte kostet).
     */
    private function DeleteDishImage(int $mediaId): void
    {
        if ($mediaId <= 0 || !@IPS_MediaExists($mediaId)) {
            return;
        }
        $parent = (int)@IPS_GetParent($mediaId);
        if ($parent > 0 && @IPS_GetName($parent) === 'Gerichtsbilder') {
            @IPS_DeleteMedia($mediaId, true);
        }
    }

    private function RemoveItemFromFavoriteListInternal(string $listId, string $itemName): bool
    {
        $name = trim($itemName);
        if ($name === '' || $listId === '') {
            return false;
        }
        $lists     = $this->LoadFavoriteLists();
        $nameLower = mb_strtolower($name);
        foreach ($lists as &$list) {
            if ($list['id'] !== $listId) {
                continue;
            }
            $before        = count($list['items']);
            $list['items'] = array_values(
                array_filter($list['items'], fn($i) => mb_strtolower($i['name']) !== $nameLower)
            );
            if (count($list['items']) < $before) {
                $this->SaveFavoriteLists($lists);
                $this->SendState();
                return true;
            }
            return false;
        }
        return false;
    }

    private function AddFavoriteListToCartInternal(string $listId): void
    {
        if ($listId === '') {
            return;
        }
        $lists      = $this->LoadFavoriteLists();
        $targetList = null;
        foreach ($lists as $list) {
            if ($list['id'] === $listId) {
                $targetList = $list;
                break;
            }
        }
        if ($targetList === null || empty($targetList['items'])) {
            return;
        }

        $semaphoreKey = 'SL_Items_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($semaphoreKey, 500)) {
            $this->SendDebug('FavoriteStore', 'Semaphore timeout on AddFavoriteListToCart', 0);
            return;
        }
        try {
            $items   = $this->LoadItems();
            $changed = false;
            foreach ($targetList['items'] as $favItem) {
                $favLower = mb_strtolower($favItem['name']);
                $found    = false;
                $favAmount = trim((string)($favItem['amount'] ?? ''));
                if ($favAmount === '') {
                    $favAmount = '1';
                }
                foreach ($items as &$item) {
                    if ($item['inCart'] === false && mb_strtolower($item['name']) === $favLower) {
                        $item['amount'] = $this->AddAmounts((string)$item['amount'], $favAmount);
                        $found          = true;
                        $changed        = true;
                        break;
                    }
                }
                unset($item);
                if (!$found) {
                    $category = (string)($favItem['category'] ?? '');
                    if ($category === '') {
                        $category = $this->LookupCategory($favItem['name']);
                    }
                    $items[] = [
                        'id'       => $this->GenerateItemID(),
                        'name'     => $favItem['name'],
                        'category' => $category,
                        'amount'   => $favAmount,
                        'notes'    => (string)($favItem['notes'] ?? ''),
                        'inCart'   => false,
                        'addedAt'  => time(),
                    ];
                    $changed = true;
                }
            }
            if ($changed) {
                $this->SaveItems($items);
            }
        } finally {
            IPS_SemaphoreLeave($semaphoreKey);
        }
    }

    private function RenameFavoriteListInternal(string $listId, string $newName): bool
    {
        $newName = trim($newName);
        if ($newName === '' || $listId === '') {
            return false;
        }
        $lists = $this->LoadFavoriteLists();
        foreach ($lists as &$list) {
            if ($list['id'] === $listId) {
                $list['name'] = $newName;
                $this->SaveFavoriteLists($lists);
                $this->SendState();
                return true;
            }
        }
        return false;
    }

    private function DeleteFavoriteListInternal(string $listId): void
    {
        if ($listId === '') {
            return;
        }
        $lists = $this->LoadFavoriteLists();
        // Zugehöriges Rezeptfoto und das generierte Gerichtsbild mitlöschen.
        foreach ($lists as $l) {
            if (($l['id'] ?? '') === $listId) {
                $this->DeleteRecipePhoto((int)($l['mediaId'] ?? 0));
                $this->DeleteDishImage((int)($l['imageId'] ?? 0));
                break;
            }
        }
        $lists = array_values(array_filter($lists, fn($l) => $l['id'] !== $listId));
        $this->SaveFavoriteLists($lists);
        $this->SendState();
    }

    /** Löscht ein Rezeptfoto-Medienobjekt — nur wenn es unter einer „Rezeptfotos"-Kategorie liegt. */
    private function DeleteRecipePhoto(int $mediaId): void
    {
        if ($mediaId <= 0 || !IPS_MediaExists($mediaId)) {
            return;
        }
        $parent = IPS_GetParent($mediaId);
        if ($parent > 0 && IPS_CategoryExists($parent) && IPS_GetName($parent) === 'Rezeptfotos') {
            IPS_DeleteMedia($mediaId, true);
        }
    }

    private function UpdateFavoriteItemInternal(string $listId, string $oldName, string $newName, string $category, string $amount, string $notes = ''): bool
    {
        $oldName = trim($oldName);
        $newName = trim($newName);
        if ($oldName === '' || $newName === '' || $listId === '') {
            return false;
        }
        $lists        = $this->LoadFavoriteLists();
        $oldNameLower = mb_strtolower($oldName);
        foreach ($lists as &$list) {
            if ($list['id'] !== $listId) {
                continue;
            }
            foreach ($list['items'] as &$item) {
                if (mb_strtolower($item['name']) === $oldNameLower) {
                    $item['name']     = $newName;
                    $item['category'] = trim($category);
                    $item['amount']   = trim($amount);
                    $item['notes']    = trim($notes);
                    $this->SaveFavoriteLists($lists);
                    $this->SendState();
                    return true;
                }
            }
            unset($item);
            return false;
        }
        return false;
    }

    private function SyncFavoriteItemsFromConfig(): void
    {
        $listId = trim($this->GetBuffer('EditingFavListId'));
        if ($listId === '') {
            return;
        }
        $raw = $this->ReadPropertyString('FavoriteItemsConfig');
        $config = json_decode($raw, true);
        if (!is_array($config)) {
            return;
        }
        if (!$this->HasValidFavoriteItems($config)) {
            return;
        }
        // Save the property items to the correct favorite list
        $this->SaveFavoriteItems($listId, $raw);
    }

    public function SwitchFavoriteList(string $NewListId, string $CurrentItemsJson): void
    {
        $oldListId = $this->GetBuffer('EditingFavListId');
        if ($oldListId !== '' && $oldListId !== $NewListId) {
            $decoded = json_decode($CurrentItemsJson, true);
            if (is_array($decoded) && $this->HasValidFavoriteItems($decoded)) {
                $this->SaveFavoriteItems($oldListId, $CurrentItemsJson);
            }
        }
        $this->SetBuffer('EditingFavListId', $NewListId);
        $this->LoadFavoriteItems($NewListId);
    }

    private function HasValidFavoriteItems(array $items): bool
    {
        foreach ($items as $row) {
            $name = trim((string)($row['name'] ?? ''));
            if ($name !== '') {
                return true;
            }
        }
        return false;
    }

    private function SyncFavoriteListsFromConfig(): void
    {
        $raw    = $this->ReadPropertyString('FavoriteListsConfig');
        $config = json_decode($raw, true);
        // Skip if property is empty (initial state) to avoid wiping existing lists
        if (!is_array($config) || count($config) === 0) {
            return;
        }
        $current = $this->LoadFavoriteLists();
        $byId    = [];
        foreach ($current as $list) {
            $byId[$list['id']] = $list;
        }
        $newLists = [];
        foreach ($config as $row) {
            $id   = trim((string)($row['id'] ?? ''));
            $name = trim((string)($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            if ($id !== '' && isset($byId[$id])) {
                $entry         = $byId[$id];
                $entry['name'] = $name;
                $newLists[]    = $entry;
            } else {
                $newLists[] = ['id' => bin2hex(random_bytes(8)), 'name' => $name, 'items' => []];
            }
        }
        if ($newLists !== $current) {
            $this->SaveFavoriteLists($newLists);
            $this->SendState();
        }
    }

    private function AddAmounts(string $existing, string $favAmount): string
    {
        $fav = trim($favAmount);
        $cur = trim($existing);
        if ($fav === '') {
            return $cur;
        }
        if (!preg_match('/^(\d+)(x?)$/i', $fav, $favMatch)) {
            return $cur;
        }
        $favNum    = (int)$favMatch[1];
        $favSuffix = strtolower($favMatch[2]);
        if ($cur === '') {
            return (string)$favNum . $favSuffix;
        }
        if (!preg_match('/^(\d+)(x?)$/i', $cur, $curMatch)) {
            return $cur;
        }
        $curNum    = (int)$curMatch[1];
        $curSuffix = strtolower($curMatch[2]);
        $total     = $curNum + $favNum;
        $suffix    = $favSuffix ?: $curSuffix;
        return (string)$total . $suffix;
    }
}

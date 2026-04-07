<?php

declare(strict_types=1);

trait FavoriteStore
{
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

    private function CreateFavoriteListInternal(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }
        $lists   = $this->LoadFavoriteLists();
        $id      = bin2hex(random_bytes(8));
        $lists[] = ['id' => $id, 'name' => $name, 'items' => []];
        $this->SaveFavoriteLists($lists);
        $this->SendState();
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
        $lists = array_values(array_filter($lists, fn($l) => $l['id'] !== $listId));
        $this->SaveFavoriteLists($lists);
        $this->SendState();
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

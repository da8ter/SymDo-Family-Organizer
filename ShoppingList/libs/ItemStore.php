<?php

declare(strict_types=1);

trait ItemStore
{
    private function LoadItems(): array
    {
        $raw = $this->ReadAttributeString('Items');
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [];
        }
        $items = array_values(array_filter($data, fn($item) => is_array($item)));
        // Normalisierung: notes muss immer String sein — ein einzelnes null
        // (Altbestand eines Bugs) ließ das strikte App-Decoding der gesamten
        // Liste scheitern.
        foreach ($items as &$item) {
            $item['notes'] = (string)($item['notes'] ?? '');
        }
        unset($item);
        return $items;
    }

    private function SaveItems(array $Items): void
    {
        $this->WriteAttributeString('Items', json_encode(array_values($Items), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->UpdateCounts($Items);
        $this->SendState();
    }

    private function UpdateCounts(array $Items): void
    {
        $itemCount = 0;
        $cartCount = 0;
        foreach ($Items as $item) {
            if ($item['inCart'] === true) {
                $cartCount++;
            } else {
                $itemCount++;
            }
        }
        $this->SetValue('ItemCount', $itemCount);
        $this->SetValue('LastUsed', $cartCount);
    }

    private function SendState(): void
    {
        $this->BumpAppRevision();
        $this->PushCurrentState();
    }

    private function PushCurrentState(): void
    {
        $this->UpdateVisualizationValue(
            json_encode($this->BuildStatePayload(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    private function GenerateItemID(): string
    {
        return bin2hex(random_bytes(8));
    }

    private function IncrementAmount(string $Amount): string
    {
        $amount = trim($Amount);
        if ($amount === '') {
            return '2';
        }
        if (preg_match('/^\d+$/', $amount)) {
            return (string)((int)$amount + 1);
        }
        if (preg_match('/^(\d+)(x)$/i', $amount, $matches)) {
            return (string)((int)$matches[1] + 1) . $matches[2];
        }
        return $amount;
    }

    private function AddItemInternal(string $Name, string $Category, string $Amount): bool
    {
        $name = trim($Name);
        if ($name === '') {
            $this->LogMessage($this->Translate('Invalid item name'), KL_WARNING);
            return false;
        }
        $category = trim($Category);
        if ($category === '') {
            $category = $this->LookupCategory($name);
        }
        $amount = trim($Amount);

        $semaphoreKey = 'SL_Items_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($semaphoreKey, 500)) {
            $this->SendDebug('ItemStore', 'Semaphore timeout on AddItem', 0);
            $this->LogMessage($this->Translate('Item operation skipped (concurrent access)'), KL_WARNING);
            return false;
        }
        try {
            $items = $this->LoadItems();
            $nameLower = mb_strtolower($name);
            foreach ($items as &$item) {
                if ($item['inCart'] === false && mb_strtolower($item['name']) === $nameLower) {
                    $item['amount'] = $this->IncrementAmount($item['amount']);
                    $this->SaveItems($items);
                    return true;
                }
            }
            unset($item);
            $items[] = [
                'id'       => $this->GenerateItemID(),
                'name'     => $name,
                'category' => $category,
                'amount'   => $amount,
                'notes'    => '',
                'inCart'   => false,
                'addedAt'  => time(),
            ];
            $this->SaveItems($items);
            return true;
        } finally {
            IPS_SemaphoreLeave($semaphoreKey);
        }
    }

    private function AddScannedItemInternal(string $Name, string $Category, string $Amount, string $Price, string $ListingId = '', string $ImageUrl = '', string $Notes = ''): bool
    {
        $name = trim($Name);
        if ($name === '') {
            return false;
        }
        $category = trim($Category);
        if ($category === '') {
            $category = $this->LookupCategory($name);
        }
        $amount    = trim($Amount);
        $price     = trim($Price);
        $listingId = trim($ListingId);
        $imageUrl  = trim($ImageUrl);
        $notes     = trim($Notes);

        $semaphoreKey = 'SL_Items_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($semaphoreKey, 500)) {
            $this->SendDebug('ItemStore', 'Semaphore timeout on AddScannedItem', 0);
            return false;
        }
        try {
            $items = $this->LoadItems();
            $nameLower = mb_strtolower($name);
            foreach ($items as &$item) {
                if ($item['inCart'] === false && mb_strtolower($item['name']) === $nameLower) {
                    $item['amount'] = $this->IncrementAmount($item['amount']);
                    if ($price !== '') {
                        $item['price'] = $price;
                    }
                    // Backfill category on duplicate if the stored one is empty
                    if ((trim((string)($item['category'] ?? '')) === '') && $category !== '') {
                        $item['category'] = $category;
                    }
                    if ($listingId !== '') {
                        $item['listingId'] = $listingId;
                        $item['marketItem'] = true;
                    }
                    if ($imageUrl !== '' && empty($item['imageUrl'])) {
                        $item['imageUrl'] = $imageUrl;
                    }
                    if ($notes !== '' && trim((string)($item['notes'] ?? '')) === '') {
                        $item['notes'] = $notes;
                    }
                    $this->SaveItems($items);
                    return true;
                }
            }
            unset($item);
            $newItem = [
                'id'       => $this->GenerateItemID(),
                'name'     => $name,
                'category' => $category,
                'amount'   => $amount,
                'notes'    => $notes,
                'price'    => $price,
                'imageUrl' => $imageUrl,
                'inCart'   => false,
                'addedAt'  => time(),
            ];
            if ($listingId !== '') {
                $newItem['listingId']  = $listingId;
                $newItem['marketItem'] = true;
            }
            $items[] = $newItem;
            $this->SaveItems($items);
            $this->TrackFrequency($name, $category);
            return true;
        } finally {
            IPS_SemaphoreLeave($semaphoreKey);
        }
    }

    private function RemoveItemInternal(string $Name): bool
    {
        $name = trim($Name);
        if ($name === '') {
            return false;
        }
        $semaphoreKey = 'SL_Items_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($semaphoreKey, 500)) {
            $this->SendDebug('ItemStore', 'Semaphore timeout on RemoveItem', 0);
            $this->LogMessage($this->Translate('Item operation skipped (concurrent access)'), KL_WARNING);
            return false;
        }
        try {
            $items = $this->LoadItems();
            $nameLower = mb_strtolower($name);
            foreach ($items as $index => $item) {
                if ($item['inCart'] === false && mb_strtolower($item['name']) === $nameLower) {
                    array_splice($items, $index, 1);
                    $this->SaveItems($items);
                    return true;
                }
            }
            return false;
        } finally {
            IPS_SemaphoreLeave($semaphoreKey);
        }
    }

    private function DeleteItemInternal(string $Id): bool
    {
        $id = trim($Id);
        if ($id === '') {
            return false;
        }
        $semaphoreKey = 'SL_Items_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($semaphoreKey, 500)) {
            $this->SendDebug('ItemStore', 'Semaphore timeout on DeleteItem', 0);
            $this->LogMessage($this->Translate('Item operation skipped (concurrent access)'), KL_WARNING);
            return false;
        }
        try {
            $items = $this->LoadItems();
            $nameLower = null;
            foreach ($items as $item) {
                if ((string)($item['id'] ?? '') === $id) {
                    $nameLower = mb_strtolower(trim((string)($item['name'] ?? '')));
                    break;
                }
            }
            if ($nameLower === null) {
                return false;
            }
            // Remove the article from the active list AND from the recently used
            // (cart) list: drop every record sharing the same name.
            $filtered = array_values(array_filter(
                $items,
                fn($item) => mb_strtolower(trim((string)($item['name'] ?? ''))) !== $nameLower
            ));
            if (count($filtered) === count($items)) {
                return false;
            }
            $this->SaveItems($filtered);
            return true;
        } finally {
            IPS_SemaphoreLeave($semaphoreKey);
        }
    }

    private function ClearCartInternal(): void
    {
        $semaphoreKey = 'SL_Items_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($semaphoreKey, 500)) {
            $this->SendDebug('ItemStore', 'Semaphore timeout on ClearCart', 0);
            $this->LogMessage($this->Translate('Item operation skipped (concurrent access)'), KL_WARNING);
            return;
        }
        try {
            $items = $this->LoadItems();
            $filtered = array_values(array_filter($items, fn($item) => $item['inCart'] !== true));
            $this->SaveItems($filtered);
        } finally {
            IPS_SemaphoreLeave($semaphoreKey);
        }
    }

    private function HasCartDuplicate(array $items, array $candidate): bool
    {
        $name   = mb_strtolower(trim($candidate['name'] ?? ''));
        $amount = trim($candidate['amount'] ?? '');
        $notes  = trim($candidate['notes'] ?? '');
        foreach ($items as $item) {
            if (($item['inCart'] ?? false) !== true) {
                continue;
            }
            if (mb_strtolower(trim($item['name'] ?? '')) === $name
                && trim($item['amount'] ?? '') === $amount
                && trim($item['notes'] ?? '') === $notes
            ) {
                return true;
            }
        }
        return false;
    }

    private function MarkAllDoneInternal(): void
    {
        $semaphoreKey = 'SL_Items_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($semaphoreKey, 500)) {
            $this->SendDebug('ItemStore', 'Semaphore timeout on MarkAllDone', 0);
            $this->LogMessage($this->Translate('Item operation skipped (concurrent access)'), KL_WARNING);
            return;
        }
        try {
            $items = $this->LoadItems();
            $changed = false;
            $remove = [];
            // Käufe sammeln und EINMAL nach der Schleife wegschreiben — je Artikel
            // zu schreiben träfe das Attribut hier dutzendfach.
            $purchased = [];
            foreach ($items as $i => &$item) {
                if (($item['inCart'] ?? false) === true) {
                    continue;
                }
                if (!isset($item['boughtAt'])) {
                    $purchased[] = $item;
                }
                if ($this->HasCartDuplicate($items, $item)) {
                    $remove[] = $i;
                } else {
                    $item['inCart'] = true;
                    $item['boughtAt'] = time();
                }
                $changed = true;
            }
            unset($item);
            $this->TrackPurchases($purchased);
            if ($remove) {
                foreach (array_reverse($remove) as $i) {
                    array_splice($items, $i, 1);
                }
            }
            if ($changed) {
                $this->SaveItems($items);
            }
        } finally {
            IPS_SemaphoreLeave($semaphoreKey);
        }
    }

    private function ToggleItemCart(string $Id, ?bool $Target = null): bool
    {
        $semaphoreKey = 'SL_Items_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($semaphoreKey, 500)) {
            $this->SendDebug('ItemStore', 'Semaphore timeout on ToggleItemCart', 0);
            $this->LogMessage($this->Translate('Item operation skipped (concurrent access)'), KL_WARNING);
            return false;
        }
        try {
            $items = $this->LoadItems();
            $idx = null;
            foreach ($items as $i => $item) {
                if ($item['id'] === $Id) {
                    $idx = $i;
                    break;
                }
            }
            if ($idx === null) {
                return false;
            }
            $current = (bool)$items[$idx]['inCart'];
            $new = $Target ?? !$current;
            if ($new === $current) {
                // Idempotent no-op: a replayed request with explicit target must
                // not flip the state back (or delete via the duplicate branch).
                return true;
            }
            // Kauf verbuchen, bevor die Zeile ggf. verschwindet. Die Markierung
            // boughtAt sitzt auf der ZEILE, nicht auf dem Übergang: „zurück auf die
            // Liste" ist ein normaler Arbeitsablauf, kein Fehlklick — ohne die
            // Markierung zählte jedes Aus/An erneut. Die Zeile stirbt beim Leeren des
            // Wagens, der nächste Einkauf ist dann eine neue Zeile.
            $purchased = [];
            if ($new && !isset($items[$idx]['boughtAt'])) {
                $purchased[] = $items[$idx];
            }
            if ($new && $this->HasCartDuplicate($items, $items[$idx])) {
                array_splice($items, $idx, 1);
            } else {
                $items[$idx]['inCart'] = $new;
                if ($new) {
                    $items[$idx]['boughtAt'] = time();
                }
            }
            $this->TrackPurchases($purchased);
            $this->SaveItems($items);
            return true;
        } finally {
            IPS_SemaphoreLeave($semaphoreKey);
        }
    }

    private function UpdateItemInternal(string $Id, string $Name, string $Amount, string $Notes, string $Category = ''): bool
    {
        $name = trim($Name);
        if ($name === '') {
            $this->LogMessage($this->Translate('Invalid item name'), KL_WARNING);
            return false;
        }
        $semaphoreKey = 'SL_Items_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($semaphoreKey, 500)) {
            $this->SendDebug('ItemStore', 'Semaphore timeout on UpdateItem', 0);
            $this->LogMessage($this->Translate('Item operation skipped (concurrent access)'), KL_WARNING);
            return false;
        }
        try {
            $items = $this->LoadItems();
            foreach ($items as &$item) {
                if ($item['id'] === $Id) {
                    $item['name']   = $name;
                    $item['amount'] = trim($Amount);
                    $item['notes']  = trim($Notes);
                    $cat = trim($Category);
                    if ($cat !== '') {
                        if (!isset($item['category']) || $item['category'] !== $cat) {
                            $this->SaveCategoryOverride($item['name'], $cat);
                        }
                        $item['category'] = $cat;
                    }
                    $this->SaveItems($items);
                    return true;
                }
            }
            return false;
        } finally {
            IPS_SemaphoreLeave($semaphoreKey);
        }
    }
}

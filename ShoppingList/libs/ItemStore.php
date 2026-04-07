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
        return array_values(array_filter($data, fn($item) => is_array($item)));
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
            foreach ($items as &$item) {
                if (($item['inCart'] ?? false) !== true) {
                    $item['inCart'] = true;
                    $changed = true;
                }
            }
            unset($item);
            if ($changed) {
                $this->SaveItems($items);
            }
        } finally {
            IPS_SemaphoreLeave($semaphoreKey);
        }
    }

    private function ToggleItemCart(string $Id): bool
    {
        $semaphoreKey = 'SL_Items_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($semaphoreKey, 500)) {
            $this->SendDebug('ItemStore', 'Semaphore timeout on ToggleItemCart', 0);
            $this->LogMessage($this->Translate('Item operation skipped (concurrent access)'), KL_WARNING);
            return false;
        }
        try {
            $items = $this->LoadItems();
            foreach ($items as &$item) {
                if ($item['id'] === $Id) {
                    $item['inCart'] = !$item['inCart'];
                    $this->SaveItems($items);
                    return true;
                }
            }
            return false;
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

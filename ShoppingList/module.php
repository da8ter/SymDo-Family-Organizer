<?php

declare(strict_types=1);

require_once __DIR__ . '/libs/ItemStore.php';
require_once __DIR__ . '/libs/SuggestionEngine.php';
require_once __DIR__ . '/libs/FavoriteStore.php';

class ShoppingList extends IPSModuleStrict
{
    use ItemStore;
    use SuggestionEngine;
    use FavoriteStore;

    public function Create(): void
    {
        parent::Create();
        $this->SetVisualizationType(1);
        $this->RegisterHook('shoppinglist/assets');
        $this->RegisterAttributeString('Items', '[]');
        $this->RegisterAttributeString('Frequencies', '{}');
        $this->RegisterAttributeString('CategoryOverrides', '{}');
        $this->RegisterAttributeString('ItemHistory', '{}');
        $this->RegisterAttributeString('SuggestionItems', '[]');
        $this->RegisterAttributeString('FavoriteLists', '[]');
        $this->RegisterAttributeString('PreviousCategoryOrder', '[]');
        $this->RegisterAttributeString('WebHookToken', '');
        $this->RegisterPropertyString('FavoriteListsConfig', '[]');
        $this->RegisterPropertyString('SuggestionItemsConfig', '[]');
        $this->RegisterPropertyBoolean('ShowProductImages', true);
        $this->RegisterPropertyString('FavoriteItemsConfig', '[]');
        $this->RegisterPropertyString('CategoryOrder', json_encode([
            'Obst & Gemüse',
            'Milch & Käse',
            'Backwaren',
            'Fleisch & Wurst',
            'Tiefkühl',
            'Getränke',
            'Snacks & Süßes',
            'Konserven & Trocken',
            'Hygiene & Pflege',
            'Haushalt & Reinigung',
            'Baby & Tier',
            'Sonstiges',
        ], JSON_UNESCAPED_UNICODE));

        $this->RegisterVariableInteger('ItemCount', $this->Translate('Item Count'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Groceries',
            'SUFFIX'       => '',
        ], 200);

        $this->RegisterVariableInteger('LastUsed', $this->Translate('Last Used'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Cart',
            'SUFFIX'       => '',
        ], 210);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }

        // Generate webhook token once
        if ($this->ReadAttributeString('WebHookToken') === '') {
            $this->WriteAttributeString('WebHookToken', bin2hex(random_bytes(16)));
        }

        // Detect category renames and propagate to items
        $this->SyncCategoryRenames();

        // Sync favorite lists from config form (user-applied changes)
        $this->SyncFavoriteListsFromConfig();

        // Sync favorite items from config form
        $this->SyncFavoriteItemsFromConfig();

        // Sync suggestions from config form
        $this->SyncSuggestionsFromConfig();

        // Sync counts and push updated state to tile
        $this->UpdateCounts($this->LoadItems());
        $this->SendState();
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
        }
    }

    public function Destroy(): void
    {
        parent::Destroy();
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'GetState':
                $this->SendState();
                return;
            case 'AddItem':
                $data = json_decode((string)$Value, true);
                if (is_array($data) && isset($data['name'])) {
                    $this->AddItem(
                        (string)($data['name'] ?? ''),
                        (string)($data['category'] ?? ''),
                        (string)($data['amount'] ?? '')
                    );
                }
                return;
            case 'ToggleCart':
                $this->ToggleItemCart((string)$Value);
                return;
            case 'RemoveItem':
                $this->RemoveItem((string)$Value);
                return;
            case 'ClearCart':
                $this->ClearCart();
                return;
            case 'MarkAllDone':
                $this->MarkAllDoneInternal();
                return;
            case 'UpdateItem':
                $data = json_decode((string)$Value, true);
                if (is_array($data) && isset($data['id'])) {
                    $this->UpdateItemInternal(
                        (string)($data['id'] ?? ''),
                        (string)($data['name'] ?? ''),
                        (string)($data['amount'] ?? ''),
                        (string)($data['notes'] ?? ''),
                        (string)($data['category'] ?? '')
                    );
                }
                return;
            case 'CreateFavoriteList':
                $data = json_decode((string)$Value, true);
                if (is_array($data) && isset($data['name'])) {
                    $this->CreateFavoriteListInternal((string)($data['name'] ?? ''));
                }
                return;
            case 'AddItemToFavoriteList':
                $data = json_decode((string)$Value, true);
                if (is_array($data) && isset($data['listId'], $data['name'])) {
                    $this->AddItemToFavoriteListInternal(
                        (string)($data['listId'] ?? ''),
                        (string)($data['name'] ?? ''),
                        (string)($data['category'] ?? ''),
                        (string)($data['amount'] ?? ''),
                        (string)($data['notes'] ?? '')
                    );
                }
                return;
            case 'RemoveItemFromFavoriteList':
                $data = json_decode((string)$Value, true);
                if (is_array($data) && isset($data['listId'], $data['name'])) {
                    $this->RemoveItemFromFavoriteListInternal(
                        (string)($data['listId'] ?? ''),
                        (string)($data['name'] ?? '')
                    );
                }
                return;
            case 'AddFavoriteListToCart':
                $this->AddFavoriteListToCartInternal((string)$Value);
                return;
            case 'RenameFavoriteList':
                $data = json_decode((string)$Value, true);
                if (is_array($data) && isset($data['listId'], $data['newName'])) {
                    $this->RenameFavoriteListInternal(
                        (string)($data['listId'] ?? ''),
                        (string)($data['newName'] ?? '')
                    );
                }
                return;
            case 'DeleteFavoriteList':
                $this->DeleteFavoriteListInternal((string)$Value);
                return;
            case 'UpdateFavoriteItem':
                $data = json_decode((string)$Value, true);
                if (is_array($data) && isset($data['listId'], $data['oldName'], $data['newName'])) {
                    $this->UpdateFavoriteItemInternal(
                        (string)($data['listId'] ?? ''),
                        (string)($data['oldName'] ?? ''),
                        (string)($data['newName'] ?? ''),
                        (string)($data['category'] ?? ''),
                        (string)($data['amount'] ?? ''),
                        (string)($data['notes'] ?? '')
                    );
                }
                return;
            default:
                throw new \Exception($this->Translate('Invalid Ident'));
        }
    }

    public function SaveSuggestionItemsFromForm(string $ItemsJson): void
    {
        $newItems = json_decode($ItemsJson, true);
        if (!is_array($newItems)) {
            return;
        }
        $clean = [];
        foreach ($newItems as $row) {
            $name = trim((string)($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $clean[] = [
                'name'     => $name,
                'category' => trim((string)($row['category'] ?? '')),
            ];
        }
        $this->SaveSuggestionItems($clean);
        $this->SendState();
    }

    public function LoadFavoriteItems(string $ListId): void
    {
        $this->SetBuffer('EditingFavListId', $ListId);
        if ($ListId === '') {
            $this->UpdateFormField('FavoriteItemsConfig', 'values', '[]');
            return;
        }
        $lists = $this->LoadFavoriteLists();
        $items = [];
        foreach ($lists as $list) {
            if ($list['id'] === $ListId) {
                foreach ($list['items'] as $item) {
                    $items[] = [
                        'name'     => $item['name'] ?? '',
                        'category' => $item['category'] ?? '',
                        'amount'   => $item['amount'] ?? '',
                        'notes'    => $item['notes'] ?? '',
                    ];
                }
                break;
            }
        }
        $this->UpdateFormField('FavoriteItemsConfig', 'values', json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function SaveFavoriteItems(string $ListId, string $ItemsJson): void
    {
        if ($ListId === '') {
            return;
        }
        $newItems = json_decode($ItemsJson, true);
        if (!is_array($newItems)) {
            return;
        }
        $lists   = $this->LoadFavoriteLists();
        $changed = false;
        foreach ($lists as &$list) {
            if ($list['id'] !== $ListId) {
                continue;
            }
            $list['items'] = [];
            foreach ($newItems as $row) {
                $name = trim((string)($row['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $list['items'][] = [
                    'name'     => $name,
                    'category' => trim((string)($row['category'] ?? '')),
                    'amount'   => trim((string)($row['amount'] ?? '')),
                    'notes'    => trim((string)($row['notes'] ?? '')),
                ];
            }
            $changed = true;
            break;
        }
        unset($list);
        if ($changed) {
            $this->SaveFavoriteLists($lists);
            $this->SendState();
        }
    }

    public function GetVisualizationTile(): string
    {
        $path = __DIR__ . '/module.html';
        $html = @file_get_contents($path);
        if (!is_string($html)) {
            $exists   = file_exists($path) ? 'yes' : 'no';
            $readable = is_readable($path) ? 'yes' : 'no';
            $size     = file_exists($path) ? (string)@filesize($path) : 'n/a';
            $err      = error_get_last();
            $errMsg   = is_array($err) && isset($err['message']) ? (string)$err['message'] : '';
            IPS_LogMessage('ShoppingList', 'GetVisualizationTile: module.html could not be loaded. path=' . $path . ' exists=' . $exists . ' readable=' . $readable . ' size=' . $size . ' err=' . $errMsg);
            return '';
        }
        if (strlen($html) < 200) {
            IPS_LogMessage('ShoppingList', 'GetVisualizationTile: module.html loaded but is very short. bytes=' . strlen($html) . ' head=' . substr($html, 0, 80));
        }
        $hookUrl = '/hook/shoppinglist/assets/?t=' . urlencode($this->ReadAttributeString('WebHookToken')) . '&f=';
        return $html . '<script>window.__imageHookUrl=' . json_encode($hookUrl) . ';</script>';
    }

    public function AddItem(string $Name, string $Category, string $Amount): bool
    {
        $result = $this->AddItemInternal($Name, $Category, $Amount);
        if ($result) {
            $this->TrackFrequency($Name, $Category);
        }
        return $result;
    }

    public function RemoveItem(string $Name): bool
    {
        return $this->RemoveItemInternal($Name);
    }

    public function ClearCart(): void
    {
        $this->ClearCartInternal();
    }

    public function GetItems(): string
    {
        return json_encode($this->LoadItems(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function GetCategoryOrderFlat(): array
    {
        $raw     = $this->ReadPropertyString('CategoryOrder');
        $decoded = json_decode($raw, true);
        $result  = [];
        if (is_array($decoded)) {
            foreach ($decoded as $entry) {
                if (is_string($entry)) {
                    $result[] = $entry;
                } elseif (is_array($entry) && isset($entry['category'])) {
                    $result[] = $entry['category'];
                }
            }
        }
        return $result;
    }

    private function SyncCategoryRenames(): void
    {
        $newOrder = $this->GetCategoryOrderFlat();
        $raw      = $this->ReadAttributeString('PreviousCategoryOrder');
        $oldOrder = json_decode($raw, true);
        if (!is_array($oldOrder) || count($oldOrder) === 0) {
            $this->WriteAttributeString('PreviousCategoryOrder', json_encode($newOrder, JSON_UNESCAPED_UNICODE));
            return;
        }
        $removed = array_values(array_diff($oldOrder, $newOrder));
        $added   = array_values(array_diff($newOrder, $oldOrder));
        // Match renames by position: old name gone → check what's now at that position
        $renames = [];
        foreach ($removed as $oldName) {
            $oldPos = array_search($oldName, $oldOrder, true);
            if ($oldPos !== false && isset($newOrder[$oldPos]) && in_array($newOrder[$oldPos], $added, true)) {
                $renames[$oldName] = $newOrder[$oldPos];
            }
        }
        if (!empty($renames)) {
            $items   = $this->LoadItems();
            $changed = false;
            foreach ($items as &$item) {
                if (isset($renames[$item['category']])) {
                    $item['category'] = $renames[$item['category']];
                    $changed = true;
                }
            }
            unset($item);
            if ($changed) {
                $this->SaveItems($items);
            }
            // Update category overrides
            $ovRaw     = $this->ReadAttributeString('CategoryOverrides');
            $overrides = json_decode($ovRaw, true);
            if (is_array($overrides)) {
                $ovChanged = false;
                foreach ($overrides as $key => $cat) {
                    if (isset($renames[$cat])) {
                        $overrides[$key] = $renames[$cat];
                        $ovChanged = true;
                    }
                }
                if ($ovChanged) {
                    $this->WriteAttributeString('CategoryOverrides', json_encode($overrides, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                }
            }
            // Update suggestion items
            $suggestions = $this->LoadSuggestionItems();
            $sugChanged  = false;
            foreach ($suggestions as &$sug) {
                if (isset($renames[$sug['category']])) {
                    $sug['category'] = $renames[$sug['category']];
                    $sugChanged = true;
                }
            }
            unset($sug);
            if ($sugChanged) {
                $this->SaveSuggestionItems($suggestions);
            }
        }
        $this->WriteAttributeString('PreviousCategoryOrder', json_encode($newOrder, JSON_UNESCAPED_UNICODE));
    }

    protected function BuildStatePayload(): array
    {
        return [
            'type'            => 'state',
            'items'           => $this->LoadItems(),
            'suggestions'     => $this->BuildSuggestionsPayload(),
            'categoryOrder'   => $this->GetCategoryOrderFlat(),
            'favoriteLists'   => $this->LoadFavoriteLists(),
            'availableImages' => $this->ReadPropertyBoolean('ShowProductImages') ? $this->GetAvailableProductImages() : [],
        ];
    }

    private function GetAvailableProductImages(): array
    {
        $dir = __DIR__ . '/assets';
        if (!is_dir($dir)) {
            return [];
        }

        // Helper: normalize NFD → NFC (macOS uses NFD for filenames)
        $toNFC = function (string $s): string {
            if (class_exists('Normalizer')) {
                return \Normalizer::normalize($s, \Normalizer::FORM_C) ?: $s;
            }
            // Fallback: common German decomposed chars (a/o/u + combining diaeresis U+0308)
            return strtr($s, [
                "a\xCC\x88" => 'ä', "o\xCC\x88" => 'ö', "u\xCC\x88" => 'ü',
                "A\xCC\x88" => 'Ä', "O\xCC\x88" => 'Ö', "U\xCC\x88" => 'Ü',
                "s\xCC\xA7" => 'ß',
            ]);
        };

        // 1. Scan image files
        $exts      = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'];
        $imageFiles = []; // baseName (NFC) => filename (original)
        foreach (scandir($dir) as $file) {
            if ($file[0] === '.') {
                continue;
            }
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (!in_array($ext, $exts)) {
                continue;
            }
            $imageFiles[$toNFC(mb_strtolower(pathinfo($file, PATHINFO_FILENAME)))] = $file;
        }

        // Build result map: productName (lowercase) => filename
        $images = [];
        foreach ($imageFiles as $baseName => $file) {
            // Exact match
            $images[$baseName] = $file;
            // Add normalized variants of the base name
            foreach ($this->NormalizeProductName($baseName) as $variant) {
                if (!isset($images[$variant])) {
                    $images[$variant] = $file;
                }
            }
        }

        // 2. Load alias mapping
        $aliasPath = $dir . '/image-aliases.json';
        if (file_exists($aliasPath)) {
            $aliasData = json_decode((string)file_get_contents($aliasPath), true);
            if (is_array($aliasData)) {
                foreach ($aliasData as $baseName => $aliases) {
                    $baseName = mb_strtolower(trim((string)$baseName));
                    if (!isset($imageFiles[$baseName]) || !is_array($aliases)) {
                        continue;
                    }
                    $file = $imageFiles[$baseName];
                    foreach ($aliases as $alias) {
                        $key = mb_strtolower(trim((string)$alias));
                        if ($key !== '' && !isset($images[$key])) {
                            $images[$key] = $file;
                        }
                        // Also add normalized variants of each alias
                        foreach ($this->NormalizeProductName($key) as $variant) {
                            if (!isset($images[$variant])) {
                                $images[$variant] = $file;
                            }
                        }
                    }
                }
            }
        }

        return $images;
    }

    private function NormalizeProductName(string $name): array
    {
        $name = mb_strtolower(trim($name));
        if ($name === '') {
            return [];
        }

        $variants = [];

        // Umlaut replacements (both directions)
        $umlautMap = ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss'];
        $withoutUmlauts = strtr($name, $umlautMap);
        if ($withoutUmlauts !== $name) {
            $variants[] = $withoutUmlauts;
        }
        $reverseMap = array_flip($umlautMap);
        $withUmlauts = strtr($name, $reverseMap);
        if ($withUmlauts !== $name) {
            $variants[] = $withUmlauts;
        }

        // German plural suffixes to strip (order matters: longest first)
        $suffixes = ['nen', 'en', 'er', 'es', 'se', 'n', 'e', 's'];
        foreach ($suffixes as $suffix) {
            $len = mb_strlen($suffix);
            if (mb_strlen($name) > $len + 2 && mb_substr($name, -$len) === $suffix) {
                $stem = mb_substr($name, 0, -$len);
                $variants[] = $stem;
                // Also add umlaut variants of the stem
                $stemNoUmlaut = strtr($stem, $umlautMap);
                if ($stemNoUmlaut !== $stem) {
                    $variants[] = $stemNoUmlaut;
                }
                $stemWithUmlaut = strtr($stem, $reverseMap);
                if ($stemWithUmlaut !== $stem) {
                    $variants[] = $stemWithUmlaut;
                }
                break; // only strip one suffix
            }
        }

        // Umlaut variants of the plural-stripped forms
        foreach ($variants as $v) {
            $alt = strtr($v, $umlautMap);
            if ($alt !== $v && !in_array($alt, $variants)) {
                $variants[] = $alt;
            }
        }

        return array_unique(array_filter($variants, fn($v) => $v !== '' && $v !== $name));
    }

    protected function ProcessHookData(): void
    {
        // Validate token
        $token = $_GET['t'] ?? '';
        if ($token === '' || !hash_equals($this->ReadAttributeString('WebHookToken'), $token)) {
            http_response_code(403);
            return;
        }

        $file = basename(urldecode($_GET['f'] ?? ''));
        $path     = __DIR__ . '/assets/' . $file;

        if ($file === '' || !file_exists($path)) {
            http_response_code(404);
            return;
        }

        $mimeMap = [
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'svg'  => 'image/svg+xml',
        ];
        $ext  = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mime = $mimeMap[$ext] ?? 'application/octet-stream';

        header('Content-Type: ' . $mime);
        header('Cache-Control: public, max-age=86400');
        readfile($path);
    }


    private function BuildSuggestionsPayload(): array
    {
        $raw   = $this->ReadAttributeString('Frequencies');
        $freqs = json_decode($raw, true);
        if (!is_array($freqs)) {
            $freqs = [];
        }

        $base     = $this->GetBaseSuggestions();
        $ranked   = [];
        $unranked = [];

        foreach ($base as $name => $category) {
            $freqKey = mb_strtolower(trim($name));
            $freq    = (int)($freqs[$freqKey] ?? 0);
            $entry   = ['name' => $name, 'category' => $category, 'frequency' => $freq];
            if ($freq > 0) {
                $ranked[] = $entry;
            } else {
                $unranked[] = $entry;
            }
        }

        usort($ranked, fn($a, $b) => $b['frequency'] <=> $a['frequency']);

        return array_merge($ranked, $unranked);
    }

    public function GetConfigurationForm(): string
    {
        // Build the CategoryOrder list element with current property values.
        // See Phase 2 RESEARCH.md Finding 1 for changeOrder: true confirmation.
        $raw     = $this->ReadPropertyString('CategoryOrder');
        $decoded = json_decode($raw, true);

        // Defensive fallback: if property is missing or malformed, use aisle-order default.
        if (!is_array($decoded) || count($decoded) === 0) {
            $decoded = [
                'Obst & Gemüse', 'Milch & Käse', 'Backwaren', 'Fleisch & Wurst',
                'Tiefkühl', 'Getränke', 'Snacks & Süßes', 'Konserven & Trocken',
                'Hygiene & Pflege', 'Haushalt & Reinigung', 'Baby & Tier', 'Sonstiges',
            ];
        }

        // Symcon saves List rows as [{"category": "..."}, ...]; support both flat strings
        // (default from Create()) and row objects (written back by Symcon after form save).
        $categoryValues = [];
        foreach ($decoded as $entry) {
            if (is_string($entry)) {
                $categoryValues[] = ['category' => $entry];
            } elseif (is_array($entry) && isset($entry['category'])) {
                $categoryValues[] = ['category' => $entry['category']];
            }
        }

        // Build category select options for suggestion items editor
        $categorySelectOptions = [];
        foreach ($categoryValues as $cv) {
            $categorySelectOptions[] = ['caption' => $cv['category'], 'value' => $cv['category']];
        }

        // Build suggestion items values from attribute
        $suggestionValues = [];
        foreach ($this->LoadSuggestionItems() as $item) {
            $suggestionValues[] = [
                'name'     => $item['name'] ?? '',
                'category' => $item['category'] ?? '',
            ];
        }

        $favoriteLists = $this->LoadFavoriteLists();

        // Build favorite lists values from attribute (always reflects current state)
        $favValues = [];
        foreach ($favoriteLists as $list) {
            $favValues[] = [
                'id'    => $list['id'],
                'name'  => $list['name'],
                'items' => count($list['items'] ?? []),
            ];
        }

        // Build select options for favorite list picker (no placeholder; first list selected by default)
        $favSelectOptions = [];
        foreach ($favoriteLists as $list) {
            $favSelectOptions[] = ['caption' => $list['name'], 'value' => $list['id']];
        }
        if (count($favSelectOptions) === 0) {
            $favSelectOptions[] = ['caption' => $this->Translate('No lists available'), 'value' => ''];
        }

        // Pre-load favorite items for selected list (fallback to first list)
        $selectedListId = trim($this->GetBuffer('EditingFavListId'));
        if ($selectedListId === '' && count($favoriteLists) > 0) {
            $selectedListId = (string)($favoriteLists[0]['id'] ?? '');
            $this->SetBuffer('EditingFavListId', $selectedListId);
        }
        $favItemValues = [];
        if ($selectedListId !== '') {
            foreach ($favoriteLists as $list) {
                if ($list['id'] === $selectedListId) {
                    foreach ($list['items'] as $item) {
                        $favItemValues[] = [
                            'name'     => $item['name'] ?? '',
                            'category' => $item['category'] ?? '',
                            'amount'   => $item['amount'] ?? '',
                            'notes'    => $item['notes'] ?? '',
                        ];
                    }
                    break;
                }
            }
        }

        $form = [
            'elements' => [
                [
                    'type'    => 'Label',
                    'caption' => $this->Translate('Shopping List Configuration'),
                ],
                [
                    'type'        => 'List',
                    'name'        => 'CategoryOrder',
                    'caption'     => $this->Translate('Category Order'),
                    'rowCount'    => 12,
                    'add'         => true,
                    'delete'      => true,
                    'changeOrder' => true,
                    'columns'     => [
                        [
                            'caption' => $this->Translate('Category'),
                            'name'    => 'category',
                            'width'   => 'auto',
                            'save'    => true,
                            'add'     => '',
                            'edit'    => ['type' => 'ValidationTextBox'],
                        ],
                    ],
                    'values' => $categoryValues,
                ],
                [
                    'type'    => 'CheckBox',
                    'name'    => 'ShowProductImages',
                    'caption' => $this->Translate('Show product images'),
                ],
                [
                    'type'    => 'Label',
                    'caption' => $this->Translate('Search suggestions'),
                ],
                [
                    'type'                        => 'List',
                    'name'                        => 'SuggestionItemsConfig',
                    'caption'                     => '',
                    'rowCount'                    => 15,
                    'add'                         => true,
                    'delete'                      => true,
                    'changeOrder'                 => false,
                    'loadValuesFromConfiguration' => false,
                    'columns'                     => [
                        [
                            'caption' => $this->Translate('Name'),
                            'name'    => 'name',
                            'width'   => 'auto',
                            'save'    => true,
                            'add'     => '',
                            'edit'    => ['type' => 'ValidationTextBox'],
                        ],
                        [
                            'caption' => $this->Translate('Category'),
                            'name'    => 'category',
                            'width'   => '250px',
                            'save'    => true,
                            'add'     => $this->Translate('Miscellaneous'),
                            'edit'    => ['type' => 'Select', 'options' => $categorySelectOptions],
                        ],
                    ],
                    'values' => $suggestionValues,
                ],
                [
                    'type'    => 'Label',
                    'caption' => $this->Translate('Favorite lists'),
                ],
                [
                    'type'                        => 'List',
                    'name'                        => 'FavoriteListsConfig',
                    'caption'                     => '',
                    'rowCount'                    => 5,
                    'add'                         => true,
                    'delete'                      => true,
                    'changeOrder'                 => false,
                    'loadValuesFromConfiguration' => false,
                    'columns'                     => [
                        [
                            'caption' => 'id',
                            'name'    => 'id',
                            'width'   => '0px',
                            'visible' => false,
                            'save'    => true,
                            'add'     => '',
                        ],
                        [
                            'caption' => $this->Translate('Name'),
                            'name'    => 'name',
                            'width'   => 'auto',
                            'save'    => true,
                            'add'     => '',
                            'edit'    => ['type' => 'ValidationTextBox'],
                        ],
                        [
                            'caption' => $this->Translate('Items'),
                            'name'    => 'items',
                            'width'   => '80px',
                            'save'    => false,
                            'add'     => 0,
                        ],
                    ],
                    'values' => $favValues,
                ],
                [
                    'type'    => 'Label',
                    'caption' => $this->Translate('Edit favorite list items'),
                ],
                [
                    'type'     => 'Select',
                    'name'     => 'FavoriteListSelect',
                    'caption'  => $this->Translate('Select favorite list'),
                    'value'    => $selectedListId,
                    'options'  => $favSelectOptions,
                    'onChange'  => 'SL_SwitchFavoriteList($id, $FavoriteListSelect, json_encode($FavoriteItemsConfig));',
                ],
                [
                    'type'                        => 'List',
                    'name'                        => 'FavoriteItemsConfig',
                    'caption'                     => '',
                    'rowCount'                    => 8,
                    'add'                         => true,
                    'delete'                      => true,
                    'changeOrder'                 => false,
                    'loadValuesFromConfiguration' => false,
                    'columns'                     => [
                        [
                            'caption' => $this->Translate('Name'),
                            'name'    => 'name',
                            'width'   => 'auto',
                            'save'    => true,
                            'add'     => '',
                            'edit'    => ['type' => 'ValidationTextBox'],
                        ],
                        [
                            'caption' => $this->Translate('Category'),
                            'name'    => 'category',
                            'width'   => '200px',
                            'save'    => true,
                            'add'     => $this->Translate('Miscellaneous'),
                            'edit'    => ['type' => 'Select', 'options' => $categorySelectOptions],
                        ],
                        [
                            'caption' => $this->Translate('Quantity / Amount'),
                            'name'    => 'amount',
                            'width'   => '120px',
                            'save'    => true,
                            'add'     => '',
                            'edit'    => ['type' => 'ValidationTextBox'],
                        ],
                        [
                            'caption' => $this->Translate('Note'),
                            'name'    => 'notes',
                            'width'   => '200px',
                            'save'    => true,
                            'add'     => '',
                            'edit'    => ['type' => 'ValidationTextBox'],
                        ],
                    ],
                    'values' => $favItemValues,
                ],
            ],
        ];

        return json_encode($form, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}

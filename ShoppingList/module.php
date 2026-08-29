<?php

declare(strict_types=1);

require_once __DIR__ . '/libs/ItemStore.php';
require_once __DIR__ . '/libs/SuggestionEngine.php';
require_once __DIR__ . '/libs/FavoriteStore.php';
require_once __DIR__ . '/libs/PurchaseStore.php';
require_once __DIR__ . '/../libs/ListSource.php';
require_once __DIR__ . '/../libs/ExternalListSync.php';
require_once __DIR__ . '/libs/ExtListHooksShopping.php';

class SymDoShoppingList extends IPSModuleStrict
{

    use ItemStore;
    use SuggestionEngine;
    use FavoriteStore;
    use PurchaseStore;
    use ExternalListSync;
    use ExtListHooksShopping;

    /**
     * Das eingebaute Aussehen der Standardkategorien: Symbol und Farbe.
     *
     * Symbolnamen sind Font-Awesome-Namen OHNE 'fa-' davor — genau die Schreibweise,
     * die der Waehler von Symcon speichert (an einer echten Konfiguration abgelesen:
     * 'apple-whole', 'cheese-swiss', 'can-food'). Die Oberflaechen ergaenzen das
     * 'fa-'. Farben sind Ganzzahlen wie von SelectColor, in RGB-Reihenfolge.
     *
     * Spiegel der Tabelle CATEGORY_STYLES in ShoppingList/module.html und
     * SymDoWebApp/module.html — synchron halten. Dort bleibt sie der Rueckfall fuer
     * Instanzen, die nichts konfiguriert haben.
     */
    private const CATEGORY_DEFAULT_STYLES = [
        'Obst & Gemüse'        => ['icon' => 'carrot',          'color' => 0x34C759],
        'Milch & Käse'         => ['icon' => 'cheese',          'color' => 0x5AC8FA],
        'Backwaren'            => ['icon' => 'croissant',       'color' => 0xA2845E],
        'Fleisch & Wurst'      => ['icon' => 'utensils',        'color' => 0xFF4D4D],
        'Tiefkühl'             => ['icon' => 'snowflake',       'color' => 0x40C8E0],
        'Getränke'             => ['icon' => 'glass-water',     'color' => 0x3498FA],
        'Snacks & Süßes'       => ['icon' => 'popcorn',         'color' => 0xFF2D55],
        'Konserven & Trocken'  => ['icon' => 'box',             'color' => 0xFF9500],
        'Hygiene & Pflege'     => ['icon' => 'shower',          'color' => 0x00C7BE],
        'Haushalt & Reinigung' => ['icon' => 'soap',            'color' => 0x5856D6],
        'Baby & Tier'          => ['icon' => 'paw',             'color' => 0xAF52DE],
        'Sonstiges'            => ['icon' => 'grid-2',          'color' => 0x8E8E93],
    ];

    /**
     * Farbtopf fuer Kategorien, die nicht in der Tabelle stehen — eigene also.
     * Gleiche Werte und gleiche Reihenfolge wie die Oberflaechen sie hatten, damit
     * sich durch den Umzug hierher keine Farbe aendert.
     */
    private const CATEGORY_HASH_PALETTE = [
        '#3498FA', '#34C759', '#FF9500', '#AF52DE', '#FF2D55', '#40C8E0', '#5856D6', '#00C7BE',
    ];

    /** JS-Semantik von `x | 0`: auf 32 Bit mit Vorzeichen kuerzen. */
    private static function ToInt32(int $wert): int
    {
        $wert &= 0xFFFFFFFF;
        return ($wert & 0x80000000) ? $wert - 0x100000000 : $wert;
    }

    /**
     * djb2 ueber den kleingeschriebenen Namen, gleiche Farbe fuer gleichen Namen.
     *
     * Muss die Rechnung von JavaScript nachbilden, sonst kaeme eine andere Farbe
     * heraus als bisher: dort kuerzt `<< 5` schon auf 32 Bit mit Vorzeichen, die
     * Summe rechnet als Fliesskomma weiter und `| 0` kuerzt erneut. PHP hat
     * 64-Bit-Ganzzahlen, also wird an denselben zwei Stellen gekuerzt.
     */
    private static function CategoryHashColor(string $name): string
    {
        $h     = 5381;
        $klein = mb_strtolower(trim($name));
        $laenge = mb_strlen($klein);
        for ($i = 0; $i < $laenge; $i++) {
            $code = mb_ord(mb_substr($klein, $i, 1)) ?: 0;
            $h    = self::ToInt32(self::ToInt32($h << 5) + $h + $code);
        }
        $topf = self::CATEGORY_HASH_PALETTE;
        return $topf[abs($h) % count($topf)];
    }

    /** Zeilen fuer die Voreinstellung von CategoryOrder: Name, Symbol, Farbe. */
    private static function DefaultCategoryRows(): array
    {
        $rows = [];
        foreach (self::CATEGORY_DEFAULT_STYLES as $name => $stil) {
            $rows[] = ['category' => $name, 'icon' => $stil['icon'], 'color' => $stil['color']];
        }
        return $rows;
    }

    public function Create(): void
    {
        parent::Create();
        $this->SetVisualizationType(1);
        $this->RegisterHook($this->GetAssetHookPath());
        $this->RegisterAttributeString('Items', '[]');
        $this->RegisterAttributeString('Frequencies', '{}');
        $this->RegisterAttributeString('CategoryOverrides', '{}');
        $this->RegisterAttributeString('ItemHistory', '{}');
        $this->RegisterAttributeString('SuggestionItems', '[]');
        $this->RegisterAttributeString('FavoriteLists', '[]');
        // Freier Hinweis fuer den Einkauf, den die Ansage als Erstes vorliest.
        // Gehoert zur Liste und nicht zum Geraet: App, Web-App und Kachel teilen ihn.
        $this->RegisterAttributeString('ShoppingHint', '');
        // Kaufhistorie: Map normalisierter Name → {name, category, count, last}.
        // Getrennt von Frequencies, weil das Hinzufügen zählt, nicht das Abhaken.
        $this->RegisterAttributeString('PurchaseHistory', '{}');
        // Von der Kachel gemeldete Visu-Farben je Schema (ReportVisuTheme)
        $this->RegisterAttributeString('VisuTheme', '{}');
        $this->RegisterAttributeString('PreviousCategoryOrder', '[]');
        // Cache der Produktbild-Map: der Scan über ~500 Asset-Dateien plus
        // Alias-Parsing lief bisher bei JEDEM State-Bau (also auch bei jedem Push).
        $this->RegisterAttributeString('ImageMapCache', '{}');
        $this->RegisterAttributeString('WebHookToken', '');
        $this->RegisterAttributeInteger('AppRevision', 0);
        $this->RegisterAttributeInteger('LastExternalScannerVariableID', 0);
        $this->RegisterAttributeString('ExtApiAccessToken', '');
        $this->RegisterAttributeString('ExtApiRefreshToken', '');
        $this->RegisterAttributeInteger('ExtApiTokenExpires', 0);
        $this->RegisterAttributeString('ExtApiPkceVerifier', '');
        $this->RegisterAttributeString('ExtApiPkceState', '');
        $this->RegisterAttributeString('ExtApiBasketId', '');
        $this->RegisterAttributeString('ExtApiGatewayToken', '');
        $this->RegisterAttributeString('ExtApiSessionId', '');
        $this->RegisterAttributeString('ExtApiInstanaId', '');
        $this->RegisterAttributeString('ExtApiRdfaId', '');
        $this->RegisterAttributeString('ExtApiDeviceId', '');
        $this->RegisterAttributeString('ExtApiCartMarketId', '');
        $this->RegisterAttributeString('ExtApiCartContextKey', '');
        $this->RegisterAttributeString('ExtApiCookies', '{}');
        $this->RegisterPropertyString('FavoriteListsConfig', '[]');
        $this->RegisterPropertyString('SuggestionItemsConfig', '[]');
        $this->RegisterPropertyBoolean('ShowProductImages', true);
        // Zeilenknoepfe. Bewusst neue Namen: das alte ShowEditButton steuerte nichts
        // und steht bei Bestandsinstanzen ueberwiegend auf true — es zu honorieren
        // haette allen ungefragt einen Knopf in die Zeile gesetzt.
        $this->RegisterPropertyBoolean('ShowFavoriteHeart', true);
        $this->RegisterPropertyBoolean('ShowRowEditButton', false);
        $this->RegisterPropertyBoolean('ShowRowDeleteButton', false);
        $this->RegisterPropertyBoolean('ScannerEnabled', true);
        $this->RegisterPropertyInteger('ExternalScannerVariableID', 0);
        $this->ExtListCreateProperties();
        $this->RegisterPropertyBoolean('ExtApiEnabled', false);
        $this->RegisterPropertyBoolean('ExtApiShowPrice', false);
        $this->RegisterPropertyString('ExtApiMarketId', '');
        $this->RegisterPropertyString('ExtApiZipCode', '');
        $this->RegisterPropertyString('ExtApiCertFile', '');
        $this->RegisterPropertyString('ExtApiKeyFile', '');
        $this->RegisterPropertyString('ExtApiConfig', '');
        $this->RegisterPropertyString('FavoriteItemsConfig', '[]');
        // Zeilen statt reiner Namen, damit Symbol und Farbe von Anfang an in der
        // Tabelle stehen und dort nur noch geaendert werden muessen. Reine Namen
        // bleiben lesbar (GetCategoryOrderFlat), Bestandsinstanzen aendern sich nicht.
        $this->RegisterPropertyString('CategoryOrder', json_encode(
            self::DefaultCategoryRows(),
            JSON_UNESCAPED_UNICODE
        ));

        $this->RegisterVariableInteger('ItemCount', $this->Translate('Item Count'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'basket-shopping',
            'SUFFIX'       => '',
        ], 200);

        $this->RegisterVariableInteger('LastUsed', $this->Translate('Last Used'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'cart-shopping',
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

        // Write external product API mTLS certificates to disk
        $this->WriteExtApiCertFiles();

        $this->SyncExternalScannerVariable();
        $this->ExtListBindTrigger();

        // Sync counts and push updated state to tile
        $this->UpdateCounts($this->LoadItems());
        $this->SendState();
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
            return;
        }

        if ($Message === VM_UPDATE && $SenderID === $this->ReadPropertyInteger('ExternalScannerVariableID')) {
            $this->HandleExternalScannerUpdate($SenderID);
            return;
        }

        // Eine externe Liste hat sich geaendert. ExtListIsTrigger prueft
        // das Changed-Flag, ein unveraenderter Fremd-Takt loest also nichts aus.
        if ($this->ExtListIsTrigger($SenderID, $Message, $Data)) {
            $this->ExtListSync();
        }
    }

    /** Abgleich mit den externen Listen von Hand — der Knopf im Formular. */
    public function ExtListSyncNow(): string
    {
        return $this->ExtListSyncNowText();
    }

    public function Destroy(): void
    {
        $this->DeleteExtApiCertFiles();
        parent::Destroy();
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'GetState':
                // Read-only push to the tile — must not bump AppRevision, otherwise
                // every tile open would invalidate the app clients' state caches.
                $this->PushCurrentState();
                return;
            case 'SetHint':
                // Leerer Text loescht den Hinweis — das ist das X in der Oberflaeche.
                $daten = json_decode((string)$Value, true);
                $this->SetHint(is_array($daten) ? (string)($daten['text'] ?? '') : (string)$Value);
                return;
            case 'AddItem':
                $data = json_decode((string)$Value, true);
                if (!is_array($data) || trim((string)($data['name'] ?? '')) === '') {
                    throw new \Exception($this->Translate('Invalid payload'));
                }
                if (!$this->AddItem(
                    (string)($data['name'] ?? ''),
                    (string)($data['category'] ?? ''),
                    (string)($data['amount'] ?? '')
                )) {
                    throw new \Exception($this->Translate('Item operation failed'));
                }
                return;
            case 'AddScannedItem':
                $data = json_decode((string)$Value, true);
                if (!is_array($data) || trim((string)($data['name'] ?? '')) === '') {
                    throw new \Exception($this->Translate('Invalid payload'));
                }
                if (!$this->AddScannedItemInternal(
                    (string)($data['name'] ?? ''),
                    (string)($data['category'] ?? ''),
                    (string)($data['amount'] ?? ''),
                    (string)($data['price'] ?? ''),
                    (string)($data['listingId'] ?? ''),
                    (string)($data['imageUrl'] ?? ''),
                    (string)($data['notes'] ?? '')
                )) {
                    throw new \Exception($this->Translate('Item operation failed'));
                }
                return;
            case 'ToggleCart':
                // Accepts the raw item id (tile) or JSON {id, inCart} with an explicit
                // target state, which makes replayed app requests idempotent.
                $data = json_decode((string)$Value, true);
                if (is_array($data) && isset($data['id'])) {
                    $result = $this->ToggleItemCart(
                        (string)$data['id'],
                        array_key_exists('inCart', $data) ? (bool)$data['inCart'] : null
                    );
                } else {
                    $result = $this->ToggleItemCart((string)$Value);
                }
                if (!$result) {
                    throw new \Exception($this->Translate('Unknown item id'));
                }
                return;
            case 'RemoveItem':
                if (trim((string)$Value) === '') {
                    throw new \Exception($this->Translate('Invalid payload'));
                }
                // Not-found is treated as success: removing an already removed
                // item is an idempotent no-op for retrying clients.
                $this->RemoveItem((string)$Value);
                return;
            case 'DeleteItem':
                if (trim((string)$Value) === '') {
                    throw new \Exception($this->Translate('Invalid payload'));
                }
                $this->DeleteItem((string)$Value);
                return;
            case 'ClearCart':
                $this->ClearCart();
                return;
            case 'MarkAllDone':
                $this->MarkAllDoneInternal();
                return;
            case 'UpdateItem':
                $data = json_decode((string)$Value, true);
                if (!is_array($data) || trim((string)($data['id'] ?? '')) === '') {
                    throw new \Exception($this->Translate('Invalid payload'));
                }
                if (!$this->UpdateItemInternal(
                    (string)($data['id'] ?? ''),
                    (string)($data['name'] ?? ''),
                    (string)($data['amount'] ?? ''),
                    (string)($data['notes'] ?? ''),
                    (string)($data['category'] ?? '')
                )) {
                    throw new \Exception($this->Translate('Unknown item id'));
                }
                return;
            case 'CreateFavoriteList':
                $data = json_decode((string)$Value, true);
                if (!is_array($data) || trim((string)($data['name'] ?? '')) === '') {
                    throw new \Exception($this->Translate('Invalid payload'));
                }
                $this->CreateFavoriteListInternal((string)($data['name'] ?? ''));
                return;
            case 'AddItemToFavoriteList':
                $data = json_decode((string)$Value, true);
                if (!is_array($data) || !isset($data['listId'], $data['name'])) {
                    throw new \Exception($this->Translate('Invalid payload'));
                }
                $this->AddItemToFavoriteListInternal(
                    (string)($data['listId'] ?? ''),
                    (string)($data['name'] ?? ''),
                    (string)($data['category'] ?? ''),
                    (string)($data['amount'] ?? ''),
                    (string)($data['notes'] ?? '')
                );
                return;
            case 'AddItemsToFavoriteList':
                $data = json_decode((string)$Value, true);
                if (!is_array($data) || !is_array($data['items'] ?? null)) {
                    throw new \Exception($this->Translate('Invalid payload'));
                }
                $this->AddItemsToFavoriteListInternal(
                    (string)($data['listId'] ?? ''),
                    (string)($data['name'] ?? ''),
                    $data['items'],
                    (string)($data['url'] ?? ''),
                    (string)($data['mediaId'] ?? '')
                );
                return;
            case 'RemoveItemFromFavoriteList':
                $data = json_decode((string)$Value, true);
                if (!is_array($data) || !isset($data['listId'], $data['name'])) {
                    throw new \Exception($this->Translate('Invalid payload'));
                }
                $this->RemoveItemFromFavoriteListInternal(
                    (string)($data['listId'] ?? ''),
                    (string)($data['name'] ?? '')
                );
                return;
            case 'AddFavoriteListToCart':
                if (trim((string)$Value) === '') {
                    throw new \Exception($this->Translate('Invalid payload'));
                }
                $this->AddFavoriteListToCartInternal((string)$Value);
                return;
            case 'UpdatePurchase':
                // Eintrag der Kaufhistorie ändern. oldName ist der Schlüssel, der
                // Rest ersetzt den Eintrag — ein neuer Name zieht den Schlüssel mit.
                $data = json_decode((string)$Value, true);
                if (!is_array($data) || trim((string)($data['oldName'] ?? '')) === '') {
                    throw new \Exception($this->Translate('Invalid payload'));
                }
                if ($this->UpdatePurchaseInternal(
                    (string)$data['oldName'],
                    (string)($data['name'] ?? ''),
                    (string)($data['category'] ?? ''),
                    (string)($data['amount'] ?? ''),
                    (string)($data['notes'] ?? '')
                )) {
                    $this->SendState();
                }
                return;
            case 'ForgetPurchase':
                // Einen Eintrag aus der Kaufhistorie streichen (Fehlkauf, Tippfehler).
                // Der Name ist der Schlüssel — Kaufeinträge haben keine ID.
                $data = json_decode((string)$Value, true);
                $name = is_array($data) ? (string)($data['name'] ?? '') : trim((string)$Value);
                if (trim($name) === '') {
                    throw new \Exception($this->Translate('Invalid payload'));
                }
                if ($this->ForgetPurchaseInternal($name)) {
                    // Kein SaveItems im Spiel, der Push muss also selbst angestoßen
                    // werden — sonst sähe die Oberfläche den Eintrag weiter.
                    $this->SendState();
                }
                return;
            case 'RenameFavoriteList':
                $data = json_decode((string)$Value, true);
                if (!is_array($data) || !isset($data['listId'], $data['newName'])) {
                    throw new \Exception($this->Translate('Invalid payload'));
                }
                $this->RenameFavoriteListInternal(
                    (string)($data['listId'] ?? ''),
                    (string)($data['newName'] ?? '')
                );
                return;
            case 'DeleteFavoriteList':
                if (trim((string)$Value) === '') {
                    throw new \Exception($this->Translate('Invalid payload'));
                }
                $this->DeleteFavoriteListInternal((string)$Value);
                return;
            case 'UpdateFavoriteItem':
                $data = json_decode((string)$Value, true);
                if (!is_array($data) || !isset($data['listId'], $data['oldName'], $data['newName'])) {
                    throw new \Exception($this->Translate('Invalid payload'));
                }
                $this->UpdateFavoriteItemInternal(
                    (string)($data['listId'] ?? ''),
                    (string)($data['oldName'] ?? ''),
                    (string)($data['newName'] ?? ''),
                    (string)($data['category'] ?? ''),
                    (string)($data['amount'] ?? ''),
                    (string)($data['notes'] ?? '')
                );
                return;
            case 'ReportVisuTheme':
                // Stiller Speicher (kein SendState): Die Kachel meldet die
                // CSS-Variablen der Visu, die SymDo-App holt sie über die
                // Gateway-Discovery ab.
                $data = json_decode((string)$Value, true);
                if (!is_array($data)) {
                    return;
                }
                $scheme = ($data['scheme'] ?? '') === 'dark' ? 'dark' : 'light';
                $colors = [];
                foreach (['accent', 'content', 'card'] as $key) {
                    $color = strtolower(trim((string)($data[$key] ?? '')));
                    if (preg_match('/^#[0-9a-f]{6}$/', $color)) {
                        $colors[$key] = $color;
                    }
                }
                if (count($colors) === 3) {
                    $theme = json_decode($this->ReadAttributeString('VisuTheme'), true);
                    if (!is_array($theme)) {
                        $theme = [];
                    }
                    $theme[$scheme] = $colors;
                    $this->WriteAttributeString('VisuTheme', json_encode($theme));
                }
                return;
            default:
                throw new \Exception($this->Translate('Invalid Ident'));
        }
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

    /** Bild-Basis-URL (Hook + Token) für Companion-Kacheln wie ShoppingListOverview. */
    public function GetTileImageBase(): string
    {
        return $this->GetAssetHookBase() . '&f=';
    }

    /**
     * Gemeinsame Basis für Asset-Aufrufe: Hook, Token und ein Versionsstempel.
     *
     * Der Stempel (`&v=`) ist nötig, weil der Asset-Hook mit
     * `Cache-Control: max-age=86400` und ohne ETag antwortet. Wird ein
     * Bestandsbild ersetzt, bleibt der Dateiname gleich — der Browser hätte also
     * keinen Anlass, seine bis zu einen Tag alte Kopie zu verwerfen, und zeigte
     * weiter das alte Bild. Ändert sich ein Asset, ändert sich der Stempel und
     * damit jede Bild-URL genau einmal.
     */
    private function GetAssetHookBase(): string
    {
        $token = urlencode($this->ReadAttributeString('WebHookToken'));
        return '/hook/' . $this->GetAssetHookPath() . '/?t=' . $token
            . '&v=' . $this->GetAssetsVersion();
    }

    /**
     * Jüngste Änderungszeit im Asset-Ordner, als Cache-Version.
     *
     * Bewusst das Maximum über die DATEIEN und nicht die mtime des Ordners: die
     * bleibt beim reinen Überschreiben einer Datei unverändert (gemessen — nur
     * ein Ersetzen per rename bumpt sie). Genau dieser Fall — gleicher Name,
     * neuer Inhalt — ist der, den der Stempel abfangen soll.
     *
     * Kostet bei ~520 Dateien rund 13 ms und läuft einmal pro Render, nicht pro
     * Bild; das Ergebnis wird für die Dauer der Anfrage gemerkt.
     */
    private ?int $assetsVersion = null;

    private function GetAssetsVersion(): int
    {
        if ($this->assetsVersion !== null) {
            return $this->assetsVersion;
        }
        $dir = __DIR__ . '/assets';
        $max = (int)@filemtime($dir);
        foreach (@scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $mtime = @filemtime($dir . '/' . $entry);
            if ($mtime !== false && $mtime > $max) {
                $max = $mtime;
            }
        }
        return $this->assetsVersion = $max;
    }

    /** ExtApi-Barcode-Lookup-Basis-URL (Hook + Token) für Companion-Kacheln. */
    public function GetTileExtApiBase(): string
    {
        $token = urlencode($this->ReadAttributeString('WebHookToken'));
        return '/hook/' . $this->GetAssetHookPath() . '/?t=' . $token . '&a=extapi&ean=';
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
            $this->LogMessage('GetVisualizationTile: module.html nicht lesbar, Pfad=' . $path . ' vorhanden=' . $exists . ' readable=' . $readable . ' size=' . $size . ' err=' . $errMsg, KL_WARNING);
            return '';
        }
        if (strlen($html) < 200) {
            $this->LogMessage('GetVisualizationTile: module.html gelesen, aber auffaellig kurz. Bytes=' . strlen($html) . ' head=' . substr($html, 0, 80), KL_WARNING);
        }
        // Dieselbe Basis wie GetTileImageBase(), inklusive Versionsstempel — sonst
        // zeigte die Kachel nach einem Bildersatz weiter die zwischengespeicherte
        // alte Fassung.
        $hookUrl = $this->GetTileImageBase();
        $extApiHookUrl = $this->GetTileExtApiBase();
        return $html . '<script>window.__imageHookUrl=' . json_encode($hookUrl) . ';window.__extApiHookUrl=' . json_encode($extApiHookUrl) . ';</script>';
    }

    private function GetAssetHookPath(): string
    {
        return 'shoppinglist/assets/' . $this->InstanceID;
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

    public function DeleteItem(string $Id): bool
    {
        return $this->DeleteItemInternal($Id);
    }

    public function ClearCart(): void
    {
        $this->ClearCartInternal();
    }

    public function GetItems(): string
    {
        return json_encode($this->LoadItems(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function GetAppRevision(): int
    {
        return $this->ReadAttributeInteger('AppRevision');
    }

    /**
     * Von der Kachel gemeldete Visu-Farben ({"dark":{accent,content,card},
     * "light":{...}}) — vom Gateway in der Discovery ausgeliefert.
     */
    public function GetVisuTheme(): string
    {
        return $this->ReadAttributeString('VisuTheme');
    }

    public function GetAppState(): string
    {
        // Read the revision before building the state: a concurrent mutation in
        // between yields a state newer than the revision, which the next poll
        // corrects; the reverse order would let clients miss an update.
        $revision = $this->ReadAttributeInteger('AppRevision');
        return json_encode([
            'revision' => $revision,
            'kind'     => 'shopping',
            'state'    => $this->BuildStatePayload(),
        /* Ein Eintrag kann Text aus fremder Hand tragen: ein Produktname aus einer
           Barcode-Datenbank, eine Aufgabe von einem CalDAV-Server. Ist darin ein
           Byte kein gueltiges UTF-8, gaebe json_encode `false` zurueck — die App
           bekaeme eine leere Zeichenkette und zeigte eine leere Liste. Das
           Ersatzzeichen ist die kleinere Stoerung. */
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public function AppCall(string $Action, string $Payload): string
    {
        $allowed = [
            'AddItem', 'AddScannedItem', 'ToggleCart', 'RemoveItem', 'DeleteItem',
            'ClearCart', 'MarkAllDone', 'UpdateItem', 'CreateFavoriteList',
            'AddItemToFavoriteList', 'AddItemsToFavoriteList', 'RemoveItemFromFavoriteList', 'AddFavoriteListToCart',
            'RenameFavoriteList', 'DeleteFavoriteList', 'UpdateFavoriteItem',
            'ForgetPurchase', 'UpdatePurchase',
            'SetHint',
        ];
        $ok    = true;
        $error = null;
        if (!in_array($Action, $allowed, true)) {
            $ok    = false;
            $error = ['code' => 'unknown_action', 'message' => 'Unknown action: ' . $Action];
        } else {
            try {
                $this->RequestAction($Action, $Payload);
            } catch (\Throwable $e) {
                $ok    = false;
                $error = ['code' => 'invalid_payload', 'message' => $e->getMessage()];
                $this->SendDebug('AppCall', $Action . ' failed: ' . $e->getMessage(), 0);
            }
        }
        $result = [
            'ok'       => $ok,
            'revision' => $this->ReadAttributeInteger('AppRevision'),
            'kind'     => 'shopping',
            'state'    => $this->BuildStatePayload(),
        ];
        if ($error !== null) {
            $result['error'] = $error;
        }
        return json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function LookupBarcode(string $EAN): string
    {
        $ean = trim($EAN);
        if (!preg_match('/^\d{8,14}$/', $ean)) {
            return json_encode(['found' => false, 'error' => 'invalid_ean']);
        }
        $product = $this->LookupBarcodeOpenFoodFacts($ean);
        $source  = 'off';
        if ($product === null) {
            $product = $this->LookupBarcodeOpenGtinDb($ean);
            $source  = 'opengtindb';
        }
        if ($product === null) {
            return json_encode(['found' => false]);
        }
        $product['source'] = $source;
        return json_encode(['found' => true, 'product' => $product], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Freier Hinweis fuer den Einkauf. Die gesprochene Einkaufsliste liest ihn als
     * Erstes vor; am Bildschirm haengt er an der Sprechblase in der Kopfzeile.
     *
     * ReadAttributeString kann vor dem ersten Kernel-Start fehlschlagen, deshalb
     * abgesichert — ein fehlender Hinweis ist einfach keiner.
     */
    private function ReadHint(): string
    {
        try {
            return trim((string)$this->ReadAttributeString('ShoppingHint'));
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Leert den Hinweis nach abgeschlossenem Einkauf. Bewusst OHNE die
     * Rueckleseprobe aus SetHint: hier hat niemand etwas eingetippt, das verloren
     * gehen koennte — und ein Fehlschlag darf das Abhaken nicht aufhalten.
     */
    private function ClearHint(): void
    {
        if ($this->ReadHint() === '') {
            return;
        }
        try {
            $this->WriteAttributeString('ShoppingHint', '');
        } catch (\Throwable $e) {
            $this->SendDebug('Hint', 'konnte nicht geleert werden: ' . $e->getMessage(), 0);
        }
    }

    /** Leerer Text loescht den Hinweis. */
    private function SetHint(string $Text): void
    {
        $sauber = trim(preg_replace('/[ \t]+/u', ' ', $Text) ?? '');
        // Deckel wie bei den Ansagen: laenger als das spricht niemand vor.
        if (mb_strlen($sauber) > 400) {
            $sauber = rtrim(mb_substr($sauber, 0, 400));
        }
        if ($sauber === $this->ReadHint()) {
            return;
        }
        $this->WriteAttributeString('ShoppingHint', $sauber);
        // Zurueckgelesen, weil WriteAttributeString ein Attribut, das der laufende
        // Kernel noch nicht kennt, STILL verschluckt (gemessen: AppCall meldete
        // trotzdem Erfolg). Ohne diese Probe verlaere der Nutzer seinen Text
        // kommentarlos; so bekommt der Client eine klare Meldung.
        if ($this->ReadHint() !== $sauber) {
            throw new \Exception($this->Translate('Hint could not be saved — please restart Symcon once.'));
        }
        $this->SendState();
    }

    private function BumpAppRevision(): void
    {
        $this->WriteAttributeInteger('AppRevision', $this->ReadAttributeInteger('AppRevision') + 1);
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

    /**
     * Darstellung JEDER vorkommenden Kategorie: Name => [icon, color].
     *
     * Vollstaendig und nicht nur das Konfigurierte: die Oberflaechen halten keine
     * eigene Tabelle mehr, der Payload ist die einzige Quelle. Wer hier fehlt,
     * bekommt dort nichts — deshalb sind auch die Kategorien dabei, die nur an
     * Artikeln, Favoriten oder Vorschlaegen haengen und nicht in der Tabelle stehen.
     *
     * Reihenfolge der Entscheidung:
     *   1. im Backend eingestellt  -> dieser Wert
     *   2. Standardkategorie       -> eingebautes Aussehen
     *   3. eigene Kategorie        -> Einkaufskorb, Farbe aus dem Namen gestreut
     *
     * Symbolnamen gehen OHNE 'fa-' hinaus, so wie der Waehler sie speichert; das
     * 'fa-' ergaenzen die Oberflaechen. Farben als '#rrggbb', direkt in CSS setzbar.
     *
     * @param string[] $weitere Kategorienamen aus Artikeln, Favoriten, Vorschlaegen
     */
    private function GetCategoryStyleMap(array $weitere = []): array
    {
        // 1. Eingestelltes einsammeln, dabei die Reihenfolge der Tabelle behalten
        $konfiguriert = [];
        $namen        = [];
        $decoded      = json_decode($this->ReadPropertyString('CategoryOrder'), true);
        foreach ((array)$decoded as $entry) {
            $name = trim(is_string($entry) ? $entry : (string)($entry['category'] ?? ''));
            if ($name === '') {
                continue;
            }
            $namen[$name] = true;
            if (!is_array($entry)) {
                continue;
            }
            $icon = trim((string)($entry['icon'] ?? ''));
            if ($icon !== '' && strcasecmp($icon, 'Transparent') !== 0) {
                $konfiguriert[$name]['icon'] = $icon;
            }
            $color = array_key_exists('color', $entry) ? (int)$entry['color'] : -1;
            if ($color >= 0) {
                $konfiguriert[$name]['color'] = sprintf('#%06X', $color & 0xFFFFFF);
            }
        }
        foreach ($weitere as $name) {
            $name = trim((string)$name);
            if ($name !== '') {
                $namen[$name] = true;
            }
        }

        // 2. Aufloesen
        $result = [];
        foreach (array_keys($namen) as $name) {
            $vorgabe = self::CATEGORY_DEFAULT_STYLES[$name] ?? null;
            $result[$name] = [
                'icon'  => $konfiguriert[$name]['icon']
                    ?? ($vorgabe !== null ? $vorgabe['icon'] : 'basket-shopping'),
                'color' => $konfiguriert[$name]['color']
                    ?? ($vorgabe !== null
                        ? sprintf('#%06X', $vorgabe['color'] & 0xFFFFFF)
                        : self::CategoryHashColor($name)),
            ];
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

    /**
     * Boolesche Eigenschaft lesen, die es in älteren Instanzen noch nicht gibt.
     *
     * Eine neu in `Create()` registrierte Eigenschaft existiert erst, nachdem das Modul
     * neu geladen wurde. Vorher liefert `ReadPropertyBoolean` **false** — nicht den
     * registrierten Standard und auch keinen Fehler. Für einen Schalter mit Standard
     * `true` heißt das: zwischen Modul-Update und nächstem Neustart wäre das Merkmal bei
     * jedem bestehenden Nutzer stillschweigend abgeschaltet.
     *
     * Gemessen am 12.08. an Instanz 28025: `IPS_GetConfiguration` listet alle
     * registrierten Eigenschaften (14 Stück, `ShowProductImages` dabei), die neue
     * `ShowEditButton` fehlte dort — und `IPS_SetProperty` meldete Erfolg, ohne dass der
     * Wert hielt. Diese Liste ist also die verlässliche Auskunft darüber, ob die
     * Eigenschaft schon existiert.
     */

    /**
     * Sichtbarkeit der Bedienelemente dieser Liste — ausschliesslich aus den eigenen
     * Eigenschaften. Es gibt keine Uebersteuerung von aussen mehr.
     *
     * Diese Werte gelten fuer die KACHEL dieser Liste. Die Web-App verwirft sie und
     * setzt ihre eigenen, appweiten Schalter (SymDoWebApp) — sie zeigt alle Listen in
     * einer Oberflaeche, dort waere ein Wechsel des Erscheinungsbilds von Liste zu
     * Liste stoerend. Ueberschrieben wird nicht hier, sondern beim Zusammensetzen der
     * Nutzlast: SymDoWebApp::StripState() und im Gateway ApplyWebAppButtonFlags().
     *
     * @return array{showFavoriteHeart: bool, showEditButton: bool, showDeleteButton: bool}
     */
    private function ResolveButtonFlags(): array
    {
        return [
            'showFavoriteHeart' => $this->ReadBooleanPropertyOrDefault('ShowFavoriteHeart', true),
            'showEditButton'    => $this->ReadBooleanPropertyOrDefault('ShowRowEditButton', false),
            'showDeleteButton'  => $this->ReadBooleanPropertyOrDefault('ShowRowDeleteButton', false),
        ];
    }

    private function ReadBooleanPropertyOrDefault(string $name, bool $default): bool
    {
        $config = json_decode(IPS_GetConfiguration($this->InstanceID), true);
        if (!is_array($config) || !array_key_exists($name, $config)) {
            return $default;
        }
        return $this->ReadPropertyBoolean($name);
    }

    protected function BuildStatePayload(): array
    {
        // Einmal bauen (Verzeichnis-Scan + Alias-Parse) — Marken-Map leitet
        // sich daraus ab statt erneut zu scannen.
        $knoepfe = $this->ResolveButtonFlags();
        $productImages = $this->ReadPropertyBoolean('ShowProductImages') ? $this->GetAvailableProductImages() : [];

        // Einmal laden und weiterverwenden: aus denselben Daten leiten sich die
        // Kategorienamen ab, die in der Stil-Karte vorkommen muessen (die Oberflaechen
        // haben keine eigene Tabelle mehr, siehe GetCategoryStyleMap).
        $items       = $this->LoadItems();
        $suggestions = $this->BuildSuggestionsPayload();
        $favorites   = $this->LoadFavoriteLists();
        $benutzt     = [];
        foreach ($items as $it) {
            $benutzt[] = (string)($it['category'] ?? '');
        }
        foreach ($suggestions as $sg) {
            $benutzt[] = (string)($sg['category'] ?? '');
        }
        foreach ($favorites as $fl) {
            foreach ((array)($fl['items'] ?? []) as $fi) {
                $benutzt[] = (string)($fi['category'] ?? '');
            }
        }

        return [
            'type'            => 'state',
            'items'           => $items,
            'suggestions'     => $suggestions,
            'categoryOrder'   => $this->GetCategoryOrderFlat(),
            // Zusaetzlicher Schluessel und KEINE Aenderung an categoryOrder: dessen
            // flache Namensliste lesen Kachel, Web-App und die iOS-App: eine andere
            // Form dort wuerde alle drei brechen.
            'categoryStyles'  => $this->GetCategoryStyleMap($benutzt),
            'favoriteLists'   => $favorites,
            'hint'            => $this->ReadHint(),
            // Schon einmal abgehakte Artikel. BEWUSST ein eigener Schlüssel und kein
            // Eintrag in favoriteLists: die Herz-Anzeige an jeder Einkaufszeile leitet
            // sich aus den Namen ALLER Favoritenlisten ab (SymDoWebApp getFavNames,
            // iOS membership) — jeder je gekaufte Artikel bekäme sonst ein gefülltes
            // Herz. Dazu käme die Liste in Auswahldialoge und Mutationspfade, die sie
            // nur ablehnen könnten.
            'purchased'       => $this->BuildPurchasedPayload(),
            // Basis-URLs IM Zustand und nicht nur als injiziertes Skript: die Kachel
            // bekommt den ersten Zustand moeglicherweise, bevor das an das HTML
            // angehaengte <script> gelaufen ist. Dann war window.__imageHookUrl noch
            // leer, und weil die Oberflaeche diese Variable selbst setzt, blieb sie
            // leer — die Kachel zeigte bis zum naechsten Push keine Produktbilder.
            // Mit dem Wert im Zustand haengt nichts mehr an der Reihenfolge.
            'imageBase'       => $this->GetTileImageBase(),
            'extApiBase'      => $this->GetTileExtApiBase(),
            'availableImages' => $productImages,
            'availableBrands' => $productImages === [] ? [] : $this->GetAvailableBrandImages($productImages),
            'extApiEnabled'   => $this->ReadPropertyBoolean('ExtApiEnabled')
                                 && $this->ReadPropertyString('ExtApiMarketId') !== ''
                                 && file_exists($this->GetExtApiCertPath())
                                 && file_exists($this->GetExtApiKeyPath()),
            'extApiShowPrice' => $this->ReadPropertyBoolean('ExtApiShowPrice'),
            'extApiCartReady' => $this->IsExtApiCartReady(),
            'scannerEnabled'  => $this->ReadPropertyBoolean('ScannerEnabled'),
            'showFavoriteHeart' => $knoepfe['showFavoriteHeart'],
            'showEditButton'    => $knoepfe['showEditButton'],
            'showDeleteButton'  => $knoepfe['showDeleteButton'],
        ];
    }

    private function IsExtApiCartReady(): bool
    {
        if (!$this->ReadPropertyBoolean('ExtApiEnabled')) {
            return false;
        }
        if ($this->ReadAttributeString('ExtApiRefreshToken') === ''
            && $this->ReadAttributeString('ExtApiAccessToken') === '') {
            return false;
        }
        $config = $this->LoadExtApiConfig();
        if (!is_array($config)) {
            return false;
        }
        $cart = $config['cart'] ?? null;
        if (!is_array($cart)) {
            return false;
        }
        return !empty($cart['modifyUrl']);
    }

    private function GetAvailableProductImages(): array
    {
        $dir = __DIR__ . '/assets';
        if (!is_dir($dir)) {
            return [];
        }

        // Cache-Key aus den mtimes von Asset-Ordner und Alias-Datei: neue/entfernte
        // Bilder und Alias-Änderungen invalidieren ihn, sonst wird der komplette
        // Verzeichnis-Scan übersprungen.
        $cacheKey = (string)@filemtime($dir) . ':' . (string)@filemtime($dir . '/image-aliases.json');
        // Defensiv: nach einem Modul-Reload ohne Kernel-Neustart ist das Attribut
        // noch nicht registriert (ReadAttributeString liefert dann false). Der Cache
        // darf in diesem Fall nur entfallen — niemals den State-Bau abbrechen.
        $cachedRaw = @$this->ReadAttributeString('ImageMapCache');
        $cached    = is_string($cachedRaw) ? json_decode($cachedRaw, true) : null;
        if (is_array($cached) && (string)($cached['key'] ?? '') === $cacheKey && is_array($cached['map'] ?? null)) {
            return $cached['map'];
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

        try {
            $this->WriteAttributeString('ImageMapCache', json_encode(['key' => $cacheKey, 'map' => $images], JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            // Attribut (noch) nicht vorhanden → ohne Cache weiterarbeiten.
        }
        return $images;
    }

    /**
     * "_brands" aus image-aliases.json: Marke => Bild-Basisname. Eine erkannte
     * Marke bestimmt das Produktbild unabhängig von den übrigen Wörtern
     * ("Pringles Sweet Paprika" → chips). Spiegel: ProductImageLibrary (App).
     */
    private function GetAvailableBrandImages(array $images): array
    {
        $aliasPath = __DIR__ . '/assets/image-aliases.json';
        if (!file_exists($aliasPath)) {
            return [];
        }
        $aliasData = json_decode((string)file_get_contents($aliasPath), true);
        $brands = is_array($aliasData) ? ($aliasData['_brands'] ?? []) : [];
        if (!is_array($brands) || $brands === []) {
            return [];
        }
        $result = [];
        foreach ($brands as $brand => $target) {
            $brand = mb_strtolower(trim((string)$brand));
            $file = $images[mb_strtolower(trim((string)$target))] ?? null;
            if ($brand !== '' && $file !== null) {
                $result[$brand] = $file;
            }
        }
        return $result;
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
        $action = $_GET['a'] ?? '';

        // OAuth callback uses its own state-based protection (no token in redirect URI from REWE)
        if ($action === 'oauth_callback') {
            $this->HandleOAuthCallback();
            return;
        }

        // Validate token
        $token = $_GET['t'] ?? '';
        $expected = $this->ReadAttributeString('WebHookToken');
        if ($token === '' || !hash_equals($expected, $token)) {
            $this->SendDebug('Hook', 'Token mismatch. file=' . ($_GET['f'] ?? ''), 0);
            http_response_code(403);
            return;
        }

        // External product API barcode lookup
        if ($action === 'extapi') {
            $this->HandleExtApiHook();
            return;
        }

        // Bulk-add all marked items to external cart
        if ($action === 'cart_bulk') {
            $this->HandleCartBulkHook();
            return;
        }

        $file = urldecode($_GET['f'] ?? '');
        // Allow subdirectories (e.g., api-images/), but prevent directory traversal
        $file = str_replace('..', '', $file);
        $path = __DIR__ . '/assets/' . $file;

        if ($file === '' || !file_exists($path) || is_dir($path)) {
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

    private function HandleExtApiHook(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        if (!$this->ReadPropertyBoolean('ExtApiEnabled')) {
            echo json_encode(['error' => 'disabled']);
            return;
        }

        $ean = trim($_GET['ean'] ?? '');
        if ($ean === '' || !preg_match('/^\d{8,14}$/', $ean)) {
            echo json_encode(['error' => 'invalid EAN']);
            return;
        }

        $certPath = $this->GetExtApiCertPath();
        $keyPath  = $this->GetExtApiKeyPath();
        if (!file_exists($certPath) || !file_exists($keyPath)) {
            $this->SendDebug('ExtAPI', 'Certificate files missing', 0);
            echo json_encode(['error' => 'certificates missing']);
            return;
        }

        $marketId = $this->ReadPropertyString('ExtApiMarketId');
        $zip      = $this->ReadPropertyString('ExtApiZipCode');
        if ($marketId === '' || $zip === '') {
            echo json_encode(['error' => 'market not configured']);
            return;
        }

        $config = $this->LoadExtApiConfig();
        if ($config === null) {
            echo json_encode(['error' => 'invalid provider config']);
            return;
        }

        $result = $this->ExtApiLookup($ean, $marketId, $zip, $certPath, $keyPath, $config);
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function SearchMarkets(string $Zip): void
    {
        $zip = trim($Zip);
        if ($zip === '') {
            $this->UpdateFormField('ExtApiMarketSearchInfo', 'caption', $this->Translate('Please enter ZIP'));
            return;
        }
        $certPath = $this->GetExtApiCertPath();
        $keyPath  = $this->GetExtApiKeyPath();
        if (!is_file($certPath) || !is_file($keyPath)) {
            $this->UpdateFormField('ExtApiMarketSearchInfo', 'caption', $this->Translate('Certificates required for market search'));
            return;
        }

        $config = $this->LoadExtApiConfig();
        if ($config === null) {
            $this->UpdateFormField('ExtApiMarketSearchInfo', 'caption', $this->Translate('Invalid provider config'));
            return;
        }

        $baseUrl    = (string)($config['baseUrl'] ?? '');
        $marketPath = (string)($config['marketPath'] ?? '');
        if ($baseUrl === '' || $marketPath === '') {
            $this->UpdateFormField('ExtApiMarketSearchInfo', 'caption', $this->Translate('Invalid provider config'));
            return;
        }

        $vars = ['zip' => $zip];
        $queryTpl = $config['marketQuery'] ?? [];
        $query    = $this->InterpolatePlaceholders($queryTpl, $vars);

        $url = rtrim($baseUrl, '/') . $marketPath;
        if (is_array($query) && count($query) > 0) {
            $url .= '?' . http_build_query($query);
        }

        $result = $this->ExtApiHttpGet($url, [], $certPath, $keyPath, $config, $vars);
        if (isset($result['error'])) {
            $this->UpdateFormField('ExtApiMarketSearchInfo', 'caption', $this->Translate('Lookup failed') . ': ' . $result['error']);
            return;
        }

        $paths   = $config['responsePaths'] ?? [];
        $markets = $this->ResolveJsonPath($result['data'], $paths['markets'] ?? []);
        if (!is_array($markets)) {
            $markets = [];
        }

        $options = [];
        foreach ($markets as $m) {
            if (!is_array($m)) {
                continue;
            }
            $id      = trim((string)$this->ResolveJsonPath($m, $paths['marketId'] ?? ''));
            $name    = trim((string)$this->ResolveJsonPath($m, $paths['marketName'] ?? ''));
            $street  = trim((string)$this->ResolveJsonPath($m, $paths['marketStreet'] ?? ''));
            $zipCode = trim((string)$this->ResolveJsonPath($m, $paths['marketZip'] ?? ''));
            $city    = trim((string)$this->ResolveJsonPath($m, $paths['marketCity'] ?? ''));
            if ($id === '') {
                continue;
            }
            $parts = array_filter([$name, $street, trim($zipCode . ' ' . $city)]);
            $caption = $id . ' · ' . implode(' · ', $parts);
            $options[] = ['caption' => $caption, 'value' => $id];
        }

        if (count($options) === 0) {
            $this->UpdateFormField('ExtApiMarketSelect', 'options', json_encode([[
                'caption' => $this->Translate('No markets found'),
                'value'   => '',
            ]], JSON_UNESCAPED_UNICODE));
            $this->UpdateFormField('ExtApiMarketSearchInfo', 'caption', $this->Translate('No markets found'));
            return;
        }

        array_unshift($options, ['caption' => $this->Translate('Select market'), 'value' => '']);
        $this->UpdateFormField('ExtApiMarketSelect', 'options', json_encode($options, JSON_UNESCAPED_UNICODE));
        $this->UpdateFormField('ExtApiMarketSearchInfo', 'caption', sprintf($this->Translate('%d markets found'), count($options) - 1));
    }

    public function ApplyMarketSelection(string $MarketId): void
    {
        $id = trim($MarketId);
        if ($id === '') {
            return;
        }
        $this->UpdateFormField('ExtApiMarketId', 'value', $id);
    }

    private function SyncExternalScannerVariable(): void
    {
        $current = $this->ReadPropertyInteger('ExternalScannerVariableID');
        $valid = false;
        if ($current > 0 && @IPS_VariableExists($current)) {
            $variable = @IPS_GetVariable($current);
            $valid = is_array($variable) && (int)($variable['VariableType'] ?? -1) === 3;
        }

        $previous = $this->ReadAttributeInteger('LastExternalScannerVariableID');
        if ($previous > 0 && (!$valid || $previous !== $current)) {
            @$this->UnregisterMessage($previous, VM_UPDATE);
            @$this->UnregisterReference($previous);
        }

        if ($valid) {
            $this->RegisterMessage($current, VM_UPDATE);
            $this->RegisterReference($current);
            $this->WriteAttributeInteger('LastExternalScannerVariableID', $current);
            return;
        }

        if ($current > 0) {
            $this->SendDebug('ExternalScanner', 'Configured variable is not a valid string variable: ' . $current, 0);
        }
        $this->WriteAttributeInteger('LastExternalScannerVariableID', 0);
    }

    private function HandleExternalScannerUpdate(int $SenderID): void
    {
        if ($SenderID <= 0 || !@IPS_VariableExists($SenderID)) {
            return;
        }

        $ean = trim((string)GetValue($SenderID));
        if ($ean === '') {
            $this->SendDebug('ExternalScanner', 'Ignoring empty scanner value', 0);
            return;
        }
        if (!preg_match('/^\d{8,14}$/', $ean)) {
            $this->SendDebug('ExternalScanner', 'Ignoring invalid EAN: ' . $ean, 0);
            return;
        }

        $this->SendDebug('ExternalScanner', 'Processing EAN: ' . $ean, 0);
        $product = $this->LookupBarcodeProduct($ean);
        if ($product === null) {
            $this->SendDebug('ExternalScanner', 'Product not found for EAN: ' . $ean, 0);
            return;
        }

        $added = $this->AddScannedItemInternal(
            (string)($product['name'] ?? ''),
            (string)($product['category'] ?? ''),
            '1',
            (string)($product['price'] ?? ''),
            (string)($product['listingId'] ?? ''),
            (string)($product['imageUrl'] ?? ''),
            (string)($product['genericName'] ?? '')
        );

        $this->SendDebug(
            'ExternalScanner',
            ($added ? 'Added product for EAN ' : 'Could not add product for EAN ') . $ean . ': ' . (string)($product['name'] ?? ''),
            0
        );
    }

    private function LookupBarcodeProduct(string $ean): ?array
    {
        $product = $this->LookupBarcodeOpenFoodFacts($ean);
        if ($product !== null) {
            return $product;
        }

        return $this->LookupBarcodeOpenGtinDb($ean);
    }

    private function LookupBarcodeOpenFoodFacts(string $ean): ?array
    {
        $url = 'https://de.openfoodfacts.org/api/v2/product/' . rawurlencode($ean) . '.json?lc=de';
        $body = $this->FetchBarcodeLookupUrl($url, 'OpenFoodFacts');
        if ($body === null) {
            return null;
        }

        $data = json_decode($body, true);
        if (!is_array($data) || (int)($data['status'] ?? 0) !== 1 || !is_array($data['product'] ?? null)) {
            return null;
        }

        $product = $data['product'];
        $name = trim((string)($product['product_name_de'] ?? ''));
        if ($name === '') {
            $name = trim((string)($product['product_name'] ?? ''));
        }
        if ($name === '') {
            $name = trim((string)($product['brands'] ?? ''));
        }
        if ($name === '') {
            return null;
        }

        // Marke voranstellen, wenn sie nicht schon im Titel steht ("Bunte
        // Schnecken" + brands "Haribo" → "Haribo Bunte Schnecken"): so greifen
        // Marken-Bild und Marken-Vokabular. Spiegel: OpenFoodFactsLookup (App).
        $brand = trim((string)explode(',', (string)($product['brands'] ?? ''))[0]);
        if ($brand !== '' && mb_stripos($name, $brand) === false) {
            $name = $brand . ' ' . $name;
        }

        // 1) Kanonische Taxonomie-Tags (sprachunabhängig, spezifischster zuletzt)
        $tags = is_array($product['categories_tags'] ?? null) ? $product['categories_tags'] : [];
        $category = $this->MapOffCategoryTags($tags);

        // 2) Fallback: Freitext-Kategorien gegen lokale Namen
        if ($category === '') {
            $categories = [];
            foreach (explode(',', (string)($product['categories'] ?? '')) as $hint) {
                $hint = trim($hint);
                if ($hint !== '') {
                    $categories[] = $hint;
                }
            }
            $category = $this->MatchLocalCategory($categories);
        }

        $generic = trim((string)($product['generic_name_de'] ?? ''));
        if ($generic === '') {
            $generic = trim((string)($product['generic_name'] ?? ''));
        }

        return [
            'name'        => $name,
            'category'    => $category,
            'price'       => '',
            'listingId'   => '',
            'imageUrl'    => '',
            'genericName' => $generic,
        ];
    }

    /**
     * Open-Food-Facts-Taxonomie-Tags (categories_tags) auf lokale Kategorien
     * mappen — spezifischster Tag gewinnt, alles Gefrorene geht in Tiefkühl.
     * Spiegel von OFFCategoryMapper in der SymDo-App — synchron halten!
     */
    private function MapOffCategoryTags(array $tags): string
    {
        $map = [
            'en:dairies' => 'Milch & Käse', 'en:milks' => 'Milch & Käse',
            'en:cheeses' => 'Milch & Käse', 'en:butters' => 'Milch & Käse',
            'en:salted-butters' => 'Milch & Käse', 'en:unsalted-butters' => 'Milch & Käse',
            'en:yogurts' => 'Milch & Käse', 'en:creams' => 'Milch & Käse',
            'en:quarks' => 'Milch & Käse', 'en:margarines' => 'Milch & Käse',
            'en:eggs' => 'Milch & Käse', 'en:fermented-milk-products' => 'Milch & Käse',
            'en:desserts' => 'Milch & Käse', 'en:dairy-desserts' => 'Milch & Käse',
            'en:plant-based-milk-alternatives' => 'Milch & Käse',
            'en:fruits' => 'Obst & Gemüse', 'en:vegetables' => 'Obst & Gemüse',
            'en:fresh-fruits' => 'Obst & Gemüse', 'en:fresh-vegetables' => 'Obst & Gemüse',
            'en:fruits-and-vegetables-based-foods' => 'Obst & Gemüse',
            'en:salads' => 'Obst & Gemüse', 'en:mushrooms' => 'Obst & Gemüse',
            'en:herbs' => 'Obst & Gemüse', 'en:potatoes' => 'Obst & Gemüse',
            'en:breads' => 'Backwaren', 'en:pastries' => 'Backwaren',
            'en:viennoiseries' => 'Backwaren', 'en:cakes' => 'Backwaren',
            'en:buns' => 'Backwaren', 'en:flours' => 'Backwaren',
            'en:crispbreads' => 'Backwaren', 'en:rusks' => 'Backwaren',
            'en:meats' => 'Fleisch & Wurst', 'en:sausages' => 'Fleisch & Wurst',
            'en:poultries' => 'Fleisch & Wurst', 'en:hams' => 'Fleisch & Wurst',
            'en:cold-cuts' => 'Fleisch & Wurst', 'en:fishes' => 'Fleisch & Wurst',
            'en:seafood' => 'Fleisch & Wurst', 'en:fish-and-meat-and-eggs' => 'Fleisch & Wurst',
            'en:meat-alternatives' => 'Fleisch & Wurst', 'en:tofu' => 'Fleisch & Wurst',
            'en:ice-creams-and-sorbets' => 'Tiefkühl', 'en:ice-creams' => 'Tiefkühl',
            'en:beverages' => 'Getränke', 'en:waters' => 'Getränke',
            'en:sodas' => 'Getränke', 'en:juices' => 'Getränke',
            'en:fruit-juices' => 'Getränke', 'en:carbonated-drinks' => 'Getränke',
            'en:beers' => 'Getränke', 'en:wines' => 'Getränke',
            'en:coffees' => 'Getränke', 'en:teas' => 'Getränke',
            'en:iced-teas' => 'Getränke', 'en:energy-drinks' => 'Getränke',
            'en:plant-based-beverages' => 'Getränke', 'en:syrups' => 'Getränke',
            'en:alcoholic-beverages' => 'Getränke', 'en:hot-beverages' => 'Getränke',
            'en:snacks' => 'Snacks & Süßes', 'en:sweet-snacks' => 'Snacks & Süßes',
            'en:salty-snacks' => 'Snacks & Süßes', 'en:chocolates' => 'Snacks & Süßes',
            'en:candies' => 'Snacks & Süßes', 'en:biscuits' => 'Snacks & Süßes',
            'en:biscuits-and-cakes' => 'Snacks & Süßes', 'en:crisps' => 'Snacks & Süßes',
            'en:chips-and-fries' => 'Snacks & Süßes', 'en:confectioneries' => 'Snacks & Süßes',
            'en:chewing-gum' => 'Snacks & Süßes', 'en:nuts' => 'Snacks & Süßes',
            'en:bars' => 'Snacks & Süßes',
            'en:canned-foods' => 'Konserven & Trocken', 'en:pastas' => 'Konserven & Trocken',
            'en:rices' => 'Konserven & Trocken', 'en:cereals-and-potatoes' => 'Konserven & Trocken',
            'en:breakfast-cereals' => 'Konserven & Trocken', 'en:cereals-and-their-products' => 'Konserven & Trocken',
            'en:sauces' => 'Konserven & Trocken', 'en:condiments' => 'Konserven & Trocken',
            'en:spices' => 'Konserven & Trocken', 'en:spreads' => 'Konserven & Trocken',
            'en:sweet-spreads' => 'Konserven & Trocken', 'en:honeys' => 'Konserven & Trocken',
            'en:jams' => 'Konserven & Trocken', 'en:vinegars' => 'Konserven & Trocken',
            'en:oils' => 'Konserven & Trocken', 'en:vegetable-oils' => 'Konserven & Trocken',
            'en:legumes' => 'Konserven & Trocken', 'en:soups' => 'Konserven & Trocken',
            'en:mustards' => 'Konserven & Trocken', 'en:ketchup' => 'Konserven & Trocken',
            'en:mayonnaises' => 'Konserven & Trocken', 'en:sugars' => 'Konserven & Trocken',
            'en:baking-supplies' => 'Konserven & Trocken',
            'en:baby-foods' => 'Baby & Tier', 'en:infant-formulas' => 'Baby & Tier',
            'en:pet-food' => 'Baby & Tier', 'en:dog-food' => 'Baby & Tier',
            'en:cat-food' => 'Baby & Tier',
        ];
        foreach (array_reverse($tags) as $tag) {
            $key = mb_strtolower(trim((string)$tag));
            if (str_starts_with($key, 'en:frozen')) {
                return 'Tiefkühl';
            }
            if (isset($map[$key])) {
                return $map[$key];
            }
        }
        return '';
    }

    private function LookupBarcodeOpenGtinDb(string $ean): ?array
    {
        $url = 'https://opengtindb.org/api.php?ean=' . rawurlencode($ean) . '&cmd=query&queryid=400000000';
        $body = $this->FetchBarcodeLookupUrl($url, 'OpenGTINDB');
        if ($body === null) {
            return null;
        }
        // OpenGTINDB antwortet in ISO-8859-1. Umgeschrieben wird das inzwischen in
        // FetchBarcodeLookupUrl fuer JEDE Quelle — hier bleibt nichts zu tun.

        $flat = trim(str_replace('---', ' ', preg_replace('/\r?\n/', ' ', $body) ?? $body));
        if ($flat === '') {
            return null;
        }

        $parsed = [];
        if (preg_match_all('/([a-z_]+)=([\s\S]*?)(?=\s+[a-z_]+=|$)/i', $flat, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $parsed[mb_strtolower($match[1])] = trim($match[2]);
            }
        }

        if (($parsed['error'] ?? '') !== '0') {
            return null;
        }

        $name = trim((string)($parsed['detailname'] ?? ''));
        if ($name === '') {
            $name = trim((string)($parsed['name'] ?? ''));
        }
        if ($name === '') {
            return null;
        }

        $categories = array_values(array_filter([
            trim((string)($parsed['maincat'] ?? '')),
            trim((string)($parsed['subcat'] ?? '')),
        ], static fn(string $value): bool => $value !== ''));

        return [
            'name'      => $name,
            'category'  => $this->MatchLocalCategory($categories),
            'price'     => '',
            'listingId' => '',
            'imageUrl'  => '',
        ];
    }

    private function FetchBarcodeLookupUrl(string $url, string $source): ?string
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        curl_setopt($ch, CURLOPT_USERAGENT, 'ShoppingList IP-Symcon Barcode Lookup');

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $httpCode >= 400) {
            $this->SendDebug('ExternalScanner', $source . ' lookup failed: ' . ($error !== '' ? $error : 'HTTP ' . $httpCode), 0);
            return null;
        }

        return $this->AsUtf8((string)$body);
    }

    /**
     * Fremden Text nach UTF-8 bringen.
     *
     * Symcon hat bis zur C++-Fassung ungueltiges UTF-8 stillschweigend geduldet
     * (Schalter „CompatibilitySloppyUTF8", da fuer die Vertraeglichkeit mit 3.4).
     * Die Rust-Fassung tut das nicht mehr — und schon vorher gab json_encode bei
     * einem einzigen falschen Byte `false` zurueck, was die ganze Liste leerte.
     *
     * Gueltiges UTF-8 bleibt unangetastet; alles andere wird als ISO-8859-1
     * gelesen. Das ist die richtige Annahme fuer die Quellen, die uns betreffen
     * (OpenGTINDB antwortet so), und es kann nicht scheitern: in ISO-8859-1 ist
     * jedes Byte ein gueltiges Zeichen.
     */
    private function AsUtf8(string $text): string
    {
        if ($text === '' || mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }
        return (string)mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
    }

    private function MatchLocalCategory(array $categoryHints): string
    {
        $hints = [];
        foreach ($categoryHints as $hint) {
            $hint = trim((string)$hint);
            if ($hint !== '') {
                $hints[] = $hint;
            }
        }
        if (count($hints) === 0) {
            return '';
        }

        $local = [];
        foreach ($this->GetCategoryOrderFlat() as $category) {
            $category = trim((string)$category);
            if ($category !== '') {
                $local[$category] = $category;
            }
        }
        foreach ($this->LoadItems() as $item) {
            $category = trim((string)($item['category'] ?? ''));
            if ($category !== '') {
                $local[$category] = $category;
            }
        }

        if (count($local) === 0) {
            return $hints[0];
        }

        foreach ($hints as $hint) {
            $hintLower = mb_strtolower($hint);
            foreach ($local as $category) {
                if (mb_strtolower($category) === $hintLower) {
                    return $category;
                }
            }
        }

        foreach ($hints as $hint) {
            $hintLower = mb_strtolower($hint);
            foreach ($local as $category) {
                $categoryLower = mb_strtolower($category);
                if (strpos($hintLower, $categoryLower) !== false || strpos($categoryLower, $hintLower) !== false) {
                    return $category;
                }
            }
        }

        return '';
    }

    private function ExtApiHttpGet(string $url, array $extraHeaders, string $certPath, string $keyPath, array $config, array $vars = []): array
    {
        return $this->ExtApiHttpRequest('GET', $url, null, $extraHeaders, $certPath, $keyPath, $config, $vars);
    }

    private function ExtApiHttpRequest(string $method, string $url, $body, array $extraHeaders, string $certPath, string $keyPath, array $config, array $vars = []): array
    {
        $userAgents = $config['userAgents'] ?? [];
        if (is_array($userAgents) && count($userAgents) > 0) {
            $vars['ua'] = (string)$userAgents[array_rand($userAgents)];
        } elseif (!isset($vars['ua'])) {
            $vars['ua'] = '';
        }
        if (!isset($vars['correlationId'])) {
            $vars['correlationId'] = bin2hex(random_bytes(16));
        }
        if (!isset($vars['rdfa']) || trim((string)$vars['rdfa']) === '') {
            $vars['rdfa'] = $this->ResolveRdfaId($config);
        }

        $configHeaders = $config['headers'] ?? [];
        $headers = [];
        if (is_array($configHeaders)) {
            foreach ($configHeaders as $name => $value) {
                $value = $this->InterpolatePlaceholders($value, $vars);
                $headers[] = $name . ': ' . (string)$value;
            }
        }
        $headers = array_merge($headers, $extraHeaders);

        // Inject rolling REWE gateway token (rd-gateway-token) if previously captured
        $gwToken = $this->ReadAttributeString('ExtApiGatewayToken');
        if ($gwToken !== '' && empty($vars['_skipGatewayToken'])) {
            $hasGw = false;
            foreach ($headers as $h) {
                if (stripos($h, 'rd-gateway-token:') === 0) {
                    $hasGw = true;
                    break;
                }
            }
            if (!$hasGw) {
                $headers[] = 'rd-gateway-token: ' . $gwToken;
            }
        }

        // Inject the optional auth-info-session-id context header unless the
        // concrete request type opts out (REWE basket merge does).
        if (empty($vars['_skipSessionHeaders'])) {
            $sessionId = $this->ReadAttributeString('ExtApiSessionId');
            if ($sessionId === '') {
                $sessionId = $this->GenerateUuidV4();
                $this->WriteAttributeString('ExtApiSessionId', $sessionId);
            }
            $hasSession = false;
            foreach ($headers as $h) {
                if (stripos($h, 'auth-info-session-id:') === 0) {
                    $hasSession = true;
                    break;
                }
            }
            if (!$hasSession) {
                $headers[] = 'auth-info-session-id: ' . $sessionId;
            }
        }

        foreach ([
            'x-instana-android' => $this->ReadOrCreateUuidAttribute('ExtApiInstanaId'),
            'rdfa'              => (string)$vars['rdfa'],
            'rdtga'             => 'payment-enable-google-pay,productlist-citrusad',
        ] as $name => $value) {
            $this->AppendHeaderIfMissing($headers, $name, $value);
        }

        // Inject optional device-id headers unless the concrete request type
        // opts out. Prefer the customer_uuid claim where the gateway expects
        // the "_deviceId" GraphQL context variable.
        if (empty($vars['_skipDeviceHeaders'])) {
            $deviceId = $this->ExtractAccessTokenClaim('customer_uuid');
            if ($deviceId === '') {
                $deviceId = $this->ReadAttributeString('ExtApiDeviceId');
                if ($deviceId === '') {
                    $deviceId = $this->GenerateUuidV4();
                    $this->WriteAttributeString('ExtApiDeviceId', $deviceId);
                }
            }
            foreach (['device-id', 'rd-device-id', 'x-device-id'] as $hdr) {
                $exists = false;
                foreach ($headers as $h) {
                    if (stripos($h, $hdr . ':') === 0) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    $headers[] = $hdr . ': ' . $deviceId;
                }
            }
        }

        $ch = curl_init($url);
        if ($certPath !== '' && $keyPath !== '') {
            curl_setopt($ch, CURLOPT_SSLCERT, $certPath);
            curl_setopt($ch, CURLOPT_SSLKEY, $keyPath);
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));

        // Send stored cookies (host-scoped jar)
        $host = parse_url($url, PHP_URL_HOST) ?: '';
        $cookieHeader = $this->BuildCookieHeader($host);
        if ($cookieHeader !== '') {
            $hasCookie = false;
            foreach ($headers as $h) {
                if (stripos($h, 'cookie:') === 0) {
                    $hasCookie = true;
                    break;
                }
            }
            if (!$hasCookie) {
                $headers[] = 'Cookie: ' . $cookieHeader;
            }
        }

        // Capture set-rd-gateway-token + Set-Cookie from response
        $capturedGwToken = '';
        $capturedCookies = [];
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $headerLine) use (&$capturedGwToken, &$capturedCookies) {
            $len = strlen($headerLine);
            if (stripos($headerLine, 'set-rd-gateway-token:') === 0) {
                $capturedGwToken = trim(substr($headerLine, strlen('set-rd-gateway-token:')));
            } elseif (stripos($headerLine, 'set-cookie:') === 0) {
                $capturedCookies[] = trim(substr($headerLine, strlen('set-cookie:')));
            }
            return $len;
        });

        if ($body !== null) {
            $payload = is_array($body) ? json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : (string)$body;
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            $hasContentType = false;
            foreach ($headers as $h) {
                if (stripos($h, 'content-type:') === 0) {
                    $hasContentType = true;
                    break;
                }
            }
            if (!$hasContentType) {
                $headers[] = 'Content-Type: application/json';
            }
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        // Redact sensitive tokens before logging
        $logHeaders = array_map(static function (string $h): string {
            $h = preg_replace('/(Authorization:\s*Bearer\s+)\S+/i', '$1***redacted***', $h) ?? $h;
            $h = preg_replace('/(rd-gateway-token:\s*)\S+/i', '$1***redacted***', $h) ?? $h;
            return $h;
        }, $headers);
        $this->SendDebug('ExtAPI', strtoupper($method) . ' ' . $url . ' | headers: ' . implode(' | ', $logHeaders), 0);

        $responseBody = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($capturedGwToken !== '') {
            $this->WriteAttributeString('ExtApiGatewayToken', $capturedGwToken);
            $this->SendDebug('ExtAPI', 'gateway token refreshed (' . strlen($capturedGwToken) . ' bytes)', 0);
        }
        if (!empty($capturedCookies) && $host !== '') {
            $this->StoreCookies($host, $capturedCookies);
        }

        if ($responseBody === false) {
            $this->SendDebug('ExtAPI', 'cURL error: ' . $error, 0);
            return ['error' => 'request failed'];
        }
        if ($httpCode >= 400) {
            $this->SendDebug('ExtAPI', 'HTTP ' . $httpCode . ': ' . $this->BuildResponseLogPreview($url, (string)$responseBody, 200), 0);
            return ['error' => 'HTTP ' . $httpCode];
        }
        $this->SendDebug('ExtAPI', 'HTTP ' . $httpCode . ' OK (' . strlen($responseBody) . ' bytes): ' . $this->BuildResponseLogPreview($url, (string)$responseBody, 400), 0);
        $data = json_decode($responseBody, true);
        if (!is_array($data)) {
            $this->SendDebug('ExtAPI', 'JSON decode failed', 0);
            return ['error' => 'invalid response'];
        }
        if (!empty($data['errors']) && is_array($data['errors'])) {
            $message = $this->SummarizeGraphQlError($data['errors']);
            $this->SendDebug('ExtAPI', 'GraphQL error: ' . $message, 0);
            return ['error' => $message, 'data' => $data];
        }
        return ['data' => $data];
    }

    private function AppendHeaderIfMissing(array &$headers, string $name, string $value): void
    {
        if ($value === '') {
            return;
        }
        foreach ($headers as $h) {
            if (stripos($h, $name . ':') === 0) {
                return;
            }
        }
        $headers[] = $name . ': ' . $value;
    }

    private function RemoveHeaders(array $headers, array $names): array
    {
        $omit = [];
        foreach ($names as $name) {
            $name = strtolower(trim((string)$name));
            if ($name !== '') {
                $omit[$name] = true;
            }
        }
        if (empty($omit)) {
            return $headers;
        }

        $filtered = [];
        foreach ($headers as $header) {
            $headerName = strtolower(trim(strtok((string)$header, ':') ?: ''));
            if ($headerName === '' || !isset($omit[$headerName])) {
                $filtered[] = $header;
            }
        }
        return $filtered;
    }

    private function BuildResponseLogPreview(string $url, string $responseBody, int $maxLength): string
    {
        if ($this->ShouldRedactResponseBodyForDebug($url)) {
            return '[redacted]';
        }
        return substr($responseBody, 0, $maxLength);
    }

    private function ShouldRedactResponseBodyForDebug(string $url): bool
    {
        $path = strtolower((string)(parse_url($url, PHP_URL_PATH) ?: ''));
        return $path === '/api/customers' || strpos($path, '/api/customers/') === 0;
    }

    private function SummarizeGraphQlError(array $errors): string
    {
        $first = $errors[0] ?? null;
        if (!is_array($first)) {
            return 'GraphQL error';
        }
        $message = trim((string)($first['message'] ?? 'GraphQL error'));
        if ($message === '') {
            $message = 'GraphQL error';
        }
        $extensions = $first['extensions'] ?? [];
        if (is_array($extensions)) {
            if (!empty($extensions['statusCode'])) {
                $message .= ' (HTTP ' . (string)$extensions['statusCode'] . ')';
            } elseif (!empty($extensions['classification'])) {
                $message .= ' (' . (string)$extensions['classification'] . ')';
            }
        }
        return $message;
    }

    private function GenerateUuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private function ReadOrCreateUuidAttribute(string $attribute): string
    {
        $value = trim($this->ReadAttributeString($attribute));
        if ($value === '') {
            $value = $this->GenerateUuidV4();
            $this->WriteAttributeString($attribute, $value);
        }
        return $value;
    }

    private function BuildCookieHeader(string $host): string
    {
        $jar = json_decode($this->ReadAttributeString('ExtApiCookies'), true);
        if (!is_array($jar) || !isset($jar[$host]) || !is_array($jar[$host])) {
            return '';
        }
        $parts = [];
        foreach ($jar[$host] as $name => $value) {
            $parts[] = $name . '=' . $value;
        }
        return implode('; ', $parts);
    }

    private function StoreCookies(string $host, array $setCookieLines): void
    {
        $jar = json_decode($this->ReadAttributeString('ExtApiCookies'), true);
        if (!is_array($jar)) {
            $jar = [];
        }
        if (!isset($jar[$host]) || !is_array($jar[$host])) {
            $jar[$host] = [];
        }
        foreach ($setCookieLines as $line) {
            $first = explode(';', $line, 2)[0] ?? '';
            $eq = strpos($first, '=');
            if ($eq === false) {
                continue;
            }
            $name  = trim(substr($first, 0, $eq));
            $value = trim(substr($first, $eq + 1));
            if ($name === '') {
                continue;
            }
            $jar[$host][$name] = $value;
        }
        $this->WriteAttributeString('ExtApiCookies', json_encode($jar, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function ExtApiLookup(string $ean, string $marketId, string $zip, string $certPath, string $keyPath, array $config): array
    {
        $baseUrl     = (string)($config['baseUrl'] ?? '');
        $productPath = (string)($config['productPath'] ?? '');
        if ($baseUrl === '' || $productPath === '') {
            return ['error' => 'invalid provider config'];
        }

        $vars = [
            'ean'      => $ean,
            'zip'      => $zip,
            'marketId' => $marketId,
        ];

        $queryTpl = $config['productQuery'] ?? [];
        $query    = $this->InterpolatePlaceholders($queryTpl, $vars);

        $url = rtrim($baseUrl, '/') . $productPath;
        if (is_array($query) && count($query) > 0) {
            $url .= '?' . http_build_query($query);
        }

        $extraHeaders = [];
        $productHeaders = $config['productHeaders'] ?? [];
        if (is_array($productHeaders)) {
            foreach ($productHeaders as $name => $value) {
                $value = $this->InterpolatePlaceholders($value, $vars);
                $extraHeaders[] = $name . ': ' . (string)$value;
            }
        }

        $result = $this->ExtApiHttpGet($url, $extraHeaders, $certPath, $keyPath, $config, $vars);
        if (isset($result['error'])) {
            return $result;
        }
        $data = $result['data'];

        $paths    = $config['responsePaths'] ?? [];
        $products = $this->ResolveJsonPath($data, $paths['products'] ?? []);
        if (!is_array($products) || count($products) === 0) {
            return ['error' => 'not found'];
        }

        $product = $products[0];
        if (!is_array($product)) {
            return ['error' => 'not found'];
        }

        $name  = (string)$this->ResolveJsonPath($product, $paths['productName'] ?? '');
        $brand = (string)$this->ResolveJsonPath($product, $paths['productBrand'] ?? '');

        if ($name === '') {
            return ['error' => 'not found'];
        }

        // Collect category hints from provider's own category metadata
        $catHints = [];
        $catFields = $paths['productCategoryFields'] ?? [];
        if (is_string($catFields)) {
            $catFields = [$catFields];
        }
        if (is_array($catFields)) {
            foreach ($catFields as $field) {
                if (!empty($product[$field]) && is_array($product[$field])) {
                    array_walk_recursive($product[$field], function ($v) use (&$catHints) {
                        if (is_string($v) && trim($v) !== '') {
                            $catHints[] = $v;
                        }
                    });
                }
            }
        }

        // Category: derive from product name + brand + category hints via
        // keyword/brand map. Return empty on Miscellaneous fallback so the
        // client-side grouping takes over.
        $misc     = $this->Translate('Miscellaneous');
        $hint     = trim($name . ' ' . $brand . ' ' . implode(' ', $catHints));
        $category = $this->LookupCategory($hint);
        $this->SendDebug('ExtAPI', 'hint=' . $hint . ' => ' . $category, 0);
        if ($category === $misc || $category === 'Miscellaneous') {
            $category = '';
        }

        // Extract price if enabled
        $price = '';
        if ($this->ReadPropertyBoolean('ExtApiShowPrice')) {
            $rawPrice = $this->ResolveJsonPath($product, $paths['productPriceCents'] ?? '');
            if (is_numeric($rawPrice) && (float)$rawPrice > 0) {
                $divisor  = (float)($config['priceDivisor'] ?? 1);
                $decimals = (int)($config['priceDecimals'] ?? 2);
                $decSep   = (string)($config['priceDecimalSeparator'] ?? '.');
                $thouSep  = (string)($config['priceThousandsSeparator'] ?? ',');
                $suffix   = (string)($config['priceSuffix'] ?? '');
                $value    = $divisor > 0 ? ((float)$rawPrice / $divisor) : (float)$rawPrice;
                $price    = number_format($value, $decimals, $decSep, $thouSep) . $suffix;
            }
        }

        // Listing ID for cart operations (REWE uses listing.listingId)
        $listingPath = $paths['productListingId'] ?? ['listing.listingId', 'listing.id', 'listingId'];
        $listingId   = (string)$this->ResolveJsonPath($product, $listingPath);

        $this->SendDebug('ExtAPI', 'Found: ' . $name . ' (cat: ' . $category . ', price: ' . $price . ', listingId: ' . $listingId . ')', 0);

        // Download and cache product image if available
        $imageUrl = (string)$this->ResolveJsonPath($product, $paths['productImageUrl'] ?? '');
        $this->SendDebug('ExtAPI', 'Raw imageUrl from API: ' . $imageUrl, 0);
        $localImagePath = '';
        if ($imageUrl !== '') {
            $localImagePath = $this->DownloadApiImage($imageUrl, $ean, $config);
        }

        return [
            'name'      => $name,
            'category'  => $category,
            'price'     => $price,
            'listingId' => $listingId,
            'imageUrl'  => $localImagePath,
        ];
    }

    private function LoadExtApiConfig(): ?array
    {
        $raw = trim($this->ReadPropertyString('ExtApiConfig'));
        if ($raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $this->SendDebug('ExtAPI', 'Invalid provider config JSON', 0);
            return null;
        }
        return $data;
    }

    private function DownloadApiImage(string $imageUrl, string $ean, array $config): string
    {
        if ($ean === '') {
            return '';
        }

        $imageDir = __DIR__ . '/assets/api-images';
        if (!is_dir($imageDir)) {
            mkdir($imageDir, 0755, true);
        }

        $filename = $ean . '.webp';
        $localPath = $imageDir . '/' . $filename;

        if (file_exists($localPath)) {
            $this->SendDebug('ExtAPI', 'Image already cached: api-images/' . $filename, 0);
            return 'api-images/' . $filename;
        }

        // Check if local image already exists for this EAN (avoid unnecessary download)
        $localImages = $this->GetAvailableProductImages();
        if (isset($localImages[$ean])) {
            $this->SendDebug('ExtAPI', 'Local image already exists for EAN ' . $ean . ': ' . $localImages[$ean], 0);
            return ''; // Return empty to let frontend use local image
        }

        $template = (string)($config['imageUrlTemplate'] ?? '');
        $format = (string)($config['imageFormat'] ?? 'webp');
        $quality = (int)($config['imageQuality'] ?? 80);
        $resize = (int)($config['imageResize'] ?? 100);

        if ($template !== '') {
            $imageUrl = str_replace(
                ['{url}', '{format}', '{quality}', '{resize}'],
                [$imageUrl, $format, $quality, $resize],
                $template
            );
        }

        $this->SendDebug('ExtAPI', 'Downloading image from: ' . $imageUrl, 0);

        $ch = curl_init($imageUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        $data = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($data !== false && $httpCode === 200 && strlen($data) > 0) {
            file_put_contents($localPath, $data);
            $this->SendDebug('ExtAPI', 'Image downloaded and cached: api-images/' . $filename, 0);
            return 'api-images/' . $filename;
        }

        $this->SendDebug('ExtAPI', 'Image download failed: ' . ($error ?: 'HTTP ' . $httpCode), 0);
        return '';
    }

    // ---------------------------------------------------------------------
    //  OAuth2 PKCE Login + Cart bulk-add
    // ---------------------------------------------------------------------

    public function StartLogin(): string
    {
        $config = $this->LoadExtApiConfig();
        if (!is_array($config)) {
            $this->UpdateFormField('ExtApiLoginInfo', 'caption', $this->Translate('Invalid provider config'));
            return '';
        }
        $auth = $config['auth'] ?? null;
        $missing = [];
        if (!is_array($auth)) {
            $missing[] = 'auth';
        } else {
            foreach (['authorizationUrl', 'tokenUrl', 'clientId'] as $k) {
                if (empty($auth[$k])) {
                    $missing[] = 'auth.' . $k;
                }
            }
        }
        if (!empty($missing)) {
            $this->UpdateFormField('ExtApiLoginInfo', 'caption',
                $this->Translate('OAuth not configured') . ': ' . implode(', ', $missing));
            return '';
        }

        [$verifier, $challenge] = $this->GeneratePkce();
        $state = bin2hex(random_bytes(16));
        $this->WriteAttributeString('ExtApiPkceVerifier', $verifier);
        $this->WriteAttributeString('ExtApiPkceState', $state);

        $redirectUri = $this->GetOAuthRedirectUri($config);
        $params = [
            'response_type'         => 'code',
            'client_id'             => (string)$auth['clientId'],
            'redirect_uri'          => $redirectUri,
            'scope'                 => (string)($auth['scope'] ?? 'openid'),
            'state'                 => $state,
            'code_challenge'        => $challenge,
            'code_challenge_method' => (string)($auth['codeChallengeMethod'] ?? 'S256'),
        ];
        $extra = $auth['extraAuthParams'] ?? [];
        if (is_array($extra)) {
            $params = array_merge($params, $extra);
        }
        $url = (string)$auth['authorizationUrl'];
        $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($params);

        // Determine flow: HTTPS redirect → automatic, custom scheme → manual paste required
        $isCustomScheme = !preg_match('/^https?:\/\//i', $redirectUri);
        $info = $isCustomScheme
            ? $this->Translate('Open URL in browser, log in, then copy the redirect URL (de.rewe.app://…) into the field below and click "Exchange code".')
            : $this->Translate('Open URL in browser to log in. Redirect happens automatically.');

        $this->UpdateFormField('ExtApiLoginInfo', 'caption', $info);
        $this->UpdateFormField('ExtApiLoginUrl', 'caption', $url);
        $this->UpdateFormField('ExtApiLoginUrl', 'visible', true);
        $this->UpdateFormField('ExtApiRedirectPasteRow', 'visible', $isCustomScheme);
        return $url;
    }

    public function ExchangeCode(string $RedirectUrl): void
    {
        $url = trim($RedirectUrl);
        if ($url === '') {
            $this->UpdateFormField('ExtApiLoginInfo', 'caption', $this->Translate('Redirect URL is empty'));
            return;
        }

        $code  = '';
        $state = '';
        $query = '';
        $qpos  = strpos($url, '?');
        if ($qpos !== false) {
            $query = substr($url, $qpos + 1);
        } else {
            $query = $url;
        }
        $parts = [];
        parse_str($query, $parts);
        $code  = (string)($parts['code']  ?? '');
        $state = (string)($parts['state'] ?? '');

        if ($code === '') {
            $this->UpdateFormField('ExtApiLoginInfo', 'caption', $this->Translate('No "code" parameter found in URL'));
            return;
        }
        $expectedState = $this->ReadAttributeString('ExtApiPkceState');
        if ($state !== '' && $expectedState !== '' && !hash_equals($expectedState, $state)) {
            $this->UpdateFormField('ExtApiLoginInfo', 'caption', $this->Translate('State mismatch – please start login again'));
            return;
        }

        $config = $this->LoadExtApiConfig();
        $auth   = is_array($config) ? ($config['auth'] ?? null) : null;
        if (!is_array($auth) || empty($auth['tokenUrl']) || empty($auth['clientId'])) {
            $this->UpdateFormField('ExtApiLoginInfo', 'caption', $this->Translate('OAuth not configured'));
            return;
        }

        $verifier = $this->ReadAttributeString('ExtApiPkceVerifier');
        $body = http_build_query([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $this->GetOAuthRedirectUri($config),
            'client_id'     => (string)$auth['clientId'],
            'code_verifier' => $verifier,
        ]);
        $token = $this->TokenRequest((string)$auth['tokenUrl'], $body);
        $this->WriteAttributeString('ExtApiPkceVerifier', '');
        $this->WriteAttributeString('ExtApiPkceState', '');

        if ($token === null) {
            $this->UpdateFormField('ExtApiLoginInfo', 'caption', $this->Translate('Token exchange failed – check debug log'));
            return;
        }
        $this->StoreTokens($token);
        $this->UpdateFormField('ExtApiLoginInfo', 'caption', $this->Translate('Logged in'));
        $this->UpdateFormField('ExtApiLoginUrl', 'visible', false);
        $this->UpdateFormField('ExtApiRedirectPasteRow', 'visible', false);
        $this->SendState();
    }

    /**
     * Diagnostic helper for REWE: queries /api/service-portfolio/{zipCode}
     * and reports which marketIDs are available for DELIVERY / PICKUP.
     */
    public function DiagnoseServicePortfolio(): void
    {
        $zip = trim($this->ReadPropertyString('ExtApiZipCode'));
        if ($zip === '') {
            $this->UpdateFormField('ExtApiPortfolioInfo', 'caption', $this->Translate('No ZIP configured'));
            return;
        }
        $config = $this->LoadExtApiConfig();
        if (!is_array($config)) {
            $this->UpdateFormField('ExtApiPortfolioInfo', 'caption', $this->Translate('Invalid provider config'));
            return;
        }
        $token = $this->EnsureAccessToken();
        if ($token === null || $token === '') {
            $this->UpdateFormField('ExtApiPortfolioInfo', 'caption', $this->Translate('Login required'));
            return;
        }
        $certPath = $this->GetExtApiCertPath();
        $keyPath  = $this->GetExtApiKeyPath();
        if (!file_exists($certPath) || !file_exists($keyPath)) {
            $this->UpdateFormField('ExtApiPortfolioInfo', 'caption', $this->Translate('Certificates missing'));
            return;
        }

        $base = (string)($config['baseUrl'] ?? 'https://mobile-clients-api.rewe.de');
        $url  = rtrim($base, '/') . '/api/service-portfolio/' . rawurlencode($zip);
        $vars = ['zip' => $zip];
        $headers = [
            'Authorization: Bearer ' . $token,
            'rd-postcode: ' . $zip,
            'rd-service-types: PICKUP,DELIVERY',
            'rd-is-pickup-station: false',
            'rd-is-lsfk: false',
            'rd-user-consent: {"conversionOptimization": 1}',
            'Accept: application/json',
        ];
        $this->SendDebug('ExtAPI', 'Diagnose service-portfolio for ZIP ' . $zip, 0);
        $result = $this->ExtApiHttpRequest('GET', $url, null, $headers, $certPath, $keyPath, $config, $vars);

        if (isset($result['error'])) {
            $msg = $this->Translate('Service portfolio request failed') . ': ' . $result['error'];
            $this->UpdateFormField('ExtApiPortfolioInfo', 'caption', $msg);
            return;
        }
        $data = $result['data'] ?? [];
        $portfolio = $this->ResolveJsonPath($data, ['data.servicePortfolio', 'servicePortfolio']);
        $delivery  = is_array($portfolio) ? ($portfolio['deliveryMarket'] ?? null) : null;
        $pickups   = is_array($portfolio) ? ($portfolio['pickupMarkets'] ?? []) : [];
        $lsfk      = is_array($portfolio) ? ($portfolio['lsfkMarkets'] ?? []) : [];
        $openLsfk  = is_array($portfolio) ? ($portfolio['openLsfkMarkets'] ?? []) : [];

        $formatMarket = static function ($m): string {
            if (!is_array($m)) {
                return '?';
            }
            return sprintf('  - %s · %s · %s',
                (string)($m['wwIdent'] ?? '?'),
                (string)($m['displayName'] ?? $m['companyName'] ?? ''),
                (string)($m['city'] ?? ''));
        };

        $lines = [];
        $lines[] = sprintf('ZIP %s:', $zip);
        if (is_array($delivery) && !empty($delivery['wwIdent'])) {
            $lines[] = sprintf('Delivery: %s (%s)', (string)$delivery['wwIdent'], (string)($delivery['displayName'] ?? $delivery['city'] ?? ''));
        } else {
            $lines[] = $this->Translate('Delivery not available for this ZIP');
        }
        $allLsfk = [];
        if (is_array($lsfk)) {
            $allLsfk = array_merge($allLsfk, $lsfk);
        }
        if (is_array($openLsfk)) {
            $allLsfk = array_merge($allLsfk, $openLsfk);
        }
        if (count($allLsfk) > 0) {
            $lines[] = sprintf('LSFK markets (shop delivery): %d', count($allLsfk));
            foreach (array_slice($allLsfk, 0, 5) as $m) {
                $lines[] = $formatMarket($m);
            }
        }
        if (is_array($pickups) && count($pickups) > 0) {
            $lines[] = sprintf('Pickup markets: %d', count($pickups));
            foreach (array_slice($pickups, 0, 5) as $m) {
                $lines[] = $formatMarket($m);
            }
        } else {
            $lines[] = $this->Translate('No pickup markets');
        }

        // Dump the full portfolio JSON for deeper inspection
        $this->SendDebug('ExtAPI', 'service-portfolio raw: ' . json_encode($portfolio, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);

        $caption = implode("\n", $lines);
        $this->SendDebug('ExtAPI', 'service-portfolio result: ' . str_replace("\n", ' | ', $caption), 0);
        $this->UpdateFormField('ExtApiPortfolioInfo', 'caption', $caption);
    }

    /**
     * Decode the JWT access token and report its claims so we can see whether
     * "device_id" / "deviceId" / similar is present.
     */
    public function DiagnoseAccessToken(): void
    {
        $token = $this->ReadAttributeString('ExtApiAccessToken');
        if ($token === '') {
            $this->UpdateFormField('ExtApiPortfolioInfo', 'caption', $this->Translate('Login required'));
            return;
        }
        $parts = explode('.', $token);
        if (count($parts) < 2) {
            $this->UpdateFormField('ExtApiPortfolioInfo', 'caption', 'Token is not a JWT');
            return;
        }
        $b64 = strtr($parts[1], '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        $payload = json_decode((string)base64_decode($b64, true), true);
        if (!is_array($payload)) {
            $this->UpdateFormField('ExtApiPortfolioInfo', 'caption', 'Failed to decode JWT payload');
            return;
        }
        // Redact obvious sensitive fields before showing
        $safe = $payload;
        foreach (['email', 'preferred_username', 'name', 'family_name', 'given_name'] as $k) {
            if (isset($safe[$k])) {
                $safe[$k] = '***';
            }
        }
        $keys = implode(', ', array_keys($payload));
        $caption = "Claims keys: " . $keys . "\n\n" . json_encode($safe, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->SendDebug('ExtAPI', 'JWT claims: ' . json_encode($safe, JSON_UNESCAPED_SLASHES), 0);
        $this->UpdateFormField('ExtApiPortfolioInfo', 'caption', $caption);
    }

    public function Logout(): void
    {
        $this->WriteAttributeString('ExtApiAccessToken', '');
        $this->WriteAttributeString('ExtApiRefreshToken', '');
        $this->WriteAttributeString('ExtApiGatewayToken', '');
        $this->WriteAttributeString('ExtApiCookies', '{}');
        $this->WriteAttributeInteger('ExtApiTokenExpires', 0);
        $this->WriteAttributeString('ExtApiBasketId', '');
        $this->WriteAttributeString('ExtApiCartMarketId', '');
        $this->WriteAttributeString('ExtApiCartContextKey', '');
        $this->UpdateFormField('ExtApiLoginInfo', 'caption', $this->Translate('Not logged in'));
        $this->SendState();
    }

    private function HandleOAuthCallback(): void
    {
        header('Content-Type: text/html; charset=utf-8');
        $code  = (string)($_GET['code'] ?? '');
        $state = (string)($_GET['state'] ?? '');
        $expectedState = $this->ReadAttributeString('ExtApiPkceState');
        if ($code === '' || $state === '' || $expectedState === '' || !hash_equals($expectedState, $state)) {
            http_response_code(400);
            echo '<html><body><p>OAuth error: invalid state</p></body></html>';
            return;
        }

        $config = $this->LoadExtApiConfig();
        $auth   = is_array($config) ? ($config['auth'] ?? null) : null;
        if (!is_array($auth) || empty($auth['tokenUrl']) || empty($auth['clientId'])) {
            http_response_code(500);
            echo '<html><body><p>OAuth error: provider config missing</p></body></html>';
            return;
        }

        $verifier = $this->ReadAttributeString('ExtApiPkceVerifier');
        $body = http_build_query([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $this->GetOAuthRedirectUri($config),
            'client_id'     => (string)$auth['clientId'],
            'code_verifier' => $verifier,
        ]);

        $token = $this->TokenRequest((string)$auth['tokenUrl'], $body);
        // Clear PKCE artifacts
        $this->WriteAttributeString('ExtApiPkceVerifier', '');
        $this->WriteAttributeString('ExtApiPkceState', '');

        if ($token === null) {
            http_response_code(500);
            echo '<html><body><p>OAuth error: token exchange failed</p></body></html>';
            return;
        }
        $this->StoreTokens($token);
        $this->SendState();

        $msg = $this->Translate('Login successful – you can close this tab');
        echo '<html><head><meta charset="utf-8"><title>OK</title></head><body style="font-family:system-ui;padding:24px"><h2>' . htmlspecialchars($msg, ENT_QUOTES) . '</h2></body></html>';
    }

    private function EnsureAccessToken(): ?string
    {
        $access  = $this->ReadAttributeString('ExtApiAccessToken');
        $expires = $this->ReadAttributeInteger('ExtApiTokenExpires');
        if ($access !== '' && $expires - 30 > time()) {
            return $access;
        }
        $refresh = $this->ReadAttributeString('ExtApiRefreshToken');
        if ($refresh === '') {
            return $access !== '' ? $access : null;
        }

        $config = $this->LoadExtApiConfig();
        $auth   = is_array($config) ? ($config['auth'] ?? null) : null;
        if (!is_array($auth) || empty($auth['tokenUrl']) || empty($auth['clientId'])) {
            return null;
        }
        $body = http_build_query([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refresh,
            'client_id'     => (string)$auth['clientId'],
        ]);
        $token = $this->TokenRequest((string)$auth['tokenUrl'], $body);
        if ($token === null) {
            $this->SendDebug('ExtAPI', 'Token refresh failed', 0);
            return null;
        }
        $this->StoreTokens($token);
        return $this->ReadAttributeString('ExtApiAccessToken');
    }

    private function TokenRequest(string $url, string $body): ?array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode >= 400) {
            $this->SendDebug('ExtAPI', 'Token endpoint HTTP ' . $httpCode, 0);
            return null;
        }
        $data = json_decode((string)$response, true);
        if (!is_array($data) || empty($data['access_token'])) {
            return null;
        }
        return $data;
    }

    private function StoreTokens(array $token): void
    {
        $this->WriteAttributeString('ExtApiAccessToken', (string)$token['access_token']);
        if (!empty($token['refresh_token'])) {
            $this->WriteAttributeString('ExtApiRefreshToken', (string)$token['refresh_token']);
        }
        $expiresIn = (int)($token['expires_in'] ?? 300);
        $this->WriteAttributeInteger('ExtApiTokenExpires', time() + $expiresIn);
    }

    private function GeneratePkce(): array
    {
        $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        return [$verifier, $challenge];
    }

    private function GetOAuthRedirectUri(?array $config = null): string
    {
        if ($config === null) {
            $config = $this->LoadExtApiConfig();
        }
        $auth = is_array($config) ? ($config['auth'] ?? []) : [];
        if (is_array($auth) && !empty($auth['redirectUri'])) {
            return (string)$auth['redirectUri'];
        }
        // The hook is registered as 'shoppinglist/assets', so callback URL is below it.
        return 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/hook/' . $this->GetAssetHookPath() . '/?a=oauth_callback';
    }

    private function HandleCartBulkHook(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        $config = $this->LoadExtApiConfig();
        if (!is_array($config) || !isset($config['cart']) || !is_array($config['cart'])) {
            echo json_encode(['error' => 'cart not configured']);
            return;
        }

        $token = $this->EnsureAccessToken();
        if ($token === null || $token === '') {
            echo json_encode(['error' => 'login required']);
            return;
        }

        $certPath = $this->GetExtApiCertPath();
        $keyPath  = $this->GetExtApiKeyPath();
        if (!file_exists($certPath) || !file_exists($keyPath)) {
            echo json_encode(['error' => 'certificates missing']);
            return;
        }

        $items = $this->LoadItems();
        $marketItems = [];
        foreach ($items as $idx => $item) {
            if (!empty($item['inCart'])) {
                continue;
            }
            if (empty($item['marketItem']) || empty($item['listingId'])) {
                continue;
            }
            $marketItems[$idx] = $item;
        }
        if (count($marketItems) === 0) {
            echo json_encode(['added' => 0, 'failed' => 0]);
            return;
        }

        $added  = 0;
        $failed = 0;
        $changed = false;
        // Read basket once before the loop; refresh ID+version after every successful add
        $basket = $this->ExtApiBasketRead($config, $token, $certPath, $keyPath);
        if ($basket === null) {
            echo json_encode(['error' => 'basket creation failed']);
            return;
        }
        foreach ($marketItems as $idx => $item) {
            $qty = $this->ParseQuantity((string)($item['amount'] ?? ''));
            $next = $this->ExtApiCartAdd(
                (string)$item['listingId'], $qty,
                (string)$basket['id'], (int)$basket['version'],
                $config, $token, $certPath, $keyPath
            );
            if ($next !== null) {
                $items[$idx]['inCart'] = true;
                $added++;
                $changed = true;
                $basket = $next; // updated id+version for next iteration
            } else {
                $failed++;
            }
        }
        if ($changed) {
            $this->SaveItems($items);
        }
        // Persist last known basket id (informational)
        if (is_array($basket) && !empty($basket['id'])) {
            $this->WriteAttributeString('ExtApiBasketId', (string)$basket['id']);
        }
        if ($added > 0 && $this->ShouldMergeCartAfterAdd($config)) {
            $merged = $this->ExtApiCartMerge($basket, $config, $token, $certPath, $keyPath);
            if (is_array($merged) && !empty($merged['id'])) {
                $basket = $merged;
                $this->WriteAttributeString('ExtApiBasketId', (string)$merged['id']);
            }
        }

        echo json_encode(['added' => $added, 'failed' => $failed]);
    }

    private function ParseQuantity(string $Amount): int
    {
        $a = trim($Amount);
        if ($a === '') {
            return 1;
        }
        if (preg_match('/^(\d+)/', $a, $m)) {
            $n = (int)$m[1];
            return $n > 0 ? $n : 1;
        }
        return 1;
    }

    /**
     * Make sure we hold a valid REWE gateway token. The mobile API issues a
     * rolling JWE via "set-rd-gateway-token" response header. The very first
     * token has to be obtained from a seed endpoint (default: /api/customers).
     */
    private function EnsureGatewayToken(array $config, string $token, string $certPath, string $keyPath): bool
    {
        if ($this->ReadAttributeString('ExtApiGatewayToken') !== '') {
            return true;
        }
        $cart = $config['cart'] ?? [];
        $seedUrl = (string)($cart['gatewayTokenSeedUrl'] ?? '');
        if ($seedUrl === '') {
            // No seed configured – proceed without token; provider may still allow it.
            return true;
        }
        $vars   = $this->BuildCartVars($config);
        $url    = (string)$this->InterpolatePlaceholders($seedUrl, $vars);
        $extra  = $this->BuildCartHeaders($config, $token, $vars);
        $this->SendDebug('ExtAPI', 'Seeding gateway token via ' . $url, 0);
        $result = $this->ExtApiHttpRequest('GET', $url, null, $extra, $certPath, $keyPath, $config, $vars);
        if (isset($result['error'])) {
            $this->SendDebug('ExtAPI', 'Gateway token seed failed: ' . $result['error'], 0);
            return false;
        }
        return $this->ReadAttributeString('ExtApiGatewayToken') !== '';
    }

    /**
     * Tell the REWE backend which market the customer has selected for
     * e-commerce. Newer basket calls also accept the market context via
     * headers, so callers decide whether a failed selection is fatal.
     */
    private function EnsureMarketSelection(array $config, string $token, string $certPath, string $keyPath): bool
    {
        $cart = $config['cart'] ?? [];
        $url  = (string)($cart['marketSelectionUrl'] ?? '');
        if ($url === '') {
            return true; // not configured – assume not needed
        }
        $vars = $this->BuildCartVars($config);
        $url  = (string)$this->InterpolatePlaceholders($url, $vars);
        $bodyTpl = $cart['marketSelectionBody'] ?? [
            'customerZipCode' => '{zip}',
            'serviceType'     => '{serviceType}',
            'wwIdent'         => '{marketId}',
            '_deviceId'       => '{deviceId}',
        ];
        $body = is_array($bodyTpl) ? $this->InterpolatePlaceholders($bodyTpl, $vars) : null;
        if (is_array($body)) {
            $deviceId = trim((string)($body['_deviceId'] ?? $body['deviceId'] ?? $vars['deviceId'] ?? ''));
            if ($deviceId !== '' && !isset($body['_deviceId'])) {
                $body['_deviceId'] = $deviceId;
            }
        }
        $extra = $this->BuildCartHeaders($config, $token, $vars);
        $this->SendDebug('ExtAPI', 'Setting customer-market-selection: ' . json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);
        $result = $this->ExtApiHttpRequest('POST', $url, $body, $extra, $certPath, $keyPath, $config, $vars);
        if (isset($result['error'])) {
            $this->SendDebug('ExtAPI', 'Market selection failed: ' . $result['error'], 0);
            return false;
        }
        $this->StoreSelectedCartMarket($result['data'] ?? [], $config);
        return true;
    }

    private function StoreSelectedCartMarket(array $data, array $config): void
    {
        $selected = trim((string)$this->ResolveJsonPath($data, [
            'data.customerMarketSelection.market.wwIdent',
            'customerMarketSelection.market.wwIdent',
        ]));
        if ($selected === '') {
            return;
        }
        $contextKey = $this->BuildCartContextKey($config);
        $configured = trim($this->ReadPropertyString('ExtApiMarketId'));
        $this->WriteAttributeString('ExtApiCartMarketId', $selected);
        $this->WriteAttributeString('ExtApiCartContextKey', $contextKey);
        if ($configured !== '' && $selected !== $configured) {
            $this->SendDebug('ExtAPI', 'Using selected cart market ' . $selected . ' instead of configured market ' . $configured, 0);
        }
    }

    private function BuildCartContextKey(array $config): string
    {
        $cart = $config['cart'] ?? [];
        return implode('|', [
            trim($this->ReadPropertyString('ExtApiMarketId')),
            trim($this->ReadPropertyString('ExtApiZipCode')),
            (string)($cart['serviceType'] ?? 'PICKUP'),
        ]);
    }

    private function ExtApiBasketRead(array $config, string $token, string $certPath, string $keyPath): ?array
    {
        if (!$this->EnsureGatewayToken($config, $token, $certPath, $keyPath)) {
            return null;
        }
        if (!$this->EnsureMarketSelection($config, $token, $certPath, $keyPath)) {
            $required = !empty(($config['cart'] ?? [])['marketSelectionRequired']);
            if ($required) {
                return null;
            }
            $this->SendDebug('ExtAPI', 'Continuing without customer-market-selection; basket headers carry market context', 0);
        }
        $cart = $config['cart'] ?? [];
        $createUrl = (string)($cart['createUrl'] ?? '');
        if ($createUrl === '') {
            return null;
        }
        $vars = $this->BuildCartVars($config);
        $url    = (string)$this->InterpolatePlaceholders($createUrl, $vars);
        $method = strtoupper((string)($cart['createMethod'] ?? 'POST'));
        $publicBasket = $this->UsePublicBasketCalls($cart);
        if ($publicBasket) {
            $vars = $this->AddPublicBasketRequestFlags($vars);
            $this->SendDebug('ExtAPI', 'Using public basket request headers', 0);
        }
        $extra  = $publicBasket
            ? $this->BuildPublicBasketHeaders($config, $vars)
            : $this->BuildCartHeaders($config, $token, $vars);

        $bodyTpl = $cart['createBodyTemplate'] ?? null;
        $body    = is_array($bodyTpl) ? $this->InterpolatePlaceholders($bodyTpl, $vars) : null;

        $result = $this->ExtApiHttpRequest($method, $url, $body, $extra, $certPath, $keyPath, $config, $vars);
        if (isset($result['error'])) {
            $this->SendDebug('ExtAPI', 'Basket read/create failed: ' . $result['error'], 0);
            return null;
        }
        return $this->ExtractBasketIdVersion($result['data'] ?? [], $cart);
    }

    /**
     * Add one listing to the basket and return the updated basket {id, version}
     * (extracted from the response). null on failure.
     */
    private function ExtApiCartAdd(string $listingId, int $quantity, string $basketId, int $basketVersion, array $config, string $token, string $certPath, string $keyPath): ?array
    {
        $cart = $config['cart'] ?? [];
        $modifyUrl = (string)($cart['modifyUrl'] ?? '');
        if ($modifyUrl === '') {
            return null;
        }
        $vars = $this->BuildCartVars($config, [
            'basketId'      => $basketId,
            'listingId'     => $listingId,
            'quantity'      => (string)$quantity,
            'basketVersion' => (string)$basketVersion,
        ]);
        $url    = (string)$this->InterpolatePlaceholders($modifyUrl, $vars);
        $method = strtoupper((string)($cart['modifyMethod'] ?? 'POST'));
        $publicBasket = $this->UsePublicBasketCalls($cart);
        if ($publicBasket) {
            $vars = $this->AddPublicBasketRequestFlags($vars);
        }
        $extra  = $publicBasket
            ? $this->BuildPublicBasketHeaders($config, $vars)
            : $this->BuildCartHeaders($config, $token, $vars);

        $bodyTpl = $cart['addBodyTemplate'] ?? null;
        $body    = is_array($bodyTpl) ? $this->InterpolatePlaceholders($bodyTpl, $vars) : null;
        // Cast numeric placeholders to int where the template still has strings
        if (is_array($body)) {
            foreach (['quantity', 'basketVersion'] as $k) {
                if (isset($body[$k]) && is_string($body[$k]) && ctype_digit($body[$k])) {
                    $body[$k] = (int)$body[$k];
                }
            }
        }

        $result = $this->ExtApiHttpRequest($method, $url, $body, $extra, $certPath, $keyPath, $config, $vars);
        if (isset($result['error'])) {
            $this->SendDebug('ExtAPI', 'Cart add failed (' . $listingId . '): ' . $result['error'], 0);
            return null;
        }
        // Best effort: response carries updated basket; fall back to existing values
        $next = $this->ExtractBasketIdVersion($result['data'] ?? [], $cart);
        if ($next === null) {
            $next = ['id' => $basketId, 'version' => $basketVersion + 1];
        }
        return $next;
    }

    private function ExtApiCartMerge(?array $basket, array $config, string $token, string $certPath, string $keyPath): ?array
    {
        $cart = $config['cart'] ?? [];
        $mergeUrl = (string)($cart['mergeUrl'] ?? '');
        if ($mergeUrl === '') {
            $baseUrl = rtrim((string)($config['baseUrl'] ?? ''), '/');
            $mergeUrl = $baseUrl !== '' ? $baseUrl . '/api/baskets/merge' : '';
        }
        if ($mergeUrl === '') {
            return null;
        }

        $vars = $this->BuildCartVars($config, [
            'basketId'      => is_array($basket) ? (string)($basket['id'] ?? '') : '',
            'basketVersion' => is_array($basket) ? (string)($basket['version'] ?? '') : '',
        ]);
        $url = (string)$this->InterpolatePlaceholders($mergeUrl, $vars);
        $method = strtoupper((string)($cart['mergeMethod'] ?? 'POST'));

        $bodyTpl = $cart['mergeBodyTemplate'] ?? [
            'serviceSelection' => [
                'serviceType' => '{serviceType}',
            ],
        ];
        $body = is_array($bodyTpl) ? $this->InterpolatePlaceholders($bodyTpl, $vars) : null;
        if (!empty($cart['refreshGatewayTokenBeforeMerge'])) {
            $this->RefreshGatewayToken($config, $token, $certPath, $keyPath, $vars);
        }
        $vars = $this->AddMergeBasketRequestFlags($cart, $vars);
        $extra = $this->BuildMergeBasketHeaders($config, $token, $vars);
        $this->SendDebug('ExtAPI', 'Merging public basket into account basket', 0);
        if ($body !== null) {
            $this->SendDebug('ExtAPI', 'Merge body: ' . json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);
        }

        $result = $this->ExtApiHttpRequest($method, $url, $body, $extra, $certPath, $keyPath, $config, $vars);
        if (isset($result['error'])) {
            $this->SendDebug('ExtAPI', 'Basket merge failed: ' . $result['error'], 0);
            return null;
        }

        return $this->ExtractBasketIdVersion($result['data'] ?? [], $cart);
    }

    private function RefreshGatewayToken(array $config, string $token, string $certPath, string $keyPath, array $vars): void
    {
        $cart = $config['cart'] ?? [];
        $seedUrl = (string)($cart['gatewayTokenSeedUrl'] ?? '');
        if ($seedUrl === '') {
            return;
        }

        $seedVars = $vars;
        unset($seedVars['basketId'], $seedVars['basketVersion']);
        $url = (string)$this->InterpolatePlaceholders($seedUrl, $seedVars);
        $extra = $this->BuildCartHeaders($config, $token, $seedVars);
        $this->SendDebug('ExtAPI', 'Refreshing gateway token before basket merge via ' . $url, 0);
        $result = $this->ExtApiHttpRequest('GET', $url, null, $extra, $certPath, $keyPath, $config, $vars);
        if (isset($result['error'])) {
            $this->SendDebug('ExtAPI', 'Gateway token refresh before merge failed: ' . $result['error'], 0);
        }
    }

    private function BuildCartVars(array $config, array $extra = []): array
    {
        $cart = $config['cart'] ?? [];
        // Prefer the customer_uuid claim from the JWT – the REWE gateway uses
        // it as the "_deviceId" context variable. A locally generated UUID is
        // unknown to the server and causes ValidationError.
        $deviceId = $this->ExtractAccessTokenClaim('customer_uuid');
        if ($deviceId === '') {
            $deviceId = $this->ReadAttributeString('ExtApiDeviceId');
            if ($deviceId === '') {
                $deviceId = $this->GenerateUuidV4();
                $this->WriteAttributeString('ExtApiDeviceId', $deviceId);
            }
        }
        $configuredMarketId = $this->ReadPropertyString('ExtApiMarketId');
        $marketId = $configuredMarketId;
        $selectedMarketId = trim($this->ReadAttributeString('ExtApiCartMarketId'));
        if ($selectedMarketId !== '' && $this->ReadAttributeString('ExtApiCartContextKey') === $this->BuildCartContextKey($config)) {
            $marketId = $selectedMarketId;
        }
        $vars = [
            'baseUrl'            => (string)($config['baseUrl'] ?? ''),
            'marketId'           => $marketId,
            'configuredMarketId' => $configuredMarketId,
            'zip'                => $this->ReadPropertyString('ExtApiZipCode'),
            'serviceType'        => (string)($cart['serviceType'] ?? 'PICKUP'),
            'deviceId'           => $deviceId,
            'rdfa'               => $this->ResolveRdfaId($config),
        ];
        return array_merge($vars, $extra);
    }

    private function ResolveRdfaId(array $config): string
    {
        $cart = $config['cart'] ?? [];
        foreach ([
            is_array($cart) ? ($cart['rdfa'] ?? '') : '',
            $config['rdfa'] ?? '',
        ] as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return $this->ReadOrCreateUuidAttribute('ExtApiRdfaId');
    }

    /**
     * Return a single claim from the current JWT access token (empty if
     * not available or token not a valid JWT).
     */
    private function ExtractAccessTokenClaim(string $claim): string
    {
        $token = $this->ReadAttributeString('ExtApiAccessToken');
        if ($token === '') {
            return '';
        }
        $parts = explode('.', $token);
        if (count($parts) < 2) {
            return '';
        }
        $b64 = strtr($parts[1], '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        $payload = json_decode((string)base64_decode($b64, true), true);
        return is_array($payload) && isset($payload[$claim]) ? (string)$payload[$claim] : '';
    }

    private function BuildCartHeaders(array $config, string $token, array $vars): array
    {
        $headers = ['Authorization: Bearer ' . $token];
        $cart = $config['cart'] ?? [];
        $extraHeaders = $cart['headers'] ?? [];
        if (is_array($extraHeaders)) {
            foreach ($extraHeaders as $name => $value) {
                $value = $this->InterpolatePlaceholders($value, $vars);
                $headers[] = $name . ': ' . (string)$value;
            }
        }
        $marketId    = (string)($vars['marketId'] ?? '');
        $zip         = (string)($vars['zip'] ?? '');
        $serviceType = (string)($vars['serviceType'] ?? '');
        foreach ([
            'rd-market-id'        => $marketId,
            'x-rd-market-id'      => $marketId,
            'rd-customer-zip'     => $zip,
            'x-rd-customer-zip'   => $zip,
            'rd-postcode'         => $zip,
            'rd-service-types'    => $serviceType,
            'x-rd-service-types'  => $serviceType,
            'rd-is-lsfk'          => 'false',
            'Accept'              => 'application/json',
        ] as $name => $value) {
            $this->AppendHeaderIfMissing($headers, $name, $value);
        }
        if (!empty($vars['basketId'])) {
            $this->AppendHeaderIfMissing($headers, 'x-rd-basket-id', (string)$vars['basketId']);
        }
        return $headers;
    }

    private function BuildMergeBasketHeaders(array $config, string $token, array $vars): array
    {
        $cart = $config['cart'] ?? [];
        $headers = $this->BuildCartHeaders($config, $token, $vars);
        $this->AppendHeaderIfMissing($headers, 'Content-Type', 'application/json; charset=UTF-8');
        if (!empty($vars['basketId'])) {
            $this->AppendHeaderIfMissing($headers, 'rd-basket-id', (string)$vars['basketId']);
            $this->AppendHeaderIfMissing($headers, 'x-rd-basket-id', (string)$vars['basketId']);
            $consent = '{"conversionOptimization": 1}';
            $this->AppendHeaderIfMissing($headers, 'rd-user-consent', $consent);
            $this->AppendHeaderIfMissing($headers, 'x-rd-user-consent', $consent);
            $this->AppendHeaderIfMissing($headers, 'rd-ecom-market-sync', '?1');
        }
        $omitHeaders = $cart['mergeOmitHeaders'] ?? ['Accept', 'rd-is-pickup-station'];
        if (is_array($omitHeaders)) {
            $headers = $this->RemoveHeaders($headers, array_map('strval', $omitHeaders));
        }
        return $headers;
    }

    private function UsePublicBasketCalls(array $cart): bool
    {
        if (array_key_exists('publicBasketCalls', $cart)) {
            return !empty($cart['publicBasketCalls']);
        }
        $createUrl = (string)($cart['createUrl'] ?? '');
        return stripos($createUrl, 'mobile-clients-api.rewe.de/api/baskets') !== false;
    }

    private function ShouldMergeCartAfterAdd(array $config): bool
    {
        $cart = $config['cart'] ?? [];
        if (!is_array($cart)) {
            return false;
        }
        if (array_key_exists('mergeAfterAdd', $cart)) {
            return !empty($cart['mergeAfterAdd']);
        }

        return false;
    }

    private function AddPublicBasketRequestFlags(array $vars): array
    {
        $vars['_skipGatewayToken'] = true;
        $vars['_skipSessionHeaders'] = true;
        $vars['_skipDeviceHeaders'] = true;
        return $vars;
    }

    private function AddMergeBasketRequestFlags(array $cart, array $vars): array
    {
        if (!array_key_exists('mergeSkipSessionHeaders', $cart) || !empty($cart['mergeSkipSessionHeaders'])) {
            $vars['_skipSessionHeaders'] = true;
        }
        if (!array_key_exists('mergeSkipDeviceHeaders', $cart) || !empty($cart['mergeSkipDeviceHeaders'])) {
            $vars['_skipDeviceHeaders'] = true;
        }
        return $vars;
    }

    private function BuildPublicBasketHeaders(array $config, array $vars): array
    {
        $headers = [];
        foreach ([
            'rd-market-id'       => (string)($vars['marketId'] ?? ''),
            'x-rd-market-id'     => (string)($vars['marketId'] ?? ''),
            'rd-customer-zip'    => (string)($vars['zip'] ?? ''),
            'x-rd-customer-zip'  => (string)($vars['zip'] ?? ''),
            'rd-postcode'        => (string)($vars['zip'] ?? ''),
            'rd-service-types'   => (string)($vars['serviceType'] ?? ''),
            'x-rd-service-types' => (string)($vars['serviceType'] ?? ''),
            'rd-is-lsfk'         => 'false',
            'Content-Type'       => 'application/json; charset=UTF-8',
        ] as $name => $value) {
            $this->AppendHeaderIfMissing($headers, $name, $value);
        }
        if (!empty($vars['basketId'])) {
            $this->AppendHeaderIfMissing($headers, 'x-rd-basket-id', (string)$vars['basketId']);
        }
        return $headers;
    }

    private function ExtractBasketIdVersion($data, array $cart): ?array
    {
        $paths   = $cart['responsePaths'] ?? [];
        $idPath  = $paths['basketId']      ?? ['data.basket.id', 'basket.id', 'id'];
        $verPath = $paths['basketVersion'] ?? ['data.basket.version', 'basket.version', 'version'];
        $id  = (string)$this->ResolveJsonPath($data, $idPath);
        $ver = $this->ResolveJsonPath($data, $verPath);
        if ($id === '') {
            $idPathStr  = is_array($idPath)  ? implode('|', $idPath)  : (string)$idPath;
            $topKeys = is_array($data) ? implode(',', array_keys($data)) : '(non-array)';
            $this->SendDebug('ExtAPI', 'basketId not found via paths [' . $idPathStr . '] - top-level keys: ' . $topKeys, 0);
            return null;
        }
        $this->SendDebug('ExtAPI', 'basket extracted: id=' . $id . ' version=' . (is_numeric($ver) ? (int)$ver : 'n/a'), 0);
        return ['id' => $id, 'version' => is_numeric($ver) ? (int)$ver : 0];
    }

    /**
     * Interpolate {key} placeholders in a scalar or (recursively) in an array
     * of scalars. Non-string scalars are returned unchanged.
     */
    private function InterpolatePlaceholders($value, array $vars)
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->InterpolatePlaceholders($v, $vars);
            }
            return $out;
        }
        if (!is_string($value) || $value === '' || strpos($value, '{') === false) {
            return $value;
        }
        return preg_replace_callback('/\{([a-zA-Z0-9_]+)\}/', function ($m) use ($vars) {
            return array_key_exists($m[1], $vars) ? (string)$vars[$m[1]] : $m[0];
        }, $value);
    }

    /**
     * Resolve a dot-notation path against a nested array. $path may be a
     * single string ("a.b.c") or an array of candidate paths (returns first hit).
     * Returns null if no path matches.
     */
    private function ResolveJsonPath($data, $path)
    {
        if (is_array($path)) {
            foreach ($path as $candidate) {
                $val = $this->ResolveJsonPath($data, $candidate);
                if ($val !== null) {
                    return $val;
                }
            }
            return null;
        }
        if (!is_string($path) || $path === '') {
            return null;
        }
        $segments = explode('.', $path);
        $cur = $data;
        foreach ($segments as $seg) {
            if (is_array($cur) && array_key_exists($seg, $cur)) {
                $cur = $cur[$seg];
            } else {
                return null;
            }
        }
        return $cur;
    }

    private function GetExtApiCertDir(): string
    {
        return IPS_GetKernelDir() . 'data/';
    }

    private function GetExtApiCertPath(): string
    {
        return $this->GetExtApiCertDir() . 'extapi_cert_' . $this->InstanceID . '.pem';
    }

    private function GetExtApiKeyPath(): string
    {
        return $this->GetExtApiCertDir() . 'extapi_key_' . $this->InstanceID . '.pem';
    }

    private function WriteExtApiCertFiles(): void
    {
        $certB64 = $this->ReadPropertyString('ExtApiCertFile');
        $keyB64  = $this->ReadPropertyString('ExtApiKeyFile');

        $dir = $this->GetExtApiCertDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $certPath = $this->GetExtApiCertPath();
        $keyPath  = $this->GetExtApiKeyPath();

        // Write or delete certificate
        if ($certB64 !== '') {
            $certData = base64_decode($certB64, true);
            if ($certData !== false) {
                file_put_contents($certPath, $certData);
            }
        } else {
            @unlink($certPath);
        }

        // Write or delete key
        if ($keyB64 !== '') {
            $keyData = base64_decode($keyB64, true);
            if ($keyData !== false) {
                file_put_contents($keyPath, $keyData);
            }
        } else {
            @unlink($keyPath);
        }
    }

    private function DeleteExtApiCertFiles(): void
    {
        @unlink($this->GetExtApiCertPath());
        @unlink($this->GetExtApiKeyPath());
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
            $decoded = self::DefaultCategoryRows();
        }

        // Symcon saves List rows as [{"category": "..."}, ...]; support both flat strings
        // (default from Create()) and row objects (written back by Symcon after form save).
        //
        // Symbol und Farbe fuellt CATEGORY_DEFAULT_STYLES auf, wo nichts gesetzt ist:
        // die Tabelle zeigt damit das eingebaute Aussehen der Standardkategorien statt
        // leerer Felder, und es ist von dort aus aenderbar. Nur die STANDARD-Kategorien
        // stehen in der Tabelle — eigene bleiben leer und heissen weiter "automatisch"
        // (Einkaufskorb, Farbe aus dem Namen gestreut).
        $categoryValues = [];
        foreach ($decoded as $entry) {
            $name  = is_string($entry) ? $entry : (string)($entry['category'] ?? '');
            $name  = trim($name);
            if ($name === '') {
                continue;
            }
            $vorgabe = self::CATEGORY_DEFAULT_STYLES[$name] ?? ['icon' => '', 'color' => -1];
            $icon    = is_array($entry) ? trim((string)($entry['icon'] ?? '')) : '';
            // -1 ist der Wert von SelectColor fuer "keine Farbe" und hier
            // "automatisch". Zeilen aus aelteren Fassungen haben keinen Schluessel;
            // isset() wuerde 0 (Schwarz) nicht von "fehlt" trennen, deshalb
            // array_key_exists.
            $color = (is_array($entry) && array_key_exists('color', $entry)) ? (int)$entry['color'] : -1;
            $categoryValues[] = [
                'category' => $name,
                'icon'     => $icon !== '' ? $icon : $vorgabe['icon'],
                'color'    => $color >= 0 ? $color : $vorgabe['color'],
            ];
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
                    'type'     => 'ExpansionPanel',
                    'caption'  => $this->Translate('Layout'),
                    'expanded' => false,
                    'items'    => [
                        [
                            'type'    => 'CheckBox',
                            'name'    => 'ShowProductImages',
                            'caption' => $this->Translate('Show product images'),
                        ],
                        [
                            'type'    => 'CheckBox',
                            'name'    => 'ShowFavoriteHeart',
                            'caption' => $this->Translate('Show favorite heart'),
                        ],
                        [
                            'type'    => 'CheckBox',
                            'name'    => 'ShowRowEditButton',
                            'caption' => $this->Translate('Show edit button'),
                        ],
                        [
                            'type'    => 'CheckBox',
                            'name'    => 'ShowRowDeleteButton',
                            'caption' => $this->Translate('Show delete button'),
                        ],
                        [
                            'type'    => 'Label',
                            'caption' => $this->Translate('Row buttons hint'),
                        ],
                    ],
                ],
                [
                    'type'     => 'ExpansionPanel',
                    'caption'  => $this->Translate('Category Order'),
                    'expanded' => false,
                    'items'    => [
                        [
                            'type'        => 'List',
                            'name'        => 'CategoryOrder',
                            'caption'     => '',
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
                                [
                                    'caption' => $this->Translate('Icon'),
                                    'name'    => 'icon',
                                    'width'   => '110px',
                                    'save'    => true,
                                    'add'     => '',
                                    'edit'    => ['type' => 'SelectIcon'],
                                ],
                                [
                                    'caption' => $this->Translate('Color'),
                                    'name'    => 'color',
                                    'width'   => '110px',
                                    'save'    => true,
                                    'add'     => -1,
                                    'edit'    => ['type' => 'SelectColor'],
                                ],
                            ],
                            'values' => $categoryValues,
                        ],
                        [
                            'type'    => 'Label',
                            'caption' => $this->Translate('The standard categories arrive with their built-in icon and color and can be changed here. Own categories stay empty: they get a shopping basket in a color derived from their name.'),
                        ],
                    ],
                ],
                [
                    'type'     => 'ExpansionPanel',
                    'caption'  => $this->Translate('Search suggestions'),
                    'expanded' => false,
                    'items'    => [
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
                    ],
                ],
                [
                    'type'     => 'ExpansionPanel',
                    'caption'  => $this->Translate('Favorite lists'),
                    'expanded' => false,
                    'items'    => [
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
                            'onChange' => 'SL_SwitchFavoriteList($id, $FavoriteListSelect, json_encode($FavoriteItemsConfig));',
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
                ],
                [
                    'type'     => 'ExpansionPanel',
                    'caption'  => $this->Translate('Barcode scanner'),
                    'expanded' => false,
                    'items'    => [
                        [
                            'type'    => 'Label',
                            'caption' => $this->Translate('Barcode scanner info'),
                        ],
                        [
                            'type'    => 'CheckBox',
                            'name'    => 'ScannerEnabled',
                            'caption' => $this->Translate('Enable barcode scanner'),
                        ],
                        [
                            'type'               => 'SelectVariable',
                            'name'               => 'ExternalScannerVariableID',
                            'caption'            => $this->Translate('External scanner variable'),
                            'validVariableTypes' => [3],
                            'width'              => '500px',
                        ],
                        [
                            'type'     => 'ExpansionPanel',
                            'caption'  => $this->Translate('External product API'),
                            'expanded' => false,
                            'visible'  => false,
                            'items'    => [
                                [
                                    'type'    => 'CheckBox',
                                    'name'    => 'ExtApiEnabled',
                                    'caption' => $this->Translate('Enable external product API'),
                                ],
                                [
                                    'type'    => 'CheckBox',
                                    'name'    => 'ExtApiShowPrice',
                                    'caption' => $this->Translate('Show price on scanned items'),
                                ],
                                [
                                    'type'    => 'ValidationTextBox',
                                    'name'    => 'ExtApiZipCode',
                                    'caption' => $this->Translate('ZIP code'),
                                ],
                                [
                                    'type'    => 'Button',
                                    'caption' => $this->Translate('Search markets'),
                                    'onClick' => 'SL_SearchMarkets($id, $ExtApiZipCode);',
                                ],
                                [
                                    'type'     => 'ValidationTextBox',
                                    'name'     => 'ExtApiMarketId',
                                    'caption'  => $this->Translate('Market ID'),
                                ],
                                [
                                    'type'     => 'Select',
                                    'name'     => 'ExtApiMarketSelect',
                                    'caption'  => $this->Translate('Select market'),
                                    'options'  => [
                                        ['caption' => $this->Translate('Enter ZIP and search'), 'value' => ''],
                                    ],
                                    'onChange' => 'SL_ApplyMarketSelection($id, $ExtApiMarketSelect);',
                                ],
                                [
                                    'type'    => 'Label',
                                    'name'    => 'ExtApiMarketSearchInfo',
                                    'caption' => '',
                                ],
                                [
                                    'type'       => 'SelectFile',
                                    'name'       => 'ExtApiCertFile',
                                    'caption'    => $this->Translate('Certificate (PEM)'),
                                    'extensions' => '.pem',
                                ],
                                [
                                    'type'       => 'SelectFile',
                                    'name'       => 'ExtApiKeyFile',
                                    'caption'    => $this->Translate('Private Key (PEM)'),
                                    'extensions' => '.pem,.key',
                                ],
                                [
                                    'type'    => 'ScriptEditor',
                                    'name'    => 'ExtApiConfig',
                                    'rowCount' => 20,
                                    'caption' => $this->Translate('Provider Config (JSON)'),
                                ],
                                [
                                    'type'    => 'Label',
                                    'caption' => $this->Translate('Cart login'),
                                    'bold'    => true,
                                ],
                                [
                                    'type'    => 'Label',
                                    'name'    => 'ExtApiLoginInfo',
                                    'caption' => $this->ReadAttributeString('ExtApiAccessToken') !== ''
                                        ? $this->Translate('Logged in')
                                        : $this->Translate('Not logged in'),
                                ],
                                [
                                    'type'    => 'Label',
                                    'name'    => 'ExtApiLoginUrl',
                                    'caption' => '',
                                    'visible' => false,
                                ],
                                [
                                    'type'    => 'RowLayout',
                                    'items'   => [
                                        [
                                            'type'    => 'Button',
                                            'caption' => $this->Translate('Login'),
                                            'onClick' => 'SL_StartLogin($id);',
                                        ],
                                        [
                                            'type'    => 'Button',
                                            'caption' => $this->Translate('Logout'),
                                            'onClick' => 'SL_Logout($id);',
                                        ],
                                    ],
                                ],
                                [
                                    'type'    => 'RowLayout',
                                    'name'    => 'ExtApiRedirectPasteRow',
                                    'visible' => false,
                                    'items'   => [
                                        [
                                            'type'    => 'ValidationTextBox',
                                            'name'    => 'ExtApiRedirectPaste',
                                            'caption' => $this->Translate('Redirect URL'),
                                            'width'   => '500px',
                                        ],
                                        [
                                            'type'    => 'Button',
                                            'caption' => $this->Translate('Exchange code'),
                                            'onClick' => 'SL_ExchangeCode($id, $ExtApiRedirectPaste);',
                                        ],
                                    ],
                                ],
                                [
                                    'type'    => 'Label',
                                    'caption' => $this->Translate('Diagnostics'),
                                    'bold'    => true,
                                ],
                                [
                                    'type'    => 'Button',
                                    'caption' => $this->Translate('Check service portfolio'),
                                    'onClick' => 'SL_DiagnoseServicePortfolio($id);',
                                ],
                                [
                                    'type'    => 'Button',
                                    'caption' => $this->Translate('Show access token claims'),
                                    'onClick' => 'SL_DiagnoseAccessToken($id);',
                                ],
                                [
                                    'type'    => 'Label',
                                    'name'    => 'ExtApiPortfolioInfo',
                                    'caption' => '',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        // Externe Listen als eigener Bereich auf der HAUPTEBENE — nicht im
        // Barcode-Bereich, wo er zuerst gelandet war: es ist eine eigene Quelle
        // und kein Zubehoer des Scanners.
        $form['elements'][] = $this->GetExtListFormElements();

        return json_encode($form, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}

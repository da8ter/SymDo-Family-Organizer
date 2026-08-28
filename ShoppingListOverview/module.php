<?php

declare(strict_types=1);

/**
 * Kompakte Kachel: die offenen Artikel einer Einkaufsliste als horizontal
 * scrollbare Bild-Leiste — Nachbildung der Einkaufsvorschau aus dem
 * SymDo-App-Dashboard (ohne Buttons). Klick öffnet ein konfigurierbares Ziel.
 */
class SymDoShoppingListOverview extends IPSModuleStrict
{
    // GUID des Quell-Moduls Shopping List (Filter/Validierung)
    private const SHOPPINGLIST_MODULE_GUID = '{A5D3F2E1-7B4C-4E8A-9D6F-1C2B3A4E5F6D}';

    // Diese Variablen der Quell-Instanz werden bei jeder Änderung gesetzt und
    // dienen als Update-Trigger für die Kachel
    private const SRC_IDENTS = ['ItemCount', 'LastUsed'];

    public function Create(): void
    {
        parent::Create();

        // Pflicht, damit Symcon die HTML-Kachel aus GetVisualizationTile() rendert
        $this->SetVisualizationType(1);

        $this->RegisterPropertyInteger('ShoppingListInstanceID', 0);
        $this->RegisterPropertyInteger('OpenObjectID', 0);
        $this->RegisterPropertyInteger('ImageHeight', 48);
        $this->RegisterPropertyInteger('FontSize', 11);

        // Merkt sich die aktuell abonnierten Variablen-IDs, um Abos sauber zu lösen
        $this->RegisterAttributeString('SubscribedVarIDs', '[]');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Kernel-Check: Kein Heavy Work vor KR_READY
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }

        // 1. Alte Abos/Referenzen sauber lösen (kein Leak bei Instanzwechsel)
        $previous = json_decode($this->ReadAttributeString('SubscribedVarIDs'), true);
        if (is_array($previous)) {
            foreach ($previous as $oldID) {
                $oldID = (int) $oldID;
                if ($oldID > 0) {
                    $this->UnregisterMessage($oldID, VM_UPDATE);
                }
            }
        }
        foreach ($this->GetReferenceList() as $refID) {
            $this->UnregisterReference($refID);
        }

        // 2. Trigger-Variablen der Quell-Instanz abonnieren
        $instanceID = $this->ReadPropertyInteger('ShoppingListInstanceID');
        $subscribed = [];

        if ($instanceID > 0 && IPS_InstanceExists($instanceID)) {
            $this->RegisterReference($instanceID);
            foreach (self::SRC_IDENTS as $ident) {
                $varID = @IPS_GetObjectIDByIdent($ident, $instanceID);
                if ($varID > 0 && IPS_VariableExists($varID)) {
                    $this->RegisterMessage($varID, VM_UPDATE);
                    $subscribed[] = $varID;
                }
            }
        }

        // Klick-Ziel referenzieren, damit es nicht unbemerkt gelöscht wird
        $openID = $this->ReadPropertyInteger('OpenObjectID');
        if ($openID > 0 && @IPS_ObjectExists($openID)) {
            $this->RegisterReference($openID);
        }

        $this->WriteAttributeString('SubscribedVarIDs', json_encode($subscribed));

        // 3. Initialwerte an die Kachel senden
        $this->PushState();
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        switch ($Message) {
            case IPS_KERNELSTARTED:
                $this->ApplyChanges();
                return;
            case VM_UPDATE:
                $this->PushState();
                return;
        }
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode((string) @file_get_contents(__DIR__ . '/form.json'), true);
        if (!is_array($form)) {
            return '{}';
        }

        // Vorhandene Einkaufslisten suchen und als Dropdown anbieten
        $lists = [];
        foreach (IPS_GetInstanceListByModuleID(self::SHOPPINGLIST_MODULE_GUID) as $id) {
            $name = IPS_GetName($id);
            $lists[] = [
                'caption' => ($name !== '' ? $name : $this->Translate('Shopping list')) . ' (#' . $id . ')',
                'value'   => $id,
            ];
        }
        usort($lists, static fn(array $a, array $b): int => strcasecmp($a['caption'], $b['caption']));

        // Gespeicherte, aber nicht mehr auffindbare Auswahl sichtbar halten
        $current = $this->ReadPropertyInteger('ShoppingListInstanceID');
        if ($current > 0 && !in_array($current, array_column($lists, 'value'), true)) {
            $lists[] = ['caption' => '#' . $current . ' (' . $this->Translate('not found') . ')', 'value' => $current];
        }

        $options = array_merge(
            [['caption' => $this->Translate('Please select'), 'value' => 0]],
            $lists
        );

        foreach ($form['elements'] as &$element) {
            if (($element['name'] ?? '') === 'ShoppingListInstanceID') {
                $element = [
                    'type'    => 'Select',
                    'name'    => 'ShoppingListInstanceID',
                    'caption' => $this->Translate('Shopping list instance'),
                    'options' => $options,
                ];
            }
        }
        unset($element);

        return json_encode($form, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Abhaken aus der Kachel. Die Kachel kennt die Einkaufsliste nicht selbst —
     * sie schickt nur die Kennung des Artikels hierher, und hier geht sie an die
     * eingestellte Liste weiter.
     *
     * `inCart` steht ausdruecklich auf `true` statt umzuschalten: die Kachel
     * zeigt ausschliesslich OFFENE Artikel. Ein Umschalten haette bei einer
     * doppelt zugestellten Anfrage den Artikel wieder in die Liste geholt.
     */
    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($Ident !== 'Check') {
            parent::RequestAction($Ident, $Value);
            return;
        }
        $id = trim((string) $Value);
        $instanceID = $this->ReadPropertyInteger('ShoppingListInstanceID');
        if ($id === '' || $instanceID <= 0 || !IPS_InstanceExists($instanceID)) {
            return;
        }
        try {
            IPS_RequestAction($instanceID, 'ToggleCart', json_encode(['id' => $id, 'inCart' => true]));
        } catch (\Throwable $e) {
            $this->SendDebug('Check', $e->getMessage(), 0);
        }
    }

    public function GetVisualizationTile(): string
    {
        $path = __DIR__ . '/module.html';
        $html = @file_get_contents($path);
        if (!is_string($html)) {
            $this->LogMessage('GetVisualizationTile: module.html nicht lesbar, Pfad=' . $path, KL_WARNING);
            return '';
        }

        // Initial-Payload inline mitgeben, damit die Kachel sofort rendert
        $payload = json_encode($this->BuildPayload(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $html .= '<script>handleMessage(' . $payload . ');</script>';

        return $html;
    }

    private function PushState(): void
    {
        $this->UpdateVisualizationValue(
            json_encode($this->BuildPayload(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    private function BuildPayload(): array
    {
        $payload = [
            'type'          => 'state',
            'items'         => [],
            'productImages' => new \stdClass(),
            'imageBase'     => '',
            'openObjectId'  => $this->ReadPropertyInteger('OpenObjectID'),
            'imageHeight'   => max(24, $this->ReadPropertyInteger('ImageHeight')),
            'fontSize'      => max(7, $this->ReadPropertyInteger('FontSize')),
            'emptyText'     => $this->Translate('List is empty'),
        ];

        $instanceID = $this->ReadPropertyInteger('ShoppingListInstanceID');
        if ($instanceID <= 0 || !IPS_InstanceExists($instanceID)) {
            return $payload;
        }

        try {
            $raw = json_decode((string) SL_GetAppState($instanceID), true);
            $state = is_array($raw) ? ($raw['state'] ?? []) : [];
            $payload['items'] = $this->OpenItemsInCategoryOrder(is_array($state) ? $state : []);
            $images = $state['availableImages'] ?? [];
            $payload['productImages'] = (is_array($images) && $images !== []) ? $images : new \stdClass();
            $brands = $state['availableBrands'] ?? [];
            $payload['productBrands'] = (is_array($brands) && $brands !== []) ? $brands : new \stdClass();
            $payload['imageBase'] = (string) SL_GetTileImageBase($instanceID);
        } catch (\Throwable $e) {
            $this->SendDebug('BuildPayload', $e->getMessage(), 0);
        }

        return $payload;
    }

    /**
     * Offene Artikel in der Reihenfolge der Kategorien-Sortierung — identisch
     * zur Einkaufslisten-Kachel und zur App.
     *
     * @return array<int, array{name: string, amount: string, imageUrl: string}>
     */
    private function OpenItemsInCategoryOrder(array $state): array
    {
        $items = [];
        foreach (($state['items'] ?? []) as $item) {
            if (!is_array($item) || !empty($item['inCart'])) {
                continue;
            }
            $category = trim((string) ($item['category'] ?? ''));
            $items[$category === '' ? 'Sonstiges' : $category][] = [
                // Die Kennung braucht die Kachel zum Abhaken; ohne sie koennte
                // sie den Artikel nur ueber den Namen benennen, und der ist
                // nicht eindeutig.
                'id'       => (string) ($item['id'] ?? ''),
                'name'     => (string) ($item['name'] ?? ''),
                'amount'   => (string) ($item['amount'] ?? ''),
                'imageUrl' => (string) ($item['imageUrl'] ?? ''),
            ];
        }

        $order = [];
        foreach (($state['categoryOrder'] ?? []) as $category) {
            $order[] = (string) $category;
        }
        foreach (array_keys($items) as $category) {
            if (!in_array($category, $order, true)) {
                $order[] = $category;
            }
        }

        $sorted = [];
        foreach ($order as $category) {
            foreach ($items[$category] ?? [] as $item) {
                $sorted[] = $item;
            }
        }
        return $sorted;
    }
}

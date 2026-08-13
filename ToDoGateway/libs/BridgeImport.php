<?php

declare(strict_types=1);

/**
 * Einmalige Übernahme des Bestands aus dem abgelösten Modul „SymDo Bridge".
 *
 * Wegwerf-Code: Er existiert nur, damit eine bestehende Bridge-Installation ohne
 * Handarbeit ins Gateway umzieht, und darf entfallen, sobald niemand mehr von einer
 * Bridge-Version aktualisiert.
 *
 * Drei Dinge wandern:
 *
 *  - Die neun Properties (Nutzer, lokale URL, KI-Einstellungen).
 *  - Die Attribute, allen voran die gekoppelten Geräte. Ihr `tokenHash` ist ein
 *    ungesalzener SHA-256 des Tokens und hängt nicht an der Instanz — die Kopplung
 *    gilt nach dem Umzug also unverändert weiter, App und Browser merken nichts.
 *  - Die Avatar-Medienobjekte. Sie hängen als Kinder an der Bridge-Instanz und würden
 *    mit ihr gelöscht; die photo-Spalte der Nutzerliste zeigt auf genau diese IDs.
 *    Umhängen statt kopieren hält die IDs stabil, damit die Zuordnung überlebt.
 *
 * Zu den Quellen siehe ReadBridgeSnapshot(): Für die Konfiguration ist
 * IPS_GetConfiguration der Weg, für die Attribute gibt es gar keine API, und sobald
 * das Bridge-Modul entfernt ist, liefert auch IPS_GetConfiguration nichts mehr.
 */
trait BridgeImport
{
    private const LEGACY_BRIDGE_GUID = '{F9B31B2B-ED34-4E88-B96D-D115E39F0B44}';

    /** Properties, die 1:1 übernommen werden. Reihenfolge = Anzeige im Ergebnis. */
    private const BRIDGE_PROPERTIES = [
        'Users',
        'LocalHttpsUrl',
        'AiEnabled',
        'AiProvider',
        'AiAnthropicKey',
        'AiOpenAIKey',
        'AiLocalBaseUrl',
        'AiLocalModel',
        'AiLocalKey',
    ];

    /**
     * Attribute, die mitwandern. `PendingPairings` fehlt bewusst: ein halb
     * abgeschlossener Kopplungsvorgang der alten Instanz ist wertlos und sein Code
     * längst abgelaufen. `AvatarCache` fehlt, weil er sich von selbst neu aufbaut.
     */
    private const BRIDGE_ATTRIBUTES = [
        'PairedDevices'       => 'string',
        'HiddenInstances'     => 'string',
        'RecipePhotoCategory' => 'string',
        'ActionDedup'         => 'string',
        'AiPrivacyAccepted'   => 'bool',
        'AiPrivacyAcceptedAt' => 'string',
    ];

    /** Übernahme per Formular-Knopf. Gibt das Ergebnis im Formular aus. */
    public function ImportBridgeState(): void
    {
        echo $this->RunBridgeImport();
    }

    /** Ziel des Ein-Schuss-Timers: übernimmt ungefragt, meldet aber nur ins Log. */
    public function RunPendingBridgeImport(): void
    {
        $this->SetTimerInterval('BridgeImport', 0);
        if (!$this->BridgeImportPending()) {
            return;
        }
        $this->LogMessage($this->RunBridgeImport(), KL_NOTIFY);
    }

    /** Instanzen des abgelösten Bridge-Moduls, aufsteigend. */
    private function LegacyBridgeInstances(): array
    {
        $ids = @IPS_GetInstanceListByModuleID(self::LEGACY_BRIDGE_GUID);
        if (!is_array($ids)) {
            return [];
        }
        sort($ids);
        return $ids;
    }

    /**
     * Deckt beide Aufrufer ab — ApplyChanges und den öffentlichen Timer-Einstieg. Der
     * Eigentums-Test steht bewusst hier: ein zweites Gateway darf den Bestand nicht an
     * sich ziehen, sonst landen Nutzer und Geräte auf einer Instanz, die die App nicht
     * bedient.
     */
    private function BridgeImportPending(): bool
    {
        return $this->AppApiOwnerID() === $this->InstanceID
            && $this->ReadBridgeImportDone() === ''
            && $this->LegacyBridgeInstances() !== [];
    }

    /**
     * Nach einem Modul-Reload ohne Kernel-Neustart kennt die Instanz frisch
     * hinzugekommene Attribute noch nicht. Lesen wirft dann — bis zum Neustart gilt
     * „noch nicht übernommen", und der Import wartet einfach.
     */
    private function ReadBridgeImportDone(): string
    {
        try {
            return $this->ReadAttributeString('BridgeImportDone');
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Idempotent: Ein zweiter Lauf wird abgewiesen, solange BridgeImportDone gesetzt ist.
     * Das Attribut hält die Quell-Instanz, damit im Formular nachvollziehbar bleibt,
     * woher der Bestand stammt.
     */
    private function RunBridgeImport(): string
    {
        $done = $this->ReadBridgeImportDone();
        if ($done !== '') {
            return sprintf($this->Translate('Already imported from instance #%s.'), $done);
        }

        $bridges = $this->LegacyBridgeInstances();
        if ($bridges === []) {
            return $this->Translate('No SymDo Bridge instance found — nothing to import.');
        }
        $bridgeID = (int) $bridges[0];

        $snapshot = $this->ReadBridgeSnapshot($bridgeID);
        $config   = $snapshot['configuration'];
        if ($config === []) {
            return sprintf(
                $this->Translate('Configuration of instance #%d could not be read — import aborted.'),
                $bridgeID
            );
        }

        $copied = [];
        foreach (self::BRIDGE_PROPERTIES as $property) {
            if (!array_key_exists($property, $config)) {
                continue;
            }
            try {
                IPS_SetProperty($this->InstanceID, $property, $config[$property]);
                $copied[] = $property;
            } catch (\Throwable $e) {
                $this->SendDebug('BridgeImport', $property . ': ' . $e->getMessage(), 0);
            }
        }
        // Vor IPS_ApplyChanges: der Lauf ruft ApplyChanges erneut auf, und das darf den
        // Ein-Schuss-Timer nicht noch einmal scharf machen.
        $this->WriteAttributeString('BridgeImportDone', (string) $bridgeID);

        if ($copied !== []) {
            IPS_ApplyChanges($this->InstanceID);
        }

        $devices = $this->ImportBridgeAttributes($snapshot['attributes']);
        $moved   = $this->ReparentBridgeMedia($bridgeID);

        return sprintf(
            $this->Translate('Imported from instance #%1$d: %2$d settings, %3$d avatars, %4$d paired devices. Instance #%1$d can now be deleted.'),
            $bridgeID,
            count($copied),
            $moved,
            $devices
        );
    }

    /**
     * Liest Konfiguration und Attribute der alten Instanz.
     *
     * Für die Konfiguration ist IPS_GetConfiguration der Weg — aber nur, solange das
     * Bridge-Modul installiert ist. Fehlt es, liefert die Funktion `false` statt zu
     * werfen (auf diesem System gemessen), obwohl die Instanz mit allen Daten
     * weiterlebt. Dann bleibt die Einstellungsdatei des Kernels als Quelle.
     *
     * Für die Attribute gibt es überhaupt keine API — kein IPS_GetAttribute —, sie
     * kommen also immer von der Platte. Das ist verlässlich, weil eine modullose
     * Instanz nichts mehr schreibt: die Datei ist der maßgebliche Stand.
     *
     * @return array{configuration: array<string,mixed>, attributes: array<string,mixed>}
     */
    private function ReadBridgeSnapshot(int $BridgeID): array
    {
        $configuration = [];
        $raw = @IPS_GetConfiguration($BridgeID);
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $configuration = $decoded;
            }
        }

        $attributes = [];
        $settings = json_decode((string) @file_get_contents(IPS_GetKernelDir() . 'settings.json'), true);
        $node = $settings['objects']['ID' . $BridgeID]['data'] ?? null;
        if (is_array($node)) {
            if ($configuration === [] && isset($node['configuration']) && is_array($node['configuration'])) {
                $configuration = $node['configuration'];
            }
            if (isset($node['attributes']) && is_array($node['attributes'])) {
                $attributes = $node['attributes'];
            }
        }

        return ['configuration' => $configuration, 'attributes' => $attributes];
    }

    /** @return int Anzahl übernommener gekoppelter Geräte (0, wenn keine gefunden). */
    private function ImportBridgeAttributes(array $Attributes): int
    {
        foreach (self::BRIDGE_ATTRIBUTES as $name => $type) {
            if (!array_key_exists($name, $Attributes)) {
                continue;
            }
            try {
                if ($type === 'bool') {
                    $this->WriteAttributeBoolean($name, (bool) $Attributes[$name]);
                } else {
                    $this->WriteAttributeString($name, (string) $Attributes[$name]);
                }
            } catch (\Throwable $e) {
                $this->SendDebug('BridgeImport', $name . ': ' . $e->getMessage(), 0);
            }
        }

        $devices = json_decode((string) ($Attributes['PairedDevices'] ?? '[]'), true);
        return is_array($devices) ? count($devices) : 0;
    }

    /**
     * Hängt die Avatar-Medien von der Bridge unter das Gateway. IPS_SetParent ändert
     * die Objekt-IDs nicht, deshalb bleiben die Verweise in der Nutzerliste gültig.
     */
    private function ReparentBridgeMedia(int $BridgeID): int
    {
        $moved = 0;
        foreach (@IPS_GetChildrenIDs($BridgeID) as $childID) {
            $object = @IPS_GetObject($childID);
            if (!is_array($object) || (int) ($object['ObjectType'] ?? -1) !== OBJECTTYPE_MEDIA) {
                continue;
            }
            try {
                IPS_SetParent($childID, $this->InstanceID);
                $moved++;
            } catch (\Throwable $e) {
                $this->SendDebug('BridgeImport', 'Media ' . $childID . ': ' . $e->getMessage(), 0);
            }
        }
        return $moved;
    }

    /** Panel für das Konfigurationsformular; leer, sobald nichts mehr zu holen ist. */
    private function GetBridgeImportFormElements(): array
    {
        $done    = $this->ReadBridgeImportDone();
        $bridges = $this->LegacyBridgeInstances();
        if ($done === '' && $bridges === []) {
            return [];
        }

        if ($done !== '') {
            $caption = $bridges === []
                ? sprintf($this->Translate('Inventory from instance #%s has been imported.'), $done)
                : sprintf(
                    $this->Translate('Inventory from instance #%s has been imported. The old SymDo Bridge instance can now be deleted.'),
                    $done
                );
            $items = [['type' => 'Label', 'caption' => $caption]];
        } else {
            $items = [
                [
                    'type'    => 'Label',
                    'caption' => sprintf(
                        $this->Translate('SymDo Bridge instance #%d found. Import takes over users, avatars, AI settings and paired devices.'),
                        (int) $bridges[0]
                    ),
                ],
                [
                    'type'    => 'Button',
                    'caption' => $this->Translate('Import inventory'),
                    'onClick' => 'TGW_ImportBridgeState($id);',
                ],
            ];
        }

        return [[
            'type'     => 'ExpansionPanel',
            'name'     => 'BridgeImportPanel',
            'caption'  => $this->Translate('Takeover from SymDo Bridge'),
            'expanded' => $done === '',
            'items'    => $items,
        ]];
    }
}

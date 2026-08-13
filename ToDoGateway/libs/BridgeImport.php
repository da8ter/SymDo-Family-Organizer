<?php

declare(strict_types=1);

/**
 * Einmalige Übernahme des Bestands aus dem abgelösten Modul „SymDo Bridge".
 *
 * Wegwerf-Code: Er existiert nur, damit eine bestehende Bridge-Installation ohne
 * Handarbeit ins Gateway umzieht, und darf entfallen, sobald niemand mehr von einer
 * Bridge-Version aktualisiert.
 *
 * Zwei Dinge wandern, und nur diese zwei:
 *
 *  - Die neun Properties. Sie sind von außen lesbar (IPS_GetConfiguration), Attribute
 *    dagegen nicht — es gibt kein IPS_GetAttribute. Gerätetokens, versteckte Listen und
 *    die KI-Einwilligung liegen in Attributen und bleiben deshalb bewusst zurück.
 *  - Die Avatar-Medienobjekte. Sie hängen als Kinder an der Bridge-Instanz und würden
 *    mit ihr gelöscht; die photo-Spalte der Nutzerliste zeigt auf genau diese IDs.
 *    Umhängen statt kopieren hält die IDs stabil, damit die Zuordnung überlebt.
 *
 * Der Import läuft, solange die Bridge noch vollständig installiert ist. Auf einer
 * verwaisten Instanz (Modul bereits entfernt) ist nicht verlässlich, ob Symcon die
 * gespeicherte Konfiguration noch herausgibt.
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

    private function BridgeImportPending(): bool
    {
        return $this->ReadBridgeImportDone() === ''
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

        $config = json_decode((string) @IPS_GetConfiguration($bridgeID), true);
        if (!is_array($config)) {
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

        $moved = $this->ReparentBridgeMedia($bridgeID);

        return sprintf(
            $this->Translate('Imported from instance #%1$d: %2$d settings, %3$d avatars. Paired devices are not transferred — please pair the app and browser again, then delete instance #%1$d.'),
            $bridgeID,
            count($copied),
            $moved
        );
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
        $done    = $this->ReadAttributeString('BridgeImportDone');
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
                        $this->Translate('SymDo Bridge instance #%d found. Import takes over users, avatars and AI settings. Paired devices stay behind and have to be paired again afterwards.'),
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

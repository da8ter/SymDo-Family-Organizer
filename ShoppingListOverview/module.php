<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/KachelPush.php';
require_once __DIR__ . '/../libs/EinkaufsUebersicht.php';

/**
 * Kompakte Kachel: die offenen Artikel einer Einkaufsliste als horizontal
 * scrollbare Bild-Leiste — Nachbildung der Einkaufsvorschau aus dem
 * SymDo-App-Dashboard (ohne Buttons). Klick öffnet ein konfigurierbares Ziel.
 */
class SymDoShoppingListOverview extends IPSModuleStrict
{
    use KachelPushWeg;

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

        // 1. Alte Abos/Referenzen sauber lösen (kein Leak bei Instanzwechsel).
        //    Nur, was es noch gibt: eine Kennung aus dem Merker kann auf ein
        //    inzwischen geloeschtes Objekt zeigen, und ein Fehler hier liesse
        //    die Instanz beim Erstellen haengen.
        $previous = json_decode((string)@$this->ReadAttributeString('SubscribedVarIDs'), true);
        if (is_array($previous)) {
            foreach ($previous as $oldID) {
                $oldID = (int) $oldID;
                if ($oldID > 0 && @IPS_ObjectExists($oldID)) {
                    $this->UnregisterMessage($oldID, VM_UPDATE);
                }
            }
        }
        foreach ($this->GetReferenceList() as $refID) {
            $this->UnregisterReference($refID);
        }

        /* 2. Trigger-Variablen der Quell-Instanz abonnieren — und die VARIABLEN
           referenzieren, nicht die Instanz. Genau wie die ToDo-Uebersicht, und
           aus einem Grund, der sich am 11.09.2026 zeigte: diese Kachel war die
           einzige, die eine INSTANZ derselben Bibliothek referenzierte, und die
           einzige, die bei jedem Neuladen der Bibliothek mit „Kann
           Schnittstellen-Instanz nicht erstellen" hängen blieb (Status 101,
           46 Mal an einem Tag) — die referenzierte Instanz ist in diesem
           Moment selbst noch nicht wieder da. Die Variablen gehoeren der
           Quell-Instanz; ihre Referenz schuetzt sie genauso vor dem Loeschen. */
        $instanceID = $this->ReadPropertyInteger('ShoppingListInstanceID');
        $subscribed = [];

        if ($instanceID > 0 && IPS_InstanceExists($instanceID)) {
            foreach (self::SRC_IDENTS as $ident) {
                $varID = @IPS_GetObjectIDByIdent($ident, $instanceID);
                if ($varID > 0 && IPS_VariableExists($varID)) {
                    $this->RegisterReference($varID);
                    $this->RegisterMessage($varID, VM_UPDATE);
                    $subscribed[] = $varID;
                }
            }
        }

        // Klick-Ziel referenzieren, damit es nicht unbemerkt geloescht wird.
        // Darf das Erstellen nie aufhalten: das Ziel ist frei waehlbar und kann
        // eine Instanz sein, die beim Neuladen gerade nicht greifbar ist.
        $openID = $this->ReadPropertyInteger('OpenObjectID');
        if ($openID > 0 && @IPS_ObjectExists($openID)) {
            try {
                $this->RegisterReference($openID);
            } catch (\Throwable $e) {
                $this->SendDebug('ApplyChanges', 'Referenz auf Klick-Ziel nicht moeglich: ' . $e->getMessage(), 0);
            }
        }

        $this->WriteAttributeString('SubscribedVarIDs', json_encode($subscribed));

        // 3. Initialwerte an die Kachel senden — Erstaufbau: in jedem Fall
        $this->KachelErstaufbau();
        $this->PushState();
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        switch ($Message) {
            case IPS_KERNELSTARTED:
                $this->ApplyChanges();
                return;
            case VM_UPDATE:
                /* BEWUSST ohne $Data[1]-Filter: die Liste setzt beide Zähler bei
                   JEDEM Speichern, sie sind hier nur der Auslöser. Umbenennen,
                   Menge oder Kategorie ändern lässt beide gleich — und genau dann
                   zeigt der Streifen etwas Neues. Ob sich etwas geändert hat,
                   entscheidet der Prüfwert der Nutzlast; der Einmal-Timer macht
                   aus den zwei Zählern eines Speicherns einen Abruf. */
                $this->KachelNachziehen();
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
        if ($Ident === KachelPush::TIMER) {
            // Der Einmal-Timer aus MessageSink: gebündelter Abruf
            $this->PushState();
            return;
        }
        if ($Ident !== 'Check') {
            parent::RequestAction($Ident, $Value);
            return;
        }
        /* Die Kachel hat den Artikel schon ausgeblendet (optimistisch). Bis eine
           Nachricht deutlich nach dem Tipp hinausging, geht deshalb JEDER Stand
           hinaus — auch ein unveränderter: er ist die Korrektur, falls das
           Abhaken scheitert (libs/KachelPush.php, Regel 3). */
        $this->KachelAktion();
        $id = trim((string) $Value);
        $instanceID = $this->ReadPropertyInteger('ShoppingListInstanceID');
        $ok = false;
        if ($id !== '' && $instanceID > 0 && IPS_InstanceExists($instanceID)) {
            try {
                // Symcon warnt statt zu werfen (unbekannte Kennung, Liste im Neuaufbau) und
                // antwortet dann false — nur das ist das Scheitern.
                $ok = @IPS_RequestAction($instanceID, 'ToggleCart', json_encode(['id' => $id, 'inCart' => true])) !== false;
            } catch (\Throwable $e) {
                $this->SendDebug('Check', $e->getMessage(), 0);
            }
        }
        if (!$ok) {
            // Gescheitert: ohne Speichern kommt von der Liste kein Ereignis — der
            // Stand holt den ausgeblendeten Artikel zurück.
            $this->PushState();
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

        // Initial-Payload inline mitgeben, damit die Kachel sofort rendert.
        // Antwortet die Liste gerade nicht, zeichnet die Kachel den leeren Streifen —
        // einen vorigen Stand, den sie behalten koennte, hat eine neue Kachel nicht.
        /* JSON_HEX_TAG ist hier PFLICHT: die Nutzlast steht in einem <script>-Block,
           und mit JSON_UNESCAPED_SLASHES bliebe ein „</script>" in einem Namen oder
           Titel woertlich stehen — der Block endete dort, handleMessage liefe nie, und
           der Rest landete als HTML in der Visu. */
        $payload = json_encode($this->BuildPayload() ?? $this->Grundstand(),
            JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $html .= '<script>handleMessage(' . $payload . ');</script>';

        return $html;
    }

    /**
     * Den Stand an die offenen Kacheln — nur wenn er sich geaendert hat
     * (libs/KachelPush.php). Antwortet die Liste gerade nicht, geht NICHTS
     * hinaus: bisher kam dann ein leerer Streifen an, eine falsche Auskunft bis
     * zum naechsten Ereignis. Das naechste Ereignis kommt von selbst — die Liste
     * setzt ihre Zaehler in ihrem ApplyChanges, sobald sie wieder steht.
     */
    private function PushState(): void
    {
        $payload = $this->BuildPayload();
        if ($payload === null) {
            return;
        }
        $this->KachelSenden((string)json_encode($payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            KachelPush::Pruefwert($payload));
    }

    /** Die Nutzlast ohne Artikel — Einstellungen und Texte der Kachel. */
    private function Grundstand(): array
    {
        return [
            'type'          => 'state',
            'items'         => [],
            'productImages' => new \stdClass(),
            'productBrands' => new \stdClass(),
            'imageBase'     => '',
            'openObjectId'  => $this->ReadPropertyInteger('OpenObjectID'),
            'imageHeight'   => max(24, $this->ReadPropertyInteger('ImageHeight')),
            'fontSize'      => max(7, $this->ReadPropertyInteger('FontSize')),
            'emptyText'     => $this->Translate('List is empty'),
            // Beschriftung unter der Zahl im Zaehler.
            'itemsLabel'    => $this->Translate('Items'),
        ];
    }

    /**
     * Die volle Nutzlast der Kachel — null, wenn die eingestellte Liste gerade
     * nicht antworten kann. Ohne eingestellte Liste ist der leere Streifen die
     * richtige Auskunft.
     *
     * Die Bildkarten gehen NUR mit den Eintraegen hinaus, die ein offener Artikel
     * treffen kann (libs/EinkaufsUebersicht.php) — vorher war es die ganze Karte
     * mit rund 3 300 Eintraegen (100 KB), bei jedem Push an jede Kachel.
     */
    private function BuildPayload(): ?array
    {
        $payload = $this->Grundstand();
        $instanceID = $this->ReadPropertyInteger('ShoppingListInstanceID');
        if ($instanceID <= 0 || !IPS_InstanceExists($instanceID)) {
            return $payload;
        }
        $state = $this->QuelleLesen($instanceID);
        if ($state === null) {
            return null;
        }
        $payload['items'] = EinkaufsUebersicht::OffeneArtikel($state);
        $images = is_array($state['availableImages'] ?? null) ? $state['availableImages'] : [];
        $brands = is_array($state['availableBrands'] ?? null) ? $state['availableBrands'] : [];
        $karten = EinkaufsUebersicht::Bildkarten($payload['items'], $images, $brands);
        $payload['productImages'] = $karten['bilder'] !== [] ? $karten['bilder'] : new \stdClass();
        $payload['productBrands'] = $karten['marken'] !== [] ? $karten['marken'] : new \stdClass();
        // Die Bild-Basis steht im Zustand — ein zweiter Ruf in die Liste
        // (SL_GetTileImageBase) war ueberfluessig.
        $payload['imageBase'] = (string)($state['imageBase'] ?? '');
        return $payload;
    }

    /**
     * Der Zustand der Einkaufsliste, soweit die Uebersicht ihn braucht — oder null,
     * wenn die Liste gerade nicht antworten kann.
     *
     * Das Symcon-Log zeigte hier „InstanceInterface is not available": beim
     * Kernel-Hochlauf und beim Neuladen der Bibliothek entsteht die Schnittstelle
     * der Liste neu, waehrend diese Instanz schon fragt. Symcon WARNT dann (kein
     * try/catch faengt das) und liefert keinen Text. Deshalb der Runlevel vorab,
     * `@` und die Typprobe — der Instanzstatus taugt nicht als Probe, Symcon setzt
     * ihn bei der Erzeugung und berechnet ihn nie neu (SymDoWebApp::IsInstanceReady).
     * Beim Neuladen bleibt der Runlevel auf KR_READY; dort faengt die Typprobe.
     *
     * Schlank ueber SL_GetOverviewState — die Funktion ist neu und erst nach
     * einem Kernel-Neustart registriert; bis dahin der volle Zustand, gesiebt wird
     * hier wie dort.
     */
    private function QuelleLesen(int $instanceID): ?array
    {
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return null;
        }
        $funktion = function_exists('SL_GetOverviewState') ? 'SL_GetOverviewState'
            : (function_exists('SL_GetAppState') ? 'SL_GetAppState' : '');
        if ($funktion === '') {
            return null;
        }
        try {
            $roh = @$funktion($instanceID);
        } catch (\Throwable $e) {
            $this->SendDebug('Quelle', $e->getMessage(), 0);
            return null;
        }
        $daten = is_string($roh) && $roh !== '' ? json_decode($roh, true) : null;
        $state = is_array($daten) ? ($daten['state'] ?? null) : null;
        if (!is_array($state)) {
            $this->SendDebug('Quelle', 'Liste #' . $instanceID . ' antwortet gerade nicht — Stand bleibt', 0);
            return null;
        }
        return $state;
    }
}

<?php

declare(strict_types=1);

require_once __DIR__ . '/libs/MealStore.php';

/**
 * SymDo Meal Plan — der Essensplan als HTML-Kachel.
 *
 * Wochenraster mit einem Gericht je Tag. Die Rezepte sind die Favoritenlisten
 * der gewählten Einkaufsliste (dort wohnen Zutaten, Foto und Quelle); ein
 * Klick legt die Zutaten in den Einkaufswagen. Freitext geht auch („Reste").
 * Neue Rezepte entstehen direkt aus der Kachel per URL- oder Foto-Analyse —
 * über das Gateway-Relay, wie es die SymDo-Web-App-Kachel vormacht. Das
 * Briefing holt sich das Tagesgericht über MPL_GetMealForDate.
 */
class SymDoMealPlan extends IPSModuleStrict
{
    use MealStore;

    private const SHOPPING_GUID = '{A5D3F2E1-7B4C-4E8A-9D6F-1C2B3A4E5F6D}';
    private const GATEWAY_GUID  = '{E677FE7B-28C9-4124-8B58-8A1FE2657E8D}';

    // Änderungen dieser Variablen der Quelle stoßen den Kachel-Push an
    private const SRC_IDENTS = ['ItemCount', 'LastUsed'];


    /**
     * Vorschlagsliste der Konsole beim Anlegen: ohne sie steht dort nur „keine
     * passende Instanz", mit ihr bietet sie das vorhandene Gateway an oder legt
     * auf Wunsch eines an.
     *
     * „connect" statt „require": an EINEM Gateway haengen ueblicherweise mehrere
     * Kacheln — „require" boete nur Gateways ohne andere untergeordnete Instanz
     * an und draengte so zu einem zweiten.
     *
     * Die Verbindung sortiert die Instanz NICHT in den Objektbaum um; sie ist
     * reiner Datenfluss (gemessen: bei allen 43 verbundenen Instanzen dieses
     * Systems liegt der Baum-Elter woanders).
     */
    public function GetCompatibleParents(): string
    {
        return json_encode(['type' => 'connect', 'moduleIDs' => [self::GATEWAY_GUID]]);
    }

    public function Create(): void
    {
        parent::Create();

        // Pflicht, damit Symcon die HTML-Kachel aus GetVisualizationTile() rendert
        $this->SetVisualizationType(1);

        $this->RegisterPropertyInteger('ShoppingListInstanceID', 0);
        $this->RegisterAttributeString('Plan', '{}');
        $this->RegisterAttributeString('SubscribedVarIDs', '[]');

        // Alt-Bestand der früher hier erzeugten Gerichtsbilder — bleibt für die
        // einmalige Übergabe ans Gateway registriert und wird danach nie wieder
        // beschrieben.
        $this->RegisterAttributeString('DishImages', '{}');
        $this->RegisterAttributeInteger('DishImageCategory', 0);
        // Quittung des synchronen Gateway-Rückrufs: er landet auf einem ANDEREN
        // Objekt derselben Instanz (gemessen) — ein Objektfeld überlebt die
        // Grenze nicht, ein Attribut schon.
        $this->RegisterAttributeString('AiSeenTxn', '');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Kernel-Check: Kein Heavy Work vor KR_READY
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }

        // Alte Abos/Referenzen sauber lösen (kein Leak bei Quellen-Wechsel)
        $vorher = json_decode($this->ReadAttributeString('SubscribedVarIDs'), true);
        foreach (is_array($vorher) ? $vorher : [] as $altID) {
            if ((int)$altID > 0) {
                $this->UnregisterMessage((int)$altID, VM_UPDATE);
            }
        }
        foreach ($this->GetReferenceList() as $refID) {
            $this->UnregisterReference($refID);
        }

        $abos = [];
        $sl = $this->QuellListe();
        if ($sl > 0) {
            $this->RegisterReference($sl);
            foreach (self::SRC_IDENTS as $ident) {
                $varID = @IPS_GetObjectIDByIdent($ident, $sl);
                if (is_int($varID) && $varID > 0) {
                    $this->RegisterMessage($varID, VM_UPDATE);
                    $abos[] = $varID;
                }
            }
        }
        $this->WriteAttributeString('SubscribedVarIDs', json_encode($abos));

        // Einmalig: früher hier erzeugte Gerichtsbilder ans Gateway übergeben.
        $this->DishUebergeben();

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

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'GetState':
                $this->PushState();
                return;

            case 'SetMeal':
                // {date, listId?, text?} — Zielzustand des Tages, kein Umschalten.
                $daten = is_array($Value) ? $Value : json_decode((string)$Value, true);
                if (is_array($daten)) {
                    $this->GerichtSetzen(
                        trim((string)($daten['date'] ?? '')),
                        (string)($daten['listId'] ?? ''),
                        (string)($daten['text'] ?? ''),
                        time()
                    );
                    $this->PushState();
                }
                return;

            case 'AddItemsToCart':
                // Auswahl-Liste: {targetId?, items[]} — jede Zeile trägt ihre
                // Herkunft (listId), damit auch eine ganze Woche in einem
                // Aufruf gehen kann. Übernommen wird je Rezept.
                $daten = is_array($Value) ? $Value : json_decode((string)$Value, true);
                if (!is_array($daten)) {
                    return;
                }
                $ziel = (int)($daten['targetId'] ?? 0);
                $nachRezept = [];
                foreach (is_array($daten['items'] ?? null) ? $daten['items'] : [] as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    // Ohne eigene Herkunft gilt die des Aufrufs (Einzel-Gericht).
                    $lid = trim((string)($item['listId'] ?? ($daten['listId'] ?? '')));
                    if ($lid !== '') {
                        $nachRezept[$lid][] = $item;
                    }
                }
                $erfolg = 0;
                foreach ($nachRezept as $lid => $zeilen) {
                    if ($this->KorbAuswahl((string)$lid, $ziel, $zeilen)) {
                        $erfolg++;
                    }
                }
                if ($erfolg > 0) {
                    $this->Push(['type' => 'cartDone', 'count' => $erfolg]);
                }
                return;

            case 'SetServings':
                // Portionszahl eines Rezepts dauerhaft aendern: {listId, servings, amounts}
                $daten = is_array($Value) ? $Value : json_decode((string)$Value, true);
                $sl = $this->QuellListe();
                if (is_array($daten) && $sl > 0) {
                    try {
                        IPS_RequestAction($sl, 'SetFavoriteServings', json_encode([
                            'listId'   => trim((string)($daten['listId'] ?? '')),
                            'servings' => (int)($daten['servings'] ?? 0),
                            'amounts'  => is_array($daten['amounts'] ?? null) ? $daten['amounts'] : [],
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    } catch (\Throwable $e) {
                        $this->SendDebug('MealPlan', 'Portionen speichern fehlgeschlagen: ' . $e->getMessage(), 0);
                    }
                }
                return;

            case 'WeekDetail':
                // Auswahl vor dem Wochen-Einkauf: alle Zutaten der Woche.
                $montag = trim((string)$Value);
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $montag) === 1) {
                    $this->Push([
                        'type'        => 'weekDetail',
                        'monday'      => $montag,
                        'ingredients' => $this->WochenZutaten($montag),
                    ]);
                }
                return;

            case 'MealDetail':
                // Detail-Blatt der Kachel: {date} → größeres Bild + Rezept-Quelle.
                $this->Push($this->GerichtDetail(trim((string)$Value)));
                return;

            case 'AddToCart':
                // Nutzlast: die rohe listId (wie beim Vorbild ToggleCart der Übersichtskachel)
                if ($this->ZutatenUebernehmen((string)$Value)) {
                    $this->Push(['type' => 'cartDone', 'listId' => trim((string)$Value)]);
                }
                return;

            case 'AddWeekToCart':
                // Nutzlast: der Montag der Woche — alle geplanten Listen dieser Woche.
                $montag = trim((string)$Value);
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $montag) !== 1) {
                    return;
                }
                $tage = $this->PlanLesen();
                $uebernommen = [];
                foreach (range(1, 7) as $t) {
                    $g = $tage[$this->DatumInWoche($montag, $t)] ?? null;
                    $listId = is_array($g) ? trim((string)($g['listId'] ?? '')) : '';
                    // Doppelt geplante Gerichte nur EINMAL anstoßen. Die Liste
                    // selbst übernimmt einen schon offenen Artikel ohnehin nicht
                    // doppelt (gemessen) — der zweite Aufruf wäre Arbeit ohne
                    // Wirkung, und Absicht ist er auch nicht.
                    if ($listId !== '' && !in_array($listId, $uebernommen, true)
                        && $this->ZutatenUebernehmen($listId)) {
                        $uebernommen[] = $listId;
                    }
                }
                $this->Push(['type' => 'cartDone', 'count' => count($uebernommen)]);
                return;

            case 'SaveScan':
                // Gescanntes Rezept als NEUE Favoritenliste sichern und dem Tag
                // zuweisen. Die listId vergibt die Einkaufsliste; wiedergefunden
                // wird sie über den frischen Zustand.
                $this->ScanSichern(is_array($Value) ? $Value : json_decode((string)$Value, true));
                return;

            case 'AiCall':
                $this->HandleAiCall((string)$Value);
                return;

            case 'AiResult':
                // Rückkanal vom Gateway → an die Kachel weiterreichen.
                $r = json_decode((string)$Value, true);
                if (is_array($r)) {
                    @$this->WriteAttributeString('AiSeenTxn', (string)($r['txn'] ?? ''));
                    $this->Push([
                        'type'   => 'aiResult',
                        'txn'    => (string)($r['txn'] ?? ''),
                        'status' => (int)($r['status'] ?? 200),
                        'json'   => $r['json'] ?? null,
                    ]);
                }
                return;
        }
        parent::RequestAction($Ident, $Value);
    }

    /**
     * Der Kachel-Zustand als JSON — für Diagnose und Skripte.
     * GetVisualizationTile bekommt von Symcon keinen Präfix-Wrapper, deshalb
     * dieser Getter (dasselbe Muster wie bei den Routinen).
     */
    public function GetState(): string
    {
        return (string)json_encode($this->PayloadBauen(time()),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** Das Gericht eines Tages („YYYY-MM-DD") als JSON — der Haken fürs Briefing. */
    public function GetMealForDate(string $Date): string
    {
        return (string)json_encode($this->GerichtAuskunft(trim($Date)),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function GetVisualizationTile(): string
    {
        $path = __DIR__ . '/module.html';
        $html = @file_get_contents($path);
        if (!is_string($html)) {
            $this->LogMessage('GetVisualizationTile: module.html nicht lesbar, Pfad=' . $path, KL_WARNING);
            return '';
        }
        // Initial-Payload inline mitgeben, damit die Kachel sofort korrekt zeichnet
        $payload = json_encode($this->PayloadBauen(time()),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        return $html . '<script>handleMessage(' . $payload . ');</script>';
    }

    public function GetConfigurationForm(): string
    {
        // Select statt SelectInstance: zeigt auch eine gespeicherte, aber nicht
        // mehr auffindbare Wahl ehrlich an (Muster der Einkaufs-Übersichtskachel).
        $listen = [];
        foreach ((array)@IPS_GetInstanceListByModuleID(self::SHOPPING_GUID) as $id) {
            $name = IPS_GetName((int)$id);
            $listen[] = ['caption' => ($name !== '' ? $name : $this->Translate('Shopping list')) . ' (#' . (int)$id . ')',
                'value' => (int)$id];
        }
        usort($listen, static fn(array $a, array $b): int => strcasecmp($a['caption'], $b['caption']));
        $aktuell = (int)@IPS_GetProperty($this->InstanceID, 'ShoppingListInstanceID');
        if ($aktuell > 0 && !in_array($aktuell, array_column($listen, 'value'), true)) {
            $listen[] = ['caption' => '#' . $aktuell . ' (' . $this->Translate('not found') . ')', 'value' => $aktuell];
        }
        $optionen = array_merge([['caption' => $this->Translate('Please select'), 'value' => 0]], $listen);

        $form = [
            'elements' => [
                ['type' => 'Select', 'name' => 'ShoppingListInstanceID',
                 'caption' => $this->Translate('Shopping list'), 'options' => $optionen],
                ['type' => 'Label', 'caption' => $this->Translate('The favorite lists of this shopping list are the recipes of the meal plan, and its cart receives the ingredients. Recipes scanned from the tile are saved there as new favorite lists.')],
                ['type' => 'Label', 'caption' => $this->Translate('A SymDo Gateway is required: it delivers the daily briefing, the recipe analysis and the dish images of the recipe lists.')],
            ],
            'actions' => [
                ['type' => 'Label', 'caption' => ''],
                ['type' => 'Label', 'caption' => 'Donation / Gift'],
                ['type' => 'Label', 'caption' => 'Say thanks and support the developer of this module:'],
                ['type' => 'Button', 'caption' => 'PayPal', 'onClick' => 'echo \'https://paypal.me/sspkbw25\';'],
            ],
        ];
        return (string)json_encode($form, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    // ------------------------------------------------------------------
    // Intern
    // ------------------------------------------------------------------

    private function PushState(): void
    {
        $this->Push($this->PayloadBauen(time()));
    }

    private function Push(array $daten): void
    {
        $this->UpdateVisualizationValue((string)json_encode($daten,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /**
     * KI-Relay der Kachel (Muster SymDoWebApp): die Kachel hat keinen Token,
     * das Gateway extrahiert und ruft synchron IPS_RequestAction($this,'AiResult').
     * Der Rückkanal des Gateways prüft die Modul-GUID des Anrufers — diese
     * Kachel steht dort auf der Weißliste. Ob der Rückruf ankam, verrät das
     * Attribut AiSeenTxn (der Rückruf läuft auf einem anderen Objekt, ein
     * Objektfeld bliebe hier immer leer — gemessen).
     */
    private function HandleAiCall(string $json): void
    {
        $req = json_decode($json, true);
        if (!is_array($req)) {
            return;
        }
        $txn = (string)($req['txn'] ?? '');
        $gatewayID = $this->GatewayInstanz();
        if ($gatewayID > 0) {
            @$this->WriteAttributeString('AiSeenTxn', '');
            try {
                IPS_RequestAction($gatewayID, 'AiTileRequest', json_encode([
                    'path'    => (string)($req['path'] ?? ''),
                    'payload' => $req['payload'] ?? [],
                    'txn'     => $txn,
                    'sdwa'    => $this->InstanceID,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                if ($txn !== '' && (string)@$this->ReadAttributeString('AiSeenTxn') === $txn) {
                    return;
                }
            } catch (Throwable $e) {
                // fällt unten in die Fehlerantwort
            }
        }
        $this->Push(['type' => 'aiResult', 'txn' => $txn, 'status' => 200, 'json' => [
            'ok' => false, 'error' => ['code' => 'ai_unavailable', 'message' => $this->Translate('Could not reach the AI service.')],
        ]]);
    }

    /** @param mixed $daten {date, name, items[], url?, mediaId?} */
    private function ScanSichern(mixed $daten): void
    {
        $sl = $this->QuellListe();
        if (!is_array($daten) || $sl <= 0 || !function_exists('SL_AppCall')) {
            return;
        }
        $name  = mb_substr(trim((string)($daten['name'] ?? '')), 0, 80);
        $datum = trim((string)($daten['date'] ?? ''));
        $items = is_array($daten['items'] ?? null) ? $daten['items'] : [];
        if ($name === '' || $items === []) {
            return;
        }
        try {
            $antwort = json_decode((string)@SL_AppCall($sl, 'AddItemsToFavoriteList', json_encode([
                'listId'   => '',
                'name'     => $name,
                'items'    => $items,
                'url'      => (string)($daten['url'] ?? ''),
                'mediaId'  => (string)($daten['mediaId'] ?? ''),
                // Was aus einer Rezept-Analyse kommt, IST ein Rezept — nur mit
                // diesem Haken erzeugt das Gateway ein Gerichtsbild.
                'isRecipe' => true,
                // Die KI erkennt die Portionszahl; ohne sie bliebe der
                // Portions-Steller im Rezept ohne Bezugsgroesse.
                'servings' => (int)($daten['servings'] ?? 0),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), true);
        } catch (\Throwable $e) {
            $this->SendDebug('MealPlan', 'Rezept speichern fehlgeschlagen: ' . $e->getMessage(), 0);
            return;
        }
        // Die neue listId steht im frischen Zustand — die jüngste Liste dieses Namens.
        $listId = '';
        foreach ((array)($antwort['state']['favoriteLists'] ?? []) as $liste) {
            if (is_array($liste) && trim((string)($liste['name'] ?? '')) === $name) {
                $listId = trim((string)($liste['id'] ?? ''));
            }
        }
        if ($listId !== '' && $datum !== '') {
            $this->GerichtSetzen($datum, $listId, '', time());
        }
        $this->PushState();
    }
}

<?php

declare(strict_types=1);

/**
 * Listen-Umschalter der Einkaufs-Kachel (22.10.2026).
 *
 * Gibt es mehrere Einkaufslisten, zeigt die Kachel oben einen Umschalter und kann jede
 * andere Liste anzeigen und bedienen. Die Kachel spricht dabei weiter nur mit IHRER
 * Instanz (requestAction erreicht nur sie); die reicht an die gewählte Liste über deren
 * öffentliche Funktionen weiter (SL_GetTileState, SL_AppCall) — nie über RequestAction
 * einer fremden Instanz.
 *
 * Grenzen:
 * - Fremde Listen nur, wenn beide Seiten den Schalter „ListSwitcher" an haben.
 *   Eine ID, die nicht im Umschalter steht, wird abgelehnt (requestAction ist für jeden
 *   Visu-Browser erreichbar).
 * - Aktionen nur aus der Freigabeliste von AppCall — dieselben wie für Web-App und App.
 *
 * Stände einer fremden Liste tragen `listId` und gehen wie jeder Push an ALLE offenen
 * Clients dieser Kachel; jeder Client zeigt nur den Stand der Liste, die er gewählt hat.
 * Ändert sich eine fremde Liste anderswo, meldet die Kachel nur `listChanged` — wer sie
 * anzeigt, holt sich den Stand selbst (mit Prüfwert, also nur bei Neuem).
 */
trait ListSwitcher
{
    /** Modul-ID der Einkaufsliste (module.json). */
    private static string $listSwitcherModule = '{A5D3F2E1-7B4C-4E8A-9D6F-1C2B3A4E5F6D}';

    /**
     * Listen für den Umschalter: diese und alle anderen, die ihn erlauben, nach Namen.
     * Leer, wenn der Schalter hier aus ist oder es keine zweite Liste gibt.
     *
     * @return list<array{id:int,name:string}>
     */
    private function SwitcherLists(): array
    {
        // Neue Eigenschaft: vor dem Neuladen des Moduls liefert ReadPropertyBoolean false,
        // nicht den Standard (siehe ReadBooleanPropertyOrDefault).
        if (!$this->ReadBooleanPropertyOrDefault('ListSwitcher', true)) {
            return [];
        }
        $lists = [];
        foreach (IPS_GetInstanceListByModuleID(self::$listSwitcherModule) as $id) {
            if ($id !== $this->InstanceID && !$this->PeerAllowsSwitcher($id)) {
                continue;
            }
            $lists[] = ['id' => $id, 'name' => IPS_GetName($id)];
        }
        if (count($lists) < 2) {
            return [];
        }
        usort($lists, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']) ?: $a['id'] <=> $b['id']);
        return $lists;
    }

    private function PeerAllowsSwitcher(int $id): bool
    {
        // Fehlt die Eigenschaft dort noch (Modul nicht neu geladen), gilt der Standard: an.
        $config = json_decode((string) @IPS_GetConfiguration($id), true);
        if (!is_array($config)) {
            return false;
        }
        return !array_key_exists('ListSwitcher', $config) || $config['ListSwitcher'] === true;
    }

    /**
     * Die Liste, die die Kachel meint: 0 = diese. Eine fremde ID gilt nur, wenn sie im
     * Umschalter steht — sonst Fehler statt stillem Rückfall, damit nichts in der
     * falschen Liste landet.
     */
    private function SwitcherTarget(mixed $value): int
    {
        if (!is_string($value) || $value === '' || $value[0] !== '{') {
            return 0;
        }
        $d = json_decode($value, true);
        $id = is_array($d) && is_numeric($d['list'] ?? null) ? (int) $d['list'] : 0;
        if ($id === 0 || $id === $this->InstanceID) {
            return 0;
        }
        foreach ($this->SwitcherLists() as $list) {
            if ($list['id'] === $id) {
                return $id;
            }
        }
        throw new \Exception($this->Translate('This shopping list is not available here'));
    }

    /** Kachelstand mit den Angaben für den Umschalter (nur wenn es ihn gibt). */
    private function WithSwitcher(array $state, int $listId): array
    {
        $lists = $this->SwitcherLists();
        if ($lists !== []) {
            $state['lists'] = $lists;
            $state['listId'] = $listId;
            $state['homeId'] = $this->InstanceID;
        }
        return $state;
    }

    /** Stand einer fremden Liste an die eigene Kachel, mit Prüfwert wie der eigene. */
    private function PushListState(int $listId, mixed $kachel = null): void
    {
        $raw = $this->PeerTileState($listId);
        if ($raw === null) {
            return;
        }
        $daten = KachelStand::MitPruefwert($this->WithSwitcher($raw, $listId));
        if (KachelStand::Kennt($daten['stateHash'], $kachel)) {
            return;
        }
        $this->UpdateVisualizationValue((string) json_encode($daten, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /** Aktion der Kachel für eine fremde Liste: {list, action, payload}. */
    private function ListCall(mixed $value): void
    {
        $d = is_string($value) ? json_decode($value, true) : null;
        if (!is_array($d) || !is_string($d['action'] ?? null) || $d['action'] === '') {
            throw new \Exception($this->Translate('Invalid payload'));
        }
        $target = $this->SwitcherTarget($value);
        if ($target === 0) {
            // Die eigene Liste geht direkt per requestAction, nie über diesen Weg.
            throw new \Exception($this->Translate('Invalid payload'));
        }
        $payload = $d['payload'] ?? '';
        $payload = is_string($payload) ? $payload : (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $result = $this->PeerAppCall($target, $d['action'], $payload);
        // Auch nach einem Fehler: die Kachel soll den echten Stand sehen.
        $this->PushListState($target);
        if (($result['ok'] ?? false) !== true) {
            $message = is_array($result['error'] ?? null) ? (string) ($result['error']['message'] ?? '') : '';
            throw new \Exception($message !== '' ? $message : $this->Translate('Item operation failed'));
        }
    }

    /** Stand einer anderen Liste (Kachelform); null, wenn sie nichts Brauchbares liefert. */
    protected function PeerTileState(int $id): ?array
    {
        // SL_GetTileState gibt es erst nach dem nächsten Kernelstart; bis dahin der App-Stand
        // (Bildkarte dann inline statt als Adresse).
        if (function_exists('SL_GetTileState')) {
            $state = json_decode((string) SL_GetTileState($id), true);
        } else {
            $app = json_decode((string) SL_GetAppState($id), true);
            $state = is_array($app) ? ($app['state'] ?? null) : null;
        }
        return is_array($state) ? $state : null;
    }

    protected function PeerAppCall(int $id, string $action, string $payload): array
    {
        $result = json_decode((string) SL_AppCall($id, $action, $payload), true);
        return is_array($result) ? $result : ['ok' => false];
    }

    /**
     * Auf Änderungen der anderen Listen hören: ihre Variable ItemCount wird bei jedem
     * Speichern gesetzt. Neue Listen kommen mit dem nächsten ApplyChanges dazu; bis dahin
     * gleicht die Kachel alle 15 Sekunden ab.
     */
    private function SwitcherWatch(): void
    {
        $old = json_decode($this->ReadAttributeString('ListPeerVars'), true);
        foreach (is_array($old) ? $old : [] as $varId) {
            if (is_int($varId) && $varId > 0) {
                @$this->UnregisterMessage($varId, VM_UPDATE);
            }
        }
        $watch = [];
        foreach ($this->SwitcherLists() as $list) {
            if ($list['id'] === $this->InstanceID) {
                continue;
            }
            $varId = @IPS_GetObjectIDByIdent('ItemCount', $list['id']);
            if (is_int($varId) && $varId > 0) {
                $this->RegisterMessage($varId, VM_UPDATE);
                $watch[] = $varId;
            }
        }
        $this->WriteAttributeString('ListPeerVars', (string) json_encode($watch));
        $this->SendDebug('ListSwitcher', 'watching ' . json_encode($watch), 0);
    }

    /** true, wenn die Nachricht eine beobachtete andere Liste betraf (und gemeldet wurde). */
    private function SwitcherMessage(int $senderId, int $message): bool
    {
        if ($message !== VM_UPDATE) {
            return false;
        }
        $watch = json_decode($this->ReadAttributeString('ListPeerVars'), true);
        if (!is_array($watch) || !in_array($senderId, $watch, true)) {
            return false;
        }
        $listId = IPS_GetParent($senderId);
        $this->SendDebug('ListSwitcher', 'listChanged ' . $listId, 0);
        $this->UpdateVisualizationValue((string) json_encode(['type' => 'listChanged', 'listId' => $listId]));
        return true;
    }
}

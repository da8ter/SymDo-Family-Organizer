<?php

declare(strict_types=1);

/**
 * Anbindung der ToDo-Liste an die externe Liste von Alexa.
 *
 * Gegenstueck zu ShoppingList/libs/ExtListHooksShopping.php, aber nicht dasselbe: hier
 * haben Aufgaben eine fortlaufende Zahl als Kennung, `done` statt `inCart`, und
 * es gibt KEINE Zusammenfassung gleichnamiger Eintraege — TDL_AddItem legt
 * immer eine neue Aufgabe an. Das bleibt so; eine Sonderregel nur fuer Alexa
 * wuerde sich vom Verhalten aller anderen Quellen unterscheiden.
 *
 * Laeuft ABSICHTLICH neben SyncBackend: das ist exklusiv (EnforceSyncBackend),
 * die Sprachliste soll aber auch auf einer Liste mit Google-Abgleich hoeren.
 */
trait ExtListHooksTodo
{
    private function ExtListCreateProperties(): void
    {
        $this->RegisterPropertyBoolean('ExtListEnabledProp', false);
        $this->RegisterPropertyInteger('AlexaListID', 0);
        $this->RegisterPropertyBoolean('ExtListPushLocal', true);
        $this->RegisterPropertyString('ExtListAssignTo', '');
        $this->RegisterAttributeInteger('ExtListLastSync', 0);
        $this->RegisterAttributeString('ExtListTriggerVars', '[]');
    }

    /**
     * Eine Einstellung lesen, die es vielleicht noch nicht gibt.
     *
     * ReadProperty* auf eine nicht registrierte Eigenschaft wirft nicht, es gibt
     * eine PHP-Warnung in die Ausgabe — und die zerlegt den Rueckgabewert von
     * GetConfigurationForm. Neue Eigenschaften entstehen erst mit dem naechsten
     * Create(); ueber die Konfiguration gelesen wirkt der Bereich sofort.
     */
    private function ExtListProp(string $name, mixed $vorgabe): mixed
    {
        $cfg = json_decode((string)@IPS_GetConfiguration($this->InstanceID), true);
        if (!is_array($cfg) || !array_key_exists($name, $cfg)) {
            return $vorgabe;
        }
        return $cfg[$name];
    }

    // ────────────────────────── Haken fuer die Maschine ──────────────────────────

    private function ExtListEnabled(): bool
    {
        return (bool)$this->ExtListProp('ExtListEnabledProp', false);
    }

    /**
     * Die eingerichtete Gegenstelle. Bring fehlt hier absichtlich: es kennt nur
     * Einkaufslisten, keine Aufgaben.
     *
     * @return list<ListSource>
     */
    private function ExtListSources(): array
    {
        if (!$this->ExtListEnabled()) {
            return [];
        }
        $id = (int)$this->ExtListProp('AlexaListID', 0);
        if ($id <= 0) {
            return [];
        }
        $quelle = ListSource::For($id);
        return $quelle !== null ? [$quelle] : [];
    }

    private function ExtListPushEnabled(): bool
    {
        return (bool)$this->ExtListProp('ExtListPushLocal', true);
    }

    /**
     * Aufgaben haben kein Mengenfeld — hier gibt es nichts zu trennen.
     *
     * „3 Milch" ist ein Einkaufsartikel; eine Aufgabe heisst „Drei Angebote
     * einholen", und daraus „Angebote einholen" mit Menge 3 zu machen waere
     * Unsinn. Die Aufteilung bleibt der Einkaufsliste.
     */
    private function ExtListParseAmountEnabled(): bool
    {
        return false;
    }

    /** Der Bestand, geschluesselt nach der Aufgaben-Kennung. */
    private function ExtListLoad(): array
    {
        $raus = [];
        foreach ($this->LoadItems() as $item) {
            $id = (int)($item['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $raus[(string)$id] = [
                'name'        => (string)($item['title'] ?? ''),
                'amount'      => '',
                'extIds'      => is_array($item['extIds'] ?? null) ? $item['extIds'] : [],
                'done'        => ($item['done'] ?? false) === true,
            ];
        }
        return $raus;
    }

    private function ExtListIsDone(array $eintrag): bool
    {
        return (bool)($eintrag['done'] ?? false);
    }

    private function ExtListCreate(string $name, string $menge, string $extId, string $quelle): void
    {
        $name = trim($name);
        if ($name === '') {
            return;
        }
        // Ueber den normalen Weg, damit Benachrichtigung, Statistik und
        // Revision genauso laufen wie bei einer per App angelegten Aufgabe.
        $entwurf = ['title' => $name, 'info' => '', 'priority' => 'normal'];
        $wem = trim((string)$this->ExtListProp('ExtListAssignTo', ''));
        if ($wem !== '') {
            $entwurf['assignedTo'] = [$wem];
        }
        try {
            $id = $this->AddItem($entwurf);
        } catch (\Throwable $e) {
            $this->SendDebug('ExtListSync', 'Aufgabe nicht angelegt: ' . $e->getMessage(), 0);
            return;
        }
        if ($id > 0) {
            $this->ExtListStamp($id, $extId, $quelle);
        }
    }

    private function ExtListMarkDone(string|int $schluessel): void
    {
        try {
            $this->ToggleDone(['id' => (int)$schluessel, 'done' => true]);
        } catch (\Throwable $e) {
            $this->SendDebug('ExtListSync', 'Aufgabe nicht abgehakt: ' . $e->getMessage(), 0);
        }
    }

    private function ExtListSetId(string|int $schluessel, string $extId, string $quelle): void
    {
        $this->ExtListStamp((int)$schluessel, $extId, $quelle);
    }

    /**
     * Schreibt Kennung und Quelle an die Aufgabe.
     *
     * Eigener Weg statt UpdateItem: der zieht die ganze Nutzlast durch die
     * Pruefungen und wuerde Felder ueberschreiben, die hier niemand anfasst.
     */
    private function ExtListStamp(int $id, string $extId, string $quelle): void
    {
        $items = $this->LoadItems();
        foreach ($items as &$item) {
            if ((int)($item['id'] ?? 0) === $id) {
                $item['voiceId']     = $extId;
                $item['voiceSource'] = $quelle;
                $item['voiceSynced'] = time();
                $this->SaveItems($items);
                return;
            }
        }
    }

    // ────────────────────────── Formular ──────────────────────────

    /** @return array<string, mixed> */
    private function GetExtListFormElements(): array
    {
        return [
            'type'     => 'ExpansionPanel',
            'caption'  => $this->Translate('Amazon Alexa task list'),
            'expanded' => false,
            'items'    => [
                [
                    'type'    => 'Label',
                    'caption' => $this->Translate('Works alongside CalDAV, Google and Microsoft — the external list is not one of the exclusive sync backends.'),
                ],
                [
                    'type'    => 'CheckBox',
                    'name'    => 'ExtListEnabledProp',
                    'caption' => $this->Translate('Sync with external lists'),
                ],
                [
                    'type'         => 'SelectInstance',
                    'name'         => 'AlexaListID',
                    'caption'      => $this->Translate('AlexaList instance'),
                    'validModules' => [ListSource::GUID_ALEXA],
                    'width'        => '500px',
                ],
                [
                    'type'    => 'CheckBox',
                    'name'    => 'ExtListPushLocal',
                    'caption' => $this->Translate('Also send tasks from this list to the external lists'),
                ],
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'ExtListAssignTo',
                    'caption' => $this->Translate('Assign spoken tasks to this member ID (optional)'),
                    'width'   => '360px',
                ],
                [
                    'type'    => 'Label',
                    'name'    => 'ExtListStatus',
                    'caption' => $this->GetExtListStatusLabel(),
                ],
                [
                    'type'    => 'Button',
                    'caption' => $this->Translate('Sync now'),
                    'onClick' => 'echo TDL_ExtListSyncNow($id);',
                ],
                [
                    'type'    => 'Label',
                    'caption' => $this->Translate("What has to be installed:\n1. Library \"Echo Remote\" via Module Control: https://github.com/roastedelectrons/IPSymconEchoRemote\n2. Instance \"Echo IO\" — logs in to the Amazon account (once for everything).\n3. Instance \"AlexaList\" — one PER Amazon list. For tasks set \"List\" to \"Task list (default)\"; the shopping list needs its OWN instance.\n4. In that instance set the update interval to 2 MINUTES (default is 60) — its schedule decides how fast a spoken task arrives here.\n5. Do not go below that: the Echo module throttles its own activity polling to 60 requests per hour, and requests count PER AMAZON ACCOUNT, not per instance. At 1 minute a single instance already uses that whole budget, and our syncs come on top. Running two AlexaList instances (shopping + tasks): 3 minutes each."),
                ],
            ],
        ];
    }

    private function GetExtListStatusLabel(): string
    {
        if (!(bool)$this->ExtListProp('ExtListEnabledProp', false)) {
            return $this->Translate('Off');
        }
        $quellen = $this->ExtListSources();
        if ($quellen === []) {
            return $this->Translate('No external list selected');
        }
        $quelle = $quellen[0];
        $letzter = (int)@$this->ReadAttributeInteger('ExtListLastSync');
        return sprintf($this->Translate('Connected (%s) · last sync: %s'),
            $quelle->Key(),
            $letzter > 0 ? date('d.m.Y H:i', $letzter) : $this->Translate('never'));
    }

    private function ExtListSyncNowText(): string
    {
        if ($this->ExtListSources() === []) {
            return $this->Translate('Switch the sync on and select at least one external list first.');
        }
        $b = $this->ExtListSync();
        $text = sprintf($this->Translate('%d added, %d sent, %d matched, %d checked off'),
            (int)$b['imported'], (int)$b['pushed'], (int)$b['resolved'], (int)$b['completed']);
        if ((string)$b['reason'] !== '') {
            $text .= ' — ' . sprintf($this->Translate('problems: %s'), (string)$b['reason']);
        }
        return $text;
    }
}

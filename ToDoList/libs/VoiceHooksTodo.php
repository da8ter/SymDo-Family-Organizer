<?php

declare(strict_types=1);

/**
 * Anbindung der ToDo-Liste an eine Sprachliste (Alexa, Bring).
 *
 * Gegenstueck zu ShoppingList/libs/VoiceHooks.php, aber nicht dasselbe: hier
 * haben Aufgaben eine fortlaufende Zahl als Kennung, `done` statt `inCart`, und
 * es gibt KEINE Zusammenfassung gleichnamiger Eintraege — TDL_AddItem legt
 * immer eine neue Aufgabe an. Das bleibt so; eine Sonderregel nur fuer Alexa
 * wuerde sich vom Verhalten aller anderen Quellen unterscheiden.
 *
 * Laeuft ABSICHTLICH neben SyncBackend: das ist exklusiv (EnforceSyncBackend),
 * die Sprachliste soll aber auch auf einer Liste mit Google-Abgleich hoeren.
 */
trait VoiceHooksTodo
{
    private function VoiceCreateProperties(): void
    {
        $this->RegisterPropertyBoolean('VoiceSyncEnabled', false);
        $this->RegisterPropertyInteger('VoiceListID', 0);
        $this->RegisterPropertyBoolean('VoicePushLocal', true);
        $this->RegisterPropertyString('VoiceAssignTo', '');
        $this->RegisterAttributeInteger('VoiceLastSync', 0);
        $this->RegisterAttributeInteger('VoiceLastVariableID', 0);
    }

    /**
     * Eine Einstellung lesen, die es vielleicht noch nicht gibt.
     *
     * ReadProperty* auf eine nicht registrierte Eigenschaft wirft nicht, es gibt
     * eine PHP-Warnung in die Ausgabe — und die zerlegt den Rueckgabewert von
     * GetConfigurationForm. Neue Eigenschaften entstehen erst mit dem naechsten
     * Create(); ueber die Konfiguration gelesen wirkt der Bereich sofort.
     */
    private function VoiceProp(string $name, mixed $vorgabe): mixed
    {
        $cfg = json_decode((string)@IPS_GetConfiguration($this->InstanceID), true);
        if (!is_array($cfg) || !array_key_exists($name, $cfg)) {
            return $vorgabe;
        }
        return $cfg[$name];
    }

    // ────────────────────────── Haken fuer die Maschine ──────────────────────────

    private function VoiceEnabled(): bool
    {
        return (bool)$this->VoiceProp('VoiceSyncEnabled', false) && $this->VoiceInstanceID() > 0;
    }

    private function VoiceInstanceID(): int
    {
        return (int)$this->VoiceProp('VoiceListID', 0);
    }

    private function VoicePushEnabled(): bool
    {
        return (bool)$this->VoiceProp('VoicePushLocal', true);
    }

    /**
     * Aufgaben haben kein Mengenfeld — hier gibt es nichts zu trennen.
     *
     * „3 Milch" ist ein Einkaufsartikel; eine Aufgabe heisst „Drei Angebote
     * einholen", und daraus „Angebote einholen" mit Menge 3 zu machen waere
     * Unsinn. Die Aufteilung bleibt der Einkaufsliste.
     */
    private function VoiceParseAmountEnabled(): bool
    {
        return false;
    }

    /** Der Bestand, geschluesselt nach der Aufgaben-Kennung. */
    private function VoiceLoad(): array
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
                'voiceId'     => (string)($item['voiceId'] ?? ''),
                'voiceSource' => (string)($item['voiceSource'] ?? ''),
                'done'        => ($item['done'] ?? false) === true,
            ];
        }
        return $raus;
    }

    private function VoiceIsDone(array $eintrag): bool
    {
        return (bool)($eintrag['done'] ?? false);
    }

    private function VoiceCreate(string $name, string $menge, string $voiceId, string $quelle): void
    {
        $name = trim($name);
        if ($name === '') {
            return;
        }
        // Ueber den normalen Weg, damit Benachrichtigung, Statistik und
        // Revision genauso laufen wie bei einer per App angelegten Aufgabe.
        $entwurf = ['title' => $name, 'info' => '', 'priority' => 'normal'];
        $wem = trim((string)$this->VoiceProp('VoiceAssignTo', ''));
        if ($wem !== '') {
            $entwurf['assignedTo'] = [$wem];
        }
        try {
            $id = $this->AddItem($entwurf);
        } catch (\Throwable $e) {
            $this->SendDebug('VoiceSync', 'Aufgabe nicht angelegt: ' . $e->getMessage(), 0);
            return;
        }
        if ($id > 0) {
            $this->VoiceStamp($id, $voiceId, $quelle);
        }
    }

    private function VoiceMarkDone(string|int $schluessel): void
    {
        try {
            $this->ToggleDone(['id' => (int)$schluessel, 'done' => true]);
        } catch (\Throwable $e) {
            $this->SendDebug('VoiceSync', 'Aufgabe nicht abgehakt: ' . $e->getMessage(), 0);
        }
    }

    private function VoiceSetId(string|int $schluessel, string $voiceId, string $quelle): void
    {
        $this->VoiceStamp((int)$schluessel, $voiceId, $quelle);
    }

    /**
     * Schreibt Kennung und Quelle an die Aufgabe.
     *
     * Eigener Weg statt UpdateItem: der zieht die ganze Nutzlast durch die
     * Pruefungen und wuerde Felder ueberschreiben, die hier niemand anfasst.
     */
    private function VoiceStamp(int $id, string $voiceId, string $quelle): void
    {
        $items = $this->LoadItems();
        foreach ($items as &$item) {
            if ((int)($item['id'] ?? 0) === $id) {
                $item['voiceId']     = $voiceId;
                $item['voiceSource'] = $quelle;
                $item['voiceSynced'] = time();
                $this->SaveItems($items);
                return;
            }
        }
    }

    // ────────────────────────── Formular ──────────────────────────

    /** @return array<string, mixed> */
    private function GetVoiceFormElements(): array
    {
        return [
            'type'     => 'ExpansionPanel',
            'caption'  => $this->Translate('External list integration'),
            'expanded' => false,
            'items'    => [
                [
                    'type'    => 'Label',
                    'caption' => $this->Translate('Works alongside CalDAV, Google and Microsoft — the voice list is not one of the exclusive sync backends.'),
                ],
                [
                    'type'    => 'CheckBox',
                    'name'    => 'VoiceSyncEnabled',
                    'caption' => $this->Translate('Sync with a voice list'),
                ],
                [
                    'type'         => 'SelectInstance',
                    'name'         => 'VoiceListID',
                    'caption'      => $this->Translate('Voice list'),
                    'validModules' => [VoiceSource::GUID_ALEXA, VoiceSource::GUID_BRING],
                    'width'        => '500px',
                ],
                [
                    'type'    => 'CheckBox',
                    'name'    => 'VoicePushLocal',
                    'caption' => $this->Translate('Also send tasks from this list to the voice list'),
                ],
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'VoiceAssignTo',
                    'caption' => $this->Translate('Assign spoken tasks to this member ID (optional)'),
                    'width'   => '360px',
                ],
                [
                    'type'    => 'Label',
                    'name'    => 'VoiceStatus',
                    'caption' => $this->GetVoiceStatusLabel(),
                ],
                [
                    'type'    => 'Button',
                    'caption' => $this->Translate('Sync now'),
                    'onClick' => 'echo TDL_VoiceSyncNow($id);',
                ],
                [
                    'type'    => 'Label',
                    'caption' => $this->Translate('For tasks you need a separate voice list instance set to the task list. The delay depends on that module: set its update interval to 1–2 minutes.'),
                ],
            ],
        ];
    }

    private function GetVoiceStatusLabel(): string
    {
        if (!(bool)$this->VoiceProp('VoiceSyncEnabled', false)) {
            return $this->Translate('Off');
        }
        $quelle = VoiceSource::For($this->VoiceInstanceID());
        if ($quelle === null) {
            return $this->Translate('No voice list selected');
        }
        $letzter = (int)@$this->ReadAttributeInteger('VoiceLastSync');
        return sprintf($this->Translate('Connected (%s) · last sync: %s'),
            $quelle->Key(),
            $letzter > 0 ? date('d.m.Y H:i', $letzter) : $this->Translate('never'));
    }

    private function VoiceSyncNowText(): string
    {
        if (!$this->VoiceEnabled()) {
            return $this->Translate('Switch the sync on and select a voice list first.');
        }
        $b = $this->VoiceSync();
        if (!$b['ok']) {
            return $b['reason'] === 'unreadable'
                ? $this->Translate('The voice list could not be read. Nothing was changed.')
                : sprintf($this->Translate('Sync did not run (%s).'), (string)$b['reason']);
        }
        return sprintf($this->Translate('%d added, %d sent, %d matched, %d checked off'),
            (int)$b['imported'], (int)$b['pushed'], (int)$b['resolved'], (int)$b['completed']);
    }
}

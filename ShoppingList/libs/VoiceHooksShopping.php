<?php

declare(strict_types=1);

/**
 * Anbindung der Einkaufsliste an eine Sprachliste (Alexa, Bring).
 *
 * Hier stehen nur die Haken, die VoiceListSync braucht — die Entscheidungen
 * fallen dort. Diese Datei uebersetzt zwischen der Maschine und unserem
 * ItemStore: dessen Eintraege haben eine Hex-Kennung, ein freies Mengenfeld und
 * `inCart` statt `done`.
 */
trait VoiceHooksShopping
{
    private function VoiceCreateProperties(): void
    {
        $this->RegisterPropertyBoolean('VoiceSyncEnabled', false);
        $this->RegisterPropertyInteger('VoiceListID', 0);
        $this->RegisterPropertyBoolean('VoicePushLocal', true);
        $this->RegisterPropertyBoolean('VoiceParseAmount', true);
        $this->RegisterAttributeInteger('VoiceLastSync', 0);
        $this->RegisterAttributeInteger('VoiceLastVariableID', 0);
    }

    /**
     * Eine Einstellung lesen, die es vielleicht noch nicht gibt.
     *
     * ReadProperty* auf eine noch nicht registrierte Eigenschaft WIRFT nicht,
     * sondern gibt eine PHP-Warnung in die Ausgabe — und die zerlegt den
     * Rueckgabewert von GetConfigurationForm (am lebenden System gesehen:
     * „Eigenschaft VoiceSyncEnabled nicht gefunden", Formular kam leer an).
     * Neue Eigenschaften entstehen erst, wenn Create() wieder laeuft, also nach
     * einem Kernel-Neustart. Ueber die Konfiguration gelesen wirkt die
     * Anbindung sofort — dasselbe Muster wie TtsSetting im Gateway.
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

    private function VoiceParseAmountEnabled(): bool
    {
        return (bool)$this->VoiceProp('VoiceParseAmount', true);
    }

    /** Der Bestand, geschluesselt nach der Artikel-Kennung. */
    private function VoiceLoad(): array
    {
        $raus = [];
        foreach ($this->LoadItems() as $item) {
            $id = (string)($item['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $raus[$id] = [
                'name'        => (string)($item['name'] ?? ''),
                'amount'      => (string)($item['amount'] ?? ''),
                'voiceId'     => (string)($item['voiceId'] ?? ''),
                'voiceSource' => (string)($item['voiceSource'] ?? ''),
                'done'        => ($item['inCart'] ?? false) === true,
            ];
        }
        return $raus;
    }

    /** „Erledigt" heisst hier: im Einkaufswagen. */
    private function VoiceIsDone(array $eintrag): bool
    {
        return (bool)($eintrag['done'] ?? false);
    }

    /**
     * Legt einen gesprochenen Artikel an.
     *
     * Zwei Wege, und der Unterschied ist gewollt: Ohne Menge geht es den
     * gewohnten Weg (AddItemInternal fasst einen gleichnamigen offenen Artikel
     * zusammen und erhoeht dessen Menge um 1). MIT gesprochener Menge waere das
     * falsch — „drei Milch" soll Menge 3 ergeben und nicht 2. Dann wird die
     * vorhandene Zeile per UpdateItemInternal auf die gesprochene Menge GESETZT.
     */
    private function VoiceCreate(string $name, string $menge, string $voiceId, string $quelle): void
    {
        $name = trim($name);
        if ($name === '') {
            return;
        }
        $treffer = $this->VoiceFindOpenByName($name);
        if ($menge !== '' && $treffer !== '') {
            $this->UpdateItemInternal($treffer, $name, $menge, '');
            $this->VoiceStamp($treffer, $voiceId, $quelle);
            return;
        }
        // Leere Kategorie: dann waehlt LookupCategory selbst — keine erfundene
        // Kategorie und dieselbe Zuordnung wie bei jedem anderen Artikel.
        if (!$this->AddItemInternal($name, '', $menge)) {
            return;
        }
        $ziel = $treffer !== '' ? $treffer : $this->VoiceFindOpenByName($name);
        if ($ziel !== '') {
            $this->VoiceStamp($ziel, $voiceId, $quelle);
        }
    }

    private function VoiceMarkDone(string|int $schluessel): void
    {
        $this->ToggleItemCart((string)$schluessel, true);
    }

    private function VoiceSetId(string|int $schluessel, string $voiceId, string $quelle): void
    {
        $this->VoiceStamp((string)$schluessel, $voiceId, $quelle);
    }

    // ────────────────────────── Helfer ──────────────────────────

    /**
     * Kennung des offenen Artikels mit diesem Namen, oder ''.
     *
     * Dieselbe Vergleichsregel wie im ItemStore (mb_strtolower auf den Namen,
     * nur nicht im Wagen) — sonst faende diese Suche etwas anderes als die
     * Duplikatpruefung des Anlegens.
     */
    private function VoiceFindOpenByName(string $name): string
    {
        $gesucht = mb_strtolower(trim($name));
        foreach ($this->LoadItems() as $item) {
            if (($item['inCart'] ?? false) === false && mb_strtolower((string)($item['name'] ?? '')) === $gesucht) {
                return (string)($item['id'] ?? '');
            }
        }
        return '';
    }

    /** Schreibt Kennung und Quelle an den Artikel. */
    private function VoiceStamp(string $id, string $voiceId, string $quelle): void
    {
        $riegel = 'SL_Items_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($riegel, 500)) {
            $this->SendDebug('VoiceSync', 'Riegel belegt — Kennung nicht vermerkt', 0);
            return;
        }
        try {
            $items = $this->LoadItems();
            foreach ($items as &$item) {
                if ((string)($item['id'] ?? '') === $id) {
                    $item['voiceId']     = $voiceId;
                    $item['voiceSource'] = $quelle;
                    $item['voiceSynced'] = time();
                    $this->SaveItems($items);
                    return;
                }
            }
        } finally {
            IPS_SemaphoreLeave($riegel);
        }
    }

    // ────────────────────────── Formular ──────────────────────────

    /** @return array<string, mixed> */
    private function GetVoiceFormElements(): array
    {
        return [
            'type'     => 'ExpansionPanel',
            'caption'  => $this->Translate('Voice assistant (Alexa, Bring)'),
            'expanded' => false,
            'items'    => [
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
                    'caption' => $this->Translate('Also send items from this list to the voice list'),
                ],
                [
                    'type'    => 'CheckBox',
                    'name'    => 'VoiceParseAmount',
                    'caption' => $this->Translate('Split a spoken amount from the name ("3 milk" becomes milk, amount 3)'),
                ],
                [
                    'type'    => 'Label',
                    'name'    => 'VoiceStatus',
                    'caption' => $this->GetVoiceStatusLabel(),
                ],
                [
                    'type'    => 'Button',
                    'caption' => $this->Translate('Sync now'),
                    'onClick' => 'echo SL_VoiceSyncNow($id);',
                ],
                [
                    'type'    => 'Label',
                    'caption' => $this->Translate('The delay depends on the voice module: it queries the cloud on its own schedule. Set its update interval to 1–2 minutes. With Bring the text box variable must be enabled, otherwise there is no trigger.'),
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

    /** Der Knopf im Formular. Gibt eine lesbare Bilanz zurueck. */
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

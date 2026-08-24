<?php

declare(strict_types=1);

/**
 * Anbindung der Einkaufsliste an externe Listen (Alexa, Bring).
 *
 * Hier stehen nur die Haken, die ExternalListSync braucht — die Entscheidungen
 * fallen dort. Diese Datei uebersetzt zwischen der Maschine und unserem
 * ItemStore: dessen Eintraege haben eine Hex-Kennung, ein freies Mengenfeld und
 * `inCart` statt `done`.
 *
 * BEIDE Dienste koennen gleichzeitig an derselben Liste haengen. Deshalb traegt
 * jeder Artikel `extIds` — eine Kennung je Dienst — und nicht eine einzige.
 */
trait ExtListHooksShopping
{
    private function ExtListCreateProperties(): void
    {
        $this->RegisterPropertyBoolean('ExtListEnabledProp', false);
        $this->RegisterPropertyInteger('AlexaListID', 0);
        $this->RegisterPropertyInteger('BringListID', 0);
        $this->RegisterPropertyBoolean('ExtListParseAmount', true);
        $this->RegisterAttributeInteger('ExtListLastSync', 0);
        $this->RegisterAttributeString('ExtListTriggerVars', '[]');
        // Welche fremden Kennungen lagen beim letzten Lauf vor — daran erkennt
        // der naechste, dass hier etwas geloescht wurde.
        $this->RegisterAttributeString('ExtListKnownIds', '{}');
        $this->RegisterAttributeString('ExtListRemovedIds', '{}');
    }

    /**
     * Eine Einstellung lesen, die es vielleicht noch nicht gibt.
     *
     * ReadProperty* auf eine noch nicht registrierte Eigenschaft WIRFT nicht,
     * sondern gibt eine PHP-Warnung in die Ausgabe — und die zerlegt den
     * Rueckgabewert von GetConfigurationForm (am lebenden System gesehen, das
     * Formular kam leer an). Neue Eigenschaften entstehen erst, wenn Create()
     * wieder laeuft, also nach einem Kernel-Neustart. Ueber die Konfiguration
     * gelesen wirkt die Anbindung sofort — Muster wie TtsSetting im Gateway.
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
     * Die eingerichteten Gegenstellen — je Feld eine, beide zusammen moeglich.
     *
     * @return list<ListSource>
     */
    private function ExtListSources(): array
    {
        if (!$this->ExtListEnabled()) {
            return [];
        }
        $raus = [];
        foreach (['AlexaListID', 'BringListID'] as $feld) {
            $id = (int)$this->ExtListProp($feld, 0);
            if ($id <= 0) {
                continue;
            }
            $quelle = ListSource::For($id);
            if ($quelle !== null) {
                $raus[] = $quelle;
            }
        }
        return $raus;
    }

    private function ExtListParseAmountEnabled(): bool
    {
        return (bool)$this->ExtListProp('ExtListParseAmount', true);
    }

    /** Der Bestand, geschluesselt nach der Artikel-Kennung. */
    private function ExtListLoad(): array
    {
        $raus = [];
        foreach ($this->LoadItems() as $item) {
            $id = (string)($item['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $raus[$id] = [
                'name'   => (string)($item['name'] ?? ''),
                'amount' => (string)($item['amount'] ?? ''),
                'extIds' => is_array($item['extIds'] ?? null) ? $item['extIds'] : [],
                'done'   => ($item['inCart'] ?? false) === true,
            ];
        }
        return $raus;
    }

    /** „Erledigt" heisst hier: im Einkaufswagen. */
    private function ExtListIsDone(array $eintrag): bool
    {
        return (bool)($eintrag['done'] ?? false);
    }

    /**
     * Legt einen Artikel von aussen an.
     *
     * Zwei Wege, und der Unterschied ist gewollt: OHNE Menge der gewohnte Weg
     * (AddItemInternal fasst einen gleichnamigen offenen Artikel zusammen und
     * erhoeht dessen Menge um 1). MIT Menge waere das falsch — „drei Milch" soll
     * Menge 3 ergeben und nicht 2 —, dann wird die vorhandene Zeile per
     * UpdateItemInternal auf die genannte Menge GESETZT.
     */
    private function ExtListCreate(string $name, string $menge, string $extId, string $quelle): void
    {
        $name = trim($name);
        if ($name === '') {
            return;
        }
        $treffer = $this->ExtListFindOpenByName($name);
        if ($menge !== '' && $treffer !== '') {
            $this->UpdateItemInternal($treffer, $name, $menge, '');
            $this->ExtListStamp($treffer, $extId, $quelle);
            return;
        }
        // Leere Kategorie: dann waehlt LookupCategory selbst — keine erfundene
        // Kategorie und dieselbe Zuordnung wie bei jedem anderen Artikel.
        if (!$this->AddItemInternal($name, '', $menge)) {
            return;
        }
        $ziel = $treffer !== '' ? $treffer : $this->ExtListFindOpenByName($name);
        if ($ziel !== '') {
            $this->ExtListStamp($ziel, $extId, $quelle);
        }
    }

    private function ExtListMarkDone(string|int $schluessel): void
    {
        $this->ToggleItemCart((string)$schluessel, true);
    }

    private function ExtListSetId(string|int $schluessel, string $extId, string $quelle): void
    {
        $this->ExtListStamp((string)$schluessel, $extId, $quelle);
    }

    // ────────────────────────── Helfer ──────────────────────────

    /**
     * Kennung des offenen Artikels mit diesem Namen, oder ''.
     *
     * Dieselbe Vergleichsregel wie im ItemStore (mb_strtolower, nur nicht im
     * Wagen) — sonst faende diese Suche etwas anderes als die Duplikatpruefung
     * des Anlegens.
     */
    private function ExtListFindOpenByName(string $name): string
    {
        $gesucht = mb_strtolower(trim($name));
        foreach ($this->LoadItems() as $item) {
            if (($item['inCart'] ?? false) === false && mb_strtolower((string)($item['name'] ?? '')) === $gesucht) {
                return (string)($item['id'] ?? '');
            }
        }
        return '';
    }

    /** Schreibt die Kennung DES DIENSTES an den Artikel. */
    private function ExtListStamp(string $id, string $extId, string $quelle): void
    {
        $riegel = 'SL_Items_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($riegel, 500)) {
            $this->SendDebug('ExtListSync', 'Riegel belegt — Kennung nicht vermerkt', 0);
            return;
        }
        try {
            $items = $this->LoadItems();
            foreach ($items as &$item) {
                if ((string)($item['id'] ?? '') === $id) {
                    // ANHAENGEN, nicht ersetzen: eine Zeile kann fuer mehrere
                    // fremde Eintraege stehen (Alexa dedupliziert nicht, unsere
                    // Liste fasst nach Namen zusammen). Altbestand war eine
                    // einzelne Zeichenkette.
                    $karte = is_array($item['extIds'] ?? null) ? $item['extIds'] : [];
                    $vorhanden = $karte[$quelle] ?? [];
                    if (is_string($vorhanden)) {
                        $vorhanden = $vorhanden === '' ? [] : [$vorhanden];
                    }
                    $vorhanden = array_values(array_map('strval', (array)$vorhanden));
                    // Einen wartenden Platzhalter ersetzt die echte Kennung.
                    if (strpos($extId, 'pending_') !== 0) {
                        $vorhanden = array_values(array_filter($vorhanden,
                            static fn(string $i): bool => strpos($i, 'pending_') !== 0));
                    }
                    if (!in_array($extId, $vorhanden, true)) {
                        $vorhanden[] = $extId;
                    }
                    $karte[$quelle] = $vorhanden;
                    $item['extIds'] = $karte;
                    $this->SaveItems($items);
                    return;
                }
            }
        } finally {
            IPS_SemaphoreLeave($riegel);
        }
    }

    // ────────────────────────── Formular ──────────────────────────

    /**
     * Der Bereich im Formular.
     *
     * Je Dienst ein eigener Unterbereich mit Auswahlfeld UND dem Hinweis, was
     * dafuer installiert werden muss — beides gehoert zusammen und stand vorher
     * getrennt. Die drei Schalter darueber gelten fuer BEIDE Dienste und bleiben
     * deshalb oben; sie je Dienst zu doppeln waere eine Einstellung, die man an
     * zwei Stellen suchen muss.
     *
     * @return array<string, mixed>
     */
    private function GetExtListFormElements(): array
    {
        return [
            'type'     => 'ExpansionPanel',
            'caption'  => $this->Translate('External shopping lists'),
            'expanded' => false,
            'items'    => [
                [
                    'type'    => 'CheckBox',
                    'name'    => 'ExtListEnabledProp',
                    'caption' => $this->Translate('Synchronisation active'),
                ],
                [
                    'type'    => 'CheckBox',
                    'name'    => 'ExtListParseAmount',
                    'caption' => $this->Translate('Split a stated amount from the name ("3 milk" becomes milk, amount 3)'),
                ],
                [
                    'type'    => 'Label',
                    'name'    => 'ExtListStatus',
                    'caption' => $this->GetExtListStatusLabel(),
                ],
                [
                    'type'    => 'Button',
                    'caption' => $this->Translate('Sync now'),
                    'onClick' => 'echo SL_ExtListSyncNow($id);',
                ],
                [
                    'type'     => 'ExpansionPanel',
                    'caption'  => $this->Translate('Amazon Alexa shopping list'),
                    'expanded' => false,
                    'items'    => [
                        [
                            'type'         => 'SelectInstance',
                            'name'         => 'AlexaListID',
                            'caption'      => $this->Translate('AlexaList instance'),
                            'validModules' => [ListSource::GUID_ALEXA],
                            'width'        => '500px',
                        ],
                        [
                            'type'    => 'Label',
                            'caption' => $this->Translate("What has to be installed:\n1. Library \"Echo Remote\" via Module Control: https://github.com/roastedelectrons/IPSymconEchoRemote\n2. Instance \"Echo IO\" — logs in to the Amazon account (once for everything).\n3. Instance \"AlexaList\" — one PER Amazon list. Set \"List\" to \"Shopping list (default)\" here; the task list needs its OWN second instance for the to-do list module.\n4. In that instance set the update interval to 2 MINUTES (default is 60) — its schedule decides how fast a spoken item arrives here.\n5. Do not go below that: the Echo module throttles its own activity polling to 60 requests per hour, and requests count PER AMAZON ACCOUNT, not per instance. At 1 minute a single instance already uses that whole budget, and our syncs come on top. Running two AlexaList instances (shopping + tasks): 3 minutes each."),
                        ],
                    ],
                ],
                [
                    'type'     => 'ExpansionPanel',
                    'caption'  => $this->Translate('Bring shopping list'),
                    'expanded' => false,
                    'items'    => [
                        [
                            'type'         => 'SelectInstance',
                            'name'         => 'BringListID',
                            'caption'      => $this->Translate('Bring List instance'),
                            'validModules' => [ListSource::GUID_BRING],
                            'width'        => '500px',
                        ],
                        [
                            'type'    => 'Label',
                            'caption' => $this->Translate("Bring is a shopping app, not a voice assistant.\n\nWhat has to be installed:\n1. Library \"Bring!\" via Module Control: https://github.com/Nall-chan/bring-symcon\n2. Instance \"Bring! Konto\" — asks for the e-mail and password of the Bring account.\n3. Instance \"Bring List\" — one per Bring list, select the list there.\n4. Switch ON \"Create text box variable\" in that instance: that variable is our trigger, and the module only writes it when the switch is on.\n5. Set the refresh interval there — those are SECONDS (60–120 is sensible), unlike Alexa's minutes.\n\nBring cannot check items off — an item bought here is removed there and lands in \"recently bought\", which means the same thing."),
                        ],
                    ],
                ],
                [
                    'type'    => 'Label',
                    'caption' => $this->Translate('Both can be used at the same time; then all three lists mirror each other.'),
                ],
            ],
        ];
    }

    private function GetExtListStatusLabel(): string
    {
        if (!$this->ExtListEnabled()) {
            return $this->Translate('Off');
        }
        $quellen = $this->ExtListSources();
        if ($quellen === []) {
            return $this->Translate('No external list selected');
        }
        $namen = [];
        foreach ($quellen as $q) {
            $namen[] = $q->Key();
        }
        $letzter = (int)@$this->ReadAttributeInteger('ExtListLastSync');
        return sprintf($this->Translate('Connected (%s) · last sync: %s'),
            implode(' + ', $namen),
            $letzter > 0 ? date('d.m.Y H:i', $letzter) : $this->Translate('never'));
    }

    /** Der Knopf im Formular. Gibt eine lesbare Bilanz zurueck. */
    private function ExtListSyncNowText(): string
    {
        if ($this->ExtListSources() === []) {
            return $this->Translate('Switch the sync on and select at least one external list first.');
        }
        $b = $this->ExtListSync();
        $text = sprintf($this->Translate('%d added, %d sent, %d matched, %d checked off, %d removed'),
            (int)$b['imported'], (int)$b['pushed'], (int)$b['resolved'], (int)$b['completed'], (int)$b['removed']);
        if ((string)$b['reason'] !== '') {
            // Auch bei Teilerfolg ehrlich sagen, was nicht ging.
            $text .= ' — ' . sprintf($this->Translate('problems: %s'), (string)$b['reason']);
        }
        return $text;
    }
}

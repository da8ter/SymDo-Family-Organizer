<?php

declare(strict_types=1);

/**
 * Sprachdialog — Werkzeugschicht.
 *
 * Das Modell ruft Werkzeuge aus diesem Katalog; ausgeführt wird ausschließlich
 * hier, gegen die vorhandenen Modulwege. Drei Regeln, die alle Werkzeuge
 * einhalten:
 *   1. Der Katalog ist die Weißliste — kein Aktionsname aus dem Modell wird
 *      je durchgereicht, jede Nutzlast wird feldweise gebaut.
 *   2. Antworten sind sprachgerecht schmal (VoiceCap): niemals ganze Zustände,
 *      der volle Einkaufszustand hat 166.000 Zeichen.
 *   3. Jede Antwort trägt `sag` — der Server formuliert den Satz, das Modell
 *      liest vor. Ein Modell, das aus rohen Fehlercodes selbst Sätze baut,
 *      erfindet Erklärungen.
 *
 * Stand: Etappe 1 — die lesenden Werkzeuge. Schreiben, Auflösen und Löschen
 * folgen in den nächsten Etappen.
 */
trait VoiceTools
{
    /** Obergrenze je Werkzeugantwort in Zeichen (json_encode-Länge). */
    private static int $VOICE_CAP = 2000;

    /**
     * Der Katalog. `schema` ist das OpenAI-Function-Schema; `art` steuert
     * Protokoll und (später) die Bestätigungspflicht.
     */
    private function VoiceKatalog(): array
    {
        return [
            'tag_uebersicht' => [
                'art' => 'lesen',
                'beschreibung' => 'Übersicht eines Tages: Termine, fällige und überfällige Aufgaben, Geburtstage, Schulzeiten, Abendessen und die Zahl der Einkäufe. Für Fragen wie "Was steht heute/morgen an?".',
                'schema' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'properties' => [
                        'tag' => ['type' => 'string', 'description' => '"heute", "morgen" oder ein Datum JJJJ-MM-TT'],
                    ],
                    'required' => ['tag'],
                ],
            ],
            'einkaufsliste_lesen' => [
                'art' => 'lesen',
                'beschreibung' => 'Liest die Einkaufsliste: was noch zu kaufen ist und was schon im Wagen liegt.',
                'schema' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'properties' => [
                        'liste' => ['type' => ['string', 'null'], 'description' => 'Name der Einkaufsliste; null = Standardliste'],
                    ],
                    'required' => ['liste'],
                ],
            ],
            'aufgaben_lesen' => [
                'art' => 'lesen',
                'beschreibung' => 'Liest die Aufgabenliste: offene, heutige oder überfällige Aufgaben.',
                'schema' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'properties' => [
                        'filter' => ['type' => 'string', 'enum' => ['offen', 'heute', 'ueberfaellig', 'alle']],
                        'liste'  => ['type' => ['string', 'null'], 'description' => 'Name der Aufgabenliste; null = Standardliste'],
                    ],
                    'required' => ['filter', 'liste'],
                ],
            ],
            'rezepte_lesen' => [
                'art' => 'lesen',
                'beschreibung' => 'Die gespeicherten Rezepte (Rezept-Favoritenlisten): ohne Angabe alle Namen, mit einem Rezeptnamen dessen Zutaten.',
                'schema' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'properties' => [
                        'rezept' => ['type' => ['string', 'null'], 'description' => 'Name eines Rezepts für die Zutaten; null = alle Rezepte auflisten'],
                        'liste'  => ['type' => ['string', 'null'], 'description' => 'Einkaufsliste, zu der die Rezepte gehören; null = Standardliste'],
                    ],
                    'required' => ['rezept', 'liste'],
                ],
            ],
            'rezept_einkaufen' => [
                'art' => 'schreiben',
                'beschreibung' => 'Setzt die Zutaten eines Rezepts auf die Einkaufsliste.',
                'schema' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'properties' => [
                        'rezept' => ['type' => 'string', 'description' => 'Name des Rezepts'],
                        'liste'  => ['type' => ['string', 'null'], 'description' => 'Ziel-Einkaufsliste; null = Standardliste'],
                    ],
                    'required' => ['rezept', 'liste'],
                ],
            ],
            'einkauf_hinzufuegen' => [
                'art' => 'schreiben',
                'beschreibung' => 'Setzt einen oder mehrere Artikel auf die Einkaufsliste.',
                'schema' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'properties' => [
                        'artikel' => [
                            'type' => 'array', 'description' => 'Die Artikel', 'maxItems' => 20,
                            'items' => [
                                'type' => 'object', 'additionalProperties' => false,
                                'properties' => [
                                    'name'  => ['type' => 'string'],
                                    'menge' => ['type' => ['string', 'null'], 'description' => 'z.B. "2", "500 g", null'],
                                ],
                                'required' => ['name', 'menge'],
                            ],
                        ],
                        'liste' => ['type' => ['string', 'null'], 'description' => 'Ziel-Einkaufsliste; null = Standardliste'],
                    ],
                    'required' => ['artikel', 'liste'],
                ],
            ],
            'aufgabe_anlegen' => [
                'art' => 'schreiben',
                'beschreibung' => 'Legt eine neue Aufgabe an.',
                'schema' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'properties' => [
                        'titel'   => ['type' => 'string'],
                        'info'    => ['type' => ['string', 'null']],
                        'frist'   => ['type' => ['string', 'null'], 'description' => 'Fälligkeitsdatum JJJJ-MM-TT oder null'],
                        'uhrzeit' => ['type' => ['string', 'null'], 'description' => 'HH:MM oder null (dann ganztägig)'],
                        'wichtig' => ['type' => 'boolean'],
                        'liste'   => ['type' => ['string', 'null'], 'description' => 'Ziel-Aufgabenliste; null = Standardliste'],
                    ],
                    'required' => ['titel', 'info', 'frist', 'uhrzeit', 'wichtig', 'liste'],
                ],
            ],
            'abhaken' => [
                'art' => 'schreiben',
                'beschreibung' => 'Hakt eine Aufgabe ab oder legt einen Einkaufsartikel in den Wagen (bzw. macht das rückgängig).',
                'schema' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'properties' => [
                        'was'      => ['type' => 'string', 'description' => 'Titel der Aufgabe oder Name des Artikels'],
                        'bereich'  => ['type' => ['string', 'null'], 'enum' => ['aufgabe', 'einkauf', null],
                                       'description' => 'Wo suchen; null = zuerst Aufgaben, dann Einkauf'],
                        'erledigt' => ['type' => 'boolean', 'description' => 'true = abhaken/in den Wagen, false = zurück'],
                        'liste'    => ['type' => ['string', 'null']],
                    ],
                    'required' => ['was', 'bereich', 'erledigt', 'liste'],
                ],
            ],
            'termine_lesen' => [
                'art' => 'lesen',
                'beschreibung' => 'Liest anstehende Termine aus dem Kalender.',
                'schema' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'properties' => [
                        'von'  => ['type' => 'string', 'description' => '"heute", "morgen" oder ein Datum JJJJ-MM-TT'],
                        'tage' => ['type' => 'integer', 'description' => 'Anzahl Tage ab "von", 1 bis 31'],
                    ],
                    'required' => ['von', 'tage'],
                ],
            ],
            'termin_anlegen' => [
                'art' => 'schreiben',
                'beschreibung' => 'Legt einen neuen Termin im Kalender an.',
                'schema' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'properties' => [
                        'titel'    => ['type' => 'string'],
                        'datum'    => ['type' => 'string', 'description' => 'JJJJ-MM-TT'],
                        'von'      => ['type' => ['string', 'null'], 'description' => 'Beginn HH:MM oder null (dann ganztägig)'],
                        'bis'      => ['type' => ['string', 'null'], 'description' => 'Ende HH:MM oder null'],
                        'ort'      => ['type' => ['string', 'null']],
                        'kalender' => ['type' => ['string', 'null'], 'description' => 'Name des Kalenders; null = Standardkalender'],
                    ],
                    'required' => ['titel', 'datum', 'von', 'bis', 'ort', 'kalender'],
                ],
            ],
        ];
    }

    /** Das `tools`-Feld der Realtime-Sitzung, direkt aus dem Katalog. */
    private function VoiceToolSpec(): array
    {
        $spec = [];
        foreach ($this->VoiceKatalog() as $name => $def) {
            $spec[] = [
                'type'        => 'function',
                'name'        => $name,
                'description' => (string)$def['beschreibung'],
                'parameters'  => $def['schema'],
            ];
        }
        return $spec;
    }

    /**
     * Ausführung: Weißliste, Argumente dekodieren, Werkzeugzweig, Deckel.
     * @param array{userId:string,defaults:array} $ctx
     * @return array<string,mixed>
     */
    private function VoiceRunTool(string $name, string $argsJson, array $ctx): array
    {
        $katalog = $this->VoiceKatalog();
        if (!isset($katalog[$name])) {
            // Zeichen für Manipulation oder ein halluziniertes Werkzeug.
            $this->VoiceLogEintrag($name, 'unbekanntes Werkzeug', false);
            return $this->VoiceErr('unknown_tool', $this->Translate('I cannot do that.'));
        }
        $args = json_decode($argsJson, true);
        if (!is_array($args)) {
            $args = [];
        }
        try {
            $antwort = match ($name) {
                'tag_uebersicht'      => $this->VoiceToolTag($args),
                'einkaufsliste_lesen' => $this->VoiceToolEinkauf($args, $ctx),
                'aufgaben_lesen'      => $this->VoiceToolAufgaben($args, $ctx),
                'rezepte_lesen'       => $this->VoiceToolRezepte($args, $ctx),
                'rezept_einkaufen'    => $this->VoiceToolRezeptEinkaufen($args, $ctx),
                'einkauf_hinzufuegen' => $this->VoiceToolEinkaufHinzu($args, $ctx),
                'aufgabe_anlegen'     => $this->VoiceToolAufgabeAnlegen($args, $ctx),
                'abhaken'             => $this->VoiceToolAbhaken($args, $ctx),
                'termine_lesen'       => $this->VoiceToolTermineLesen($args, $ctx),
                'termin_anlegen'      => $this->VoiceToolTerminAnlegen($args, $ctx),
            };
        } catch (\Throwable $e) {
            $this->SendDebug('Voice', 'Werkzeug ' . $name . ' warf: ' . $e->getMessage(), 0);
            $antwort = $this->VoiceErr('intern', $this->Translate('Something went wrong — nothing was changed.'));
        }
        $this->VoiceLogEintrag($name, (string)($antwort['sag'] ?? ''), ($antwort['ok'] ?? false) === true);
        return $this->VoiceCap($antwort);
    }

    // ------------------------------------------------------------------
    // Die Werkzeuge
    // ------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function VoiceToolTag(array $args): array
    {
        $tage = $this->VoiceTagOffset((string)($args['tag'] ?? 'heute'));
        if ($tage === null) {
            return $this->VoiceErr('ungueltige_eingabe', $this->Translate('I can only look up to seven days ahead.'));
        }
        // BriefingCollect sammelt alles ohne KI-Aufruf — genau die Auskunft,
        // aus der das Modell die gestellte Frage beantwortet.
        $b = $this->BriefingCollect($tage);
        $deckel = static fn($v, int $n): array => array_slice(array_values(array_filter(
            is_array($v) ? array_map('strval', $v) : [], static fn(string $z): bool => trim($z) !== ''
        )), 0, $n);
        return [
            'ok'  => true,
            'tag' => date('Y-m-d', strtotime('+' . $tage . ' day')),
            'termine'      => $deckel($b['termine'] ?? [], 8),
            'aufgaben'     => $deckel($b['aufgaben'] ?? [], 8),
            'ueberfaellig' => $deckel($b['ueberfaellig'] ?? [], 5),
            'anlaesse'     => $deckel($b['geburtstage'] ?? [], 3),
            'schule'       => $deckel($b['schule'] ?? [], 4),
            'essen'        => $deckel($b['essen'] ?? [], 2),
            'einkauf'      => is_array($b['einkauf'] ?? null)
                ? $deckel($b['einkauf'], 2)
                : (trim((string)($b['einkauf'] ?? '')) !== '' ? [(string)$b['einkauf']] : []),
        ];
    }

    /** @return array<string,mixed> */
    private function VoiceToolEinkauf(array $args, array $ctx): array
    {
        $ziel = $this->VoiceListeFinden('shopping', $args['liste'] ?? null, $ctx);
        if (!($ziel['ok'] ?? false)) {
            return $ziel;
        }
        $items = json_decode((string)@SL_GetItems((int)$ziel['id']), true);
        if (!is_array($items)) {
            return $this->VoiceErr('nicht_bereit', $this->Translate('The shopping list is not answering right now.'));
        }
        $offenListe = [];
        $imWagen = 0;
        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }
            if (($it['inCart'] ?? false) === true) {
                $imWagen++;
                continue;
            }
            $name  = trim((string)($it['name'] ?? ''));
            $menge = trim((string)($it['amount'] ?? ''));
            if ($name !== '') {
                $offenListe[] = $menge !== '' ? ($name . ' (' . $menge . ')') : $name;
            }
        }
        $gesamt = count($offenListe);
        $gezeigt = array_slice($offenListe, 0, 25);
        return [
            'ok'       => true,
            'liste'    => (string)$ziel['name'],
            'offen'    => $gesamt,
            'im_wagen' => $imWagen,
            'artikel'  => $gezeigt,
            'gekuerzt' => $gesamt > count($gezeigt),
            'sag'      => $gesamt === 0
                ? sprintf($this->Translate('Nothing left to buy on %s.'), (string)$ziel['name'])
                : sprintf($this->Translate('%d items still to buy on %s.'), $gesamt, (string)$ziel['name']),
        ];
    }

    /** @return array<string,mixed> */
    private function VoiceToolAufgaben(array $args, array $ctx): array
    {
        $ziel = $this->VoiceListeFinden('todo', $args['liste'] ?? null, $ctx);
        if (!($ziel['ok'] ?? false)) {
            return $ziel;
        }
        $st = json_decode((string)@TDL_GetAppState((int)$ziel['id']), true);
        $items = is_array($st) ? (($st['state'] ?? [])['items'] ?? null) : null;
        if (!is_array($items)) {
            return $this->VoiceErr('nicht_bereit', $this->Translate('The task list is not answering right now.'));
        }
        $filter = (string)($args['filter'] ?? 'offen');
        $heute  = date('Y-m-d');
        $raus   = [];
        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }
            $erledigt = ($it['done'] ?? false) === true;
            $due      = (int)($it['due'] ?? 0);
            $tag      = $due > 0 ? date('Y-m-d', $due) : '';
            $passt = match ($filter) {
                'alle'         => true,
                'heute'        => !$erledigt && $tag === $heute,
                'ueberfaellig' => !$erledigt && $tag !== '' && $tag < $heute,
                default        => !$erledigt,
            };
            if (!$passt) {
                continue;
            }
            $raus[] = [
                'titel'   => mb_substr(trim((string)($it['title'] ?? '')), 0, 60),
                'frist'   => $tag !== '' ? $tag : null,
                'wichtig' => (string)($it['priority'] ?? 'normal') === 'high',
            ];
        }
        $gesamt = count($raus);
        return [
            'ok'       => true,
            'liste'    => (string)$ziel['name'],
            'anzahl'   => $gesamt,
            'aufgaben' => array_slice($raus, 0, 20),
            'gekuerzt' => $gesamt > 20,
            'sag'      => $gesamt === 0
                ? $this->Translate('Nothing there — all done.')
                : sprintf($this->Translate('%d task(s) on %s.'), $gesamt, (string)$ziel['name']),
        ];
    }

    /** @return array<string,mixed> */
    private function VoiceToolTermineLesen(array $args, array $ctx): array
    {
        $offset = $this->VoiceTagOffset((string)($args['von'] ?? 'heute'));
        if ($offset === null) {
            return $this->VoiceErr('ungueltige_eingabe', $this->Translate('I did not understand the date.'));
        }
        $tage = max(1, min(31, (int)($args['tage'] ?? 7)));
        $von = (int)strtotime(date('Y-m-d', strtotime('+' . $offset . ' day')) . ' 00:00');
        $bis = $von + $tage * 86400;
        $r = $this->CalHandleAction(['action' => 'events', 'from' => $von, 'to' => $bis]);
        if (($r['ok'] ?? false) !== true) {
            return $this->VoiceErr('nicht_bereit', $this->Translate('The calendar is not answering right now.'));
        }
        $termine = [];
        foreach ((array)($r['events'] ?? []) as $e) {
            if (!is_array($e)) {
                continue;
            }
            $termine[] = $this->VoiceTerminZeile($e);
        }
        $gesamt = count($termine);
        return [
            'ok'       => true,
            'zeitraum' => $tage === 1 ? date('d.m.', $von) : (date('d.m.', $von) . '–' . date('d.m.', $bis - 1)),
            'anzahl'   => $gesamt,
            'termine'  => array_slice($termine, 0, 20),
            'gekuerzt' => $gesamt > 20,
            'sag'      => $gesamt === 0
                ? $this->Translate('No appointments in that period.')
                : sprintf($this->Translate('%d appointment(s).'), $gesamt),
        ];
    }

    /** Ein Termin als sprachgerechte Zeile: „Fr 05.09. 15:00 Zahnarzt (Praxis)". */
    private function VoiceTerminZeile(array $e): string
    {
        $start = (int)($e['start'] ?? 0);
        $wo    = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
        $wann  = $start > 0
            ? ($wo[(int)date('w', $start)] . ' ' . date('d.m.', $start)
               . (($e['allDay'] ?? false) === true ? ' ganztägig' : ' ' . date('H:i', $start)))
            : '';
        $titel = trim((string)($e['title'] ?? ''));
        $ort   = trim((string)($e['location'] ?? ''));
        return trim($wann . ' ' . $titel . ($ort !== '' ? ' (' . $ort . ')' : ''));
    }

    /** @return array<string,mixed> */
    private function VoiceToolTerminAnlegen(array $args, array $ctx): array
    {
        $titel = trim((string)($args['titel'] ?? ''));
        if ($titel === '') {
            return $this->VoiceErr('ungueltige_eingabe', $this->Translate('The appointment needs a title.'));
        }
        $datum = trim((string)($args['datum'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum) !== 1) {
            return $this->VoiceErr('ungueltige_eingabe', $this->Translate('I need a date for the appointment.'));
        }
        $kal = $this->VoiceKalenderFinden($args['kalender'] ?? null);
        if (!($kal['ok'] ?? false)) {
            return $kal;
        }
        $von = trim((string)($args['von'] ?? ''));
        $ganztags = preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $von) !== 1;
        $event = [
            'title'    => $titel,
            'allDay'   => $ganztags,
            'start'    => $ganztags ? $datum : ($datum . 'T' . $von),
            'location' => trim((string)($args['ort'] ?? '')),
        ];
        $bis = trim((string)($args['bis'] ?? ''));
        if (!$ganztags && preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $bis) === 1) {
            $event['end'] = $datum . 'T' . $bis;
        }
        // Mitglieder-Zuweisung: der Kachel-Benutzer, wenn vorhanden.
        if (($ctx['userId'] ?? '') !== '') {
            $event['members'] = [(string)$ctx['userId']];
        }
        $r = $this->CalHandleAction(['action' => 'create', 'calendarID' => (int)$kal['id'], 'event' => $event]);
        if (($r['ok'] ?? false) !== true) {
            $msg = (string)($r['error']['message'] ?? $this->Translate('The calendar rejected the appointment.'));
            return $this->VoiceErr((string)($r['error']['code'] ?? 'kalender_fehler'), $msg);
        }
        $wann = $ganztags ? ('am ' . date('d.m.', (int)strtotime($datum))) : ('am ' . date('d.m.', (int)strtotime($datum)) . ' um ' . $von);
        return [
            'ok'    => true,
            'titel' => $titel,
            'kalender' => (string)$kal['name'],
            'sag'   => sprintf($this->Translate('Appointment "%s" %s created in %s.'), $titel, $wann, (string)$kal['name']),
        ];
    }

    /**
     * Kalender nach Name oder Vorgabe (erster beschreibbarer). Nur beschreibbare
     * kommen infrage — ein Feiertagskalender lässt sich nicht bestücken.
     * @return array<string,mixed>
     */
    private function VoiceKalenderFinden(mixed $name): array
    {
        $r = $this->CalHandleAction(['action' => 'calendars']);
        $schreibbar = [];
        foreach ((array)($r['calendars'] ?? []) as $c) {
            if (is_array($c) && ($c['canWrite'] ?? false) === true) {
                $schreibbar[] = ['id' => (int)($c['id'] ?? 0), 'name' => (string)($c['name'] ?? '')];
            }
        }
        if ($schreibbar === []) {
            return $this->VoiceErr('nicht_erlaubt', $this->Translate('There is no writable calendar.'));
        }
        $such = is_string($name) ? trim($name) : '';
        if ($such === '') {
            return ['ok' => true] + $schreibbar[0];
        }
        $sn = $this->VoiceNorm($such);
        foreach ($schreibbar as $c) {
            $kn = $this->VoiceNorm($c['name']);
            if ($kn === $sn || str_contains($kn, $sn) || str_contains($sn, $kn)) {
                return ['ok' => true] + $c;
            }
        }
        $namen = array_map(static fn(array $c): string => $c['name'], $schreibbar);
        return [
            'ok' => false, 'error' => ['code' => 'unbekannter_kalender', 'message' => 'Kalender nicht gefunden'],
            'sag' => sprintf($this->Translate('Which calendar? There is: %s.'), implode(', ', $namen)),
        ];
    }

    /** @return array<string,mixed> */
    private function VoiceToolEinkaufHinzu(array $args, array $ctx): array
    {
        $ziel = $this->VoiceListeFinden('shopping', $args['liste'] ?? null, $ctx);
        if (!($ziel['ok'] ?? false)) {
            return $ziel;
        }
        $artikel = is_array($args['artikel'] ?? null) ? $args['artikel'] : [];
        if ($artikel === []) {
            return $this->VoiceErr('ungueltige_eingabe', $this->Translate('I did not catch any item.'));
        }
        $namen = [];
        $n = 0;
        foreach (array_slice($artikel, 0, 20) as $a) {
            if (!is_array($a)) {
                continue;
            }
            $name = trim((string)($a['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $menge = trim((string)($a['menge'] ?? ''));
            // Menge aus dem Namen ziehen: „2 Liter Milch" gehört getrennt, auch
            // wenn das Modell alles in den Namen geschrieben hat.
            [$name, $menge] = $this->VoiceMengeTrennen($name, $menge);
            // AppCall AddItem nimmt ein Objekt {name, category, amount} — feldweise.
            @SL_AppCall((int)$ziel['id'], 'AddItem', (string)json_encode(
                ['name' => $name, 'category' => '', 'amount' => $menge],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $namen[] = $menge !== '' ? ($name . ' (' . $menge . ')') : $name;
            $n++;
        }
        if ($n === 0) {
            return $this->VoiceErr('ungueltige_eingabe', $this->Translate('I did not catch any item.'));
        }
        return [
            'ok'    => true,
            'liste' => (string)$ziel['name'],
            'anzahl'=> $n,
            'sag'   => $n === 1
                ? sprintf($this->Translate('%s is on %s now.'), $namen[0], (string)$ziel['name'])
                : sprintf($this->Translate('%d items are on %s now: %s.'), $n, (string)$ziel['name'], implode(', ', $namen)),
        ];
    }

    /** @return array<string,mixed> */
    private function VoiceToolAufgabeAnlegen(array $args, array $ctx): array
    {
        $ziel = $this->VoiceListeFinden('todo', $args['liste'] ?? null, $ctx);
        if (!($ziel['ok'] ?? false)) {
            return $ziel;
        }
        $titel = trim((string)($args['titel'] ?? ''));
        if ($titel === '') {
            return $this->VoiceErr('ungueltige_eingabe', $this->Translate('The task needs a title.'));
        }
        // Frist + Uhrzeit → Zeitstempel. Ohne Uhrzeit ist die Aufgabe ganztägig.
        $due = 0;
        $ganztags = false;
        $frist = trim((string)($args['frist'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $frist) === 1) {
            $uhr = trim((string)($args['uhrzeit'] ?? ''));
            if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $uhr) === 1) {
                $due = (int)strtotime($frist . ' ' . $uhr);
            } else {
                $due = (int)strtotime($frist . ' 00:00');
                $ganztags = true;
            }
        }
        $payload = [
            'title'      => $titel,
            'info'       => trim((string)($args['info'] ?? '')),
            'due'        => $due,
            'dueAllDay'  => $ganztags,
            'priority'   => ($args['wichtig'] ?? false) === true ? 'high' : 'normal',
            'assignedTo' => ($ctx['userId'] ?? '') !== '' ? [(string)$ctx['userId']] : [],
        ];
        @TDL_AppCall((int)$ziel['id'], 'AddItem', (string)json_encode($payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $wann = $due > 0 ? ($ganztags ? (' am ' . date('d.m.', $due)) : (' am ' . date('d.m. H:i', $due))) : '';
        return [
            'ok'    => true,
            'liste' => (string)$ziel['name'],
            'titel' => $titel,
            'sag'   => sprintf($this->Translate('Task "%s" created%s.'), $titel, $wann),
        ];
    }

    /** @return array<string,mixed> */
    private function VoiceToolAbhaken(array $args, array $ctx): array
    {
        $was = trim((string)($args['was'] ?? ''));
        if ($was === '') {
            return $this->VoiceErr('ungueltige_eingabe', $this->Translate('What should I check off?'));
        }
        $erledigt = ($args['erledigt'] ?? true) !== false;
        $bereich = (string)($args['bereich'] ?? '');

        // Reihenfolge: ausdrücklicher Bereich zuerst; sonst Aufgaben, dann Einkauf.
        $versuche = $bereich === 'einkauf' ? ['einkauf']
                  : ($bereich === 'aufgabe' ? ['aufgabe'] : ['aufgabe', 'einkauf']);
        $letzterFehler = null;
        foreach ($versuche as $b) {
            if ($b === 'aufgabe') {
                $ziel = $this->VoiceListeFinden('todo', $args['liste'] ?? null, $ctx);
                if (!($ziel['ok'] ?? false)) { $letzterFehler = $ziel; continue; }
                $erg = $this->VoiceAufgabeAufloesen((int)$ziel['id'], $was);
                $fehler = $this->VoiceAufloeseFehler($erg, $was, $this->Translate('tasks'));
                if ($fehler === null) {
                    $t = $erg['treffer'][0];
                    @TDL_AppCall((int)$ziel['id'], 'ToggleDone', (string)json_encode(
                        ['id' => (int)$t['schluessel'], 'done' => $erledigt]));
                    return ['ok' => true, 'bereich' => 'aufgabe', 'titel' => (string)$t['titel'],
                        'sag' => sprintf($erledigt ? $this->Translate('"%s" is checked off.')
                                                   : $this->Translate('"%s" is open again.'), (string)$t['titel'])];
                }
                $letzterFehler = $fehler;
            } else {
                $ziel = $this->VoiceListeFinden('shopping', $args['liste'] ?? null, $ctx);
                if (!($ziel['ok'] ?? false)) { $letzterFehler = $ziel; continue; }
                $erg = $this->VoiceArtikelAufloesen((int)$ziel['id'], $was);
                $fehler = $this->VoiceAufloeseFehler($erg, $was, $this->Translate('shopping items'));
                if ($fehler === null) {
                    $t = $erg['treffer'][0];
                    @SL_AppCall((int)$ziel['id'], 'ToggleCart', (string)json_encode(
                        ['id' => (string)$t['schluessel'], 'inCart' => $erledigt]));
                    return ['ok' => true, 'bereich' => 'einkauf', 'titel' => (string)$t['titel'],
                        'sag' => sprintf($erledigt ? $this->Translate('"%s" is in the cart.')
                                                   : $this->Translate('"%s" is back on the list.'), (string)$t['titel'])];
                }
                $letzterFehler = $fehler;
            }
        }
        return $letzterFehler ?? $this->VoiceErr('nicht_gefunden', $this->Translate('I did not find that.'));
    }

    /** @return array<string,mixed> */
    private function VoiceToolRezepte(array $args, array $ctx): array
    {
        $ziel = $this->VoiceListeFinden('shopping', $args['liste'] ?? null, $ctx);
        if (!($ziel['ok'] ?? false)) {
            return $ziel;
        }
        $rezepte = $this->VoiceRezeptListe((int)$ziel['id']);
        $such = is_string($args['rezept'] ?? null) ? trim($args['rezept']) : '';
        if ($such === '') {
            $namen = array_map(static fn(array $r): string => $r['name'], $rezepte);
            return [
                'ok'      => true,
                'anzahl'  => count($namen),
                'rezepte' => array_slice($namen, 0, 30),
                'gekuerzt'=> count($namen) > 30,
                'sag'     => count($namen) === 0
                    ? $this->Translate('There are no recipes saved yet.')
                    : sprintf($this->Translate('%d recipes saved.'), count($namen)),
            ];
        }
        $treffer = $this->VoiceRezeptFinden($rezepte, $such);
        if (($treffer['status'] ?? '') !== 'eindeutig') {
            return $this->VoiceRezeptMehrdeutig($treffer, $such);
        }
        $r = $treffer['rezept'];
        $zutaten = [];
        foreach ((array)($r['items'] ?? []) as $it) {
            if (!is_array($it)) {
                continue;
            }
            $name  = trim((string)($it['name'] ?? ''));
            $menge = trim((string)($it['amount'] ?? ''));
            if ($name !== '') {
                $zutaten[] = $menge !== '' ? ($name . ' (' . $menge . ')') : $name;
            }
        }
        return [
            'ok'      => true,
            'rezept'  => (string)$r['name'],
            'zutaten' => array_slice($zutaten, 0, 30),
            'anzahl'  => count($zutaten),
            'gekuerzt'=> count($zutaten) > 30,
            'sag'     => count($zutaten) === 0
                ? sprintf($this->Translate('The recipe "%s" has no ingredients stored.'), (string)$r['name'])
                : sprintf($this->Translate('The recipe "%s" has %d ingredients.'), (string)$r['name'], count($zutaten)),
        ];
    }

    /** @return array<string,mixed> */
    private function VoiceToolRezeptEinkaufen(array $args, array $ctx): array
    {
        $ziel = $this->VoiceListeFinden('shopping', $args['liste'] ?? null, $ctx);
        if (!($ziel['ok'] ?? false)) {
            return $ziel;
        }
        $rezepte = $this->VoiceRezeptListe((int)$ziel['id']);
        $treffer = $this->VoiceRezeptFinden($rezepte, is_string($args['rezept'] ?? null) ? trim($args['rezept']) : '');
        if (($treffer['status'] ?? '') !== 'eindeutig') {
            return $this->VoiceRezeptMehrdeutig($treffer, (string)($args['rezept'] ?? ''));
        }
        $r = $treffer['rezept'];
        // AddFavoriteListToCart nimmt die ROHE listId (kein JSON) — feldweise, nie
        // die Nutzlast des Modells durchreichen.
        @SL_AppCall((int)$ziel['id'], 'AddFavoriteListToCart', (string)$r['id']);
        $anzahl = count((array)($r['items'] ?? []));
        return [
            'ok'     => true,
            'rezept' => (string)$r['name'],
            'liste'  => (string)$ziel['name'],
            'anzahl' => $anzahl,
            'sag'    => sprintf($this->Translate('The ingredients for "%s" are now on %s.'),
                (string)$r['name'], (string)$ziel['name']),
        ];
    }

    /**
     * Die Rezept-Favoritenlisten einer Einkaufsliste, schmal. Der volle Zustand
     * ist 166.000 Zeichen — er bleibt in PHP, wir nehmen nur die Favoriten mit
     * isRecipe.
     * @return list<array{id:string,name:string,items:array}>
     */
    private function VoiceRezeptListe(int $slId): array
    {
        $st = json_decode((string)@SL_GetAppState($slId), true);
        $favs = is_array($st) ? (($st['state'] ?? [])['favoriteLists'] ?? null) : null;
        $raus = [];
        foreach (is_array($favs) ? $favs : [] as $f) {
            if (is_array($f) && ($f['isRecipe'] ?? false) === true) {
                $raus[] = [
                    'id'    => (string)($f['id'] ?? ''),
                    'name'  => (string)($f['name'] ?? ''),
                    'items' => is_array($f['items'] ?? null) ? $f['items'] : [],
                ];
            }
        }
        return $raus;
    }

    /**
     * Rezeptname unscharf auflösen — wie VoiceListeFinden, aber über die
     * Rezeptnamen. @return array{status:string,rezept?:array,treffer?:array}
     */
    private function VoiceRezeptFinden(array $rezepte, string $such): array
    {
        if ($such === '') {
            return ['status' => 'nichts', 'treffer' => []];
        }
        $sn = $this->VoiceNorm($such);
        $genau = [];
        $teil  = [];
        foreach ($rezepte as $r) {
            $kn = $this->VoiceNorm($r['name']);
            if ($kn === $sn) {
                $genau[] = $r;
            } elseif (str_contains($kn, $sn) || str_contains($sn, $kn)) {
                $teil[] = $r;
            }
        }
        if (count($genau) === 1) {
            return ['status' => 'eindeutig', 'rezept' => $genau[0]];
        }
        $kandidaten = $genau !== [] ? $genau : $teil;
        if (count($kandidaten) === 1) {
            return ['status' => 'eindeutig', 'rezept' => $kandidaten[0]];
        }
        if ($kandidaten === []) {
            return ['status' => 'nichts', 'treffer' => []];
        }
        return ['status' => 'mehrdeutig', 'treffer' => $kandidaten];
    }

    /** @return array<string,mixed> */
    private function VoiceRezeptMehrdeutig(array $treffer, string $such): array
    {
        if (($treffer['status'] ?? '') === 'nichts') {
            return [
                'ok' => false, 'error' => ['code' => 'nicht_gefunden', 'message' => 'Rezept nicht gefunden'],
                'sag' => sprintf($this->Translate('I cannot find a recipe called "%s".'), $such),
            ];
        }
        $namen = array_map(static fn(array $r): string => $r['name'], array_slice($treffer['treffer'] ?? [], 0, 5));
        return [
            'ok' => false, 'error' => ['code' => 'mehrdeutig', 'message' => 'Rezept nicht eindeutig'],
            'sag' => sprintf($this->Translate('Which recipe do you mean? For example: %s.'), implode(', ', $namen)),
            'treffer' => $namen,
        ];
    }

    // ------------------------------------------------------------------
    // Helfer
    // ------------------------------------------------------------------

    /**
     * Liste nach Name oder Vorgabe finden. Passt ein Name nicht eindeutig,
     * kommt die Auswahl zurück, damit das Modell nachfragen kann statt zu raten.
     * @return array<string,mixed> ok:true+id+name | Fehlerform
     */
    private function VoiceListeFinden(string $art, mixed $name, array $ctx): array
    {
        $alle = [];
        foreach ($this->GetListInstances() as $inst) {
            if ((string)($inst['kind'] ?? '') === $art) {
                // GetListInstances liefert nur id+kind — der Name haengt am Objekt.
                // Namenlose Instanzen bekommen einen Notnamen: ein leerer Name
                // passt sonst per str_contains auf JEDE Suche und macht alles
                // mehrdeutig (live an einer unbenannten ToDo-Liste gemessen).
                $id = (int)$inst['id'];
                $n  = trim((string)@IPS_GetName($id));
                $alle[] = ['id' => $id, 'name' => $n !== '' ? $n : ('Liste ' . $id)];
            }
        }
        if ($alle === []) {
            return $this->VoiceErr('nicht_erlaubt', $art === 'shopping'
                ? $this->Translate('There is no shopping list here.')
                : $this->Translate('There is no task list here.'));
        }
        $such = is_string($name) ? trim($name) : '';
        if ($such === '') {
            $vorgabe = (int)($ctx['defaults'][$art] ?? 0);
            foreach ($alle as $l) {
                if ($l['id'] === $vorgabe) {
                    return ['ok' => true] + $l;
                }
            }
            return ['ok' => true] + $alle[0];
        }
        $suchNorm = $this->VoiceNorm($such);
        $treffer  = [];
        foreach ($alle as $l) {
            $kandNorm = $this->VoiceNorm($l['name']);
            if ($kandNorm === $suchNorm
                || str_contains($kandNorm, $suchNorm) || str_contains($suchNorm, $kandNorm)) {
                $treffer[] = $l;
            }
        }
        if (count($treffer) === 1) {
            return ['ok' => true] + $treffer[0];
        }
        $namen = array_map(static fn(array $l): string => $l['name'], $treffer !== [] ? $treffer : $alle);
        return [
            'ok'  => false,
            'error' => ['code' => 'unbekannte_liste', 'message' => 'Liste nicht eindeutig'],
            'sag' => sprintf($this->Translate('Which list do you mean? There is: %s.'), implode(', ', $namen)),
            'listen' => $namen,
        ];
    }

    /**
     * Ist die Einheit ein reiner Stück-/Gebinde-Zähler? Dann trägt nur die Zahl
     * die Menge (Nutzerwunsch 02.09.2026: „1 Packung Butter" → Menge „1").
     */
    private function VoiceGebinde(string $einheit): bool
    {
        $e = strtr(mb_strtolower(trim($einheit)), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        return in_array($e, [
            'stück', 'stueck', 'stk', 'st', 'packung', 'packungen', 'pck', 'pkg', 'paket',
            'dose', 'dosen', 'flasche', 'flaschen', 'glas', 'glaeser', 'becher',
            'tüte', 'tuete', 'tueten', 'tüten', 'beutel', 'bund', 'kopf', 'koepfe',
            'zehe', 'zehen', 'scheibe', 'scheiben', 'blatt', 'blaetter',
            'tafel', 'tafeln', 'riegel', 'rolle', 'rollen', 'kasten', 'kiste',
            'paar', 'portion', 'portionen',
        ], true);
    }

    /**
     * Trennt eine führende Mengenangabe vom Artikelnamen. „2 Liter Milch" →
     * ['Milch', '2 Liter']; „500g Mehl" → ['Mehl', '500 g']; „6 Eier" →
     * ['Eier', '6']; „Milch" → ['Milch', ''] (unverändert).
     *
     * Läuft IMMER: schrieb das Modell alles in den Namen, wird korrigiert;
     * hatte es die Menge schon getrennt, bleibt sie und der Name wird nur
     * bereinigt, falls die Menge dort noch einmal steht.
     *
     * @return array{0:string,1:string} [name, menge]
     */
    private function VoiceMengeTrennen(string $name, string $menge): array
    {
        $roh = trim($name);
        // Zahlwörter am Anfang → Ziffer (Diktat liefert oft Wörter).
        $zahlwort = [
            'ein' => '1', 'eine' => '1', 'einen' => '1', 'eins' => '1',
            'zwei' => '2', 'drei' => '3', 'vier' => '4', 'fünf' => '5', 'fuenf' => '5',
            'sechs' => '6', 'sieben' => '7', 'acht' => '8', 'neun' => '9',
            'zehn' => '10', 'elf' => '11', 'zwölf' => '12', 'zwoelf' => '12',
            'ein halbes' => '0,5', 'ein halber' => '0,5', 'anderthalb' => '1,5',
        ];
        foreach ($zahlwort as $wort => $ziffer) {
            if (preg_match('/^' . preg_quote($wort, '/') . '\b/iu', $roh) === 1) {
                $roh = $ziffer . ' ' . preg_replace('/^' . preg_quote($wort, '/') . '\s*/iu', '', $roh);
                break;
            }
        }
        // Bekannte Einheiten (mit Punkt-Varianten).  greift bei „g"/„l" sauber.
        // Lange Einheiten ZUERST — bei Alternation gewinnt der erste Treffer,
        // sonst schluckt „l" das „L" von „Liter".
        $einheiten = 'kilogramm|messerspitze|packungen|portionen|flaschen|'
            . 'scheiben|packung|flasche|portion|gramm|kilo|liter|becher|beutel|'
            . 'gläser|blätter|scheibe|köpfe|zehen|blatt|tüten|tueten|dosen|'
            . 'prisen|tafeln|rollen|kasten|kiste|riegel|handvoll|'
            . 'stück|paket|dose|glas|tüte|bund|kopf|zehe|prise|tafel|rolle|paar|'
            . 'stk|pck|pkg|msp|kg|mg|ml|cl|dl|el|tl|st|g|l';
        $zahl = '\d+(?:[.,]\d+)?';
        $extrakt = '';
        $rest = $roh;
        // A: Zahl + Einheit + Rest
        if (preg_match('/^(' . $zahl . ')\s*(' . $einheiten . ')\b\.?\s+(.+)$/iu', $roh, $m) === 1) {
            // Gebinde-Einheiten (Packung, Dose, …) sind nur Zähler und fallen
            // weg — „1 Packung Butter" ist „1 Butter". Echte Maßeinheiten
            // (g, kg, Liter, EL) bleiben, „500" allein wäre sinnlos.
            $extrakt = $this->VoiceGebinde($m[2]) ? $m[1] : ($m[1] . ' ' . $m[2]);
            $rest = trim($m[3]);
        // B: nur Zahl + Rest (der Rest muss mit einem Buchstaben beginnen, sonst
        //    ist es kein „6 Eier", sondern etwa „3-Minuten-Terrine")
        } elseif (preg_match('/^(' . $zahl . ')\s+(\p{L}.+)$/u', $roh, $m) === 1) {
            $extrakt = $m[1];
            $rest = trim($m[2]);
        }
        if ($extrakt === '') {
            return [$roh, $menge];   // keine führende Menge — unverändert
        }
        // Menge gesetzt lassen, wenn das Modell sie schon getrennt hatte; sonst
        // die extrahierte übernehmen. Der Name ist in jedem Fall der bereinigte.
        return [$rest !== '' ? $rest : $roh, $menge !== '' ? $menge : $extrakt];
    }

    /** Kleinschreibung + Umlautfaltung — levenshtein/Vergleiche sind sonst falsch geeicht. */
    private function VoiceNorm(string $t): string
    {
        $t = mb_strtolower(trim($t));
        return strtr($t, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
    }

    /** „heute"/„morgen"/Datum → Tagesabstand 0..7, sonst null. */
    private function VoiceTagOffset(string $tag): ?int
    {
        $t = $this->VoiceNorm($tag);
        if ($t === '' || $t === 'heute') {
            return 0;
        }
        if ($t === 'morgen') {
            return 1;
        }
        if ($t === 'uebermorgen') {
            return 2;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $t, $m) === 1
            && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            $diff = (int)floor((strtotime($t . ' 12:00') - strtotime(date('Y-m-d') . ' 12:00')) / 86400);
            return ($diff >= 0 && $diff <= 7) ? $diff : null;
        }
        return null;
    }

    /**
     * Der Systemteil der Sitzung: wer spricht, wer zum Haushalt gehört, welche
     * Listen es gibt — KEINE Kennungen, KEINE Inhalte. Deckel 1200 Zeichen.
     */
    private function VoiceInstructions(string $userId): string
    {
        $wer = '';
        $familie = [];
        try {
            foreach ($this->LoadUsers() as $u) {
                $n = trim((string)($u['name'] ?? ''));
                if ($n === '') {
                    continue;
                }
                $familie[] = $n;
                if ((string)($u['id'] ?? '') === $userId && $userId !== '') {
                    $wer = $n;
                }
            }
        } catch (\Throwable $e) {
            // ohne Gateway-Benutzer eben ohne Namen
        }
        $einkauf = [];
        $aufgaben = [];
        foreach ($this->GetListInstances() as $inst) {
            $n = (string)@IPS_GetName((int)($inst['id'] ?? 0));
            if ($n === '') {
                continue;
            }
            if ((string)($inst['kind'] ?? '') === 'shopping') {
                $einkauf[] = $n;
            } else {
                $aufgaben[] = $n;
            }
        }
        $zeilen = [
            'Du bist SymDo, der Sprachassistent dieses Haushalts. Sprich Deutsch, antworte in ein bis zwei kurzen Sätzen, außer man bittet um mehr.',
            'Heute ist ' . $this->VoiceDatumZeile() . '.',
        ];
        if ($wer !== '') {
            $zeilen[] = 'Du sprichst mit ' . $wer . '. „ich", „mir" und „meine Aufgaben" heißen: ' . $wer . '.';
        }
        if ($familie !== []) {
            $zeilen[] = 'Zum Haushalt gehören: ' . implode(', ', array_slice($familie, 0, 10)) . '.';
        }
        if ($einkauf !== []) {
            $zeilen[] = 'Einkaufslisten: ' . implode(', ', array_slice($einkauf, 0, 6)) . '.';
        }
        if ($aufgaben !== []) {
            $zeilen[] = 'Aufgabenlisten: ' . implode(', ', array_slice($aufgaben, 0, 6)) . '.';
        }
        // Gibt es Rezepte, dem Modell sagen, dass es sie abfragen kann.
        $rezAnzahl = 0;
        foreach ($this->GetListInstances() as $inst) {
            if ((string)($inst['kind'] ?? '') === 'shopping') {
                $rezAnzahl += count($this->VoiceRezeptListe((int)($inst['id'] ?? 0)));
            }
        }
        if ($rezAnzahl > 0) {
            $zeilen[] = 'Es gibt ' . $rezAnzahl . ' gespeicherte Rezepte; frag sie mit dem Werkzeug rezepte_lesen ab.';
        }
        $zeilen[] = 'Bevor du ein Werkzeug aufrufst, sage in einem kurzen Satz, was du tust.';
        $zeilen[] = 'Sage nie, etwas sei erledigt, bevor ein Werkzeug ok:true gemeldet hat. Erfinde keine Listeninhalte; wenn ein Werkzeug nichts findet, sage das. Lies das Feld "sag" einer Antwort sinngemäß vor. Nenne niemals Kennungen oder technische Fehlermeldungen.';
        $text = implode("\n", $zeilen);
        return mb_strlen($text) > 1200 ? mb_substr($text, 0, 1200) : $text;
    }

    private function VoiceDatumZeile(): string
    {
        $tage = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
        return $tage[(int)date('w')] . ', der ' . date('d.m.Y') . ', ' . date('H:i') . ' Uhr';
    }

    /** Antwort auf die Obergrenze stutzen: Listenfelder von hinten kürzen. */
    private function VoiceCap(array $antwort): array
    {
        $mess = static fn(array $a): int => strlen((string)json_encode($a, JSON_UNESCAPED_UNICODE));
        $runden = 0;
        // Je Runde faellt die HAELFTE der laengsten Liste — eintragsweises Kappen
        // braeuchte bei grossen Antworten hunderte Runden (gemessen: 19k Zeichen).
        while ($mess($antwort) > self::$VOICE_CAP && $runden < 24) {
            $runden++;
            $laengste = '';
            $max = 0;
            foreach ($antwort as $k => $v) {
                if (is_array($v) && array_is_list($v) && count($v) > $max) {
                    $max = count($v);
                    $laengste = (string)$k;
                }
            }
            if ($laengste === '' || $max === 0) {
                break;
            }
            $behalten = ($max > 1) ? intdiv($max, 2) : 0;
            $antwort[$laengste] = array_slice($antwort[$laengste], 0, $behalten);
            $antwort['gekuerzt'] = true;
        }
        return $antwort;
    }

    /** Ringpuffer „Was hat die KI getan?" — 50 Einträge, auch Fehlschläge. */
    private function VoiceLogEintrag(string $werkzeug, string $text, bool $ok): void
    {
        try {
            $log = json_decode((string)@$this->ReadAttributeString('VoiceLog'), true);
            $log = is_array($log) ? $log : [];
            array_unshift($log, ['t' => time(), 'werkzeug' => $werkzeug,
                'text' => mb_substr($text, 0, 160), 'ok' => $ok]);
            @$this->WriteAttributeString('VoiceLog',
                (string)json_encode(array_slice($log, 0, 50), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } catch (\Throwable $e) {
            // Protokoll darf nie die Ausführung reißen.
        }
    }
}

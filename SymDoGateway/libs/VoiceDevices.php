<?php

declare(strict_types=1);

/**
 * Geräte im Haus per Sprache — die Datenseite.
 *
 * Bis zum 07.09.2026 war der Sprachassistent ausdrücklich eingezäunt („Du
 * steuerst NICHTS im Haus"); hier bekommt er erstmals einen Weg in den
 * Objektbaum. Drei Regeln halten ihn eng:
 *
 *  1. Die REICHWEITE sind Wurzel-Kategorien aus dem Formular (VoiceDeviceRoots).
 *     Steuerbar ist, was darunter sichtbar ist und eine Aktion hat; die nächste
 *     Elternkategorie ist der Raum („Wohnzimmer Deckenlampe"). Der volle Pfad
 *     im Baum verlässt den Server nie (dieselbe Haltung wie AppCore, location).
 *  2. Der KATALOG wird je Aufruf frisch gelaufen, nicht gecacht: IPS_GetObject
 *     und IPS_GetVariable kosten je Objekt Mikrosekunden, und ein Cache brächte
 *     nur Staleness und ein neues Attribut. Darstellungen werden NUR für das
 *     aufgelöste Ziel gelesen.
 *  3. Jede Schaltung wird GEGENGELESEN. RequestAction ist beim Zielmodul oft
 *     asynchron; der Satz behauptet nur, was der Rücklesewert zeigt.
 *
 * Werte kommen GESPROCHEN an („an", „50 Prozent", „21,5 Grad", „hoch", „Auto")
 * und werden hier aus der Darstellung der Variable in den echten Wert
 * übersetzt: geklemmt wird nie stillschweigend (150 % ist ein Fehler, keine
 * 100), eingerastet wird auf die Schrittweite, gerundet auf die Stellen.
 *
 * Rollläden folgen dem Symcon-Profil ~Shutter (0 = offen, 100 = geschlossen);
 * „.Reversed" bzw. ein Umkehr-Schlüssel der Darstellung kehrt das um. Im
 * Bestand dieses Hauses gibt es keine Rollladen-Variable, an der sich der
 * Schlüsselname der Darstellung messen ließ — die Suche unten ist deshalb
 * großzügig (REVERSED, REVERSE, INVERT) und der Rückfall ist „nicht umgekehrt".
 * Zahlen werden immer wörtlich gesetzt; ein Fehlgriff träfe nur „hoch/runter".
 */
trait VoiceDevices
{
    /** Szenensteuerung (Kern-Modul) — Szenen sind Kindvariablen Scene<n>. */
    private const VOICE_SZS_GUID = '{87F46796-CC43-442D-94FD-AAA0BD8D9F54}';
    /** Deckel des Katalogs — mehr liest niemand vor, und der Lauf muss unter 8 s bleiben. */
    private const VOICE_GERAETE_DECKEL = 500;
    private const VOICE_GERAETE_TIEFE = 12;
    /** Alte Darstellung: der GUID sagt „nimm das Profil". */
    private const VOICE_PRES_LEGACY = '4153A8D4-5C33-C65F-C1F3-7B61AAF99B1C';
    private const VOICE_PRES_SWITCH = '60AE6B26-B3E2-BDB1-A3A1-BE232940664B';
    private const VOICE_PRES_SLIDER = '6B9CAEEC-5958-C223-30F7-BD36569FC57A';
    private const VOICE_PRES_ENUM   = '52D9E126-D7D2-2CBB-5E62-4CF7BA7C5D82';
    private const VOICE_PRES_SHUTTER = '6075FC22-69AF-B110-3749-C24138883082';
    private const VOICE_PRES_COLOR  = '05CC3CC2-A0B2-5837-A4A7-A07EA0B9DDFB';
    private const VOICE_PRES_PLAYBACK = '2F0FF5B0-FC86-117B-DDAA-2D2D33C3F8AC';

    /** Merkzettel je Anfrage: der Katalog wird in einem Werkzeugaufruf höchstens einmal gelaufen. */
    private ?array $voiceGeraeteMemo = null;

    // ────────────────────────────── Katalog ──────────────────────────────

    /** @return list<int> */
    private function VoiceGeraeteWurzeln(): array
    {
        return array_values(array_filter($this->VoiceObjektListe('VoiceDeviceRoots'),
            static fn(int $id): bool => @IPS_ObjectExists($id)));
    }

    /**
     * Alle Einträge unter den Wurzeln. Ein Eintrag:
     *   id, typ (var|skript|szene), name, raum, titel, alias[], vt, akt, via
     *   (Link, über den er gefunden wurde, sonst 0), wurzel, inst/nr (Szene)
     *
     * @return list<array<string,mixed>>
     */
    private function VoiceGeraeteKatalog(): array
    {
        if ($this->voiceGeraeteMemo !== null) {
            return $this->voiceGeraeteMemo;
        }
        $start = microtime(true);
        $eintraege = [];
        $gesehen = [];
        $voll = false;
        foreach ($this->VoiceGeraeteWurzeln() as $wurzel) {
            $this->VoiceGeraeteLaufen($wurzel, (string)@IPS_GetName($wurzel), 0, $wurzel, true, $eintraege, $gesehen, $voll);
        }
        usort($eintraege, static fn(array $a, array $b): int
            => strcmp((string)$a['raum'], (string)$b['raum']) ?: strcmp((string)$a['name'], (string)$b['name']));
        $this->SendDebug('Voice', sprintf('Gerätekatalog: %d Einträge in %d ms%s', count($eintraege),
            (int)round((microtime(true) - $start) * 1000), $voll ? ' — GEDECKELT' : ''), 0);
        $this->voiceGeraeteMemo = $eintraege;
        return $eintraege;
    }

    /**
     * @param list<array<string,mixed>> $eintraege
     * @param array<int,int> $gesehen  Zielobjekt → Index im Katalog (Dedup über Links)
     */
    private function VoiceGeraeteLaufen(int $id, string $raum, int $tiefe, int $wurzel, bool $istWurzel,
                                        array &$eintraege, array &$gesehen, bool &$voll): void
    {
        if ($voll || $tiefe > self::VOICE_GERAETE_TIEFE) {
            return;
        }
        if (count($eintraege) >= self::VOICE_GERAETE_DECKEL) {
            $voll = true;
            return;
        }
        $o = @IPS_GetObject($id);
        if (!is_array($o)) {
            return;
        }
        // Versteckte Teilbäume fallen weg — die Wurzel selbst darf versteckt sein,
        // sie wurde ausdrücklich gewählt.
        if (!$istWurzel && ($o['ObjectIsHidden'] ?? false) === true) {
            return;
        }
        $typ = (int)($o['ObjectType'] ?? -1);
        switch ($typ) {
            case 0: // Kategorie: der nächste Raumname
                $raum = $istWurzel ? $raum : trim((string)($o['ObjectName'] ?? $raum));
                break;
            case 6: // Link: das Ziel unter dem Namen des Links, ohne dessen Kinder zu laufen
                $link = @IPS_GetLink($id);
                $ziel = (int)($link['TargetID'] ?? 0);
                if ($ziel > 0 && @IPS_ObjectExists($ziel)) {
                    $zo = @IPS_GetObject($ziel);
                    $zt = (int)($zo['ObjectType'] ?? -1);
                    if ($zt === 2) {
                        $this->VoiceGeraetVariableAufnehmen($ziel, trim((string)($o['ObjectName'] ?? '')), $raum, $id, $wurzel, $eintraege, $gesehen);
                    } elseif ($zt === 3) {
                        $this->VoiceGeraetSkriptAufnehmen($ziel, trim((string)($o['ObjectName'] ?? '')), $raum, $id, $wurzel, $eintraege, $gesehen);
                    } elseif ($zt === 1) {
                        // Link auf eine Instanz: ihre Variablen unter dem Link-Namen
                        foreach ((array)($zo['ChildrenIDs'] ?? []) as $kind) {
                            $ko = @IPS_GetObject((int)$kind);
                            if (is_array($ko) && (int)($ko['ObjectType'] ?? -1) === 2 && ($ko['ObjectIsHidden'] ?? false) !== true) {
                                $this->VoiceGeraetVariableAufnehmen((int)$kind, '', $raum, $id, $wurzel, $eintraege, $gesehen,
                                    trim((string)($o['ObjectName'] ?? '')));
                            }
                        }
                    }
                }
                return;
            case 1: // Instanz: Szenensteuerung gesondert, sonst die Variablen darunter
                if ($this->VoiceIstSzenensteuerung($id)) {
                    $this->VoiceSzenenAufnehmen($id, $raum, $wurzel, $eintraege, $gesehen);
                    return;
                }
                break;
            case 2:
                $this->VoiceGeraetVariableAufnehmen($id, '', $raum, 0, $wurzel, $eintraege, $gesehen);
                return;
            case 3:
                $this->VoiceGeraetSkriptAufnehmen($id, '', $raum, 0, $wurzel, $eintraege, $gesehen);
                return;
            default: // Ereignisse, Medien
                return;
        }
        foreach ((array)($o['ChildrenIDs'] ?? []) as $kind) {
            $this->VoiceGeraeteLaufen((int)$kind, $raum, $tiefe + 1, $wurzel, false, $eintraege, $gesehen, $voll);
        }
    }

    private function VoiceIstSzenensteuerung(int $id): bool
    {
        $inst = @IPS_GetInstance($id);
        return is_array($inst) && strcasecmp((string)($inst['ModuleInfo']['ModuleID'] ?? ''), self::VOICE_SZS_GUID) === 0;
    }

    /**
     * @param list<array<string,mixed>> $eintraege
     * @param array<int,int> $gesehen
     */
    private function VoiceGeraetVariableAufnehmen(int $varId, string $anzeigeName, string $raum, int $via, int $wurzel,
                                                  array &$eintraege, array &$gesehen, string $instanzName = ''): void
    {
        $name = $this->VoiceGeraetName($varId, $anzeigeName, $instanzName);
        if (isset($gesehen[$varId])) {
            // Über zwei Wege gefunden (Link und direkt): ein Eintrag, zweiter Titel als Alias.
            $titel = $this->VoiceGeraetTitel($raum, $name);
            $e =& $eintraege[$gesehen[$varId]];
            if ($titel !== $e['titel'] && !in_array($titel, $e['alias'], true)) {
                $e['alias'][] = $titel;
            }
            return;
        }
        $v = @IPS_GetVariable($varId);
        if (!is_array($v)) {
            return;
        }
        $eintraege[] = [
            'id'     => $varId,
            'typ'    => 'var',
            'name'   => $name,
            'raum'   => $raum,
            'titel'  => $this->VoiceGeraetTitel($raum, $name),
            'alias'  => [],
            'vt'     => (int)($v['VariableType'] ?? 0),
            'akt'    => $this->VoiceGeraetAktionierbar($v),
            'via'    => $via,
            'wurzel' => $wurzel,
        ];
        $gesehen[$varId] = count($eintraege) - 1;
    }

    /**
     * @param list<array<string,mixed>> $eintraege
     * @param array<int,int> $gesehen
     */
    private function VoiceGeraetSkriptAufnehmen(int $id, string $anzeigeName, string $raum, int $via, int $wurzel,
                                                array &$eintraege, array &$gesehen): void
    {
        if (isset($gesehen[$id])) {
            return;
        }
        $name = trim($anzeigeName !== '' ? $anzeigeName : (string)@IPS_GetName($id));
        if ($name === '') {
            $name = $this->Translate('Script') . ' ' . $id;
        }
        $eintraege[] = ['id' => $id, 'typ' => 'skript', 'name' => $name, 'raum' => $raum,
                        'titel' => $this->VoiceGeraetTitel($raum, $name), 'alias' => [],
                        'vt' => null, 'akt' => true, 'via' => $via, 'wurzel' => $wurzel];
        $gesehen[$id] = count($eintraege) - 1;
    }

    /**
     * @param list<array<string,mixed>> $eintraege
     * @param array<int,int> $gesehen
     */
    private function VoiceSzenenAufnehmen(int $inst, string $raum, int $wurzel, array &$eintraege, array &$gesehen): void
    {
        $io = @IPS_GetObject($inst);
        foreach ((array)($io['ChildrenIDs'] ?? []) as $kind) {
            $ko = @IPS_GetObject((int)$kind);
            if (!is_array($ko) || (int)($ko['ObjectType'] ?? -1) !== 2 || isset($gesehen[(int)$kind])) {
                continue;
            }
            if (preg_match('/^Scene(\d+)$/i', (string)($ko['ObjectIdent'] ?? ''), $m) !== 1) {
                continue;
            }
            $name = trim((string)($ko['ObjectName'] ?? ''));
            if ($name === '') {
                $name = $this->Translate('Scene') . ' ' . $m[1];
            }
            $eintraege[] = ['id' => (int)$kind, 'typ' => 'szene', 'name' => $name, 'raum' => $raum,
                            'titel' => $this->VoiceGeraetTitel($raum, $name),
                            'alias' => [trim($this->Translate('Scene') . ' ' . $name)],
                            'vt' => null, 'akt' => true, 'via' => 0, 'wurzel' => $wurzel,
                            'inst' => $inst, 'nr' => (int)$m[1]];
            $gesehen[(int)$kind] = count($eintraege) - 1;
        }
    }

    /**
     * Der gesprochene Name einer Variable.
     *
     * Ein leerer Name passt per Teilwort auf JEDE Suche (Falle aus
     * VoiceListeFinden) — deshalb gibt es immer einen. Und eine Gerätevariable
     * heißt meist „Status" oder „Zustand": dann ist die INSTANZ das Gerät.
     */
    private function VoiceGeraetName(int $varId, string $anzeigeName, string $instanzName = ''): string
    {
        if ($anzeigeName !== '') {
            return $anzeigeName;            // der Link-Name ist die Absicht des Nutzers
        }
        $o = @IPS_GetObject($varId);
        $varName = trim((string)($o['ObjectName'] ?? ''));
        $eltern = (int)($o['ParentID'] ?? 0);
        $eo = $eltern > 0 ? @IPS_GetObject($eltern) : null;
        $istInstanz = is_array($eo) && (int)($eo['ObjectType'] ?? -1) === 1;
        if ($instanzName === '' && $istInstanz) {
            $instanzName = trim((string)($eo['ObjectName'] ?? ''));
        }
        $generisch = ['status', 'state', 'zustand', 'schalter', 'switch', 'level', 'wert', 'value',
                      'power', 'on', 'onoff', 'on_off', 'position', 'ein aus', 'ein/aus'];
        if ($istInstanz && $instanzName !== '') {
            if ($varName === '' || in_array($this->VoiceNorm($varName), $generisch, true)
                || $this->VoiceEinzigeAktion($eltern, $varId)) {
                return $instanzName;
            }
            return $instanzName . ' ' . $varName;
        }
        if ($varName !== '') {
            return $varName;
        }
        return $this->Translate('Device') . ' ' . $varId;
    }

    /** Ist $varId die einzige aktionierbare Variable ihrer Instanz? Dann IST sie das Gerät. */
    private function VoiceEinzigeAktion(int $inst, int $varId): bool
    {
        $o = @IPS_GetObject($inst);
        $n = 0;
        $dabei = false;
        foreach ((array)($o['ChildrenIDs'] ?? []) as $kind) {
            if (!@IPS_VariableExists((int)$kind)) {
                continue;
            }
            $v = @IPS_GetVariable((int)$kind);
            if (is_array($v) && $this->VoiceGeraetAktionierbar($v)) {
                $n++;
                $dabei = $dabei || (int)$kind === $varId;
                if ($n > 1) {
                    return false;
                }
            }
        }
        return $n === 1 && $dabei;
    }

    private function VoiceGeraetTitel(string $raum, string $name): string
    {
        if ($raum === '' || str_starts_with($this->VoiceNorm($name), $this->VoiceNorm($raum))) {
            return $name;
        }
        return $raum . ' ' . $name;
    }

    /**
     * Hat die Variable eine Aktion, die es auch gibt? CustomAction schlägt Action;
     * 1 heißt „ausdrücklich keine". Existenzprobe wie in RoomTile — eine
     * gelöschte Aktionsinstanz ließe RequestAction werfen.
     *
     * @param array<string,mixed> $v
     */
    private function VoiceGeraetAktionierbar(array $v): bool
    {
        $custom = (int)($v['VariableCustomAction'] ?? 0);
        $aktion = $custom > 1 ? $custom : ($custom === 0 ? (int)($v['VariableAction'] ?? 0) : 0);
        return $aktion > 1 && (@IPS_InstanceExists($aktion) || @IPS_ScriptExists($aktion));
    }

    /** Liegt das Objekt (oder der Link, über den es kam) unter einer der Wurzeln? */
    private function VoiceGeraetImUmfang(int $objectID, int $via = 0): bool
    {
        foreach ($this->VoiceGeraeteWurzeln() as $wurzel) {
            if ($objectID === $wurzel || @IPS_IsChild($objectID, $wurzel, true)) {
                return true;
            }
            if ($via > 0 && @IPS_IsChild($via, $wurzel, true)) {
                $link = @IPS_GetLink($via);
                if ((int)($link['TargetID'] ?? 0) === $objectID) {
                    return true;
                }
                // Link auf die Instanz, deren Variable gemeint ist
                $o = @IPS_GetObject($objectID);
                if ((int)($link['TargetID'] ?? 0) === (int)($o['ParentID'] ?? -1)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Für das Formular: Zahlen und Räume, und was von der Rückfrage-Liste außerhalb liegt. */
    private function VoiceGeraeteKatalogStand(): array
    {
        $katalog = $this->VoiceGeraeteKatalog();
        $raeume = [];
        $geraete = 0;
        $skripte = 0;
        foreach ($katalog as $e) {
            if ($e['raum'] !== '' && !in_array($e['raum'], $raeume, true)) {
                $raeume[] = $e['raum'];
            }
            if ($e['typ'] === 'var') {
                $geraete++;
            } else {
                $skripte++;
            }
        }
        $drin = array_map(static fn(array $e): int => (int)$e['id'], $katalog);
        $ausserhalb = array_values(array_filter($this->VoiceObjektListe('VoiceDeviceConfirm'),
            static fn(int $id): bool => !in_array($id, $drin, true)));
        return ['geraete' => $geraete, 'skripte' => $skripte, 'raeume' => $raeume,
                'rueckfrageAusserhalb' => $ausserhalb, 'gedeckelt' => count($katalog) >= self::VOICE_GERAETE_DECKEL];
    }

    // ────────────────────────────── Auflösung ──────────────────────────────

    /**
     * Ein Gerät aus dem Gesagten. Eigener Scorer über VoicePunkte statt
     * VoiceAufloesen: das schneidet bei 300 Kandidaten und zählte zwei Titel
     * DESSELBEN Ziels als zwei Treffer („Deckenlampe" wäre mehrdeutig mit sich
     * selbst). Rückgabeform wie VoiceAufloesen, damit VoiceAufloeseFehler die
     * Rückfrage formuliert.
     *
     * @param list<array<string,mixed>> $eintraege
     * @return array{status:string,treffer:list<array>,beinah:list<array>}
     */
    private function VoiceGeraeteAufloesen(string $such, array $eintraege, string $raum = ''): array
    {
        $sn = $this->VoiceGeraetSuchNorm($such);
        if ($raum !== '') {
            $eintraege = array_values(array_filter($eintraege,
                fn(array $e): bool => $this->VoiceRaumPasst((string)$e['raum'], $raum)));
        }
        $varianten = $this->VoiceGeraetSynonyme($sn);
        $bewertet = [];
        foreach ($eintraege as $e) {
            /* Titel, Name, Aliasse — aber KEINE gedrehte Variante „Name Raum":
               die Wortreihenfolge deckt die 80-Punkte-Regel („jedes Wort steckt
               drin") ohnehin ab, und die gedrehte Form traf per similar_text mit
               71 auf Fremdes („Prüfschalter Prüfraum" ~ „Außenschalter
               Prüfstand") — genug, um zu schalten. Gemessen am Prüfstand. */
            $titel = array_merge([(string)$e['titel'], (string)$e['name']], (array)$e['alias']);
            $best = 0;
            foreach ($titel as $t) {
                $tn = $this->VoiceNorm($t);
                foreach ($varianten as $v) {
                    $best = max($best, $this->VoicePunkte($v, $tn));
                }
            }
            $bewertet[] = ['schluessel' => $e, 'titel' => (string)$e['titel'], 'punkte' => $best];
        }
        usort($bewertet, static fn(array $a, array $b): int => $b['punkte'] <=> $a['punkte']);
        /* Treffer ab 70, nicht ab 60 wie beim Auflöser für Listen: 70 ist das
           Levenshtein-Niveau, darunter trägt nur noch similar_text — und das
           ist bei Geräten zu wenig, um zu SCHALTEN. Gemessen am Prüfstand:
           „Außenschalter Prüfstand" (außerhalb der Wurzel) traf mit 60+ auf
           „Prüfraum Prüfschalter" als einziger Treffer, galt als eindeutig
           und wurde geschaltet. Ein schwacher Einzeltreffer wird jetzt zur
           Rückfrage („Meintest du …?"), nie zur Handlung. */
        $treffer = array_values(array_filter($bewertet, static fn(array $b): bool => $b['punkte'] >= 70));
        $beinah  = array_values(array_filter($bewertet, static fn(array $b): bool => $b['punkte'] >= 45 && $b['punkte'] < 70));
        if ($treffer === []) {
            // Vertrag von VoiceAufloeseFehler: beinah sind TITEL, keine Zeilen — die
            // Zeile trüge den ganzen Katalogeintrag samt Objektnummer ins Gespräch.
            return ['status' => 'nichts', 'treffer' => [],
                    'beinah' => array_map(static fn(array $b): string => (string)$b['titel'], array_slice($beinah, 0, 3))];
        }
        $eindeutig = count($treffer) === 1
            || ($treffer[0]['punkte'] >= 90 && $treffer[1]['punkte'] < 80);
        return ['status' => $eindeutig ? 'eindeutig' : 'mehrdeutig',
                'treffer' => array_slice($treffer, 0, $eindeutig ? 1 : 5), 'beinah' => []];
    }

    /** Gesagtes ohne Artikel und Füllwörter, normalisiert. */
    private function VoiceGeraetSuchNorm(string $such): string
    {
        $sn = $this->VoiceNorm($such);
        $sn = (string)preg_replace('/^(?:die|der|das|den|dem|im|in|am|an|auf|bitte)\s+/u', '', $sn);
        return trim((string)preg_replace('/\s+/u', ' ', $sn));
    }

    /**
     * Die Suche in ihren Synonymen — nur die Gruppen, die in einem Haushalt
     * wirklich durcheinandergehen. Der Katalogname bleibt, was er ist.
     * @return list<string>
     */
    private function VoiceGeraetSynonyme(string $sn): array
    {
        $gruppen = [
            ['licht', 'lampe', 'leuchte', 'beleuchtung'],
            ['heizung', 'thermostat', 'temperatur', 'heizkoerper'],
            ['rollladen', 'rollo', 'jalousie', 'raffstore', 'markise'],
            ['steckdose', 'stecker', 'dose'],
            ['fernseher', 'tv', 'glotze'],
            ['fenster', 'fensterkontakt'],
            ['tuer', 'haustuer', 'tuerschloss', 'schloss'],
        ];
        $aus = [$sn];
        foreach ($gruppen as $g) {
            foreach ($g as $wort) {
                if (str_contains($sn, $wort)) {
                    foreach ($g as $ersatz) {
                        if ($ersatz !== $wort) {
                            $aus[] = str_replace($wort, $ersatz, $sn);
                        }
                    }
                    break;
                }
            }
        }
        return array_values(array_unique($aus));
    }

    private function VoiceRaumPasst(string $raumEintrag, string $gesagt): bool
    {
        $a = $this->VoiceNorm($raumEintrag);
        $b = $this->VoiceGeraetSuchNorm($gesagt);
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b || str_contains($a, $b) || str_contains($b, $a)) {
            return true;
        }
        $synonyme = [
            ['bad', 'badezimmer', 'bath', 'dusche'],
            ['wc', 'toilette', 'gaeste wc', 'gaestebad'],
            ['flur', 'diele', 'gang', 'eingang', 'treppenhaus'],
            ['wohnzimmer', 'wohnbereich', 'stube', 'wohnen', 'wohnraum'],
            ['schlafzimmer', 'schlafen', 'elternschlafzimmer'],
            ['kueche', 'kochen', 'essbereich', 'esszimmer'],
            ['buero', 'arbeitszimmer', 'office', 'arbeiten'],
            ['kinderzimmer', 'kind', 'kids'],
            ['garten', 'draussen', 'aussen', 'terrasse', 'balkon', 'hof'],
            ['keller', 'garage', 'hwr', 'hauswirtschaftsraum', 'waschkueche'],
        ];
        foreach ($synonyme as $g) {
            $ia = false;
            $ib = false;
            foreach ($g as $w) {
                $ia = $ia || str_contains($a, $w);
                $ib = $ib || str_contains($b, $w);
            }
            if ($ia && $ib) {
                return true;
            }
        }
        return $this->VoicePunkte($b, $a) >= 80;
    }

    /** @return list<string> Räume mit Geräten, in Katalogreihenfolge. */
    private function VoiceGeraeteRaeume(): array
    {
        $raeume = [];
        foreach ($this->VoiceGeraeteKatalog() as $e) {
            if ($e['raum'] !== '' && !in_array($e['raum'], $raeume, true)) {
                $raeume[] = $e['raum'];
            }
        }
        return $raeume;
    }

    // ────────────────────────────── Darstellung ──────────────────────────────

    /**
     * Was die Variable KANN — live gelesen, nur fürs Ziel. IPS_GetVariablePresentation
     * löst Vorlagen und Vererbung auf; der Legacy-GUID heißt „nimm das Profil".
     *
     * @return array{art:string,vt:int,min:float,max:float,step:float,digits:int,suffix:string,
     *               options:list<array{0:mixed,1:string}>,an:string,aus:string,reversed:bool}
     */
    private function VoiceGeraetSpezifikation(int $id): array
    {
        $v = @IPS_GetVariable($id);
        $vt = (int)($v['VariableType'] ?? 0);
        $spec = ['art' => 'lesen', 'vt' => $vt, 'min' => 0.0, 'max' => 0.0, 'step' => 0.0, 'digits' => -1,
                 'suffix' => '', 'options' => [], 'an' => $this->Translate('on'), 'aus' => $this->Translate('off'),
                 'reversed' => false];
        $p = null;
        if (function_exists('IPS_GetVariablePresentation')) {
            try {
                $p = @IPS_GetVariablePresentation($id);
            } catch (\Throwable $e) {
                $p = null;
            }
        }
        $guid = is_array($p) ? strtoupper(trim((string)($p['PRESENTATION'] ?? ''), '{} ')) : '';
        $legacy = !is_array($p) || $guid === '' || $guid === self::VOICE_PRES_LEGACY;

        if (!$legacy) {
            $spec['art'] = match ($guid) {
                self::VOICE_PRES_SWITCH   => 'schalter',
                self::VOICE_PRES_SLIDER   => 'regler',
                self::VOICE_PRES_ENUM     => 'auswahl',
                self::VOICE_PRES_SHUTTER  => 'rollladen',
                self::VOICE_PRES_COLOR    => 'farbe',
                self::VOICE_PRES_PLAYBACK => 'wiedergabe',
                default                   => '',
            };
            if (isset($p['MIN'], $p['MAX']) && (float)$p['MAX'] > (float)$p['MIN']) {
                $spec['min'] = (float)$p['MIN'];
                $spec['max'] = (float)$p['MAX'];
            }
            if (isset($p['STEP_SIZE']) && (float)$p['STEP_SIZE'] > 0) {
                $spec['step'] = (float)$p['STEP_SIZE'];
            }
            if (isset($p['DIGITS'])) {
                $spec['digits'] = (int)$p['DIGITS'];
            }
            $spec['suffix'] = trim((string)($p['SUFFIX'] ?? ''));
            if (trim((string)($p['CAPTION_ON'] ?? '')) !== '') {
                $spec['an'] = trim((string)$p['CAPTION_ON']);
            }
            if (trim((string)($p['CAPTION_OFF'] ?? '')) !== '') {
                $spec['aus'] = trim((string)$p['CAPTION_OFF']);
            }
            $spec['options'] = $this->VoiceGeraetOptionen($p['OPTIONS'] ?? null);
            foreach (['REVERSED', 'REVERSE', 'INVERT', 'INVERTED'] as $k) {
                if (($p[$k] ?? false) === true) {
                    $spec['reversed'] = true;
                }
            }
        } else {
            $profil = trim((string)($v['VariableCustomProfile'] ?? '')) ?: trim((string)($v['VariableProfile'] ?? ''));
            if ($profil !== '' && @IPS_VariableProfileExists($profil)) {
                $pr = @IPS_GetVariableProfile($profil);
                if (is_array($pr)) {
                    if ((float)($pr['MaxValue'] ?? 0) > (float)($pr['MinValue'] ?? 0)) {
                        $spec['min'] = (float)$pr['MinValue'];
                        $spec['max'] = (float)$pr['MaxValue'];
                    }
                    if ((float)($pr['StepSize'] ?? 0) > 0) {
                        $spec['step'] = (float)$pr['StepSize'];
                    }
                    $spec['digits'] = (int)($pr['Digits'] ?? -1);
                    $spec['suffix'] = trim((string)($pr['Suffix'] ?? ''));
                    foreach ((array)($pr['Associations'] ?? []) as $a) {
                        if (is_array($a) && isset($a['Value'])) {
                            $spec['options'][] = [$a['Value'], trim((string)($a['Name'] ?? ''))];
                        }
                    }
                    $spec['reversed'] = str_ends_with($profil, '.Reversed');
                    if (str_starts_with($profil, '~Shutter')) {
                        $spec['art'] = 'rollladen';
                    }
                }
            }
        }
        if ($spec['art'] === '' || $spec['art'] === 'lesen') {
            // Aus dem Typ ableiten, wenn die Darstellung nichts sagt
            $spec['art'] = match (true) {
                $vt === 0                              => 'schalter',
                $spec['options'] !== []                => 'auswahl',
                $vt === 3                              => 'text',
                default                                => 'regler',
            };
        }
        if ($vt === 0 && $spec['options'] !== []) {
            // Ein Bool mit Beschriftungen: „Offen"/„Geschlossen" statt an/aus
            foreach ($spec['options'] as [$wert, $caption]) {
                if ($caption === '') {
                    continue;
                }
                if ($wert === true || $wert === 1 || $wert === '1') {
                    $spec['an'] = $caption;
                } elseif ($wert === false || $wert === 0 || $wert === '0' || $wert === '') {
                    $spec['aus'] = $caption;
                }
            }
        }
        return $spec;
    }

    /**
     * OPTIONS kommt als Liste ODER als JSON-Text; Zeilen tragen Value/Caption
     * (Intervalle: Min). Deutsche Beschriftungen aus locale.de bevorzugt.
     * @return list<array{0:mixed,1:string}>
     */
    private function VoiceGeraetOptionen(mixed $roh): array
    {
        if (is_string($roh)) {
            $roh = json_decode($roh, true);
        }
        if (!is_array($roh)) {
            return [];
        }
        $aus = [];
        foreach ($roh as $o) {
            if (!is_array($o)) {
                continue;
            }
            $wert = $o['Value'] ?? $o['value'] ?? $o['Min'] ?? $o['IntervalMinValue'] ?? null;
            $caption = trim((string)($o['Caption'] ?? $o['caption'] ?? $o['Name'] ?? $o['ConstantValue'] ?? ''));
            if (is_array($o['locale'] ?? null) && trim((string)($o['locale']['de'] ?? '')) !== '') {
                $caption = trim((string)$o['locale']['de']);
            }
            if ($wert !== null) {
                $aus[] = [$wert, $caption];
            }
        }
        return $aus;
    }

    // ────────────────────────────── Werte ──────────────────────────────

    /**
     * Gesprochener Wunsch → echter Wert. Nie stillschweigend klemmen.
     *
     * @return array{ok:bool,wert?:mixed,text?:string,sag?:string,error?:array}
     */
    private function VoiceGeraetWertMappen(array $e, array $spec, string $wert, mixed $aktuell): array
    {
        $name = (string)$e['titel'];
        $w = $this->VoiceNorm($wert);
        $w = trim((string)preg_replace('/^(?:auf|bitte|stelle?|setze?)\s+/u', '', $w));
        $w = trim((string)preg_replace('/\s*(?:prozent|%|grad(?:\s*celsius)?|°c|°)\s*$/u', '', $w));
        $an  = ['an', 'ein', 'einschalten', 'anschalten', 'anmachen', 'on', 'true', 'ja', 'aktiv', 'aktivieren'];
        $aus = ['aus', 'ausschalten', 'ausmachen', 'off', 'false', 'nein', 'inaktiv', 'deaktivieren'];
        $toggle = ['umschalten', 'wechseln', 'toggle', 'umlegen'];
        $zahl = $this->VoiceGeraetZahl($w);

        switch ($spec['art']) {
            case 'schalter':
                if (in_array($w, $an, true) || $w === '1') {
                    return ['ok' => true, 'wert' => true, 'text' => $spec['an']];
                }
                if (in_array($w, $aus, true) || $w === '0') {
                    return ['ok' => true, 'wert' => false, 'text' => $spec['aus']];
                }
                if (in_array($w, $toggle, true)) {
                    $neu = !((bool)$aktuell);
                    return ['ok' => true, 'wert' => $neu, 'text' => $neu ? $spec['an'] : $spec['aus']];
                }
                // Beschriftungen wie „offen"/„geschlossen"
                foreach ([[true, $spec['an']], [false, $spec['aus']]] as [$wertB, $caption]) {
                    if ($caption !== '' && $this->VoicePunkte($w, $this->VoiceNorm($caption)) >= 80) {
                        return ['ok' => true, 'wert' => $wertB, 'text' => $caption];
                    }
                }
                return $this->VoiceErr('ungueltiger_wert', sprintf($this->Translate('For %1$s I only know %2$s and %3$s.'), $name, $spec['an'], $spec['aus']));

            case 'auswahl':
                if ($spec['options'] === []) {
                    return $this->VoiceErr('nicht_unterstuetzt', sprintf($this->Translate('I cannot set %s by voice yet.'), $name));
                }
                foreach ($spec['options'] as [$ow, $caption]) {
                    if ($zahl !== null && is_numeric($ow) && (float)$ow === $zahl) {
                        return ['ok' => true, 'wert' => $this->VoiceGeraetTyp($ow, (int)$spec['vt']), 'text' => $caption !== '' ? $caption : (string)$ow];
                    }
                }
                $kand = [];
                foreach ($spec['options'] as $i => [$ow, $caption]) {
                    if ($caption !== '') {
                        $kand[] = ['schluessel' => $i, 'titel' => $caption];
                    }
                }
                $erg = $this->VoiceAufloesen($w, $kand);
                if ($erg['status'] === 'eindeutig') {
                    [$ow, $caption] = $spec['options'][(int)$erg['treffer'][0]['schluessel']];
                    return ['ok' => true, 'wert' => $this->VoiceGeraetTyp($ow, (int)$spec['vt']), 'text' => $caption];
                }
                $moeglich = implode(', ', array_slice(array_filter(array_map(static fn(array $o): string => $o[1], $spec['options'])), 0, 8));
                return $this->VoiceErr('ungueltiger_wert', sprintf($this->Translate('I do not know "%1$s" for %2$s. Possible: %3$s.'), $wert, $name, $moeglich));

            case 'rollladen':
                $offen = $spec['reversed'] ? $spec['max'] : $spec['min'];
                $zu    = $spec['reversed'] ? $spec['min'] : $spec['max'];
                if ($spec['max'] <= $spec['min']) {
                    $offen = $spec['reversed'] ? 100.0 : 0.0;
                    $zu    = $spec['reversed'] ? 0.0 : 100.0;
                    $spec['min'] = 0.0;
                    $spec['max'] = 100.0;
                }
                if (in_array($w, ['hoch', 'auf', 'oeffnen', 'offen', 'rauf', 'oben', 'open', 'hochfahren', 'aufmachen'], true)) {
                    return ['ok' => true, 'wert' => $this->VoiceGeraetTyp($offen, (int)$spec['vt']), 'text' => $this->Translate('open')];
                }
                if (in_array($w, ['runter', 'zu', 'schliessen', 'geschlossen', 'unten', 'herunter', 'close', 'runterfahren', 'zumachen'], true)) {
                    return ['ok' => true, 'wert' => $this->VoiceGeraetTyp($zu, (int)$spec['vt']), 'text' => $this->Translate('closed')];
                }
                if (in_array($w, ['stopp', 'stop', 'halt', 'anhalten'], true)) {
                    return $this->VoiceErr('nicht_unterstuetzt', sprintf($this->Translate('Stopping is not possible for %s here.'), $name));
                }
                if ($w === 'halb') {
                    $zahl = ($spec['min'] + $spec['max']) / 2;
                }
                if ($zahl === null) {
                    return $this->VoiceErr('ungueltiger_wert', sprintf($this->Translate('For %s say up, down or a percentage.'), $name));
                }
                return $this->VoiceGeraetZahlSetzen($spec, $zahl, $name);

            case 'regler':
                if ($spec['max'] <= $spec['min']) {
                    $spec['min'] = 0.0;
                    $spec['max'] = 100.0;
                }
                $prozentig = $spec['suffix'] === '' || str_contains($spec['suffix'], '%');
                if (in_array($w, ['maximal', 'maximum', 'voll', 'ganz', 'hell', 'volle', 'hoechste'], true)) {
                    $zahl = $spec['max'];
                } elseif (in_array($w, ['minimal', 'minimum', 'dunkel', 'niedrigste'], true)) {
                    $zahl = $spec['min'];
                } elseif ($w === 'halb' || $w === 'haelfte') {
                    $zahl = ($spec['min'] + $spec['max']) / 2;
                } elseif ($prozentig && $spec['min'] <= 0 && in_array($w, $an, true)) {
                    $zahl = $spec['max'];
                } elseif ($prozentig && $spec['min'] <= 0 && in_array($w, $aus, true)) {
                    $zahl = $spec['min'];
                }
                if ($zahl === null) {
                    $beispiel = $this->VoiceGeraetZahlText(($spec['min'] + $spec['max']) / 2, $spec);
                    return $this->VoiceErr('ungueltiger_wert', sprintf($this->Translate('%1$s needs a number, for example %2$s.'), $name, $beispiel));
                }
                return $this->VoiceGeraetZahlSetzen($spec, $zahl, $name);

            case 'text':
                return ['ok' => true, 'wert' => mb_substr(trim($wert), 0, 200), 'text' => mb_substr(trim($wert), 0, 60)];

            default: // farbe, wiedergabe
                return $this->VoiceErr('nicht_unterstuetzt', sprintf($this->Translate('I cannot set %s by voice yet.'), $name));
        }
    }

    /** Klemmen ist ein FEHLER, einrasten und runden sind Pflicht. */
    private function VoiceGeraetZahlSetzen(array $spec, float $zahl, string $name): array
    {
        if ($zahl < $spec['min'] - 1e-9 || $zahl > $spec['max'] + 1e-9) {
            return $this->VoiceErr('ausserhalb', sprintf($this->Translate('%1$s goes from %2$s to %3$s.'), $name,
                $this->VoiceGeraetZahlText($spec['min'], $spec), $this->VoiceGeraetZahlText($spec['max'], $spec)));
        }
        $step = $spec['step'];
        if ($step <= 0) {
            $step = $spec['digits'] > 0 ? 10 ** -$spec['digits'] : ((int)$spec['vt'] === 1 ? 1.0 : 0.0);
        }
        if ($step > 0) {
            $zahl = $spec['min'] + round(($zahl - $spec['min']) / $step) * $step;
        }
        if ($spec['digits'] >= 0) {
            $zahl = round($zahl, $spec['digits']);
        }
        $zahl = min(max($zahl, $spec['min']), $spec['max']);   // nur Rundungsrauschen
        return ['ok' => true, 'wert' => $this->VoiceGeraetTyp($zahl, (int)$spec['vt']),
                'text' => $this->VoiceGeraetZahlText($zahl, $spec)];
    }

    private function VoiceGeraetZahlText(float $zahl, array $spec): string
    {
        $digits = $spec['digits'] >= 0 ? $spec['digits'] : ((int)$spec['vt'] === 1 ? 0 : (floor($zahl) === $zahl ? 0 : 1));
        $s = number_format($zahl, $digits, ',', '');
        return $spec['suffix'] !== '' ? $s . $spec['suffix'] : $s;
    }

    /** Zahl aus dem Gesagten — Ziffern, Komma, kleine Zahlwörter. */
    private function VoiceGeraetZahl(string $w): ?float
    {
        if (preg_match('/-?\d+(?:[.,]\d+)?/', $w, $m) === 1) {
            return (float)str_replace(',', '.', $m[0]);
        }
        $woerter = ['null' => 0, 'eins' => 1, 'ein' => 1, 'zwei' => 2, 'drei' => 3, 'vier' => 4, 'fuenf' => 5,
                    'sechs' => 6, 'sieben' => 7, 'acht' => 8, 'neun' => 9, 'zehn' => 10, 'elf' => 11, 'zwoelf' => 12,
                    'fuenfzehn' => 15, 'zwanzig' => 20, 'dreissig' => 30, 'vierzig' => 40, 'fuenfzig' => 50,
                    'sechzig' => 60, 'siebzig' => 70, 'achtzig' => 80, 'neunzig' => 90, 'hundert' => 100];
        foreach ($woerter as $wort => $zahl) {
            if ($w === $wort) {
                return (float)$zahl;
            }
        }
        return null;
    }

    private function VoiceGeraetTyp(mixed $wert, int $vt): mixed
    {
        return match ($vt) {
            0 => (bool)$wert,
            1 => (int)round((float)$wert),
            2 => (float)$wert,
            default => (string)$wert,
        };
    }

    // ────────────────────────────── Lesen ──────────────────────────────

    /** „Wohnzimmer Deckenlampe" — der Titel trägt den Raum schon. */
    private function VoiceGeraetZustandText(array $e, array $spec): string
    {
        $id = (int)$e['id'];
        $text = '';
        try {
            $text = trim((string)@GetValueFormatted($id));
        } catch (\Throwable $ex) {
            $text = '';
        }
        if ($text !== '') {
            return $text;
        }
        $wert = @GetValue($id);
        return match ($spec['art']) {
            'schalter' => ((bool)$wert) ? $spec['an'] : $spec['aus'],
            'auswahl'  => $this->VoiceGeraetOptionText($spec, $wert),
            'text'     => mb_substr((string)$wert, 0, 120),
            default    => is_numeric($wert) ? $this->VoiceGeraetZahlText((float)$wert, $spec) : (string)$wert,
        };
    }

    private function VoiceGeraetOptionText(array $spec, mixed $wert): string
    {
        $best = null;
        foreach ($spec['options'] as [$ow, $caption]) {
            if ($ow === $wert || (is_numeric($ow) && is_numeric($wert) && (float)$ow <= (float)$wert)) {
                $best = $caption !== '' ? $caption : (string)$ow;   // höchste Option <= Wert (Intervalle)
            }
        }
        return $best ?? (string)$wert;
    }

    /** Ist das Gerät gerade „an"? Für den Filter der Raumliste. */
    private function VoiceGeraetIstAn(array $e, array $spec): bool
    {
        $wert = @GetValue((int)$e['id']);
        return match ($spec['art']) {
            'schalter'  => (bool)$wert,
            'rollladen' => is_numeric($wert) && abs((float)$wert - ($spec['reversed'] ? $spec['max'] : $spec['min'])) > 1e-6,
            'regler'    => is_numeric($wert) && (float)$wert > $spec['min'],
            default     => false,
        };
    }

    /** @return array<string,mixed> */
    private function VoiceToolGeraeteLesen(array $args, array $ctx): array
    {
        $katalog = $this->VoiceGeraeteKatalog();
        if ($katalog === []) {
            return $this->VoiceErr('nicht_erlaubt', $this->Translate('No devices are released for voice control.'));
        }
        $geraet = trim((string)($args['geraet'] ?? ''));
        $raum   = trim((string)($args['raum'] ?? ''));
        $filter = (string)($args['filter'] ?? 'alle');
        $raeume = $this->VoiceGeraeteRaeume();

        if ($geraet !== '') {
            $erg = $this->VoiceGeraeteAufloesen($geraet, $katalog, $raum);
            $fehler = $this->VoiceAufloeseFehler($erg, $geraet, $this->Translate('devices'));
            if ($fehler !== null) {
                return $fehler;
            }
            $e = $erg['treffer'][0]['schluessel'];
            if ($e['typ'] !== 'var') {
                return ['ok' => true, 'geraet' => $e['titel'],
                        'sag' => sprintf($this->Translate('%s is a scene or script — I can start it, but it has no state.'), $e['titel'])];
            }
            $spec = $this->VoiceGeraetSpezifikation((int)$e['id']);
            $zustand = $this->VoiceGeraetZustandText($e, $spec);
            return ['ok' => true, 'geraet' => $e['titel'], 'wert' => $zustand,
                    'sag' => sprintf($this->Translate('%1$s: %2$s.'), $e['titel'], $zustand)];
        }

        if ($raum === '') {
            return ['ok' => true, 'raeume' => array_slice($raeume, 0, 12),
                    'sag' => $raeume === []
                        ? sprintf($this->Translate('%d devices, no rooms.'), count($katalog))
                        : sprintf($this->Translate('Rooms with devices: %s. Which one?'), implode(', ', array_slice($raeume, 0, 12)))];
        }
        $imRaum = array_values(array_filter($katalog,
            fn(array $e): bool => $e['typ'] === 'var' && $this->VoiceRaumPasst((string)$e['raum'], $raum)));
        if ($imRaum === []) {
            $erg = $this->VoiceAufloesen($raum, array_map(static fn(string $r): array => ['schluessel' => $r, 'titel' => $r], $raeume));
            $fehler = $this->VoiceAufloeseFehler($erg, $raum, $this->Translate('rooms'));
            if ($fehler !== null) {
                return $fehler;
            }
            $imRaum = array_values(array_filter($katalog,
                fn(array $e): bool => $e['typ'] === 'var' && $e['raum'] === (string)$erg['treffer'][0]['schluessel']));
            $raum = (string)$erg['treffer'][0]['schluessel'];
        }
        $zeilen = [];
        $anzahlAn = 0;
        foreach ($imRaum as $e) {
            $spec = $this->VoiceGeraetSpezifikation((int)$e['id']);
            $an = $this->VoiceGeraetIstAn($e, $spec);
            if ($an) {
                $anzahlAn++;
            }
            if (($filter === 'an' && !$an) || ($filter === 'aus' && ($an || $spec['art'] !== 'schalter'))) {
                continue;
            }
            $zeilen[] = $e['name'] . ': ' . $this->VoiceGeraetZustandText($e, $spec);
            if (count($zeilen) >= 15) {
                break;
            }
        }
        $sag = match (true) {
            $zeilen === [] && $filter === 'an' => sprintf($this->Translate('Nothing is on in %s.'), $raum),
            $zeilen === []                     => sprintf($this->Translate('I know no devices in %s.'), $raum),
            count($zeilen) === 1               => $raum . ': ' . $zeilen[0],
            default                            => sprintf($this->Translate('%1$d devices in %2$s, %3$d of them on.'), count($imRaum), $raum, $anzahlAn),
        };
        return ['ok' => true, 'raum' => $raum, 'geraete' => $zeilen, 'sag' => $sag];
    }

    // ────────────────────────────── Steuern ──────────────────────────────

    /**
     * Schaltet oder stellt ein Gerät. Das Ziel wird HIER noch einmal live
     * geprüft — der Katalog ist eine Momentaufnahme, und beim zweiten Aufruf
     * (Marke) können Sekunden vergangen sein.
     *
     * @param array{id:int,typ:string,titel:string,wert:mixed,text:string,via:int,vt:int} $ziel
     * @return array<string,mixed>
     */
    private function VoiceGeraetAusfuehren(array $ziel): array
    {
        $id = (int)$ziel['id'];
        $titel = (string)$ziel['titel'];
        if (!@IPS_VariableExists($id) || !$this->VoiceGeraetImUmfang($id, (int)($ziel['via'] ?? 0))) {
            return $this->VoiceErr('nicht_gefunden', sprintf($this->Translate('I cannot find "%s" among the devices any more.'), $titel));
        }
        $v = @IPS_GetVariable($id);
        if (!is_array($v) || !$this->VoiceGeraetAktionierbar($v)) {
            return $this->VoiceErr('nicht_steuerbar', sprintf($this->Translate('%s cannot be controlled.'), $titel));
        }
        $spec = $this->VoiceGeraetSpezifikation($id);
        $vorher = @GetValue($id);
        $gleich = fn(mixed $a, mixed $b): bool => (int)$spec['vt'] === 2
            ? abs((float)$a - (float)$b) < max($spec['step'] / 2, 1e-6)
            : $a === $b || (string)$a === (string)$b;
        $schonSo = $gleich($vorher, $ziel['wert']);
        try {
            @RequestAction($id, $ziel['wert']);
        } catch (\Throwable $e) {
            $this->SendDebug('Voice', 'RequestAction #' . $id . ' warf: ' . $e->getMessage(), 0);
            return str_contains($e->getMessage(), 'Action is invalid')
                ? $this->VoiceErr('nicht_steuerbar', sprintf($this->Translate('%s cannot be controlled.'), $titel))
                : $this->VoiceErr('geraet_fehler', sprintf($this->Translate('%s did not accept that.'), $titel));
        }
        $nachher = @GetValue($id);
        if (!$gleich($nachher, $ziel['wert'])) {
            usleep(250000);              // das Zielmodul schaltet oft asynchron
            $nachher = @GetValue($id);
        }
        $bestaetigt = $gleich($nachher, $ziel['wert']);
        $text = $bestaetigt ? $this->VoiceGeraetZustandText(['id' => $id], $spec) : (string)$ziel['text'];
        $sag = match (true) {
            $schonSo && $bestaetigt => sprintf($this->Translate('%1$s was already %2$s.'), $titel, $text),
            $bestaetigt             => sprintf($this->Translate('%1$s is now %2$s.'), $titel, $text),
            default                 => sprintf($this->Translate('I have set %1$s to %2$s.'), $titel, $text),
        };
        return ['ok' => true, 'geraet' => $titel, 'wert' => $text, 'bestaetigt' => $bestaetigt, 'sag' => $sag];
    }

    /**
     * Szene oder Skript starten. Beides ist asynchron — der Satz sagt „gestartet".
     * @param array<string,mixed> $ziel
     */
    private function VoiceSzeneAusfuehren(array $ziel): array
    {
        $id = (int)$ziel['id'];
        $titel = (string)$ziel['titel'];
        try {
            if (($ziel['typ'] ?? '') === 'szene') {
                $inst = (int)($ziel['inst'] ?? 0);
                if (!@IPS_InstanceExists($inst) || !@IPS_VariableExists($id) || !$this->VoiceGeraetImUmfang($inst)
                    || !function_exists('SZS_CallScene')) {
                    return $this->VoiceErr('nicht_gefunden', sprintf($this->Translate('I cannot find "%s" among the devices any more.'), $titel));
                }
                @SZS_CallScene($inst, (int)($ziel['nr'] ?? 0));
                if (function_exists('SZS_UpdateActive')) {
                    @SZS_UpdateActive($inst);
                }
                return ['ok' => true, 'szene' => $titel, 'sag' => sprintf($this->Translate('Scene %s started.'), $titel)];
            }
            if (!@IPS_ScriptExists($id) || !$this->VoiceGeraetImUmfang($id, (int)($ziel['via'] ?? 0))) {
                return $this->VoiceErr('nicht_gefunden', sprintf($this->Translate('I cannot find "%s" among the devices any more.'), $titel));
            }
            @IPS_RunScript($id);
            return ['ok' => true, 'skript' => $titel, 'sag' => sprintf($this->Translate('I have started %s.'), $titel)];
        } catch (\Throwable $e) {
            $this->SendDebug('Voice', 'Szene/Skript #' . $id . ' warf: ' . $e->getMessage(), 0);
            return $this->VoiceErr('szene_fehler', sprintf($this->Translate('%s could not be started.'), $titel));
        }
    }

    /**
     * Das Ziel aus dem Gesagten: Gerät auflösen, Wert übersetzen. Noch nichts
     * geschaltet — das Ergebnis ist entweder ausführbar oder die Rückfrage.
     *
     * @return array{ok:bool,ziel?:array<string,mixed>}|array<string,mixed>
     */
    private function VoiceGeraetZielBilden(array $args, string $was): array
    {
        $katalog = $this->VoiceGeraeteKatalog();
        if ($katalog === []) {
            return $this->VoiceErr('nicht_erlaubt', $this->Translate('No devices are released for voice control.'));
        }
        $raum = trim((string)($args['raum'] ?? ''));
        if ($was === 'szene') {
            $kandidaten = array_values(array_filter($katalog, static fn(array $e): bool => $e['typ'] !== 'var'));
            $such = trim((string)($args['name'] ?? ''));
            $wasArt = $this->Translate('scenes and scripts');
        } else {
            $kandidaten = array_values(array_filter($katalog, static fn(array $e): bool => $e['typ'] === 'var' && $e['akt'] === true));
            $such = trim((string)($args['geraet'] ?? ''));
            $wasArt = $this->Translate('devices');
        }
        if ($such === '') {
            return $this->VoiceErr('ungueltige_eingabe', $was === 'szene'
                ? $this->Translate('Which scene or script?') : $this->Translate('Which device do you mean?'));
        }
        $erg = $this->VoiceGeraeteAufloesen($such, $kandidaten, $raum);
        if ($erg['status'] === 'nichts' && $raum !== '' && !array_filter($katalog, fn(array $e): bool => $this->VoiceRaumPasst((string)$e['raum'], $raum))) {
            return $this->VoiceErr('nicht_gefunden', sprintf($this->Translate('I know no room called "%1$s". Rooms are: %2$s.'),
                $raum, implode(', ', array_slice($this->VoiceGeraeteRaeume(), 0, 12))));
        }
        $fehler = $this->VoiceAufloeseFehler($erg, $such, $wasArt);
        if ($fehler !== null) {
            return $fehler;
        }
        $e = $erg['treffer'][0]['schluessel'];
        $ziel = ['bereich' => 'geraet', 'id' => (int)$e['id'], 'typ' => (string)$e['typ'], 'titel' => (string)$e['titel'],
                 'via' => (int)$e['via'], 'vt' => (int)($e['vt'] ?? 0), 'inst' => (int)($e['inst'] ?? 0), 'nr' => (int)($e['nr'] ?? 0),
                 'raum' => (string)($e['raum'] ?? ''),
                 'wert' => null, 'text' => $this->Translate('run')];
        if ($was !== 'szene') {
            $wert = trim((string)($args['wert'] ?? ''));
            if ($wert === '') {
                return $this->VoiceErr('ungueltige_eingabe', sprintf($this->Translate('What should I set %s to?'), $e['titel']));
            }
            $spec = $this->VoiceGeraetSpezifikation((int)$e['id']);
            $map = $this->VoiceGeraetWertMappen($e, $spec, $wert, @GetValue((int)$e['id']));
            if (($map['ok'] ?? false) !== true) {
                return $map;
            }
            $ziel['wert'] = $map['wert'];
            $ziel['text'] = (string)$map['text'];
        }
        return ['ok' => true, 'ziel' => $ziel];
    }

    /**
     * geraet_steuern und szene_starten — derselbe Fluss, zwei Katalogfilter.
     * Rückfrage-Liste, Kinder, Marke und Deckel sitzen HIER, vor jeder Ausführung.
     *
     * @return array<string,mixed>
     */
    private function VoiceToolGeraetSteuern(array $args, array $ctx, string $was = 'geraet'): array
    {
        $marke = is_string($args['marke'] ?? null) ? trim((string)$args['marke']) : '';

        // ---- Zweiter Aufruf: Marke einlösen ----
        if ($marke !== '') {
            $ziel = $this->VoiceMarkeEinloesen($marke);
            if ($ziel === null || ($ziel['bereich'] ?? '') !== 'geraet') {
                // NICHT auf eine frische Auflösung zurückfallen — eine erfundene
                // oder abgelaufene Marke darf nichts Unbestätigtes schalten.
                return $this->VoiceErr('marke_abgelaufen', $this->Translate('The confirmation has expired — please tell me again what to switch.'));
            }
            if ((string)($ziel['userId'] ?? '') !== (string)($ctx['userId'] ?? '') || $this->VoiceIstKind($ctx)) {
                return $this->VoiceErr('nicht_erlaubt', $this->Translate('I may not switch that for you — please ask an adult.'));
            }
            return $this->VoiceGeraetSchalten($ziel, $ctx, true);
        }

        // ---- Erster Aufruf ----
        $gebildet = $this->VoiceGeraetZielBilden($args, $was);
        if (($gebildet['ok'] ?? false) !== true) {
            return $gebildet;
        }
        $ziel = $gebildet['ziel'];
        if ($this->VoiceGeraetMitRueckfrage((int)$ziel['id']) || ((int)($ziel['inst'] ?? 0) > 0 && $this->VoiceGeraetMitRueckfrage((int)$ziel['inst']))) {
            if ($this->VoiceIstKind($ctx)) {
                return $this->VoiceErr('nicht_erlaubt', $this->Translate('I may not switch that for you — please ask an adult.'));
            }
            $ziel['userId'] = (string)($ctx['userId'] ?? '');
            $id = $this->VoiceMarkeErzeugen($ziel, self::$VOICE_MARKE_TTL);
            if ($id === '') {
                return $this->VoiceErr('intern', $this->Translate('Something went wrong — nothing was changed.'));
            }
            $frage = $ziel['typ'] === 'var'
                ? sprintf($this->Translate('I will set %1$s to %2$s. Shall I really?'), $ziel['titel'], $ziel['text'])
                : sprintf($this->Translate('I will start %s. Shall I really?'), $ziel['titel']);
            return ['ok' => false, 'error' => ['code' => 'bestaetigung_noetig', 'message' => 'confirmation required'],
                    'marke' => $id, 'sag' => $frage];
        }
        return $this->VoiceGeraetSchalten($ziel, $ctx, false);
    }

    /** Deckel, Ausführung, Zählung, Protokoll — für beide Aufrufwege. */
    private function VoiceGeraetSchalten(array $ziel, array $ctx, bool $bestaetigt): array
    {
        if (!$this->VoiceGeraeteDeckelOffen()) {
            return $this->VoiceErr('geraete_deckel', $this->Translate('Too many switching commands in a short time — please try again later.'));
        }
        $antwort = ($ziel['typ'] ?? '') === 'var' ? $this->VoiceGeraetAusfuehren($ziel) : $this->VoiceSzeneAusfuehren($ziel);
        if (($antwort['ok'] ?? false) === true) {
            $this->VoiceGeraeteZaehlen();
            $wer = '';
            $userId = (string)($ctx['userId'] ?? '');
            if ($userId !== '') {
                try {
                    foreach ($this->LoadUsers() as $u) {
                        if ((string)($u['id'] ?? '') === $userId) {
                            $wer = (string)($u['name'] ?? '');
                        }
                    }
                } catch (\Throwable $e) {
                    $wer = '';
                }
            }
            $this->LogMessage(sprintf('SymDo Sprachdialog: %s „%s" (#%d) → %s%s%s',
                ($ziel['typ'] ?? '') === 'var' ? 'geschaltet' : 'gestartet', (string)$ziel['titel'], (int)$ziel['id'],
                (string)($ziel['text'] ?? ''), $wer !== '' ? ' von ' . $wer : '', $bestaetigt ? ' (bestätigt)' : ''), KL_NOTIFY);
        }
        return $antwort;
    }
}

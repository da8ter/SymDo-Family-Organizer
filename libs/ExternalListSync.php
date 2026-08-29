<?php

declare(strict_types=1);

/**
 * Abgleich einer eigenen Liste mit externen Listen (Alexa, Bring).
 *
 * „Extern" und nicht „Sprache": Alexa ist eine Sprachliste, Bring ist eine App.
 * Beide koennen GLEICHZEITIG an derselben Liste haengen — deshalb traegt jeder
 * Eintrag eine Kennung JE DIENST (`extIds`), nicht eine einzige.
 *
 * Der Trait entscheidet, kennt aber weder die Gegenstelle noch die eigene Ablage:
 * die Gegenstelle kommt als ListSource herein, die eigene Liste bedient das
 * jeweilige Modul ueber die Haken am Ende dieser Datei. Genau dadurch laesst sich
 * die Logik ohne Cloud und ohne Symcon-Objektbaum pruefen.
 *
 * Warum NICHT als weiteres SyncBackend: `SyncBackend` in der ToDo-Liste ist
 * exklusiv und wird von EnforceSyncBackend() erzwungen — eine Liste hat dort
 * genau einen Partner. Die Sprachliste muss aber DANEBEN laufen: die vorhandene
 * „ToDo Liste Google" soll auch Alexa hoeren. Deshalb eigene Felder, eigener
 * Auslöser, keine Beruehrung mit SyncBackend.
 */
trait ExternalListSync
{
    /** Kennung eines lokal angelegten, noch nicht uebertragenen Eintrags. */
    private const EXT_PENDING = 'pending_';

    /**
     * Einheiten, die zur Menge gehoeren duerfen.
     *
     * Bewusst eine feste Liste und keine Heuristik: „3 Kilo Aepfel" soll sich
     * teilen, „3 Musketiere" nicht. Wer raet, benennt Artikel falsch um — und das
     * faellt erst beim Einkaufen auf.
     */
    private const EXT_UNITS = [
        'kilo', 'kilogramm', 'kg', 'gramm', 'g', 'pfund',
        'liter', 'l', 'milliliter', 'ml',
        'packung', 'packungen', 'paeckchen', 'päckchen', 'pack', 'pck',
        'dose', 'dosen', 'flasche', 'flaschen', 'glas', 'glaeser', 'gläser',
        'stueck', 'stück', 'stk', 'becher', 'tuete', 'tüte', 'tueten', 'tüten',
        'beutel', 'bund', 'scheibe', 'scheiben', 'el', 'tl', 'prise', 'kiste', 'kasten',
    ];

    // ────────────────────────────── Auslöser ──────────────────────────────

    /**
     * Meldet sich auf die Auslöser-Variable der gewaehlten Instanz an bzw. ab.
     *
     * Muster uebernommen von SyncExternalScannerVariable() in der Einkaufsliste:
     * die zuletzt angemeldete ID steht in einem Attribut, weil ApplyChanges die
     * ALTE Anmeldung loesen muss und die Property zu diesem Zeitpunkt schon den
     * neuen Wert traegt.
     */
    private function ExtListBindTrigger(): void
    {
        $alt = $this->ExtListTriggerVars();
        $neu = [];
        foreach ($this->ExtListSources() as $quelle) {
            $kandidat = $quelle->TriggerVariableID();
            if ($kandidat > 0 && @IPS_VariableExists($kandidat)) {
                $neu[] = $kandidat;
            }
        }
        sort($alt);
        sort($neu);
        if ($alt === $neu) {
            return;
        }
        foreach (array_diff($alt, $neu) as $weg) {
            @$this->UnregisterMessage($weg, VM_UPDATE);
        }
        foreach (array_diff($neu, $alt) as $dazu) {
            $this->RegisterMessage($dazu, VM_UPDATE);
            @$this->RegisterReference($dazu);
        }
        @$this->WriteAttributeString('ExtListTriggerVars', (string)json_encode(array_values($neu)));
    }

    /**
     * Welche fremden Kennungen lagen beim LETZTEN Lauf lokal vor?
     *
     * Daran erkennt der naechste Lauf, dass hier etwas geloescht wurde: eine
     * Kennung, die gemerkt war und deren Zeile es nicht mehr gibt, ist von Hand
     * entfernt worden. Ohne diesen Merkposten kam der Eintrag beim naechsten
     * Abgleich als „unbekannt und offen" zurueck und wurde neu angelegt — live
     * gemeldet am 24.08.2026: zwei in der Kachel geloeschte Artikel standen nach
     * dem naechsten Abgleich wieder da.
     *
     * Bewusst ein Vergleich und keine Meldung an jeder Loeschstelle: die
     * Einkaufsliste loescht an vier Stellen (einzeln, per Name, Wagen leeren,
     * Kaufhistorie), die ToDo-Liste beim Abhaken mit DeleteCompletedTasks. Jede
     * einzeln zu verdrahten hiesse, dass die naechste neue Stelle es wieder
     * vergisst.
     *
     * @return array<string, array<string, int>> [Dienst => [Kennung => 1]]
     */
    private function ExtListKnownRead(): array
    {
        $d = json_decode((string)@$this->ReadAttributeString('ExtListKnownIds'), true);
        return is_array($d) ? $d : [];
    }

    /** @return array<string, array<string, int>> Kennungen, die hier geloescht wurden */
    private function ExtListRemovedRead(): array
    {
        $d = json_decode((string)@$this->ReadAttributeString('ExtListRemovedIds'), true);
        return is_array($d) ? $d : [];
    }

    /** @return list<int> die Variablen, auf die wir angemeldet sind */
    private function ExtListTriggerVars(): array
    {
        $roh = json_decode((string)@$this->ReadAttributeString('ExtListTriggerVars'), true);
        return is_array($roh) ? array_values(array_map('intval', $roh)) : [];
    }

    /**
     * Ist diese Nachricht unser Auslöser? Nur DANN abgleichen.
     *
     * Das Changed-Flag ist entscheidend: die Variable „Liste" wird bei jedem Takt
     * der Gegenstelle geschrieben, auch unveraendert. Ohne diese Pruefung kostete
     * jeder Leerlauf-Takt einen weiteren Cloud-Aufruf.
     */
    private function ExtListIsTrigger(int $senderID, int $message, array $data): bool
    {
        if ($message !== VM_UPDATE || !in_array($senderID, $this->ExtListTriggerVars(), true)) {
            return false;
        }
        return (bool)($data[1] ?? false);
    }

    // ────────────────────────────── Abgleich ──────────────────────────────

    /**
     * Ein Durchlauf. Gibt eine Bilanz zurueck (fuer Protokoll und Prueflauf).
     *
     * @return array{ok: bool, imported: int, pushed: int, completed: int, resolved: int, reason: string}
     */
    private function ExtListSync(): array
    {
        $leer = ['ok' => false, 'lokal' => 0, 'imported' => 0, 'pushed' => 0, 'completed' => 0, 'vanished' => 0, 'resolved' => 0, 'removed' => 0, 'reason' => ''];
        $quellen = $this->ExtListSources();
        if ($quellen === []) {
            return array_merge($leer, ['reason' => $this->ExtListEnabled() ? 'no_source' : 'disabled']);
        }
        $riegel = 'ExtListSync_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($riegel, 500)) {
            return array_merge($leer, ['reason' => 'busy']);
        }
        try {
            // Jede Quelle einzeln, NACHEINANDER: jeder Schritt liest und schreibt
            // die eigene Liste, verschraenkt arbeitete die zweite Quelle auf
            // einem Stand, den die erste gerade veraendert.
            $summe   = ['ok' => false, 'lokal' => 0, 'imported' => 0, 'pushed' => 0, 'completed' => 0, 'vanished' => 0, 'resolved' => 0, 'removed' => 0, 'reason' => ''];
            $gruende = [];
            $runde   = function () use ($quellen, &$summe, &$gruende): int {
                $neuImportiert = 0;
                foreach ($quellen as $quelle) {
                    $b = $this->ExtListSyncStep($quelle);
                    foreach (['lokal', 'imported', 'pushed', 'completed', 'vanished', 'resolved', 'removed'] as $feld) {
                        $summe[$feld] += (int)$b[$feld];
                    }
                    $neuImportiert += (int)$b['imported'];
                    // Geglueckt, wenn MINDESTENS eine Quelle lesbar war — sonst
                    // meldete eine kaputte Bring-Anbindung den erfolgreichen
                    // Alexa-Abgleich als Fehlschlag.
                    if ($b['ok']) {
                        $summe['ok'] = true;
                    } else {
                        $gruende[] = $quelle->Key() . ': ' . (string)$b['reason'];
                    }
                }
                return $neuImportiert;
            };
            $importiert = $runde();

            // ZWEITE Runde, wenn etwas hereinkam und mehr als ein Dienst haengt.
            //
            // Der Grund ist eine Asymmetzie der Reihenfolge, die der Prueflauf
            // gefunden hat: Der Bring-Artikel wird erst im zweiten Schritt
            // importiert — da ist der Alexa-Schritt schon vorbei und hat ihn nie
            // gesehen. Er erreichte Alexa erst bei der naechsten Aenderung, und
            // wenn keine kommt, nie. Eine zweite Runde holt genau das nach; sie
            // importiert nichts Neues (die Kennungen sind jetzt bekannt) und
            // kostet je Dienst einen weiteren Lesezugriff — deshalb nur dann.
            if ($importiert > 0 && count($quellen) > 1) {
                $gruende = [];
                $runde();
            }

            // Die Kachel erfaehrt von sich aus nichts. Jede Aenderung, die aus
            // der Kachel selbst kommt, laeuft ueber RequestAction und stoesst dort
            // den Push an — ein Abgleich aber laeuft am Formular-Knopf oder am
            // Fremd-Takt vorbei daran vorbei. Ohne diesen Aufruf melden wir „1 neue
            // Aufgabe" und die Anzeige bleibt auf dem alten Stand stehen.
            //
            // Nur bei SICHTBARER Aenderung: die Kennungs-Stempel (resolved, pushed)
            // aendern nichts, was jemand sehen kann.
            if ($summe['lokal'] > 0) {
                $this->ExtListAfterChange();
            }

            $summe['reason'] = implode(', ', array_unique($gruende));
            return $summe;
        } finally {
            IPS_SemaphoreLeave($riegel);
        }
    }

    /** @return array{ok: bool, imported: int, pushed: int, completed: int, resolved: int, reason: string} */
    private function ExtListSyncStep(ListSource $quelle): array
    {
        $bilanz = ['ok' => true, 'lokal' => 0, 'imported' => 0, 'pushed' => 0, 'completed' => 0, 'vanished' => 0, 'resolved' => 0, 'removed' => 0, 'reason' => ''];

        // Der eigene Lesezugriff schreibt bei Alexa die Variable „Liste" neu und
        // erzeugt damit dieselbe Nachricht, die uns gerade gerufen hat. Das
        // kreist trotzdem nicht: der zweite Schreibvorgang traegt denselben Text,
        // also Changed = false, und ExtListIsTrigger laesst ihn liegen. Ueberlappen
        // kann es auch nicht — der Riegel in ExtListSync haelt den Lauf allein.
        $fremd = $quelle->Read();
        if ($fremd === false) {
            // NICHTS anfassen. Ein nicht lesbarer Bestand sieht aus wie ein leerer,
            // und ein leerer hiesse „alles geloescht" — der teuerste Fehlschluss,
            // den dieser Baustein machen koennte.
            $this->SendDebug('ExtListSync', 'Gegenstelle nicht lesbar — Lauf entfaellt', 0);
            return array_merge($bilanz, ['ok' => false, 'reason' => 'unreadable']);
        }

        // Ist die Antwort NACHWEISLICH vollstaendig? Nur dann darf ein fehlender
        // Eintrag als „von der Liste genommen" gelten. AlexaList holt
        // `?limit=100` ohne Seitenfortsetzung — bei mehr Eintraegen kommt ein
        // Ausschnitt, und der sieht aus wie eine geleerte Liste.
        $vollstaendig = count($fremd) < ListSource::READ_LIMIT;

        $lokal   = $this->ExtListLoad();
        $key     = $quelle->Key();
        // Die Kennungen gelten JE DIENST — und es sind MEHRERE moeglich.
        //
        // Warum eine Menge und nicht eine Kennung: Alexa dedupliziert nicht, dort
        // stehen „Milch" und „3 Milch" gleichzeitig. Bei uns ist das EIN Artikel,
        // weil die Einkaufsliste nach Namen zusammenfasst. Mit nur einer Kennung
        // je Zeile galt der jeweils andere Eintrag als unbekannt, wurde importiert,
        // in dieselbe Zeile verschmolzen und ueberschrieb die Kennung — bei jedem
        // Lauf „1 neu" und eine Menge, die hin und her sprang (am 24.08.2026 an
        // den echten Daten gesehen). Eine Zeile darf fuer mehrere fremde Eintraege
        // stehen.
        //
        // Altbestand: frueher stand dort eine einzelne Zeichenkette.
        $kennungen = static function (array $e) use ($key): array {
            $roh = ($e['extIds'] ?? [])[$key] ?? [];
            if (is_string($roh)) {
                $roh = $roh === '' ? [] : [$roh];
            }
            $raus = [];
            foreach ((array)$roh as $x) {
                $x = (string)$x;
                if ($x !== '') {
                    $raus[] = $x;
                }
            }
            return $raus;
        };
        $echte = static function (array $ids): array {
            return array_values(array_filter($ids, static fn(string $i): bool => strpos($i, self::EXT_PENDING) !== 0));
        };
        $bekannt = [];   // fremde Kennung => lokaler Schluessel (mehrere je Zeile)
        foreach ($lokal as $schluessel => $eintrag) {
            foreach ($echte($kennungen($eintrag)) as $id) {
                $bekannt[$id] = $schluessel;
            }
        }

        // ── 0. Hier geloeschte Eintraege an die Gegenstelle melden ──
        //
        // Muss VOR dem Import laufen: sonst kommt der geloeschte Eintrag als
        // „unbekannt und offen" zurueck und wird neu angelegt — genau der
        // gemeldete Fehler.
        $gemerkt  = $this->ExtListKnownRead();
        $friedhof = $this->ExtListRemovedRead();
        $vorher   = array_keys($gemerkt[$key] ?? []);
        foreach (array_diff($vorher, array_keys($bekannt)) as $weg) {
            $friedhof[$key][(string)$weg] = 1;
        }
        // Die Merkposten DIESES Laufs festhalten: was hier drinsteht, wird unten
        // nicht importiert — auch dann nicht, wenn das Entfernen scheitert.
        // Sonst holte ein misslungener Loeschversuch den Eintrag zurueck.
        $gesperrt    = $friedhof[$key] ?? [];
        $fremdNachId = [];
        foreach ($fremd as $f) {
            $fremdNachId[(string)$f['id']] = $f;
        }
        foreach (array_keys($gesperrt) as $id) {
            if (isset($fremdNachId[$id])) {
                if ($quelle->Remove((string)$id, (string)$fremdNachId[$id]['name'],
                        (string)($fremdNachId[$id]['spec'] ?? ''))) {
                    unset($friedhof[$key][$id]);
                    $bilanz['removed']++;
                }
                continue;
            }
            // Dort schon weg. Den Merkposten nur bei VOLLSTAENDIGER Antwort
            // fallen lassen — bei einer abgeschnittenen Liste (ueber 100
            // Eintraege) waere „nicht enthalten" kein Beweis fuer „nicht da".
            if ($vollstaendig) {
                unset($friedhof[$key][$id]);
            }
        }
        if (($friedhof[$key] ?? []) === []) {
            unset($friedhof[$key]);
        }
        @$this->WriteAttributeString('ExtListRemovedIds', (string)json_encode($friedhof));

        // ── 1. Platzhalter aufloesen — VOR dem Import ──
        //
        // Die Reihenfolge ist hier alles: ein Eintrag, den wir gerade hochgeladen
        // haben, kommt beim naechsten Lauf als „unbekannte Kennung" zurueck.
        // Wuerde erst importiert und dann aufgeloest, stuende er DOPPELT auf der
        // Liste — einmal als Original, einmal als Import. Genau das hat der
        // Prueflauf gefunden („und nicht als Import gezaehlt: ist 2, soll 1").
        //
        // Bei mehreren gleichnamigen Kandidaten wird NICHT geraten (Vorbild
        // MicrosoftFindServerMatchByTitle): ein falsch verknuepfter Eintrag haekt
        // spaeter den falschen Artikel ab, und das faellt niemandem auf.
        $offen = [];
        foreach ($fremd as $f) {
            if (!$f['done'] && !isset($bekannt[(string)$f['id']])) {
                $offen[$this->ExtListNormalize((string)$f['name'])][] = (string)$f['id'];
            }
        }
        foreach ($lokal as $schluessel => $eintrag) {
            $ids     = $kennungen($eintrag);
            $wartend = array_values(array_diff($ids, $echte($ids)));
            if ($wartend === []) {
                continue;
            }
            $name = $this->ExtListNormalize((string)($eintrag['name'] ?? ''));
            if (count($offen[$name] ?? []) !== 1) {
                continue;
            }
            $echt = $offen[$name][0];
            $this->ExtListSetId($schluessel, $echt, $key);
            $bekannt[$echt] = $schluessel;
            unset($offen[$name]);
            $bilanz['resolved']++;
        }
        if ($bilanz['resolved'] > 0) {
            $lokal = $this->ExtListLoad();
        }

        // ── 2. Von der Gegenstelle hierher ──
        $gesehen = [];
        foreach ($fremd as $f) {
            $id = (string)$f['id'];
            $gesehen[$id] = true;
            if (isset($bekannt[$id])) {
                // Bekannt: nur der Status kann sich geaendert haben.
                if ($f['done'] && !$this->ExtListIsDone($lokal[$bekannt[$id]])) {
                    $this->ExtListMarkDone($bekannt[$id]);
                    $bilanz['completed']++;
                    $bilanz['lokal']++;
                }
                continue;
            }
            if ($f['done']) {
                // Erledigt und unbekannt: das ist Vergangenheit der Gegenstelle,
                // die hier nie stand. Nicht importieren, sonst erscheinen alte
                // Einkaeufe als frische Eintraege.
                continue;
            }
            if (isset($gesperrt[$id])) {
                // Hier geloescht. Nicht wieder hereinholen, auch wenn das
                // Entfernen an der Gegenstelle gerade nicht geklappt hat.
                continue;
            }
            [$name, $menge] = $this->ExtListSplitAmount((string)$f['name'], (string)($f['spec'] ?? ''));
            $this->ExtListCreate($name, $menge, $id, $key);
            $bilanz['imported']++;
            $bilanz['lokal']++;
        }

        // Nach dem Import neu laden: ExtListCreate schreibt in die Ablage, und der
        // naechste Abschnitt muss die frischen Eintraege sehen (sonst schickte er
        // sie sofort wieder zurueck).
        $lokal = $this->ExtListLoad();

        // ── 3. Von hier zur Gegenstelle — immer, in beide Richtungen ──
        foreach ($lokal as $schluessel => $eintrag) {
            $ids      = $kennungen($eintrag);
            $vergeben = $echte($ids);
            $erledigt = $this->ExtListIsDone($eintrag);

            // 3a. Noch nie uebertragen → anlegen.
            if ($ids === []) {
                if ($erledigt) {
                    continue;   // erledigt und nie dort gewesen: nichts zu tun
                }
                $name  = (string)($eintrag['name'] ?? '');
                $menge = (string)($eintrag['amount'] ?? '');
                if ($name === '') {
                    continue;
                }
                if ($quelle->Add($name, $menge)) {
                    // Die Kennung kennt nur die Gegenstelle. Bis der naechste
                    // Lauf sie aufloest, steht ein Platzhalter — Muster aus
                    // GoogleTasksSync: ohne ihn wuerde der Eintrag bei jedem
                    // Lauf erneut hochgeladen.
                    $this->ExtListSetId($schluessel, self::EXT_PENDING . $this->InstanceID . '_' . $schluessel, $key);
                    $bilanz['pushed']++;
                }
                continue;
            }

            // Nur Platzhalter: die Auflösung lief oben (Abschnitt 1). Klappte sie
            // nicht, ist der Eintrag mehrdeutig — dann hier nichts tun und beim
            // naechsten Lauf erneut versuchen.
            if ($vergeben === []) {
                continue;
            }

            // 3b. Bekannt, aber hier erledigt → dort abhaken.
            if ($erledigt) {
                // JEDE noch offene Kennung dieser Zeile abhaken — sie kann fuer
                // mehrere fremde Eintraege stehen.
                foreach ($vergeben as $id) {
                    if (isset($fremdNachId[$id]) && !$fremdNachId[$id]['done']) {
                        $quelle->Complete($id, (string)($eintrag['name'] ?? ''),
                            (string)($eintrag['amount'] ?? ''));
                        $bilanz['completed']++;
                    }
                }
                continue;
            }

            // 3c. Bekannt, hier offen, dort ganz verschwunden.
            //
            // Verschwunden heisst „von der Liste genommen" — abgehakt bei
            // eingeschaltetem DeleteCompletedItems oder in der Alexa-App
            // geloescht. Beides ist dieselbe Absicht, also hier erledigen.
            //
            // ABER nur bei nachweislich vollstaendiger Antwort. Sonst waere
            // eine abgeschnittene Liste (ueber 100 Eintraege, siehe
            // ListSource::READ_LIMIT) ein Befehl, den halben Zettel
            // abzuhaken — und das nimmt dem Nutzer niemand wieder ab.
            // Erledigen nur, wenn ALLE Kennungen der Zeile verschwunden sind.
            $nochDa = false;
            foreach ($vergeben as $id) {
                if (isset($gesehen[$id])) {
                    $nochDa = true;
                    break;
                }
            }
            if (!$nochDa) {
                if ($vollstaendig) {
                    // Dort GELOESCHT heisst hier geloescht — nicht bloss abgehakt
                    // (so entschieden am 24.08.2026). Abhaken bleibt Abhaken:
                    // COMPLETE fuehrt weiter in den Wagen, das ist Abschnitt 2.
                    //
                    // Der Riegel $vollstaendig ist hier keine Feinheit, sondern
                    // die Bedingung: Alexa liefert hoechstens 100 Eintraege ohne
                    // Hinweis auf weitere, und „nicht enthalten" waere bei einer
                    // laengeren Liste kein Beweis fuer „geloescht". Loeschen ist
                    // nicht umkehrbar, deshalb nur bei nachweislich vollstaendiger
                    // Antwort.
                    $this->ExtListDelete($schluessel);
                    $bilanz['vanished']++;
                    $bilanz['lokal']++;
                } else {
                    $this->SendDebug('ExtListSync',
                        'Antwort unvollstaendig (' . count($fremd) . ' Eintraege) — fehlender Eintrag bleibt offen', 0);
                }
            }
        }

        // Merkposten fuer den naechsten Lauf: welche fremden Kennungen liegen
        // JETZT lokal vor. Frisch geladen, weil Import, Auflösung und Senden den
        // Bestand veraendert haben.
        $stand = [];
        foreach ($this->ExtListLoad() as $eintrag) {
            foreach ($echte($kennungen($eintrag)) as $id) {
                $stand[$id] = 1;
            }
        }
        $gemerkt[$key] = $stand;
        @$this->WriteAttributeString('ExtListKnownIds', (string)json_encode($gemerkt));

        @$this->WriteAttributeInteger('ExtListLastSync', time());
        $this->SendDebug('ExtListSync', sprintf(
            '%s: %d neu, %d gesendet, %d aufgeloest, %d abgehakt, %d dort entfernt, %d hier entfernt',
            $key, $bilanz['imported'], $bilanz['pushed'], $bilanz['resolved'],
            $bilanz['completed'], $bilanz['vanished'], $bilanz['removed']), 0);
        return $bilanz;
    }

    // ────────────────────────────── Menge ──────────────────────────────

    /**
     * Trennt eine gesprochene Menge vom Artikelnamen — Bring-Stil.
     *
     * Alexa hat kein Mengenfeld, die Zahl steckt im Namen („3 Milch"). Bring
     * trennt selbst, dann kommt die Menge als `$spec` herein und wird nur
     * uebernommen.
     *
     * @return array{0: string, 1: string} Name, Menge
     */
    private function ExtListSplitAmount(string $text, string $spec = ''): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($spec !== '') {
            return [$text, trim($spec)];
        }
        if ($text === '' || !$this->ExtListParseAmountEnabled()) {
            return [$text, ''];
        }
        // Zahl am Anfang: ganz, mit Komma, als Bruch oder als Bruchzeichen.
        $muster = '/^((?:\d+[.,]\d+)|(?:\d+\s*\/\s*\d+)|[½⅓⅔¼¾⅕⅛]|\d+)\s+(.*)$/u';
        if (preg_match($muster, $text, $m) !== 1) {
            return [$text, ''];
        }
        $zahl = trim($m[1]);
        $rest = trim($m[2]);
        if ($rest === '') {
            return [$text, ''];
        }
        // Steht dahinter eine bekannte Einheit, gehoert sie zur Menge.
        $teile = explode(' ', $rest);
        $wort  = $this->ExtListNormalize(rtrim($teile[0], '.'));
        if (in_array($wort, self::EXT_UNITS, true) && count($teile) > 1) {
            return [trim(implode(' ', array_slice($teile, 1))), $zahl . ' ' . $teile[0]];
        }
        // Keine Einheit: die blosse Zahl ist die Menge, der Rest der Name.
        return [$rest, $zahl];
    }

    /** Vergleichsform eines Namens — dieselbe Regel wie in der Einkaufsliste. */
    private function ExtListNormalize(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    // ────────────────────── Haken, die das Modul stellt ──────────────────────
    // Absichtlich keine abstrakten Methoden: ein Trait mit abstrakten Methoden
    // erzwingt die Signatur, und beide Module haben unterschiedliche Ablagen
    // (fortlaufende Kennung gegen Hex-Kennung). Die Haken sind hier nur benannt:
    //
    //   ExtListSources(): list<ListSource>    die eingerichteten Gegenstellen
    //   ExtListEnabled(): bool                Schalter der Instanz
    //   ExtListParseAmountEnabled(): bool     Menge aus dem Namen loesen?
    //   ExtListLoad(): array                  [schluessel => ['name','amount','extIds'=>[dienst=>id]]]
    //   ExtListIsDone(array $eintrag): bool   erledigt bzw. im Wagen?
    //   ExtListCreate(string $name, string $menge, string $extId, string $quelle): void
    //   ExtListMarkDone(string|int $schluessel): void   (dort abgehakt)
    //   ExtListDelete(string|int $schluessel): void     (dort geloescht)
    //   ExtListSetId(string|int $schluessel, string $extId, string $quelle): void
    //   ExtListAfterChange(): void            Kachel/Anzeige nach sichtbarer Aenderung
}

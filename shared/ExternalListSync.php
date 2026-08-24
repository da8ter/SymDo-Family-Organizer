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
        $leer = ['ok' => false, 'imported' => 0, 'pushed' => 0, 'completed' => 0, 'resolved' => 0, 'reason' => ''];
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
            $summe   = ['ok' => false, 'imported' => 0, 'pushed' => 0, 'completed' => 0, 'resolved' => 0, 'reason' => ''];
            $gruende = [];
            $runde   = function () use ($quellen, &$summe, &$gruende): int {
                $neuImportiert = 0;
                foreach ($quellen as $quelle) {
                    $b = $this->ExtListSyncStep($quelle);
                    foreach (['imported', 'pushed', 'completed', 'resolved'] as $feld) {
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

            $summe['reason'] = implode(', ', array_unique($gruende));
            return $summe;
        } finally {
            IPS_SemaphoreLeave($riegel);
        }
    }

    /** @return array{ok: bool, imported: int, pushed: int, completed: int, resolved: int, reason: string} */
    private function ExtListSyncStep(ListSource $quelle): array
    {
        $bilanz = ['ok' => true, 'imported' => 0, 'pushed' => 0, 'completed' => 0, 'resolved' => 0, 'reason' => ''];

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
        // Die Kennung gilt JE DIENST: derselbe Artikel kann gleichzeitig bei
        // Alexa und bei Bring liegen, mit verschiedenen Kennungen.
        $kennung = static fn(array $e): string => (string)(($e['extIds'] ?? [])[$key] ?? '');
        $bekannt = [];   // fremde Kennung => lokaler Schluessel
        foreach ($lokal as $schluessel => $eintrag) {
            $id = $kennung($eintrag);
            if ($id !== '' && strpos($id, self::EXT_PENDING) !== 0) {
                $bekannt[$id] = $schluessel;
            }
        }

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
            $id = $kennung($eintrag);
            if (strpos($id, self::EXT_PENDING) !== 0) {
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
                }
                continue;
            }
            if ($f['done']) {
                // Erledigt und unbekannt: das ist Vergangenheit der Gegenstelle,
                // die hier nie stand. Nicht importieren, sonst erscheinen alte
                // Einkaeufe als frische Eintraege.
                continue;
            }
            [$name, $menge] = $this->ExtListSplitAmount((string)$f['name'], (string)($f['spec'] ?? ''));
            $this->ExtListCreate($name, $menge, $id, $key);
            $bilanz['imported']++;
        }

        // Nach dem Import neu laden: ExtListCreate schreibt in die Ablage, und der
        // naechste Abschnitt muss die frischen Eintraege sehen (sonst schickte er
        // sie sofort wieder zurueck).
        $lokal = $this->ExtListLoad();

        // ── 3. Von hier zur Gegenstelle ──
        if ($this->ExtListPushEnabled()) {
            foreach ($lokal as $schluessel => $eintrag) {
                $id       = $kennung($eintrag);
                $erledigt = $this->ExtListIsDone($eintrag);

                // 3a. Noch nie uebertragen → anlegen.
                if ($id === '') {
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

                // Platzhalter: die Auflösung lief oben (Abschnitt 1). Klappte sie
                // nicht, ist der Eintrag mehrdeutig — dann hier nichts tun und
                // beim naechsten Lauf erneut versuchen.
                if (strpos($id, self::EXT_PENDING) === 0) {
                    continue;
                }

                // 3b. Bekannt, aber hier erledigt → dort abhaken.
                if ($erledigt) {
                    $nochOffen = false;
                    foreach ($fremd as $f) {
                        if ((string)$f['id'] === $id && !$f['done']) {
                            $nochOffen = true;
                            break;
                        }
                    }
                    if ($nochOffen) {
                        $quelle->Complete($id, (string)($eintrag['name'] ?? ''));
                        $bilanz['completed']++;
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
                if (!isset($gesehen[$id])) {
                    if ($vollstaendig) {
                        $this->ExtListMarkDone($schluessel);
                        $bilanz['completed']++;
                    } else {
                        $this->SendDebug('ExtListSync',
                            'Antwort unvollstaendig (' . count($fremd) . ' Eintraege) — fehlender Eintrag bleibt offen', 0);
                    }
                }
            }
        }

        @$this->WriteAttributeInteger('ExtListLastSync', time());
        $this->SendDebug('ExtListSync', sprintf('%s: %d neu, %d gesendet, %d aufgeloest, %d abgehakt',
            $key, $bilanz['imported'], $bilanz['pushed'], $bilanz['resolved'], $bilanz['completed']), 0);
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
    //   ExtListPushEnabled(): bool            eigene Eintraege senden?
    //   ExtListParseAmountEnabled(): bool     Menge aus dem Namen loesen?
    //   ExtListLoad(): array                  [schluessel => ['name','amount','extIds'=>[dienst=>id]]]
    //   ExtListIsDone(array $eintrag): bool   erledigt bzw. im Wagen?
    //   ExtListCreate(string $name, string $menge, string $extId, string $quelle): void
    //   ExtListMarkDone(string|int $schluessel): void
    //   ExtListSetId(string|int $schluessel, string $extId, string $quelle): void
}

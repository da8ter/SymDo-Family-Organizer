<?php

declare(strict_types=1);

/**
 * Datenhaltung und Rechenwerk der Routinen-Kachel.
 *
 * Alles Zustandsbehaftete liegt hier, damit ein Prüfstand es ohne Symcon
 * ausführen kann (Muster: Stundenplan/libs/TimetableStore.php). Der Zustand
 * eines Tages steht in EINEM Attribut-JSON; die Münzstände in einem zweiten,
 * weil sie den Tagesreset überleben.
 */
trait RoutineStore
{
    // ------------------------------------------------------------------
    // Konfiguration lesen
    // ------------------------------------------------------------------

    /**
     * Eine Uhrzeit aus dem Formular als Minuten des Tages, -1 wenn leer.
     *
     * SelectTime trägt seinen Wert als JSON ({"hour":..,"minute":..}); ältere
     * oder von Hand gesetzte Werte können „HH:MM" sein. Beides lesen — dieselbe
     * Falle hat im Stundenplan einmal jede Zelle als „ungültig" angezeigt.
     */
    private function ZeitMinuten(mixed $wert): int
    {
        if (is_string($wert)) {
            $roh = trim($wert);
            if ($roh === '') {
                return -1;
            }
            if ($roh[0] === '{') {
                $wert = json_decode($roh, true);
            } elseif (preg_match('/^(\d{1,2}):(\d{2})/', $roh, $t)) {
                $wert = ['hour' => (int)$t[1], 'minute' => (int)$t[2]];
            } else {
                return -1;
            }
        }
        if (!is_array($wert) || !isset($wert['hour'], $wert['minute'])) {
            return -1;
        }
        $std = (int)$wert['hour'];
        $min = (int)$wert['minute'];
        if ($std < 0 || $std > 23 || $min < 0 || $min > 59) {
            return -1;
        }
        return $std * 60 + $min;
    }

    /**
     * Die konfigurierten Routinen, nur brauchbare Zeilen (mit Kennung und Name).
     *
     * IPS_GetConfiguration statt ReadPropertyString: so liest auch ein Aufruf
     * vor dem ersten Kernel-Neustart nach einem Update nicht ins Leere.
     *
     * @return list<array{id:string,name:string,emoji:string,memberId:string,von:int,bis:int}>
     */
    private function RoutinenLesen(): array
    {
        $cfg = json_decode((string)@IPS_GetConfiguration($this->InstanceID), true);
        $raus = [];
        foreach ((array)json_decode((string)($cfg['Routines'] ?? '[]'), true) as $z) {
            if (!is_array($z)) {
                continue;
            }
            $id   = trim((string)($z['id'] ?? ''));
            $name = trim((string)($z['name'] ?? ''));
            if ($id === '' || $name === '') {
                continue;
            }
            $raus[] = [
                'id'       => $id,
                'name'     => $name,
                'emoji'    => trim((string)($z['emoji'] ?? '')),
                'memberId' => trim((string)($z['memberId'] ?? '')),
                'von'      => $this->ZeitMinuten($z['von'] ?? ''),
                'bis'      => $this->ZeitMinuten($z['bis'] ?? ''),
            ];
        }
        return $raus;
    }

    /**
     * Die Schritte, je Routine-Kennung gruppiert, in Formular-Reihenfolge.
     *
     * @return array<string, list<array{emoji:string,text:string,coins:int}>>
     */
    private function SchritteJeRoutine(): array
    {
        $cfg = json_decode((string)@IPS_GetConfiguration($this->InstanceID), true);
        $karte = [];
        foreach ((array)json_decode((string)($cfg['Steps'] ?? '[]'), true) as $z) {
            if (!is_array($z)) {
                continue;
            }
            $routine = trim((string)($z['routine'] ?? ''));
            $text    = trim((string)($z['text'] ?? ''));
            if ($routine === '' || $text === '') {
                continue;
            }
            $karte[$routine][] = [
                'emoji' => trim((string)($z['emoji'] ?? '')),
                'text'  => $text,
                'coins' => max(0, (int)($z['coins'] ?? 5)),
            ];
        }
        return $karte;
    }

    /**
     * Münzt fehlende Routine-Kennungen nach und schreibt die Liste zurück.
     *
     * Neue Zeilen kommen ohne Kennung aus dem Formular (die Spalte ist
     * unsichtbar, `add` liefert ''). Ohne Kennung hängen weder Häkchen noch
     * Schritte noch Variablen an der Routine.
     */
    private function RoutinenNachtragen(): void
    {
        $cfg = json_decode((string)@IPS_GetConfiguration($this->InstanceID), true);
        if (!is_array($cfg) || !array_key_exists('Routines', $cfg)) {
            return;
        }
        $zeilen = json_decode((string)$cfg['Routines'], true);
        if (!is_array($zeilen) || $zeilen === []) {
            return;
        }
        $ergaenzt = false;
        foreach ($zeilen as &$z) {
            if (!is_array($z)) {
                continue;
            }
            if (trim((string)($z['id'] ?? '')) === '' && trim((string)($z['name'] ?? '')) !== '') {
                $z['id']  = bin2hex(random_bytes(8));
                $ergaenzt = true;
            }
        }
        unset($z);
        if (!$ergaenzt) {
            return;
        }
        IPS_SetProperty($this->InstanceID, 'Routines', json_encode($zeilen, JSON_UNESCAPED_UNICODE));
        $this->UebernehmenNachtragen();
    }

    // ------------------------------------------------------------------
    // Tageszustand
    // ------------------------------------------------------------------

    /**
     * Der logische Tag: er kippt zur Reset-Zeit, nicht um Mitternacht.
     * Um 02:59 gehört die Nacht noch zum Vortag (Reset-Vorgabe 03:00).
     */
    private function TagKennung(int $jetzt): string
    {
        [$std, $min] = $this->ResetZeit();
        return date('Y-m-d', $jetzt - ($std * 3600 + $min * 60));
    }

    /** @return array{0:int,1:int} Stunde und Minute der Reset-Zeit */
    private function ResetZeit(): array
    {
        $cfg = json_decode((string)@IPS_GetConfiguration($this->InstanceID), true);
        $m = $this->ZeitMinuten($cfg['ResetTime'] ?? '');
        if ($m < 0) {
            $m = 3 * 60;
        }
        return [intdiv($m, 60), $m % 60];
    }

    /**
     * Der Zustand des laufenden Tages. Ist der gespeicherte Tag ein anderer,
     * beginnt der Tag frisch — das holt einen verpassten Timer nach (Box war
     * aus), ohne dass jemand etwas tun muss.
     *
     * done: Routine-Kennung => (Schritt-Index => beim Abhaken vergebene
     * Münzen). Der gemerkte Wert macht das Zurücknehmen symmetrisch, auch wenn
     * das Formular den Münzwert inzwischen geändert hat.
     */
    private function ZustandAktuell(int $jetzt): array
    {
        $roh = json_decode($this->ReadAttributeString('State'), true);
        $tag = $this->TagKennung($jetzt);
        if (!is_array($roh) || (string)($roh['day'] ?? '') !== $tag) {
            return ['day' => $tag, 'done' => []];
        }
        $roh['done'] = is_array($roh['done'] ?? null) ? $roh['done'] : [];
        return $roh;
    }

    /** Neuer Tag: Zustand leeren und die Erledigt-Variablen zurücknehmen. */
    private function TagesReset(int $jetzt): void
    {
        $this->WriteAttributeString('State', json_encode(['day' => $this->TagKennung($jetzt), 'done' => []]));
        foreach ($this->RoutinenLesen() as $r) {
            @$this->SetValue('DONE_' . $r['id'], false);
        }
    }

    /**
     * Ein Häkchen setzen oder zurücknehmen — mit ZIELZUSTAND, kein Toggle:
     * eine doppelt zugestellte Anfrage darf das Häkchen nicht wieder entfernen
     * (Lehre aus der Einkaufs-Übersichtskachel).
     */
    private function Abhaken(string $routineId, int $schritt, bool $done, int $jetzt): void
    {
        $routinen = [];
        foreach ($this->RoutinenLesen() as $r) {
            $routinen[$r['id']] = $r;
        }
        $schritte = $this->SchritteJeRoutine()[$routineId] ?? [];
        if (!isset($routinen[$routineId]) || !isset($schritte[$schritt])) {
            return;
        }

        $zustand = $this->ZustandAktuell($jetzt);
        $war = array_key_exists((string)$schritt, $zustand['done'][$routineId] ?? []);
        if ($war === $done) {
            return;                                     // schon im Zielzustand
        }

        $muenzenAn = (bool)@IPS_GetProperty($this->InstanceID, 'CoinsEnabled');
        $beutel = $routinen[$routineId]['memberId'] !== '' ? $routinen[$routineId]['memberId'] : $routineId;
        if ($done) {
            $wert = $muenzenAn ? (int)$schritte[$schritt]['coins'] : 0;
            $zustand['done'][$routineId][(string)$schritt] = $wert;
            if ($wert > 0) {
                $this->MuenzenAnpassen($beutel, $wert);
            }
        } else {
            // Beim Zurücknehmen zählt der BEIM ABHAKEN gemerkte Wert — so bleibt
            // An/Aus/An netto genau eine Gutschrift, egal was das Formular
            // zwischenzeitlich am Münzwert gedreht hat.
            $wert = (int)($zustand['done'][$routineId][(string)$schritt] ?? 0);
            unset($zustand['done'][$routineId][(string)$schritt]);
            if ($zustand['done'][$routineId] === []) {
                unset($zustand['done'][$routineId]);
            }
            if ($wert > 0) {
                $this->MuenzenAnpassen($beutel, -$wert);
            }
        }
        $this->WriteAttributeString('State', json_encode($zustand));

        $alle = count($zustand['done'][$routineId] ?? []) >= count($schritte);
        @$this->SetValue('DONE_' . $routineId, $alle);
    }

    // ------------------------------------------------------------------
    // Münzen
    // ------------------------------------------------------------------

    /**
     * Den Geldbeutel anpassen; liefert den neuen Stand. Nie unter null — ein
     * Kind soll keine Schulden sehen, auch wenn die Eltern zu viel einlösen.
     */
    private function MuenzenAnpassen(string $beutel, int $delta): int
    {
        if ($beutel === '') {
            return 0;
        }
        $karte = json_decode($this->ReadAttributeString('Coins'), true);
        if (!is_array($karte)) {
            $karte = [];
        }
        $neu = max(0, (int)($karte[$beutel] ?? 0) + $delta);
        $karte[$beutel] = $neu;
        $this->WriteAttributeString('Coins', json_encode($karte));
        @$this->SetValue('COINS_' . $beutel, $neu);
        return $neu;
    }

    /** @return array<string,int> Geldbeutel-Kennung => Stand */
    private function MuenzenLesen(): array
    {
        $karte = json_decode($this->ReadAttributeString('Coins'), true);
        return is_array($karte) ? array_map('intval', $karte) : [];
    }

    // ------------------------------------------------------------------
    // Gateway: Mitglieder, Gesichter
    // ------------------------------------------------------------------

    /** Das gewählte Gateway, mit Rückfall auf das, das die App bedient. */
    private function GatewayInstanz(): int
    {
        $gw = (int)@IPS_GetProperty($this->InstanceID, 'GatewayInstanceID');
        if ($gw > 0 && IPS_InstanceExists($gw)
            && (IPS_GetInstance($gw)['ModuleInfo']['ModuleID'] ?? '') === self::GATEWAY_GUID) {
            return $gw;
        }
        $ids = @IPS_GetInstanceListByModuleID(self::GATEWAY_GUID);
        if (!is_array($ids) || $ids === []) {
            return 0;
        }
        sort($ids);
        return (int)$ids[0];
    }

    /**
     * Alle Mitglieder mit Namen und Avatar (Data-URI, für die Kachel
     * verkleinert). Kennung => ['name','avatar'].
     *
     * @return array<string, array{name:string,avatar:string}>
     */
    private function Mitglieder(): array
    {
        $gw = $this->GatewayInstanz();
        if ($gw <= 0 || !function_exists('TGW_GetUsersForTile')) {
            return [];
        }
        try {
            $roh = json_decode((string)@TGW_GetUsersForTile($gw), true);
        } catch (\Throwable $e) {
            return [];
        }
        $karte = [];
        foreach (is_array($roh) ? $roh : [] as $u) {
            $id = is_array($u) ? trim((string)($u['id'] ?? '')) : '';
            if ($id === '') {
                continue;
            }
            $karte[$id] = [
                'name'   => trim((string)($u['name'] ?? '')),
                'avatar' => (string)($u['avatar'] ?? ''),
            ];
        }
        return $karte;
    }

    /**
     * Auswahloptionen fürs Formular: erst die Kinder, sonst alle Mitglieder.
     * Gespeichert wird die KENNUNG — der Name löst keine Avatare auf.
     *
     * @return list<array{caption:string,value:string}>
     */
    private function MitgliederOptionen(): array
    {
        $gw = $this->GatewayInstanz();
        $optionen = [['caption' => $this->Translate('— none —'), 'value' => '']];
        if ($gw <= 0 || !function_exists('TGW_GetUsers')) {
            return $optionen;
        }
        try {
            $roh = json_decode((string)@TGW_GetUsers($gw), true);
        } catch (\Throwable $e) {
            return $optionen;
        }
        $kinder = [];
        $alle   = [];
        foreach (is_array($roh) ? $roh : [] as $u) {
            if (!is_array($u)) {
                continue;
            }
            $id   = trim((string)($u['id'] ?? ''));
            $name = trim((string)($u['name'] ?? ''));
            if ($id === '' || $name === '') {
                continue;
            }
            $alle[$id] = $name;
            if (strtolower(trim((string)($u['persona'] ?? ''))) === 'child') {
                $kinder[$id] = $name;
            }
        }
        foreach ($kinder !== [] ? $kinder : $alle as $id => $name) {
            $optionen[] = ['caption' => $name, 'value' => $id];
        }
        return $optionen;
    }

    // ------------------------------------------------------------------
    // Heute-ToDos (Leerlauf-Füller)
    // ------------------------------------------------------------------

    /**
     * Offene Aufgaben der Routine-Kinder, fällig heute oder überfällig — aus
     * allen ToDo-Listen des Systems. Angezeigt, wenn gerade keine Routine im
     * Zeitfenster liegt.
     *
     * @return list<array{list:int,id:int,title:string,memberId:string,overdue:bool}>
     */
    private function HeutigeTodos(int $jetzt): array
    {
        if (!(bool)@IPS_GetProperty($this->InstanceID, 'IdleTodos')) {
            return [];
        }
        $kinder = [];
        foreach ($this->RoutinenLesen() as $r) {
            if ($r['memberId'] !== '') {
                $kinder[$r['memberId']] = true;
            }
        }
        if ($kinder === [] || !function_exists('TDL_GetAppState')) {
            return [];
        }
        $tagesEnde = strtotime('tomorrow', $jetzt);      // lokale Mitternacht
        $heute     = date('Y-m-d', $jetzt);
        $raus = [];
        foreach ((array)@IPS_GetInstanceListByModuleID(self::TODO_GUID) as $listID) {
            try {
                $antwort = json_decode((string)@TDL_GetAppState((int)$listID), true);
            } catch (\Throwable $e) {
                continue;
            }
            foreach ((array)($antwort['state']['items'] ?? []) as $it) {
                if (!is_array($it) || ($it['done'] ?? false) === true) {
                    continue;
                }
                $due = (int)($it['due'] ?? 0);
                if ($due <= 0) {
                    continue;                            // ohne Termin kein „heute"
                }
                // Ganztägig zählt der Kalendertag, sonst der Zeitpunkt.
                $faellig = !empty($it['dueAllDay'])
                    ? (string)($it['dueDay'] ?? date('Y-m-d', $due)) <= $heute
                    : $due < $tagesEnde;
                if (!$faellig) {
                    continue;
                }
                $mitglied = '';
                foreach ((array)($it['assignedTo'] ?? []) as $mid) {
                    if (isset($kinder[(string)$mid])) {
                        $mitglied = (string)$mid;
                        break;
                    }
                }
                if ($mitglied === '') {
                    continue;
                }
                $ueberfaellig = !empty($it['dueAllDay'])
                    ? (string)($it['dueDay'] ?? date('Y-m-d', $due)) < $heute
                    : $due < $jetzt;
                $raus[] = [
                    'list'     => (int)$listID,
                    'id'       => (int)($it['id'] ?? 0),
                    'title'    => trim((string)($it['title'] ?? '')),
                    'memberId' => $mitglied,
                    'due'      => $due,
                    'overdue'  => $ueberfaellig,
                ];
            }
        }
        usort($raus, static fn(array $a, array $b): int => $a['due'] <=> $b['due']);
        // Die Kachel ist ein Füller, keine zweite ToDo-Liste: hart deckeln.
        return array_slice(array_map(static function (array $t): array {
            unset($t['due']);
            return $t;
        }, $raus), 0, 20);
    }

    // ------------------------------------------------------------------
    // Payload und Timer
    // ------------------------------------------------------------------

    /** Millisekunden bis zur nächsten Reset-Zeit, heute oder morgen. */
    private function NaechsteResetMs(int $jetzt): int
    {
        [$std, $min] = $this->ResetZeit();
        $heute = mktime($std, $min, 0, (int)date('n', $jetzt), (int)date('j', $jetzt), (int)date('Y', $jetzt));
        if ($heute === false) {
            return 3600000;
        }
        // strtotime rechnet über die Zeitumstellung richtig, +86400 nicht.
        $ziel = $heute > $jetzt ? (int)$heute : (int)strtotime('+1 day', (int)$heute);
        // Mindestabstand, damit ein Grenzfall (Zielzeit genau jetzt) den Timer
        // nicht in eine Schleife schickt.
        return max(60000, ($ziel - $jetzt) * 1000);
    }

    /** Der komplette Kachel-Zustand. Träger Tagesreset inklusive. */
    private function PayloadBauen(int $jetzt): array
    {
        $zustand = $this->ZustandAktuell($jetzt);
        $gespeichert = json_decode($this->ReadAttributeString('State'), true);
        if ((string)(is_array($gespeichert) ? ($gespeichert['day'] ?? '') : '') !== $zustand['day']) {
            // Der gespeicherte Tag ist vorbei: Variablen jetzt mit umlegen,
            // nicht erst beim nächsten Häkchen.
            $this->TagesReset($jetzt);
        }

        $mitglieder = $this->Mitglieder();
        $schritte   = $this->SchritteJeRoutine();
        $muenzenAn  = (bool)@IPS_GetProperty($this->InstanceID, 'CoinsEnabled');
        $staende    = $muenzenAn ? $this->MuenzenLesen() : [];

        $routinen = [];
        $benutzt  = [];
        foreach ($this->RoutinenLesen() as $r) {
            $liste = [];
            foreach ($schritte[$r['id']] ?? [] as $i => $s) {
                $liste[] = [
                    'emoji' => $s['emoji'],
                    'text'  => $s['text'],
                    'coins' => $muenzenAn ? $s['coins'] : 0,
                    'done'  => array_key_exists((string)$i, $zustand['done'][$r['id']] ?? []),
                ];
            }
            $beutel = $r['memberId'] !== '' ? $r['memberId'] : $r['id'];
            if ($r['memberId'] !== '') {
                $benutzt[$r['memberId']] = true;
            }
            $routinen[] = [
                'id'       => $r['id'],
                'name'     => $r['name'],
                'emoji'    => $r['emoji'],
                'memberId' => $r['memberId'],
                'von'      => $r['von'],
                'bis'      => $r['bis'],
                'beutel'   => $beutel,
                'steps'    => $liste,
            ];
        }

        $personen = [];
        foreach ($benutzt as $id => $unbenutzt) {
            $personen[$id] = $mitglieder[$id] ?? ['name' => '', 'avatar' => ''];
        }

        return [
            'type'     => 'state',
            'day'      => $zustand['day'],
            'now'      => (int)date('G', $jetzt) * 60 + (int)date('i', $jetzt),
            'coins'    => $muenzenAn ? $staende : null,
            'routines' => $routinen,
            'members'  => $personen,
            'todos'    => $this->HeutigeTodos($jetzt),
            'texts'    => [
                'hello'    => $this->Translate('Hi %s!'),
                'of'       => $this->Translate('%1$d of %2$d'),
                'allDone'  => $this->Translate('All done!'),
                'praise'   => $this->Translate('You did it — high five!'),
                'idleNone' => $this->Translate('Nothing to do right now. Enjoy!'),
                'idleNext' => $this->Translate('Coming up at %1$s: %2$s'),
                'todoHead' => $this->Translate('Today\'s tasks'),
                'overdue'  => $this->Translate('overdue'),
                'noSteps'  => $this->Translate('No steps configured yet — add routines and steps in the instance settings.'),
            ],
        ];
    }
}

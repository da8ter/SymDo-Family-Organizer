<?php

declare(strict_types=1);

require_once __DIR__ . '/HomeworkCalc.php';

/**
 * Hausaufgaben — Fach, Fälligkeit, erledigt, Notiz. Je Eintrag genau ein Kind.
 *
 * Bewusst KEINE ToDo-Instanz, sondern ein Bestand des Gateways nach dem Muster
 * der Notizen: ein Attribut, EIN POST-Endpunkt mit `action` im Rumpf, ein Zweig
 * im Kachel-Relay. Vier Gründe:
 *
 *  1. Eine Aufgabe hat kein Fach und keinen Ordner. Ein Fach als Freitext im
 *     Notizfeld verliert Symbol und Farbe, die der Stundenplan schon kennt.
 *  2. Jede Listeninstanz trägt den vollen Abgleich-Apparat (CalDAV, Google,
 *     Microsoft) und zwanzig Anzeigeschalter — für Hausaufgaben alles Ballast.
 *  3. Der Kindmodus filtert Aufgaben nur über die Zuweisung. Hausaufgaben
 *     stünden damit zwischen „Zimmer aufräumen" in derselben Liste.
 *  4. Eine neue Listenart in /v1/discovery zerlegt die ausgelieferte iOS-App
 *     (siehe Notes.php). Hausaufgaben erscheinen deshalb NICHT in discovery.
 *
 * Ablageform im Attribut HomeworkStore:
 *
 *   { "v":1, "rev":12,
 *     "items":[{"id","srcId","childId","subject","due","done","doneAt","doneBy",
 *               "note","source","createdAt","updatedAt"}] }
 *
 * `srcId` ist die Nummer der Aufgabe in der fremden Quelle (heute WebUntis).
 * Sie ist der Schluessel, an dem ein zweiter Abruf denselben Eintrag
 * wiedererkennt — und die Grenze, hinter der der Abruf nicht wirkt: ein
 * Eintrag OHNE sie ist von Hand angelegt und wird niemals angefasst.
 *
 * Das Fach steht als NAME drin, nicht als Kennung: im Stundenplan ist die
 * Fachkennung schon der Name, und ein zweiter Name derselben Sache wäre eine
 * zweite Wahrheit.
 *
 * Alles Rechnende liegt in HomeworkCalc — dieser Trait hält nur den Bestand,
 * die Sperre und den Verteiler.
 */
trait Homework
{
    private const HW_ATTR = 'HomeworkStore';
    private const HW_LOCK = 'TGW_Homework_';
    /** 300 Einträge mit je 500 Zeichen Notiz liegen weit darunter. */
    private const HW_STORE_MAX = 262144;

    private function HomeworkCreate(): void
    {
        $this->RegisterAttributeString(self::HW_ATTR, '');
    }

    private function HomeworkStore(): array
    {
        $leer = ['v' => 1, 'rev' => 0, 'items' => []];
        $d = json_decode($this->ReadAttributeStringSafe(self::HW_ATTR, ''), true);
        if (!is_array($d)) {
            return $leer;
        }
        if (!isset($d['items']) || !is_array($d['items'])) {
            $d['items'] = [];
        }
        $d['v'] = (int)($d['v'] ?? 1);
        $d['rev'] = (int)($d['rev'] ?? 0);
        return $d;
    }

    /**
     * Der einzige Schreiber. Mit Rücklese-Probe: ein vor dem Kernel-Neustart
     * unbekanntes Attribut schluckt still, und die PHP-Warnung zerlegte im Hook
     * die HTTP-Antwort (dieselbe Begründung wie in Notes.php).
     */
    private function HomeworkWriteStore(array $store): bool
    {
        $store['rev'] = (int)$store['rev'] + 1;
        $store['items'] = array_values($store['items']);
        $json = json_encode($store, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || strlen($json) > self::HW_STORE_MAX) {
            return false;
        }
        @$this->WriteAttributeString(self::HW_ATTR, $json);
        if ($this->ReadAttributeStringSafe(self::HW_ATTR, '') === $json) {
            /* Die einzige Schreibstelle — also der richtige Ort für das Signal.
               Ohne es sähe ein anderes Gerät eine neue Hausaufgabe erst nach dem
               Neuladen: der Bestand hängt an keiner Listen-Revision. */
            $this->WsPushDirty();
            /* Die Stundenplan-Kacheln zeigen die Zahl offener Aufgaben an der
               Stunde. Der Einmal-Timer legt das Nachziehen HINTER den laufenden
               Hook und bündelt eine Klickserie zu einem Neuzeichnen. */
            $this->RegisterOnceTimer('HomeworkNachziehen', 'TGW_HomeworkRefreshTiles($_IPS[\'TARGET\']);');
            return true;
        }
        $this->LogMessage(
            'SymDo: Attribut ' . self::HW_ATTR . ' ist nicht speicherbar — der Symcon-Kernel '
            . 'muss nach dem Modul-Update einmal neu gestartet werden.',
            KL_ERROR
        );
        return false;
    }

    private function HomeworkFehler(string $code): array
    {
        return ['ok' => false, 'error' => ['code' => $code]];
    }

    /** Die Kennungen der Kinder — nur an ihnen darf eine Hausaufgabe hängen. */
    private function HomeworkKinder(): array
    {
        $raus = [];
        foreach ($this->LoadUsers() as $u) {
            if (strtolower(trim((string)($u['persona'] ?? ''))) === 'child'
                && trim((string)($u['id'] ?? '')) !== '') {
                $raus[] = trim((string)$u['id']);
            }
        }
        return $raus;
    }

    /**
     * Die Fächer aus den Stundenplan-Instanzen, nur die Namen.
     *
     * @return list<string>
     */
    private function HomeworkFaecher(): array
    {
        $raus = [];
        foreach ($this->TimetableInstances() as $id) {
            if (!function_exists('STPL_GetSubjects')) {
                break;
            }
            try {
                $roh = json_decode((string)@STPL_GetSubjects((int)$id), true);
            } catch (\Throwable $e) {
                continue;
            }
            foreach (is_array($roh) ? $roh : [] as $f) {
                $name = is_array($f) ? trim((string)($f['name'] ?? '')) : '';
                if ($name !== '' && !in_array($name, $raus, true)) {
                    $raus[] = $name;
                }
            }
        }
        return $raus;
    }

    /** Die Einträge, aufbewahrungsgefiltert. Der Lesepfad schreibt nie. */
    private function HomeworkItems(): array
    {
        return HomeworkCalc::Aufbewahrung($this->HomeworkStore()['items'], time());
    }

    /**
     * Öffentliche Auskunft für andere Module (Stundenplan-Kachel, Sprachdialog).
     * Wie TGW_GetUsers: JSON heraus, keine Objekte.
     */
    public function GetHomework(string $ChildId = ''): string
    {
        $kind = trim($ChildId);
        $items = [];
        foreach ($this->HomeworkItems() as $i) {
            if ($kind === '' || (string)$i['childId'] === $kind) {
                $items[] = $i;
            }
        }
        return (string)json_encode(['items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Die Stundenplan-Kacheln neu zeichnen lassen. Läuft über den Einmal-Timer
     * und damit außerhalb des Hooks — ein direkter Aufruf mitten im Hook hieße,
     * dass das Gateway das Stundenplan-Modul ruft, während es selbst arbeitet.
     */
    public function HomeworkRefreshTiles(): void
    {
        if (!function_exists('STPL_Refresh')) {
            return;
        }
        foreach ($this->TimetableInstances() as $id) {
            try {
                @STPL_Refresh((int)$id);
            } catch (\Throwable $e) {
                // eine Kachel, die nicht mag, hält die anderen nicht auf
            }
        }
    }

    /**
     * Der Endpunkt: ein Pfad, Aktion im Rumpf. Lesen läuft ohne Sperre, alles
     * Ändernde darunter — und im Zweifel wird abgelehnt statt gewartet, damit
     * keine Nutzereingabe still verloren geht (Muster aus Notes.php).
     */
    private function HomeworkHandleAction(array $body, ?array $device = null): array
    {
        $action = (string)($body['action'] ?? '');
        $store = $this->HomeworkStore();

        if ($action === '' || $action === 'list') {
            $kind = trim((string)($body['childId'] ?? ''));
            $mitErledigten = ($body['includeDone'] ?? true) !== false;
            $von = trim((string)($body['from'] ?? ''));
            $bis = trim((string)($body['to'] ?? ''));
            $items = [];
            foreach ($this->HomeworkItems() as $i) {
                if ($kind !== '' && (string)$i['childId'] !== $kind) {
                    continue;
                }
                if (!$mitErledigten && ($i['done'] ?? false) === true) {
                    continue;
                }
                $due = (string)$i['due'];
                if ($von !== '' && $due !== '' && $due < $von) {
                    continue;
                }
                if ($bis !== '' && $due !== '' && $due > $bis) {
                    continue;
                }
                $items[] = $i;
            }
            /* Nach Fälligkeit, Einträge ohne Datum zuletzt: „irgendwann" gehört
               nicht zwischen „heute" und „morgen". */
            usort($items, static function (array $a, array $b): int {
                $da = (string)$a['due'] === '' ? '9999-99-99' : (string)$a['due'];
                $db = (string)$b['due'] === '' ? '9999-99-99' : (string)$b['due'];
                return $da === $db ? ((int)$a['createdAt'] <=> (int)$b['createdAt']) : strcmp($da, $db);
            });
            return [
                'ok'     => true,
                'rev'    => (int)$store['rev'],
                'items'  => $items,
                'limits' => ['items' => HomeworkCalc::ITEMS_MAX, 'note' => HomeworkCalc::NOTE_MAX],
            ];
        }

        if (!in_array($action, ['create', 'update', 'done', 'delete'], true)) {
            return $this->HomeworkFehler('invalid_payload');
        }

        $lock = self::HW_LOCK . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 800)) {
            return $this->HomeworkFehler('busy');
        }
        try {
            return $this->HomeworkMutate($action, $body);
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    private function HomeworkMutate(string $action, array $body): array
    {
        $store = $this->HomeworkStore();
        $jetzt = time();
        $heute = date('Y-m-d', $jetzt);
        // Aufbewahrung greift beim Schreiben: hier ist der Ort, an dem der
        // gefilterte Stand auch wirklich gespeichert wird.
        $store['items'] = HomeworkCalc::Aufbewahrung($store['items'], $jetzt);

        if ($action === 'create') {
            if (count($store['items']) >= HomeworkCalc::ITEMS_MAX) {
                return $this->HomeworkFehler('quota_exceeded');
            }
            $kinder = $this->HomeworkKinder();
            if ($kinder === []) {
                return $this->HomeworkFehler('no_child');
            }
            $satz = HomeworkCalc::Normalisieren($body, $this->HomeworkFaecher(), $kinder, $heute, $jetzt);
            if ($satz === null) {
                // Ohne Kind oder ohne Fach ist es keine Hausaufgabe.
                return $this->HomeworkFehler(
                    in_array(trim((string)($body['childId'] ?? '')), $kinder, true) ? 'invalid_payload' : 'no_child'
                );
            }
            $satz['id'] = bin2hex(random_bytes(4));
            $satz['createdAt'] = $jetzt;
            $satz['updatedAt'] = $jetzt;
            $store['items'][] = $satz;
            if (!$this->HomeworkWriteStore($store)) {
                return $this->HomeworkFehler('store_unwritable');
            }
            return ['ok' => true, 'rev' => (int)$store['rev'] + 1, 'item' => $satz];
        }

        $id = trim((string)($body['id'] ?? ''));
        $pos = null;
        foreach ($store['items'] as $i => $satz) {
            if ((string)($satz['id'] ?? '') === $id && $id !== '') {
                $pos = $i;
                break;
            }
        }
        if ($pos === null) {
            return $this->HomeworkFehler('not_found');
        }

        if ($action === 'delete') {
            unset($store['items'][$pos]);
            if (!$this->HomeworkWriteStore($store)) {
                return $this->HomeworkFehler('store_unwritable');
            }
            return ['ok' => true, 'rev' => (int)$store['rev'] + 1];
        }

        $satz = $store['items'][$pos];
        if ($action === 'done') {
            $ziel = ($body['done'] ?? false) === true;
            /* Zielzustand, nicht umschalten: eine doppelt zugestellte Anfrage
               darf das Häkchen nicht zurücknehmen. Und wenn der Zustand schon
               stimmt, wird NICHT geschrieben — sonst zählt jede doppelte
               Zustellung die Revision hoch, schickt ein Push und lässt die
               Stundenplan-Kacheln neu zeichnen, ohne dass sich etwas geändert
               hat (live gemessen). */
            if (($satz['done'] ?? false) === $ziel) {
                return ['ok' => true, 'rev' => (int)$store['rev'], 'item' => $satz];
            }
            $satz['done'] = $ziel;
            $satz['doneAt'] = $ziel ? $jetzt : 0;
            /* Hier hat ein Mensch gehakt — ueber die App, die Kachel oder den
               Sprachdialog. Beim Zuruecknehmen faellt der Urheber weg, damit
               ein spaeteres Haekchen der Schule nicht als das eigene erscheint. */
            $satz['doneBy'] = $ziel ? HomeworkCalc::BY_USER : '';
        } else {
            if (array_key_exists('subject', $body)) {
                $fach = HomeworkCalc::FachAufloesen((string)$body['subject'], $this->HomeworkFaecher());
                if ($fach === '') {
                    return $this->HomeworkFehler('invalid_payload');
                }
                $satz['subject'] = $fach;
            }
            if (array_key_exists('due', $body)) {
                $due = trim((string)$body['due']);
                if ($due !== '' && !HomeworkCalc::DatumImFenster($due, $heute)) {
                    return $this->HomeworkFehler('invalid_payload');
                }
                $satz['due'] = $due;
            }
            if (array_key_exists('note', $body)) {
                $satz['note'] = mb_substr(trim((string)$body['note']), 0, HomeworkCalc::NOTE_MAX);
            }
        }
        $satz['updatedAt'] = $jetzt;
        $store['items'][$pos] = $satz;
        if (!$this->HomeworkWriteStore($store)) {
            return $this->HomeworkFehler('store_unwritable');
        }
        return ['ok' => true, 'rev' => (int)$store['rev'] + 1, 'item' => $satz];
    }

    /**
     * Einen Abruf einer fremden Quelle einrechnen (heute: WebUntis).
     *
     * Absichtlich KEINE Präfix-Funktion: der Aufrufer sitzt im selben Gateway
     * (Trait WebUntis), und eine neue öffentliche PREFIX_-Funktion würde einen
     * Kernel-Neustart verlangen, bevor der Abruf überhaupt anläuft.
     *
     * Geschrieben wird nur, wenn sich wirklich etwas geändert hat. Sonst hebt
     * jeder stündliche Abruf die Revision, schickt allen Geräten ein Signal und
     * lässt die Stundenplan-Kacheln neu zeichnen — für nichts.
     *
     * @param list<array> $roh Einträge der Quelle, je Eintrag mindestens
     *        `srcId`, `subject`, `due`; dazu `note` und `done`
     * @return array{ok:bool,neu:int,geaendert:int,entfernt:int,uebergangen:int,fehler:string}
     */
    private function HomeworkImportieren(string $kind, array $roh, string $von, string $bis, string $quelle = 'untis'): array
    {
        $leer = ['ok' => false, 'neu' => 0, 'geaendert' => 0, 'entfernt' => 0, 'uebergangen' => 0, 'fehler' => ''];
        $kind = trim($kind);
        if ($kind === '') {
            return array_merge($leer, ['fehler' => 'no_child']);
        }
        $kinder = $this->HomeworkKinder();
        if (!in_array($kind, $kinder, true)) {
            /* Das Mitglied ist nicht (mehr) als Kind geführt. Kein Grund für
               einen Fehler im Log — aber auch kein Grund, irgendwo Aufgaben
               anzulegen. */
            return array_merge($leer, ['fehler' => 'no_child']);
        }

        $jetzt = time();
        $heute = date('Y-m-d', $jetzt);
        $faecher = $this->HomeworkFaecher();
        $saetze = [];
        $uebergangen = 0;
        foreach ($roh as $r) {
            if (!is_array($r) || (int)($r['srcId'] ?? 0) <= 0) {
                $uebergangen++;
                continue;
            }
            $r['childId'] = $kind;
            $r['source'] = $quelle;
            /* Ein Haekchen, das mit dem Abruf HEREINKOMMT, gehoert der Schule.
               Ohne diese Zeile gaelte es beim ersten Abruf als hier gesetzt
               (Normalisieren nimmt „hier" als Rueckfall), und die Oberflaeche
               faerbte es falsch. */
            $r['doneBy'] = HomeworkCalc::BY_UNTIS;
            $satz = HomeworkCalc::Normalisieren($r, $faecher, $kinder, $heute, $jetzt);
            if ($satz === null) {
                // Ohne Fach oder mit unmöglicher Fälligkeit: übergehen, nicht
                // raten. Die Zahl steht in der Statuszeile.
                $uebergangen++;
                continue;
            }
            $saetze[] = $satz;
        }

        $lock = self::HW_LOCK . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 3000)) {
            return array_merge($leer, ['fehler' => 'busy', 'uebergangen' => $uebergangen]);
        }
        try {
            $store = $this->HomeworkStore();
            $store['items'] = HomeworkCalc::Aufbewahrung($store['items'], $jetzt);
            $e = HomeworkCalc::Zusammenfuehren($store['items'], $saetze, $kind, $von, $bis, $jetzt);
            if ($e['neu'] === 0 && $e['geaendert'] === 0 && $e['entfernt'] === 0) {
                return ['ok' => true, 'neu' => 0, 'geaendert' => 0, 'entfernt' => 0,
                        'uebergangen' => $uebergangen, 'fehler' => ''];
            }
            $store['items'] = $e['items'];
            if (!$this->HomeworkWriteStore($store)) {
                return array_merge($leer, ['fehler' => 'store_unwritable', 'uebergangen' => $uebergangen]);
            }
            return ['ok' => true, 'neu' => (int)$e['neu'], 'geaendert' => (int)$e['geaendert'],
                    'entfernt' => (int)$e['entfernt'], 'uebergangen' => $uebergangen, 'fehler' => ''];
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }
}

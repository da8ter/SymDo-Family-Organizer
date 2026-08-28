<?php

declare(strict_types=1);

/**
 * Notizen — Ordner mit Notizen aus Titel, Text und Anhaengen.
 *
 * Bewusst KEINE eigene Listeninstanz, sondern ein Bestand des Gateways nach dem
 * Muster von Kalender, Mail-Vorschlaegen und Briefing: ein Attribut, EIN
 * POST-Endpunkt mit `action` im Rumpf, ein Zweig im Kachel-Relay. Zwei Gruende:
 *
 *  1. Ein Ordner je Familienmitglied ist keine Instanz. Mitglieder sind eine
 *     Property-Liste auf dem Gateway; eine Instanz je Ordner hiesse, sie von Hand
 *     anzulegen und aufzuraeumen.
 *  2. Eine neue Listenart in /v1/discovery zerlegt die ausgelieferte iOS-App:
 *     ihr ListKind kennt nur shopping und todo und ist nicht optional — ein
 *     unbekannter Wert laesst die GANZE Antwort scheitern, also auch Listen und
 *     Mitglieder. Notizen erscheinen deshalb NICHT in discovery.instances.
 *
 * Ablageform im Attribut NotesStore:
 *
 *   { "v":1, "rev":37,
 *     "seen":    ["a1b2c3d4"],                       // Mitglieder, die je einen Ordner bekamen
 *     "folders": [{"id","name","memberId","createdAt","updatedAt"}],
 *     "notes":   [{"id","folderId","title","text","att":[…],"createdAt","updatedAt","source"}] }
 *
 * EIN Attribut, nicht mehrere — wegen der Atomizitaet, nicht wegen der Groesse:
 * Ein Ordnerloeschen aendert folders, notes und ggf. seen in einem Zug. Zwei
 * Attribute waeren zwei Schreibvorgaenge, und der zweite kann still scheitern
 * (Attribut vor dem Kernel-Neustart) — dann haette man Notizen ohne Ordner.
 */
trait Notes
{
    /** Harte Notbremse fuer das Attribut. Bei Freitext zaehlt man Bytes, nicht Datensaetze. */
    // Mit NOTE_TEXT_MAX = 3000 und NOTES_MAX = 200 liegt der schlimmste Fall bei
    // etwa 600 kB Text plus Titel und Anhangs-Angaben. Der Deckel muss darueber
    // liegen, sonst scheitert das Schreiben mit „store_unwritable" — und dessen
    // Meldung schickt auf die falsche Faehrte (Kernel-Neustart).
    private const NOTES_STORE_MAX = 786432;

    private const NOTES_MAX        = 200;
    private const NOTE_TEXT_MAX    = 3000;
    private const NOTE_TITLE_MAX   = 120;
    private const NOTES_FOLDERS_MAX = 40;
    private const NOTE_FOLDER_NAME_MAX = 60;
    private const NOTE_ATTACH_MAX  = 5;
    /** Laenge der Vorschau in der Uebersicht — der volle Text kommt erst mit `get`. */
    private const NOTE_PREVIEW_MAX = 160;
    /** Monoton wachsend; nur eine Notbremse gegen ein durchgedrehtes Formular. */
    private const NOTES_SEEN_MAX   = 500;

    private const NOTES_ATTR = 'NotesStore';
    private const NOTES_LOCK = 'TGW_Notes_';

    private function NotesCreate(): void
    {
        $this->RegisterAttributeString(self::NOTES_ATTR, '');
        $this->NotesMediaCreate();
    }

    /**
     * Mitglieder-Ordner nachziehen. Idempotent, ruft NIE IPS_ApplyChanges — das
     * tut EnsureUserIDs schon, ein zweiter Aufruf waere eine Rekursion.
     */
    private function NotesApplyChanges(): void
    {
        $this->NotesEnsureMemberFolders();
        // Kein eigener Timer: ein neu registrierter existiert vor dem
        // Kernel-Neustart nicht und warnt bei jedem ApplyChanges in die Ausgabe.
        // Der Durchlauf ist billig (ein Blick in eine Kategorie) und laeuft
        // deshalb einfach hier mit — bei jedem Kernelstart und jedem Uebernehmen.
        $this->NotesSweepOrphans();
    }

    // ── Ablage ───────────────────────────────────────────────────────────────

    /** @return array{v:int,rev:int,seen:array,folders:array,notes:array} */
    private function NotesStore(): array
    {
        $leer = ['v' => 1, 'rev' => 0, 'seen' => [], 'folders' => [], 'notes' => []];
        $d = json_decode($this->ReadAttributeStringSafe(self::NOTES_ATTR, ''), true);
        if (!is_array($d)) {
            return $leer;
        }
        foreach (['seen', 'folders', 'notes'] as $k) {
            if (!isset($d[$k]) || !is_array($d[$k])) {
                $d[$k] = [];
            }
        }
        $d['v']   = (int)($d['v'] ?? 1);
        $d['rev'] = (int)($d['rev'] ?? 0);
        return $d;
    }

    /**
     * Schreiben mit Rücklese-Probe. Ein Attribut, das der Kernel noch nicht kennt,
     * schluckt still — und die PHP-Warnung dazu landet in der AUSGABE, was im Hook
     * die HTTP-Antwort zerlegt. Deshalb Klammeraffe UND Gegenprobe.
     *
     * Nicht kürzen, sondern ablehnen: eine still gekappte Notiz ist schlimmer als
     * eine abgelehnte. (CalWriteStore kürzt still — dort sind es Erinnerungen, hier
     * ist es Text, den ein Mensch geschrieben hat.)
     */
    private function NotesWriteStore(array $store): bool
    {
        $store['rev'] = (int)$store['rev'] + 1;
        $json = json_encode($store, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return false;
        }
        if (strlen($json) > self::NOTES_STORE_MAX) {
            return false;
        }
        @$this->WriteAttributeString(self::NOTES_ATTR, $json);
        if ($this->ReadAttributeStringSafe(self::NOTES_ATTR, '') === $json) {
            /* Die EINZIGE Schreibstelle des Bestands — also der richtige Ort fuer
               das Signal. Ohne es sah ein anderes Geraet eine neue Notiz erst
               nach dem Neuladen der App: die Notizen haengen an keiner
               Listen-Revision und kamen ueber den Revisionsabgleich nie mit. */
            $this->WsPushDirty();
            return true;
        }
        $this->LogMessage(
            'SymDo: Attribut ' . self::NOTES_ATTR . ' ist nicht speicherbar — der Symcon-Kernel '
            . 'muss nach dem Modul-Update einmal neu gestartet werden.',
            KL_ERROR
        );
        return false;
    }

    /**
     * Ist der Bestand ueberhaupt benutzbar?
     *
     * WriteAttributeString wirft nicht, es tut vor dem Kernel-Neustart einfach
     * nichts — und ReadAttributeString liefert dann false. Beides sieht von aussen
     * wie „leer" aus. Diese Probe unterscheidet es, ohne rev zu bewegen: den
     * aktuellen Wert zurueckschreiben und gegenlesen.
     */
    private function NotesStorable(): bool
    {
        // Es MUSS ein anderer Wert geschrieben werden als der vorhandene: Schreibt man
        // denselben zurueck, sieht ein wirkungsloses Schreiben genauso aus wie ein
        // erfolgreiches. Der Probewert bleibt gueltiges JSON, damit ein gleichzeitiger
        // Leser nichts Kaputtes sieht, und der alte Stand wird sofort wiederhergestellt.
        $store = $this->NotesStore();
        $alt   = (string)json_encode($store, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $probe = (string)json_encode($store + ['probe' => uniqid('', true)],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        @$this->WriteAttributeString(self::NOTES_ATTR, $probe);
        $ok = $this->ReadAttributeStringSafe(self::NOTES_ATTR, '') === $probe;
        if ($ok) {
            @$this->WriteAttributeString(self::NOTES_ATTR, $alt);
        }
        return $ok;
    }

    private function NotesNewId(): string
    {
        return bin2hex(random_bytes(4));
    }

    // ── Mitglieder-Ordner ────────────────────────────────────────────────────

    /**
     * Für jedes Mitglied wird HÖCHSTENS EINMAL, jemals, ein Ordner angelegt.
     *
     * Der Merkposten `seen` und der Ordner entstehen im SELBEN Schreibvorgang. Damit
     * kann ein gelöschter Mitglieder-Ordner nicht wiederkehren — auch dann nicht,
     * wenn beim Löschen das Schreiben scheitert. Eine getrennte Liste „bewusst
     * gelöscht" hätte genau diese Lücke: Ordner weg, Merkposten nicht geschrieben,
     * Ordner beim nächsten Aufruf wieder da.
     */
    private function NotesEnsureMemberFolders(): bool
    {
        // Unter DERSELBEN Sperre wie die Mutationen: diese Methode liest den Bestand
        // und schreibt ihn zurueck, und sie laeuft auf Lesewegen (GET /v1/notes ->
        // action 'list') sowie beim ApplyChanges. Ohne Sperre kann ihr Rueckschreiben
        // eine gleichzeitig gespeicherte Notiz verschlucken (verlorenes Update).
        // Bekommt sie die Sperre nicht, wird NICHT gewartet: das Nachziehen ist eine
        // Heilung, die beim naechsten Aufruf ohnehin wieder ansteht.
        $lock = self::NOTES_LOCK . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 300)) {
            return true;
        }
        try {
            return $this->NotesEnsureMemberFoldersLocked();
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    /** Nur aufrufen, wenn die Notizen-Sperre gehalten wird. */
    private function NotesEnsureMemberFoldersLocked(): bool
    {
        $store = $this->NotesStore();
        $vorher = count($store['folders']);
        $seen = array_flip(array_map('strval', $store['seen']));
        $jetzt = time();
        foreach ($this->LoadUsers() as $u) {
            $id = (string)$u['id'];
            if (isset($seen[$id])) {
                continue;
            }
            if (count($store['folders']) >= self::NOTES_FOLDERS_MAX) {
                // NICHT in seen eintragen: sonst bekäme das Mitglied nie mehr einen
                // Ordner, auch wenn später Platz frei wird.
                $this->LogMessage(
                    'SymDo Notizen: Ordnergrenze erreicht, für "' . $u['name'] . '" wurde keiner angelegt.',
                    KL_NOTIFY
                );
                break;
            }
            if (count($store['seen']) < self::NOTES_SEEN_MAX) {
                $store['seen'][] = $id;
                $seen[$id] = true;
            }
            $store['folders'][] = [
                'id'        => $this->NotesNewId(),
                'name'      => (string)$u['name'],
                'memberId'  => $id,
                'createdAt' => $jetzt,
                'updatedAt' => $jetzt,
            ];
        }
        if (count($store['folders']) === $vorher) {
            return true;
        }
        return $this->NotesWriteStore($store);
    }

    /**
     * Ordner für die Antwort aufbereiten.
     *
     * `memberId` bleibt für immer im Datensatz stehen und wird NIE ausgetragen —
     * die Zuordnung wird erst hier gegen die lebenden Mitglieder aufgelöst. Ein
     * Diff beim ApplyChanges wäre eine Falle: LoadUsers liefert [] , wenn
     * IPS_GetProperty nichts Brauchbares gibt, und würde dann ALLEN Ordnern ihr
     * Mitglied nehmen — still und unumkehrbar.
     */
    private function NotesFolderRows(array $store): array
    {
        $lebend = [];
        foreach ($this->LoadUsers() as $u) {
            $lebend[(string)$u['id']] = $u;
        }
        $zahl = [];
        foreach ($store['notes'] as $n) {
            $f = (string)($n['folderId'] ?? '');
            $zahl[$f] = ($zahl[$f] ?? 0) + 1;
        }
        $rows = [];
        foreach ($store['folders'] as $f) {
            $mid = (string)($f['memberId'] ?? '');
            $u = $lebend[$mid] ?? null;
            $rows[] = [
                'id'         => (string)$f['id'],
                'name'       => (string)($f['name'] ?? ''),
                // Nur wenn das Mitglied noch existiert. Sonst ist der Ordner ein
                // gewöhnlicher: kein Foto, frei umbenennbar, löschbar.
                'memberId'   => $u ? $mid : '',
                'hasAvatar'  => $u ? (bool)$u['hasAvatar'] : false,
                'count'      => (int)($zahl[(string)$f['id']] ?? 0),
                'updatedAt'  => (int)($f['updatedAt'] ?? 0),
            ];
        }
        usort($rows, static function (array $a, array $b): int {
            // Mitglieder-Ordner zuerst, dann alphabetisch — die Familie steht oben.
            if (($a['memberId'] !== '') !== ($b['memberId'] !== '')) {
                return $a['memberId'] !== '' ? -1 : 1;
            }
            return strcasecmp($a['name'], $b['name']);
        });
        return $rows;
    }

    private function NotesRow(array $n, bool $mitText): array
    {
        $text = (string)($n['text'] ?? '');
        $row = [
            'id'        => (string)$n['id'],
            'folderId'  => (string)($n['folderId'] ?? ''),
            'title'     => (string)($n['title'] ?? ''),
            'att'       => array_values(array_map(static function (array $a): array {
                return [
                    'id'    => (int)($a['id'] ?? 0),
                    'kind'  => (string)($a['kind'] ?? ''),
                    'name'  => (string)($a['name'] ?? ''),
                    'bytes' => (int)($a['bytes'] ?? 0),
                ];
            }, is_array($n['att'] ?? null) ? $n['att'] : [])),
            'updatedAt' => (int)($n['updatedAt'] ?? 0),
            'source'    => (string)($n['source'] ?? 'manual'),
        ];
        if ($mitText) {
            $row['text'] = $text;
        } else {
            $row['preview'] = mb_substr(preg_replace('/\s+/u', ' ', $text) ?? '', 0, self::NOTE_PREVIEW_MAX);
        }
        return $row;
    }

    // ── Verteiler ────────────────────────────────────────────────────────────

    private function NotesFehler(string $code): array
    {
        return ['ok' => false, 'error' => ['code' => $code]];
    }

    /**
     * Ein Endpunkt, Aktion im Rumpf. Grund wie beim Kalender: die Visu-Kachel kann
     * nur POSTs auf EINEN Pfad relayen, und so trifft dieselbe Anfrage Browser,
     * App und Kachel. HTTP ist immer 200, der Fehler steht im Rumpf.
     *
     * @param array<string,mixed>|null $device Gerätedatensatz (REST) oder null (Relay)
     */
    private function NotesHandleAction(array $body, ?array $device = null): array
    {
        $action = (string)($body['action'] ?? '');
        if ($action === '' || $action === 'list') {
            // Faul nachziehen: heilt den Fall, in dem das Anlegen beim ApplyChanges
            // still scheiterte (Attribut noch nicht bekannt).
            $this->NotesEnsureMemberFolders();
            $store = $this->NotesStore();
            $notes = [];
            foreach ($store['notes'] as $n) {
                $notes[] = $this->NotesRow($n, false);
            }
            usort($notes, static fn(array $a, array $b): int => $b['updatedAt'] <=> $a['updatedAt']);
            $ordner = $this->NotesFolderRows($store);
            // Aus den AUFGELÖSTEN Zeilen, nicht aus dem Rohbestand: dort steht die
            // memberId auch dann noch, wenn das Mitglied gelöscht wurde (sie bleibt
            // als Herkunftsangabe stehen). Die Karte würde sonst einen Ordner für
            // ein Mitglied nennen, das es nicht mehr gibt.
            $mitglieder = [];
            foreach ($ordner as $f) {
                if ($f['memberId'] !== '') {
                    $mitglieder[$f['memberId']] = $f['id'];
                }
            }
            return [
                'ok'      => true,
                'rev'     => (int)$store['rev'],
                'folders' => $ordner,
                'notes'   => $notes,
                // Als OBJEKT, auch wenn leer: json_encode macht aus einem leeren
                // PHP-Array `[]`, und der Vertrag sagt „Karte userId → folderId".
                'memberFolders' => (object)$mitglieder,
                'limits'  => [
                    'notes'      => self::NOTES_MAX,
                    'text'       => self::NOTE_TEXT_MAX,
                    'title'      => self::NOTE_TITLE_MAX,
                    'folders'    => self::NOTES_FOLDERS_MAX,
                    'attach'     => self::NOTE_ATTACH_MAX,
                ],
            ];
        }

        if ($action === 'attachData') {
            return $this->NotesAttachData((string)($body['noteId'] ?? ''),
                (int)($body['attachmentId'] ?? 0), ($body['meta'] ?? false) === true);
        }

        if ($action === 'get') {
            $store = $this->NotesStore();
            $i = $this->NotesIndexOf($store['notes'], (string)($body['id'] ?? ''));
            if ($i < 0) {
                return $this->NotesFehler('not_found');
            }
            return ['ok' => true, 'rev' => (int)$store['rev'], 'note' => $this->NotesRow($store['notes'][$i], true)];
        }

        // Alles Weitere ändert etwas — unter Semaphore, und im Zweifel ABLEHNEN.
        // (ReserveAction führt im Zweifel aus; das ist für eine Dedup-Tabelle
        // richtig und für Nutzertext falsch, dort wäre es ein verlorener Schreibvorgang.)
        $lock = self::NOTES_LOCK . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 800)) {
            return $this->NotesFehler('busy');
        }
        try {
            return $this->NotesMutate($action, $body, $device);
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    private function NotesIndexOf(array $rows, string $id): int
    {
        if ($id === '') {
            return -1;
        }
        foreach ($rows as $i => $r) {
            if ((string)($r['id'] ?? '') === $id) {
                return (int)$i;
            }
        }
        return -1;
    }

    private function NotesMutate(string $action, array $body, ?array $device): array
    {
        $store = $this->NotesStore();
        $jetzt = time();

        switch ($action) {
            case 'folderCreate':
                $name = $this->NotesTrim((string)($body['name'] ?? ''), self::NOTE_FOLDER_NAME_MAX);
                if ($name === '') {
                    return $this->NotesFehler('invalid_payload');
                }
                if (count($store['folders']) >= self::NOTES_FOLDERS_MAX) {
                    return $this->NotesFehler('quota_exceeded');
                }
                $ordner = ['id' => $this->NotesNewId(), 'name' => $name, 'memberId' => '',
                           'createdAt' => $jetzt, 'updatedAt' => $jetzt];
                $store['folders'][] = $ordner;
                return $this->NotesWriteStore($store)
                    ? ['ok' => true, 'rev' => (int)$store['rev'] + 1, 'folder' => $ordner]
                    : $this->NotesFehler('store_unwritable');

            case 'folderRename':
                $i = $this->NotesIndexOf($store['folders'], (string)($body['id'] ?? ''));
                $name = $this->NotesTrim((string)($body['name'] ?? ''), self::NOTE_FOLDER_NAME_MAX);
                if ($i < 0) {
                    return $this->NotesFehler('not_found');
                }
                if ($name === '') {
                    return $this->NotesFehler('invalid_payload');
                }
                $store['folders'][$i]['name'] = $name;
                $store['folders'][$i]['updatedAt'] = $jetzt;
                return $this->NotesWriteStore($store)
                    ? ['ok' => true, 'rev' => (int)$store['rev'] + 1]
                    : $this->NotesFehler('store_unwritable');

            case 'folderDelete':
                return $this->NotesFolderDelete($store, $body, $jetzt);

            case 'noteCreate':
                return $this->NotesNoteCreate($store, $body, $jetzt);

            case 'noteUpdate':
                return $this->NotesNoteUpdate($store, $body, $jetzt);

            case 'noteDelete':
                $i = $this->NotesIndexOf($store['notes'], (string)($body['id'] ?? ''));
                if ($i < 0) {
                    return $this->NotesFehler('not_found');
                }
                $medien = $this->NotesAttachmentIds([$store['notes'][$i]]);
                array_splice($store['notes'], $i, 1);
                if (!$this->NotesWriteStore($store)) {
                    return $this->NotesFehler('store_unwritable');
                }
                // ERST der Store, DANN die Medien: umgekehrt hätte ein gescheiterter
                // Schreibvorgang Notizen mit toten Anhängen hinterlassen. Und nur, was
                // keine andere Notiz und kein offener Vorschlag mehr nennt — eine Mail
                // gibt allen ihren Notiz-Funden dieselbe Anhangsliste mit.
                $this->NotesDeleteMedia($this->NotesUnreferencedMedia($store, $medien));
                return ['ok' => true, 'rev' => (int)$store['rev'] + 1];

            case 'attachUpload':
                return $this->NotesAttachUpload($store, $body, $jetzt);

            case 'attachDelete':
                return $this->NotesAttachDelete($store, $body, $jetzt);

            case 'analyse':
                return $this->NotesAnalyse($body, $device);

            case 'adopt':
                return $this->NotesAdopt($store, $body, $jetzt);
        }
        return $this->NotesFehler('invalid_payload');
    }

    private function NotesTrim(string $wert, int $max): string
    {
        /* Der Trichter fuer alles, was aus fremder Hand in den Bestand geht:
           Titel, Ordnernamen, Anhangsnamen. Ein Anhangsname stammt aus einer Mail
           und ist nicht immer UTF-8 — mb_substr macht daraus Zeichensalat, und
           Symcon 9.1 weist ungueltiges UTF-8 beim Speichern rundweg zurueck
           („Value is not encoded as valid UTF-8"). Deshalb hier, an EINER Stelle:
           gueltiges UTF-8 bleibt unangetastet, alles andere wird als ISO-8859-1
           gelesen — das kann nicht scheitern. */
        if (!mb_check_encoding($wert, 'UTF-8')) {
            $wert = (string)mb_convert_encoding($wert, 'UTF-8', 'ISO-8859-1');
        }
        return mb_substr(trim($wert), 0, $max);
    }

    private function NotesFolderDelete(array $store, array $body, int $jetzt): array
    {
        $i = $this->NotesIndexOf($store['folders'], (string)($body['id'] ?? ''));
        if ($i < 0) {
            return $this->NotesFehler('not_found');
        }
        // `mode` ist Pflicht. Eine Vorgabe, die Notizen mitnimmt, wäre ein
        // Datenverlust-Automat.
        $mode = (string)($body['mode'] ?? '');
        $fid = (string)$store['folders'][$i]['id'];
        if ($mode === 'move') {
            $ziel = (string)($body['targetId'] ?? '');
            if ($ziel === $fid || $this->NotesIndexOf($store['folders'], $ziel) < 0) {
                return $this->NotesFehler('invalid_payload');
            }
            foreach ($store['notes'] as $k => $n) {
                if ((string)($n['folderId'] ?? '') === $fid) {
                    $store['notes'][$k]['folderId'] = $ziel;
                    $store['notes'][$k]['updatedAt'] = $jetzt;
                }
            }
            $medien = [];
        } elseif ($mode === 'notes') {
            $bleibt = [];
            $weg = [];
            foreach ($store['notes'] as $n) {
                if ((string)($n['folderId'] ?? '') === $fid) {
                    $weg[] = $n;
                } else {
                    $bleibt[] = $n;
                }
            }
            $store['notes'] = $bleibt;
            $medien = $this->NotesAttachmentIds($weg);
        } else {
            return $this->NotesFehler('invalid_payload');
        }
        array_splice($store['folders'], $i, 1);
        if (!$this->NotesWriteStore($store)) {
            return $this->NotesFehler('store_unwritable');
        }
        $frei = $this->NotesUnreferencedMedia($store, $medien);
        $this->NotesDeleteMedia($frei);
        return ['ok' => true, 'rev' => (int)$store['rev'] + 1, 'removedMedia' => count($frei)];
    }

    private function NotesNoteCreate(array $store, array $body, int $jetzt): array
    {
        $fid = (string)($body['folderId'] ?? '');
        if ($this->NotesIndexOf($store['folders'], $fid) < 0) {
            return $this->NotesFehler('not_found');
        }
        if (count($store['notes']) >= self::NOTES_MAX) {
            return $this->NotesFehler('quota_exceeded');
        }
        $titel = $this->NotesTrim((string)($body['title'] ?? ''), self::NOTE_TITLE_MAX);
        $text  = trim((string)($body['text'] ?? ''));
        if ($titel === '' && $text === '') {
            return $this->NotesFehler('invalid_payload');
        }
        if (mb_strlen($text) > self::NOTE_TEXT_MAX || mb_strlen((string)($body['title'] ?? '')) > self::NOTE_TITLE_MAX) {
            return $this->NotesFehler('invalid_payload');
        }
        $notiz = [
            'id' => $this->NotesNewId(), 'folderId' => $fid,
            'title' => $titel !== '' ? $titel : mb_substr($text, 0, 40),
            'text' => $text, 'att' => [],
            'createdAt' => $jetzt, 'updatedAt' => $jetzt,
            'source' => in_array((string)($body['source'] ?? ''), ['mail', 'ai'], true) ? (string)$body['source'] : 'manual',
        ];
        $store['notes'][] = $notiz;
        return $this->NotesWriteStore($store)
            ? ['ok' => true, 'rev' => (int)$store['rev'] + 1, 'note' => $this->NotesRow($notiz, true)]
            : $this->NotesFehler('store_unwritable');
    }

    private function NotesNoteUpdate(array $store, array $body, int $jetzt): array
    {
        $i = $this->NotesIndexOf($store['notes'], (string)($body['id'] ?? ''));
        if ($i < 0) {
            return $this->NotesFehler('not_found');
        }
        if (array_key_exists('title', $body)) {
            if (mb_strlen((string)$body['title']) > self::NOTE_TITLE_MAX) {
                return $this->NotesFehler('invalid_payload');
            }
            $store['notes'][$i]['title'] = $this->NotesTrim((string)$body['title'], self::NOTE_TITLE_MAX);
        }
        if (array_key_exists('text', $body)) {
            if (mb_strlen((string)$body['text']) > self::NOTE_TEXT_MAX) {
                return $this->NotesFehler('invalid_payload');
            }
            $store['notes'][$i]['text'] = trim((string)$body['text']);
        }
        if (array_key_exists('folderId', $body)) {
            $fid = (string)$body['folderId'];
            if ($this->NotesIndexOf($store['folders'], $fid) < 0) {
                return $this->NotesFehler('not_found');
            }
            $store['notes'][$i]['folderId'] = $fid;
        }
        $store['notes'][$i]['updatedAt'] = $jetzt;
        return $this->NotesWriteStore($store)
            ? ['ok' => true, 'rev' => (int)$store['rev'] + 1, 'note' => $this->NotesRow($store['notes'][$i], true)]
            : $this->NotesFehler('store_unwritable');
    }
}

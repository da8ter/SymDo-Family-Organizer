<?php

declare(strict_types=1);

require_once __DIR__ . '/EduStoreCalc.php';

/**
 * Klassenseiten — der eigene Bestand, der Endpunkt und der Umzug.
 *
 * Bis heute lagen die Karten der Klassenseite als NOTIZEN im Notizen-Bestand:
 * zwei Ordnerebenen, 55 von 64 Notizen, neun Felder, die nur der Spiegel
 * schreibt, und die Sperrliste gelöschter Seiten. Das hatte drei Folgen — der
 * Notizbereich zeigte fast nur Fremdes, die Notizen trugen Code, der nur für
 * Klassenseiten da war, und die Klassenseiten hatten keinen Ort, an dem sie für
 * sich stehen konnten.
 *
 * Getrennt wird nur der BESTAND. Abruf, Kartenzerlegung, KI-Auswertung, Push und
 * der gemeinsame Tagesdeckel bleiben in EduMaps.php, und die Werkzeuge der
 * Notizen (Medienobjekte anlegen, Kennungen kürzen) werden weiter benutzt: alle
 * Traits leben in DERSELBEN Klasse, eine Kopie wäre eine zweite Wahrheit.
 *
 * Ablageform im Attribut EduStore:
 *
 *   { "v":1, "rev":12, "migratedAt":1789000000,
 *     "folders":[{"id","name","parentId","memberId","eduKey","createdAt","updatedAt"}],
 *     "notes":  [{"id","folderId","title","text","html","att",…,"srcId","srcRev"}],
 *     "blocked":[{"key","url","name","at"}] }
 *
 * Zwei Ebenen: Mitglied → Seite. Die Zwischenebene „Edumaps" von früher entfällt
 * — sie war nur nötig, weil die Karten in fremdem Haus lagen.
 *
 * `blocked` ist die Sperrliste der von Hand gelöschten Seiten. Sie liegt hier
 * und nicht in einem eigenen Attribut, weil Löschen und Sperren in DENSELBEN
 * Schreibvorgang gehören — sonst gibt es das Fenster „Ordner weg, Sperre nicht
 * geschrieben, Ordner beim nächsten Lauf wieder da". Dieselbe Begründung stand
 * vorher in Notes.php.
 *
 * SPERR-ORDNUNG, einmal festgelegt und überall gleich: erst die Notizen-Sperre,
 * dann die der Klassenseiten. Wer beide braucht (Umzug, Medien-Aufräumer), nimmt
 * sie in dieser Reihenfolge; wer nur eine braucht, nimmt nur seine.
 *
 * Alles Rechnende liegt in EduStoreCalc — dieser Trait hält den Bestand, die
 * Sperre und den Verteiler.
 */
trait EduStore
{
    private const EDU_ATTR = 'EduStore';
    private const EDU_LOCK = 'TGW_Edu_';

    private function EduStoreCreate(): void
    {
        $this->RegisterAttributeString(self::EDU_ATTR, '');
    }

    // ── Ablage ───────────────────────────────────────────────────────────────

    private function EduStoreRead(): array
    {
        return EduStoreCalc::Formen(json_decode($this->ReadAttributeStringSafe(self::EDU_ATTR, ''), true));
    }

    /**
     * Der einzige Schreiber. Mit Rücklese-Probe: ein Attribut, das der Kernel
     * noch nicht kennt, schluckt still — und die PHP-Warnung dazu landet in der
     * AUSGABE, was im Hook die HTTP-Antwort zerlegt (dieselbe Begründung wie in
     * Notes.php).
     *
     * Nicht kürzen, sondern ablehnen: eine still gekappte Karte wäre schlimmer
     * als eine abgelehnte Antwort.
     */
    private function EduWriteStore(array $store): bool
    {
        $store['rev'] = (int)$store['rev'] + 1;
        $store['folders'] = array_values($store['folders']);
        $store['notes']   = array_values($store['notes']);
        $store['blocked'] = array_values($store['blocked']);
        $json = json_encode($store, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || strlen($json) > EduStoreCalc::STORE_MAX) {
            return false;
        }
        @$this->WriteAttributeString(self::EDU_ATTR, $json);
        if ($this->ReadAttributeStringSafe(self::EDU_ATTR, '') === $json) {
            /* Die EINZIGE Schreibstelle — also der richtige Ort für das Signal.
               Ohne es sähe ein anderes Gerät eine neue Karte erst nach dem
               Neuladen: der Bestand hängt an keiner Listen-Revision. */
            $this->WsPushDirty();
            return true;
        }
        $this->LogMessage(
            'SymDo: Attribut ' . self::EDU_ATTR . ' ist nicht speicherbar — der Symcon-Kernel '
            . 'muss nach dem Modul-Update einmal neu gestartet werden.',
            KL_ERROR
        );
        return false;
    }

    /**
     * Ist der Bestand überhaupt benutzbar?
     *
     * WriteAttributeString wirft nicht, es tut vor dem Kernel-Neustart einfach
     * nichts — und ReadAttributeString liefert dann false. Beides sieht von
     * außen wie „leer" aus, und genau dieser Unterschied entscheidet darüber, ob
     * der Medien-Aufräumer die Anhänge der Klassenseiten für Waisen hält.
     */
    private function EduStorable(): bool
    {
        $store = $this->EduStoreRead();
        $alt   = (string)json_encode($store, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $probe = (string)json_encode($store + ['probe' => uniqid('', true)],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        @$this->WriteAttributeString(self::EDU_ATTR, $probe);
        $ok = $this->ReadAttributeStringSafe(self::EDU_ATTR, '') === $probe;
        if ($ok) {
            @$this->WriteAttributeString(self::EDU_ATTR, $alt);
        }
        return $ok;
    }

    /** Alle Medien-Kennungen, die die Karten belegen — Anhang UND Vorschaubild. */
    private function EduAttachmentIds(): array
    {
        return EduStoreCalc::AnhangIds($this->EduStoreRead()['notes']);
    }

    // ── Projektion ───────────────────────────────────────────────────────────

    /**
     * Ordner für die Antwort. `memberId` wird erst hier gegen die lebenden
     * Mitglieder aufgelöst — ein Abgleich beim ApplyChanges wäre eine Falle:
     * LoadUsers liefert [], wenn IPS_GetProperty nichts Brauchbares gibt, und
     * würde dann ALLEN Ordnern ihr Mitglied nehmen (so steht es auch in
     * Notes.php).
     */
    private function EduOrdnerZeilen(array $store): array
    {
        $lebend = [];
        foreach ($this->LoadUsers() as $u) {
            $lebend[(string)$u['id']] = $u;
        }
        $zahl = EduStoreCalc::Zaehlen($store);
        $rows = [];
        foreach ($store['folders'] as $f) {
            if (!is_array($f)) {
                continue;
            }
            $mid = (string)($f['memberId'] ?? '');
            $u = $lebend[$mid] ?? null;
            $eltern = (string)($f['parentId'] ?? '');
            $rows[] = [
                'id'        => (string)($f['id'] ?? ''),
                'name'      => (string)($f['name'] ?? ''),
                /* Zeigt der Verweis auf einen Ordner, den es nicht mehr gibt,
                   gilt der Ordner als oberste Ebene — sonst wäre er
                   unerreichbar. */
                'parentId'  => EduStoreCalc::StelleVon($store['folders'], $eltern) >= 0 ? $eltern : '',
                'memberId'  => $u ? $mid : '',
                'hasAvatar' => $u ? (bool)$u['hasAvatar'] : false,
                'count'     => (int)($zahl[(string)($f['id'] ?? '')] ?? 0),
                'updatedAt' => (int)($f['updatedAt'] ?? 0),
                /* Bleibt im Vertrag, damit gemeinsame Client-Funktionen dieselbe
                   Form sehen wie bei den Notizen. Hier ist alles eine
                   Klassenseite. */
                'source'    => 'edumaps',
            ];
        }
        usort($rows, static function (array $a, array $b): int {
            // Die Kinder zuerst, dann alphabetisch — die Familie steht oben.
            if (($a['memberId'] !== '') !== ($b['memberId'] !== '')) {
                return $a['memberId'] !== '' ? -1 : 1;
            }
            return strcasecmp($a['name'], $b['name']);
        });
        return $rows;
    }

    // ── Verteiler ────────────────────────────────────────────────────────────

    private function EduFehler(string $code): array
    {
        return ['ok' => false, 'error' => ['code' => $code]];
    }

    /**
     * Ein Endpunkt, Aktion im Rumpf — derselbe Draht-Vertrag wie /notes, damit
     * die geerbte Oberfläche ohne Umbau damit arbeitet. HTTP ist immer 200, der
     * Fehler steht im Rumpf.
     *
     * Es gibt WENIGER Aktionen als bei den Notizen, und das ist der Punkt: der
     * Bestand ist ein Spiegel. Anlegen, Hochladen und Auswerten fehlen — was
     * hier von Hand entstünde, wäre beim nächsten Lauf entweder weg oder
     * archiviert. Erlaubt sind: lesen, umbenennen (der Name gehört dem Nutzer,
     * wiedererkannt wird über eduKey), eine Seite löschen und damit sperren, und
     * eine ARCHIVIERTE Karte endgültig wegwerfen.
     *
     * Erscheint bewusst NICHT in /discovery: eine neue Listenart dort zerlegt
     * die ausgelieferte iOS-App (ihr ListKind kennt nur shopping und todo und
     * ist nicht optional) — dieselbe Rücksicht wie bei Notizen und Hausaufgaben.
     */
    private function EduHandleAction(array $body, ?array $device = null): array
    {
        $action = (string)($body['action'] ?? '');

        if ($action === '' || $action === 'list') {
            $store = $this->EduStoreRead();
            /* Volltext auf Wunsch, aber nur für EINEN Ordner: die Kartenansicht
               braucht ihn, die Übersicht nicht. Über alle Karten wäre die
               Antwort um ein Vielfaches größer, ohne dass jemand den Text
               liest. Gedeckelt, damit eine volle Seite die Antwort nicht
               sprengt — der Rest kommt als Vorschau. */
            $volltextOrdner = (string)($body['folderId'] ?? '');
            $mitText = ($body['withText'] ?? false) === true && $volltextOrdner !== '';
            $rest = EduStoreCalc::FULLTEXT_MAX;
            $karten = [];
            foreach ($store['notes'] as $n) {
                if (!is_array($n)) {
                    continue;
                }
                $voll = $mitText && (string)($n['folderId'] ?? '') === $volltextOrdner && $rest > 0;
                if ($voll) {
                    $rest--;
                }
                $karten[] = EduStoreCalc::KarteZeile($n, $voll);
            }
            usort($karten, static fn(array $a, array $b): int => $b['updatedAt'] <=> $a['updatedAt']);
            $ordner = $this->EduOrdnerZeilen($store);
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
                'notes'   => $karten,
                // Als OBJEKT, auch wenn leer: json_encode macht aus einem leeren
                // PHP-Array `[]`, und der Vertrag sagt „Karte userId → folderId".
                'memberFolders' => (object)$mitglieder,
                'limits'  => [
                    'notes'   => EduStoreCalc::KARTEN_MAX,
                    'text'    => EduStoreCalc::TEXT_MAX,
                    'title'   => EduStoreCalc::TITLE_MAX,
                    'folders' => EduStoreCalc::FOLDERS_MAX,
                    'attach'  => EduStoreCalc::ATTACH_MAX,
                ],
            ];
        }

        if ($action === 'get') {
            $store = $this->EduStoreRead();
            $i = EduStoreCalc::StelleVon($store['notes'], (string)($body['id'] ?? ''));
            if ($i < 0) {
                return $this->EduFehler('not_found');
            }
            return ['ok' => true, 'rev' => (int)$store['rev'],
                    'note' => EduStoreCalc::KarteZeile($store['notes'][$i], true)];
        }

        if ($action === 'attachData') {
            return $this->EduAttachData((string)($body['noteId'] ?? ''),
                (int)($body['attachmentId'] ?? 0), ($body['meta'] ?? false) === true);
        }

        if (!in_array($action, ['folderRename', 'folderDelete', 'noteDelete'], true)) {
            /* Eigener Code, keine Ausrede: „invalid_payload" schickte auf die
               Suche nach einem Tippfehler, obwohl die Aktion hier grundsätzlich
               nicht vorgesehen ist. */
            return $this->EduFehler('read_only');
        }

        $lock = self::EDU_LOCK . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 800)) {
            return $this->EduFehler('busy');
        }
        try {
            return $this->EduMutate($action, $body);
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    private function EduMutate(string $action, array $body): array
    {
        $store = $this->EduStoreRead();
        $jetzt = time();

        if ($action === 'folderRename') {
            $i = EduStoreCalc::StelleVon($store['folders'], (string)($body['id'] ?? ''));
            if ($i < 0) {
                return $this->EduFehler('not_found');
            }
            $name = EduStoreCalc::Kappen((string)($body['name'] ?? ''), EduStoreCalc::TITLE_MAX);
            if ($name === '') {
                return $this->EduFehler('invalid_payload');
            }
            $store['folders'][$i]['name'] = $name;
            $store['folders'][$i]['updatedAt'] = $jetzt;
            if (!$this->EduWriteStore($store)) {
                return $this->EduFehler('store_unwritable');
            }
            return ['ok' => true, 'rev' => (int)$store['rev'] + 1];
        }

        if ($action === 'noteDelete') {
            $i = EduStoreCalc::StelleVon($store['notes'], (string)($body['id'] ?? ''));
            if ($i < 0) {
                return $this->EduFehler('not_found');
            }
            /* Nur ARCHIVIERTE Karten: eine aktuelle wäre beim nächsten Lauf
               wieder da, und das Löschen sähe aus wie eine Geste ohne Wirkung.
               Im Archiv ist es die einzige Möglichkeit, etwas endgültig
               wegzuräumen. */
            if ((int)($store['notes'][$i]['archived'] ?? 0) <= 0) {
                return $this->EduFehler('not_archived');
            }
            $medien = EduStoreCalc::AnhangIds([$store['notes'][$i]]);
            array_splice($store['notes'], $i, 1);
            if (!$this->EduWriteStore($store)) {
                return $this->EduFehler('store_unwritable');
            }
            $frei = $this->NotesUnreferencedMedia($this->NotesStore(), $medien);
            $this->NotesDeleteMedia($frei);
            return ['ok' => true, 'rev' => (int)$store['rev'] + 1, 'removedMedia' => count($frei)];
        }

        // folderDelete
        $i = EduStoreCalc::StelleVon($store['folders'], (string)($body['id'] ?? ''));
        if ($i < 0) {
            return $this->EduFehler('not_found');
        }
        $fid = (string)$store['folders'][$i]['id'];
        foreach ($store['folders'] as $f) {
            if (is_array($f) && (string)($f['parentId'] ?? '') === $fid) {
                /* Mit Unterordnern wird NICHT gelöscht. Rekursiv wäre ein
                   Datenverlust-Automat, ein stilles Hochziehen der Kinder eine
                   Überraschung. */
                return $this->EduFehler('has_children');
            }
        }
        $eigene = [];
        $bleibt = [];
        foreach ($store['notes'] as $n) {
            if (is_array($n) && (string)($n['folderId'] ?? '') === $fid) {
                $eigene[] = $n;
            } else {
                $bleibt[] = $n;
            }
        }
        $store['notes'] = $bleibt;
        /* Eine Seite? Dann im SELBEN Schreibvorgang sperren. Ohne das legt der
           nächste Lauf den Ordner über denselben eduKey kommentarlos neu an —
           und seit der QR-Erkennung findet er die Seite sogar dann wieder, wenn
           sie nirgends mehr verlinkt ist. Löschen ohne Sperre wäre also eine
           Geste ohne Wirkung. */
        $eduKey = (string)($store['folders'][$i]['eduKey'] ?? '');
        $gesperrt = '';
        if (str_starts_with($eduKey, 'edupage:')) {
            $gesperrt = $this->EduSperren($store, $eduKey,
                (string)($store['folders'][$i]['name'] ?? ''),
                EduStoreCalc::SeitenUrl($eigene));
        }
        array_splice($store['folders'], $i, 1);
        if (!$this->EduWriteStore($store)) {
            return $this->EduFehler('store_unwritable');
        }
        $frei = $this->NotesUnreferencedMedia($this->NotesStore(), EduStoreCalc::AnhangIds($eigene));
        $this->NotesDeleteMedia($frei);
        return ['ok' => true, 'rev' => (int)$store['rev'] + 1,
                'removedMedia' => count($frei), 'blocked' => $gesperrt];
    }

    /**
     * Eine Klassenseite sperren. Nur den Bestand ändern, NICHT schreiben — der
     * Aufrufer schreibt gleich, und beides gehört in denselben Schreibvorgang.
     *
     * @return string der gesperrte Name (für die Rückmeldung), '' wenn schon gesperrt
     */
    private function EduSperren(array &$store, string $key, string $name, string $url): string
    {
        foreach ($store['blocked'] as $b) {
            if (is_array($b) && (string)($b['key'] ?? '') === $key) {
                return '';                      // steht schon drin
            }
        }
        $store['blocked'][] = [
            'key'  => $key,
            'url'  => $url,
            'name' => EduStoreCalc::Kappen($name !== '' ? $name : $url, EduStoreCalc::TITLE_MAX),
            'at'   => time(),
        ];
        /* Die Fundliste gleich miträumen: ein gesperrter Eintrag belegte dort
           sonst dauerhaft einen der EDU_GEFUNDEN_MAX Plätze. */
        $this->EduAusFundliste($url, $key);
        return $name;
    }

    /** Eine gesperrte Seite aus EduFound entfernen (siehe EduSperren). */
    private function EduAusFundliste(string $url, string $key): void
    {
        $roh = json_decode((string)@$this->ReadAttributeString('EduFound'), true);
        if (!is_array($roh) || $roh === []) {
            return;
        }
        $bleibt = array_values(array_filter($roh, static function ($e) use ($url, $key): bool {
            if (!is_array($e)) {
                return false;
            }
            $u = rtrim((string)($e['url'] ?? ''), '/');
            return !($u !== '' && ($u === $url || 'edupage:' . md5($u) === $key
                || 'edupage:' . md5((string)$e['url']) === $key));
        }));
        if (count($bleibt) !== count($roh)) {
            @$this->WriteAttributeString('EduFound', (string)json_encode($bleibt, JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * Eine Anhang-Datei als data:-URL — der Weg für die Kachel, die keinen Token
     * und damit keine Datei-Adresse hat. Dieselbe Prüfung wie bei den Notizen,
     * nur gegen diesen Bestand.
     */
    private function EduAttachData(string $karteId, int $mediaId, bool $nurArt = false): array
    {
        $store = $this->EduStoreRead();
        $i = EduStoreCalc::StelleVon($store['notes'], $karteId);
        if ($i < 0) {
            return $this->EduFehler('not_found');
        }
        if (!in_array($mediaId, EduStoreCalc::AnhangIds([$store['notes'][$i]]), true)) {
            return $this->EduFehler('forbidden');
        }
        return $this->NotesMediaAusgeben($mediaId, $nurArt);
    }
}

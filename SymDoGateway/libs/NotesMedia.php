<?php

declare(strict_types=1);

/**
 * Anhaenge der Notizen — Bilder und PDFs als Medienobjekte.
 *
 * Vorbild ist TtsCategoryID/TtsEvict, NICHT AiSaveMedia/AiReadMedia. Drei Gruende,
 * die alle drei in AiExtract als Fehler nachweisbar sind:
 *
 *  1. Die Kategorie „Rezeptfotos" haengt unter der SymDoWebApp-Instanz und ist ohne
 *     sie gar nicht da (AiRecipePhotoCategory liefert dann 0). Notizen sollen auch
 *     ohne Kachel-Instanz funktionieren, also haengt „Notizen" unter dem Gateway.
 *  2. AiReadMedia liest die Kategorie ROH aus dem Attribut statt sie aufzuloesen —
 *     vor dem ersten Speichern antwortet deshalb jeder Abruf `forbidden`. Hier
 *     loest das Lesen die Kategorie auf, ohne sie anzulegen.
 *  3. AiSaveMedia prueft nur die ANZAHL, nie die Groesse. Die Ausgabe einer Anfrage
 *     endet aber bei der Kernoption `ScriptOutputBufferLimit` (Vorgabe 1 MiB), und
 *     zwar in der Summe (Naeheres bei TtsOutputLimit() in Tts.php). Eine zu grosse
 *     Datei ist damit dauerhaft unabrufbar. Deshalb wird hier beim ABLEGEN
 *     begrenzt: Bilder werden skaliert, PDFs abgelehnt.
 *
 * Eine eigene Kategorie ist auch deshalb Pflicht, weil ShoppingList Medienobjekte
 * loescht, sobald ihr Vater eine Kategorie namens „Rezeptfotos" ist — modulfremd
 * und nur ueber den Namen.
 */
trait NotesMedia
{
    private const NOTES_CATEGORY_NAME = 'Notizen';
    private const NOTES_MEDIA_ATTR    = 'NotesMediaCategory';
    /** Eigene Kategorie, eigene Quote (AiSaveMedia hat 200 fuer die Rezeptfotos). */
    private const NOTES_MEDIA_MAX     = 300;
    /** Laengste Kante eines abgelegten Bildes. Groesser kostet Platz ohne mehr zu zeigen. */
    private const NOTES_IMAGE_EDGE    = 1600;
    /** Schonfrist, bevor ein unbenutzter Anhang eingesammelt wird (zwei Tage). */
    private const NOTES_SWEEP_GRACE   = 172800;
    /**
     * Groessengrenzen kommen aus OutputLimit()/RelayLimitB64() in AppCore — ein PDF
     * laesst sich nicht verkleinern, und ueber der Ausgabegrenze waere es abgelegt,
     * aber nie wieder abrufbar.
     */

    private function NotesMediaCreate(): void
    {
        $this->RegisterAttributeString(self::NOTES_MEDIA_ATTR, '');
    }

    /**
     * Kategorie der Anhaenge. `$anlegen=false` loest nur auf — Lesepfade duerfen
     * nichts erzeugen, sollen aber auch nicht scheitern, bloss weil das Attribut
     * noch leer ist.
     */
    private function NotesMediaCategory(bool $anlegen): int
    {
        $id = (int)$this->ReadAttributeStringSafe(self::NOTES_MEDIA_ATTR, '0');
        if ($id > 0 && IPS_CategoryExists($id)) {
            return $id;
        }
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $kind) {
            if (IPS_CategoryExists($kind) && IPS_GetName($kind) === self::NOTES_CATEGORY_NAME) {
                @$this->WriteAttributeString(self::NOTES_MEDIA_ATTR, (string)$kind);
                return $kind;
            }
        }
        if (!$anlegen) {
            return 0;
        }
        $id = IPS_CreateCategory();
        IPS_SetParent($id, $this->InstanceID);
        IPS_SetName($id, self::NOTES_CATEGORY_NAME);
        @$this->WriteAttributeString(self::NOTES_MEDIA_ATTR, (string)$id);
        return $id;
    }

    /**
     * Bild oder PDF ablegen.
     *
     * @return array{ok:bool,id?:int,kind?:string,name?:string,bytes?:int,error?:array}
     */
    private function NotesSaveAttachment(string $base64, string $name): array
    {
        $roh = base64_decode($this->AiStripImage($base64), true);
        if (!is_string($roh) || $roh === '') {
            return $this->NotesFehler('invalid_payload');
        }
        // Magic Bytes VOR jeder Objekterzeugung. PNG ist dabei, weil jeder
        // Handy-Screenshot einer ist; HEIC bleibt draussen (GD kann es nicht).
        $istPdf = str_starts_with($roh, '%PDF-');
        $istJpg = str_starts_with($roh, "\xFF\xD8\xFF");
        $istPng = str_starts_with($roh, "\x89PNG\r\n\x1a\n");
        if (!$istPdf && !$istJpg && !$istPng) {
            return $this->NotesFehler('unsupported_file');
        }
        if ($istPdf) {
            if (strlen($roh) > $this->OutputLimit()) {
                return $this->NotesFehler('file_too_large');
            }
        } else {
            // Bilder werden auf JPEG normalisiert: ein MIME, eine Endung, und die
            // Ausgabegroesse ist garantiert. Der Preis sind weichere Kanten bei
            // einem Text-Screenshot — bewusst in Kauf genommen.
            $klein = $this->AiScaleImage($roh, self::NOTES_IMAGE_EDGE);
            if ($klein === null) {
                // Ohne GD nicht ungeprueft durchlassen: dann gilt derselbe Riegel
                // wie fuer PDF, sonst waere die Datei nie wieder abrufbar.
                if (strlen($roh) > $this->OutputLimit() || $istPng) {
                    return $this->NotesFehler('file_too_large');
                }
            } else {
                $roh = $klein;
            }
        }
        $kat = $this->NotesMediaCategory(true);
        if ($kat <= 0) {
            return $this->NotesFehler('no_category');
        }
        if (count(IPS_GetChildrenIDs($kat)) >= self::NOTES_MEDIA_MAX) {
            return $this->NotesFehler('quota_exceeded');
        }
        $mid = IPS_CreateMedia($istPdf ? MEDIATYPE_DOCUMENT : MEDIATYPE_IMAGE);
        IPS_SetParent($mid, $kat);
        IPS_SetName($mid, $this->NotesTrim($name !== '' ? $name : 'Anhang', 80));
        // Reihenfolge zwingend: erst eine Datei, dann Inhalt.
        IPS_SetMediaFile($mid, 'media/symdo_note_' . $mid . ($istPdf ? '.pdf' : '.jpg'), false);
        IPS_SetMediaContent($mid, base64_encode($roh));
        return ['ok' => true, 'id' => $mid, 'kind' => $istPdf ? 'pdf' : 'image',
                'name' => (string)@IPS_GetName($mid), 'bytes' => strlen($roh)];
    }

    /** Alle Medien-IDs, die diese Notizen belegen. @return int[] */
    private function NotesAttachmentIds(array $notizen): array
    {
        $ids = [];
        foreach ($notizen as $n) {
            foreach ((is_array($n['att'] ?? null) ? $n['att'] : []) as $a) {
                $id = (int)($a['id'] ?? 0);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * Von den genannten Dateien die, auf die NIEMAND mehr zeigt.
     *
     * Noetig, weil eine Mail mit mehreren Notiz-Funden allen Eintraegen DIESELBE
     * Anhangsliste mitgibt: uebernimmt man beide, verweisen zwei Notizen auf
     * dasselbe Medienobjekt. Ein Loeschen an der einen Notiz nahm der anderen dann
     * die Datei weg — deren Anhang blieb in der Liste stehen und antwortete ab da
     * mit 403. Geprueft wird gegen den NEUEN Stand (das Loeschen ist schon darin)
     * und gegen die offenen Vorschlaege.
     *
     * @param list<int> $ids
     * @return list<int>
     */
    private function NotesUnreferencedMedia(array $store, array $ids): array
    {
        $benutzt = array_merge(
            $this->NotesAttachmentIds(is_array($store['notes'] ?? null) ? $store['notes'] : []),
            $this->NotesProposalAttachmentIds()
        );
        $frei = [];
        foreach ($ids as $id) {
            if (!in_array((int)$id, $benutzt, true)) {
                $frei[] = (int)$id;
            }
        }
        return $frei;
    }

    /** Loescht nur, was wirklich in der Notizen-Kategorie liegt. */
    private function NotesDeleteMedia(array $ids): void
    {
        $kat = $this->NotesMediaCategory(false);
        if ($kat <= 0) {
            return;
        }
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id <= 0 || !IPS_MediaExists($id)) {
                continue;
            }
            if (IPS_GetParent($id) !== $kat) {
                continue;
            }
            @IPS_DeleteMedia($id, true);
        }
    }

    private function NotesAttachUpload(array $store, array $body, int $jetzt): array
    {
        $i = $this->NotesIndexOf($store['notes'], (string)($body['noteId'] ?? ''));
        if ($i < 0) {
            return $this->NotesFehler('not_found');
        }
        $vorhanden = is_array($store['notes'][$i]['att'] ?? null) ? $store['notes'][$i]['att'] : [];
        if (count($vorhanden) >= self::NOTE_ATTACH_MAX) {
            return $this->NotesFehler('quota_exceeded');
        }
        $daten = (string)($body['pdf'] ?? $body['image'] ?? '');
        if ($daten === '') {
            return $this->NotesFehler('invalid_payload');
        }
        // Der Client nennt NIE eine Medien-ID, nur Bytes. Sonst waere att[] beliebig
        // setzbar und die Datei-Route lieferte jedes Medienobjekt im System aus.
        $r = $this->NotesSaveAttachment($daten, (string)($body['name'] ?? ''));
        if (($r['ok'] ?? false) !== true) {
            return $r;
        }
        $anhang = ['id' => $r['id'], 'kind' => $r['kind'], 'name' => $r['name'], 'bytes' => $r['bytes']];
        $store['notes'][$i]['att'] = array_merge($vorhanden, [$anhang]);
        $store['notes'][$i]['updatedAt'] = $jetzt;
        if (!$this->NotesWriteStore($store)) {
            $this->NotesDeleteMedia([$r['id']]);
            return $this->NotesFehler('store_unwritable');
        }
        return ['ok' => true, 'rev' => (int)$store['rev'] + 1, 'attachment' => $anhang];
    }

    private function NotesAttachDelete(array $store, array $body, int $jetzt): array
    {
        $i = $this->NotesIndexOf($store['notes'], (string)($body['noteId'] ?? ''));
        if ($i < 0) {
            return $this->NotesFehler('not_found');
        }
        $mid = (int)($body['attachmentId'] ?? 0);
        $bleibt = [];
        $gefunden = false;
        foreach ((is_array($store['notes'][$i]['att'] ?? null) ? $store['notes'][$i]['att'] : []) as $a) {
            if ((int)($a['id'] ?? 0) === $mid) {
                $gefunden = true;
                continue;
            }
            $bleibt[] = $a;
        }
        if (!$gefunden) {
            return $this->NotesFehler('not_found');
        }
        $store['notes'][$i]['att'] = $bleibt;
        $store['notes'][$i]['updatedAt'] = $jetzt;
        if (!$this->NotesWriteStore($store)) {
            return $this->NotesFehler('store_unwritable');
        }
        $this->NotesDeleteMedia($this->NotesUnreferencedMedia($store, [$mid]));
        return ['ok' => true, 'rev' => (int)$store['rev'] + 1];
    }

    /**
     * Anhang als data:-URL — der Weg fuer die Visu-Kachel.
     *
     * Die Kachel hat keinen Token und kann die Datei-Route deshalb nicht abrufen. Als
     * AKTION und nicht als Pfad, weil der Kachel-Relay mit str_ends_with vergleicht:
     * ein Pfad '/notes/media' liefe in den 'media'-Zweig der Rezeptfotos.
     *
     * Mit `meta` kommt nur die Art zurueck. Die Oberflaeche braucht sie VOR dem Klick,
     * um synchron entscheiden zu koennen — nach einem await wertet Safari ein
     * window.open als Popup.
     */
    /**
     * Eine Anhang-Datei als data:-URL — der Weg fuer die Kachel, die keinen Token
     * und damit keine Datei-Adresse hat.
     *
     * `$noteId` LEER heisst: die Datei haengt noch an einem Mail-Vorschlag, nicht
     * an einer Notiz. Auch die muss sich ansehen lassen, sonst waehlt man im
     * Editor blind aus, was uebernommen wird.
     */
    private function NotesAttachData(string $noteId, int $mediaId, bool $nurArt = false): array
    {
        $kat = $this->NotesMediaCategory(false);
        if ($noteId === '') {
            if (!in_array($mediaId, $this->NotesProposalAttachmentIds(), true)) {
                return $this->NotesFehler('forbidden');
            }
        } else {
            $store = $this->NotesStore();
            $i = $this->NotesIndexOf($store['notes'], $noteId);
            if ($i < 0) {
                return $this->NotesFehler('not_found');
            }
            if (!in_array($mediaId, $this->NotesAttachmentIds([$store['notes'][$i]]), true)) {
                return $this->NotesFehler('forbidden');
            }
        }
        if ($mediaId <= 0 || !IPS_MediaExists($mediaId) || $kat <= 0 || IPS_GetParent($mediaId) !== $kat) {
            return $this->NotesFehler('forbidden');
        }
        $b64 = (string)IPS_GetMediaContent($mediaId);
        $roh = base64_decode($b64, true);
        if (!is_string($roh) || $roh === '') {
            return $this->NotesFehler('empty');
        }
        $istPdf = str_starts_with($roh, '%PDF-');
        if ($nurArt) {
            return ['ok' => true, 'isPdf' => $istPdf];
        }
        // Grenze der Relay-Nutzlast. Base64 blaeht um ein Drittel auf; ein grosses PDF
        // kaeme in der Kachel nicht mehr an. Die Web-App holt es stattdessen ueber die
        // Datei-Route, die kein Base64 braucht.
        if (strlen($b64) > $this->RelayLimitB64()) {
            // Ein Bild weiter verkleinern, damit es auf jedem Weg ankommt; ein PDF
            // laesst sich nicht verkleinern und geht nur ueber die Datei-Route.
            $passt = $istPdf ? null : $this->AiFitImageForRelay($roh, $this->RelayLimitB64());
            if ($passt === null) {
                return $this->NotesFehler('too_large_for_tile');
            }
            $b64 = base64_encode($passt);
        }
        return ['ok' => true, 'isPdf' => $istPdf,
                'dataUrl' => 'data:' . ($istPdf ? 'application/pdf' : 'image/jpeg') . ';base64,' . $b64];
    }

    /**
     * GET /v1/notes/media/{id} — Rohdatei. Noetig, weil WebKit ein PDF im iframe
     * nur als erste, nicht scrollbare Seite rendert; ueber diese Adresse uebernimmt
     * der System-Viewer.
     *
     * ZWEI Pruefungen: Die ID muss in einer Notiz stehen UND ihr Vater muss die
     * Notizen-Kategorie sein. Die erste allein reicht nicht — sie faengt keinen
     * verdorbenen Index, der auf einen Avatar oder einen Tonschnipsel zeigt.
     */
    private function HandleNotesMediaFile(int $mediaId): void
    {
        $kat = $this->NotesMediaCategory(false);
        // Zwei Quellen sind erlaubt: die Anhaenge bestehender Notizen UND die
        // Anhaenge noch offener Mail-Vorschlaege. Letztere braucht man, um VOR dem
        // Uebernehmen hineinzusehen — ohne das waehlt man blind aus, welche Datei
        // an die Notiz kommt. Keine weitere Einschraenkung nach Mitglied: der
        // KI-Bereich zeigt dieselben Vorschlaege ohnehin allen Geraeten, und die
        // Notizen sind bewusst gemeinsam.
        $erlaubt = in_array($mediaId, $this->NotesAttachmentIds($this->NotesStore()['notes']), true)
            || in_array($mediaId, $this->NotesProposalAttachmentIds(), true);
        if (!$erlaubt || $mediaId <= 0 || !IPS_MediaExists($mediaId) || $kat <= 0 || IPS_GetParent($mediaId) !== $kat) {
            $this->SendApiError('forbidden', 'Not a note attachment', 403);
            return;
        }
        $m = IPS_GetMedia($mediaId);
        $roh = base64_decode((string)IPS_GetMediaContent($mediaId), true);
        if (!is_string($roh) || $roh === '') {
            $this->SendApiError('empty', 'Media not readable', 404);
            return;
        }
        $istPdf = str_starts_with($roh, '%PDF-');
        $etag = '"' . md5($mediaId . '|' . (string)($m['MediaUpdated'] ?? 0) . '|' . strlen($roh)) . '"';
        header('ETag: ' . $etag);
        header('Cache-Control: private, no-cache');
        if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
            http_response_code(304);
            return;
        }
        header('Content-Type: ' . ($istPdf ? 'application/pdf' : 'image/jpeg'));
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: inline; ' . $this->AiFileNameParams((string)@IPS_GetName($mediaId), $istPdf, 'Notiz'));
        echo $roh;
    }

    /**
     * Waisen einsammeln.
     *
     * Notwendig, weil es zwei Wege gibt, auf denen ein Verweis OHNE Zutun
     * verschwindet: ein Mail-Vorschlag verfaellt nach MAIL_RETENTION_DAYS still beim
     * Lesen, und MailWriteProposals schneidet ueber MAIL_PROPOSALS_MAX ab. Beides
     * wirft Datensaetze weg, ohne dass jemand davon erfaehrt. Ohne diesen Durchlauf
     * fuellt jeder verworfene Vorschlag mit Anhang die Platte — unsichtbar, fuer immer.
     */
    /**
     * Alle Anhang-Kennungen, die an einem Mail-Vorschlag haengen.
     *
     * Zwei Quellen, und beide sind noetig: „atts" ist die Liste, seit eine Mail
     * mehrere Anhaenge mitbringen kann, „mediaId" der Einzelne aus Vorschlaegen,
     * die vor der Umstellung entstanden sind. Nur „mediaId" zu lesen hiess: alle
     * Anhaenge ausser dem ersten gelten als Waise und werden nach der Schonfrist
     * geloescht — die Auswahl im Editor haette dann ins Leere gezeigt.
     *
     * Dieselbe Liste entscheidet, was die Datei-Route herausgeben darf: ein
     * Anhang, den der KI-Bereich anbietet, muss sich ansehen lassen, BEVOR man
     * ihn uebernimmt. Sonst waehlt man blind.
     *
     * @return list<int>
     */
    private function NotesProposalAttachmentIds(): array
    {
        $ids = [];
        foreach ($this->MailProposals() as $v) {
            foreach ((is_array($v['items'] ?? null) ? $v['items'] : []) as $it) {
                if (!is_array($it) || ($it['taken'] ?? false) === true) {
                    continue;
                }
                foreach ((array)($it['atts'] ?? []) as $a) {
                    if (is_array($a) && (int)($a['id'] ?? 0) > 0) {
                        $ids[] = (int)$a['id'];
                    }
                }
                if ((int)($it['mediaId'] ?? 0) > 0) {
                    $ids[] = (int)$it['mediaId'];
                }
            }
        }
        return array_values(array_unique($ids));
    }

    private function NotesSweepOrphans(): int
    {
        $kat = $this->NotesMediaCategory(false);
        if ($kat <= 0) {
            return 0;
        }
        // Unter der Notizen-Sperre, denn NotesStorable() schreibt einen Probewert und
        // stellt danach den ALTEN Stand wieder her. Laeuft gleichzeitig eine
        // Mutation, ueberschreibt diese Wiederherstellung sie — die Notiz waere weg.
        // Ohne freie Sperre lieber gar nicht aufraeumen: die Schonfrist von zwei
        // Tagen laesst jede Menge weitere Gelegenheiten.
        $lock = self::NOTES_LOCK . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 300)) {
            return 0;
        }
        try {
            return $this->NotesSweepOrphansLocked($kat);
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    /** Nur aufrufen, wenn die Notizen-Sperre gehalten wird. */
    private function NotesSweepOrphansLocked(int $kat): int
    {
        // Ohne benutzbaren Bestand NICHT aufraeumen. Ein unlesbares Attribut liefert
        // eine leere Notizliste — dann saehe JEDER Anhang wie eine Waise aus und der
        // Durchlauf loeschte den ganzen Bestand.
        //
        // ACHTUNG, Reichweite: NotesStorable prueft NUR das Notizen-Attribut. Fuer
        // die Mail-Vorschlaege gilt derselbe Zweifel — auch MailProposals() liefert
        // bei unlesbarem Attribut still eine leere Liste — deshalb die zweite Wache
        // darunter: ist der Vorschlagsbestand nicht lesbar, wird ebenfalls nichts
        // geloescht. (Fruehere Fassung dieses Kommentars behauptete, NotesStorable
        // deckte beides ab. Tat es nicht.)
        if (!$this->NotesStorable() || !$this->MailProposalsReadable()) {
            return 0;
        }
        $lebt = array_merge(
            $this->NotesAttachmentIds($this->NotesStore()['notes']),
            $this->NotesProposalAttachmentIds()
        );
        // Zweites Netz: eine Schonfrist. Ein gerade abgelegter Anhang gehoert zu
        // einem Vorschlag, der noch keine Notiz ist — er darf nicht weggeraeumt
        // werden, bloss weil der Bestand ihn (noch) nicht nennt.
        $grenze = time() - self::NOTES_SWEEP_GRACE;
        $weg = 0;
        foreach (IPS_GetChildrenIDs($kat) as $kind) {
            if (!IPS_MediaExists($kind) || in_array($kind, $lebt, true)) {
                continue;
            }
            $m = @IPS_GetMedia($kind);
            if (is_array($m) && (int)($m['MediaUpdated'] ?? 0) > $grenze) {
                continue;
            }
            @IPS_DeleteMedia($kind, true);
            $weg++;
        }
        if ($weg > 0) {
            $this->LogMessage(sprintf(
                'SymDo Notizen: %d nicht mehr benutzte Anhang-Datei(en) entfernt.',
                $weg
            ), KL_NOTIFY);
        }
        return $weg;
    }
}

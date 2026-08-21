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
 *  3. AiSaveMedia prueft nur die ANZAHL, nie die Groesse. Symcons Hook-Ausgabe endet
 *     aber bei 1 MB, kumulativ ueber die ganze Antwort (gemessen, siehe Tts.php).
 *     Eine zu grosse Datei ist damit dauerhaft unabrufbar. Deshalb wird hier beim
 *     ABLEGEN begrenzt: Bilder werden skaliert, PDFs abgelehnt.
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
    private const NOTES_IMAGE_QUALITY = 82;
    /**
     * Rohgroesse eines PDF. Ein PDF laesst sich nicht skalieren, und ueber der
     * 1-MB-Grenze der Hook-Ausgabe waere es abgelegt, aber nie wieder abrufbar.
     * Dieselbe Zahl benutzt HandleUserAvatar fuer denselben Zweck.
     */
    private const NOTES_PDF_MAX_BYTES = 900000;
    /** Schonfrist, bevor ein unbenutzter Anhang eingesammelt wird (zwei Tage). */
    private const NOTES_SWEEP_GRACE   = 172800;
    /** Groesste Base64-Nutzlast fuer den Kachel-Relay (roh etwa 700 KB). */
    private const NOTES_RELAY_MAX_B64 = 960000;

    public function NotesMediaCreate(): void
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
            if (strlen($roh) > self::NOTES_PDF_MAX_BYTES) {
                return $this->NotesFehler('file_too_large');
            }
        } else {
            // Bilder werden auf JPEG normalisiert: ein MIME, eine Endung, und die
            // Ausgabegroesse ist garantiert. Der Preis sind weichere Kanten bei
            // einem Text-Screenshot — bewusst in Kauf genommen.
            $klein = $this->NotesScaleImage($roh);
            if ($klein === null) {
                // Ohne GD nicht ungeprueft durchlassen: dann gilt derselbe Riegel
                // wie fuer PDF, sonst waere die Datei nie wieder abrufbar.
                if (strlen($roh) > self::NOTES_PDF_MAX_BYTES || $istPng) {
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

    /**
     * Seitenverhaeltnis erhalten, laengste Kante begrenzen, als JPEG ausgeben.
     * ScaleAvatar taugt hier NICHT — das schneidet quadratisch mittig zu, bei einem
     * Dokumentfoto waeren Kopf und Fuss weg.
     */
    private function NotesScaleImage(string $binaer): ?string
    {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }
        $bild = @imagecreatefromstring($binaer);
        if ($bild === false) {
            return null;
        }
        try {
            $b = imagesx($bild);
            $h = imagesy($bild);
            $lang = max($b, $h);
            if ($lang > self::NOTES_IMAGE_EDGE) {
                $f = self::NOTES_IMAGE_EDGE / $lang;
                $neu = imagescale($bild, max(1, (int)round($b * $f)), max(1, (int)round($h * $f)));
                if ($neu !== false) {
                    imagedestroy($bild);
                    $bild = $neu;
                }
            }
            ob_start();
            imagejpeg($bild, null, self::NOTES_IMAGE_QUALITY);
            $aus = (string)ob_get_clean();
            return $aus !== '' ? $aus : null;
        } finally {
            imagedestroy($bild);
        }
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
        $this->NotesDeleteMedia([$mid]);
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
    private function NotesAttachData(string $noteId, int $mediaId, bool $nurArt = false): array
    {
        $store = $this->NotesStore();
        $i = $this->NotesIndexOf($store['notes'], $noteId);
        if ($i < 0) {
            return $this->NotesFehler('not_found');
        }
        $kat = $this->NotesMediaCategory(false);
        if (!in_array($mediaId, $this->NotesAttachmentIds([$store['notes'][$i]]), true)
            || $mediaId <= 0 || !IPS_MediaExists($mediaId) || $kat <= 0 || IPS_GetParent($mediaId) !== $kat) {
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
        if (strlen($b64) > self::NOTES_RELAY_MAX_B64) {
            return $this->NotesFehler('too_large_for_tile');
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
        $bekannt = in_array($mediaId, $this->NotesAttachmentIds($this->NotesStore()['notes']), true);
        if (!$bekannt || $mediaId <= 0 || !IPS_MediaExists($mediaId) || $kat <= 0 || IPS_GetParent($mediaId) !== $kat) {
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
    private function NotesSweepOrphans(): int
    {
        $kat = $this->NotesMediaCategory(false);
        if ($kat <= 0) {
            return 0;
        }
        // Ohne benutzbaren Bestand NICHT aufraeumen. Ein unlesbares Attribut liefert
        // eine leere Notizliste — dann saehe JEDER Anhang wie eine Waise aus und der
        // Durchlauf loeschte den ganzen Bestand. Dasselbe gilt fuer die
        // Mail-Vorschlaege, die aus demselben Grund leer erscheinen koennen.
        if (!$this->NotesStorable()) {
            return 0;
        }
        $lebt = $this->NotesAttachmentIds($this->NotesStore()['notes']);
        foreach ($this->MailProposals() as $v) {
            foreach ((is_array($v['items'] ?? null) ? $v['items'] : []) as $it) {
                $mid = (int)($it['mediaId'] ?? 0);
                if ($mid > 0) {
                    $lebt[] = $mid;
                }
            }
        }
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

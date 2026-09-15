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
    /** Der Knoten ueber den Schul-Toepfen. Er entsteht mit dem ersten von ihnen. */
    private const SCHULE_CATEGORY_NAME = 'Schule';
    /**
     * Wohin die Dateien EINER Quelle gehoeren — der Name des Topfes unter „Schule".
     *
     * Der Schluessel ist die Quelle der Karte, wie `EduStoreCalc::Quelle` sie
     * liefert. Was hier nicht steht, gehoert zu den Notizen: Mail-Anhaenge und
     * alles, was jemand in der App an eine Notiz haengt. `untis` steht mit drin,
     * obwohl WebUntis heute keine einzige Datei ablegt — der Topf entstuende
     * sonst spaeter unter einem anderen Namen, und die Regel waere nur halb
     * aufgeschrieben.
     */
    private const NOTES_TOPF_NAMEN = [
        'edumaps' => 'Edumaps',
        'moodle'  => 'Logineo',
        'untis'   => 'WebUntis',
    ];
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

    /** @var array<string,int> Aufgeloeste Schul-Toepfe; der Namensdurchlauf kostet sonst je Datei. */
    private array $notesTopfCache = [];
    /** Und ihr gemeinsamer Vater — sonst durchsucht jeder der drei die Instanz erneut. */
    private int $notesSchuleCache = 0;

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
        /* BestandID() und nicht InstanceID: sie ist die dafuer vorgesehene
           Antwort auf „unter welcher Instanz liegen Medien und Kategorien".
           Im Gateway ist das dieselbe Zahl — aber nur so steht die Notizen-
           Kategorie unter derselben Regel wie die Schul-Toepfe darunter. */
        $id = $this->NotesKategorieUnter($this->BestandID(), self::NOTES_CATEGORY_NAME, $anlegen);
        if ($id > 0) {
            @$this->WriteAttributeString(self::NOTES_MEDIA_ATTR, (string)$id);
        }
        return $id;
    }

    /**
     * Der Topf einer Quelle.
     *
     * Die Dateien der Schulseiten liegen NICHT bei den Notizen. Ein Elternbrief
     * aus Edumaps, ein Arbeitsblatt aus LOGINEO und ein Foto, das jemand selbst
     * an eine Notiz gehaengt hat, sahen im Objektbaum bis zum 15.09.2026 gleich
     * aus — und teilten sich EINE Quote von 300. Eine grosse Klassenseite konnte
     * damit den Notizen den Platz nehmen; jetzt hat jede Quelle ihre eigene.
     *
     * Eine unbekannte Quelle landet bewusst bei den Notizen statt in einem
     * eigenen Topf: ein Tippfehler soll keine Kategorie erzeugen, die danach
     * niemand mehr liest und kein Aufraeumer kennt.
     */
    private function NotesMedienTopf(string $quelle, bool $anlegen): int
    {
        $name = self::NOTES_TOPF_NAMEN[$quelle] ?? '';
        if ($name === '') {
            return $this->NotesMediaCategory($anlegen);
        }
        $gemerkt = (int)($this->notesTopfCache[$quelle] ?? 0);
        if ($gemerkt > 0 && IPS_CategoryExists($gemerkt)) {
            return $gemerkt;
        }
        $schule = $this->notesSchuleCache > 0 && IPS_CategoryExists($this->notesSchuleCache)
            ? $this->notesSchuleCache
            : $this->NotesKategorieUnter($this->BestandID(), self::SCHULE_CATEGORY_NAME, $anlegen);
        $this->notesSchuleCache = $schule;
        if ($schule <= 0) {
            return 0;
        }
        $id = $this->NotesKategorieUnter($schule, $name, $anlegen);
        if ($id > 0) {
            $this->notesTopfCache[$quelle] = $id;
        }
        return $id;
    }

    /**
     * Eine Kategorie dieses Namens unter diesem Vater — finden oder anlegen.
     *
     * Ueber den NAMEN und nicht ueber ein Attribut: ein neu registriertes
     * Attribut existiert vor dem Kernel-Neustart nicht, und dann entstuende bei
     * jedem Abruf ein weiterer Topf. Der Name ueberlebt auch ein Modul-Update.
     */
    private function NotesKategorieUnter(int $vater, string $name, bool $anlegen): int
    {
        if ($vater <= 0) {
            return 0;
        }
        foreach (IPS_GetChildrenIDs($vater) as $kind) {
            if (IPS_CategoryExists($kind) && IPS_GetName($kind) === $name) {
                return $kind;
            }
        }
        if (!$anlegen) {
            return 0;
        }
        $id = IPS_CreateCategory();
        IPS_SetParent($id, $vater);
        IPS_SetName($id, $name);
        return $id;
    }

    /**
     * Alle Toepfe, die uns gehoeren — Notizen und die drei Schul-Toepfe.
     *
     * Die EINE Stelle, an der „liegt diese Datei bei uns" beantwortet wird.
     * Vier Lesewege und der Aufraeumer fragten das vorher jeder fuer sich, und
     * zwar gegen die EINE Notizen-Kategorie. Mit dem zweiten Topf haette jeder
     * von ihnen stillschweigend „nein" gesagt: jedes Kartenbild waere mit 403
     * geendet, und geloescht worden waere nie wieder eines.
     *
     * @return list<int>
     */
    private function NotesMedienToepfe(): array
    {
        $ids = [$this->NotesMediaCategory(false)];
        foreach (array_keys(self::NOTES_TOPF_NAMEN) as $quelle) {
            $ids[] = $this->NotesMedienTopf((string)$quelle, false);
        }
        return array_values(array_unique(array_filter($ids, static fn($id): bool => (int)$id > 0)));
    }

    /** Gehoert dieses Medienobjekt uns? Die eine Frage hinter jeder Berechtigung. */
    private function NotesMedienUnser(int $mediaId): bool
    {
        return $mediaId > 0 && IPS_MediaExists($mediaId)
            && in_array(IPS_GetParent($mediaId), $this->NotesMedienToepfe(), true);
    }

    /**
     * Bild oder PDF ablegen.
     *
     * @return array{ok:bool,id?:int,kind?:string,name?:string,bytes?:int,error?:array}
     */
    private function NotesSaveAttachment(string $base64, string $name, string $quelle = ''): array
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
        /* Die eine Tuer, durch die jedes Medienobjekt entsteht — deshalb steht
           hier und nur hier, in welchen Topf es kommt. Die Quote gilt JE Topf:
           eine Klassenseite kann den Notizen keinen Platz mehr wegnehmen. */
        $kat = $this->NotesMedienTopf($quelle, true);
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
                /* Das Vorschaubild eines PDF gehoert zum Anhang, nicht daneben.
                   Es MUSS hier mitgezaehlt werden: dieselbe Liste entscheidet,
                   was die Datei-Route ausliefert UND was als verwaist geloescht
                   wird. Fehlte es, waere die Vorschau nicht abrufbar und beim
                   naechsten Aufraeumen weg. */
                $mini = (int)($a['thumb'] ?? 0);
                if ($mini > 0) {
                    $ids[] = $mini;
                }
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * Alle Medien-Kennungen, auf die IRGENDWER zeigt.
     *
     * Drei Quellen, und alle drei müssen hier stehen: die Notizen, die noch
     * offenen Mail-Vorschläge und die Karten der Klassenseiten. Seit die Karten
     * einen eigenen Bestand haben, ist das die Stelle, an der beide
     * zusammenlaufen — fehlte einer, sähe der Aufräumer dessen Anhänge als
     * Waisen und löschte sie nach der Schonfrist. Bei den Klassenseiten wären
     * das auf einen Schlag alle Kartenbilder.
     *
     * @return list<int>
     */
    private function NotesLiveMediaIds(): array
    {
        return array_values(array_unique(array_merge(
            $this->NotesAttachmentIds($this->NotesStore()['notes']),
            $this->NotesProposalAttachmentIds(),
            $this->EduAttachmentIds()
        )));
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
        /* Der übergebene Stand gilt für die NOTIZEN (dort steckt das gerade
           Gelöschte schon drin); Vorschläge und Klassenseiten kommen frisch
           dazu. Ohne den dritten Topf nähme ein Löschen in den Notizen einer
           Karte die Datei weg, die beide teilen. */
        $benutzt = array_merge(
            $this->NotesAttachmentIds(is_array($store['notes'] ?? null) ? $store['notes'] : []),
            $this->NotesProposalAttachmentIds(),
            $this->EduAttachmentIds()
        );
        $frei = [];
        foreach ($ids as $id) {
            if (!in_array((int)$id, $benutzt, true)) {
                $frei[] = (int)$id;
            }
        }
        return $frei;
    }

    /** Loescht nur, was wirklich in einem unserer Toepfe liegt. */
    private function NotesDeleteMedia(array $ids): void
    {
        $toepfe = $this->NotesMedienToepfe();
        if ($toepfe === []) {
            return;
        }
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id <= 0 || !IPS_MediaExists($id)) {
                continue;
            }
            if (!in_array(IPS_GetParent($id), $toepfe, true)) {
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
        return $this->NotesMediaAusgeben($mediaId, $nurArt);
    }

    /**
     * Ein geprueftes Medienobjekt als data:-URL herausgeben.
     *
     * Herausgezogen, weil zwei Bestaende dieselbe Ausgabe brauchen: die Notizen
     * und die Karten der Klassenseiten. Die BERECHTIGUNG prueft jeder Aufrufer
     * selbst — er allein weiss, in welchem Bestand die Kennung stehen muss.
     * Hier bleibt nur, was fuer beide gleich ist: liegt die Datei wirklich in
     * einem unserer Toepfe, und passt sie durch das Relay.
     */
    private function NotesMediaAusgeben(int $mediaId, bool $nurArt = false): array
    {
        if (!$this->NotesMedienUnser($mediaId)) {
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
     * ZWEI Pruefungen: Die ID muss in einer Notiz stehen UND ihr Vater muss einer
     * unserer Toepfe sein. Die erste allein reicht nicht — sie faengt keinen
     * verdorbenen Index, der auf einen Avatar oder einen Tonschnipsel zeigt.
     */
    private function HandleNotesMediaFile(int $mediaId): void
    {
        // Zwei Quellen sind erlaubt: die Anhaenge bestehender Notizen UND die
        // Anhaenge noch offener Mail-Vorschlaege. Letztere braucht man, um VOR dem
        // Uebernehmen hineinzusehen — ohne das waehlt man blind aus, welche Datei
        // an die Notiz kommt. Keine weitere Einschraenkung nach Mitglied: der
        // KI-Bereich zeigt dieselben Vorschlaege ohnehin allen Geraeten, und die
        // Notizen sind bewusst gemeinsam.
        /* DREI Quellen, seit die Klassenseiten einen eigenen Bestand haben.
           Ohne den dritten Topf antwortet jedes Kartenbild mit 403 — die
           Adresse ist dieselbe (/v1/notes/media/<id>), egal in welcher Kategorie
           die Datei liegt, und die Web-App hat sie fest verdrahtet. */
        $erlaubt = in_array($mediaId, $this->NotesLiveMediaIds(), true);
        if (!$erlaubt || !$this->NotesMedienUnser($mediaId)) {
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
        $toepfe = $this->NotesMedienToepfe();
        if ($toepfe === []) {
            return 0;
        }
        // Unter der Notizen-Sperre, denn NotesStorable() schreibt einen Probewert und
        // stellt danach den ALTEN Stand wieder her. Laeuft gleichzeitig eine
        // Mutation, ueberschreibt diese Wiederherstellung sie — die Notiz waere weg.
        // Ohne freie Sperre lieber gar nicht aufraeumen: die Schonfrist von zwei
        // Tagen laesst jede Menge weitere Gelegenheiten.
        /* BEIDE Sperren, in der festgelegten Ordnung Notizen → Klassenseiten.
           Grund wie unten bei NotesStorable: auch EduStorable schreibt einen
           Probewert und stellt danach den ALTEN Stand wieder her. Läuft
           gleichzeitig eine Spiegelung, überschreibt diese Wiederherstellung
           sie — die Karte wäre weg. Ohne freie Sperren lieber gar nicht
           aufräumen: die Schonfrist von zwei Tagen lässt jede Menge weitere
           Gelegenheiten. */
        $lock = self::NOTES_LOCK . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 0)) {
            return 0;
        }
        try {
            $eduLock = self::EDU_LOCK . $this->InstanceID;
            if (!IPS_SemaphoreEnter($eduLock, 0)) {
                return 0;
            }
            try {
                $this->NotesMedienEinsortieren();
                return $this->NotesSweepOrphansLocked($this->NotesMedienToepfe());
            } finally {
                IPS_SemaphoreLeave($eduLock);
            }
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    /**
     * Jede Datei in den Topf ihrer Quelle.
     *
     * Kein einmaliger Umzug, sondern eine Zusicherung, die bei jedem Durchgang
     * wieder gilt: eine Seite darf die Quelle wechseln (eine Klassenseite, die
     * es spaeter auch in LOGINEO gibt), und dann folgt ihre Datei. Der Umzug
     * selbst ist ein `IPS_SetParent` — die KENNUNG bleibt, also bleibt auch
     * jeder Verweis darauf gueltig: im Bestand, in der App, in der Kachel und
     * in der Datei-Route. Es gibt nichts nachzuziehen.
     *
     * Angefasst wird NUR, was schon in einem unserer Toepfe liegt. Eine
     * verdorbene Kennung im Bestand risse sonst ein fremdes Medienobjekt aus
     * seinem Baum — ein Avatar, ein Tonschnipsel.
     *
     * Nur aufrufen, wenn beide Sperren gehalten werden.
     */
    private function NotesMedienEinsortieren(): int
    {
        /* Ohne die Wachen des Aufraeumers (NotesStorable & Co.), und das mit
           Absicht: ein unlesbarer Bestand liefert hier eine LEERE Kartenliste,
           und die heisst „nichts verschieben". Beim Loeschen hiesse dieselbe
           leere Liste „alles ist eine Waise" — deshalb steht die Wache dort. */
        $eigene = $this->NotesMedienToepfe();
        if ($eigene === []) {
            return 0;
        }
        $umgezogen = 0;
        foreach ($this->EduStoreRead()['notes'] as $karte) {
            if (!is_array($karte)) {
                continue;
            }
            $ids = EduStoreCalc::AnhangIds([$karte]);
            if ($ids === []) {
                continue;
            }
            /* Erst hier anlegen, nicht je Karte: eine Karte ohne Datei soll
               keinen Topf erzeugen, den nie etwas fuellt. */
            $ziel = $this->NotesMedienTopf(EduStoreCalc::Quelle($karte), true);
            if ($ziel <= 0) {
                continue;
            }
            foreach ($ids as $mid) {
                $mid = (int)$mid;
                if ($mid <= 0 || !IPS_MediaExists($mid)) {
                    continue;
                }
                $vater = IPS_GetParent($mid);
                if ($vater === $ziel || !in_array($vater, $eigene, true)) {
                    continue;
                }
                @IPS_SetParent($mid, $ziel);
                $umgezogen++;
            }
        }
        if ($umgezogen > 0) {
            $this->LogMessage(sprintf(
                'SymDo Notizen: %d Datei(en) der Schulseiten in ihren Ordner unter „%s" verschoben.',
                $umgezogen, self::SCHULE_CATEGORY_NAME
            ), KL_NOTIFY);
        }
        return $umgezogen;
    }

    /**
     * Nur aufrufen, wenn die Notizen-Sperre gehalten wird.
     *
     * @param list<int> $toepfe alle Kategorien, die uns gehoeren
     */
    private function NotesSweepOrphansLocked(array $toepfe): int
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
        /* DRITTE Wache, aus demselben Grund wie die zweite: ein Attribut, das
           der Kernel noch nicht kennt, liefert einen LEEREN Bestand — und dann
           sähe jeder Anhang der Klassenseiten wie eine Waise aus. Genau dieser
           Fall tritt nach einem Modul-Update vor dem Kernel-Neustart ein. */
        if (!$this->NotesStorable() || !$this->MailProposalsReadable() || !$this->EduStorable()) {
            return 0;
        }
        $lebt = $this->NotesLiveMediaIds();
        // Zweites Netz: eine Schonfrist. Ein gerade abgelegter Anhang gehoert zu
        // einem Vorschlag, der noch keine Notiz ist — er darf nicht weggeraeumt
        // werden, bloss weil der Bestand ihn (noch) nicht nennt.
        $grenze = time() - self::NOTES_SWEEP_GRACE;
        $weg = 0;
        foreach ($toepfe as $kat) {
            foreach (IPS_GetChildrenIDs((int)$kat) as $kind) {
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

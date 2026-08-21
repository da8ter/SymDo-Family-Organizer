<?php

declare(strict_types=1);

/**
 * KI fuer Notizen: ein Bild oder PDF hineingeben, Titel und Text bekommen — und
 * dazu die Aufgaben und Termine, die im Dokument stecken.
 *
 * Ein einziger Anbieter-Aufruf fuer beides. Die Unterscheidung Aufgabe/Termin
 * kommt wortgleich aus AiKindRule, damit es sie nur an einer Stelle gibt.
 *
 * WICHTIG — das Antwortformat ist NICHT reines JSON. Der Bestand hat vom Modell
 * nie einen langen Freitext verlangt; ein solcher enthaelt aber regelmaessig
 * ECHTE Zeilenumbrueche, und die sind in einem JSON-String unzulaessig. Deshalb
 * stehen Titel und Text hier in eigenen Abschnitten ausserhalb jedes JSON-Strings,
 * und nur die Fundliste ist JSON.
 */
trait NotesAi
{
    /** Marken des Antwortformats. Bewusst schlicht, damit auch kleine Modelle sie treffen. */
    private const NOTES_AI_MARK = '/^###[ \t]*(TITEL|NOTIZ|FUNDE)[ \t]*$/mu';

    /**
     * KI-Freigabe fuer Notizen.
     *
     * Ausdruecklich MIT Einwilligung, anders als AiIsEnabled — das prueft nur die
     * Property. Notizen legen Anhaenge DAUERHAFT ab, da ist die Einwilligung keine
     * Kosmetik. Muster: MailIsEnabled.
     */
    private function NotesAiAllowed(): bool
    {
        try {
            if (!$this->ReadPropertyBoolean('AiEnabled')) {
                return false;
            }
        } catch (Throwable $e) {
            return false;
        }
        return $this->AiPrivacyAccepted();
    }

    /**
     * action: 'analyse' — legt NICHTS ab, liefert nur Vorschlaege.
     *
     * @param array<string,mixed>|null $device null = Kachel-Relay (ohne Geraet und
     *        damit ohne Ratenbegrenzung, genau wie savephoto dort heute schon)
     */
    private function NotesAnalyse(array $body, ?array $device): array
    {
        if (!$this->NotesAiAllowed()) {
            return $this->NotesFehler('no_consent');
        }
        if ($device !== null
            && !$this->AiRateLimitAllows((string)($device['id'] ?? ''), self::AI_RATE_MAX, self::AI_RATE_WINDOW)) {
            return $this->NotesFehler('ai_quota');
        }
        $pdf   = $this->AiStripImage((string)($body['pdf'] ?? ''));
        $image = $this->AiStripImage((string)($body['image'] ?? ''));
        if ($pdf !== '' && strlen($pdf) > self::AI_MAX_PDF_B64) {
            return $this->NotesFehler('file_too_large');
        }
        if ($image !== '' && strlen($image) > self::AI_MAX_IMAGE_B64) {
            return $this->NotesFehler('file_too_large');
        }
        if ($pdf === '' && $image === '') {
            return $this->NotesFehler('invalid_payload');
        }
        $r = $this->AiRunCompletion(
            $this->NotesAiSystemPrompt(date('Y-m-d')),
            $pdf !== '' ? 'Mache aus dieser Datei eine Notiz.' : 'Mache aus diesem Bild eine Notiz.',
            $image !== '' ? $image : null,
            $pdf !== '' ? $pdf : null
        );
        if (($r['ok'] ?? false) !== true) {
            return $this->NotesFehler((string)($r['code'] ?? 'ai_upstream'));
        }
        $erg = $this->NotesParseAi((string)$r['text']);
        return ['ok' => true, 'note' => ['title' => $erg['title'], 'text' => $erg['text']],
                'todos' => $erg['todos']];
    }

    private function NotesAiSystemPrompt(string $heute): string
    {
        return 'Du liest ein Dokument (Foto oder PDF) und machst daraus eine Notiz zum '
            . 'Nachschlagen. Heute ist der ' . $heute . '. '
            . 'Antworte GENAU in diesem Format, mit den drei Marken in dieser Reihenfolge, '
            . 'ohne Einleitung, ohne Erklaerung, ohne Markdown-Auszeichnung:' . "\n"
            . '### TITEL' . "\n"
            . 'eine einzige kurze Zeile, hoechstens 60 Zeichen' . "\n"
            . '### NOTIZ' . "\n"
            . 'der Inhalt in eigenen Worten, beliebig viele Zeilen, hoechstens 1500 Zeichen' . "\n"
            . '### FUNDE' . "\n"
            . '[ ... ]' . "\n\n"
            . 'ZUM TITEL: Er benennt die Sache, nicht die Gattung. „Elternabend 3b am 12.09." '
            . 'ist gut, „Elternbrief" oder „Dokument" ist schlecht. '
            . 'ZUR NOTIZ: Gib alles wieder, was man spaeter nachschlagen will — Namen, '
            . 'Betraege, Fristen, Raeume, Telefonnummern, Anschriften, Aktenzeichen, '
            . 'Bestellnummern. Erfinde nichts und lass nichts Wichtiges weg. Steht kaum '
            . 'Text im Bild, beschreibe knapp, was zu sehen ist. '
            . 'ZU DEN FUNDEN: Dort steht ein JSON-Array mit allem, was einen Termin oder '
            . 'eine Handlung darstellt — leer („[]"), wenn es nichts davon gibt. Jeder '
            . 'Eintrag hat die Felder "title", "info", "due" (YYYY-MM-DD oder weglassen), '
            . '"time", "priority" ("high"|"normal"|"low") und "kind". '
            . 'Schreibe in den FUNDEN keine echten Zeilenumbrueche in einen Wert, '
            . 'sondern hoechstens \\n.'
            . $this->AiKindRule();
    }

    /**
     * Antwort in Titel, Text und Funde zerlegen.
     *
     * Drei Stufen, weil ein Modell die Marken verfehlen kann und eine leere Notiz
     * das schlechteste Ergebnis waere: Der Nutzer hat ein Dokument hineingegeben,
     * irgendein Text ist immer besser als nichts. (Anders als bei AiParseTodos, wo
     * „nichts erkannt" eine richtige Antwort ist.)
     *
     * @return array{title:string,text:string,todos:array}
     */
    private function NotesParseAi(string $antwort): array
    {
        $teile = preg_split(self::NOTES_AI_MARK, $antwort, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (is_array($teile) && count($teile) >= 3) {
            $ab = [];
            for ($i = 1; $i < count($teile); $i += 2) {
                $ab[strtoupper(trim((string)$teile[$i]))] = (string)($teile[$i + 1] ?? '');
            }
            if (isset($ab['TITEL']) || isset($ab['NOTIZ'])) {
                $titel = $this->NotesErsteZeile((string)($ab['TITEL'] ?? ''));
                $text  = trim((string)($ab['NOTIZ'] ?? ''));
                $funde = isset($ab['FUNDE'])
                    ? $this->AiValidateTodoRows($this->AiDecodeJsonArray($ab['FUNDE']))
                    : [];
                if ($titel === '' && $text !== '') {
                    $titel = $this->NotesErsteZeile($text);
                }
                if ($titel !== '' || $text !== '') {
                    return ['title' => mb_substr($titel, 0, self::NOTE_TITLE_MAX),
                            'text' => mb_substr($text, 0, self::NOTE_TEXT_MAX),
                            'todos' => $funde];
                }
            }
        }
        // Stufe 2: reines JSON-Objekt {title, text, todos}
        $obj = $this->AiDecodeJsonObject($antwort);
        if (is_array($obj) && (isset($obj['title']) || isset($obj['text']))) {
            $rows = is_array($obj['todos'] ?? null) ? $obj['todos'] : [];
            return ['title' => mb_substr($this->NotesErsteZeile((string)($obj['title'] ?? '')), 0, self::NOTE_TITLE_MAX),
                    'text'  => mb_substr(trim((string)($obj['text'] ?? '')), 0, self::NOTE_TEXT_MAX),
                    'todos' => $this->AiValidateTodoRows($rows)];
        }
        // Stufe 3: blanke Prosa. Erste Zeile als Titel, alles als Text.
        $roh = trim($antwort);
        return ['title' => mb_substr($this->NotesErsteZeile($roh), 0, self::NOTE_TITLE_MAX),
                'text'  => mb_substr($roh, 0, self::NOTE_TEXT_MAX),
                'todos' => []];
    }

    private function NotesErsteZeile(string $text): string
    {
        foreach (preg_split('/\R/u', $text) ?: [] as $zeile) {
            // Fuehrende Markdown-Reste wegnehmen, falls das Modell doch auszeichnet.
            $z = trim((string)preg_replace('/^\s*(#+|[-*>]+)\s*/u', '', (string)$zeile));
            $z = trim($z, " \t*_#");
            if ($z !== '') {
                return $z;
            }
        }
        return '';
    }

    /**
     * action: 'adopt' — aus einem Mail-Vorschlag wird eine Notiz.
     *
     * Die Medien-ID des Anhangs wird SERVERSEITIG im Vorschlag nachgeschlagen; der
     * Client nennt sie nie. Sonst waere att[] beliebig setzbar und die Datei-Route
     * lieferte jedes Medienobjekt im System aus.
     *
     * Als „taken" meldet der Client den Vorschlag erst NACH dieser Antwort ab —
     * derselbe Zweischritt wie beim Kalender.
     */
    private function NotesAdopt(array $store, array $body, int $jetzt): array
    {
        $vid = (string)($body['proposalId'] ?? '');
        $idx = (int)($body['i'] ?? -1);
        $fid = (string)($body['folderId'] ?? '');
        if ($this->NotesIndexOf($store['folders'], $fid) < 0) {
            return $this->NotesFehler('not_found');
        }
        if (count($store['notes']) >= self::NOTES_MAX) {
            return $this->NotesFehler('quota_exceeded');
        }
        $eintrag = null;
        foreach ($this->MailProposals() as $v) {
            if ((string)($v['id'] ?? '') !== $vid) {
                continue;
            }
            $items = is_array($v['items'] ?? null) ? $v['items'] : [];
            if (isset($items[$idx]) && is_array($items[$idx])) {
                $eintrag = $items[$idx];
            }
            break;
        }
        if ($eintrag === null) {
            return $this->NotesFehler('not_found');
        }
        // Titel und Text kommen aus dem EDITOR, wenn er sie mitschickt — der Nutzer
        // darf den Vorschlag vor dem Speichern aendern. Nur die Medien-ID wird
        // ausschliesslich serverseitig im Vorschlag nachgeschlagen; sonst waere att[]
        // beliebig setzbar und die Datei-Route lieferte jedes Medienobjekt aus.
        $titelRoh = array_key_exists('title', $body) ? (string)$body['title'] : (string)($eintrag['title'] ?? '');
        $textRoh  = array_key_exists('text', $body)
            ? (string)$body['text']
            : (string)($eintrag['text'] ?? $eintrag['info'] ?? '');
        if (mb_strlen($titelRoh) > self::NOTE_TITLE_MAX || mb_strlen($textRoh) > self::NOTE_TEXT_MAX) {
            return $this->NotesFehler('invalid_payload');
        }
        $titel = $this->NotesTrim($titelRoh, self::NOTE_TITLE_MAX);
        $text  = trim($textRoh);
        if ($titel === '' && $text === '') {
            return $this->NotesFehler('invalid_payload');
        }
        $att = [];
        $mid = (int)($eintrag['mediaId'] ?? 0);
        if ($mid > 0 && IPS_MediaExists($mid) && $this->NotesMediaCategory(false) === IPS_GetParent($mid)) {
            $roh = (string)IPS_GetMediaContent($mid);
            $att[] = ['id' => $mid, 'kind' => str_starts_with((string)base64_decode(substr($roh, 0, 12), true), '%PDF-') ? 'pdf' : 'image',
                      'name' => (string)@IPS_GetName($mid), 'bytes' => (int)(strlen($roh) * 3 / 4)];
        }
        $notiz = ['id' => $this->NotesNewId(), 'folderId' => $fid,
                  'title' => $titel !== '' ? $titel : mb_substr($text, 0, 40),
                  'text' => $text, 'att' => $att,
                  'createdAt' => $jetzt, 'updatedAt' => $jetzt, 'source' => 'mail'];
        $store['notes'][] = $notiz;
        return $this->NotesWriteStore($store)
            ? ['ok' => true, 'rev' => (int)$store['rev'] + 1, 'note' => $this->NotesRow($notiz, true)]
            : $this->NotesFehler('store_unwritable');
    }
}

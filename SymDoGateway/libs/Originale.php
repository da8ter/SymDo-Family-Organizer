<?php

declare(strict_types=1);

require_once __DIR__ . '/AnhangGrenzenCalc.php';
require_once __DIR__ . '/OriginalCalc.php';
require_once __DIR__ . '/OriginalStore.php';

/**
 * Die Originale der KI-Vorschlaege (24.09.2026) — ablegen, ausliefern,
 * ableiten, aufraeumen; dazu die Experteneinstellungen fuer Anhaenge.
 *
 * Was die KI gelesen hat, bleibt jetzt erhalten: der Text und die Anhaenge,
 * die durch die Auswertung liefen, als Ordner je Original (OriginalStore).
 * Das Original lebt so lange wie sein Vorschlag. Waehlt jemand im
 * Hinzufuegen-Dialog „Original speichern", entsteht fuer DIESEN Eintrag ein
 * eigenes, abgeleitetes Original mit genau den gewaehlten Anhaengen — es lebt
 * so lange wie der Eintrag. Aufgeraeumt wird zustandsbasiert: was keinen
 * Verweis mehr hat, faellt weg; ist eine Quelle nicht lesbar, faellt nichts.
 *
 * Einstiege von aussen: die Aktionen original/originalTeil/originalBehalten am
 * Vorschlags-Endpunkt (MailHandleAction, auch ueber das Kachel-Relay) und die
 * REST-Route GET /v1/original/<id>/<n>?teil=k (ApiRouter).
 */
trait Originale
{
    /** Takt des Aufraeumens — der Einmal-Lauf nach dem Start und nach „Loeschen" kommt dazu. */
    private const ORIGINALE_TAKT_MS = 21600000;
    /** So lange bleibt ein Original ohne Verweis liegen (Auftrag unterwegs, Eintrag wird gerade gespeichert). */
    private const ORIGINALE_SCHONFRIST = 3600;

    private function OriginaleCreate(): void
    {
        foreach (AnhangGrenzenCalc::STANDARD as $name => $wert) {
            if (is_string($wert)) {
                $this->RegisterPropertyString($name, $wert);
            } else {
                $this->RegisterPropertyInteger($name, $wert);
            }
        }
        // Pruefsumme ⇒ [Zaehler, zuletzt] — kein Bildinhalt.
        $this->RegisterAttributeString('OriginalBildHashes', '{}');
        // Eben verworfene Vorschlaege: ihr Original faellt ohne Schonfrist.
        $this->RegisterAttributeString('OriginaleVerworfen', '[]');
        /* IPS_RequestAction statt einer TGW_-Funktion: eine neue Praefix-Funktion
           gaebe es erst nach einem Kernel-Neustart, RequestAction sofort. */
        $this->RegisterTimer('OriginaleAufraeumen', 0,
            'IPS_RequestAction($_IPS[\'TARGET\'], \'OriginaleAufraeumen\', \'\');');
    }

    private function OriginaleApplyChanges(): void
    {
        try {
            $this->SetTimerInterval('OriginaleAufraeumen', self::ORIGINALE_TAKT_MS);
        } catch (\Throwable $e) {
            $this->SendDebug('Originale', 'Zeitgeber fehlt noch (Modul neu laden)', 0);
        }
        $this->OriginaleBaldAufraeumen();
    }

    private function OriginaleRequestAction(string $Ident, mixed $Value): bool
    {
        if ($Ident === 'OriginaleAufraeumen') {
            $this->OriginaleAufraeumen();
            return true;
        }
        if ($Ident === 'ExpertenStandard') {
            foreach (AnhangGrenzenCalc::STANDARD as $name => $wert) {
                $this->UpdateFormField($name, 'value', $wert);
            }
            return true;
        }
        return false;
    }

    /** Hinter den laufenden Aufruf legen und buendeln — derselbe Weg wie beim Nachziehen der Hausaufgaben. */
    private function OriginaleBaldAufraeumen(): void
    {
        try {
            @$this->RegisterOnceTimer('OriginaleJetzt',
                'IPS_RequestAction($_IPS[\'TARGET\'], \'OriginaleAufraeumen\', \'\');');
        } catch (\Throwable $e) {
            // Aeltere Symcon-Fassung: dann raeumt eben der 6-Stunden-Takt.
        }
    }

    private function OriginalVerzeichnis(): string
    {
        return rtrim((string)@IPS_GetKernelDir(), '/\\') . DIRECTORY_SEPARATOR . 'symdo_originale'
            . DIRECTORY_SEPARATOR . $this->InstanceID;
    }

    private function OriginalAblage(bool $anlegen = true): OriginalStore
    {
        return OriginalStore::in($this->OriginalVerzeichnis(), $anlegen);
    }

    /**
     * Die wirksamen Filter und Grenzen. IPS_GetConfiguration statt
     * ReadProperty*: vor dem Neuladen gibt es die Eigenschaften noch nicht, dann
     * gelten die Standardwerte (= die frueheren Konstanten).
     *
     * @return array<string,mixed> siehe AnhangGrenzenCalc::Wirksam
     */
    private function AnhangGrenzen(): array
    {
        $cfg = json_decode((string)@IPS_GetConfiguration($this->InstanceID), true);
        $roh = [];
        foreach (array_keys(AnhangGrenzenCalc::STANDARD) as $k) {
            if (is_array($cfg) && array_key_exists($k, $cfg)) {
                $roh[$k] = $cfg[$k];
            }
        }
        return AnhangGrenzenCalc::Wirksam($roh);
    }

    // ── Ablegen ────────────────────────────────────────────────────────────

    /**
     * Das Original eines Vorschlags ablegen und seine Kennung liefern ('' wenn
     * nicht: keine Einwilligung, Schreibfehler — der Vorschlag entsteht dann
     * eben ohne Original).
     *
     * @param array<string,mixed>       $kopf
     * @param list<array<string,mixed>> $anhaenge {kind, name, base64, url?}
     * @param string $vorgabe  Kennung aus dem Auftragskopf (asynchroner Weg)
     * @param bool   $nurWennFehlt  Auftragsergebnis: ein beim Einreihen
     *        abgelegtes Original (voller Text, Anhaenge) nie durch den gekuerzten
     *        Text aus dem Auftrag ersetzen
     */
    private function OriginalSichern(string $vid, array $kopf, string $text, array $anhaenge, string $quelle,
        string $vorgabe = '', bool $nurWennFehlt = false): string
    {
        if ($vid === '' || !$this->AiPrivacyAccepted()) {
            return '';
        }
        try {
            $ablage = $this->OriginalAblage();
            $id = OriginalCalc::KennungGueltig($vorgabe) ? $vorgabe
                : OriginalCalc::Kennung($vid, $text, self::MAIL_ORIGIN_TEXT_MAX);
            if ($nurWennFehlt && $ablage->existiert($id)) {
                return $id;
            }
            $g = $this->AnhangGrenzen();
            $gedaechtnis = json_decode($this->ReadAttributeStringSafe('OriginalBildHashes', '{}'), true);
            $gedaechtnis = is_array($gedaechtnis) ? $gedaechtnis : [];
            $atts = [];
            $dateien = [];
            $skipped = [];
            $hashes = [];
            $n = 0;
            foreach ($anhaenge as $a) {
                $name = (string)($a['name'] ?? '');
                if ($n >= min((int)$g['maxCount'], OriginalCalc::ANHAENGE_MAX)) {
                    $skipped[] = $name;
                    continue;
                }
                $roh = base64_decode($this->AiStripImage((string)($a['base64'] ?? '')), true);
                $art = is_string($roh) ? OriginalCalc::AnhangArt($roh) : '';
                if ($art === '') {
                    $skipped[] = $name;
                    continue;
                }
                // Die Pruefsumme der Datei, wie sie ankam — das Briefpapier ist jedes Mal dieselbe.
                $hash = sha1($roh);
                if ($art !== 'pdf') {
                    $klein = $this->AiScaleImage($roh, OriginalCalc::BILD_KANTE);
                    if (is_string($klein) && $klein !== '') {
                        $roh = $klein;
                        $art = 'jpg';
                    }
                    $hashes[] = $hash;
                }
                $n++;
                $atts[] = ['n' => $n, 'name' => $name, 'kind' => $art, 'bytes' => strlen($roh),
                    'deko' => OriginalCalc::IstDeko($name, $art, OriginalCalc::SchonGesehen($gedaechtnis, $hash),
                        (array)$g['decoNames'], (int)$g['decoRepeat'])];
                $dateien[$n] = $roh;
            }
            $satz = OriginalCalc::Satz([
                'id'         => $id,
                'proposalId' => $vid,
                'source'     => $quelle,
                'from'       => (string)($kopf['SenderAddress'] ?? ''),
                'fromName'   => (string)($kopf['SenderName'] ?? ''),
                'subject'    => trim((string)($kopf['Subject'] ?? '')),
                'date'       => (int)($kopf['Date'] ?? time()),
                'origin'     => $this->MailDetectOrigin($text),
                'savedAt'    => time(),
                'text'       => $text,
                'truncated'  => $nurWennFehlt && mb_strlen($text) >= self::MAIL_ORIGIN_TEXT_MAX,
                'atts'       => $atts,
                'skipped'    => $skipped,
            ]);
            if (!$ablage->anlegen($id, $satz, $dateien)) {
                $this->SendDebug('Originale', 'Original nicht ablegbar (' . $quelle . ')', 0);
                return '';
            }
            if ($hashes !== []) {
                @$this->WriteAttributeString('OriginalBildHashes', (string)json_encode(
                    OriginalCalc::Gedaechtnis($gedaechtnis, $hashes, time())));
            }
            return $id;
        } catch (\Throwable $e) {
            $this->SendDebug('Originale', 'Sichern gescheitert: ' . $e->getMessage(), 0);
            return '';
        }
    }

    // ── Ausliefern ─────────────────────────────────────────────────────────

    private function OriginalFehler(string $code): array
    {
        $text = match ($code) {
            'not_found'    => 'The original is no longer available.',
            'store_failed' => 'The original could not be saved.',
            default        => 'Invalid original.',
        };
        return ['ok' => false, 'error' => ['code' => $code, 'message' => $this->Translate($text)]];
    }

    /** Kopf, Text und Anhangsliste — Aktion `original`. */
    private function OriginalAbrufen(string $id): array
    {
        if (!OriginalCalc::KennungGueltig($id)) {
            return $this->OriginalFehler('invalid_payload');
        }
        $satz = $this->OriginalAblage(false)->lesen($id);
        if ($satz === null) {
            return $this->OriginalFehler('not_found');
        }
        $o = OriginalCalc::Oeffentlich($satz);
        /* Passt der Anhang in eine Notiz? Deren Datei-Route liefert in EINER
           Antwort aus — was darueber liegt, bietet der Notiz-Dialog nicht an. */
        $grenze = $this->OutputLimit();
        $o['atts'] = array_map(static fn(array $a): array => $a + ['notiz' => (int)($a['bytes'] ?? 0) <= $grenze], $o['atts']);
        return ['ok' => true, 'original' => $o];
    }

    /**
     * Stueckgroesse: REST liefert roh unter der Ausgabegrenze von Symcon, das
     * Kachel-Relay Base64 in JSON unter RelayLimitB64.
     */
    private function OriginalStueck(bool $relay): int
    {
        return $relay
            ? max(65536, (int)floor(($this->RelayLimitB64() - 4096) * 3 / 4))
            : max(65536, $this->OutputLimit() - 65536);
    }

    /** Ein Stueck als Base64 — Aktion `originalTeil`, fuer die Visu-Kachel ohne Token. */
    private function OriginalTeilRelay(string $id, int $n, int $teil): array
    {
        if (!OriginalCalc::KennungGueltig($id)) {
            return $this->OriginalFehler('invalid_payload');
        }
        $t = $this->OriginalAblage(false)->teilLesen($id, $n, $teil, $this->OriginalStueck(true));
        if ($t === null) {
            return $this->OriginalFehler('not_found');
        }
        return ['ok' => true, 'teil' => $teil, 'teile' => $t['teile'], 'groesse' => $t['groesse'],
                'kind' => $t['art'], 'name' => $t['name'], 'data' => base64_encode($t['daten'])];
    }

    /** GET /v1/original/<id>/<n>?teil=k — die rohen Bytes eines Stuecks. */
    private function HandleOriginalFile(string $id, int $n, int $teil): void
    {
        if (!OriginalCalc::KennungGueltig($id)) {
            $this->SendApiError('invalid_payload', 'Invalid original', 400);
            return;
        }
        $t = $this->OriginalAblage(false)->teilLesen($id, $n, $teil, $this->OriginalStueck(false));
        if ($t === null) {
            $this->SendApiError('not_found', 'Original not found', 404);
            return;
        }
        header('Content-Type: ' . OriginalCalc::Mime($t['art']));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        header('X-SymDo-Teile: ' . $t['teile']);
        header('X-SymDo-Groesse: ' . $t['groesse']);
        header('Access-Control-Expose-Headers: X-SymDo-Teile, X-SymDo-Groesse');
        header('Content-Disposition: inline; ' . $this->AiFileNameParams(
            (string)preg_replace('/\.(pdf|jpe?g|png)$/i', '', $t['name']), $t['art'] === 'pdf', 'Anhang'));
        echo $t['daten'];
    }

    /**
     * „Original speichern": ein eigenes Original fuer EINEN Eintrag, mit dem
     * Text und genau den gewaehlten Anhaengen — Aktion `originalBehalten`.
     *
     * @param list<mixed> $nummern
     */
    private function OriginalBehalten(string $id, array $nummern): array
    {
        if (!OriginalCalc::KennungGueltig($id)) {
            return $this->OriginalFehler('invalid_payload');
        }
        $ablage = $this->OriginalAblage(false);
        $alt = $ablage->lesen($id);
        if ($alt === null) {
            return $this->OriginalFehler('not_found');
        }
        $vorhanden = [];
        foreach ((array)($alt['atts'] ?? []) as $a) {
            $vorhanden[(int)($a['n'] ?? 0)] = $a;
        }
        $gewaehlt = [];
        foreach ($nummern as $n) {
            if (!is_int($n) && !(is_string($n) && ctype_digit($n))) {
                return $this->OriginalFehler('invalid_payload');
            }
            if (!isset($vorhanden[(int)$n])) {
                return $this->OriginalFehler('invalid_payload');
            }
            $gewaehlt[(int)$n] = $vorhanden[(int)$n];
        }
        ksort($gewaehlt);
        $neu = OriginalCalc::NeueKennung();
        $satz = OriginalCalc::Satz(array_merge($alt, [
            'id' => $neu, 'abgeleitetVon' => $id, 'savedAt' => time(), 'atts' => array_values($gewaehlt),
        ]));
        if (!$ablage->ableiten($id, $neu, $satz, array_keys($gewaehlt))) {
            return $this->OriginalFehler('store_failed');
        }
        return ['ok' => true, 'id' => $neu];
    }

    private function OriginalExistiert(string $id): bool
    {
        return OriginalCalc::KennungGueltig($id) && $this->OriginalAblage(false)->existiert($id);
    }

    /** Das Original eines eben verworfenen Vorschlags: faellt beim naechsten Lauf ohne Schonfrist. */
    private function OriginalVerworfenMerken(string $id): void
    {
        if (!OriginalCalc::KennungGueltig($id)) {
            return;
        }
        $liste = json_decode($this->ReadAttributeStringSafe('OriginaleVerworfen', '[]'), true);
        $liste = is_array($liste) ? $liste : [];
        $liste[] = $id;
        @$this->WriteAttributeString('OriginaleVerworfen',
            (string)json_encode(array_values(array_slice(array_unique($liste), -200))));
        $this->OriginaleBaldAufraeumen();
    }

    // ── Aufraeumen ─────────────────────────────────────────────────────────

    /**
     * Ein Attribut roh lesen — und null, wenn das nicht sicher geht. MailAttr
     * und Freunde liefern bei einem Fehler still die Vorgabe; beim Aufraeumen
     * hiesse das „es gibt keine Verweise" und alles fiele weg.
     */
    private function OriginalAttributRoh(string $name): ?string
    {
        error_clear_last();
        try {
            $wert = @$this->ReadAttributeString($name);
        } catch (\Throwable $e) {
            return null;
        }
        if (error_get_last() !== null || !is_string($wert)) {
            return null;
        }
        return $wert;
    }

    /**
     * Wer haelt welches Original? Null, wenn eine Quelle nicht sicher lesbar
     * ist — dann wird nichts geloescht.
     *
     * @return array{lebend:array<string,bool>, eintraege:array<string,bool>}|null
     */
    private function OriginalVerweise(): ?array
    {
        $lebend = [];
        $eintraege = [];
        $merke = static function (array &$in, mixed $id): void {
            if (OriginalCalc::KennungGueltig($id)) {
                $in[(string)$id] = true;
            }
        };
        $liste = function (string $attribut, string $feld) : ?array {
            $roh = $this->OriginalAttributRoh($attribut);
            if ($roh === null) {
                return null;
            }
            if (trim($roh) === '') {
                return [];
            }
            $d = json_decode($roh, true);
            if (!is_array($d)) {
                return null;
            }
            return $feld === '' ? $d : (is_array($d[$feld] ?? null) ? $d[$feld] : (isset($d[$feld]) ? null : []));
        };

        // Vorschlaege
        $vorschlaege = $liste('MailProposals', '');
        if ($vorschlaege === null) {
            return null;
        }
        foreach ($this->MailProposals() as $p) {
            $merke($lebend, $p['originalId'] ?? null);
        }
        // Laufende Auftraege: ihr Original liegt seit dem Einreihen
        try {
            foreach ($this->AiJobLaden(false)->koepfe() as $k) {
                $h = is_array($k['origin'] ?? null) ? $k['origin'] : [];
                if (($h['type'] ?? '') !== AiJobStore::HERKUNFT_HINTERGRUND) {
                    continue;
                }
                $merke($lebend, $h['original'] ?? OriginalCalc::Kennung((string)($h['vorschlag'] ?? ''),
                    (string)($h['text'] ?? ''), self::MAIL_ORIGIN_TEXT_MAX));
            }
        } catch (\Throwable $e) {
            return null;
        }
        // Hausaufgaben, Notizen, Termine
        $hw = $liste('HomeworkStore', 'items');
        $notizen = $liste('NotesStore', 'notes');
        $termine = $liste('CalOriginals', '');
        if ($hw === null || $notizen === null || $termine === null) {
            return null;
        }
        foreach ($hw as $i) {
            $merke($eintraege, is_array($i) ? ($i['originalId'] ?? null) : null);
        }
        foreach ($notizen as $n) {
            $merke($eintraege, is_array($n) ? ($n['originalId'] ?? null) : null);
        }
        foreach ($termine as $id) {
            $merke($eintraege, $id);
        }
        // Aufgaben aller ToDo-Instanzen — derselbe Weg wie das Briefing
        foreach ($this->GetListInstances() as $instanz) {
            if (($instanz['kind'] ?? '') !== 'todo') {
                continue;
            }
            if (!$this->IsInstanceReady((int)$instanz['id'])) {
                return null;
            }
            $zustand = json_decode((string)$this->CallInstanceGetAppState((int)$instanz['id'], 'todo'), true);
            $items = $zustand['state']['items'] ?? $zustand['items'] ?? null;
            if (!is_array($items)) {
                return null;
            }
            foreach ($items as $it) {
                $merke($eintraege, is_array($it) ? ($it['originalId'] ?? null) : null);
            }
        }
        return ['lebend' => $lebend, 'eintraege' => $eintraege];
    }

    /**
     * Aufraeumen. Ohne Einwilligung (widerrufen) zaehlen nur noch die Eintraege:
     * die Originale der Vorschlaege fallen dann sofort.
     *
     * @return int wie viele Originale wegfielen
     */
    private function OriginaleAufraeumen(): int
    {
        $sperre = 'SymDo_Originale_' . $this->InstanceID;
        if (!@IPS_SemaphoreEnter($sperre, 0)) {
            return 0;
        }
        try {
            $ablage = $this->OriginalAblage(false);
            $dateien = $ablage->alle();
            $verworfen = json_decode($this->ReadAttributeStringSafe('OriginaleVerworfen', '[]'), true);
            $sofort = [];
            foreach (is_array($verworfen) ? $verworfen : [] as $id) {
                if (OriginalCalc::KennungGueltig($id)) {
                    $sofort[(string)$id] = true;
                }
            }
            if ($dateien === []) {
                if ($sofort !== []) {
                    @$this->WriteAttributeString('OriginaleVerworfen', '[]');
                }
                return 0;
            }
            $verweise = $this->OriginalVerweise();
            if ($verweise === null) {
                $this->SendDebug('Originale', 'Aufraeumen ausgesetzt: eine Quelle ist nicht lesbar', 0);
                return 0;
            }
            $einwilligung = $this->AiPrivacyAccepted();
            $weg = OriginalCalc::Wegraeumen($dateien, $einwilligung ? $verweise['lebend'] : [],
                $verweise['eintraege'], $sofort, time(), $einwilligung ? self::ORIGINALE_SCHONFRIST : 0,
                (int)$this->AnhangGrenzen()['capBytes']);
            foreach ($weg as $id) {
                $ablage->loeschen($id);
            }
            if ($sofort !== []) {
                @$this->WriteAttributeString('OriginaleVerworfen', '[]');
            }
            if ($weg !== []) {
                $this->SendDebug('Originale', count($weg) . ' Original(e) entfernt, ' . (count($dateien) - count($weg)) . ' bleiben', 0);
            }
            return count($weg);
        } finally {
            @IPS_SemaphoreLeave($sperre);
        }
    }

    // ── Formular ───────────────────────────────────────────────────────────

    /** Das Panel „Experteneinstellungen" im KI-Bereich. */
    private function GetExpertenPanel(): array
    {
        $cfg = json_decode((string)@IPS_GetConfiguration($this->InstanceID), true);
        if (!is_array($cfg) || !array_key_exists('AttImageMinKB', $cfg)) {
            return ['type' => 'ExpansionPanel', 'caption' => $this->Translate('Expert settings'), 'expanded' => false,
                'items' => [['type' => 'Label', 'caption' => $this->Translate('The expert settings appear after the module has been reloaded.')]]];
        }
        $zahl = function (string $name, string $caption, string $suffix): array {
            [$min, $max] = AnhangGrenzenCalc::BEREICH[$name];
            return ['type' => 'NumberSpinner', 'name' => $name, 'minimum' => $min, 'maximum' => $max, 'suffix' => $suffix,
                'caption' => sprintf($this->Translate($caption), (string)AnhangGrenzenCalc::STANDARD[$name])];
        };
        return [
            'type' => 'ExpansionPanel', 'caption' => $this->Translate('Expert settings'), 'expanded' => false,
            'items' => [
                ['type' => 'Label', 'caption' => $this->Translate('Only change these if attachments are missing or logos slip through. The default is shown in brackets.')],
                ['type' => 'Label', 'bold' => true, 'caption' => $this->Translate('Mail attachments (filtered before download)')],
                $zahl('AttImageMinKB', 'Smallest image size (default %s)', ' KB'),
                $zahl('AttImageMinPixel', 'Shortest image edge (default %s)', ' px'),
                ['type' => 'ValidationTextBox', 'name' => 'AttDecoNames', 'width' => '100%',
                 'caption' => $this->Translate('Layout names, comma-separated — such images are not pre-selected')],
                $zahl('AttMaxCount', 'Attachments per mail (default %s)', ''),
                $zahl('AttMaxFileMB', 'Size per file (default %s)', ' MB'),
                $zahl('AttMaxTotalMB', 'Total per mail (default %s)', ' MB'),
                ['type' => 'Label', 'bold' => true, 'caption' => $this->Translate('Class pages and LOGINEO')],
                $zahl('EduAttMaxTotalMB', 'Attachments per card in total (default %s)', ' MB'),
                ['type' => 'Label', 'bold' => true, 'caption' => $this->Translate('Originals')],
                $zahl('OrigDecoRepeat', 'An image counts as letterhead from its n-th appearance (default %s, 0 = off)', ''),
                $zahl('OrigCapMB', 'Space for originals that only belong to a suggestion (default %s)', ' MB'),
                ['type' => 'Button', 'caption' => $this->Translate('Default values'),
                 'onClick' => 'IPS_RequestAction($id, \'ExpertenStandard\', \'\');'],
            ],
        ];
    }
}

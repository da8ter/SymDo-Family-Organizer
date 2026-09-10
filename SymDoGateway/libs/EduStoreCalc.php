<?php

declare(strict_types=1);

/**
 * Klassenseiten — das Rechenwerk des eigenen Bestands, ohne Symcon.
 *
 * Keine Symcon-Aufrufe, kein Attribut, keine Uhr: alles kommt als Parameter
 * herein. Damit läuft es im Prüfstand (SymDoGateway/tests/EdumapsStoreTest.php),
 * und der UMZUG aus den Notizen — der einzige Schritt dieses Umbaus, der Daten
 * verlieren könnte — ist eine reine Funktion, die man vorher durchrechnen kann.
 * Vorbild: SymDoGateway/libs/HomeworkCalc.php.
 */
class EduStoreCalc
{
    /** Die Karten tragen `html`; der Notizen-Deckel von 786 kB wäre zu knapp. */
    public const STORE_MAX    = 1048576;
    public const FOLDERS_MAX  = 40;
    /** Über alle Seiten zusammen. Je Seite deckelt EduMaps::EDU_KARTEN_MAX. */
    public const KARTEN_MAX   = 300;
    public const TEXT_MAX     = 8000;
    public const TITLE_MAX    = 120;
    public const PREVIEW_MAX  = 160;
    /** So viele Karten liefert `list` höchstens MIT Text (Kartenansicht). */
    public const FULLTEXT_MAX = 60;
    public const ATTACH_MAX   = 5;
    /** Wie tief Ordner ineinander liegen dürfen: Mitglied → Seite. */
    public const DEPTH_MAX    = 2;

    /** @return array{v:int,rev:int,migratedAt:int,folders:list<array>,notes:list<array>,blocked:list<array>} */
    public static function Leer(): array
    {
        /* `migratedAt` ist der Merker des Umzugs — und er liegt IM Bestand, nicht
           in einem eigenen Attribut. Ein Attribut kann von seinem Bestand
           abdriften (Rücksicherung, halber Schreibvorgang); ein Stempel im
           Bestand kann das nicht. */
        return ['v' => 1, 'rev' => 0, 'migratedAt' => 0, 'folders' => [], 'notes' => [], 'blocked' => []];
    }

    /** Einen gelesenen Bestand in Form bringen — fehlende Listen sind leer. */
    public static function Formen(mixed $roh): array
    {
        if (!is_array($roh)) {
            return self::Leer();
        }
        foreach (['folders', 'notes', 'blocked'] as $k) {
            if (!isset($roh[$k]) || !is_array($roh[$k])) {
                $roh[$k] = [];
            }
        }
        $roh['v']   = (int)($roh['v'] ?? 1);
        $roh['rev'] = (int)($roh['rev'] ?? 0);
        $roh['migratedAt'] = (int)($roh['migratedAt'] ?? 0);
        return $roh;
    }

    public static function Kappen(string $text, int $max): string
    {
        return mb_substr(trim($text), 0, $max);
    }

    /** Stelle eines Datensatzes mit dieser Kennung, oder -1. */
    public static function StelleVon(array $rows, string $id): int
    {
        if ($id === '') {
            return -1;
        }
        foreach ($rows as $i => $r) {
            if (is_array($r) && (string)($r['id'] ?? '') === $id) {
                return (int)$i;
            }
        }
        return -1;
    }

    /**
     * Alle Medien-Kennungen, die diese Karten belegen — Anhang UND Vorschaubild.
     *
     * Diese Liste entscheidet mit darüber, was der Medien-Aufräumer als verwaist
     * ansieht. Fehlt hier ein Vorschaubild, ist es beim nächsten Durchlauf weg.
     *
     * @return list<int>
     */
    public static function AnhangIds(array $karten): array
    {
        $ids = [];
        foreach ($karten as $n) {
            if (!is_array($n)) {
                continue;
            }
            foreach ((is_array($n['att'] ?? null) ? $n['att'] : []) as $a) {
                if (!is_array($a)) {
                    continue;
                }
                foreach ([(int)($a['id'] ?? 0), (int)($a['thumb'] ?? 0)] as $id) {
                    if ($id > 0) {
                        $ids[] = $id;
                    }
                }
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * Eine Karte für die Antwort. WEISSLISTE: was hier nicht steht, kommt in der
     * App nie an (dieselbe Falle wie beim `status` einer Stunde).
     */
    public static function KarteZeile(array $n, bool $mitText): array
    {
        $text = (string)($n['text'] ?? '');
        $row = [
            'id'        => (string)($n['id'] ?? ''),
            'folderId'  => (string)($n['folderId'] ?? ''),
            'title'     => (string)($n['title'] ?? ''),
            'att'       => array_values(array_map(static function (array $a): array {
                $raus = [
                    'id'    => (int)($a['id'] ?? 0),
                    'kind'  => (string)($a['kind'] ?? ''),
                    'name'  => (string)($a['name'] ?? ''),
                    'bytes' => (int)($a['bytes'] ?? 0),
                ];
                if ((int)($a['thumb'] ?? 0) > 0) {
                    $raus['thumb'] = (int)$a['thumb'];
                }
                /* Adresse aus einem QR-Code im Bild. Nur wenn wirklich einer
                   drin war: das leere Feld ist nur der Merker, dass schon
                   nachgesehen wurde, und geht die App nichts an. */
                if (trim((string)($a['qr'] ?? '')) !== '') {
                    $raus['qr'] = (string)$a['qr'];
                }
                return $raus;
            }, is_array($n['att'] ?? null) ? array_filter($n['att'], 'is_array') : [])),
            'updatedAt' => (int)($n['updatedAt'] ?? 0),
            /* Bleibt drin, obwohl in diesem Bestand alles von der Klassenseite
               kommt: gemeinsame Client-Funktionen (Anhang öffnen, Bild
               nachladen) lesen es, und ein Sonderfall dort wäre teurer als ein
               Feld hier. */
            'source'    => 'edumaps',
        ];
        if (($n['section'] ?? '') !== '') {
            $row['section'] = (string)$n['section'];
        }
        if (isset($n['pos'])) {
            $row['pos'] = (int)$n['pos'];
        }
        /* Die formatierte Fassung geht nur mit dem VOLLTEXT heraus: sie ist so
           lang wie er, und in der Übersicht liest sie niemand. */
        if ($mitText && ($n['html'] ?? '') !== '') {
            $row['html'] = (string)$n['html'];
        }
        foreach (['sectionColor', 'color'] as $feld) {
            if (($n[$feld] ?? '') !== '') {
                $row[$feld] = (string)$n[$feld];
            }
        }
        /* Buchungslage einer buchbaren Karte (AG-Wahl, Sprechtag) und der Weg
           zur Karte auf der Seite. Gebucht wird DORT — deshalb reist der
           Verweis mit. */
        if (is_array($n['booking'] ?? null) && (int)($n['booking']['limit'] ?? 0) > 0) {
            $row['booking'] = [
                'count' => (int)($n['booking']['anzahl'] ?? 0),
                'limit' => (int)$n['booking']['limit'],
                'price' => (float)($n['booking']['preis'] ?? 0),
                'time'  => (string)($n['booking']['zeit'] ?? ''),
            ];
        }
        if (($n['srcUrl'] ?? '') !== '') {
            $row['srcUrl'] = (string)$n['srcUrl'];
        }
        /* Archiviert: die Karte gibt es auf der Seite nicht mehr. Sie bleibt im
           Bestand, zeigt sich aber nur noch im eingeklappten Archiv. */
        if ((int)($n['archived'] ?? 0) > 0) {
            $row['archived'] = (int)$n['archived'];
        }
        if ($mitText) {
            $row['text'] = $text;
        } else {
            $row['preview'] = mb_substr(preg_replace('/\s+/u', ' ', $text) ?? '', 0, self::PREVIEW_MAX);
        }
        return $row;
    }

    /**
     * Die Adresse der Klassenseite aus den Karten eines Ordners.
     *
     * Am Ordner steht nur der Schlüssel („edupage:<md5>") — die Adresse selbst
     * trägt jede Karte als `srcUrl` („<Seite>#box-<Karte>"). Der Teil vor der
     * Raute ist die Seite. Gebraucht wird sie für die Sperrliste im Formular.
     *
     * @param list<array<string,mixed>> $karten
     */
    public static function SeitenUrl(array $karten): string
    {
        foreach ($karten as $n) {
            if (!is_array($n)) {
                continue;
            }
            $u = (string)($n['srcUrl'] ?? '');
            if ($u === '') {
                continue;
            }
            $ohneAnker = explode('#', $u)[0];
            if (preg_match('#^https?://#i', $ohneAnker) === 1) {
                return rtrim($ohneAnker, '/');
            }
        }
        return '';       // Karten aus der Zeit vor srcUrl — dann zählt der Schlüssel
    }

    /**
     * Anzahl je Ordner, Unterordner mitgezählt.
     *
     * Aus einer KOPIE gezählt: die Summe wächst während der Schleife, und wer
     * den Wert von dort nimmt, addiert das schon Hochgezählte ein zweites Mal
     * weiter nach oben (im Notizen-Bestand einmal genau so passiert).
     *
     * @return array<string,int>
     */
    public static function Zaehlen(array $store): array
    {
        $zahl = [];
        foreach ($store['notes'] as $n) {
            if (!is_array($n)) {
                continue;
            }
            $f = (string)($n['folderId'] ?? '');
            $zahl[$f] = ($zahl[$f] ?? 0) + 1;
        }
        $eigen = $zahl;
        foreach ($store['folders'] as $f) {
            if (!is_array($f)) {
                continue;
            }
            $eigene = (int)($eigen[(string)($f['id'] ?? '')] ?? 0);
            if ($eigene === 0) {
                continue;
            }
            $eltern = (string)($f['parentId'] ?? '');
            $tiefe = 0;
            while ($eltern !== '' && $tiefe < self::DEPTH_MAX) {
                $zahl[$eltern] = ($zahl[$eltern] ?? 0) + $eigene;
                $i = self::StelleVon($store['folders'], $eltern);
                $eltern = $i < 0 ? '' : (string)($store['folders'][$i]['parentId'] ?? '');
                $tiefe++;
            }
        }
        return $zahl;
    }

    /**
     * Der UMZUG: Klassenseiten aus dem Notizen-Bestand in den eigenen holen.
     *
     * Reine Rechnung — sie schreibt nichts. Der Aufrufer schreibt erst den neuen
     * Bestand, liest ihn zurück und räumt DANN den alten auf; scheitert etwas
     * dazwischen, sind die Notizen unangetastet.
     *
     * Drei Regeln, und jede hat einen Fall hinter sich:
     *
     *  1. **Kennungen bleiben.** Ordner und Karten ziehen mit ihrer `id` um.
     *     Damit bleiben `folderId` und alle Medien-Verweise gültig — es zieht
     *     kein einziges Medienobjekt mit.
     *  2. **Eine Ebene fällt weg.** Heute: Mitglied → „Edumaps" → Seite. Der
     *     „Edumaps"-Ordner WIRD zum Mitglieder-Ordner des eigenen Bestands
     *     (trägt `memberId`, heißt wie das Mitglied); die Seitenordner behalten
     *     ihn als Elternteil. Die Zwischenebene war nur nötig, weil die Karten
     *     in fremdem Haus lagen.
     *  3. **Was keine Karte ist, bleibt eine Notiz.** Eine von Hand in einen
     *     Klassenseiten-Ordner geschriebene Notiz (kein `srcId`) zieht NICHT
     *     mit: sie wird an den nächsten überlebenden Vorfahren gehängt. Findet
     *     sich keiner, bleibt ihr Ordner in den Notizen stehen — lieber ein
     *     übriger Ordner als eine unerreichbare Notiz.
     *
     * @param array $notizen Notizen-Bestand (mit `folders`, `notes`, `eduBlocked`)
     * @param array<string,string> $mitglieder id => Name, für die Ordnernamen
     * @return array{edu:array,notes:array,stat:array<string,int>}
     */
    public static function Umzug(array $notizen, array $mitglieder, int $jetzt): array
    {
        $ordner = is_array($notizen['folders'] ?? null) ? array_values(array_filter($notizen['folders'], 'is_array')) : [];
        $karten = is_array($notizen['notes'] ?? null) ? array_values(array_filter($notizen['notes'], 'is_array')) : [];

        // Welche Ordner gehören der Klassenseite? Am eduKey erkannt, nie am Namen.
        $wurzel = [];      // eduKey 'edu:<userId>'      → Stelle
        $seiten = [];      // eduKey 'edupage:<md5>'     → Stelle
        foreach ($ordner as $i => $f) {
            $key = (string)($f['eduKey'] ?? '');
            if ($key === '') {
                continue;
            }
            if (str_starts_with($key, 'edupage:')) {
                $seiten[$i] = $key;
            } else {
                $wurzel[$i] = $key;
            }
        }
        $zieht = array_values(array_unique(array_merge(array_keys($wurzel), array_keys($seiten))));
        if ($zieht === []) {
            return ['edu' => self::Leer(), 'notes' => $notizen,
                    'stat' => ['ordner' => 0, 'karten' => 0, 'notizen' => 0, 'verwaist' => 0, 'gesperrt' => 0]];
        }

        $ziehtIds = [];
        foreach ($zieht as $i) {
            $ziehtIds[(string)$ordner[$i]['id']] = true;
        }

        /* Der nächste Vorfahre, der NICHT mitzieht — dorthin gehen Notizen ohne
           Karten-Kennung. In der heutigen Form ist das der Mitglieder-Ordner. */
        $ueberlebenderVorfahr = static function (string $fid) use ($ordner, $ziehtIds): string {
            $tiefe = 0;
            while ($fid !== '' && $tiefe < 8) {
                $i = self::StelleVon($ordner, $fid);
                if ($i < 0) {
                    return '';
                }
                $eltern = (string)($ordner[$i]['parentId'] ?? '');
                if ($eltern === '') {
                    return '';
                }
                if (!isset($ziehtIds[$eltern])) {
                    return $eltern;
                }
                $fid = $eltern;
                $tiefe++;
            }
            return '';
        };

        // ── Karten und Fremdlinge trennen ───────────────────────────────────
        $eduKarten = [];
        $bleibtKarten = [];
        $verwaisteOrdner = [];      // Ordner, die wegen einer Handnotiz bleiben müssen
        $umgehaengt = 0;
        foreach ($karten as $n) {
            $fid = (string)($n['folderId'] ?? '');
            if (!isset($ziehtIds[$fid])) {
                $bleibtKarten[] = $n;
                continue;
            }
            if (str_starts_with((string)($n['srcId'] ?? ''), 'edu:')) {
                $eduKarten[] = $n;
                continue;
            }
            // Keine Karte, sondern eine Notiz in fremdem Ordner.
            $ziel = $ueberlebenderVorfahr($fid);
            if ($ziel !== '') {
                $n['folderId'] = $ziel;
                $n['updatedAt'] = $jetzt;
                $umgehaengt++;
                $bleibtKarten[] = $n;
                continue;
            }
            $verwaisteOrdner[$fid] = true;
            $bleibtKarten[] = $n;
        }

        // ── Ordner umbauen ──────────────────────────────────────────────────
        $eduOrdner = [];
        $bleibtOrdner = [];
        foreach ($ordner as $i => $f) {
            $fid = (string)$f['id'];
            if (!isset($ziehtIds[$fid]) || isset($verwaisteOrdner[$fid])) {
                $bleibtOrdner[] = $f;
                continue;
            }
            $key = (string)($f['eduKey'] ?? '');
            if (isset($wurzel[$i])) {
                /* Aus dem „Edumaps"-Ordner wird der Mitglieder-Ordner: oberste
                   Ebene, mit Mitglied und dessen Namen. Ist das Mitglied
                   unbekannt, bleibt der alte Name — ein Ordner ohne Namen wäre
                   schlimmer als einer mit dem alten. */
                $uid = substr($key, strlen('edu:'));
                $name = (string)($mitglieder[$uid] ?? '');
                $eduOrdner[] = [
                    'id'        => $fid,
                    'name'      => self::Kappen($name !== '' ? $name : (string)($f['name'] ?? ''), self::TITLE_MAX),
                    'parentId'  => '',
                    'memberId'  => $uid,
                    'eduKey'    => $key,
                    'createdAt' => (int)($f['createdAt'] ?? $jetzt),
                    'updatedAt' => $jetzt,
                ];
                continue;
            }
            /* Seitenordner: das Elternteil zieht mit, also bleibt der Verweis.
               Zieht es nicht mit (weil es wegen einer Handnotiz bleiben muss),
               steht die Seite auf der obersten Ebene — erreichbar ist wichtiger
               als sortiert. */
            $eltern = (string)($f['parentId'] ?? '');
            $eduOrdner[] = [
                'id'        => $fid,
                'name'      => self::Kappen((string)($f['name'] ?? ''), self::TITLE_MAX),
                'parentId'  => (isset($ziehtIds[$eltern]) && !isset($verwaisteOrdner[$eltern])) ? $eltern : '',
                'memberId'  => '',
                'eduKey'    => $key,
                'createdAt' => (int)($f['createdAt'] ?? $jetzt),
                'updatedAt' => $jetzt,
            ];
        }

        /* Karten, deren Ordner doch geblieben ist, ziehen auch nicht mit —
           sonst zeigte ihr folderId in den anderen Bestand. */
        $wirklich = [];
        $eduIds = [];
        foreach ($eduOrdner as $f) {
            $eduIds[(string)$f['id']] = true;
        }
        foreach ($eduKarten as $n) {
            if (isset($eduIds[(string)($n['folderId'] ?? '')])) {
                $wirklich[] = $n;
            } else {
                $bleibtKarten[] = $n;
            }
        }

        $edu = self::Leer();
        $edu['folders'] = $eduOrdner;
        $edu['notes']   = $wirklich;
        $edu['blocked'] = is_array($notizen['eduBlocked'] ?? null)
            ? array_values(array_filter($notizen['eduBlocked'], 'is_array')) : [];

        $neuNotizen = $notizen;
        $neuNotizen['folders'] = $bleibtOrdner;
        $neuNotizen['notes']   = $bleibtKarten;
        unset($neuNotizen['eduBlocked']);

        return [
            'edu'   => $edu,
            'notes' => $neuNotizen,
            'stat'  => [
                'ordner'   => count($eduOrdner),
                'karten'   => count($wirklich),
                'notizen'  => count($bleibtKarten),
                'verwaist' => count($verwaisteOrdner),
                'umgehaengt' => $umgehaengt,
                'gesperrt' => count($edu['blocked']),
            ],
        ];
    }
}

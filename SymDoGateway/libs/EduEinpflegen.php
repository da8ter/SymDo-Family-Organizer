<?php

declare(strict_types=1);

/**
 * Klassenseiten einpflegen — die GATEWAY-Haelfte des Laufs.
 *
 * Getrennt vom Lesen, weil beide Haelften bald in verschiedenen Spuren laufen:
 * Holen, Zerlegen und Auswerten einer Seite dauert Minuten und zieht in eine
 * eigene SymDoScanner-Instanz um; Einpflegen dauert Millisekunden und bleibt
 * hier, weil hier der Bestand liegt und die Hooks ihn lesen.
 *
 * Die eine Regel, an der alles haengt: **EIN Schreibvorgang je SEITE.**
 *
 * Vorher schrieb jede Karte den ganzen Bestand — bei 55 Karten also 55 Mal bis
 * zu ein Megabyte, jedes Mal serialisiert, in der Spur, die gleichzeitig die
 * App bedient. Und jeder dieser Schreibvorgaenge nahm die Sperre neu: zwischen
 * zwei Karten derselben Seite konnte ein anderer Zugriff dazwischenfahren und
 * einen halb gespiegelten Stand sehen.
 *
 * Was daran haengt und deshalb hier steht:
 *
 *  - **Die Medienbilanz.** Neue Anhaenge entstehen VOR dem Schreiben. Schlaegt
 *    das Schreiben fehl, gehoeren sie niemandem mehr und muessen weg — alle,
 *    nicht die der letzten Karte.
 *  - **Die alten Anhaenge.** Sie duerfen erst NACH dem Schreiben verschwinden,
 *    und nur, wenn sie wirklich niemand mehr nennt.
 */
trait EduEinpflegen
{
    /**
     * Eine ganze Seite in den Bestand — Sperre, Lesen und Schreiben genau einmal.
     *
     * @param array{name:string,url:string,userId:string} $seite
     * @param list<array<string,mixed>>                   $karten
     * @return int wie viele Karten den VOLLEN Satz bekommen haben
     */
    private function EduSeiteSpiegeln(array $seite, array $karten): int
    {
        if (!(bool)$this->EduProp('EduToNotes', false)) {
            return 0;
        }
        if (!$this->EduStorable()) {
            $this->SendDebug('EduMaps', 'Klassenseiten-Bestand nicht beschreibbar — Kernel-Neustart nötig', 0);
            return 0;
        }
        $lock = self::EDU_LOCK . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 0)) {
            $this->SendDebug('EduMaps', 'Bestand belegt — Seite beim naechsten Lauf', 0);
            return 0;
        }
        try {
            $store = $this->EduStoreRead();
            $this->eduOrdnerGeaendert = false;
            $ordnerId = $this->EduOrdner($store, $seite);
            if ($ordnerId === '') {
                return 0;
            }

            $neueMedien = [];   // in DIESEM Lauf angelegt — bei Fehlschlag weg
            $alteMedien = [];   // ersetzt — nach dem Schreiben pruefen
            $gespiegelt = 0;
            $geaendert  = $this->eduOrdnerGeaendert;

            foreach ($karten as $nr => $karte) {
                $erg = $this->EduKarteEinpflegen($store, $seite, $karte, (int)$nr, $ordnerId,
                    $neueMedien, $alteMedien);
                if ($erg === 'voll') {
                    $gespiegelt++;
                }
                if ($erg !== 'nichts') {
                    $geaendert = true;
                }
            }

            if (!$geaendert) {
                return 0;
            }
            if (!$this->EduWriteStore($store)) {
                /* Die in diesem Lauf angelegten Medien gehoeren jetzt niemandem.
                   ALLE, nicht nur die der letzten Karte — das war der Grund, den
                   Schreibvorgang ueberhaupt hierher zu heben. */
                if ($neueMedien !== []) {
                    $this->NotesDeleteMedia($neueMedien);
                }
                return 0;
            }
            $this->eduOrdnerGeaendert = false;

            /* ERST der Bestand, DANN die alten Medien — und nur, was weder eine
               Notiz noch eine andere Karte noch ein offener Vorschlag nennt.
               Uebergeben wird der NOTIZEN-Bestand: die Klassenseiten liest
               NotesUnreferencedMedia selbst frisch dazu. */
            if ($alteMedien !== []) {
                $this->NotesDeleteMedia($this->NotesUnreferencedMedia($this->NotesStore(),
                    array_values(array_unique($alteMedien))));
            }
            return $gespiegelt;
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    /**
     * Eine Karte in den schon gelesenen Bestand — ohne Sperre, ohne Schreiben.
     *
     * @param array<string,mixed>       $store      wird veraendert
     * @param array<string,mixed>       $seite
     * @param array<string,mixed>       $karte
     * @param list<int>                 $neueMedien wird ergaenzt
     * @param list<int>                 $alteMedien wird ergaenzt
     * @return string 'voll' | 'nachzug' | 'nichts'
     */
    private function EduKarteEinpflegen(array &$store, array $seite, array $karte, int $nr,
        string $ordnerId, array &$neueMedien, array &$alteMedien): string
    {
        $srcId = 'edu:' . (string)($karte['boxid'] ?? '');
        $i = -1;
        foreach ($store['notes'] as $k => $n) {
            if ((string)($n['srcId'] ?? '') === $srcId) {
                $i = (int)$k;
                break;
            }
        }
        $alt = $i >= 0 ? $store['notes'][$i] : null;

        /* Unveraendert? Dann die Anhaenge NICHT anfassen — kein Laden, keine
           neuen Medien. Was trotzdem fehlen kann, traegt der Nachzug nach. */
        if (EduStoreCalc::Bedarf($alt, $karte) === 'nachzug') {
            [$satz, $geaendert] = $this->EduNachzug($alt, $seite, $karte, $nr, $ordnerId);
            if (!$geaendert) {
                return 'nichts';
            }
            $store['notes'][$i] = $satz;
            return 'nachzug';
        }

        if ($i < 0 && count($store['notes']) >= EduStoreCalc::KARTEN_MAX) {
            $this->SendDebug('EduMaps', 'Kartengrenze erreicht — Karte nicht gespiegelt: '
                . (string)($karte['titel'] ?? ''), 0);
            return 'nichts';
        }

        $text = $this->EduNotizText($karte);
        if (mb_strlen($text) > EduStoreCalc::TEXT_MAX) {
            /* Gekuerzt wird SICHTBAR. Eine still gekappte Notiz waere schlimmer
               als eine fehlende — man sieht ihr nicht an, dass die Haelfte fehlt. */
            $text = mb_substr($text, 0, EduStoreCalc::TEXT_MAX - 40) . "\n\n… (gekürzt)";
        }
        foreach ($alt !== null ? EduStoreCalc::AnhangIds([$alt]) : [] as $mid) {
            $alteMedien[] = (int)$mid;
        }
        $anhaenge = $this->EduNotizAnhaenge($karte);
        foreach (EduStoreCalc::AnhangIds([['att' => $anhaenge]]) as $mid) {
            $neueMedien[] = (int)$mid;
        }

        $satz = EduStoreCalc::SatzBauen($alt, $karte, $nr, $ordnerId,
            (string)$seite['url'], $text, $anhaenge,
            $alt === null ? $this->NotesNewId() : '', time());

        if ($i >= 0) {
            $store['notes'][$i] = $satz;
        } else {
            $store['notes'][] = $satz;
        }
        return 'voll';
    }

    /**
     * Der Nachzug einer unveraenderten Karte — das Teure davor, der Rest im
     * Rechenkern.
     *
     * @return array{0:array<string,mixed>,1:bool}
     */
    private function EduNachzug(array $alt, array $seite, array $karte, int $nr, string $ordnerId): array
    {
        $att = is_array($alt['att'] ?? null) ? $alt['att'] : [];
        /* Die Anhaenge der KARTE in derselben Ordnung wie die abgelegten:
           gefiltert nach denen, die ueberhaupt eine Datei sind. Zugeordnet wird
           ueber die Reihenfolge. */
        $kartenDateien = array_values(array_filter((array)$karte['anhaenge'],
            fn(array $a): bool => $this->EduArt((string)($a['datei'] ?? $a['name'])) !== ''));
        $namen = array_map(static fn(array $a): string => (string)$a['name'], $kartenDateien);

        /* Das Teure: Vorschaubild und QR-Code. Beides holt EINMAL je Anhang und
           nur dort, wo es fehlt — der Rechenkern bekommt nur die Ergebnisse. */
        $thumbs = [];
        if (count($att) === count($kartenDateien)) {
            foreach ($att as $k => $a) {
                if ((string)($a['kind'] ?? '') !== 'pdf' || (int)($a['thumb'] ?? 0) > 0) {
                    continue;
                }
                $mini = $this->EduVorschau((string)($kartenDateien[$k]['preview'] ?? ''),
                    (string)$a['name']);
                if ($mini > 0) {
                    $thumbs[$k] = $mini;
                }
            }
        }
        $qr = [];
        foreach ($att as $k => $a) {
            if (array_key_exists('qr', $a)) {
                /* Schon gelesen — aber der Fund gehoert trotzdem in die
                   Fundliste, sonst kaeme er nach dem ersten Lauf nie an. */
                $this->EduQrVormerken((string)$a['qr']);
                continue;
            }
            /* Das GERADE geholte Vorschaubild zaehlt mit. Ohne `$thumbs[$k]`
               bekaeme ein PDF, dessen Vorschau in diesem Lauf entstanden ist,
               die Quelle 0 — und damit ein leeres Ergebnis, das als „geprueft"
               vermerkt und nie wieder angefasst wird. Der QR-Code auf diesem
               Elternbrief waere fuer immer verloren. */
            $quelle = (string)($a['kind'] ?? '') === 'image'
                ? (int)($a['id'] ?? 0)
                : (int)($thumbs[$k] ?? $a['thumb'] ?? 0);
            $qr[$k] = $this->EduQrCode($quelle);
        }

        return EduStoreCalc::NachzugRechnen($alt, $karte, $nr, $ordnerId,
            (string)$seite['url'], $this->EduNotizText($karte), $namen, $thumbs, $qr);
    }
}

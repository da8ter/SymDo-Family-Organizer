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
 * Die Regel, an der alles haengt: **Sperre und Lesen einmal je SEITE,
 * geschrieben in Schueben.**
 *
 * Vorher schrieb jede Karte den ganzen Bestand — bei 55 Karten also 55 Mal bis
 * zu ein Megabyte, jedes Mal serialisiert, in der Spur, die gleichzeitig die
 * App bedient. Und jeder dieser Schreibvorgaenge nahm die Sperre neu: zwischen
 * zwei Karten derselben Seite konnte ein anderer Zugriff dazwischenfahren und
 * einen halb gespiegelten Stand sehen.
 *
 * Genau EIN Schreibvorgang je Seite waere aber zu weit gegangen — das war der
 * erste Anlauf, und er hatte zwei Loecher, die der alte Weg nicht hatte:
 *
 *  - **Alles oder nichts.** Schlug der eine Schreibvorgang fehl (der Bestand
 *    ist bei einem Megabyte gedeckelt, und ein Speicherlimit kann den Prozess
 *    mitten in der Seite toeten), war die GANZE Seite verloren — und bei jedem
 *    weiteren Lauf aufs Neue. Der alte Weg behielt, was bis dahin geschrieben
 *    war.
 *  - **Der Medien-Deckel.** Siehe `EDU_MEDIEN_SCHUB`.
 *
 * Deshalb: sammeln, und festschreiben, sobald zu viele neue Medienobjekte auf
 * ihre Freigabe warten. Eine Seite aus Textkarten kostet weiterhin genau einen
 * Schreibvorgang.
 *
 * Was daran haengt und deshalb hier steht:
 *
 *  - **Die Medienbilanz.** Neue Anhaenge entstehen VOR dem Schreiben. Schlaegt
 *    das Schreiben fehl, gehoeren sie niemandem mehr und muessen weg — alle des
 *    Schubs, nicht nur die der letzten Karte, und die Vorschaubilder des
 *    Nachzugs gehoeren dazu.
 *  - **Die alten Anhaenge.** Sie duerfen erst NACH dem Schreiben verschwinden,
 *    und nur, wenn sie wirklich niemand mehr nennt.
 */
trait EduEinpflegen
{
    /**
     * So viele frisch angelegte Medienobjekte duerfen hoechstens auf einen
     * Schreibvorgang warten.
     *
     * Der Deckel der Notizen-Kategorie liegt bei 300 Objekten
     * (`NotesMedia::NOTES_MEDIA_MAX`), und die ERSETZTEN werden erst frei,
     * wenn der Bestand geschrieben ist — vorher nennt ihn ja noch die alte
     * Fassung der Notiz. Zwischen Anlegen und Freigeben stehen also beide da.
     *
     * Der alte Weg schrieb je Karte und hielt diesen Ueberhang damit bei
     * hoechstens einer Karte. Wer eine ganze Seite sammelt, braucht im
     * Grenzfall 60 Karten x 5 Anhaenge x 2 (Datei + Vorschau) = 600 Objekte
     * gegen einen Deckel von 300 — und was dann nicht mehr abgelegt werden
     * kann, faellt STILL weg: die Karte wird mit leerem `att`, aber neuem
     * `srcRev` geschrieben, gilt fortan als unveraendert, und ihre alten
     * Anhaenge werden als unreferenziert geloescht. Die Datei ist dann weg.
     *
     * Deshalb wird nach Medien-DRUCK geschoben, nicht nach Kartenzahl: eine
     * Seite aus Textkarten kostet weiterhin genau einen Schreibvorgang, eine
     * Seite voller Elternbriefe ein paar mehr.
     */
    private const EDU_MEDIEN_SCHUB = 20;

    /**
     * Eine ganze Seite in den Bestand — Sperre und Lesen genau einmal,
     * geschrieben in Schueben.
     *
     * @param array{name:string,url:string,userId:string} $seite
     * @param list<array<string,mixed>>                   $karten
     * @return int wie viele Karten den VOLLEN Satz bekommen haben UND
     *             tatsaechlich im Bestand stehen
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

            $neueMedien = [];   // in DIESEM Schub angelegt — bei Fehlschlag weg
            $alteMedien = [];   // ersetzt — nach dem Schreiben pruefen
            $gespiegelt = 0;    // 'voll' im Speicher
            $sicher     = 0;    // 'voll' und geschrieben
            $offen      = $this->eduOrdnerGeaendert;

            foreach ($karten as $nr => $karte) {
                $erg = $this->EduKarteEinpflegen($store, $seite, $karte, (int)$nr, $ordnerId,
                    $neueMedien, $alteMedien);
                if ($erg === 'voll') {
                    $gespiegelt++;
                }
                if ($erg !== 'nichts') {
                    $offen = true;
                }
                /* Schieben, sobald zu viele neue Medien auf ihre Freigabe
                   warten — sonst reisst der Deckel der Kategorie. */
                if (count($neueMedien) >= self::EDU_MEDIEN_SCHUB) {
                    if (!$this->EduSchubSchreiben($store, $neueMedien, $alteMedien)) {
                        return $sicher;
                    }
                    $sicher = $gespiegelt;
                    $offen  = false;
                }
            }

            if ($offen && !$this->EduSchubSchreiben($store, $neueMedien, $alteMedien)) {
                return $sicher;
            }
            return $gespiegelt;
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    /**
     * Einen Schub festschreiben: Bestand schreiben, dann die ersetzten Medien
     * freigeben.
     *
     * Die Reihenfolge ist keine Kosmetik. ERST der Bestand, DANN die alten
     * Medien — und nur, was weder eine Notiz noch eine andere Karte noch ein
     * offener Vorschlag nennt. Umgekehrt stuende nach einem Fehlschlag eine
     * Notiz da, deren Anhang geloescht ist.
     *
     * @param array<string,mixed> $store      wird veraendert (rev, Ordnung)
     * @param list<int>           $neueMedien wird geleert
     * @param list<int>           $alteMedien wird geleert
     */
    private function EduSchubSchreiben(array &$store, array &$neueMedien, array &$alteMedien): bool
    {
        if (!$this->EduWriteStore($store)) {
            /* Nicht still bleiben. EduWriteStore meldet nur den Fall „Attribut
               nicht speicherbar"; den Groessenriegel (STORE_MAX) meldet es
               nicht, und der Lauf zaehlte danach einfach null gespiegelte
               Karten — von aussen nicht von „nichts zu tun" zu unterscheiden. */
            $this->LogMessage('SymDo Klassenseiten: der Bestand liess sich nicht schreiben — '
                . 'die Seite bleibt beim vorigen Stand.', KL_WARNING);
            if ($neueMedien !== []) {
                // Die eben angelegten Medien gehoeren jetzt niemandem.
                $this->NotesDeleteMedia(array_values(array_unique($neueMedien)));
            }
            $neueMedien = [];
            $alteMedien = [];
            return false;
        }

        /* `EduWriteStore` nimmt den Bestand als WERT: es zaehlt `rev` in seiner
           eigenen Kopie hoch und raeumt dort die Listenordnung auf. Ohne das
           hier naechzuziehen schriebe der naechste Schub DIESELBE Revision —
           ein Geraet, das sich `rev` merkt, bekaeme ihn nie zu sehen. */
        $store['rev']     = (int)$store['rev'] + 1;
        $store['folders'] = array_values($store['folders']);
        $store['notes']   = array_values($store['notes']);
        $store['blocked'] = array_values($store['blocked']);
        $this->eduOrdnerGeaendert = false;
        $neueMedien = [];

        if ($alteMedien !== []) {
            /* Uebergeben wird der NOTIZEN-Bestand: die Klassenseiten liest
               NotesUnreferencedMedia selbst frisch dazu — also den Stand, den
               wir gerade geschrieben haben. */
            $this->NotesDeleteMedia($this->NotesUnreferencedMedia($this->NotesStore(),
                array_values(array_unique($alteMedien))));
            $alteMedien = [];
        }
        return true;
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
            [$satz, $geaendert] = $this->EduNachzug($alt, $seite, $karte, $nr, $ordnerId, $neueMedien);
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
     * @param list<int> $neueMedien wird um jedes frisch angelegte Vorschaubild ergaenzt
     * @return array{0:array<string,mixed>,1:bool}
     */
    private function EduNachzug(array $alt, array $seite, array $karte, int $nr, string $ordnerId,
        array &$neueMedien): array
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
                    /* AUCH das ist ein frisch angelegtes Medienobjekt. Es fehlte
                       hier: scheiterte danach der Schreibvorgang, blieben die
                       Vorschaubilder des Nachzugs herrenlos liegen und zaehlten
                       bis zum naechsten Waisen-Durchgang (zwei Tage Schonfrist)
                       gegen den Deckel der Kategorie. */
                    $neueMedien[] = $mini;
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

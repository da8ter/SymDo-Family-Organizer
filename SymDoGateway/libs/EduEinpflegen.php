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
     * So viele Verweise traegt ein Umschlag je Seite hoechstens. Die eigentliche
     * Grenze zieht `EduGefundeneAufnehmen` (EDU_GEFUNDEN_MAX ueber ALLE Seiten);
     * dieser Deckel haelt nur eine Datei klein, die von aussen kommt.
     */
    private const EDU_FUNDE_MAX = 50;

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
        if ($karten === [] || !$this->EduSpiegelnAn(EduStoreCalc::Quelle($karten[0]))) {
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
        $srcId = EduStoreCalc::SrcId($karte);
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

    // ------------------------------------------------------------------
    // Der Weg von aussen: ein Umschlag aus der Scanner-Spur
    // ------------------------------------------------------------------

    /**
     * Die `seiten` eines Umschlags einpflegen.
     *
     * Der Scanner holt und zerlegt, das Gateway pflegt ein. Diese Teilung ist
     * nicht frei gewaehlt, sondern von drei Dingen erzwungen:
     *
     *  - **Medienobjekte.** `NotesSaveAttachment` haengt sie unter die EIGENE
     *    Instanz, und `NotesDeleteMedia` loescht nur, was im eigenen
     *    Kategorie-Knoten haengt. Im Scanner angelegte Anhaenge waeren
     *    dauerhaft unaufraeumbare Leichen gegen eine Quote von 300.
     *  - **Die Sperre.** `EDU_LOCK` traegt die Instanzkennung im Namen. Ein
     *    Scanner naehme eine ANDERE Sperre und schloesse die Hooks des
     *    Gateways damit gar nicht aus.
     *  - **Die Sperrliste.** Sie steht als `blocked` IM Bestand, nicht in einem
     *    eigenen Attribut, und wird aus dem Hook geschrieben, wenn jemand in
     *    der App einen Seitenordner loescht. Nur hier ist sie aktuell.
     *
     * @param array<string,mixed> $umschlag der bereits geprueifte Umschlag
     * @return int wie viele Karten den vollen Satz bekommen haben
     */
    private function EduUmschlagEinpflegen(array $umschlag): int
    {
        // Was der Scanner gemeldet hat („12 Karten auf 4 Seiten gelesen").
        // Die vollstaendige Zeile entsteht unten, wenn auch feststeht, was aus
        // den Karten geworden ist.
        $text = trim((string)($umschlag['status']['text'] ?? ''));

        /* Der Knopf „Alles auswerten" reist mit dem Auftrag hinueber und mit dem
           Ergebnis zurueck (ScanKanalCalc haelt das Feld in seiner weissen
           Liste). Ohne ihn haette er nach der Uebergabe dieselbe Wirkung wie
           „Jetzt pruefen". */
        $alles = ($umschlag['alles'] ?? false) === true;

        $gespiegelt = 0;
        $geaendert  = 0;
        $analysiert = 0;
        $gedeckelt  = false;
        /* Ab hier wird gesammelt statt gemeldet — EINE Nachricht am Ende, genau
           wie im eigenen Lauf. Ohne das kaeme je Seite eine eigene Meldung. */
        $this->eduPushSammlung = [];
        foreach ((array)($umschlag['seiten'] ?? []) as $roh) {
            $eintrag = $this->EduUmschlagSeite($roh);
            if ($eintrag === null) {
                continue;
            }
            [$seite, $karten, $funde] = $eintrag;

            /* Die QR-Funde gehoeren zu DIESER Seite. Geleert wird VOR dem
               Spiegeln, denn gefuellt wird waehrenddessen: `EduQrCode` laeuft
               beim Anlegen eines Anhangs und beim Nachzug. Ohne das Leeren
               truege die naechste Seite die Funde der vorigen; ohne das
               Aufnehmen unten fuellte sich die Liste immer weiter und niemand
               holte sie ab — im Gateway-Weg tut das `EduSeiteLesen`, und das
               laeuft nach der Uebergabe nicht mehr. */
            $this->eduQrSeiten = [];

            /* Die Sperrliste gilt HIER, nicht dort. Zwischen dem Auftrag und
               seinem Ergebnis koennen Minuten liegen, und in dieser Zeit kann
               jemand die Seite in der App geloescht haben. Ohne diese Probe
               kaeme sie mit dem naechsten Umschlag zurueck. */
            if ($this->EduGesperrt((string)$seite['url'])) {
                $this->SendDebug('EduMaps', 'Seite ist gesperrt — Umschlag uebersprungen: '
                    . (string)$seite['name'], 0);
                continue;
            }

            $dieseSeite = $this->EduSeiteSpiegeln($seite, $karten);
            $gespiegelt += $dieseSeite;
            $this->EduPushKarten((string)($seite['userId'] ?? ''), (string)$seite['name'], $dieseSeite);
            $this->EduArchivAbgleichen($seite, $karten);

            /* Und die Auswertung. Sie gehoert HIERHER, nicht zum Scanner: den
               Prompt baut nur diese Instanz (sie hat den Bestand), der Merker
               `EduSeen` ist ihr Attribut, und die Tagesdeckel zaehlen bei ihr.
               Genau dieselbe Funktion wie im eigenen Lauf — sonst waeren es
               zwei Politiken, und die teurere faellt erst auf der Rechnung auf.

               `$analysiert` laeuft ueber ALLE Seiten dieses Umschlags: der
               Deckel je Lauf gilt fuer den Durchgang, nicht je Seite. */
            $aus = $this->EduKartenAuswerten($seite, $karten, $analysiert, $alles);
            $geaendert  += $aus['geaendert'];
            $analysiert += $aus['analysiert'];
            $gedeckelt   = $gedeckelt || $aus['gedeckelt'];

            /* Verweise aus dem Seitenrumpf UND Adressen aus QR-Codes muenden in
               dieselbe Aufnahme — sie prueft den Schalter, den Deckel und ob
               sich die Adresse ueberhaupt abrufen laesst. */
            $adressen = array_values(array_unique(array_merge($funde, $this->eduQrSeiten)));
            $this->eduQrSeiten = [];
            if ($adressen !== []) {
                $neu = $this->EduGefundeneAufnehmen($seite, $adressen);
                if ($neu > 0) {
                    $this->SendDebug('EduMaps', sprintf('%d verlinkte Anlage(n) aufgenommen (%s)',
                        $neu, (string)$seite['name']), 0);
                }
            }
        }

        /* Die Statuszeile gehoert ins Attribut DIESER Instanz: das
           Konfigurationsformular liest sie hier. Schriebe der Scanner sie bei
           sich, stuende dort dauerhaft „Noch nicht nachgesehen". Was er
           geschickt hat, sagt nur „gelesen" — was daraus wurde, weiss erst
           diese Haelfte, und der Nutzer will beide Zahlen sehen.
           Der Klammeraffe, weil das Attribut nach einem Modul-Reload ohne
           Kernel-Neustart noch nicht registriert ist — Symcon wirft dann nicht,
           es WARNT, und eine Warnung zerlegt im Hook die HTTP-Antwort. */
        $teile = [];
        if ($text !== '') {
            $teile[] = $text;
        }
        $teile[] = sprintf($this->Translate('%1$d changed, %2$d analysed.'), $geaendert, $analysiert);
        if ($gespiegelt > 0) {
            $teile[] = sprintf($this->Translate('%d card(s) mirrored.'), $gespiegelt);
        }
        if ($gedeckelt) {
            $teile[] = $this->Translate('daily AI limit reached — the rest follows later');
        }
        @$this->WriteAttributeString('EduStatus', (string)json_encode(
            ['t' => time(), 'text' => implode(' ', $teile)], JSON_UNESCAPED_UNICODE));

        /* Und jetzt die eine Nachricht. Wartet noch ein Auswerte-Auftrag, legt
           `EduPushAbschluss` den Stand beiseite und der letzte fertige Auftrag
           schickt alles zusammen. */
        $this->EduPushAbschluss();
        return $gespiegelt;
    }

    /**
     * Eine Seite aus dem Umschlag pruefen und in Form bringen.
     *
     * Der Umschlag kommt als DATEI von einer fremden Instanz. Die weisse Liste
     * von `ScanKanalCalc` prueft nur, dass `seiten` eine Liste von Objekten ist
     * — was DRIN steht, prueft niemand. Eine Karte ohne `boxid` legte eine
     * Notiz mit der Kennung `edu:` an; beim naechsten Umschlag faende sie sich
     * selbst wieder und ueberschriebe sich gegenseitig.
     *
     * @return array{0:array<string,mixed>,1:list<array<string,mixed>>,2:list<string>}|null
     */
    private function EduUmschlagSeite(mixed $roh): ?array
    {
        if (!is_array($roh)) {
            return null;
        }
        $s = is_array($roh['seite'] ?? null) ? $roh['seite'] : [];
        $url = trim((string)($s['url'] ?? ''));
        /* Nur die FORM, kein Namensdienst. Zwei Gruende: eine DNS-Abfrage je
           Seite laegen in der Gateway-Spur, und der eigentliche Riegel gegen
           Rufe ins eigene Netz sitzt ohnehin am Abruf selbst — `AiFetchPublicPage`
           prueft JEDE Weiterleitung einzeln.
           Das Schema muss trotzdem hier geprueft werden: die Adresse wird als
           `srcUrl` an der Karte abgelegt, und die Oberflaeche macht daraus
           einen Verweis. Ein `javascript:` darin waere fremder Code in der App. */
        $teile  = parse_url($url);
        $schema = strtolower((string)($teile['scheme'] ?? ''));
        if ($url === '' || !in_array($schema, ['http', 'https'], true)
            || trim((string)($teile['host'] ?? '')) === '') {
            $this->SendDebug('EduMaps', 'Umschlag ohne brauchbare Adresse — uebersprungen', 0);
            return null;
        }
        $seite = [
            'name'   => EduStoreCalc::Kappen(trim((string)($s['name'] ?? '')), EduStoreCalc::TITLE_MAX),
            'url'    => $url,
            'userId' => trim((string)($s['userId'] ?? '')),
        ];

        /* Verweise auf ANDERE Anlagen, vom Scanner aus dem rohen Rumpf geholt.
           Aufgenommen werden sie hier: die Fundliste ist ein Attribut DIESER
           Instanz, und hier haengt auch der Schalter „Verlinkten Seiten folgen"
           samt Deckel und Erreichbarkeitsprobe. */
        $funde = [];
        foreach ((array)($roh['funde'] ?? []) as $f) {
            $a = trim((string)$f);
            $t = parse_url($a);
            if (in_array(strtolower((string)($t['scheme'] ?? '')), ['http', 'https'], true)
                && trim((string)($t['host'] ?? '')) !== '') {
                $funde[] = mb_substr($a, 0, 2000);
            }
            if (count($funde) >= self::EDU_FUNDE_MAX) {
                break;
            }
        }

        $karten = [];
        foreach ((array)($roh['karten'] ?? []) as $k) {
            $karte = $this->EduUmschlagKarte($k);
            if ($karte !== null) {
                $karten[] = $karte;
            }
            if (count($karten) >= self::EDU_KARTEN_MAX) {
                /* Derselbe Deckel, den `EduKarten` beim Zerlegen zieht. Ohne ihn
                   brächte ein Umschlag beliebig viele Karten mit — und jede
                   kostet Abrufe in der Gateway-Spur. */
                $this->SendDebug('EduMaps', 'Kartendeckel im Umschlag erreicht: ' . $seite['name'], 0);
                break;
            }
        }

        if ($karten === []) {
            /* Kein stilles Schweigen: bricht das Markup der Schule, sieht es
               sonst aus wie „nichts Neues" — und der Archiv-Abgleich haette
               jede Karte der Seite archiviert. */
            $this->SendDebug('EduMaps', '0 Karten im Umschlag fuer ' . $seite['name']
                . ' — Seite uebersprungen', 0);
            return null;
        }
        return [$seite, array_values($karten), $funde];
    }

    /**
     * Eine Karte aus dem Umschlag hart in Form bringen.
     *
     * Vorgaben ZU ERGAENZEN genuegt nicht — der PHP-Plus-Operator laesst einen
     * vorhandenen Schluessel unberuehrt, ein FALSCHER Typ bleibt also stehen.
     * Und was hier durchkommt, wird gleich darauf ungeprueft benutzt:
     *
     *  - `anhaenge` als Zeichenkette ergibt in `EduNotizText` ein
     *    `(array)"keine"` = `["keine"]`, und `$a['name']` auf einer
     *    Zeichenkette wirft in PHP 8. Der Wurf verliesse `ScanEinpflegen`
     *    ungefangen — der Umschlag bliebe als aeltester liegen und verkeilte
     *    den ganzen Kanal.
     *  - `html` landet ueber den Bestand in einem `innerHTML` der App. Heute
     *    entsteht es AUSSCHLIESSLICH in `EduHtml()`, einer Weissliste; der
     *    Umschlagweg umgeht genau diese eine Pruefstelle. Deshalb geht es hier
     *    noch einmal hindurch — mitsamt dem Laengendeckel.
     *
     * @return array<string,mixed>|null
     */
    private function EduUmschlagKarte(mixed $k): ?array
    {
        if (!is_array($k)) {
            return null;
        }
        /* Die QUELLE entscheidet, wie die Kennung aussieht. Sie kommt aus einer
           fremden Datei und wird deshalb gegen eine feste Liste gehalten —
           ein erfundener Wert landete sonst als `source` im Bestand und der
           Archiv-Abgleich fasste eine ganze Ordnerhaelfte nicht mehr an. */
        $quelle = (string)($k['quelle'] ?? 'edumaps');
        if (!in_array($quelle, ['edumaps', 'moodle'], true)) {
            return null;
        }

        $boxid = trim((string)($k['boxid'] ?? ''));
        $srcId = trim((string)($k['srcId'] ?? ''));
        /* Die Kennung traegt die Wiedererkennung im Bestand. Sie darf deshalb
           nichts anderes sein als das, was die Quelle vergibt: eine
           Klassenseite nennt ihre `boxid` (daraus wird `edu:<boxid>`, und der
           Sprung auf die Seite haengt daran), LOGINEO bringt sie fertig mit
           (`moodle:<id>`, `moodlesec:<id>`, `moodlepost:<id>`).

           Ohne diese Probe koennte ein Umschlag die Kennung einer BELIEBIGEN
           Karte tragen — auch die einer fremden Quelle — und deren Notiz
           ueberschreiben. */
        if ($quelle === 'moodle') {
            if (preg_match('/^moodle(sec|post)?:[0-9]{1,18}$/', $srcId) !== 1) {
                return null;
            }
        } else {
            if ($boxid === '' || preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $boxid) !== 1) {
                return null;
            }
            $srcId = '';   // wird aus der boxid gebildet
        }

        $anhaenge = [];
        foreach ((array)($k['anhaenge'] ?? []) as $a) {
            if (!is_array($a)) {
                continue;   // eine Zeichenkette hier wirft weiter unten
            }
            $anhaenge[] = [
                'name'    => EduStoreCalc::Kappen((string)($a['name'] ?? ''), EduStoreCalc::TITLE_MAX),
                'datei'   => EduStoreCalc::Kappen((string)($a['datei'] ?? ''), EduStoreCalc::TITLE_MAX),
                'url'     => trim((string)($a['url'] ?? '')),
                'preview' => trim((string)($a['preview'] ?? '')),
                /* Die Groesse, wenn die Quelle sie kennt. LOGINEO nennt sie
                   vorab, und dann wird eine zu grosse Datei gar nicht erst
                   geholt (`EduDateiDeckel`). */
                'bytes'   => max(0, (int)($a['bytes'] ?? 0)),
            ];
            if (count($anhaenge) >= EduStoreCalc::ATTACH_MAX) {
                /* Der Deckel zaehlt hier ENTWUERFE, nicht Erfolge: jeder
                   Eintrag kostet einen Abruf von bis zu fuenfzehn Sekunden in
                   der Gateway-Spur, auch der, der scheitert. */
                break;
            }
        }

        return [
            'quelle'         => $quelle,
            'boxid'          => $boxid,
            /* Nur bei einer fremden Quelle gesetzt; bei einer Klassenseite
               bildet `EduStoreCalc::SrcId` sie aus der `boxid`. */
            'srcId'          => $srcId,
            'updated'        => (int)($k['updated'] ?? 0),
            /* Das Datum der QUELLE. Fehlt es, gilt die Fassung — bei einer
               Klassenseite ist das dieselbe Zahl. */
            'srcAt'          => max(0, (int)($k['srcAt'] ?? ($k['updated'] ?? 0))),
            /* Der eigene Weg der Karte. Er landet als Verweis in der App —
               deshalb nur http/https, wie bei der Seitenadresse. */
            'srcUrl'         => self::EduUmschlagWeg($k['srcUrl'] ?? ''),
            'titel'          => EduStoreCalc::Kappen((string)($k['titel'] ?? ''), EduStoreCalc::TITLE_MAX),
            'text'           => (string)($k['text'] ?? ''),
            // Durch dieselbe Weissliste wie eine frisch gelesene Karte.
            'html'           => $this->EduHtml((string)($k['html'] ?? '')),
            'abschnitt'      => EduStoreCalc::Kappen((string)($k['abschnitt'] ?? ''), EduStoreCalc::TITLE_MAX),
            'abschnittFarbe' => $this->EduFarbe($k['abschnittFarbe'] ?? ''),
            'farbe'          => $this->EduFarbe($k['farbe'] ?? ''),
            'buchung'        => is_array($k['buchung'] ?? null) ? $k['buchung'] : null,
            'anhaenge'       => $anhaenge,
        ];
    }

    /**
     * Der eigene Weg einer Karte — oder gar keiner.
     *
     * Er wird in der App zu einem Verweis. Ein `javascript:` darin waere
     * fremder Code; dieselbe Probe wie fuer die Seitenadresse.
     */
    private static function EduUmschlagWeg(mixed $roh): string
    {
        $u = trim((string)$roh);
        if ($u === '') {
            return '';
        }
        $t = parse_url($u);
        if (!is_array($t) || !in_array(strtolower((string)($t['scheme'] ?? '')), ['http', 'https'], true)
            || trim((string)($t['host'] ?? '')) === '') {
            return '';
        }
        return mb_substr($u, 0, 2000);
    }

    /** Eine Farbe ist `#RRGGBB` oder gar nichts — sie geht in ein style-Attribut. */
    private function EduFarbe(mixed $roh): string
    {
        $f = trim((string)$roh);
        return preg_match('/^#[0-9A-Fa-f]{6}$/', $f) === 1 ? $f : '';
    }
}

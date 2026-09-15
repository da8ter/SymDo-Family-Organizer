<?php

declare(strict_types=1);

/**
 * Das Rechenwerk des Scan-Kanals: Namen, Reihenfolge, Verfall und die weisse
 * Liste der Umschlaege.
 *
 * Ohne eine einzige IPS-Funktion, ohne Dateisystem, ohne Uhr — alles kommt als
 * Parameter herein. Dieselbe Hausregel wie bei TransitCalc und EduStoreCalc:
 * was rechnet, steht in einer eigenen Klasse und ist damit ohne Kernel
 * pruefbar. Der Trait ScanKanal daneben fasst nur die Platte an.
 *
 * Der Kanal ist der passive Weg zwischen zwei Spuren: das Gateway legt
 * Auftraege ab und liest Ergebnisse, der Scanner nimmt Auftraege und schreibt
 * Ergebnisse. Keine Seite ruft die andere synchron — genau darum geht der
 * ganze Umbau.
 */
final class ScanKanalCalc
{
    /** Die Form der Umschlaege. Wer sie aendert, erhoeht sie. */
    public const VERSION = 1;

    /**
     * Woher ein Ergebnis stammt. „probe" ist der Selbsttest des Kanals: er
     * beweist den Weg, bevor ein echter Scan darauf faehrt.
     */
    public const QUELLEN = ['probe', 'doku', 'edu', 'moodle', 'mail', 'briefing', 'untis', 'auftrag'];

    /** Die Nutzlastfelder eines Ergebnisses. Jedes ist eine Liste oder fehlt. */
    public const NUTZLAST = ['seiten', 'spiegel', 'vorschlaege', 'hausaufgaben', 'push', 'dateien'];

    /**
     * Wie lang eine Nutzlastliste hoechstens sein darf. Ueberlange Listen
     * werden GEKAPPT, nicht abgelehnt: sonst ginge wegen eines Ausreissers der
     * ganze Rest eines Laufs verloren. Dieselbe Haltung wie beim
     * Anhangs-Gesamtdeckel im Mailweg.
     */
    public const GRENZEN = [
        'seiten' => 100, 'spiegel' => 300, 'vorschlaege' => 200,
        'hausaufgaben' => 300, 'push' => 50, 'dateien' => 32,
    ];

    /** Weshalb ein Auftrag gestellt wurde — der staerkere gewinnt beim Verschmelzen. */
    public const ANLAESSE = ['start', 'timer', 'hook', 'hand'];

    /** Hoechstwert fuer kiAufrufe; darueber wird gekappt, nicht abgelehnt. */
    public const KI_MAX = 500;

    /** Wie lange die Selbstprobe hoechstens verweilen darf (Sekunden). */
    public const VERWEIL_MAX = 60;

    /** So viele Seiten traegt ein Auftrag hoechstens. */
    public const SEITEN_MAX = 40;

    /** Deckel fuer einen Namen im Auftrag. */
    public const TEXT_MAX = 200;

    /** Deckel fuer eine Adresse im Auftrag. */
    public const URL_MAX = 2000;

    public static function QuelleGueltig(string $quelle): bool
    {
        return in_array($quelle, self::QUELLEN, true);
    }

    // ------------------------------------------------------------------
    // Namen
    // ------------------------------------------------------------------

    /**
     * Der Name eines Ergebnisses: Sekunden-Mikrosekunden-Instanz-Quelle-Zufall.
     *
     * Die ersten beiden Felder haben FESTE Breite (`time()` bleibt bis 2286
     * zehnstellig). Damit ist der schlichte Zeichenvergleich schon die
     * zeitliche Reihenfolge — der Kanal braucht keinen Zusatzzustand, um zu
     * wissen, was zuerst kam. Der Zufallsschwanz trennt zwei Kernel, die sich
     * ein Verzeichnis teilen.
     */
    public static function ErgebnisName(int $sekunden, int $mikro, int $instanz, string $quelle, string $zufall): string
    {
        return sprintf('%010d-%06d-%d-%s-%s.json',
            max(0, $sekunden), max(0, min(999999, $mikro)), max(0, $instanz), $quelle, $zufall);
    }

    /**
     * Einen Ergebnisnamen zerlegen; null, wenn er nicht dem Schema folgt.
     *
     * @return array{basis:string,at:int,us:int,instanz:int,quelle:string,zufall:string}|null
     */
    public static function NameZerlegen(string $datei): ?array
    {
        $name = basename($datei);
        if (preg_match('/^(\d{10})-(\d{6})-(\d{1,7})-([a-z]{3,12})-([0-9a-f]{4})\.json$/', $name, $t) !== 1) {
            return null;
        }
        if (!self::QuelleGueltig($t[4])) {
            return null;
        }
        return [
            'basis'  => substr($name, 0, -5),
            'at'     => (int)$t[1],
            'us'     => (int)$t[2],
            'instanz'=> (int)$t[3],
            'quelle' => $t[4],
            'zufall' => $t[5],
        ];
    }

    /**
     * Der Name einer Nebendatei. Bewusst OHNE `.json`: so ist sie fuer die
     * Warteschlange unsichtbar, die nur `*.json` einsammelt.
     */
    public static function NebenName(string $basis, int $nr): string
    {
        return $basis . '.d' . max(0, $nr);
    }

    /** Gehoert dieser Verzeichniseintrag zu diesem Umschlag (Umschlag selbst oder Nebendatei)? */
    public static function GehoertDazu(string $basis, string $eintrag): bool
    {
        $name = basename($eintrag);
        return $name === $basis . '.json'
            || $name === $basis . '.grund'
            || preg_match('/^' . preg_quote($basis, '/') . '\.d\d+$/', $name) === 1;
    }

    /** Genau EIN offener Auftrag je Quelle — ein zweiter Anstoss verschmilzt, statt sich anzureihen. */
    public static function AuftragName(string $quelle): string
    {
        return $quelle . '.json';
    }

    /**
     * Der Name eines beanspruchten Auftrags. Die Zeit steht MIT im Namen,
     * damit ein mitten im Lauf gestorbener Scanner keinen Muell hinterlaesst,
     * den niemand mehr altern sehen kann.
     */
    public static function NimmtName(string $quelle, int $at, int $instanz): string
    {
        return sprintf('%s.%010d.%d.nimmt', $quelle, max(0, $at), max(0, $instanz));
    }

    /** @return array{quelle:string,at:int,instanz:int}|null */
    public static function NimmtZerlegen(string $datei): ?array
    {
        $name = basename($datei);
        if (preg_match('/^([a-z]{3,12})\.(\d{10})\.(\d{1,7})\.nimmt$/', $name, $t) !== 1) {
            return null;
        }
        return ['quelle' => $t[1], 'at' => (int)$t[2], 'instanz' => (int)$t[3]];
    }

    /**
     * Aelteste zuerst. Sortiert wird ueber den DATEINAMEN, nicht den Pfad —
     * sonst entschiede das Verzeichnis-Praefix statt der Zeit. Unlesbares
     * faellt heraus, damit kein Fremdkoerper die Reihenfolge kippt.
     *
     * @param list<string> $dateien
     * @return list<string>
     */
    public static function Sortieren(array $dateien): array
    {
        $gut = array_values(array_filter($dateien,
            static fn(string $d): bool => self::NameZerlegen($d) !== null));
        usort($gut, static fn(string $a, string $b): int => strcmp(basename($a), basename($b)));
        return $gut;
    }

    /**
     * Was aelter ist als die Frist. Gemessen am NAMEN, nicht an der
     * Aenderungszeit: ein `touch` oder eine Sicherung soll nichts verjuengen.
     *
     * @param list<string> $dateien
     * @return list<string>
     */
    public static function Verfallen(array $dateien, int $jetzt, int $ttl): array
    {
        $raus = [];
        foreach ($dateien as $d) {
            $teile = self::NameZerlegen($d);
            if ($teile !== null && ($jetzt - $teile['at']) > $ttl) {
                $raus[] = $d;
            }
        }
        return $raus;
    }

    // ------------------------------------------------------------------
    // Umschlaege
    // ------------------------------------------------------------------

    /**
     * Der kleinstmoegliche gueltige Umschlag: nur der Rahmen, keine Nutzlast.
     * Genau den schreibt der Selbsttest „probe".
     *
     * @return array<string,mixed>
     */
    public static function LeererUmschlag(string $quelle, int $gateway, int $scanner, int $at, string $text = ''): array
    {
        return [
            'v'       => self::VERSION,
            'quelle'  => $quelle,
            'gateway' => $gateway,
            'scanner' => $scanner,
            'at'      => $at,
            'status'  => ['ok' => true, 'text' => $text, 'dauerMs' => 0, 'fehler' => []],
        ];
    }

    /**
     * Die weisse Liste fuer ein Ergebnis.
     *
     * Abgelehnt wird nur, was den Rahmen sprengt (falsche Fassung, fremdes
     * Gateway, unbekannte Quelle, kein Status). Alles andere wird
     * zurechtgestutzt: unbekannte Felder fallen weg, zu lange Listen werden
     * gekappt, und der Hinweis darauf steht danach in `status.fehler`. So
     * verliert ein Lauf nie sein ganzes Ergebnis wegen einer Kleinigkeit.
     *
     * @return array{ok:bool,umschlag:array<string,mixed>,fehler:list<string>}
     */
    public static function PruefeErgebnis(mixed $roh, int $gateway): array
    {
        $fehler = [];
        if (!is_array($roh)) {
            return ['ok' => false, 'umschlag' => [], 'fehler' => ['kein Objekt']];
        }
        if ((int)($roh['v'] ?? 0) !== self::VERSION) {
            $fehler[] = 'falsche Fassung';
        }
        $quelle = (string)($roh['quelle'] ?? '');
        if (!self::QuelleGueltig($quelle)) {
            $fehler[] = 'unbekannte Quelle';
        }
        if ((int)($roh['gateway'] ?? 0) !== $gateway) {
            $fehler[] = 'fremdes Gateway';
        }
        $at = (int)($roh['at'] ?? 0);
        if ($at <= 0) {
            $fehler[] = 'ohne Zeitstempel';
        }
        $status = $roh['status'] ?? null;
        if (!is_array($status) || !array_key_exists('ok', $status) || !is_bool($status['ok'])
            || !array_key_exists('text', $status) || !is_string($status['text'])) {
            $fehler[] = 'Status fehlt oder ist falsch geformt';
        }
        if ($fehler !== []) {
            return ['ok' => false, 'umschlag' => [], 'fehler' => $fehler];
        }

        $raus = [
            'v'       => self::VERSION,
            'quelle'  => $quelle,
            'gateway' => $gateway,
            'scanner' => max(0, (int)($roh['scanner'] ?? 0)),
            'at'      => $at,
            'status'  => [
                'ok'      => (bool)$status['ok'],
                'text'    => (string)$status['text'],
                'dauerMs' => max(0, (int)($status['dauerMs'] ?? 0)),
                'fehler'  => self::TextListe($status['fehler'] ?? []),
            ],
        ];

        // Womit sich ein Ergebnis seinem Auftrag zuordnen laesst — daran haengt
        // die Messung „wie lange lag der Auftrag, bis er lief".
        if (isset($roh['auftragAt'])) {
            $raus['auftragAt'] = max(0, (int)$roh['auftragAt']);
        }
        if (isset($roh['anlass']) && in_array((string)$roh['anlass'], self::ANLAESSE, true)) {
            $raus['anlass'] = (string)$roh['anlass'];
        }
        /* Gehoert zum Anlass: „alles auswerten" ist ein Griff von Hand, und die
           Haelfte, die auswertet, sitzt beim Gateway. Ohne dieses Feld im
           Ergebnis wuesste sie nicht, dass auch schon Gemerktes drankommen
           soll — der Knopf „Alles auswerten" haette nach der Uebergabe
           dieselbe Wirkung wie „Jetzt pruefen". */
        if (isset($roh['alles'])) {
            $raus['alles'] = (bool)$roh['alles'];
        }

        $ki = max(0, (int)($roh['kiAufrufe'] ?? 0));
        if ($ki > self::KI_MAX) {
            $raus['status']['fehler'][] = sprintf('kiAufrufe %d auf %d gekappt', $ki, self::KI_MAX);
            $ki = self::KI_MAX;
        }
        if ($ki > 0) {
            $raus['kiAufrufe'] = $ki;
        }

        foreach (self::NUTZLAST as $feld) {
            if (!array_key_exists($feld, $roh)) {
                continue;
            }
            $liste = $roh[$feld];
            if (!is_array($liste) || !array_is_list($liste)) {
                $raus['status']['fehler'][] = $feld . ' ist keine Liste und faellt weg';
                continue;
            }
            $sauber = array_values(array_filter($liste, 'is_array'));
            if (count($sauber) !== count($liste)) {
                $raus['status']['fehler'][] = $feld . ': Eintraege ohne Form entfernt';
            }
            $grenze = self::GRENZEN[$feld] ?? 100;
            if (count($sauber) > $grenze) {
                $raus['status']['fehler'][] = sprintf('%s von %d auf %d gekappt', $feld, count($sauber), $grenze);
                $sauber = array_slice($sauber, 0, $grenze);
            }
            $raus[$feld] = $sauber;
        }

        // Das Briefing ist als einziges Feld ein Objekt, keine Liste.
        if (isset($roh['briefing']) && is_array($roh['briefing'])) {
            $raus['briefing'] = $roh['briefing'];
        }

        return ['ok' => true, 'umschlag' => $raus, 'fehler' => $raus['status']['fehler']];
    }

    /**
     * Dieselbe weisse Liste fuer einen Auftrag. Er traegt statt Nutzlast einen
     * `auftrag`-Block: was zu tun ist.
     *
     * @return array{ok:bool,umschlag:array<string,mixed>,fehler:list<string>}
     */
    public static function PruefeAuftrag(mixed $roh, int $gateway): array
    {
        $geprueft = self::PruefeErgebnis($roh, $gateway);
        if (!$geprueft['ok']) {
            return $geprueft;
        }
        $umschlag = $geprueft['umschlag'];
        $a = is_array($roh) && is_array($roh['auftrag'] ?? null) ? $roh['auftrag'] : [];
        $umschlag['auftrag'] = self::AuftragBlock($a);
        return ['ok' => true, 'umschlag' => $umschlag, 'fehler' => $geprueft['fehler']];
    }

    /**
     * Die weisse Liste des Auftragsblocks. Was hier nicht steht, kommt beim
     * Scanner nicht an — und zwar lautlos. Genau das ist einmal passiert:
     * `verweilen` fehlte, und die Selbstprobe ueber das Gateway verweilte
     * immer null Sekunden. Der Beweis „die App bleibt schnell" haette dann
     * gegen eine Spur gemessen, die gar nicht belegt war.
     *
     * @return array{anlass:string,alles:bool,nur:list<string>,tage:int,verweilen:int,seiten:list<array<string,string>>,konten:list<array<string,string>>,gesperrt:list<string>}
     */
    public static function AuftragBlock(array $a): array
    {
        $anlass = (string)($a['anlass'] ?? 'timer');
        return [
            'anlass' => in_array($anlass, self::ANLAESSE, true) ? $anlass : 'timer',
            'alles'  => (bool)($a['alles'] ?? false),
            'nur'    => self::TextListe($a['nur'] ?? []),
            'tage'   => max(0, (int)($a['tage'] ?? 0)),
            /* Nur fuer die Quelle „probe": wie lange sie ihre Spur ABSICHTLICH
               belegt. Ein Messwerkzeug, kein Fachfeld — deshalb gedeckelt. */
            'verweilen' => max(0, min(self::VERWEIL_MAX, (int)($a['verweilen'] ?? 0))),
            /* Die Seiten, die ein Scan abklappern soll. Sie MUESSEN mit dem
               Auftrag reisen: sie stehen als Eigenschaft am Gateway, und
               `IPS_GetProperty` auf eine fremde Instanz zeigt einen nur
               eingetippten, noch nicht uebernommenen Wert nicht — der Scanner
               koennte sie also weder lesen noch merken, ob sie aktuell sind. */
            'seiten' => self::SeitenListe($a['seiten'] ?? []),
            /* Die LOGINEO-Zugaenge samt Token. Auch sie MUESSEN mitreisen: der
               Token liegt in einem Attribut des Gateways, und ein Attribut ist
               von einer anderen Instanz aus nicht zu lesen. */
            'konten' => self::KontenListe($a['konten'] ?? []),
            /* Adressen, die der Nutzer in der App geloescht hat. Ohne sie holte
               der Leser die Inhalte eines Kurses, den es hier nicht mehr geben
               soll. Geprueft wird beim Einpflegen ein zweites Mal. */
            'gesperrt' => self::AdressListe($a['gesperrt'] ?? []),
        ];
    }

    /**
     * Die Zugaenge eines LOGINEO-Auftrags in Form bringen.
     *
     * Eine weisse Liste je Zugang, nicht nur fuer die Liste als Ganzes: was
     * hier durchkommt, ruft der Scanner anschliessend im Netz auf — mit einem
     * Token im Gepaeck. Ein fremdes Schema waere ein Aufruf, den niemand
     * gewollt hat, und ein zu langer Token blaehte nur die Datei.
     *
     * Der Token steht damit in der Auftragsdatei. Sie liegt mit 0600 im
     * Kernel-Verzeichnis — strenger als der Ort, an dem er ohnehin schon steht:
     * `settings.json` ist weltlesbar.
     *
     * @return list<array{site:string,userId:string,name:string,token:string}>
     */
    public static function KontenListe(mixed $roh): array
    {
        $raus = [];
        foreach (is_array($roh) ? $roh : [] as $z) {
            if (!is_array($z)) {
                continue;
            }
            $site  = trim((string)($z['site'] ?? ''));
            $token = trim((string)($z['token'] ?? ''));
            $teile = parse_url($site);
            if (!is_array($teile)
                || !in_array(strtolower((string)($teile['scheme'] ?? '')), ['http', 'https'], true)
                || trim((string)($teile['host'] ?? '')) === '') {
                continue;
            }
            /* Ein Moodle-Token ist hexadezimal. Enger als noetig zu pruefen
               waere hier riskant (andere Installationen, andere Laengen) —
               aber Steuerzeichen und Zeilenumbrueche haben in einer Adresse
               nichts zu suchen. */
            if ($token === '' || strlen($token) > 256
                || preg_match('/^[A-Za-z0-9._~-]+$/', $token) !== 1) {
                continue;
            }
            $raus[] = [
                'site'   => mb_substr(rtrim($site, '/'), 0, self::URL_MAX),
                'userId' => mb_substr(trim((string)($z['userId'] ?? '')), 0, 64),
                'name'   => mb_substr(trim((string)($z['name'] ?? '')), 0, self::TEXT_MAX),
                'token'  => $token,
            ];
            if (count($raus) >= 20) {
                break;   // mehr Kinder hat kein Haushalt
            }
        }
        return $raus;
    }

    /**
     * Eine Liste von Adressen — nur http/https, gedeckelt.
     *
     * @return list<string>
     */
    public static function AdressListe(mixed $roh): array
    {
        $raus = [];
        foreach (is_array($roh) ? $roh : [] as $u) {
            $a = trim((string)$u);
            $t = parse_url($a);
            if (is_array($t)
                && in_array(strtolower((string)($t['scheme'] ?? '')), ['http', 'https'], true)
                && trim((string)($t['host'] ?? '')) !== '') {
                $raus[] = mb_substr($a, 0, self::URL_MAX);
            }
            if (count($raus) >= self::SEITEN_MAX * 2) {
                break;
            }
        }
        return $raus;
    }

    /**
     * Die Seitenliste eines Auftrags in Form bringen.
     *
     * Eine weisse Liste je Seite, nicht nur fuer die Liste als Ganzes: der
     * Auftrag ist eine Datei, und was hier durchkommt, ruft der Scanner
     * anschliessend im Netz ab. Ein fremdes Schema waere ein Abruf, den
     * niemand gewollt hat.
     *
     * @return list<array{name:string,url:string,userId:string}>
     */
    public static function SeitenListe(mixed $roh): array
    {
        if (!is_array($roh)) {
            return [];
        }
        $raus = [];
        foreach ($roh as $s) {
            if (!is_array($s)) {
                continue;
            }
            $url = trim((string)($s['url'] ?? ''));
            $teile = parse_url($url);
            if (!in_array(strtolower((string)($teile['scheme'] ?? '')), ['http', 'https'], true)
                || trim((string)($teile['host'] ?? '')) === '') {
                continue;
            }
            $raus[] = [
                'name'   => mb_substr(trim((string)($s['name'] ?? '')), 0, self::TEXT_MAX),
                'url'    => mb_substr($url, 0, self::URL_MAX),
                'userId' => mb_substr(trim((string)($s['userId'] ?? '')), 0, 64),
            ];
            if (count($raus) >= self::SEITEN_MAX) {
                break;
            }
        }
        return $raus;
    }

    /**
     * Zwei Auftraege zu einem machen.
     *
     * Ohne das verlaere ein „alles neu scannen" aus dem Formular gegen den
     * eine Sekunde spaeter eintreffenden Auftrag des Zeitgebers. Deshalb:
     * `alles` wird oder-verknuepft, eine Einschraenkung faellt weg, sobald ein
     * Auftrag ohne sie dazukommt, und der staerkere Anlass gewinnt.
     *
     * @return array{anlass:string,alles:bool,nur:list<string>,tage:int}
     */
    public static function AuftragVerschmelzen(array $alt, array $neu): array
    {
        $a = self::AuftragBlock($alt);
        $b = self::AuftragBlock($neu);
        $staerker = array_search($a['anlass'], self::ANLAESSE, true) >= array_search($b['anlass'], self::ANLAESSE, true)
            ? $a['anlass'] : $b['anlass'];
        $alles = $a['alles'] || $b['alles'];
        // „Nur diese" gilt nur, solange BEIDE Seiten sich einschraenken.
        $nur = ($a['nur'] === [] || $b['nur'] === []) ? [] : array_values(array_unique(array_merge($a['nur'], $b['nur'])));
        return [
            'anlass' => $staerker,
            'alles'  => $alles,
            'nur'    => $alles ? [] : $nur,
            'tage'   => max($a['tage'], $b['tage']),
            // Zwei Proben kurz hintereinander: die laengere gewinnt.
            'verweilen' => max($a['verweilen'], $b['verweilen']),
            /* Die JUENGERE Liste gewinnt, nicht die Vereinigung: das Gateway
               schickt jedes Mal seinen aktuellen Stand mit. Wer vereinigte,
               brachte eine geloeschte Seite mit dem naechsten Auftrag zurueck.
               Eine leere Liste ist dabei keine Aussage, sondern eine fehlende
               (etwa bei einem Auftrag von Hand) — dann bleibt die alte. */
            'seiten' => $b['seiten'] !== [] ? $b['seiten'] : $a['seiten'],
            // Dieselbe Regel: der juengere Stand gewinnt, eine leere Liste ist
            // keine Aussage.
            'konten' => $b['konten'] !== [] ? $b['konten'] : $a['konten'],
            'gesperrt' => $b['gesperrt'] !== [] ? $b['gesperrt'] : $a['gesperrt'],
        ];
    }

    /** @return list<string> */
    private static function TextListe(mixed $roh): array
    {
        if (!is_array($roh)) {
            return [];
        }
        $raus = [];
        foreach ($roh as $e) {
            if (is_string($e) || is_int($e) || is_float($e)) {
                $raus[] = (string)$e;
            }
        }
        return $raus;
    }
}

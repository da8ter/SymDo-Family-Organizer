<?php

declare(strict_types=1);

/**
 * Ferien und Feiertage, quellenneutral.
 *
 * Eine Quelle liefert Abschnitte mit reinen Datums-Strings. Verglichen wird
 * ausschliesslich ueber „Y-m-d" und niemals ueber Zeitstempel.
 *
 * Zur Datumsfalle der Vorlage: WuselPlan dokumentiert in
 * SchoolHolidayResolver.swift ausfuehrlich, dass die API UTC-Bereiche liefere
 * und lokales Runden auf Mitternacht jeden Abschnitt in Berlin um einen Tag
 * verlaengere. Am echten Endpunkt nachgesehen (24.08.2026): geliefert werden
 * „startDate": "2026-06-29" und „endDate": "2026-08-07", also nackte Datums-
 * Strings. Die Falle entsteht erst dadurch, dass Swift sie in Date wandelt.
 * Wer bei Strings bleibt, hat sie nicht — deshalb bleibt hier alles String.
 */
abstract class HolidaySource
{
    /**
     * @return list<array{name:string,start:string,end:string,public:bool}>|false
     *         false heisst „nicht lesbar" und ist NICHT dasselbe wie „keine
     *         Ferien": ein Netzfehler darf keinen Schultag zum Ferientag machen
     *         und umgekehrt.
     */
    abstract public function Read(string $von, string $bis): array|false;

    abstract public function Key(): string;

    /**
     * Wie weit voraus gefragt wird, als Angabe fuer strtotime().
     *
     * Vorgabe ist ein volles Jahr und mehr: eine Quelle, die einen Zeitraum in
     * EINER Anfrage liefert, kostet das nichts. Wer je Tag antworten muss, sagt
     * hier eine kuerzere Spanne — der Abruf laeuft taeglich, das Fenster wandert
     * also mit und nichts geht verloren.
     */
    public function Vorausschau(): string
    {
        return '+13 months';
    }

    /** Fremde Funktion vorsichtig rufen — Muster aus libs/ListSource.php (Bibliotheksebene). */
    protected static function Fremd(string $funktion, mixed ...$args): mixed
    {
        if (!function_exists($funktion)) {
            return null;
        }
        try {
            return $funktion(...$args);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Liegt $datum in einem der Abschnitte? Reiner String-Vergleich. */
    public static function AmTag(array $abschnitte, string $datum): ?array
    {
        foreach ($abschnitte as $a) {
            $von = (string)($a['start'] ?? '');
            $bis = (string)($a['end'] ?? '');
            if ($von === '' || $bis === '') {
                continue;
            }
            if ($datum >= $von && $datum <= $bis) {
                return [
                    'name'   => (string)($a['name'] ?? ''),
                    'until'  => $bis,
                    'public' => (bool)($a['public'] ?? false),
                ];
            }
        }
        return null;
    }
}

/**
 * OpenHolidaysAPI — kostenlos, ohne Schluessel, ohne Konto. Dieselbe Quelle,
 * die die Vorlage benutzt. Liefert Schulferien UND gesetzliche Feiertage.
 */
class HolidaySourceOpenHolidays extends HolidaySource
{
    public function __construct(private string $bundesland) {}

    public function Key(): string
    {
        return 'openholidays';
    }

    public function Read(string $von, string $bis): array|false
    {
        $land = preg_match('/^DE-[A-Z]{2}$/', $this->bundesland) === 1 ? $this->bundesland : '';
        if ($land === '') {
            return false;
        }
        $abschnitte = [];
        foreach (['SchoolHolidays' => false, 'PublicHolidays' => true] as $pfad => $gesetzlich) {
            $url = sprintf(
                'https://openholidaysapi.org/%s?countryIsoCode=DE&subdivisionCode=%s'
                . '&validFrom=%s&validTo=%s&languageIsoCode=DE',
                $pfad, rawurlencode($land), rawurlencode($von), rawurlencode($bis));
            $roh = $this->Holen($url);
            if ($roh === false) {
                // EIN misslungener Teil verwirft den ganzen Lauf. Sonst stuenden
                // Ferien ohne Feiertage da, und der naechste Lauf legte einen
                // halben Stand ab, der wie ein vollstaendiger aussieht.
                return false;
            }
            foreach ($roh as $e) {
                if (!is_array($e)) {
                    continue;
                }
                $von1 = (string)($e['startDate'] ?? '');
                $bis1 = (string)($e['endDate'] ?? $von1);
                if ($von1 === '') {
                    continue;
                }
                $abschnitte[] = [
                    'name'   => $this->NameLesen($e),
                    'start'  => $von1,
                    'end'    => $bis1 !== '' ? $bis1 : $von1,
                    'public' => $gesetzlich,
                ];
            }
        }
        usort($abschnitte, static fn(array $a, array $b): int => $a['start'] <=> $b['start']);
        return $abschnitte;
    }

    /** Der Name steht als Liste mit Sprachkennung. Deutsch bevorzugt. */
    private function NameLesen(array $e): string
    {
        $namen = $e['name'] ?? [];
        if (!is_array($namen)) {
            return '';
        }
        $ersatz = '';
        foreach ($namen as $n) {
            if (!is_array($n)) {
                continue;
            }
            $text = trim((string)($n['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            if (strtoupper((string)($n['language'] ?? '')) === 'DE') {
                return $text;
            }
            if ($ersatz === '') {
                $ersatz = $text;
            }
        }
        return $ersatz;
    }

    private function Holen(string $url): array|false
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return false;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => ['Accept: application/json', 'User-Agent: Symcon-Stundenplan'],
        ]);
        $rumpf  = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($rumpf) || $status !== 200) {
            return false;
        }
        /* json_decode verlangt gueltiges UTF-8 und gibt sonst null zurueck — eine
           Antwort in ISO-8859-1 saehe damit aus wie „keine Ferien". Das ist die
           gefaehrlichere Auskunft, seit das Briefing an Ferientagen NUR die
           Ferienlage nennt. Deshalb vorher umschreiben; gueltiges UTF-8 bleibt
           unangetastet. */
        if (!mb_check_encoding($rumpf, 'UTF-8')) {
            $rumpf = (string)mb_convert_encoding($rumpf, 'UTF-8', 'ISO-8859-1');
        }
        $daten = json_decode($rumpf, true);
        return is_array($daten) ? $daten : false;
    }
}

/**
 * Jahreskalender (Almanac) von Wilkware.
 *
 * Gegen die ECHTE Schnittstelle geschrieben (27.08.2026, Modul installiert und
 * eingerichtet). Der vorherige Adapter riet drei Funktionsnamen —
 * ALMANAC_GetHolidays, GetVacations, GetData —, von denen es keine gibt; das
 * Ergebnis war die Meldung „Almanac hat nicht geantwortet". Genau davor warnte
 * der Kommentar, der hier stand.
 *
 * Wirklich vorhanden ist EINE brauchbare Funktion:
 *
 *   ALMANAC_DateInfo($id, $zeitstempel) → JSON eines TAGES, darin unter anderem
 *   `Vacation`/`IsVacation` (Schulferien) und `Holiday`/`IsHoliday` (Feiertag).
 *
 * Sie beantwortet also die Frage „was ist an DIESEM Tag", nicht „welche
 * Abschnitte gibt es". Deshalb wird der Zeitraum Tag fuer Tag abgefragt und
 * daraus werden die Abschnitte zusammengesetzt: gleicher Name an
 * aufeinanderfolgenden Tagen ist ein Abschnitt.
 *
 * Der Preis steht in Zahlen: der erste Aufruf kostet 790 ms (das Modul holt
 * seine Jahresdaten), jeder weitere rund 21 ms aus dessen Zwischenspeicher.
 * Vierhundert Tage sind damit etwa neun Sekunden — einmal taeglich, im Timer.
 * Ein groesseres Fenster in Schritten abzutasten waere schneller, wuerde aber
 * einzelne freie Tage verschlucken (ein beweglicher Ferientag ist EIN Tag), und
 * ein verschluckter freier Tag ist schlimmer als eine langsame Aktualisierung.
 */
class HolidaySourceAlmanac extends HolidaySource
{
    /** Deckel gegen ein versehentlich riesiges Fenster (rund 21 ms je Tag). */
    private const MAX_TAGE = 450;

    /**
     * Wie weit ueber das Fensterende hinaus ein noch laufender Abschnitt zu Ende
     * gefragt wird. Sechs Wochen decken jede Schulferienzeit ab; laenger zu
     * suchen hiesse, einen Fehler in der Quelle mit Rechenzeit zu bezahlen.
     */
    private const MAX_NACHLAUF = 42;

    public function __construct(private int $instanceID) {}

    public function Key(): string
    {
        return 'almanac';
    }

    /**
     * Vier Monate statt dreizehn. Je Tag eine Anfrage heisst: das volle Jahr
     * kostete gemessen 11,4 s, vier Monate rund 3 s. Verloren geht dabei nichts
     * — der Abruf laeuft taeglich, das Fenster wandert mit, und angezeigt wird
     * ohnehin nur die laufende Woche und der Tag im Briefing.
     */
    public function Vorausschau(): string
    {
        return '+4 months';
    }

    public function Read(string $von, string $bis): array|false
    {
        if ($this->instanceID <= 0 || !IPS_InstanceExists($this->instanceID)) {
            return false;
        }
        // Mittag statt Mitternacht: an den Umstellungstagen liegt Mitternacht
        // sonst im Vortag, und der ganze Abschnitt verschoebe sich um einen Tag.
        $start = strtotime($von . ' 12:00:00');
        $ende  = strtotime($bis . ' 12:00:00');
        if ($start === false || $ende === false || $ende < $start) {
            return false;
        }
        $tage = min((int)floor(($ende - $start) / 86400) + 1, self::MAX_TAGE);

        $offen = [];        // Art => laufender Abschnitt
        $abschnitte = [];
        for ($i = 0; $i < $tage; $i++) {
            $ts    = $start + $i * 86400;
            $datum = date('Y-m-d', $ts);
            $roh   = self::Fremd('ALMANAC_DateInfo', $this->instanceID, $ts);
            if ($roh === null) {
                // Funktion fehlt oder hat geworfen: „nicht lesbar". Ein
                // Teilergebnis waere schlimmer — die fehlenden Tage saehen aus
                // wie Schultage.
                return false;
            }
            $tag = is_string($roh) ? json_decode($roh, true) : $roh;
            if (!is_array($tag)) {
                return false;
            }
            $heute = [];
            if (($tag['IsVacation'] ?? false) === true) {
                $heute['vacation'] = ['name' => self::Saeubern((string)($tag['Vacation'] ?? '')), 'public' => false];
            }
            if (($tag['IsHoliday'] ?? false) === true) {
                $heute['holiday'] = ['name' => self::Saeubern((string)($tag['Holiday'] ?? '')), 'public' => true];
            }
            foreach (['vacation', 'holiday'] as $art) {
                $jetzt = $heute[$art] ?? null;
                $lauf  = $offen[$art] ?? null;
                // Weiter derselbe Abschnitt? Nur bei gleichem Namen UND
                // luekenlos: ein Tag Pause trennt zwei Abschnitte.
                if ($lauf !== null && ($jetzt === null || $lauf['name'] !== $jetzt['name']
                    || $lauf['end'] !== date('Y-m-d', $ts - 86400))) {
                    $abschnitte[] = $lauf;
                    $lauf = null;
                }
                if ($jetzt === null) {
                    $offen[$art] = null;
                    continue;
                }
                $offen[$art] = $lauf === null
                    ? ['name' => $jetzt['name'], 'start' => $datum, 'end' => $datum, 'public' => $jetzt['public']]
                    : ['name' => $lauf['name'], 'start' => $lauf['start'], 'end' => $datum, 'public' => $lauf['public']];
            }
        }
        /* Ein Abschnitt, der am letzten abgefragten Tag noch laeuft, wird zu Ende
           gefragt. Ohne das truege er das Fensterende als Enddatum — die Kachel
           schriebe „Weihnachtsferien bis 27.12.", obwohl sie bis zum 6. Januar
           gehen. Das kostet nur die paar Tage, die der Abschnitt noch dauert. */
        foreach (['vacation', 'holiday'] as $art) {
            $lauf = $offen[$art] ?? null;
            if (!is_array($lauf)) {
                continue;
            }
            for ($n = 1; $n <= self::MAX_NACHLAUF; $n++) {
                $ts  = $start + ($tage - 1 + $n) * 86400;
                $roh = self::Fremd('ALMANAC_DateInfo', $this->instanceID, $ts);
                $tag = is_string($roh) ? json_decode($roh, true) : $roh;
                if (!is_array($tag)) {
                    break;   // nicht mehr lesbar: lieber hier enden als raten
                }
                $an   = $art === 'vacation' ? ($tag['IsVacation'] ?? false) : ($tag['IsHoliday'] ?? false);
                $name = self::Saeubern((string)($art === 'vacation' ? ($tag['Vacation'] ?? '') : ($tag['Holiday'] ?? '')));
                if ($an !== true || $name !== $lauf['name']) {
                    break;
                }
                $lauf['end'] = date('Y-m-d', $ts);
            }
            $abschnitte[] = $lauf;
        }
        usort($abschnitte, static fn(array $a, array $b): int => $a['start'] <=> $b['start']);
        return $abschnitte;
    }

    /**
     * Der Name, wie ihn die Kachel zeigen soll.
     *
     * Almanac haengt an die Ferien auf Wunsch den Zeitraum an
     * („Sommerferien (01.08.2026-10.09.2026)", Eigenschaft SchoolPeriod). Der
     * Klammerzusatz muss weg: er stuende sonst im Balken und im Briefing, das
     * seine Zeitangabe selbst setzt — und er wechselte den Namen mitten im
     * Abschnitt, wenn jemand die Einstellung umlegt.
     */
    private static function Saeubern(string $name): string
    {
        $name = trim($name);
        $ohne = preg_replace('/\s*\([^)]*\)\s*$/u', '', $name);
        return trim((string)($ohne ?? $name));
    }
}

/**
 * Die Instanz-Seite: abrufen, ablegen, nachschlagen. Der abgelegte Stand wird
 * einmal taeglich erneuert; ein misslungener Abruf laesst den alten stehen,
 * statt ihn zu leeren.
 */
trait TimetableHolidays
{
    private function FerienQuelle(): ?HolidaySource
    {
        $wahl = (string)@IPS_GetProperty($this->InstanceID, 'HolidaySource');
        if ($wahl === 'openholidays') {
            return new HolidaySourceOpenHolidays((string)@IPS_GetProperty($this->InstanceID, 'HolidayRegion'));
        }
        if ($wahl === 'almanac') {
            return new HolidaySourceAlmanac((int)@IPS_GetProperty($this->InstanceID, 'AlmanacInstanceID'));
        }
        return null;
    }

    /**
     * Abrufen und ablegen. Gibt eine Meldung fuer die Statuszeile zurueck.
     * Der Bereich reicht vom Anfang des laufenden Schuljahres bis ein Jahr
     * voraus — ein Stundenplan interessiert sich nicht fuer die Vergangenheit,
     * wohl aber fuer die Ferien nach dem Jahreswechsel.
     */
    private function FerienAbrufen(): string
    {
        $quelle = $this->FerienQuelle();
        if ($quelle === null) {
            return $this->Translate('No holiday source selected.');
        }
        $von = date('Y-m-d', strtotime('-1 month'));
        $bis = date('Y-m-d', strtotime($quelle->Vorausschau()) ?: strtotime('+13 months'));
        $abschnitte = $quelle->Read($von, $bis);
        if ($abschnitte === false) {
            return $quelle->Key() === 'almanac'
                ? $this->Translate("Almanac did not answer. Check whether the selected instance is an Almanac instance and whether it has already fetched its data.")
                : $this->Translate('Could not fetch the holidays. The stored ones stay untouched.');
        }
        @$this->WriteAttributeString('Holidays', (string)json_encode($abschnitte, JSON_UNESCAPED_UNICODE));
        @$this->WriteAttributeInteger('HolidaysFetched', time());
        $schule = count(array_filter($abschnitte, static fn(array $a): bool => !$a['public']));
        return sprintf($this->Translate('%d holiday periods and %d public holidays fetched.'),
            $schule, count($abschnitte) - $schule);
    }

    /**
     * Der abgelegte Stand — oder NICHTS, wenn keine Quelle gewaehlt ist.
     *
     * Der abgerufene Stand bleibt beim Abschalten absichtlich liegen: wer die
     * Quelle nur kurz wechselt, soll seine Ferien nicht neu holen muessen. Ohne
     * diese Abfrage wirkte er aber weiter — Band, grauer Balken und die Zeile im
     * Briefing blieben stehen, obwohl im Formular „Keine" stand. Wer die Quelle
     * abschaltet, will keine Ferien mehr sehen; das ist die ganze Aussage.
     */
    private function Ferien(): array
    {
        if ((string)@IPS_GetProperty($this->InstanceID, 'HolidaySource') === 'none') {
            return [];
        }
        $roh = json_decode((string)@$this->ReadAttributeString('Holidays'), true);
        return is_array($roh) ? $roh : [];
    }

    /** Ferien oder Feiertag an diesem Tag — oder null. */
    private function FerienAmTag(string $datum): ?array
    {
        return HolidaySource::AmTag($this->Ferien(), $datum);
    }
}

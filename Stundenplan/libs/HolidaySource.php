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

    /** Fremde Funktion vorsichtig rufen — Muster aus shared/ListSource.php. */
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
        $daten = json_decode($rumpf, true);
        return is_array($daten) ? $daten : false;
    }
}

/**
 * Jahreskalender (Almanac) von Wilkware.
 *
 * ACHTUNG — UNGEPRUEFT. Das Modul ist auf diesem System nicht installiert; der
 * Adapter ist nach der Dokumentationslage geschrieben, nicht gegen die echte
 * Schnittstelle. Genau dieser Fall hat bei der Bring-Anbindung zu einem Aufruf
 * gefuehrt, den es nicht gab (BRING_RemoveItem). Deshalb:
 *
 *   - JEDER fremde Aufruf laeuft ueber Fremd() und ergibt bei fehlender
 *     Funktion oder Ausnahme null,
 *   - ein Fehlschlag ergibt false („nicht lesbar"), niemals ein leeres
 *     Ergebnis, das wie „keine Ferien" aussaehe,
 *   - das Formular weist die Quelle ausdruecklich als ungeprueft aus.
 *
 * Sobald das Modul installiert ist, wird der Adapter gegen die echte
 * Schnittstelle nachgezogen — nicht vorher.
 */
class HolidaySourceAlmanac extends HolidaySource
{
    public function __construct(private int $instanceID) {}

    public function Key(): string
    {
        return 'almanac';
    }

    public function Read(string $von, string $bis): array|false
    {
        if ($this->instanceID <= 0 || !IPS_InstanceExists($this->instanceID)) {
            return false;
        }
        // Erwartet wird eine Funktion, die die Abschnitte als JSON liefert.
        // Welche es wirklich ist, entscheidet die Installation — solange keine
        // passt, bleibt es bei „nicht lesbar".
        foreach (['ALMANAC_GetHolidays', 'ALMANAC_GetVacations', 'ALMANAC_GetData'] as $funktion) {
            $roh = self::Fremd($funktion, $this->instanceID);
            if ($roh === null || $roh === false) {
                continue;
            }
            $abschnitte = $this->Deuten($roh);
            if ($abschnitte !== []) {
                return $abschnitte;
            }
        }
        return false;
    }

    /** Nimmt JSON-String wie Array und sucht die ueblichen Feldnamen. */
    private function Deuten(mixed $roh): array
    {
        if (is_string($roh)) {
            $roh = json_decode($roh, true);
        }
        if (!is_array($roh)) {
            return [];
        }
        $abschnitte = [];
        foreach ($roh as $e) {
            if (!is_array($e)) {
                continue;
            }
            $von = (string)($e['start'] ?? $e['startDate'] ?? $e['from'] ?? $e['DateStart'] ?? '');
            $bis = (string)($e['end'] ?? $e['endDate'] ?? $e['to'] ?? $e['DateEnd'] ?? $von);
            $name = trim((string)($e['name'] ?? $e['title'] ?? $e['Name'] ?? ''));
            // Zeitstempel kommen vor — dann in ein reines Datum wandeln, damit
            // der Vergleich String gegen String bleibt.
            if (ctype_digit($von) && $von !== '') {
                $von = date('Y-m-d', (int)$von);
                $bis = ctype_digit($bis) && $bis !== '' ? date('Y-m-d', (int)$bis) : $von;
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $von) !== 1) {
                continue;
            }
            $abschnitte[] = [
                'name'   => $name,
                'start'  => $von,
                'end'    => preg_match('/^\d{4}-\d{2}-\d{2}$/', $bis) === 1 ? $bis : $von,
                'public' => (bool)($e['public'] ?? $e['isPublic'] ?? false),
            ];
        }
        usort($abschnitte, static fn(array $a, array $b): int => $a['start'] <=> $b['start']);
        return $abschnitte;
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
        $bis = date('Y-m-d', strtotime('+13 months'));
        $abschnitte = $quelle->Read($von, $bis);
        if ($abschnitte === false) {
            return $quelle->Key() === 'almanac'
                ? $this->Translate('Almanac did not answer. The connection is untested — the module is not installed here.')
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

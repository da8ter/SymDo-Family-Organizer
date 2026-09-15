<?php

declare(strict_types=1);

require_once __DIR__ . '/AiRecipePage.php';

/**
 * Darf das Gateway an DIESEN Push-Endpunkt schicken?
 *
 * Ein gekoppeltes Geraet nennt seinen Endpunkt selbst — der Browser hat ihn vom
 * Push-Dienst bekommen, das Gateway bekommt ihn vom Browser. Geprueft wurde
 * bisher nur das Praefix `https://`. Damit war der Server ein, wenn auch enger,
 * Anfragekanal ins eigene Netz: `https://127.0.0.1:8443/private` durchlief die
 * ganze Verschluesselung und ging hinaus, und ueber `/push/test` kamen Status
 * und Transportfehler zurueck (SSRF). Gemeldet von einem externen Codereview am
 * 14.09.2026 (F8).
 *
 * Bewusst KEINE Liste erlaubter Dienste. Sie waere die engere Grenze, aber sie
 * altert: Apple, Mozilla, Google und Microsoft benennen ihre Endpunkte ohne uns
 * zu fragen, und ein vergessener Name hiesse „Benachrichtigungen kommen nicht
 * mehr an" — ohne Fehlermeldung, weil das Abo ja gespeichert ist. Stattdessen
 * dieselbe Zielklasse, die schon die Rezeptseiten schuetzt: https, Standardport,
 * ein NAME (kein Adressliteral), und jede aufgeloeste Adresse muss oeffentlich
 * sein. Was danach bleibt, ist „POST an eine oeffentliche https-Adresse" — nicht
 * mehr, als das gekoppelte Geraet auch selbst kann.
 *
 * Geprueft wird an BEIDEN Stellen: beim Anmelden und vor jedem Versand. Sonst
 * traegt ein einmal gespeichertes Abo den Schutz nicht mehr — und der Bestand
 * ueberlebt jede Aktualisierung.
 */
final class PushZiel
{
    /** Push-Dienste sprechen https auf dem Standardport. */
    public const PORT = 443;

    /** Laenge eines Endpunkts. Echte liegen bei 150–350 Zeichen. */
    public const URL_MAX = 2000;

    /**
     * @return array{ok: bool, grund: string, ips: list<string>}
     *   grund: '' = in Ordnung, 'form' = als Push-Endpunkt unbrauchbar (dauerhaft),
     *   'dns' = nicht aufloesbar, 'privat' = zeigt ins eigene Netz.
     *   Die Unterscheidung entscheidet, ob ein Abo WEGGEWORFEN werden darf: nur
     *   bei 'form'. Eine DNS-Stoerung darf kein gueltiges Abo kosten.
     */
    public static function pruefen(string $endpunkt): array
    {
        /* Ein Rundruf geht an mehrere Geraete, und die haengen meist am selben
           Dienst. Die Namensaufloesung laeuft in der Gateway-Spur — sie soll
           nicht je Abo noch einmal passieren. Der Zwischenspeicher lebt nur
           innerhalb EINES Aufrufs (Hook oder Timer); danach wird wieder
           aufgeloest, ein DNS-Wechsel bleibt also hoechstens einen Lauf lang
           unbemerkt. */
        static $merker = [];
        $name = (string)(parse_url($endpunkt, PHP_URL_HOST) ?? '');

        $nein = static fn(string $g): array => ['ok' => false, 'grund' => $g, 'ips' => []];

        if ($endpunkt === '' || strlen($endpunkt) > self::URL_MAX) {
            return $nein('form');
        }
        if (filter_var($endpunkt, FILTER_VALIDATE_URL) === false) {
            return $nein('form');
        }
        $teile = parse_url($endpunkt);
        if (!is_array($teile)) {
            return $nein('form');
        }
        if (strtolower((string)($teile['scheme'] ?? '')) !== 'https') {
            return $nein('form');
        }
        // `https://user:pass@host` — die Zugangsdaten gingen an das Ziel, und
        // manche Bibliotheken lesen den Host daraus anders als parse_url.
        if (isset($teile['user']) || isset($teile['pass'])) {
            return $nein('form');
        }
        if (isset($teile['port']) && (int)$teile['port'] !== self::PORT) {
            return $nein('form');
        }
        $host = (string)($teile['host'] ?? '');
        if ($host === '') {
            return $nein('form');
        }
        // Ein Push-Dienst ist ein Name. Ein Adressliteral kann nur ein Ziel
        // meinen, das sich niemand vom Dienst geben liess.
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
            return $nein('form');
        }

        /* Dieselbe Pruefung wie fuer Rezeptseiten: aufloesen und JEDE Adresse
           gegen privat/loopback/link-local/reserviert halten — samt der Bereiche,
           die PHPs Filter durchlaesst (CGNAT, NAT64, IPv4-mapped). */
        if (isset($merker[$name])) {
            return $merker[$name];
        }
        $adressen = null;
        if (!AiRecipePage::istOeffentlich($endpunkt, $adressen)) {
            return $merker[$name] = $nein(self::aufloesbar($host) ? 'privat' : 'dns');
        }
        $adressen = array_values(array_unique(array_map('strval', (array)$adressen)));
        if ($adressen === []) {
            return $merker[$name] = $nein('dns');
        }
        return $merker[$name] = ['ok' => true, 'grund' => '', 'ips' => $adressen];
    }

    /** Kurzform fuer den Ja/Nein-Fall. */
    public static function erlaubt(string $endpunkt): bool
    {
        return self::pruefen($endpunkt)['ok'];
    }

    /**
     * Nur fuer die Begruendung: konnte der Name ueberhaupt aufgeloest werden?
     * Trennt „gerade kein DNS" von „zeigt absichtlich nach innen".
     */
    private static function aufloesbar(string $host): bool
    {
        $a = @gethostbynamel($host);
        if (is_array($a) && $a !== []) {
            return true;
        }
        $aaaa = @dns_get_record($host, DNS_AAAA);
        return is_array($aaaa) && $aaaa !== [];
    }
}

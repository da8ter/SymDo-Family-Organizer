<?php

declare(strict_types=1);

/**
 * Der Transport zur EFA des VRR — mehr nicht.
 *
 * Die Rheinbahn hat keine eigene öffentliche Schnittstelle; die Fahrplanauskunft
 * des Verbunds (EFA, Mentz) liefert Abfahrten und Verbindungen mit Echtzeit,
 * ohne Schlüssel und ohne Anmeldung. Am 11.09.2026 gemessen: Haltestellensuche
 * 0,6 s, Abfahrten 0,3–0,6 s, Verbindungen 1,2 s.
 *
 * Zwei Dinge, die man hier wissen muss:
 *
 * 1. **Eine unbekannte Haltestelle antwortet mit HTTP 200 und einer leeren
 *    Liste**, nicht mit einem Fehlercode. Der Status taugt deshalb nicht als
 *    einzige Prüfung — der Aufrufer muss den Inhalt ansehen.
 * 2. **Es ist keine zugesicherte Schnittstelle.** Kein Schlüssel heißt auch
 *    kein Vertrag. Deshalb kurze Fristen, ein eigener User-Agent, mit dem man
 *    uns erkennen und ansprechen kann, und ein Takt, der sich zurückhält
 *    (siehe den Fehlerriegel im Modul).
 *
 * Getrennt vom Rechenwerk (TransitCalc) und von Symcon: diese Klasse spricht
 * HTTP, sonst nichts. Deshalb steht sie auch nicht unter Prüfstand — geprüft
 * wird, was aus ihren Antworten gemacht wird.
 */
final class Efa
{
    /** Die EFA des VRR. Ohne Schrägstrich am Ende. */
    public const BASIS = 'https://efa.vrr.de/vrr';

    /**
     * Frist je Abruf. Zwanzig Sekunden, wie bei WebUntis — lang genug für eine
     * Streckenauskunft (gemessen 1,2 s), kurz genug, dass ein hängender Dienst
     * den Takt nicht auffrisst.
     */
    private const FRIST_S = 20;

    /** Frist für den Verbindungsaufbau allein. */
    private const VERBINDEN_S = 8;

    /**
     * Haltestellen suchen.
     *
     * @return array{ok:bool,data?:array<string,mixed>,message?:string}
     */
    public static function Stopfinder(string $suche): array
    {
        $suche = trim($suche);
        if ($suche === '') {
            return ['ok' => false, 'message' => 'Suchwort fehlt'];
        }
        return self::Holen('XML_STOPFINDER_REQUEST', [
            'outputFormat' => 'rapidJSON',
            'language'     => 'de',
            'type_sf'      => 'any',
            'name_sf'      => $suche,
            /* Ohne diese Angabe kommen die Koordinaten in Mercator („5332932,
               764540") — unbrauchbar fuer die Umkreissuche und nicht als
               Fehler erkennbar, weil es Zahlen sind. */
            'coordOutputFormat' => 'WGS84[dd.ddddd]',
        ]);
    }

    /**
     * Haltestellen im Umkreis einer Koordinate.
     *
     * Fuer Adressen: die EFA kennt „Duesseldorf, Kissbergweg" als Strasse, aber
     * nicht als Haltestelle. Mit ihrer Koordinate findet diese Abfrage, was in
     * der Naehe haelt — und zwar mit Entfernung in Metern.
     *
     * Achtung, wie ueberall bei der EFA: LAENGE:BREITE, andersherum als jede
     * Karten-App es zeigt.
     *
     * @return array{ok:bool,data?:array<string,mixed>,message?:string}
     */
    public static function Umkreis(float $breite, float $laenge, int $radius = 1200, int $anzahl = 10): array
    {
        if (abs($breite) < 0.00001 && abs($laenge) < 0.00001) {
            return ['ok' => false, 'message' => 'Koordinate fehlt'];
        }
        return self::Holen('XML_COORD_REQUEST', [
            'outputFormat' => 'rapidJSON',
            'language'     => 'de',
            'coordOutputFormat' => 'WGS84[dd.ddddd]',
            'coord'        => sprintf('%.5f:%.5f:WGS84[dd.ddddd]', $laenge, $breite),
            'type_1'       => 'STOP',
            'radius_1'     => (string)max(100, min(5000, $radius)),
            'inclFilter'   => '1',
            'max'          => (string)max(1, min(30, $anzahl)),
        ]);
    }

    /**
     * Die nächsten Abfahrten einer Haltestelle.
     *
     * `type_dm=any` statt `stop`: die EFA nimmt damit sowohl die moderne Kennung
     * (`de:05111:18235`) als auch die alte Zahl (`20018235`) — beide Formen
     * wurden am 11.09.2026 mit identischer Antwort geprüft, und in Beständen
     * stehen erfahrungsgemäß beide.
     *
     * @return array{ok:bool,data?:array<string,mixed>,message?:string}
     */
    public static function Abfahrten(string $stopId, int $anzahl = 10): array
    {
        $stopId = trim($stopId);
        if ($stopId === '') {
            return ['ok' => false, 'message' => 'Haltestelle fehlt'];
        }
        return self::Holen('XML_DM_REQUEST', [
            'outputFormat' => 'rapidJSON',
            'language'     => 'de',
            'type_dm'      => 'any',
            'name_dm'      => $stopId,
            'mode'         => 'direct',
            'useRealtime'  => '1',
            'limit'        => (string)max(1, min(40, $anzahl)),
        ]);
    }

    /**
     * Verbindungen zwischen zwei Orten.
     *
     * `$von` und `$nach` sind entweder eine Haltestellen-Kennung oder
     * „lat,lon" für eine Adresse — der Koordinatenstart ist geprüft und liefert
     * den Fußweg bis zur Haltestelle als eigenen Abschnitt (gemessen: 360 s).
     *
     * @param string $modus 'dep' = ab dieser Zeit losfahren, 'arr' = bis dahin ankommen
     * @param int    $wann  Zeitstempel; 0 = jetzt
     * @return array{ok:bool,data?:array<string,mixed>,message?:string}
     */
    public static function Strecke(string $von, string $nach, string $modus = 'dep',
                                   int $wann = 0, int $anzahl = 3, bool $nurDirekt = false): array
    {
        $von  = trim($von);
        $nach = trim($nach);
        if ($von === '' || $nach === '') {
            return ['ok' => false, 'message' => 'Start oder Ziel fehlt'];
        }
        $modus = $modus === 'arr' ? 'arr' : 'dep';
        [$vonTyp, $vonWert]   = self::Ort($von);
        [$nachTyp, $nachWert] = self::Ort($nach);

        $felder = [
            'outputFormat'     => 'rapidJSON',
            'language'         => 'de',
            'type_origin'      => $vonTyp,
            'name_origin'      => $vonWert,
            'type_destination' => $nachTyp,
            'name_destination' => $nachWert,
            /* ZWEI Namen für dieselbe Sache, und nur der zweite wirkt.
               Am 11.09.2026 gemessen: mit `depArrMacro=arr` allein lieferte die
               EFA Verbindungen, die zur genannten Zeit LOSFAHREN (08:00 ab,
               08:03 an) — also genau das Gegenteil. Erst
               `itdTripDateTimeDepArr=arr` brachte Ankünfte um 07:40 und 07:53.
               `depArrMacro` bleibt mit drin, weil andere EFA-Installationen es
               auswerten und es hier nachweislich nicht stört. */
            'depArrMacro'        => $modus,
            'itdTripDateTimeDepArr' => $modus,
            'calcNumberOfTrips'  => (string)max(1, min(10, $anzahl)),
            'useRealtime'        => '1',
        ];
        /* Umsteigefrei laesst die Auskunft SELBST suchen, statt aus einer
           gemischten Antwort wegzuwerfen. Am 15.09.2026 gemessen, Benrath →
           Duesseldorf Hbf: ohne den Parameter vier Verbindungen, davon zwei mit
           Umstieg; mit ihm vier umsteigefreie — darunter zwei Abfahrten, die in
           der ungefilterten Antwort gar nicht vorkamen. Wo es keine gibt
           (Benrath → Ratingen Mitte), kommt eine leere Liste zurueck, und das
           ist die richtige Auskunft. */
        if ($nurDirekt) {
            $felder['maxChanges'] = '0';
        }
        if ($wann > 0) {
            // Die EFA will Datum und Uhrzeit getrennt, in Ortszeit.
            $felder['itdDate'] = date('Ymd', $wann);
            $felder['itdTime'] = date('Hi', $wann);
        }
        return self::Holen('XML_TRIP_REQUEST2', $felder);
    }

    /**
     * Start oder Ziel für die EFA aufbereiten.
     *
     * „51.2217,6.7763" wird zu einer Koordinatenangabe — Achtung, die EFA will
     * sie in der Reihenfolge LÄNGE:BREITE, also andersherum als jede Karten-App
     * sie anzeigt. Alles andere gilt als Haltestellen-Kennung.
     *
     * @return array{0:string,1:string}
     */
    private static function Ort(string $wert): array
    {
        if (preg_match('/^\s*(-?\d+[.,]\d+)\s*,\s*(-?\d+[.,]\d+)\s*$/', $wert, $t) === 1) {
            $breite = str_replace(',', '.', $t[1]);
            $laenge = str_replace(',', '.', $t[2]);
            return ['coord', $laenge . ':' . $breite . ':WGS84[DD.ddddd]'];
        }
        return ['any', $wert];
    }

    /**
     * @param array<string,string> $felder
     * @return array{ok:bool,data?:array<string,mixed>,message?:string}
     */
    private static function Holen(string $pfad, array $felder): array
    {
        $url = self::BASIS . '/' . $pfad . '?' . http_build_query($felder);
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'message' => 'curl nicht verfügbar'];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::FRIST_S,
            CURLOPT_CONNECTTIMEOUT => self::VERBINDEN_S,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            // Ein eigener Name, an dem der Verbund uns erkennt. Ein Dienst ohne
            // Vertrag darf wenigstens wissen, wer da klopft.
            CURLOPT_USERAGENT      => 'Symcon-SymDo-VRR',
        ]);
        $rumpf  = curl_exec($ch);
        $fehler = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        /* KEIN curl_close(): seit PHP 8.0 wirkungslos und seit 8.5 veraltet —
           es schreibt dann eine Verfallswarnung in die AUSGABE, und die zerlegt
           im Hook die HTTP-Antwort. Das Handle räumt der Zähler selbst ab. */
        unset($ch);

        if (!is_string($rumpf) || $rumpf === '') {
            return ['ok' => false, 'message' => $fehler !== '' ? $fehler : 'keine Antwort'];
        }
        if ($status !== 200) {
            return ['ok' => false, 'message' => 'HTTP ' . $status];
        }
        /* json_decode verlangt gültiges UTF-8 und gibt sonst null zurück. Die
           EFA liefert historisch ISO-8859-1; eine Antwort mit „Düsseldorf" sähe
           damit aus wie gar keine. Gültiges UTF-8 bleibt unangetastet. */
        if (!mb_check_encoding($rumpf, 'UTF-8')) {
            $rumpf = (string)mb_convert_encoding($rumpf, 'UTF-8', 'ISO-8859-1');
        }
        $daten = json_decode($rumpf, true);
        if (!is_array($daten)) {
            return ['ok' => false, 'message' => 'Antwort nicht lesbar'];
        }
        return ['ok' => true, 'data' => $daten];
    }
}

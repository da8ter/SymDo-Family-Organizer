<?php

declare(strict_types=1);

/**
 * Der Nahverkehr für die Apps.
 *
 * Das Gateway pflegt hier nichts — es holt die fertige Auskunft aus den
 * SymDo-VRR-Transit-Instanzen und reicht sie weiter. Gerechnet und vor allem
 * GEHOLT wird dort, und das ist der Punkt: ein Abruf bei der Fahrplanauskunft
 * dauert eine halbe bis anderthalb Sekunden, und Symcon führt je Instanz genau
 * eine Sache zur Zeit aus. Liefe er hier, wartete jede Anfrage der App mit.
 *
 * Gemessen am 11.09.2026: während das Modul 1,30 s lang holt, bleibt der Hook
 * des Gateways bei 10 ms.
 */
trait TransitBridge
{
    private const TRANSIT_MODULE_GUID = '{A444346D-D473-4F50-AAB6-38A482E458E2}';

    /**
     * @return array{ok:bool,transit:array|null}
     *         transit = null heisst „nichts einzublenden" — kein Modul, keine
     *         Instanz, oder nichts eingerichtet.
     */
    private function TransitPublic(): array
    {
        if (!function_exists('SDVT_GetBoard')) {
            return ['ok' => true, 'transit' => null];
        }
        $haltestellen = [];
        $strecken     = [];
        /* Die Uhr des Servers. Die App vergleicht `fetchedAt` damit und NICHT
           mit ihrer eigenen Uhr: ein Telefon, das zwei Minuten vorgeht, hielte
           sonst jeden frischen Stand fuer alt und fragte endlos nach. */
        $jetzt        = 0;
        /* Der Steig-Schalter gilt der ANZEIGE, nicht der einzelnen Haltestelle.
           Gibt es mehrere VRR-Instanzen, gewinnt die erste, die ihn abschaltet:
           wer ihn irgendwo nicht sehen will, will ihn nirgends sehen. */
        $steigZeigen = true;
        foreach ((array)@IPS_GetInstanceListByModuleID(self::TRANSIT_MODULE_GUID) as $id) {
            try {
                $roh = json_decode((string)@SDVT_GetBoard((int)$id), true);
            } catch (\Throwable $e) {
                $this->SendDebug('Transit', $id . ': ' . $e->getMessage(), 0);
                continue;
            }
            if (!is_array($roh)) {
                continue;
            }
            if (($roh['showPlatform'] ?? true) === false) {
                $steigZeigen = false;
            }
            $jetzt = max($jetzt, (int)($roh['now'] ?? 0));
            foreach ((array)($roh['stops'] ?? []) as $s) {
                if (is_array($s)) {
                    $haltestellen[] = $this->TransitHaltestelle($s);
                }
            }
            foreach ((array)($roh['routes'] ?? []) as $r) {
                if (is_array($r)) {
                    $strecken[] = $this->TransitStrecke($r);
                }
            }
        }
        if ($haltestellen === [] && $strecken === []) {
            return ['ok' => true, 'transit' => null];
        }
        return ['ok' => true, 'transit' => [
            'stops' => $haltestellen, 'routes' => $strecken, 'showPlatform' => $steigZeigen,
            'now'   => $jetzt > 0 ? $jetzt : time(),
        ]];
    }

    /**
     * Eine Haltestelle für die App.
     *
     * Diese Liste ist eine WEISSLISTE, kein Durchreichen: ohne die Zeile kommt
     * der Schlüssel nie in der App an. Derselbe Grundsatz wie beim Stundenplan
     * — was die App zeigt, steht namentlich hier.
     *
     * @param array<string,mixed> $s
     * @return array<string,mixed>
     */
    private function TransitHaltestelle(array $s): array
    {
        $abfahrten = [];
        foreach ((array)($s['departures'] ?? []) as $d) {
            if (!is_array($d)) {
                continue;
            }
            $abfahrten[] = [
                'line'        => (string)($d['line'] ?? ''),
                'product'     => (string)($d['product'] ?? ''),
                'icon'        => (string)($d['icon'] ?? ''),
                'color'       => (string)($d['color'] ?? ''),
                'destination' => (string)($d['destination'] ?? ''),
                'planned'     => (string)($d['planned'] ?? ''),
                'estimated'   => (string)($d['estimated'] ?? ''),
                'at'          => (int)($d['at'] ?? 0),
                'delay'       => (int)($d['delay'] ?? 0),
                'countdown'   => (int)($d['countdown'] ?? 0),
                'leaveIn'     => (int)($d['leaveIn'] ?? 0),
                'reachable'   => ($d['reachable'] ?? false) === true,
                'platform'    => (string)($d['platform'] ?? ''),
                'cancelled'   => ($d['cancelled'] ?? false) === true,
            ];
        }
        return [
            'key'        => (string)($s['key'] ?? ''),
            'name'       => (string)($s['name'] ?? ''),
            'member'     => (string)($s['member'] ?? ''),
            'walk'       => (int)($s['walk'] ?? 0),
            'stale'      => ($s['stale'] ?? false) === true,
            /* Wann dieser Stand geholt wurde. Ohne die Zeile kann die App den
               Unterschied zwischen „gerade geholt" und „von heute frueh" nicht
               sehen — und genau der entscheidet, ob sie gleich noch einmal
               fragt, statt eine Minute lang eine leere Tafel zu zeigen. */
            'fetchedAt'  => (int)($s['fetchedAt'] ?? 0),
            'departures' => $abfahrten,
        ];
    }

    /**
     * Eine Strecke für die App, samt Schulweg-Auskunft.
     *
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private function TransitStrecke(array $r): array
    {
        $fahrten = $this->TransitFahrten($r['journeys'] ?? []);
        /* Die zweite Liste MUSS mit durch diese Weissliste — sie ist der
           Unterschied zwischen „Alle" und „Ohne Umsteigen" in der App. Am
           15.09.2026 fehlte sie hier, und der Bereich meldete beim Schulweg
           „keine umsteigefreie Verbindung", obwohl im Bestand der VRR-Instanz
           drei standen. Die Kachel zeigte sie: die bekommt die Nutzlast direkt,
           nur die App geht ueber diese Bruecke. */
        $direkt  = $this->TransitFahrten($r['journeysDirect'] ?? []);
        /* Der Schulweg gehört MIT: an ihm hängt die Zeile „zur Schule, da sein
           um 07:50" und die Karte auf der Übersicht. Ohne diese Zeilen wüsste
           die App nicht einmal, in welche Richtung die Verbindung zeigt. */
        $schule = is_array($r['school'] ?? null) ? [
            'direction'   => (string)($r['school']['direction'] ?? ''),
            'schoolStart' => (string)($r['school']['schoolStart'] ?? ''),
            'schoolEnd'   => (string)($r['school']['schoolEnd'] ?? ''),
            'targetTime'  => (string)($r['school']['targetTime'] ?? ''),
            'bufferUsed'  => (int)($r['school']['bufferUsed'] ?? 0),
            // Ohne diese Zeile wüsste die App nicht, dass die planmäßige
            // Abfahrt schon vorbei war und ab jetzt gesucht wurde.
            'fromNow'     => ($r['school']['fromNow'] ?? false) === true,
        ] : null;

        return [
            'key'      => (string)($r['key'] ?? ''),
            'name'     => (string)($r['name'] ?? ''),
            'member'   => (string)($r['member'] ?? ''),
            'mode'     => (string)($r['mode'] ?? 'dep'),
            'school'   => $schule,
            'stale'    => ($r['stale'] ?? false) === true,
            // Siehe TransitHaltestelle: das Alter des Standes.
            'fetchedAt' => (int)($r['fetchedAt'] ?? 0),
            /* Auch dieses Feld MUSS durch die Weissliste — ohne es zeigte die
               Uebersichtskarte wieder die gemischte Liste, und niemand saehe,
               warum der Haken nichts tut. */
            'directOnly' => ($r['directOnly'] ?? false) === true,
            'journeys' => $fahrten,
            'journeysDirect' => $direkt,
        ];
    }

    /**
     * Eine Liste von Verbindungen auf die Felder stutzen, die die App kennt.
     *
     * Eigene Methode, weil es ZWEI Listen gibt — „Alle" und „Ohne Umsteigen".
     * Zwei Kopien waeren beim naechsten neuen Feld auseinandergelaufen, und
     * genau so ist die zweite Liste ueberhaupt erst verlorengegangen.
     *
     * @param mixed $roh
     * @return list<array<string,mixed>>
     */
    private function TransitFahrten(mixed $roh): array
    {
        $raus = [];
        foreach ((is_array($roh) ? $roh : []) as $v) {
            if (!is_array($v)) {
                continue;
            }
            $abschnitte = [];
            foreach ((array)($v['legs'] ?? []) as $l) {
                if (!is_array($l)) {
                    continue;
                }
                $abschnitte[] = [
                    'kind'     => (string)($l['kind'] ?? 'ride'),
                    'line'     => (string)($l['line'] ?? ''),
                    'product'  => (string)($l['product'] ?? ''),
                    'icon'     => (string)($l['icon'] ?? ''),
                    /* Die Gattung als Zahl und die Farbe, die das Modul ihr
                       zugeordnet hat. Der VRR liefert keine Farben mit; die
                       Tabelle dahinter pflegt der Nutzer im Formular. */
                    'class'    => (int)($l['class'] ?? -1),
                    'color'    => (string)($l['color'] ?? ''),
                    'from'     => (string)($l['from'] ?? ''),
                    'to'       => (string)($l['to'] ?? ''),
                    'depText'  => (string)($l['depText'] ?? ''),
                    'arrText'  => (string)($l['arrText'] ?? ''),
                    'depDelay' => (int)($l['depDelay'] ?? 0),
                    'seconds'  => (int)($l['seconds'] ?? 0),
                    /* Die drei fuer die ausfuehrliche Zeitachse. Sie stehen
                       HIER, weil diese Liste eine weisse Liste ist: was nicht
                       aufgezaehlt ist, erreicht die App nie — genau daran fehlte
                       schon einmal `journeysDirect`, und der Schalter in der
                       Web-App zeigte leere Listen. */
                    'platform'   => (string)($l['platform'] ?? ''),
                    'platformTo' => (string)($l['platformTo'] ?? ''),
                    'stops'      => (int)($l['stops'] ?? 0),
                    'occupancy'  => (string)($l['occupancy'] ?? ''),
                    /* Die Haltestellenfolge der senkrechten Ansicht. Sie ist
                       meist leer — das Modul schickt sie nur, wenn eine Ansicht
                       sie auch zeigt. */
                    'halte'      => array_values(array_map(
                        static fn (array $h): array => [
                            'n' => (string)($h['n'] ?? ''),
                            't' => (string)($h['t'] ?? ''),
                        ],
                        array_filter((array)($l['halte'] ?? []), 'is_array')
                    )),
                ];
            }
            $raus[] = [
                'departureText' => (string)($v['departureText'] ?? ''),
                'arrivalText'   => (string)($v['arrivalText'] ?? ''),
                'departure'     => (int)($v['departure'] ?? 0),
                'arrival'       => (int)($v['arrival'] ?? 0),
                'seconds'       => (int)($v['seconds'] ?? 0),
                'interchanges'  => (int)($v['interchanges'] ?? 0),
                'legs'          => $abschnitte,
            ];
        }
        return $raus;
    }
}

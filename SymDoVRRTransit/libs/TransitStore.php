<?php

declare(strict_types=1);

require_once __DIR__ . '/Efa.php';
require_once __DIR__ . '/TransitCalc.php';

/**
 * Bestand und Takt der VRR-Auskunft.
 *
 * Alles Zustandsbehaftete steht hier, damit `module.php` die Symcon-Anbindung
 * bleibt und nichts weiter.
 *
 * Die eine Regel, der hier alles folgt: **geholt wird im eigenen Takt, gelesen
 * wird nur der Bestand.** Symcon führt je Instanz genau eine Sache zur Zeit aus
 * (am 11.09.2026 gemessen: 30 gleichzeitige Hook-Abrufe brauchten 874 ms statt
 * 31). Läuft eine Auskunft durch den Hook des Gateways, ist das Gateway mit
 * genau diesem Aufruf beschäftigt und lässt keinen Rückruf mehr hinein — ein
 * `TGW_GetUsers` von hier aus antwortet dann mit einem leeren String. Deshalb
 * stehen die Mitgliedsnamen und die Schulzeiten MIT im Bestand: sie werden
 * geholt, wenn wir die Zeit dafür haben, und gelesen, wenn jemand fragt.
 */
trait TransitStore
{
    private const TRANSIT_ATTR = 'Board';

    /** Abstand zwischen zwei Abrufen desselben Eintrags, solange jemand zusieht. */
    private const TAKT_S = 60;

    /** So lange nach dem letzten Zugriff gilt jemand als anwesend. */
    private const ZUSCHAUER_S = 180;

    /**
     * Ohne Zuschauer läuft der Takt langsam weiter — aber nur, wenn es einen
     * Schulweg gibt, und nur morgens: die Auskunft soll am Frühstückstisch schon
     * dastehen und nicht erst auf einen Abruf warten.
     */
    private const MORGEN_VON = 5;
    private const MORGEN_BIS = 9;
    private const MORGEN_TAKT_S = 600;

    /** Fehlerriegel: so viele Fehlschläge in Folge, dann Pause. */
    private const FEHLER_MAX = 3;
    private const SPERRE_S   = 3600;

    private const STUNDENPLAN_GUID = '{C22E0A96-1BC7-4029-B8C5-7E94E4F2A9D9}';

    // ------------------------------------------------------------------
    // Anlegen
    // ------------------------------------------------------------------

    private function TransitCreate(): void
    {
        $this->RegisterPropertyString('Stops', '[]');
        $this->RegisterPropertyString('Routes', '[]');
        $this->RegisterPropertyInteger('SchoolBuffer', 10);
        $this->RegisterPropertyString('DefaultView', 'departures');
        // 0 = die erste Stundenplan-Instanz mit eigenen Daten nehmen.
        $this->RegisterPropertyInteger('TimetableInstanceID', 0);

        $this->RegisterAttributeString(self::TRANSIT_ATTR, '{}');
        $this->RegisterAttributeInteger('LastSeen', 0);
        $this->RegisterAttributeInteger('Fails', 0);
        $this->RegisterAttributeInteger('FailAt', 0);
        $this->RegisterAttributeBoolean('ParentMigrated', false);
        /* Der zuletzt GESETZTE Takt. Ohne ihn wird der Timer verhungert:
           siehe TransitTaktSetzen(). -1 heißt „noch nie gesetzt". */
        $this->RegisterAttributeInteger('TimerMs', -1);

        $this->RegisterTimer('Refresh', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'Refresh\', 0);');
    }

    // ------------------------------------------------------------------
    // Konfiguration
    // ------------------------------------------------------------------

    /**
     * Die eigene Konfiguration — über IPS_GetConfiguration, nicht über
     * ReadProperty*: so sind gestagte Werte sofort sichtbar, und es geht auch
     * vor dem ersten Kernel-Neustart nach einer Erweiterung. Dasselbe Argument
     * wie in ChoreStore und in der Web-App.
     *
     * @return array<string,mixed>
     */
    private function TransitKonfiguration(): array
    {
        $roh = json_decode((string)@IPS_GetConfiguration($this->InstanceID), true);
        return is_array($roh) ? $roh : [];
    }

    /** @return list<array<string,mixed>> */
    private function TransitZeilen(string $feld): array
    {
        $cfg = $this->TransitKonfiguration();
        $roh = json_decode((string)($cfg[$feld] ?? '[]'), true);
        if (!is_array($roh)) {
            return [];
        }
        return array_values(array_filter($roh, 'is_array'));
    }

    /**
     * Gehört diese Haltestelle in die Abfahrtstafel?
     *
     * Eine Haltestelle wird aus zwei Gründen eingerichtet: weil man ihre
     * Abfahrten sehen will, ODER nur, damit sie in einer Strecke als Start
     * oder Ziel auswählbar ist. Der zweite Fall ist der häufigere — Schule und
     * Umsteigepunkt interessieren als Tafel niemanden.
     *
     * Unsichtbare Haltestellen werden auch NICHT ABGERUFEN. Das ist der
     * eigentliche Gewinn: jede Tafel kostet eine Anfrage an einen fremden
     * Dienst pro Minute, und eine, die niemand sieht, ist eine Anfrage zu viel.
     *
     * Fehlt das Feld, gilt „ja" — Zeilen aus der Zeit vor dieser Spalte
     * sollen nicht kommentarlos aus der Kachel verschwinden.
     *
     * @param array<string,mixed> $zeile
     */
    private function TransitStopSichtbar(array $zeile): bool
    {
        return !array_key_exists('show', $zeile) || (bool)$zeile['show'];
    }

    /**
     * Siebt diese Haltestelle ueberhaupt etwas weg?
     *
     * Wahr, sobald an EINER Tour der Haken fehlt. Zwei Stellen fragen danach:
     * der Zwischenspeicher-Schluessel (ein geaenderter Filter muss den alten
     * Stand verwerfen) und die Abrufmenge (nach dem Sieben bleibt weniger
     * uebrig, also wird grosszuegiger geholt).
     *
     * @param array<string,mixed> $zeile
     */
    private function TransitTourenSieben(array $zeile): bool
    {
        foreach ((array)($zeile['tours'] ?? []) as $t) {
            if (is_array($t) && array_key_exists('show', $t) && !$t['show']) {
                return true;
            }
        }
        return false;
    }

    private function TransitZahl(string $feld, int $vorgabe): int
    {
        $cfg = $this->TransitKonfiguration();
        return array_key_exists($feld, $cfg) ? (int)$cfg[$feld] : $vorgabe;
    }

    /**
     * Der Schlüssel eines Eintrags im Bestand.
     *
     * Bewusst aus dem INHALT gebildet und nicht als eigene Kennung in der Liste:
     * ändert jemand Start oder Ziel, ist es eine andere Strecke und die alte
     * Auskunft gilt nicht mehr. Verwaiste Einträge räumt der Lauf selbst weg.
     */
    private function TransitSchluessel(string $art, array $zeile): string
    {
        if ($art === 'stop') {
            // Die Anzahl gehört MIT hinein: wer sie erhöht, bekäme sonst bis zum
            // nächsten fälligen Lauf die alte, kürzere Antwort zu sehen.
            // Der Richtungsfilter als Merker ebenso: mit ihm wird großzügiger
            // geholt, und wer ihn setzt, soll nicht bis zum nächsten Lauf die
            // alte, nach dem Sieben halbe Antwort sehen.
            return 'stop:' . trim((string)($zeile['stopId'] ?? ''))
                 . ':' . max(1, (int)($zeile['limit'] ?? 6))
                 . ($this->TransitTourenSieben($zeile) ? ':r' : '');
        }
        /* Der AUFGELOESTE Punkt gehört in den Schlüssel, nicht das Feld: wer
           die Markierung auf der Karte verschiebt, hat eine andere Strecke und
           bekäme sonst die Auskunft von der alten Stelle zu sehen. */
        return 'route:' . substr(md5(implode('|', [
            TransitCalc::Punkt($zeile, 'from'),
            TransitCalc::Punkt($zeile, 'to'),
            trim((string)($zeile['mode'] ?? 'dep')),
            trim((string)($zeile['member'] ?? '')),
            // Der Zeitwaehler legt ein Objekt ab — als Text waere das „Array".
            TransitCalc::ZeitText($zeile['time'] ?? ''),
            (string)max(1, (int)($zeile['count'] ?? 4)),
        ])), 0, 12);
    }

    // ------------------------------------------------------------------
    // Bestand
    // ------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function TransitBestand(): array
    {
        $roh = json_decode((string)@$this->ReadAttributeString(self::TRANSIT_ATTR), true);
        if (!is_array($roh)) {
            $roh = [];
        }
        return [
            'v'       => 1,
            'entries' => is_array($roh['entries'] ?? null) ? $roh['entries'] : [],
            'members' => is_array($roh['members'] ?? null) ? $roh['members'] : [],
        ];
    }

    /**
     * Der einzige Schreiber — mit Rücklese-Probe.
     *
     * `WriteAttributeString` wirft nicht: vor dem ersten Kernel-Neustart tut es
     * schlicht nichts, und die PHP-Warnung landet in der AUSGABE, wo sie im Hook
     * die HTTP-Antwort zerlegt. Deshalb `@` davor und danach gegenlesen.
     */
    private function TransitBestandSchreiben(array $bestand): bool
    {
        $text = (string)json_encode($bestand, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        @$this->WriteAttributeString(self::TRANSIT_ATTR, $text);
        return (string)@$this->ReadAttributeString(self::TRANSIT_ATTR) === $text;
    }

    // ------------------------------------------------------------------
    // Zuschauer und Takt
    // ------------------------------------------------------------------

    /**
     * „Jemand sieht hin." Gestempelt von der Kachel und vom Gateway.
     *
     * Geschrieben wird nur, wenn sich der Wert spürbar ändert: die Web-App fragt
     * im Sekundentakt, und jedes Schreiben wäre ein Schreibzugriff für nichts.
     */
    /**
     * „Jemand sieht hin." Gibt zurück, ob vorher NIEMAND hinsah — dann ist der
     * Bestand womöglich von gestern, und der Aufrufer laesst gleich holen
     * statt erst in einer Minute.
     */
    private function TransitGesehen(int $jetzt): bool
    {
        $kalt = !$this->TransitZuschauer($jetzt);
        if ($kalt || $jetzt - (int)@$this->ReadAttributeInteger('LastSeen') >= 30) {
            @$this->WriteAttributeInteger('LastSeen', $jetzt);
        }
        return $kalt;
    }

    private function TransitZuschauer(int $jetzt): bool
    {
        return $jetzt - (int)@$this->ReadAttributeInteger('LastSeen') <= self::ZUSCHAUER_S;
    }

    /** Morgens, wenn ein Schulweg eingerichtet ist: auch ohne Zuschauer. */
    private function TransitMorgenlauf(int $jetzt): bool
    {
        $stunde = (int)date('G', $jetzt);
        if ($stunde < self::MORGEN_VON || $stunde >= self::MORGEN_BIS) {
            return false;
        }
        return $this->TransitSchulwegVorhanden();
    }

    /**
     * Der Takt: schnell mit Zuschauer, langsam für den Morgenlauf, sonst aus.
     *
     * Ein Dauerpoller wäre gegenüber einer Schnittstelle ohne Schlüssel und ohne
     * zugesicherte Verfügbarkeit unhöflich — und nutzlos, solange niemand
     * hinsieht.
     */
    private function TransitTaktSetzen(int $jetzt, bool $sofort = false): void
    {
        if ($sofort) {
            /* Erster Blick nach einer Pause: der Bestand kann von gestern sein.
               Einmal kurz anschlagen, damit die zweite Frage der App — eine
               Minute später — schon das Frische bekommt. Geholt wird weiter im
               eigenen Thread, nicht im Hook des Gateways. */
            $this->TransitTimer(1000);
            return;
        }
        if ($this->TransitZuschauer($jetzt)) {
            $ms = self::TAKT_S * 1000;
        } elseif ($this->TransitSchulwegVorhanden()) {
            /* Mit eingerichtetem Schulweg NIE ganz aus: der Morgenlauf kann
               sich sonst nicht selbst wecken. Ein Timer auf 0 feuert nicht
               mehr, und nichts setzt ihn um 5 Uhr wieder — gemessen am
               12.09.2026: nach einem Kernel-Neustart am Abend stand der
               Schulweg beim ersten Blick am Morgen noch auf dem Stand der
               Nacht. Der langsame Schlag kostet nichts: ohne Zuschauer und
               außerhalb des Morgenfensters kehrt TransitAbrufen sofort um. */
            $ms = self::MORGEN_TAKT_S * 1000;
        } else {
            $ms = 0;
        }
        // Bei Sperre trotzdem langsam weiterlaufen, sonst öffnet sie sich nie.
        if ($ms === 0 && $this->TransitGesperrt($jetzt)) {
            $ms = self::MORGEN_TAKT_S * 1000;
        }
        $this->TransitTimer($ms);
    }

    /**
     * Den Takt setzen — aber NUR, wenn er sich ändert.
     *
     * `SetTimerInterval` startet in Symcon 9.1 die Uhr neu, auch beim selben
     * Wert. Und gesetzt wird er bei JEDEM Blick: die Kachel und die App fragen
     * im Minutentakt, jede Frage lief durch TransitTaktSetzen. Der Timer stand
     * damit ständig wieder auf 60 Sekunden und feuerte nie — gemessen am
     * 12.09.2026: sechs Abrufe im 50-Sekunden-Takt, vier Minuten lang
     * unveränderte Abrufzeit. Wer hinsah, verhinderte das Nachladen; der
     * Schulweg blieb auf „keine Verbindung gefunden" stehen.
     *
     * Der zuletzt gesetzte Wert steht im Attribut. Nach einem Kernel-Neustart
     * steht dort noch der alte, der Timer selbst ist aber aus — deshalb setzt
     * ApplyChanges ihn über TransitTaktVergessen() zurück.
     */
    private function TransitTimer(int $ms): void
    {
        if ((int)@$this->ReadAttributeInteger('TimerMs') === $ms) {
            return;
        }
        @$this->SetTimerInterval('Refresh', $ms);
        @$this->WriteAttributeInteger('TimerMs', $ms);
    }

    /** Nach einem Kernelstart weiß niemand mehr, was der Timer tut. */
    private function TransitTaktVergessen(): void
    {
        @$this->WriteAttributeInteger('TimerMs', -1);
    }

    /** Ist überhaupt ein Schulweg eingerichtet? */
    private function TransitSchulwegVorhanden(): bool
    {
        foreach ($this->TransitZeilen('Routes') as $z) {
            if ((string)($z['mode'] ?? '') === 'school') {
                return true;
            }
        }
        return false;
    }

    // ------------------------------------------------------------------
    // Fehlerriegel
    // ------------------------------------------------------------------

    /**
     * Nach drei Fehlschlägen in Folge eine Stunde Pause.
     *
     * Der Zähler wird NICHT in ApplyChanges zurückgesetzt — dieselbe Falle wie
     * bei WebUntis: ApplyChanges läuft bei jedem Kernelstart und bei jeder
     * Änderung am Formular, und ein Riegel, der sich dabei öffnet, ist keiner.
     */
    private function TransitGesperrt(int $jetzt): bool
    {
        if ((int)@$this->ReadAttributeInteger('Fails') < self::FEHLER_MAX) {
            return false;
        }
        return ($jetzt - (int)@$this->ReadAttributeInteger('FailAt')) < self::SPERRE_S;
    }

    private function TransitFehlschlag(int $jetzt): void
    {
        @$this->WriteAttributeInteger('Fails', (int)@$this->ReadAttributeInteger('Fails') + 1);
        @$this->WriteAttributeInteger('FailAt', $jetzt);
    }

    private function TransitErfolg(): void
    {
        if ((int)@$this->ReadAttributeInteger('Fails') !== 0) {
            @$this->WriteAttributeInteger('Fails', 0);
        }
    }

    // ------------------------------------------------------------------
    // Abrufen
    // ------------------------------------------------------------------

    /**
     * Ein Lauf: alles holen, was fällig ist.
     *
     * Hier und nur hier wird auf fremde Dienste gewartet — in der eigenen
     * Instanz, wo es niemanden aufhält.
     */
    private function TransitAbrufen(int $jetzt): void
    {
        if ($this->TransitGesperrt($jetzt)) {
            return;
        }
        $zuschauer = $this->TransitZuschauer($jetzt);
        $morgens   = $this->TransitMorgenlauf($jetzt);
        if (!$zuschauer && !$morgens) {
            return;
        }
        $bestand = $this->TransitBestand();
        $alt     = $bestand;
        $frist   = $zuschauer ? self::TAKT_S : self::MORGEN_TAKT_S;
        $puffer  = max(0, $this->TransitZahl('SchoolBuffer', 10));
        $lebend  = [];

        foreach ($this->TransitZeilen('Stops') as $z) {
            $stopId = trim((string)($z['stopId'] ?? ''));
            if ($stopId === '' || !$this->TransitStopSichtbar($z)) {
                continue;
            }
            $key = $this->TransitSchluessel('stop', $z);
            $lebend[$key] = true;
            if (!$this->TransitFaellig($bestand, $key, $jetzt, $frist)) {
                continue;
            }
            /* Grosszuegiger holen als gezeigt wird: die EFA liefert die Liste
               UNSORTIERT und mit bereits abgefahrenen Verbindungen darin (am
               11.09.2026 gemessen). Was davon übrig bleibt, entscheidet erst
               das Rechenwerk. */
            /* Mit abgewaehlten Touren fällt etwa die Hälfte weg — dann doppelt
               holen, sonst bliebe die Tafel nach dem Sieben zu kurz. */
            $faktor  = $this->TransitTourenSieben($z) ? 2 : 1;
            $antwort = Efa::Abfahrten($stopId, max(1, (int)($z['limit'] ?? 8)) * $faktor + 8);
            $bestand['entries'][$key] = $this->TransitEintrag(
                $bestand['entries'][$key] ?? [], $antwort, $jetzt,
                static fn(array $daten): array => ['raw' => $daten]
            );
        }

        foreach ($this->TransitZeilen('Routes') as $z) {
            $von  = TransitCalc::Punkt($z, 'from');
            $nach = TransitCalc::Punkt($z, 'to');
            if ($von === '' || $nach === '') {
                continue;
            }
            $key = $this->TransitSchluessel('route', $z);
            $lebend[$key] = true;
            if (!$this->TransitFaellig($bestand, $key, $jetzt, $frist)) {
                continue;
            }
            $modus = (string)($z['mode'] ?? 'dep');
            $schule = null;
            $wann = 0;

            if ($modus === 'school') {
                $eigenerPuffer = trim((string)($z['buffer'] ?? ''));
                $schule = $this->TransitSchulweg(
                    trim((string)($z['member'] ?? '')), $jetzt,
                    $eigenerPuffer === '' ? $puffer : max(0, (int)$eigenerPuffer)
                );
                if ($schule === null) {
                    // Ferien, kein Unterricht, kein Stundenplan: nichts zu holen.
                    $bestand['entries'][$key] = [
                        'at' => $jetzt, 'ok' => true, 'school' => null, 'raw' => null,
                    ];
                    continue;
                }
                // Für den Rückweg drehen sich Start und Ziel um.
                if ($schule['direction'] === 'from') {
                    [$von, $nach] = [$nach, $von];
                }
                $modus = $schule['mode'];
                $wann  = $schule['targetAt'];
            } elseif ($modus === 'arr') {
                $wann = $this->TransitUhrzeit($z['time'] ?? '', $jetzt);
            }

            /* Großzügiger anfragen als gezeigt wird: bei einer Ankunftsvorgabe
               fallen hinterher alle Verbindungen weg, die zu SPÄT kommen — von
               fünf angefragten blieben so drei übrig. Drei Reserve decken das
               ab, ohne die fremde Schnittstelle unnötig zu belasten. */
            /* Vier ist die Obergrenze der Schnittstelle: mit
               calcNumberOfTrips 5, 8 und 10 kamen am 11.09.2026 immer vier
               Verbindungen zurück, in beiden Richtungen. Für mehr müsste ein
               zweites Mal gefragt werden — das ist es gegenüber einem Dienst
               ohne Schlüssel und ohne Zusicherung nicht wert. */
            /* ZWEI Anfragen je Strecke: einmal wie gefragt, einmal mit
               `maxChanges=0`. Der Schalter in der Kachel soll ohne Wartezeit
               umschalten koennen, und aus der gemischten Antwort liesse sich
               die umsteigefreie nicht herstellen — die Auskunft findet mit dem
               Parameter ANDERE Verbindungen (gemessen Benrath → Duesseldorf
               Hbf: 19:27 und 20:37 kamen ungefiltert gar nicht vor). */
            $anzahl  = max(1, (int)($z['count'] ?? 4));
            $antwort = Efa::Strecke($von, $nach, $modus, $wann, $anzahl);
            $direkt  = Efa::Strecke($von, $nach, $modus, $wann, $anzahl, true);
            /* Nur die erste Antwort entscheidet ueber Erfolg und Veralten: die
               zweite ist eine Zugabe. Faellt sie aus, siebt die Kachel eben aus
               der gemischten — weniger, aber nicht falsch. */
            $direktDaten = ($direkt['ok'] ?? false) === true
                ? (array)($direkt['data'] ?? []) : null;
            $bestand['entries'][$key] = $this->TransitEintrag(
                $bestand['entries'][$key] ?? [], $antwort, $jetzt,
                static fn(array $daten): array => ['raw' => $daten, 'rawDirect' => $direktDaten,
                                                   'school' => $schule]
            );
        }

        // Verwaiste Einträge weg: eine geänderte Strecke ist eine andere.
        foreach (array_keys($bestand['entries']) as $key) {
            if (!isset($lebend[$key])) {
                unset($bestand['entries'][$key]);
            }
        }

        $namen = $this->TransitMitglieder();
        if ($namen !== []) {
            $bestand['members'] = $namen;
        }

        if ($bestand !== $alt) {
            $this->TransitBestandSchreiben($bestand);
        }
    }

    /** @param array<string,mixed> $bestand */
    private function TransitFaellig(array $bestand, string $key, int $jetzt, int $frist): bool
    {
        $eintrag = $bestand['entries'][$key] ?? null;
        if (!is_array($eintrag)) {
            return true;
        }
        return ($jetzt - (int)($eintrag['at'] ?? 0)) >= $frist;
    }

    /**
     * Einen Eintrag aus einer Antwort bauen — oder den alten behalten.
     *
     * Bei Fehlschlag bleibt der alte Stand liegen und wird als `stale` markiert.
     * Eine Abfahrt von vor zwei Minuten ist brauchbarer als ein leeres Feld,
     * und ein leeres Feld sähe aus wie „heute fährt nichts".
     *
     * @param array<string,mixed> $alt
     * @param array<string,mixed> $antwort
     */
    private function TransitEintrag(array $alt, array $antwort, int $jetzt, callable $formen): array
    {
        if (($antwort['ok'] ?? false) !== true) {
            $this->TransitFehlschlag($jetzt);
            $alt['stale']   = true;
            $alt['ok']      = false;
            $alt['message'] = (string)($antwort['message'] ?? '');
            return $alt;
        }
        $this->TransitErfolg();
        return array_merge(['at' => $jetzt, 'ok' => true, 'stale' => false, 'message' => ''],
            $formen((array)($antwort['data'] ?? [])));
    }

    /** „07:30" am Tag von $jetzt; liegt es schon hinter uns, gilt morgen. */
    private function TransitUhrzeit(mixed $wert, int $jetzt): int
    {
        $hhmm = TransitCalc::ZeitText($wert);
        if ($hhmm === '' || $hhmm === '00:00') {
            // Mitternacht heisst „nicht gesetzt": ankommen um 0 Uhr will niemand.
            return 0;
        }
        $ziel = strtotime(date('Y-m-d', $jetzt) . ' ' . $hhmm);
        if ($ziel === false) {
            return 0;
        }
        return $ziel < $jetzt ? $ziel + 86400 : $ziel;
    }

    /**
     * Einmalige Umschrift der Uhrzeit auf die Form des Zeitwaehlers.
     *
     * Die Spalte ist seit dem 12.09.2026 ein `SelectTime`, und die Konsole
     * liest eine solche Zelle mit JSON.parse: in einer gewachsenen Liste stuende
     * sonst „ungueltig", obwohl das Modul den Text noch versteht. Dieselbe
     * Wanderung wie im Stundenplan, und genau wie dort schreibt sie nur, wenn
     * wirklich etwas zu aendern ist — sonst liefe ApplyChanges im Kreis.
     */
    private function TransitZeitenWandern(): void
    {
        static $laeuft = false;
        if ($laeuft) {
            return;
        }
        $zeilen = $this->TransitZeilen('Routes');
        $geaendert = false;
        foreach ($zeilen as $i => $z) {
            $wert = $z['time'] ?? '';
            // Schon eine Zelle des Zeitwaehlers? Dann nichts tun.
            if (is_string($wert) && str_starts_with(trim($wert), '{')) {
                continue;
            }
            $zeilen[$i]['time'] = TransitCalc::ZeitFeld(TransitCalc::ZeitText($wert));
            $geaendert = true;
        }
        if (!$geaendert) {
            return;
        }
        $laeuft = true;
        try {
            @IPS_SetProperty($this->InstanceID, 'Routes',
                (string)json_encode($zeilen, JSON_UNESCAPED_UNICODE));
            /* Uebernehmen NACHTRAEGLICH: IPS_ApplyChanges aus ApplyChanges
               heraus laeuft nicht. Derselbe Weg wie im Stundenplan. */
            @$this->RegisterOnceTimer('RoutesMigrated', 'IPS_ApplyChanges($_IPS[\'TARGET\']);');
        } finally {
            $laeuft = false;
        }
        $this->LogMessage('SymDo VRR Transit: Uhrzeit der Strecken auf den Zeitwaehler umgeschrieben',
            KL_NOTIFY);
    }

    // ------------------------------------------------------------------
    // Der Schulweg
    // ------------------------------------------------------------------

    /**
     * Richtung und Zielzeit für ein Kind — aus dem Stundenplan.
     *
     * EIN Abruf genügt für die ganze Woche: `STPL_GetPlanForDate` liefert die
     * Woche um das genannte Datum, und jeder Tag trägt sein eigenes (am
     * 11.09.2026 gegengelesen). Erst wenn darin kein Schultag mehr übrig ist —
     * freitagabends etwa —, wird die Folgewoche gefragt.
     *
     * @return array<string,mixed>|null
     */
    private function TransitSchulweg(string $mitglied, int $jetzt, int $puffer): ?array
    {
        if ($mitglied === '' || !function_exists('STPL_GetPlanForDate')) {
            return null;
        }
        foreach ([$jetzt, $jetzt + 7 * 86400] as $anker) {
            foreach ($this->TransitStundenplaene() as $id) {
                $plan = json_decode((string)@STPL_GetPlanForDate($id, date('Y-m-d', $anker)), true);
                if (!is_array($plan) || !is_array($plan['children'] ?? null)) {
                    continue;
                }
                foreach ($plan['children'] as $kind) {
                    if (!is_array($kind) || (string)($kind['userId'] ?? '') !== $mitglied) {
                        continue;
                    }
                    $tage = (array)($kind['days'] ?? []);
                    usort($tage, static fn(array $a, array $b): int =>
                        strcmp((string)($a['date'] ?? ''), (string)($b['date'] ?? '')));
                    foreach ($tage as $tag) {
                        $datum = trim((string)($tag['date'] ?? ''));
                        if ($datum === '' || $datum < date('Y-m-d', $jetzt)) {
                            continue;
                        }
                        $weg = TransitCalc::Schulweg($tag, $datum, $jetzt, $puffer);
                        if ($weg !== null) {
                            return $weg;
                        }
                    }
                }
            }
        }
        return null;
    }

    /** @return list<int> */
    private function TransitStundenplaene(): array
    {
        $gewaehlt = $this->TransitZahl('TimetableInstanceID', 0);
        if ($gewaehlt > 0 && @IPS_InstanceExists($gewaehlt)) {
            return [$gewaehlt];
        }
        $ids = [];
        foreach ((array)@IPS_GetInstanceListByModuleID(self::STUNDENPLAN_GUID) as $id) {
            // Spiegel-Instanzen übergehen: sie führen dieselben Kinder ein zweites Mal.
            $cfg = json_decode((string)@IPS_GetConfiguration((int)$id), true);
            if (is_array($cfg) && (int)($cfg['SourceInstanceID'] ?? 0) === 0) {
                $ids[] = (int)$id;
            }
        }
        return $ids;
    }

    /**
     * Die Mitgliedsnamen aus dem Gateway — im TAKT geholt, nicht beim Lesen.
     *
     * Leer heißt „keine Auskunft", nicht „keine Mitglieder": läuft der Lauf
     * gerade im Hook des Gateways, kommt der Rückruf nicht hinein. Der Aufrufer
     * behält dann den alten Stand.
     *
     * @return array<string,string>
     */
    private function TransitMitglieder(): array
    {
        $gw = $this->TransitGateway();
        if ($gw <= 0 || !function_exists('TGW_GetUsersForTile')) {
            return [];
        }
        /* GetUsersForTile und nicht GetUsers: nur dort liegt das FOTO als
           Data-URI bei, und die Kachel zeigt das Kind mit Bild und Namen. Das
           Gateway hält die Bilder selbst zwischengespeichert. */
        $roh = json_decode((string)@TGW_GetUsersForTile($gw), true);
        if (!is_array($roh)) {
            return [];
        }
        $karte = [];
        foreach ($roh as $u) {
            if (!is_array($u)) {
                continue;
            }
            $id = trim((string)($u['id'] ?? ''));
            $name = trim((string)($u['name'] ?? ''));
            if ($id !== '' && $name !== '') {
                $karte[$id] = ['name' => $name, 'avatar' => (string)($u['avatar'] ?? '')];
            }
        }
        return $karte;
    }

    private function TransitGateway(): int
    {
        $eltern = (int)(@IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        if ($eltern > 0) {
            return $eltern;
        }
        /* Sonst die Instanz mit der NIEDRIGSTEN Kennung: sie bedient die App,
           und nur sie führt die Mitgliederliste. Dieselbe Regel wie im
           Stundenplan. */
        $ids = (array)@IPS_GetInstanceListByModuleID('{E677FE7B-28C9-4124-8B58-8A1FE2657E8D}');
        if ($ids === []) {
            return 0;
        }
        sort($ids);
        return (int)$ids[0];
    }

    // ------------------------------------------------------------------
    // Auskunft
    // ------------------------------------------------------------------

    /**
     * Der fertige Zustand für Kachel, App und Gateway — rein aus dem Bestand.
     *
     * Nichts hier ruft nach draußen. Das ist kein Zufall, sondern die
     * Bedingung dafür, dass diese Funktion auch aus dem Hook des Gateways
     * heraus vollständige Auskunft gibt.
     *
     * @return array<string,mixed>
     */
    private function TransitPayload(int $jetzt): array
    {
        $bestand = $this->TransitBestand();
        $cfg     = $this->TransitKonfiguration();
        $namen   = (array)$bestand['members'];

        $haltestellen = [];
        foreach ($this->TransitZeilen('Stops') as $z) {
            if (!$this->TransitStopSichtbar($z)) {
                continue;
            }
            $key = $this->TransitSchluessel('stop', $z);
            $e   = $bestand['entries'][$key] ?? [];
            $roh = is_array($e['raw'] ?? null) ? $e['raw'] : [];
            $mitglied = trim((string)($z['member'] ?? ''));
            $haltestellen[] = [
                'key'        => $key,
                'name'       => trim((string)($z['name'] ?? '')),
                'member'     => $mitglied,
                'memberName' => (string)($namen[$mitglied]['name'] ?? ''),
                'memberAvatar' => (string)($namen[$mitglied]['avatar'] ?? ''),
                'walk'       => max(0, (int)($z['walk'] ?? 0)),
                'stale'      => ($e['stale'] ?? false) === true,
                'fetchedAt'  => (int)($e['at'] ?? 0),
                /* Gesiebt wird nur noch ueber die Haken der Tourenliste aus dem
                   Zeilen-Editor. Die beiden Textfelder „Nur diese Linien" und
                   „Richtung" sind am 15.09.2026 entfallen: sie wurden
                   UND-verknuepft und konnten „diese Linie in DIESE Richtung"
                   nie ausdruecken. */
                'departures' => TransitCalc::Abfahrten($roh, $jetzt,
                    max(0, (int)($z['walk'] ?? 0)), [], max(1, (int)($z['limit'] ?? 8)), [],
                    is_array($z['tours'] ?? null) ? $z['tours'] : []),
            ];
        }

        $strecken = [];
        foreach ($this->TransitZeilen('Routes') as $z) {
            $key = $this->TransitSchluessel('route', $z);
            $e   = $bestand['entries'][$key] ?? [];
            $roh = is_array($e['raw'] ?? null) ? $e['raw'] : [];
            $schule = is_array($e['school'] ?? null) ? $e['school'] : null;
            $mitglied = trim((string)($z['member'] ?? ''));
            // Eine Ankunftsvorgabe ist eine Zusage: was später kommt, zählt nicht.
            $nichtNach = ($schule !== null && ($schule['mode'] ?? '') === 'arr')
                ? (int)$schule['targetAt'] : 0;
            $vonName  = trim((string)($z['fromName'] ?? ''));
            $nachName = trim((string)($z['toName'] ?? ''));
            /* Auf dem Rueckweg drehen sich Start und Ziel um — dann steht am
               Anfang die Schule und am Ende das Zuhause. Die Namen gehoeren an
               den PUNKT, nicht an die Stelle in der Verbindung. */
            if ($schule !== null && ($schule['direction'] ?? '') === 'from') {
                [$vonName, $nachName] = [$nachName, $vonName];
            }
            $strecken[] = [
                'key'        => $key,
                'name'       => trim((string)($z['name'] ?? '')),
                'member'     => $mitglied,
                'memberName' => (string)($namen[$mitglied]['name'] ?? ''),
                'memberAvatar' => (string)($namen[$mitglied]['avatar'] ?? ''),
                'mode'       => (string)($z['mode'] ?? 'dep'),
                'school'     => $schule,
                'stale'      => ($e['stale'] ?? false) === true,
                'fetchedAt'  => (int)($e['at'] ?? 0),
                /* Fuer die Uebersichtskarte, die keinen Schalter hat. */
                'directOnly' => ($z['direct'] ?? false) === true,
                'journeys'   => TransitCalc::EndenBenennen(
                    TransitCalc::Verbindungen($roh, max(1, (int)($z['count'] ?? 4)), $nichtNach),
                    $vonName, $nachName),
                /* Die zweite Liste fuer den Schalter „Ohne Umsteigen". Fehlt
                   die eigene Antwort, wird aus der gemischten gesiebt — dann
                   sind es weniger, aber keine falschen. */
                'journeysDirect' => TransitCalc::EndenBenennen(
                    TransitCalc::Verbindungen(
                        is_array($e['rawDirect'] ?? null) ? $e['rawDirect'] : $roh,
                        max(1, (int)($z['count'] ?? 4)), $nichtNach, true),
                    $vonName, $nachName),
            ];
        }

        return [
            'v'        => 1,
            'now'      => $jetzt,
            'view'     => (string)($cfg['DefaultView'] ?? 'departures') === 'routes' ? 'routes' : 'departures',
            'stops'    => $haltestellen,
            'routes'   => $strecken,
            'blocked'  => $this->TransitGesperrt($jetzt),
        ];
    }
}

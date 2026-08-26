<?php

declare(strict_types=1);

/**
 * Datenhaltung und Aufbereitung: liest die im Formular gepflegten Listen und
 * baut daraus den fertigen Zustand fuer Kachel, Gateway und Web-App.
 *
 * ABWEICHUNG VOM PLAN, bewusst: der Plan sah die Daten in Attributen vor, mit
 * dem Formular als zweiter Ablage. Das waere genau die Verdopplung, die in der
 * ToDo-Liste schon einmal Kennungen verschluckt hat (der Formular-Neuaufbau
 * ueberschrieb den Laufzeitstand). Hier sind die FORMULAR-LISTEN die Wahrheit,
 * einen zweiten Stand gibt es nicht. Der Nutzer richtet den Plan ohnehin im
 * Backend ein — eine Laufzeit-Schnittstelle zum Aendern braucht es dafuer nicht.
 *
 * Kinder und Faecher werden ueber ihren NAMEN verknuepft, nicht ueber eine
 * verborgene Kennung: Symcon schreibt die Werte unsichtbarer Listenspalten
 * nicht mit — eine id-Spalte kaeme leer zurueck. Der Preis ist, dass ein
 * Umbenennen die Zuordnung loest; genau das meldet die Statuszeile.
 */
trait TimetableStore
{
    /** Hoechstzahl der Kinder. Steht im Code, weil Symcon Eigenschaften nur fest
     *  in Create() registriert — mehr Kinder heissen Codeaenderung UND
     *  Kernel-Neustart. Je Kind sechs Wochentage, also 6 x 6 Eigenschaften. */
    public const MAX_KINDER = 6;

    /** Name der Eigenschaft fuer Kind $kind (1-basiert) an Wochentag $tag. */
    public static function SlotProp(int $kind, int $tag): string
    {
        return sprintf('SlotsK%dD%d', $kind, $tag);
    }

    /** Schalter „Betreuung" unter der Tagesliste. */
    public static function CareProp(int $kind, int $tag): string
    {
        return sprintf('CareK%dD%d', $kind, $tag);
    }

    /** Endzeit der Betreuung, als Zeitwaehler-Objekt abgelegt. */
    public static function CareEndProp(int $kind, int $tag): string
    {
        return sprintf('CareEndK%dD%d', $kind, $tag);
    }

    /** Die drei Eigenschaften, die zu Kind $kind an Tag $tag gehoeren.
     *  @return list<string> */
    public static function TagProps(int $kind, int $tag): array
    {
        return [self::SlotProp($kind, $tag), self::CareProp($kind, $tag), self::CareEndProp($kind, $tag)];
    }

    /** Ein Schalter, nachsichtig gelesen — siehe ListeLesen. */
    private function SchalterLesen(string $name): bool
    {
        return (bool)@IPS_GetProperty($this->InstanceID, $name);
    }

    /** @return list<array<string,mixed>> Zeilen einer Listen-Eigenschaft. */
    private function ListeLesen(string $name): array
    {
        // Ueber IPS_GetProperty und nicht ReadPropertyString: neue Eigenschaften
        // gibt es vor dem naechsten Kernel-Neustart nicht, und das Lesen gaebe
        // eine PHP-Warnung, die kein try/catch faengt (Muster aus dem Gateway).
        $roh = @IPS_GetProperty($this->InstanceID, $name);
        $zeilen = json_decode((string)$roh, true);
        if (!is_array($zeilen)) {
            return [];
        }
        return array_values(array_filter($zeilen, 'is_array'));
    }

    /** @return list<array{name:string,color:mixed,userId:string,saturday:array,care:list}> */
    private function Kinder(): array
    {
        $kinder = [];
        foreach ($this->ListeLesen('Children') as $z) {
            $name = trim((string)($z['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            // Die Betreuung haengt wie die Stunden an der POSITION des Kindes:
            // je Tag ein Schalter und eine Endzeit, gepflegt unter der Tagesliste.
            $nr = count($kinder) + 1;
            $betreuung = [];
            if ($nr <= self::MAX_KINDER) {
                foreach (TimetableCalc::Wochentage(true) as $tag) {
                    if (!$this->SchalterLesen(self::CareProp($nr, $tag))) {
                        continue;
                    }
                    $ende = TimetableCalc::ZeitText(@IPS_GetProperty($this->InstanceID, self::CareEndProp($nr, $tag)));
                    if ($ende === '') {
                        continue;
                    }
                    $betreuung[] = ['weekday' => $tag, 'end' => $ende];
                }
            }
            $kinder[] = [
                'id'       => $name,   // der Name IST die Kennung, siehe Kopf
                'name'     => $name,
                'color'    => TimetableSubjects::FarbeHex($z['color'] ?? -1) ?? '#1E88E5',
                // Nachsichtig gegen den alten Fehler: bis zur Berichtigung hat das
                // Formular den NAMEN statt der Kennung gespeichert. Steht dort ein
                // bekannter Mitgliedsname, wird er hier auf die Kennung gedreht —
                // sonst bliebe das Gesicht leer, bis jemand die Zeile neu waehlt.
                'userId'   => $this->MitgliedKennung(trim((string)($z['userId'] ?? ''))),
                'saturday' => [
                    'enabled'  => (bool)($z['saturday'] ?? false),
                    'biweekly' => (bool)($z['biweekly'] ?? false),
                    'parity'   => (string)($z['parity'] ?? 'even') === 'odd' ? 'odd' : 'even',
                ],
                'care'     => $betreuung,
            ];
        }
        return $kinder;
    }

    /**
     * Kennung eines Mitglieds. Ist der Wert schon eine Kennung, bleibt er; ist es
     * ein Name, wird die Kennung dazu gesucht. Ohne Gateway bleibt alles, wie es
     * ist — dann gibt es ohnehin keine Gesichter.
     */
    /**
     * Nur noch die Kennung selbst — die Nachsicht gegen NAMEN ist raus.
     *
     * Sie war als Rettung fuer Zeilen gedacht, die aus der Zeit stammen, als das
     * Formular den Namen statt der Kennung speicherte. Genau daran ist sie
     * gescheitert: in Tims Zeile stand „Mia", und die Nachsicht loeste das
     * pflichtschuldig auf Mias Kennung auf — beide Kinder trugen dasselbe Foto.
     * Ein LEERES Bild haette dem Nutzer gesagt, dass die Zuordnung fehlt; ein
     * fremdes Foto sagt gar nichts, es sieht nur richtig aus.
     *
     * Wie „Mia" in Tims Zeile kam: als die Auswahlliste von Namen auf Kennungen
     * umgestellt wurde, stand Tims alter Wert nicht mehr unter den Optionen —
     * und ein Select ohne passende Option faellt auf die erste zurueck.
     */
    private function MitgliedKennung(string $wert): string
    {
        $wert = trim($wert);
        if ($wert === '') {
            return '';
        }
        return isset($this->GatewayMitglieder()[$wert]) ? $wert : '';
    }

    /** @return list<array{id:string,name:string,icon:string,color:int}> */
    private function Faecher(): array
    {
        $faecher = [];
        foreach ($this->ListeLesen('Subjects') as $z) {
            $name = trim((string)($z['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $faecher[] = [
                'id'    => $name,
                'name'  => $name,
                'icon'  => trim((string)($z['icon'] ?? '')),
                'color' => (int)($z['color'] ?? -1),
            ];
        }
        return $faecher;
    }

    /**
     * Alle Stunden, aufgesammelt aus den Tageslisten je Kind.
     *
     * Die Struktur der Rueckgabe ist UNVERAENDERT gegenueber der frueheren
     * Sammelliste — Kachel, Gateway, Briefing und der Prueflauf haengen daran
     * und merken vom Umbau nichts.
     *
     * Kind und Wochentag stehen nicht mehr in der Zeile, sondern SIND die Liste.
     * Damit kann es keine Stunde mehr geben, deren Kind es nicht gibt.
     *
     * @return list<array<string,mixed>>
     */
    private function Stunden(): array
    {
        $slots = [];
        foreach (array_values($this->Kinder()) as $i => $kind) {
            $nr = $i + 1;
            if ($nr > self::MAX_KINDER) {
                break;
            }
            foreach (TimetableCalc::Wochentage(true) as $tag) {
                foreach ($this->ListeLesen(self::SlotProp($nr, $tag)) as $z => $zeile) {
                    // ZeitText statt trim: die Spalten sind Zeitwaehler und legen
                    // ein Objekt ab. Alte Zeilen stehen weiter als „07:45" da —
                    // beide Formen kommen hier gleich heraus.
                    $von = TimetableCalc::ZeitText($zeile['start'] ?? '');
                    $bis = TimetableCalc::ZeitText($zeile['end'] ?? '');
                    if ($von === '' || $bis === '') {
                        continue;
                    }
                    $fach = trim((string)($zeile['subject'] ?? ''));
                    $slots[] = [
                        // Kennung nur INNERHALB eines Laufs eindeutig (Konflikt-
                        // pruefung, Vergleich mit sich selbst); nirgends gespeichert.
                        'id'        => sprintf('k%dd%dr%d', $nr, $tag, $z),
                        'childId'   => (string)$kind['name'],
                        'weekday'   => $tag,
                        'subjectId' => $fach,
                        'subject'   => $fach,
                        'start'     => $von,
                        'end'       => $bis,
                        // Keine Farbe je Stunde mehr — sie kommt vom Fach.
                        'color'     => null,
                    ];
                }
            }
        }
        return $slots;
    }

    // ────────────────────────── Aufbereiteter Zustand ──────────────────────────

    /**
     * Die Gateway-Instanz, aus der Namen und Gesichter kommen. Erste Wahl ist die
     * im Formular gewaehlte; zeigt sie ins Leere — oder ist keine gewaehlt —,
     * wird die vorhandene genommen, genau wie es die SymDo Web App tut
     * (GetAppGatewayID).
     *
     * Der Rueckfall ist nicht Bequemlichkeit, sondern Schadensbegrenzung: an
     * dieser einen Zahl haengt der ganze Bezug zu SymDo. Zeigt sie auf eine
     * geloeschte Instanz, liefert die Auswahlliste „Familienmitglied" nur noch
     * „— keins —", die Kachel zeigt Anfangsbuchstaben statt Fotos, und beides
     * ohne eine Zeile Erklaerung. Genau so ist es hier passiert: eine Instanz
     * stand auf #1, die es nie gab.
     */
    private function GatewayInstanz(): int
    {
        $gw = (int)@IPS_GetProperty($this->InstanceID, 'GatewayInstanceID');
        if ($gw > 0 && IPS_InstanceExists($gw)
            && (IPS_GetInstance($gw)['ModuleInfo']['ModuleID'] ?? '') === self::GATEWAY_GUID) {
            return $gw;
        }
        $ids = @IPS_GetInstanceListByModuleID(self::GATEWAY_GUID);
        if (!is_array($ids) || $ids === []) {
            return 0;
        }
        sort($ids);
        return (int)$ids[0];
    }

    /**
     * Gesichter aus dem Gateway, als Data-URI je Mitglied. GetUsersForTile
     * liefert verkleinerte Bilder — die Kachel kann sich am App-Hook nicht
     * anmelden und braucht sie eingebettet.
     *
     * @return array<string,string>
     */
    private function Gesichter(): array
    {
        $gw = $this->GatewayInstanz();
        if ($gw <= 0 || !function_exists('TGW_GetUsersForTile')) {
            return [];
        }
        try {
            $roh = json_decode((string)@TGW_GetUsersForTile($gw), true);
        } catch (\Throwable $e) {
            return [];
        }
        $karte = [];
        foreach (is_array($roh) ? $roh : [] as $u) {
            if (is_array($u) && trim((string)($u['id'] ?? '')) !== '') {
                $karte[(string)$u['id']] = (string)($u['avatar'] ?? '');
            }
        }
        return $karte;
    }

    /**
     * Der vollstaendige Zustand fuer die Anzeige. Hoehen und Luecken werden HIER
     * gerechnet, nicht in der Kachel: dieselben Zahlen sollen im Raster, in der
     * Timeline und in der Web-App herauskommen, und die Rechenregeln stehen
     * unter Prueflauf.
     */
    private function PlanAufbauen(string $datum = ''): array
    {
        $kinder  = $this->Kinder();
        $bilder  = $this->Gesichter();
        $faecher = $this->Faecher();
        $slots   = $this->Stunden();
        // Ein DATUM, nicht fest „heute": das Briefing fragt abends auch nach
        // morgen. Ferien und die Heute-Marke muessen sich dann auf diesen Tag
        // beziehen — sonst behauptet die Abendvorschau mitten in den Ferien
        // Unterricht.
        $heute   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum) === 1 ? $datum : date('Y-m-d');
        [$von, $bis] = TimetableCalc::Wochenspanne($slots, $kinder);
        $ferien  = $this->FerienAmTag($heute);
        $jetzt   = date('H:i');
        // Ferien JE WOCHENTAG, nicht nur fuer heute: die Timeline blaettert
        // durch die Woche, und Ferien haengen am Datum. Ohne das waere der
        // Donnerstag grau, nur weil heute (Mittwoch) ein Feiertag ist. Einmal
        // vorab statt in der Kinderschleife — sonst dieselbe Suche sechsmal je
        // Kind.
        $abschnitte  = $this->Ferien();
        $ferienJeTag = [];
        foreach ([1, 2, 3, 4, 5, 6] as $t) {
            $ferienJeTag[$t] = HolidaySource::AmTag(
                $abschnitte, TimetableCalc::DatumInWoche($heute, $t));
        }

        $betreuungGepflegt = in_array(TimetableCalc::FACH_BETREUUNG,
            array_column($faecher, 'name'), true);

        $ausgabe = [];
        foreach ($kinder as $kind) {
            $samstag  = $kind['saturday'];
            $heuteTag = $ferien === null ? TimetableCalc::Schultag($heute, $samstag) : null;
            $tage     = [];
            foreach (TimetableCalc::Wochentage((bool)$samstag['enabled']) as $tag) {
                $tages  = TimetableCalc::TagesSlots($tag, $kind, $slots);
                $naechste = $tag === $heuteTag
                    ? (TimetableCalc::NaechsteStunde($tages, $jetzt)['id'] ?? '')
                    : '';
                $vorher = $von;
                $karten = [];
                foreach ($tages as $s) {
                    $stil = TimetableSubjects::Aufloesen($s, $faecher);
                    $beginn = TimetableCalc::Minuten((string)$s['start']);
                    $karten[] = [
                        'name'   => $stil['name'],
                        'icon'   => $stil['icon'],
                        // Bei der Betreuung entscheidet das Fach, WENN es gepflegt
                        // ist; sonst Grau. Ein hart gesetztes Grau haette die
                        // Farbwahl des Nutzers stumm uebergangen.
                        'color'  => ((bool)($s['care'] ?? false) && !$betreuungGepflegt)
                            ? '#9E9E9E' : $stil['color'],
                        'start'  => $s['start'],
                        'end'    => $s['end'],
                        'from'   => $beginn,
                        'to'     => TimetableCalc::Minuten((string)$s['end']),
                        'gap'    => TimetableCalc::LueckeHoehe($vorher, $beginn),
                        'height' => TimetableCalc::SlotHoehe($s),
                        'care'   => (bool)($s['care'] ?? false),
                        'next'   => $s['id'] === $naechste && $naechste !== '',
                    ];
                    $vorher = TimetableCalc::Minuten((string)$s['end']);
                }
                $tage[] = [
                    'weekday' => $tag,
                    'label'   => TimetableCalc::TagKurz($tag),
                    // Ferien oder Feiertag AN DIESEM Tag, oder null.
                    'holiday' => $ferienJeTag[$tag] ?? null,
                    // Dauer JE TAG, nicht nur fuer heute: die Timeline laesst
                    // sich durch die Woche blaettern und braucht sie ueberall.
                    'minutes' => TimetableCalc::TagesDauer($tages),
                    'today'   => $tag === $heuteTag,
                    'parity'  => $tag === TimetableCalc::SAMSTAG && $samstag['biweekly']
                        ? ($samstag['parity'] === 'odd' ? 'ungerade KW' : 'gerade KW') : '',
                    'slots'   => $karten,
                ];
            }
            $heuteSlots = $heuteTag === null ? [] : TimetableCalc::TagesSlots($heuteTag, $kind, $slots);
            $ausgabe[] = [
                'name'    => $kind['name'],
                'color'   => $kind['color'],
                'userId'  => $kind['userId'],
                'avatar'  => (string)($bilder[$kind['userId']] ?? ''),
                'days'    => $tage,
                'today'   => $heuteTag,
                'todayLabel' => $heuteTag === null ? '' : TimetableCalc::TagKurz($heuteTag),
                'minutes' => TimetableCalc::TagesDauer($heuteSlots),
                'next'    => $heuteTag === null
                    ? '' : (TimetableSubjects::Aufloesen(
                        TimetableCalc::NaechsteStunde($heuteSlots, $jetzt) ?? [], $faecher)['name']),
            ];
        }

        return [
            'mode'     => $this->Darstellung(),
            'date'     => $heute,
            // Die aktuelle Minute fuer den Jetzt-Strich in der Timeline — und
            // NUR, wenn dieser Plan auch von heute handelt. Die Abendvorschau
            // baut denselben Plan fuer morgen; ein Strich darin behauptete, es
            // sei gerade Dienstagvormittag, obwohl der Dienstag noch bevorsteht.
            'now'      => $heute === date('Y-m-d') ? TimetableCalc::Minuten($jetzt) : null,
            'span'     => [$von, $bis],
            'holiday'  => $ferien,
            'children' => $ausgabe,
            'empty'    => $kinder === [] || $slots === [],
        ];
    }

    /**
     * Welche Stunden ohne Kind oder ohne Fach dastehen. Das passiert beim
     * Umbenennen — die Verknuepfung laeuft ueber den Namen (siehe Kopf), und ein
     * geaenderter Name loest sie. Wird in der Statuszeile gemeldet, damit es
     * nicht still im Raster fehlt.
     *
     * @return array{konflikte:list<string>,verwaist:list<string>}
     */
    private function PlanPruefen(): array
    {
        $faecher = array_column($this->Faecher(), 'name');
        $slots   = $this->Stunden();
        $konflikte = [];
        $verwaist  = [];
        foreach ($slots as $s) {
            // Nur noch das FACH kann verwaisen. Das Kind steht seit dem Umbau auf
            // Tageslisten nicht mehr in der Zeile — es IST die Liste, und die
            // Zeilen entstehen beim Lesen aus der Kinderliste selbst. Eine Stunde
            // ohne passendes Kind kann es damit nicht mehr geben.
            if ($s['subjectId'] !== '' && !in_array($s['subjectId'], $faecher, true)) {
                $verwaist[] = sprintf('%s %s %s: Fach „%s" gibt es nicht',
                    $s['childId'], TimetableCalc::TagKurz((int)$s['weekday']), $s['start'], $s['subjectId']);
            }
            $gegen = TimetableCalc::Konflikt($s, $slots);
            if ($gegen !== null) {
                $paar = [$s['id'], $gegen['id']];
                sort($paar);
                $konflikte[implode('|', $paar)] = sprintf('%s %s: %s–%s trifft %s–%s',
                    $s['childId'], TimetableCalc::TagKurz((int)$s['weekday']),
                    $s['start'], $s['end'], $gegen['start'], $gegen['end']);
            }
        }
        return ['konflikte' => array_values($konflikte), 'verwaist' => $verwaist];
    }
}

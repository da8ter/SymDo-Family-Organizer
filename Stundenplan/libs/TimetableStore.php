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

    /**
     * Die Kinder des Plans.
     *
     * MIT Gateway sind es genau die Familienmitglieder mit der Rolle `child`, in
     * deren Reihenfolge; Name und Foto kommen von dort und folgen jeder
     * Umbenennung. Die gespeicherten Zeilen liefern dann nur noch, was der
     * Stundenplan selbst braucht — Farbe, Samstag, gerade/ungerade Wochen —,
     * nachgeschlagen ueber die KENNUNG und nicht ueber die Position.
     *
     * OHNE Gateway bleibt es bei der eigenen Liste: die Kachel soll allein
     * lauffaehig bleiben.
     *
     * @return list<array{id:string,name:string,color:mixed,userId:string,saturday:array,care:list}>
     */
    private function Kinder(): array
    {
        $zeilen     = $this->ListeLesen('Children');
        $ausGateway = $this->GatewayKinder();

        if ($ausGateway !== []) {
            $nachKennung = [];
            foreach ($zeilen as $z) {
                $id = trim((string)($z['userId'] ?? ''));
                if ($id !== '' && !isset($nachKennung[$id])) {
                    $nachKennung[$id] = $z;
                }
            }
            $zeilen = [];
            foreach ($ausGateway as $id => $name) {
                /* (string) ist PFLICHT: die Kennung ist der Array-SCHLUESSEL, und
                   PHP macht aus einem Schluessel aus lauter Ziffern still ein int.
                   Ohne den Guss stuende in `userId` eine ZAHL — und die Kachel
                   vergleicht sie streng mit den Mitgliedern eines Termins
                   (members[] === userId). Fuer ein Kind mit rein numerischer
                   Kennung waere damit kein einziger Termin je zugeordnet, ohne
                   dass irgendwo ein Fehler auftaucht. */
                $kennung = (string)$id;
                $z = $nachKennung[$kennung] ?? [];
                $z['userId'] = $kennung;
                $z['name']   = $name;
                $zeilen[]    = $z;
            }
        }

        $kinder = [];
        foreach ($zeilen as $z) {
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
            $kennung = $this->MitgliedKennung(trim((string)($z['userId'] ?? '')));
            $kinder[] = [
                /* Die Identitaet des Kindes. Mit Gateway die Mitglieds-Kennung —
                   sie ueberlebt ein Umbenennen und ein Umsortieren, woran der
                   Name beides nicht tut. Ohne Gateway bleibt der Name die
                   Kennung; eine andere gibt es dort nicht. */
                'id'       => $kennung !== '' ? $kennung : $name,
                'name'     => $name,
                'color'    => TimetableSubjects::FarbeHex($z['color'] ?? -1) ?? '#1E88E5',
                'userId'   => $kennung,
                /* Ausgeblendet: das Kind bleibt in der Liste — seine Stunden
                   haengen an der Position, und ein Herausfiltern hier verschoebe
                   die Plaene aller nachfolgenden Kinder. Uebersprungen wird erst
                   beim Zusammenbauen des Plans. */
                'hidden'   => (bool)($z['hidden'] ?? false),
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
        $mitglieder = $this->GatewayMitglieder();
        /* Eine LEERE Liste heisst „keine Auskunft", nicht „Kennung ungueltig".
           Holt die Web-App ihren Stundenplan, laeuft der Abruf IM Hook des
           Gateways: das Gateway ist mit genau diesem Aufruf beschaeftigt, und
           Symcon laesst den Rueckruf TGW_GetUsers nicht hinein — er antwortet
           mit einem leeren String (am 11.09.2026 im Hook gemessen, 62 Aufrufe
           je Abruf, alle leer). Die gespeicherte Kennung daraufhin wegzuwerfen
           kostete der App den GANZEN Stundenplan-Bereich: ihr Kindmodus zeigt
           ihn nur, wenn ein Kind im Plan dieselbe userId traegt wie das
           gewaehlte Mitglied. Ohne Auskunft gilt deshalb, was gespeichert
           ist. */
        if ($mitglieder === []) {
            return $wert;
        }
        return isset($mitglieder[$wert]) ? $wert : '';
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
                        'childId'   => (string)$kind['id'],
                        // Fuer Meldungen: die Kennung sagt niemandem etwas.
                        'childName' => (string)$kind['name'],
                        'weekday'   => $tag,
                        'subjectId' => $fach,
                        'subject'   => $fach,
                        'start'     => $von,
                        'end'       => $bis,
                        /* Raum und Lehrer sind FREITEXT je Stunde — dasselbe Fach
                           kann in verschiedenen Raeumen und bei verschiedenen
                           Lehrern liegen, sie gehoeren also an die Stunde und
                           nicht an das Fach. Leer bleibt leer: die Kachel zeigt
                           die Zeile dann gar nicht. */
                        'room'      => trim((string)($zeile['room'] ?? '')),
                        'teacher'   => trim((string)($zeile['teacher'] ?? '')),
                        /* normal | vertretung | entfall | termin — gesetzt von
                           ImportSlots aus WebUntis. Von Hand gepflegte Stunden
                           haben das Feld nicht; leer heisst normal. */
                        'status'    => trim((string)($zeile['status'] ?? '')),
                        // Ersetzte Lehrkraft, nur aus dem Import.
                        'insteadOf' => trim((string)($zeile['insteadOf'] ?? '')),
                        // Klassenarbeit (nur aus dem Import, 24.09.2026).
                        'exam'      => ($zeile['exam'] ?? false) === true,
                        'examTitle' => trim((string)($zeile['examTitle'] ?? '')),
                        // Keine Farbe je Stunde mehr — sie kommt vom Fach.
                        'color'     => null,
                    ];
                }
            }
        }
        return $slots;
    }

    // ───────────────────────── Datierte Tage (Import) ─────────────────────────

    /**
     * Die datierten Tage, die ein Import geschrieben hat.
     *
     * WARUM eine zweite Ebene und nicht statt des Wochenplans: der Wochenplan
     * ist die von Hand gepflegte Wahrheit. Er gilt fuer jede Woche, auch fuer
     * die, die kein Import kennt, und er traegt weiter, wenn die Schule ihre
     * Schnittstelle zumacht. Die Ebene darueber sagt nur, was an EINEM Tag
     * wirklich war — Entfall, Vertretung, ein Projekttag.
     *
     * Bewusst ein ATTRIBUT und keine Eigenschaft: es sind Maschinendaten. Im
     * Formular haetten sie nichts zu suchen, und ein Formular-Neuaufbau darf
     * sie nicht anfassen koennen (genau daran hat sich die ToDo-Liste schon
     * einmal die Kennungen verschluckt).
     *
     * Schluessel ist der KINDERNAME, wie ueberall in diesem Modul — eine
     * Umbenennung loest die Zuordnung, der naechste Import stellt sie her.
     *
     * @return array<string, array<string, list<array<string,mixed>>>>
     */
    private function ImportierteTage(): array
    {
        // (string) davor: fehlt das Attribut noch (Kernel-Neustart), gibt
        // ReadAttributeString false zurueck, und json_decode(false) ist in
        // PHP 8 ein Fatal.
        $roh = json_decode((string)@$this->ReadAttributeString('ImportedDays'), true);
        return is_array($roh) ? $roh : [];
    }

    /**
     * Ob ein Import vorliegt, der diese Woche noch etwas aussagt.
     *
     * Nicht schlicht „das Attribut ist nicht leer": aufgeraeumt wird nur beim
     * SCHREIBEN. Hoert der Import auf — Schalter aus, WebUntis gesperrt —,
     * stuenden dort auf ewig die Tage der letzten Woche, und die Kachel zeigte
     * weiter Datum und Wochenwechsler, ohne dass ein einziger Tag dahinter
     * steht.
     */
    private function ImportStandVorhanden(): bool
    {
        $grenze = TimetableCalc::DatumInWoche(date('Y-m-d'), 1);
        foreach ($this->ImportierteTage() as $tage) {
            foreach (is_array($tage) ? array_keys($tage) : [] as $datum) {
                if ((string)$datum >= $grenze) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Datierte Tage EINES Kindes ablegen. Genannte Daten werden ersetzt, nicht
     * genannte bleiben stehen, Vergangenes fliegt weg — sonst waechst das
     * Attribut endlos.
     *
     * @param array<string, list<array<string,mixed>>> $jeDatum
     * @return array{0:list<string>, 1:bool} geschriebene Daten, und ob es ankam
     */
    private function ImportierteTageSetzen(string $kind, array $jeDatum): array
    {
        $alles = $this->ImportierteTage();
        $stand = is_array($alles[$kind] ?? null) ? $alles[$kind] : [];
        foreach ($jeDatum as $datum => $slots) {
            $stand[(string)$datum] = $slots;
        }
        /* Weggeworfen wird erst, was VOR dieser Woche liegt — nicht alles vor
           heute. Das Wochenraster zeigt die ganze Woche, auch den Montag am
           Freitag: mit dem alten Schnitt standen dort ab Dienstag wieder die
           Zeiten der Vorlage statt der importierten Stunden, samt verschwundener
           Entfaelle. Aelter als der Wochenanfang braucht niemand mehr; endlos
           wachsen darf das Attribut ja nicht. */
        $grenze = TimetableCalc::DatumInWoche(date('Y-m-d'), 1);
        foreach (array_keys($stand) as $datum) {
            if ((string)$datum < $grenze) {
                unset($stand[$datum]);
            }
        }
        ksort($stand);
        /* Bleibt nichts uebrig, faellt das Kind ganz raus — ein leerer Eintrag
           zaehlte in ImportStandVorhanden() sonst als „es gibt einen Import". */
        if ($stand === []) {
            unset($alles[$kind]);
        } else {
            $alles[$kind] = $stand;
        }
        $text = (string)json_encode($alles, JSON_UNESCAPED_UNICODE);
        /* Schreiben und GEGENLESEN: ohne Kernel-Neustart gibt es das Attribut
           nicht, und Symcon wirft dann keine Ausnahme, sondern eine PHP-Warnung
           — die faengt kein try/catch, und der Aufrufer haelte den Import fuer
           gelungen. */
        @$this->WriteAttributeString('ImportedDays', $text);
        $angekommen = (string)@$this->ReadAttributeString('ImportedDays') === $text;
        return [array_map('strval', array_keys($jeDatum)), $angekommen];
    }

    /**
     * Der Wochenplan, an den Tagen dieser Woche ersetzt durch das, was der
     * Import fuer GENAU dieses Datum kennt.
     *
     * Die Betreuung bleibt aussen vor: sie kommt aus den Schaltern des
     * Formulars und steht nicht in WebUntis.
     *
     * @param list<array<string,mixed>> $slots
     * @param list<array<string,mixed>> $kinder
     * @return list<array<string,mixed>>
     */
    private function StundenMitImport(array $slots, string $heute, array $kinder): array
    {
        $importiert = $this->ImportierteTage();
        if ($importiert === []) {
            return $slots;
        }
        foreach ($kinder as $kind) {
            $jeDatum = $importiert[(string)($kind['name'] ?? '')] ?? null;
            if (!is_array($jeDatum) || $jeDatum === []) {
                continue;
            }
            foreach (TimetableCalc::Wochentage(true) as $tag) {
                $datum = TimetableCalc::DatumInWoche($heute, $tag);
                if (!is_array($jeDatum[$datum] ?? null)) {
                    continue;
                }
                $kennung = (string)($kind['id'] ?? '');
                $slots = array_values(array_filter($slots, static fn(array $s): bool
                    => !((string)($s['childId'] ?? '') === $kennung
                         && (int)($s['weekday'] ?? 0) === $tag)));
                foreach (array_values($jeDatum[$datum]) as $i => $zeile) {
                    if (!is_array($zeile)) {
                        continue;
                    }
                    $von = TimetableCalc::ZeitText($zeile['start'] ?? '');
                    $bis = TimetableCalc::ZeitText($zeile['end'] ?? '');
                    $fach = trim((string)($zeile['subject'] ?? ''));
                    if ($von === '' || $bis === '' || $fach === '') {
                        continue;
                    }
                    $slots[] = [
                        'id'        => sprintf('i%sd%dr%d', $kennung, $tag, $i),
                        'childId'   => $kennung,
                        'childName' => (string)($kind['name'] ?? ''),
                        'weekday'   => $tag,
                        'subjectId' => $fach,
                        'subject'   => $fach,
                        'start'     => $von,
                        'end'       => $bis,
                        'room'      => trim((string)($zeile['room'] ?? '')),
                        'teacher'   => trim((string)($zeile['teacher'] ?? '')),
                        'status'    => trim((string)($zeile['status'] ?? '')),
                        'insteadOf' => trim((string)($zeile['insteadOf'] ?? '')),
                        'exam'      => ($zeile['exam'] ?? false) === true,
                        'examTitle' => trim((string)($zeile['examTitle'] ?? '')),
                        'color'     => null,
                        // Damit die Kachel sagen kann, woher die Stunde kommt.
                        'dated'     => $datum,
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
        // 1. die verbundene Eltern-Instanz (Konsole, seit dem Elternanschluss)
        $eltern = (int)(@IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        if ($eltern > 0) {
            return $eltern;
        }
        // 2. die alte Property — ihr Feld gibt es nicht mehr, aber eine früher
        //    getroffene Wahl soll nicht stillschweigend kippen
        $gw = (int)@IPS_GetProperty($this->InstanceID, 'GatewayInstanceID');
        if ($gw > 0 && IPS_InstanceExists($gw)
            && (IPS_GetInstance($gw)['ModuleInfo']['ModuleID'] ?? '') === self::GATEWAY_GUID) {
            return $gw;
        }
        // 3. das Gateway, das die App bedient
        return $this->GatewayAppSeite();
    }

    /**
     * Das Gateway, das die APP bedient: die Instanz mit der niedrigsten ID.
     *
     * Das ist keine Vorliebe, sondern eine feste Regel des Gateways
     * (OwnsAppApi) — die Hook-Pfade gibt es nur einmal. Wer die Mitglieder und
     * ihre Fotos will, muss diese Instanz fragen; jede andere fuehrt eine eigene,
     * meist leere Mitgliederliste.
     */
    private function GatewayAppSeite(): int
    {
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
     * Die Termine der Kinder fuer die angezeigte Woche, als Marker-Daten.
     *
     * EIN Abruf beim Gateway (TGW_GetEventsForTile, liest OpenCalendars Store in
     * Millisekunden), dann Buckets Kennung → Wochentag. Regeln, alle
     * Nutzerentscheid vom 27.08.2026:
     *   - nur Termine MIT Uhrzeit — ganztaegige erscheinen nicht,
     *   - nur Termine, denen das Mitglied des Kindes zugeordnet ist,
     *   - ein Termin gehoert zu dem Tag, an dem er BEGINNT (Regel aus dem
     *     Briefing — sonst leckt ein mehrtaegiger Block in jeden Tag).
     *
     * @param list<array<string,mixed>> $kinder aus Kinder()
     * @return array<string,array<int,list<array{title:string,time:string,at:int}>>>
     */
    private function TermineFuerWoche(array $kinder, string $heute): array
    {
        if (!$this->SchalterLesen('ShowCalendarEvents')) {
            return [];
        }
        $kennungen = [];
        foreach ($kinder as $kind) {
            $id = trim((string)($kind['userId'] ?? ''));
            if ($id !== '' && ($kind['hidden'] ?? false) !== true) {
                $kennungen[$id] = true;
            }
        }
        $gw = $this->GatewayInstanz();
        if ($kennungen === [] || $gw <= 0 || !function_exists('TGW_GetEventsForTile')) {
            return [];
        }
        // Montag 00:00 bis Samstag 24:00 der ANGEZEIGTEN Woche — die Timeline
        // blaettert durch genau diese Tage.
        $von = strtotime(TimetableCalc::DatumInWoche($heute, 1) . ' 00:00:00');
        $bis = strtotime(TimetableCalc::DatumInWoche($heute, 6) . ' 00:00:00') + 86400;
        if ($von === false || $bis === false) {
            return [];
        }
        try {
            $roh = json_decode((string)@TGW_GetEventsForTile($gw, $von, $bis), true);
        } catch (\Throwable $e) {
            return [];
        }
        if (!is_array($roh) || ($roh['ok'] ?? false) !== true) {
            return [];
        }
        $buckets = [];
        foreach ((array)($roh['events'] ?? []) as $e) {
            if (!is_array($e) || ($e['allDay'] ?? false) === true) {
                continue;
            }
            $start = (int)($e['start'] ?? 0);
            if ($start < $von || $start >= $bis) {
                continue;
            }
            $tag = (int)date('N', $start);
            if ($tag > 6) {
                continue;   // Sonntag zeigt die Timeline nicht
            }
            /* Dauer: der Termin wird als Balken gezeichnet. Ohne echtes Ende
               (manche Kalender legen Termine mit Dauer null an) gilt EINE
               STUNDE, und der Balken laeuft nach rechts aus — die Kachel
               behauptet dann keine Endzeit, die niemand gesetzt hat. */
            $ende  = (int)($e['end'] ?? 0);
            $offen = $ende <= $start;
            $von_m = (int)date('G', $start) * 60 + (int)date('i', $start);
            $bis_m = $offen ? $von_m + 60 : $von_m + (int)ceil(($ende - $start) / 60);
            $marker = [
                'title' => trim((string)($e['title'] ?? '')),
                'time'  => date('H:i', $start),
                'at'    => $von_m,
                // Ende in Minuten des Tages, gedeckelt: ein Termin ueber
                // Mitternacht hinaus endet in dieser Darstellung um 24:00.
                'bis'   => min($bis_m, 24 * 60),
                'open'  => $offen,
            ];
            foreach ((array)($e['members'] ?? []) as $m) {
                $m = (string)$m;
                if (isset($kennungen[$m])) {
                    $buckets[$m][$tag][] = $marker;
                }
            }
        }
        foreach ($buckets as &$tage) {
            foreach ($tage as &$liste) {
                usort($liste, static fn(array $a, array $b): int => $a['at'] <=> $b['at']);
            }
        }
        return $buckets;
    }

    /**
     * Der vollstaendige Zustand fuer die Anzeige. Hoehen und Luecken werden HIER
     * gerechnet, nicht in der Kachel: dieselben Zahlen sollen im Raster, in der
     * Timeline und in der Web-App herauskommen, und die Rechenregeln stehen
     * unter Prueflauf.
     */
    private function PlanAufbauen(string $datum = '', bool $mitTerminen = true): array
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
        /* Ab hier gilt fuer jeden Tag dieser Woche das, was ein Import fuer
           GENAU dieses Datum kennt — und nur dort. Der Wochenplan bleibt die
           Grundlage, auch fuer die Wochen, die kein Import erreicht hat. */
        $slots   = $this->StundenMitImport($slots, $heute, $kinder);
        /* Die Zeitachse richtet sich nach den SICHTBAREN Kindern. Sonst zoege ein
           ausgeblendetes Kind mit spaeter Betreuung die Achse in die Laenge und
           der Plan der anderen schrumpfte auf ein Drittel der Kachel. */
        $sichtbar = array_values(array_filter($kinder, static fn(array $k): bool => ($k['hidden'] ?? false) !== true));
        $sichtbareIds = array_column($sichtbar, 'id');
        $slotsSichtbar = array_values(array_filter($slots,
            static fn(array $s): bool => in_array((string)($s['childId'] ?? ''), $sichtbareIds, true)));
        [$von, $bis] = TimetableCalc::Wochenspanne($slotsSichtbar, $sichtbar);
        /* Termin-Marker: EIN Abruf fuer die Woche, Buckets Kennung → Wochentag.
           Die ZEITACHSE zieht mit — Kindertermine liegen meist am Nachmittag,
           hinter dem Ende der Betreuung; ohne die Erweiterung laege der Marker
           unsichtbar hinter dem Achsenende. 15 Minuten Vorlauf fuer den Punkt,
           45 Nachlauf als Platz fuer das Label. */
        $termine = $mitTerminen ? $this->TermineFuerWoche($kinder, $heute) : [];
        foreach ($termine as $jeTag) {
            foreach ($jeTag as $liste) {
                foreach ($liste as $marker) {
                    $von = min($von, max(0, (int)$marker['at'] - 15));
                    // Bis zum ENDE des Termins, plus etwas Luft fuer das Label.
                    $bis = max($bis, min(24 * 60, (int)$marker['bis'] + 15));
                }
            }
        }
        $ferien  = $this->FerienAmTag($heute);
        $jetzt   = date('H:i');
        // Ferien JE WOCHENTAG, nicht nur fuer heute: die Timeline blaettert
        // durch die Woche, und Ferien haengen am Datum. Ohne das waere der
        // Donnerstag grau, nur weil heute (Mittwoch) ein Feiertag ist. Einmal
        // vorab statt in der Kinderschleife — sonst dieselbe Suche sechsmal je
        // Kind.
        $abschnitte  = $this->Ferien();
        $ferienJeTag = [];
        foreach (TimetableCalc::AnzeigeTage() as $t) {
            $ferienJeTag[$t] = HolidaySource::AmTag(
                $abschnitte, TimetableCalc::DatumInWoche($heute, $t));
        }

        $betreuungGepflegt = in_array(TimetableCalc::FACH_BETREUUNG,
            array_column($faecher, 'name'), true);

        $ausgabe = [];
        foreach ($kinder as $kind) {
            if (($kind['hidden'] ?? false) === true) {
                continue;
            }
            $samstag  = $kind['saturday'];
            $heuteTag = $ferien === null ? TimetableCalc::Schultag($heute, $samstag) : null;
            $tage     = [];
            /* ALLE sieben Tage, nicht nur die mit moeglichem Unterricht: ein
               fehlender Samstag sagt nichts, ein Samstag mit „Wochenende"
               darauf sagt, warum nichts da ist. Der Unterricht selbst kommt
               weiter nur an den Tagen aus Wochentage() vor — an den uebrigen
               findet TagesSlots() schlicht nichts. */
            $unterrichtstage = TimetableCalc::Wochentage((bool)$samstag['enabled']);
            foreach (TimetableCalc::AnzeigeTage() as $tag) {
                /* An einem Tag OHNE Unterricht bleibt die Liste leer, auch wenn
                   dort Stunden gespeichert sind. Gemessen an #22469: bei einem
                   Kind mit abgeschaltetem Samstag lag noch eine Musikstunde in
                   SlotsK2D6 — unsichtbar, solange der Samstag gar nicht im
                   Raster stand. Seit alle sieben Tage angezeigt werden, waere
                   sie ohne diesen Riegel wieder aufgetaucht, obwohl der Nutzer
                   den Samstag ausdruecklich abgeschaltet hat. */
                $tages  = in_array($tag, $unterrichtstage, true)
                    ? TimetableCalc::TagesSlots($tag, $kind, $slots) : [];
                /* FERIEN SCHLAGEN DEN UNTERRICHT. Die Ferienauskunft gilt fuer
                   genau dieses Datum, der Wochenplan nur fuer die Woche im
                   Allgemeinen — an einem Ferientag steht sonst die Vorlage da
                   und behauptet sechs Stunden Unterricht. Timeline, Karte im
                   Dashboard und die Schulzeile des Briefings halten es laengst
                   so; das Wochenraster war die letzte Stelle, die es nicht tat. */
                if (($ferienJeTag[$tag] ?? null) !== null) {
                    $tages = [];
                }
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
                        // Die Betreuung hat beides nicht; dort bleibt es leer.
                        'room'    => (string)($s['room'] ?? ''),
                        'teacher' => (string)($s['teacher'] ?? ''),
                        'status'  => (string)($s['status'] ?? ''),
                        // Ersetzte Lehrkraft bei einer Vertretung (nur aus dem Import).
                        'insteadOf' => (string)($s['insteadOf'] ?? ''),
                        /* Klassenarbeit: Merkmal und Titel (24.09.2026). Die
                           Karte ist die Ausgabe fuer Kachel, Gateway und App. */
                        'exam'      => ($s['exam'] ?? false) === true,
                        'examTitle' => (string)($s['examTitle'] ?? ''),
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
                    /* Das DATUM dieses Wochentags in der gezeigten Woche. Die
                       Kachel schreibt es hinter das Kuerzel, sobald ein Import
                       laeuft — ohne Import ist der Plan eine Vorlage, und ein
                       Datum an einer Vorlage behauptet mehr, als sie weiss. */
                    'date'    => TimetableCalc::DatumInWoche($heute, $tag),
                    // Ferien oder Feiertag AN DIESEM Tag, oder null.
                    'holiday' => $ferienJeTag[$tag] ?? null,
                    // Dauer JE TAG, nicht nur fuer heute: die Timeline laesst
                    // sich durch die Woche blaettern und braucht sie ueberall.
                    'minutes' => TimetableCalc::TagesDauer($tages),
                    'today'   => $tag === $heuteTag,
                    'parity'  => $tag === TimetableCalc::SAMSTAG && $samstag['biweekly']
                        ? ($samstag['parity'] === 'odd' ? 'ungerade KW' : 'gerade KW') : '',
                    'slots'   => $karten,
                    // Termine des Kindes an DIESEM Wochentag — leer, wenn der
                    // Schalter aus ist oder niemand zugeordnet hat.
                    'events'  => $termine[$kind['userId']][$tag] ?? [],
                ];
            }
            $heuteSlots = $heuteTag === null ? [] : TimetableCalc::TagesSlots($heuteTag, $kind, $slots);
            /* Ist die gezeigte Woche fuer dieses Kind schon durch? Gemessen am
               DATUM des letzten Tages mit Unterricht — nicht an „heute ist
               Samstag": ein Kind mit Samstagsunterricht ist am Samstag noch
               mitten in seiner Woche.

               Wozu: am Wochenende zeigte das Raster Montag bis Freitag der
               VERGANGENEN Woche, obwohl die kommende laengst importiert ist.
               Die Anzeige stellt sich damit von selbst auf die naechste Woche,
               solange der Nutzer nicht selbst blaettert. In den Ferien bleibt
               es beim aktuellen Bild: dort hat KEIN Tag Unterricht, der
               Vergleich greift also nicht — und die naechste Woche waeren
               vermutlich auch Ferien. */
            $letzterMitUnterricht = '';
            foreach ($tage as $t) {
                foreach ((array)$t['slots'] as $k) {
                    if (($k['care'] ?? false) !== true) {
                        $letzterMitUnterricht = max($letzterMitUnterricht, (string)$t['date']);
                        break;
                    }
                }
            }
            $ausgabe[] = [
                'name'    => $kind['name'],
                'color'   => $kind['color'],
                'userId'  => $kind['userId'],
                'avatar'  => (string)($bilder[$kind['userId']] ?? ''),
                'days'    => $tage,
                'weekOver' => $letzterMitUnterricht !== '' && $letzterMitUnterricht < $heute,
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
            /* Ob ueberhaupt ein Import vorliegt. Daran haengt in der Kachel
               beides: das Datum im Spaltenkopf und der Wochenwechsler. */
            'dated'    => $this->ImportStandVorhanden(),
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
                    $s['childName'] ?? $s['childId'], TimetableCalc::TagKurz((int)$s['weekday']),
                    $s['start'], $s['subjectId']);
            }
            $gegen = TimetableCalc::Konflikt($s, $slots);
            if ($gegen !== null) {
                $paar = [$s['id'], $gegen['id']];
                sort($paar);
                $konflikte[implode('|', $paar)] = sprintf('%s %s: %s–%s trifft %s–%s',
                    $s['childName'] ?? $s['childId'], TimetableCalc::TagKurz((int)$s['weekday']),
                    $s['start'], $s['end'], $gegen['start'], $gegen['end']);
            }
        }
        return ['konflikte' => array_values($konflikte), 'verwaist' => $verwaist];
    }
}

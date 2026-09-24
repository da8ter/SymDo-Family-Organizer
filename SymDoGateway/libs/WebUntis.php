<?php

declare(strict_types=1);

/**
 * WebUntis: Stundenplan und Vertretungen holen.
 *
 * Der Plan im Stundenplan-Modul stand bisher von Hand da — abgetippt aus einem
 * PDF. Was dort nie ankommt, sind Vertretungen, Entfall und Raumwechsel. Genau
 * die stehen in WebUntis, das die Schule ohnehin fuehrt.
 *
 * Der Weg ist der offiziell vorgesehene: die JSON-RPC unter
 * `/WebUntis/jsonrpc.do?school=…`. Untis stellt sie nach eigener Auskunft
 * „seit vielen Jahren auf Anfrage bereit, insbesondere fuer Schulprojekte,
 * Schuelerentwicklungen und kleinere Eigenentwicklungen".
 *
 * EINE FALLE, die Stunden kosten kann: Der Verteiler loest Methoden ueber Name
 * UND Parameterform auf. `authenticate` mit `params` als LISTE antwortet
 * „Method not found" (-32601), als OBJEKT „bad credentials" (-8504). Wer das
 * erste sieht, haelt die Anmeldung faelschlich fuer abgeschaltet und baut einen
 * Umweg ueber das Web-Formular, den es nicht braucht.
 *
 * Am 03.09.2026 an fuenf Schulen mit WebUntis 2027.1.3 geprueft. Welche, steht
 * hier bewusst nicht: die Datei liegt in einem oeffentlichen Repo, und die
 * Schule des Nutzers geht niemanden etwas an.
 */
trait WebUntis
{

    private const UNTIS_FEHLER_MAX  = 3;         // danach steht der Timer (Kontosperre!)
    /* So lange bleibt der Riegel zu, wenn ihn niemand von Hand loest. Danach
       gibt es wieder DREI Versuche, mehr nicht. Ohne diese Frist muesste man das
       Formular aufsuchen, nur weil das Kennwort einmal falsch stand. */
    private const UNTIS_SPERRE_FRIST = 6 * 3600;
    private const UNTIS_INTERVALL_STD = 60;      // Minuten
    private const UNTIS_PRUEF_ATTR    = 'UntisPruefungen';
    private const UNTIS_LERN_TAGE_STD = 7;       // Lern-Erinnerung so viele Tage vorher
    private const UNTIS_LERN_TAGE_MAX = 30;

    /* Die Ueberschneidungen im Plan dieses Kindes: je Wochentag und Uhrzeit
       die Faecher, die dort gleichzeitig stehen. Daraus baut das Formular eine
       Zeile je Entscheidung. Gesammelt wird NUR auf dem Wochenraster-Weg — der
       datierte Weg fuehrt durch dieselbe Funktion, und beide zusammen haetten
       jede Ueberschneidung mehrfach gemeldet. */
    /* Wer ist angemeldet? `authenticate` liefert personId und personType mit —
       und getTimetable verlangt IMMER ein Element, auch fuer den eigenen Plan
       („no element provided"). Das ist der Rueckfall, wenn in der Kinderliste
       nichts steht. */
    /* Der Bearer der REST-Ansicht (siehe UntisRest). Er entsteht aus der
       laufenden Sitzung und lebt genauso lange — einmal je Anmeldung holen
       genuegt. */

    // ────────────────────────────── Lebenszyklus ──────────────────────────────

    private function UntisCreate(): void
    {
        $this->RegisterPropertyBoolean('UntisEnabled', false);
        $this->RegisterPropertyString('UntisServer', '');
        $this->RegisterPropertyString('UntisSchool', '');
        $this->RegisterPropertyString('UntisUser', '');
        $this->RegisterPropertyString('UntisPassword', '');
        $this->RegisterPropertyInteger('UntisIntervalMinutes', self::UNTIS_INTERVALL_STD);
        /* Eigener Schalter fuer die Meldung aufs Telefon. Der Merker UntisLast
           wird trotzdem gefuehrt: wer sie spaeter einschaltet, bekommt nicht
           nachtraeglich alles, was in der Zwischenzeit war. */
        $this->RegisterPropertyBoolean('UntisPush', true);
        /* Hausaufgaben von der Schule holen. AUS als Vorgabe: es ist ein
           zusätzlicher Zugriff je Durchlauf, und wer seine Hausaufgaben von
           Hand pflegt, soll das durch ein Modul-Update nicht anders
           vorfinden. */
        $this->RegisterPropertyBoolean('UntisHomework', false);
        /* Pruefungen (24.09.2026): melden, wenn eine im Plan erscheint, verlegt
           wird oder entfaellt — und am Vorabend erinnern (zur Uhrzeit der
           Hausaufgaben-Erinnerung, Minutentakt in WebPush). Eigener Schalter:
           wer Vertretungen stumm haben will, will die Klassenarbeit trotzdem
           wissen. */
        $this->RegisterPropertyBoolean('UntisExamPush', true);
        /* So viele Tage vor einer Pruefung erscheint eine Lern-Erinnerung in den
           Hausaufgaben des Kindes, faellig am Vortag. 0 = aus. */
        $this->RegisterPropertyInteger('UntisExamStudyDays', self::UNTIS_LERN_TAGE_STD);
        /* Je Kind: Anzeigename, Ziel-Stundenplan und wessen Plan geholt wird.
           Leerer Elementtyp = der Plan des angemeldeten Kontos selbst. */
        $this->RegisterPropertyString('UntisStudents', '[]');
        /* Das alte Suchfeld. Es steht nicht mehr im Formular (die Suche las die
           Schuelerliste der ganzen Schule), bleibt aber REGISTRIERT: ein
           Formularfeld ohne Eigenschaft laesst „Uebernehmen" fuer die GANZE
           Konfiguration scheitern, eine Eigenschaft ohne Feld ist harmlos. */
        $this->RegisterPropertyString('UntisSearchName', '');
        /* Die Kurswahl je Kind: eine Zeile je Fach, das im Plan vorkam, mit
           Haekchen „besucht". Sie ersetzt die getippte Kursliste — Fachnamen
           von Hand zu treffen war die haeufigste Fehlerquelle daran.

           Die Zeilen entstehen beim Aufbau des Formulars aus dem, was der
           letzte Durchlauf gefunden hat (Attribut unten), und werden beim
           Uebernehmen zurueckgeschrieben. Dasselbe Verfahren wie bei
           TimetableChoice. */
        $this->RegisterPropertyString('UntisCourses', '[]');
        /* Was der letzte Durchlauf im Plan gefunden hat: Fach, ob es zu einer
           Zeit mit mehreren stand, und zu welchem Kind. Ein Attribut und keine
           Eigenschaft — sonst muesste der Lauf IPS_ApplyChanges auf die eigene
           Instanz rufen, und das ist ein Griff ins eigene Getriebe. */
        $this->RegisterAttributeString('UntisCourseFound', '[]');
        /* Die Kinder des angemeldeten Kontos — id und Anzeigename, wie WebUntis
           sie nennt. Gefuellt vom Knopf „Schueler abrufen", gelesen von der
           Auswahl im Formular. Sie stehen damit in der settings.json; das ist
           der Preis dafuer, dass die Spalte einen NAMEN zeigt und nicht eine
           Nummer. Die Schuelerliste der Schule kommt hier nie hinein. */
        $this->RegisterAttributeString('UntisAccountStudents', '[]');
        $this->RegisterAttributeString('UntisLast', '{}');    // letzter Stand je Kind
        $this->RegisterAttributeString('UntisStatus', '{}');  // Statuszeile im Formular
        $this->RegisterAttributeInteger('UntisFails', 0);
        // Wann der Riegel zufiel — er oeffnet sich nach einer Frist von selbst
        // wieder (siehe UntisGesperrt).
        $this->RegisterAttributeInteger('UntisFailAt', 0);
        /* Die gemerkten Pruefungen je Kind — Quelle fuer Liste, Briefing und
           die Vorabend-Meldung. Einziger Schreiber: UntisPruefungenEinpflegen. */
        $this->RegisterAttributeString(self::UNTIS_PRUEF_ATTR, '{}');
        $this->RegisterTimer('UntisScan', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'UntisScan\', 0);');
    }

    private function UntisApplyChanges(): void
    {
        /* HIER faellt der Fehlerzaehler NICHT mehr.
           Frueher tat er es, mit der Begruendung „Speichern heisst, jemand hat
           etwas geaendert". Das stimmt nur fuers Formular: IPS_ApplyChanges auf
           dieser Instanz laeuft auch, wenn die App einen Namen oder ein
           Profilbild aendert (AppCore::UpdateAppUser) — und bei jedem
           Kernelstart. Jedes Mal fiel damit der Riegel gegen die WebUntis-
           KONTOSPERRE, und der Abruf klopfte mit demselben falschen Kennwort
           wieder an. Geloest wird er jetzt nur dort, wo wirklich jemand handelt
           („Verbindung testen") oder genug Zeit vergangen ist (UntisGesperrt). */
        $minuten = max(15, (int)$this->UntisProp('UntisIntervalMinutes', self::UNTIS_INTERVALL_STD));
        $an = $this->UntisIsEnabled() && $this->UntisKinder() !== [];
        @$this->SetTimerInterval('UntisScan', ($an && !$this->UntisGesperrt()) ? $minuten * 60000 : 0);
    }

    /**
     * Steht der Abruf wegen falscher Zugangsdaten?
     *
     * Der Riegel oeffnet sich nach UNTIS_SPERRE_FRIST von selbst — dann gibt es
     * wieder drei Versuche. Damit kostet ein falsches Kennwort hoechstens drei
     * Fehlanmeldungen alle sechs Stunden, statt drei bei jedem Kernelstart und
     * jeder Profilaenderung in der App.
     */
    private function UntisGesperrt(): bool
    {
        if ((int)@$this->ReadAttributeInteger('UntisFails') < self::UNTIS_FEHLER_MAX) {
            return false;
        }
        $seit = (int)@$this->ReadAttributeInteger('UntisFailAt');
        if ($seit > 0 && (time() - $seit) >= self::UNTIS_SPERRE_FRIST) {
            @$this->WriteAttributeInteger('UntisFails', 0);
            @$this->WriteAttributeInteger('UntisFailAt', 0);
            return false;
        }
        return true;
    }

    private function UntisRequestAction(string $Ident, mixed $Value): bool
    {
        if ($Ident === 'UntisScan') {
            $this->UntisScanRun();
            return true;
        }
        if ($Ident === 'UntisScanNow') {
            // Trockenlauf: holen und zeigen, aber weder schreiben noch melden.
            $this->UpdateFormField('UntisStatusLabel', 'caption', $this->UntisScanRun(true));
            return true;
        }
        if ($Ident === 'UntisScanApply') {
            $this->UpdateFormField('UntisStatusLabel', 'caption', $this->UntisScanRun(false));
            return true;
        }
        if ($Ident === 'UntisTest') {
            $this->UpdateFormField('UntisStatusLabel', 'caption', $this->UntisTestverbindung());
            return true;
        }
        if ($Ident === 'UntisFetchStudents') {
            $this->UntisKontoSchuelerHolen();
            return true;
        }
        return false;
    }

    // ──────────────────────────────── Anmeldung ────────────────────────────────

    /** „Verbindung testen": anmelden, Schuljahr lesen, wieder abmelden. */
    private function UntisTestverbindung(): string
    {
        /* Dieser Knopf IST die Ansage „ich habe die Zugangsdaten geprueft" —
           also faellt der Riegel hier, und nur hier von Hand. Ein Fehlversuch
           zaehlt danach sofort wieder (UntisFehlerZaehlen), das Schlupfloch zum
           Sperren des Kontos ist damit nicht offen: drei Knopfdruecke, dann
           steht es wieder. */
        @$this->WriteAttributeInteger('UntisFails', 0);
        @$this->WriteAttributeInteger('UntisFailAt', 0);
        $an = $this->UntisLogin();
        if (($an['ok'] ?? false) !== true) {
            $this->UntisFehlerZaehlen((int)($an['code'] ?? 0));
            $text = $this->Translate('Login failed: ') . (string)($an['message'] ?? '?');
            $this->UntisStatusSchreiben($text);
            return $text;
        }
        $jahr = $this->UntisRpc('getCurrentSchoolyear');
        $name = (string)(($jahr['result']['name']) ?? '');
        /* Die Kinder des Kontos gleich mitnennen: bei einem Elternzugang ist das
           die Auskunft, die man sucht — und der Abruf braucht dann nichts weiter.
           Sie stehen NUR im offenen Formular; der gemerkte Status wuerde die
           Namen in die weltlesbare settings.json schreiben. */
        $kinder = in_array((int)$this->untisIch['type'],
            [self::UNTIS_TYP_KLASSE, self::UNTIS_TYP_SCHUELER], true)
            ? [] : $this->UntisKinderDesKontos();
        $this->UntisLogout();
        @$this->WriteAttributeInteger('UntisFails', 0);
        $text = $name !== ''
            ? sprintf($this->Translate('Connected — school year "%s".'), $name)
            : $this->Translate('Login worked, but the school year is unreadable.');
        // Auch merken, nicht nur ins offene Formular schreiben: sonst ist das
        // Ergebnis beim naechsten Oeffnen weg.
        $this->UntisStatusSchreiben($text);
        if ($kinder !== []) {
            $text .= ' ' . sprintf($this->Translate('Children on the account: %s — nothing to enter, the fetch takes them itself.'),
                implode(', ', array_map(
                    static fn(array $k): string => $k['id'] . ' · ' . $k['name'], $kinder)));
        }
        return $text;
    }

    /**
     * Die Formularzeilen der Kurswahl — Liste plus Erklaerung.
     *
     * Sie steht UNTER der Kinderliste: erst wer, dann was. Ohne einen einzigen
     * Durchlauf ist sie leer, und dann sagt der Text, woran es liegt — eine
     * leere Liste ohne Begruendung sieht nach einem Fehler aus.
     *
     * @return list<array<string,mixed>>
     */
    private function UntisKurswahlFelder(): array
    {
        $zeilen = $this->UntisKurseZeilen();
        /* KEINE Ueberschneidung, keine Kurswahl — und dann auch kein Kasten und
           kein Satz darueber. Ob ein Plan Ueberschneidungen enthaelt, haengt an
           der Schule: fragt das Modul den persoenlichen Plan und hat die Schule
           die Kurse je Schueler zugeordnet, ist nichts zu waehlen; ein
           Klassenplan enthaelt dagegen alle parallelen Kurse. Beides kommt vor,
           und allgemein entscheiden laesst es sich nicht — also zeigt sich die
           Wahl genau dort, wo es eine gibt. */
        if ($zeilen === []) {
            return [];
        }
        return [
            ['type' => 'Label', 'caption' => $this->Translate('One row per overlap: on this weekday at this time several lessons stand at once. Pick the one the child attends — the others are left out.')],
            ['type' => 'List', 'name' => 'UntisCourses', 'rowCount' => 6,
             'add' => false, 'delete' => false,
             'caption' => $this->Translate('Course choice'),
             'columns' => [
                 /* Zwei Fallen in einer Zeile, beide hier gemessen:
                    - Eine Spalte OHNE `edit` faellt beim Uebernehmen lautlos
                      weg, wenn sie nicht `save` traegt. Kennung, Wochentag und
                      Uhrzeit muessen mit, sonst weiss die Wahl nicht, wozu sie
                      gehoert.
                    - Eine bearbeitbare Spalte braucht ZUSAETZLICH `add` — den
                      Wert einer neuen Zeile. Beide Listen, die hier
                      nachweislich bedienbar sind (UntisStudents und die des
                      Aemtchenplans), tragen ihn; ohne ihn blieb das Dropdown
                      unbedienbar. */
                 ['caption' => $this->Translate('Child'), 'name' => 'kind', 'width' => '110px',
                  'add' => '', 'save' => true],
                 ['caption' => $this->Translate('Weekday'), 'name' => 'tag', 'width' => '120px',
                  'add' => '', 'save' => true],
                 ['caption' => $this->Translate('Time'), 'name' => 'zeit', 'width' => '80px',
                  'add' => '', 'save' => true],
                 ['caption' => $this->Translate('At the same time'), 'name' => 'stehen',
                  'width' => 'auto', 'add' => '', 'save' => true],
                 ['caption' => $this->Translate('Attends'), 'name' => 'kurs', 'width' => '240px',
                  'add' => '', 'edit' => ['type' => 'Select', 'options' => $this->UntisKursOptionen()]],
                 // Kennung und Wochentagszahl braucht das Modul, nicht der Mensch.
                 ['caption' => $this->Translate('ID'), 'name' => 'userId', 'width' => '1px',
                  'add' => '', 'save' => true],
                 ['caption' => '#', 'name' => 'wt', 'width' => '1px', 'add' => 0, 'save' => true],
             ],
             'values' => $zeilen],
        ];
    }

    /**
     * Die gefundenen Faecher dieses Kindes ins Attribut legen.
     *
     * Die Faecher der ANDEREN Kinder bleiben stehen: gelesen wird je Kind, und
     * ein Lauf, der nur eines betrifft, darf die Wahl der anderen nicht
     * wegnehmen.
     */
    private function UntisKurseMerken(string $userId, array $slots): void
    {
        if ($userId === '') {
            return;
        }
        $alt = (array)json_decode((string)@$this->ReadAttributeString('UntisCourseFound'), true);
        $raus = [];
        foreach ($alt as $z) {
            if (is_array($z) && trim((string)($z['userId'] ?? '')) !== $userId) {
                $raus[] = $z;
            }
        }
        ksort($slots);
        foreach ($slots as $k) {
            $faecher = array_keys((array)($k['faecher'] ?? []));
            sort($faecher);
            $raus[] = ['userId' => $userId, 'wt' => (int)$k['wt'],
                       'zeit' => (string)$k['zeit'], 'faecher' => $faecher];
        }
        @$this->WriteAttributeString('UntisCourseFound',
            (string)json_encode($raus, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Die Zeilen der Kurswahl fuer das Formular: gefundene Faecher, mit der
     * gespeicherten Wahl darueber.
     *
     * Neu gefundene Faecher stehen auf „besucht" — der Normalfall ist, dass ein
     * Fach im Plan auch besucht wird. Abgewaehlt wird, was nicht stimmt.
     *
     * @return list<array<string,mixed>>
     */
    private function UntisKurseZeilen(): array
    {
        /* Die getroffene Wahl: je Kind, Wochentag und Uhrzeit ein Fach. */
        $wahl = [];
        foreach ((array)json_decode((string)$this->UntisProp('UntisCourses', '[]'), true) as $z) {
            if (!is_array($z)) {
                continue;
            }
            $u = trim((string)($z['userId'] ?? ''));
            if ($u === '' || (int)($z['wt'] ?? 0) <= 0) {
                continue;
            }
            $wahl[$u . '|' . (int)$z['wt'] . '|' . trim((string)($z['zeit'] ?? ''))]
                = trim((string)($z['kurs'] ?? ''));
        }
        $namen = $this->UntisMitglieder();
        $zeilen = [];
        foreach ((array)json_decode((string)@$this->ReadAttributeString('UntisCourseFound'), true) as $z) {
            if (!is_array($z)) {
                continue;
            }
            $u  = trim((string)($z['userId'] ?? ''));
            $wt = (int)($z['wt'] ?? 0);
            $zeit = trim((string)($z['zeit'] ?? ''));
            $faecher = array_values(array_filter(array_map('strval', (array)($z['faecher'] ?? []))));
            if ($u === '' || $wt <= 0 || $zeit === '' || count($faecher) < 2) {
                continue;
            }
            $zeilen[] = [
                'userId'  => $u,
                'kind'    => (string)($namen[$u] ?? $u),
                'wt'      => $wt,
                'tag'     => $this->UntisTagName($wt),
                'zeit'    => $zeit,
                /* Was hier gleichzeitig steht — als Text, damit die Zeile auch
                   ohne aufgeklappte Auswahl vollstaendig ist. */
                'stehen'  => implode(', ', $faecher),
                'kurs'    => $wahl[$u . '|' . $wt . '|' . $zeit] ?? '',
            ];
        }
        usort($zeilen, static fn(array $a, array $b): int =>
            [$a['kind'], $a['wt'], $a['zeit']] <=> [$b['kind'], $b['wt'], $b['zeit']]);
        return $zeilen;
    }

    /** Der Wochentag als Wort. 1 = Montag, wie date('N'). */
    private function UntisTagName(int $wt): string
    {
        $tage = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday',
                 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
        return array_key_exists($wt, $tage) ? $this->Translate($tage[$wt]) : (string)$wt;
    }

    /**
     * Alle Faecher, die in irgendeiner Ueberschneidung dieses Bestands stehen.
     *
     * Eine Listenspalte hat EINE Auswahl fuer alle Zeilen — Symcon kann sie
     * nicht je Zeile fuellen. Deshalb enthaelt das Dropdown die Faecher aller
     * Ueberschneidungen; welche zu welcher Zeile gehoeren, steht in der Zeile
     * („stehen"). Ein Griff daneben ist folgenlos: er trifft die Stunde nicht,
     * die Ueberschneidung bleibt offen und die Statuszeile sagt es.
     *
     * @return list<array{caption:string,value:string}>
     */
    private function UntisKursOptionen(): array
    {
        $faecher = [];
        foreach ((array)json_decode((string)@$this->ReadAttributeString('UntisCourseFound'), true) as $z) {
            foreach ((array)($z['faecher'] ?? []) as $f) {
                $f = trim((string)$f);
                if ($f !== '') {
                    $faecher[mb_strtolower($f)] = $f;
                }
            }
        }
        asort($faecher);
        $raus = [['caption' => $this->Translate('— not chosen —'), 'value' => '']];
        foreach ($faecher as $f) {
            $raus[] = ['caption' => $f, 'value' => $f];
        }
        return $raus;
    }

    /**
     * Die Spalten der Schuelerliste. Sie stehen HIER und nicht im Formularbau,
     * weil der Knopf „Schueler abrufen" dieselben Spalten frisch setzen muss
     * (UpdateFormField) — zwei Fassungen liefen unweigerlich auseinander.
     *
     * @return list<array<string,mixed>>
     */
    private function UntisStudentsSpalten(): array
    {
        $mitglieder = [['caption' => $this->Translate('— none —'), 'value' => '']];
        foreach ($this->UntisMitglieder() as $id => $name) {
            /* (string) ist PFLICHT: die Kennung ist eine Zeichenkette, aber als
               ARRAYSCHLUESSEL macht PHP aus „57648139" die Zahl 57648139 — und
               dann stimmt der Wert der Auswahl nicht mehr mit dem ueberein, was
               in der Zeile steht. */
            $mitglieder[] = ['caption' => $name, 'value' => (string)$id];
        }
        /* Die Auswahl „WebUntis Name": ausschliesslich die Kinder DIESES Kontos
           — bei einem Schuelerzugang das Konto selbst. Kein „automatisch": die
           Spalte soll einen NAMEN zeigen, auch wenn es nur einer ist. Die
           Schuelerliste der Schule kommt nie in dieses Formular.

           Steht in einer Zeile noch keine Nummer (0), aendert das nichts am
           Abruf: der loest weiter selbst auf (Konto bzw. Kind mit passendem
           Namen). Die Spalte zeigt bis zur Wahl die 0. */
        $wahl = [];
        foreach ((array)json_decode((string)@$this->ReadAttributeString('UntisAccountStudents'), true) as $k) {
            if (!is_array($k) || (int)($k['id'] ?? 0) <= 0) {
                continue;
            }
            $name = trim((string)($k['name'] ?? ''));
            $wahl[] = ['caption' => $name !== ''
                           ? $name : sprintf($this->Translate('Element %d'), (int)$k['id']),
                       'value'   => (int)$k['id']];
        }
        return [
            ['caption' => $this->Translate('Timetable instance'), 'name' => 'stpl', 'width' => '220px',
             'add' => 0, 'edit' => ['type' => 'SelectInstance']],
            /* EIN Feld fuer das Kind. Daraus folgen Anzeigename, das Kind in der
               Zielinstanz (ueber Children[].userId) und das Ziel der Meldung —
               statt dreier Spalten, die alle dasselbe meinten. */
            ['caption' => $this->Translate('Family member'), 'name' => 'userId', 'width' => '160px',
             'add' => '', 'edit' => ['type' => 'Select', 'options' => $mitglieder]],
            /* „automatisch" deckt fast alles ab: bei einem Schuelerkonto ist das
               Konto selbst das Element, bei einem Elternkonto nimmt der Abruf
               das Kind, dessen Name zum Familienmitglied passt. Gewaehlt wird
               nur, wenn das nicht eindeutig ist. */
            ['caption' => $this->Translate('WebUntis name'), 'name' => 'elementId', 'width' => '200px',
             'add' => 0, 'edit' => ['type' => 'Select', 'options' => $wahl]],
        ];
    }

    /**
     * Die Schueler DIESES Kontos holen und als Auswahl ins Formular schreiben.
     *
     * Vorher stand hier eine Suche ueber `getStudents` — und die liefert, wenn
     * die Schule die Rechte weit gesetzt hat, die Schuelerliste der GANZEN
     * Schule. Das ist mehr, als dieses Modul braucht, und mehr, als jemand
     * ueber ein Formularfeld sehen sollte. Der Weg hier fragt stattdessen
     * `app/data` und bekommt genau die Kinder, die am angemeldeten Konto
     * haengen — dieselben, die die Untis-App zeigt.
     *
     * Eine Anmeldung je Druck. Der Riegel gilt: nach drei Fehlversuchen sperrt
     * WebUntis das Konto, und dieser Knopf ist kein Schlupfloch daran vorbei.
     */
    private function UntisKontoSchuelerHolen(): void
    {
        if ($this->UntisGesperrt()) {
            $this->UpdateFormField('UntisStatusLabel', 'caption',
                $this->Translate('Paused after repeated login failures — press „Test connection" to try again.'));
            return;
        }
        $an = $this->UntisLogin();
        if (($an['ok'] ?? false) !== true) {
            $this->UntisFehlerZaehlen((int)($an['code'] ?? 0));
            $this->UpdateFormField('UntisStatusLabel', 'caption',
                $this->Translate('Login failed: ') . (string)($an['message'] ?? '?'));
            return;
        }
        /* Elternzugang: die Kinder am Konto. Schuelerzugang: das Konto selbst,
           mit seinem Namen — beides liefert UntisKinderDesKontos(). */
        $kinder = $this->UntisKinderDesKontos();
        $this->UntisLogout();
        @$this->WriteAttributeInteger('UntisFails', 0);

        @$this->WriteAttributeString('UntisAccountStudents',
            (string)json_encode($kinder, JSON_UNESCAPED_UNICODE));
        // Die Auswahl der offenen Liste sofort nachziehen, damit niemand das
        // Formular schliessen und wieder oeffnen muss.
        $this->UpdateFormField('UntisStudents', 'columns',
            (string)json_encode($this->UntisStudentsSpalten(), JSON_UNESCAPED_UNICODE));
        $this->UpdateFormField('UntisStatusLabel', 'caption', $kinder === []
            ? $this->Translate('Nothing found on this account — neither children nor an own element.')
            : sprintf($this->Translate('%d student(s) fetched — pick them in the column „WebUntis name".'),
                count($kinder)));
    }

    /**
     * Fehlschlaege zaehlen und ab dem dritten den Timer abstellen.
     *
     * WebUntis SPERRT Konten nach mehreren Fehlversuchen. Ein Timer, der alle
     * 60 Minuten mit falschem Kennwort anklopft, sperrt also das Konto der
     * Eltern — deshalb dieser Riegel. Ein Speichern im Formular hebt ihn auf
     * (UntisApplyChanges).
     */
    private function UntisFehlerZaehlen(int $code): void
    {
        // Nur ANMELDE-Fehler zaehlen; ein Netzausfall darf nicht sperren.
        if ($code !== -8504) {
            return;
        }
        $n = (int)@$this->ReadAttributeInteger('UntisFails') + 1;
        @$this->WriteAttributeInteger('UntisFails', $n);
        // Der Zeitpunkt entscheidet, wann der Riegel von selbst wieder aufgeht.
        @$this->WriteAttributeInteger('UntisFailAt', time());
        if ($n >= self::UNTIS_FEHLER_MAX) {
            @$this->SetTimerInterval('UntisScan', 0);
            $this->LogMessage(sprintf(
                'SymDo WebUntis: %d× falsche Zugangsdaten — Abruf angehalten, damit das Konto nicht gesperrt wird. Zugangsdaten prüfen und „Verbindung testen" drücken.',
                $n), KL_ERROR);
        }
    }

    // ──────────────────────────────── Der Lauf ────────────────────────────────

    private function UntisScanRun(bool $trocken = false): string
    {
        if (!$this->UntisIsEnabled()) {
            return $this->Translate('WebUntis is switched off.');
        }
        $kinder = $this->UntisKinder();
        if ($kinder === []) {
            return $this->Translate('No student entered yet.');
        }
        /* Auch der Trockenlauf steht still. Frueher lief er weiter (`&& !$trocken`)
           — und weil er sich anmeldet, war der Knopf das Schlupfloch, durch das
           man das Konto trotz Riegel sperren konnte. */
        if ($this->UntisGesperrt()) {
            return $this->Translate('Paused after repeated login failures — press „Test connection" to try again.');
        }
        /* Bedient ein Scanner WebUntis, wird hier nur noch ein AUFTRAG
           abgelegt. Der Zeitgeber bleibt AN — er ist die einzige Uhr dieses
           Laufs; der Scanner arbeitet nur ab, was im Kanal liegt.

           Der TROCKENLAUF bleibt ausdruecklich hier: „Verbindung testen" und
           „Jetzt pruefen" sollen sagen, ob Zugang und Zuordnung stimmen, und
           darauf wartet jemand vor dem Formular. Ein Auftrag kaeme Minuten
           spaeter. */
        if (!$trocken && $this->ScanQuelleUebernommen('untis')) {
            return $this->ScanAuftragGeben('untis', ['anlass' => 'timer', 'kinder' => $kinder])
                ? $this->Translate('Order placed — the scanner is working on it. '
                    . 'The report appears here when it is done.')
                : $this->Translate('No student entered yet.');
        }

        $ernte = $this->UntisKontoErnten($kinder, !$trocken);
        if (($ernte['ok'] ?? false) !== true) {
            $this->UntisFehlerZaehlen((int)($ernte['code'] ?? 0));
            $text = $this->Translate('Login failed: ') . (string)($ernte['meldung'] ?? '?');
            $this->UntisStatusSchreiben($text);
            return $text;
        }
        @$this->WriteAttributeInteger('UntisFails', 0);

        $text = $this->UntisErnteEinpflegen($ernte, $trocken);
        $this->UntisStatusSchreiben($text);
        return $text;
    }

    /**
     * Den Umschlag eines WebUntis-Laufs einpflegen.
     *
     * Der Fehlerzaehler wird HIER gefuehrt und nirgends sonst: an ihm haengt
     * der Schutz vor der Kontosperre, und er ist ein Attribut dieser Instanz.
     * Der Scanner meldet nur, WAS passiert ist.
     *
     * @param array<string,mixed> $umschlag
     */
    private function UntisUmschlagEinpflegen(array $umschlag): string
    {
        $block = is_array($umschlag['untis'] ?? null) ? $umschlag['untis'] : [];
        if (($block['ok'] ?? false) !== true) {
            /* Eine gescheiterte Anmeldung zaehlt — sonst klopfte ein toter
               Zugang sechsmal am Tag an, und WebUntis sperrt nach dreien. */
            $this->UntisFehlerZaehlen((int)($block['code'] ?? 0));
            $text = $this->Translate('Login failed: ')
                . (string)($umschlag['status']['text'] ?? '?');
            $this->UntisStatusSchreiben($text);
            return $text;
        }
        @$this->WriteAttributeInteger('UntisFails', 0);
        $text = $this->UntisErnteEinpflegen(['kinder' => (array)($block['kinder'] ?? [])], false);
        $this->UntisStatusSchreiben($text);
        return $text;
    }

    /**
     * Eine ganze Ernte einpflegen — die schreibende Haelfte eines Laufs.
     *
     * Sie laeuft IMMER im Gateway, ob die Ernte aus dem eigenen Lauf kommt
     * oder als Umschlag aus der zweiten Spur.
     *
     * @param array<string,mixed> $ernte
     */
    private function UntisErnteEinpflegen(array $ernte, bool $trocken = false): string
    {
        $teile = [];
        foreach ((array)($ernte['kinder'] ?? []) as $eintrag) {
            if (!is_array($eintrag)) {
                continue;
            }
            $kind = is_array($eintrag['kind'] ?? null) ? $eintrag['kind'] : [];
            if (trim((string)($kind['name'] ?? '')) === '') {
                continue;
            }
            $teile[] = $this->UntisKindEinpflegen($kind, $eintrag, $trocken);
        }
        return implode(' | ', array_filter($teile));
    }

    /**
     * Die Ernte eines Kindes einpflegen — die schreibende Haelfte.
     *
     * Sie laeuft IMMER im Gateway: hier liegen der Stundenplan (ueber
     * `STPL_ImportSlots`), der Merker der schon gemeldeten Aenderungen
     * (`UntisLast`), die Kursliste des Formulars (`UntisCourseFound`) und die
     * Hausaufgaben.
     *
     * @param array<string,mixed> $ernte
     */
    private function UntisKindEinpflegen(array $kind, array $ernte, bool $trocken): string
    {
        /* Die Pruefungen ZUERST: auch ein Lauf ohne eine einzige Stunde im
           Planfenster (Ferien) kennt die Klassenarbeit danach. `null` heisst
           „unbekannt" (RPC-Rueckfall, gescheiterter Abruf) — dann bleibt der
           gemerkte Stand, wie er ist, samt Lern-Erinnerungen. */
        $pruefText = '';
        $pruefungen = $ernte['pruefungen'] ?? null;
        if (is_array($pruefungen)) {
            $pruefText = $trocken
                ? sprintf($this->Translate('%d exam(s)'), count($pruefungen))
                : $this->UntisPruefungenEinpflegen($kind, $pruefungen);
        }
        if (($ernte['ok'] ?? false) !== true) {
            return (string)($ernte['meldung'] ?? '') . ($pruefText === '' ? '' : ', ' . $pruefText);
        }
        $stunden    = (int)($ernte['stunden'] ?? 0);
        $auffaellig = (array)($ernte['auffaellig'] ?? []);
        $offen      = (int)($ernte['offen'] ?? 0);
        $verworfen  = (array)($ernte['verworfen'] ?? []);
        $klassenText = (string)($ernte['klassen'] ?? '');

        /* Die Kursliste des Formulars wird AUCH im Trockenlauf nachgezogen: sie
           schreibt keinen Plan und meldet nichts, sie ist eine Hilfe beim
           Einrichten und keine Wirkung nach aussen. */
        $this->UntisKurseMerken((string)($kind['userId'] ?? ''), (array)($ernte['slots'] ?? []));

        /* Was eine ungeklaerte Ueberschneidung an Meldungen verschluckt hat,
           steht in der Statuszeile — sonst faellt der Entfall aus Push und
           Briefing weg, und niemand erfaehrt, dass es ihn gab. Die Abhilfe ist
           immer dieselbe: den Kurs in die Kursliste des Kindes eintragen. */
        $verlust = $verworfen === [] ? '' : ' — ' . sprintf(
            $this->Translate('%1$d message(s) unassigned (%2$s) — add the course to the child\'s course list'),
            count($verworfen),
            implode(', ', array_slice($verworfen, 0, 3)) . (count($verworfen) > 3 ? ' …' : '')
        );
        if ($trocken) {
            /* Trockenlauf: NICHTS schreiben, NICHT melden und den Merker nicht
               anfassen. Sonst gaelten die Aenderungen als gemeldet, ohne dass
               jemand sie gesehen hat — und der echte Lauf schwiege dann. */
            return sprintf($this->Translate('%1$s: %2$d lesson(s), %3$d change(s), %4$d unresolved overlap(s) — dry run, nothing written'),
                $kind['name'], $stunden, count($auffaellig), $offen) . $verlust
                . ($klassenText === '' ? '' : ', ' . sprintf($this->Translate('class %s'), $klassenText))
                . ($pruefText === '' ? '' : ', ' . $pruefText);
        }
        $tage    = (array)($ernte['tage'] ?? []);
        $datiert = (array)($ernte['datiert'] ?? []);
        $eingespielt = ((int)$kind['stpl'] > 0 && $tage !== []) ? $this->UntisEinspielen($kind, $tage) : 0;
        /* Zweiter Aufruf, datiert: der Wochenplan zeigt die REGELWOCHE, die
           Ebene darueber den einzelnen Tag mit Entfall, Vertretung und
           Projekttag. Beides zusammen, weil beides etwas anderes beantwortet:
           „wie sieht ein Dienstag aus" und „was ist am Dienstag". */
        $datierteTage = ((int)$kind['stpl'] > 0 && $datiert !== []) ? $this->UntisEinspielen($kind, $datiert) : 0;
        $neu = $this->UntisAenderungenMelden($kind, $auffaellig);

        $hausaufgaben = '';
        $zeilen = $ernte['hausaufgaben'] ?? null;
        if (is_array($zeilen)) {
            [$von, $bis] = $this->UntisFenster();
            $hausaufgaben = trim((string)($kind['userId'] ?? '')) === ''
                ? $this->Translate('homework: no family member assigned')
                : $this->UntisHausaufgabenEinpflegen((string)$kind['userId'], $zeilen, $von, $bis);
        } elseif ($zeilen === null && (bool)$this->UntisProp('UntisHomework', false)) {
            $hausaufgaben = $this->Translate('homework: not available');
        }

        return sprintf($this->Translate('%1$s: %2$d lesson(s), %3$d change(s), %4$d new, %5$d weekday(s) + %6$d date(s) written, %7$d overlap(s) unresolved'),
            $kind['name'], $stunden, count($auffaellig), $neu, $eingespielt, $datierteTage, $offen)
            . $verlust . ($klassenText === '' ? '' : ', ' . sprintf($this->Translate('class %s'), $klassenText))
            . ($hausaufgaben === '' ? '' : ', ' . $hausaufgaben)
            . ($pruefText === '' ? '' : ', ' . $pruefText);
    }

    // ──────────────────────────────── Pruefungen ────────────────────────────────

    /**
     * Der gemerkte Stand. `da` sagt, ob es das Attribut schon gibt: vor dem
     * Neuladen des Moduls liest die Hilfe die Vorgabe, und wer dann jeden Lauf
     * mit „nichts gemerkt" vergleicht, meldet stuendlich alles als neu.
     *
     * @return array{v:int, rev:int, kinder:array<string,mixed>, da:bool}
     */
    private function UntisPruefungenStand(): array
    {
        $roh = $this->ReadAttributeStringSafe(self::UNTIS_PRUEF_ATTR, '');
        $d = json_decode($roh, true);
        $d = is_array($d) ? $d : [];
        return ['v' => 1, 'rev' => (int)($d['rev'] ?? 0),
                'kinder' => is_array($d['kinder'] ?? null) ? $d['kinder'] : [], 'da' => $roh !== ''];
    }

    /** Schreiben mit Rueckleseprobe — nur ein gelesener Stand ist ein gesicherter. */
    private function UntisPruefungenSchreiben(array $stand): bool
    {
        unset($stand['da']);
        $json = (string)json_encode($stand, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        @$this->WriteAttributeString(self::UNTIS_PRUEF_ATTR, $json);
        if ($this->ReadAttributeStringSafe(self::UNTIS_PRUEF_ATTR, '') !== $json) {
            $this->LogMessage('SymDo WebUntis: Attribut ' . self::UNTIS_PRUEF_ATTR
                . ' nicht beschreibbar — das Modul muss einmal neu geladen werden.', KL_ERROR);
            return false;
        }
        // Die Liste steht in den Hausaufgaben: andere Geraete sollen nachladen.
        $this->WsPushDirty();
        return true;
    }

    /**
     * Die Pruefungen eines Abrufs einpflegen: abgleichen, Lern-Erinnerungen
     * nachfuehren, merken, melden.
     *
     * Gemeldet wird NUR, wenn es fuer dieses Kind schon einen gesicherten Stand
     * gab und der neue gesichert ist. Der erste Lauf legt die Grundlage still —
     * sonst kaeme nach dem Update eine Meldung mit allem, was in acht Wochen
     * ansteht; und ein nicht schreibbares Attribut hiesse: jede Stunde dieselbe.
     *
     * @param list<array<string,mixed>> $neu die Pruefungen des Abrufs
     */
    private function UntisPruefungenEinpflegen(array $kind, array $neu): string
    {
        $userId = trim((string)($kind['userId'] ?? ''));
        if ($userId === '') {
            // Ohne Mitglied weiss niemand, wessen Pruefung es ist — die
            // Statuszeile sagt es schon bei den Hausaufgaben.
            return '';
        }
        $stand = $this->UntisPruefungenStand();
        if (!$stand['da']) {
            return $this->Translate('exams: waiting for the module to be reloaded');
        }
        $heute = date('Y-m-d');
        $neu = array_values(array_filter($neu,
            static fn(mixed $p): bool => is_array($p) && (string)($p['date'] ?? '') >= $heute));
        $vorher = is_array($stand['kinder'][$userId] ?? null) ? $stand['kinder'][$userId] : null;
        $hatteStand = $vorher !== null && (int)($vorher['seit'] ?? 0) > 0;
        $alt = $hatteStand ? array_values(array_filter((array)($vorher['exams'] ?? []), 'is_array')) : [];

        $erg = UntisPruefungCalc::Abgleichen($alt, $neu, time());
        $liste = $this->UntisLernAbgleich($userId, $erg['liste'], $erg['weg'], $heute);
        if ($liste === null) {
            // Die Hausaufgaben waren gerade gesperrt: alles beim naechsten Lauf,
            // sonst gingen Loeschungen fuer verschwundene Pruefungen verloren.
            return $this->Translate('exams: homework busy, next run');
        }
        $zahl = count(array_filter($liste, static fn(array $p): bool => (string)$p['status'] !== 'entfall'));
        $eintrag = ['seit' => $hatteStand ? (int)$vorher['seit'] : time(),
                    'name' => (string)($kind['name'] ?? ''), 'exams' => $liste];
        if ($hatteStand && $eintrag == $vorher) {
            return sprintf($this->Translate('%d exam(s)'), $zahl);
        }
        $stand['kinder'][$userId] = $eintrag;
        $stand['rev'] = (int)$stand['rev'] + 1;
        if (!$this->UntisPruefungenSchreiben($stand)) {
            return $this->Translate('exams: not saved');
        }
        if ($hatteStand) {
            $this->UntisPruefungenMelden($kind, $erg);
        }
        return sprintf($this->Translate('%d exam(s)'), $zahl);
    }

    /**
     * Die Lern-Erinnerungen eines Kindes nachfuehren.
     *
     * Einmal angelegt, danach nur noch NACHGEFUEHRT: verlegt → Faelligkeit und
     * Notiz ziehen mit; entfaellt, verschwindet oder ist der Tag da → weg,
     * solange noch offen. Abgehakt bleibt stehen. Und selbst geloescht bleibt
     * geloescht — `lern` behaelt die Kennung, es wird nicht neu angelegt.
     *
     * Die Erinnerung ist eine gewoehnliche Hausaufgabe (Herkunft `exam`), die
     * Pruefung steht als `srcId` daran: so findet ein zweiter Lauf sie auch
     * dann, wenn der gemerkte Stand einmal nicht gespeichert werden konnte —
     * sie entsteht nie doppelt.
     *
     * @param list<array<string,mixed>> $liste
     * @param list<array<string,mixed>> $weg verschwundene Pruefungen
     * @return list<array<string,mixed>>|null null = Hausaufgaben gesperrt
     */
    private function UntisLernAbgleich(string $userId, array $liste, array $weg, string $heute): ?array
    {
        $tage = max(0, min(self::UNTIS_LERN_TAGE_MAX,
            (int)$this->UntisProp('UntisExamStudyDays', self::UNTIS_LERN_TAGE_STD)));
        if (!in_array($userId, $this->HomeworkKinder(), true)) {
            return $liste;     // kein Kind: keine Hausaufgaben, also auch keine Erinnerung
        }
        $bestand = [];
        $nachQuelle = [];
        foreach ($this->HomeworkItems() as $i) {
            $bestand[(string)$i['id']] = $i;
            if ((string)($i['source'] ?? '') === 'exam' && (string)($i['childId'] ?? '') === $userId
                && (int)($i['srcId'] ?? 0) > 0) {
                $nachQuelle[(int)$i['srcId']] = $i;
            }
        }
        $offen = static fn(?array $i): bool => $i !== null && ($i['done'] ?? false) !== true;

        $anlegen = [];
        $aendern = [];
        $loeschen = [];
        foreach ($liste as $n => $p) {
            $key = (int)$p['key'];
            $lern = (string)($p['lern'] ?? '');
            // Verloren gegangene Kennung (Stand nicht gespeichert): ueber die Quelle.
            if ($lern === '' && isset($nachQuelle[$key])) {
                $lern = (string)$nachQuelle[$key]['id'];
                $liste[$n]['lern'] = $lern;
                // Passt ihre Faelligkeit nicht mehr, gilt sie als verlegt.
                $liste[$n]['lernFuer'] = (string)$nachQuelle[$key]['due']
                    === UntisPruefungCalc::LernDue((string)$p['date'], $heute) ? (string)$p['date'] : '';
                $p['lernFuer'] = $liste[$n]['lernFuer'];
            }
            if ($lern !== '') {
                $item = $bestand[$lern] ?? null;
                $vorbei = (string)$p['status'] === 'entfall' || (string)$p['date'] <= $heute;
                if ($offen($item) && $vorbei) {
                    $loeschen[] = $lern;
                } elseif ($offen($item) && (string)($p['lernFuer'] ?? '') !== (string)$p['date']) {
                    $aendern[$lern] = ['due' => UntisPruefungCalc::LernDue((string)$p['date'], $heute),
                                       'note' => $this->UntisLernNotiz($p)];
                    $liste[$n]['lernFuer'] = (string)$p['date'];
                }
                continue;
            }
            if (UntisPruefungCalc::LernFaellig($p, $heute, $tage)) {
                $anlegen[$n] = [
                    'childId' => $userId,
                    'subject' => (string)$p['subject'],
                    'due'     => UntisPruefungCalc::LernDue((string)$p['date'], $heute),
                    'note'    => $this->UntisLernNotiz($p),
                    'source'  => 'exam',
                    'srcId'   => $key,
                ];
            }
        }
        foreach ($weg as $p) {
            $lern = (string)($p['lern'] ?? '');
            if ($lern !== '' && $offen($bestand[$lern] ?? null)) {
                $loeschen[] = $lern;
            }
        }
        $e = $this->HomeworkSystemAbgleich($anlegen, $aendern, $loeschen);
        if (($e['ok'] ?? false) !== true) {
            return null;
        }
        foreach ((array)$e['ids'] as $n => $id) {
            $liste[(int)$n]['lern'] = (string)$id;
            $liste[(int)$n]['lernFuer'] = (string)$liste[(int)$n]['date'];
        }
        return $liste;
    }

    /** „Für die Prüfung lernen: KA Briefe schreiben (Donnerstag 08.10., 10:35)" */
    private function UntisLernNotiz(array $p): string
    {
        $titel = trim((string)($p['title'] ?? ''));
        $zeit = strtotime((string)$p['date'] . ' 12:00:00');
        return sprintf($this->Translate('Study for the exam: %1$s (%2$s %3$s, %4$s)'),
            $titel !== '' ? $titel : (string)$p['subject'],
            $this->UntisTagName((int)date('N', (int)$zeit)), date('d.m.', (int)$zeit), (string)$p['start']);
    }

    /** „08.10. 10:35 Deutsch — KA Briefe schreiben" */
    private function UntisPruefungZeile(array $p): string
    {
        $titel = trim((string)($p['title'] ?? ''));
        $fach = (string)($p['subject'] ?? '');
        return date('d.m.', (int)strtotime((string)$p['date'] . ' 12:00:00')) . ' ' . (string)$p['start'] . ' ' . $fach
            . ($titel !== '' && UntisPruefungCalc::TitelNorm($titel) !== UntisPruefungCalc::TitelNorm($fach)
                ? ' — ' . $titel : '');
    }

    /**
     * Eine Sammelmeldung je Kind: im Plan, verlegt, entfaellt.
     *
     * Ziel wie bei der Vorabend-Meldung der Hausaufgaben: die Geraete des
     * Kindes, hat es keine, der Haushalt — Kinder haben oft keines, und eine
     * Klassenarbeit ist keine Nachricht, die im Leeren verschwinden darf.
     *
     * @param array{neu:list<array>, verlegt:list<array>, entfallen:list<array>} $erg
     */
    private function UntisPruefungenMelden(array $kind, array $erg): void
    {
        if (!(bool)$this->UntisProp('UntisExamPush', true)) {
            return;
        }
        $zeilen = [];
        foreach ($erg['neu'] as $p) {
            $zeilen[] = sprintf($this->Translate('In the plan: %s'), $this->UntisPruefungZeile($p));
        }
        foreach ($erg['verlegt'] as $v) {
            $zeilen[] = sprintf($this->Translate('Moved: %1$s (before %2$s)'),
                $this->UntisPruefungZeile($v['nachher']),
                date('d.m.', (int)strtotime((string)$v['vorher']['date'] . ' 12:00:00')) . ' ' . (string)$v['vorher']['start']);
        }
        foreach ($erg['entfallen'] as $p) {
            $zeilen[] = sprintf($this->Translate('Cancelled: %s'), $this->UntisPruefungZeile($p));
        }
        if ($zeilen === []) {
            return;
        }
        $mehr = count($zeilen) - 4;
        $zeilen = array_slice($zeilen, 0, 4);
        if ($mehr > 0) {
            $zeilen[] = sprintf($this->Translate('and %d more'), $mehr);
        }
        $userId = trim((string)($kind['userId'] ?? ''));
        try {
            $ziel = $this->PushSubscriptions($userId) !== [] ? $userId : '';
            $this->PushBroadcast(sprintf($this->Translate('Exams %s'), (string)$kind['name']),
                implode("\n", $zeilen), $ziel, 'dashboard');
        } catch (\Throwable $e) {
            $this->SendDebug('WebUntis', 'Pruefungsmeldung warf: ' . $e->getMessage(), 0);
        }
    }

    /**
     * Die Pruefungen fuer die Oberflaechen: ab heute, nach Kind gefiltert.
     *
     * @return list<array<string,mixed>>
     */
    private function UntisPruefungenOeffentlich(string $kind = ''): array
    {
        $heute = date('Y-m-d');
        $raus = [];
        foreach ($this->UntisPruefungenStand()['kinder'] as $userId => $eintrag) {
            if ($kind !== '' && (string)$userId !== $kind) {
                continue;
            }
            foreach ((array)($eintrag['exams'] ?? []) as $p) {
                if (!is_array($p) || (string)($p['date'] ?? '') < $heute) {
                    continue;
                }
                $raus[] = [
                    'id'      => (string)($p['key'] ?? ''),
                    'childId' => (string)$userId,
                    'date'    => (string)$p['date'],
                    'start'   => (string)($p['start'] ?? ''),
                    'end'     => (string)($p['end'] ?? ''),
                    'subject' => (string)($p['subject'] ?? ''),
                    'title'   => (string)($p['title'] ?? ''),
                    'room'    => (string)($p['room'] ?? ''),
                    'teacher' => (string)($p['teacher'] ?? ''),
                    'status'  => (string)($p['status'] ?? 'normal'),
                ];
            }
        }
        usort($raus, static fn(array $a, array $b): int
            => [$a['date'], UntisPruefungCalc::Minuten($a['start']), $a['childId']]
            <=> [$b['date'], UntisPruefungCalc::Minuten($b['start']), $b['childId']]);
        return $raus;
    }

    /** Die Revision des Stands — die Oberflaeche zeichnet nur bei Aenderung neu. */
    private function UntisPruefungenRev(): int
    {
        return (int)$this->UntisPruefungenStand()['rev'];
    }

    /**
     * Die Zeilen „KOMMENDE PRUEFUNGEN" fuers Briefing: was NACH dem Briefingtag
     * $tag (JJJJ-MM-TT) bis eine Woche danach ansteht. Die Pruefung des Tages
     * selbst steht in der Schulzeile.
     *
     * @return list<string>
     */
    private function UntisPruefungenBriefing(string $tag): array
    {
        $namen = $this->UntisMitglieder();
        $jeKind = [];
        foreach ($this->UntisPruefungenStand()['kinder'] as $userId => $eintrag) {
            $name = (string)($namen[(string)$userId] ?? ($eintrag['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $jeKind[$name] = (array)($eintrag['exams'] ?? []);
        }
        $bis = date('Y-m-d', (int)strtotime($tag . ' 12:00:00') + UntisPruefungCalc::BRIEFING_TAGE * 86400);
        return UntisPruefungCalc::BriefingZeilen($jeKind, $tag, $bis, date('Y-m-d'));
    }

    /**
     * Untis-Stunden auf die Slot-Form des Stundenplan-Moduls abbilden.
     *
     * WICHTIG: Der Wochenplan des Moduls kennt nur Wochentage, keine Daten. Aus
     * mehreren Wochen wird deshalb die NAECHSTE Woche je Wochentag genommen —
     * genauer geht es nicht, ohne das Modul auf Datumsbasis umzubauen. Die
     * Vertretungen dagegen werden ueber den ganzen Zeitraum gemeldet.
     *
     * @return array{0:array<int,list<array<string,mixed>>>, 1:array<string,list<array<string,mixed>>>, 2:list<array<string,mixed>>, 3:int, 4:list<string>}
     */

    /**
     * Die SCHREIBENDE Haelfte: die gelesenen Zeilen uebernehmen.
     *
     * Sie bleibt beim Gateway — dort liegen die Hausaufgaben, und
     * `HomeworkImportieren` raeumt im Fenster auf.
     *
     * @param list<array<string,mixed>> $roh
     */
    private function UntisHausaufgabenEinpflegen(string $userId, array $roh, int $von, int $bis): string
    {
        $iso = static fn(int $ymd): string => substr((string)$ymd, 0, 4) . '-'
            . substr((string)$ymd, 4, 2) . '-' . substr((string)$ymd, 6, 2);
        // Das enge Fenster: nur hier darf zurueckgezogen werden (siehe oben).
        $e = $this->HomeworkImportieren($userId, $roh, $iso($von), $iso($bis));
        if (($e['ok'] ?? false) !== true) {
            return sprintf($this->Translate('homework: not taken over (%s)'), (string)($e['fehler'] ?? '?'));
        }
        if ((int)$e['neu'] === 0 && (int)$e['geaendert'] === 0 && (int)$e['entfernt'] === 0) {
            return sprintf($this->Translate('%d homework item(s), unchanged'), count($roh));
        }
        return sprintf($this->Translate('%1$d homework item(s): %2$d new, %3$d updated, %4$d withdrawn'),
            count($roh), (int)$e['neu'], (int)$e['geaendert'], (int)$e['entfernt']);
    }

    /** Den Wochenplan ins Stundenplan-Modul geben. @return int geschriebene Tage */
    private function UntisEinspielen(array $kind, array $tage): int
    {
        if (!function_exists('STPL_ImportSlots')) {
            $this->SendDebug('WebUntis', 'STPL_ImportSlots fehlt — Kernel-Neustart nötig', 0);
            return 0;
        }
        $rumpf = (string)json_encode([
            'child'  => $kind['child'],
            'source' => 'WebUntis',
            'days'   => (object)$tage,
        ], JSON_UNESCAPED_UNICODE);
        try {
            $antwort = json_decode((string)@STPL_ImportSlots((int)$kind['stpl'], $rumpf), true);
        } catch (\Throwable $e) {
            $this->LogMessage('SymDo WebUntis: Einspielen warf — ' . $e->getMessage(), KL_ERROR);
            return 0;
        }
        if (($antwort['ok'] ?? false) !== true) {
            // Die Antwort der offenen Funktion ist der Grund — sie schluckt nichts.
            $this->LogMessage('SymDo WebUntis: Stundenplan abgelehnt — '
                . (string)($antwort['error']['message'] ?? '?'), KL_ERROR);
            return 0;
        }
        return count((array)($antwort['tage'] ?? []));
    }

    /**
     * Neue Entfaelle und Vertretungen melden — je Stunde genau einmal.
     *
     * Der Merker haelt die Kennungen der schon gemeldeten Aenderungen. Ohne ihn
     * kaeme dieselbe Meldung bei jedem Lauf, also stuendlich.
     */
    private function UntisAenderungenMelden(array $kind, array $auffaellig): int
    {
        $karte = json_decode((string)@$this->ReadAttributeString('UntisLast'), true);
        $karte = is_array($karte) ? $karte : [];
        $topf = 'k' . (int)$kind['stpl'] . ':' . $kind['name'];
        $alt = array_map('strval', (array)($karte[$topf] ?? []));
        $jetzt = [];
        $neu = [];
        foreach ($auffaellig as $a) {
            $schluessel = $a['datum'] . '|' . $a['start'] . '|' . $a['subject'] . '|' . $a['status'];
            $jetzt[] = $schluessel;
            if (!in_array($schluessel, $alt, true)) {
                $neu[] = $a;
            }
        }
        if ($neu !== []) {
            $this->UntisPushen($kind, $neu);
        }
        $karte[$topf] = $jetzt;   // nur der aktuelle Stand: Vergangenes faellt weg
        @$this->WriteAttributeString('UntisLast', (string)json_encode($karte, JSON_UNESCAPED_UNICODE));
        return count($neu);
    }

    /**
     * Entfall und Vertretung eines Datums, nach Kindernamen.
     *
     * Fuer das Briefing. Die Quelle ist der Merker des letzten Laufs und damit
     * DATIERT — der Wochenplan des Stundenplan-Moduls waere es nicht: er kennt
     * nur Wochentage, und an einem Tag wie dem Projekttag (alles entfallen)
     * steht dort bewusst ein anderer, regulaerer Termin derselben Woche.
     *
     * @return array<string, array{entfall:list<string>, vertretung:list<string>}>
     */
    private function UntisTagesmeldungen(string $datum): array
    {
        $tag = str_replace('-', '', $datum);
        if (strlen($tag) !== 8) {
            return [];
        }
        $karte = json_decode((string)@$this->ReadAttributeString('UntisLast'), true);
        if (!is_array($karte)) {
            return [];
        }
        $raus = [];
        foreach ($karte as $topf => $schluessel) {
            // Topf heisst „k<Instanz>:<Name>"
            $name = (string)substr((string)$topf, (int)strpos((string)$topf, ':') + 1);
            foreach ((array)$schluessel as $k) {
                $t = explode('|', (string)$k);
                if (count($t) !== 4 || $t[0] !== $tag) {
                    continue;
                }
                $text = $t[2] . ' ' . $t[1];
                if ($t[3] === 'entfall') {
                    $raus[$name]['entfall'][] = $text;
                } elseif ($t[3] === 'vertretung') {
                    $raus[$name]['vertretung'][] = $text;
                }
            }
        }
        foreach ($raus as $name => $eintrag) {
            $raus[$name] = ['entfall' => $eintrag['entfall'] ?? [], 'vertretung' => $eintrag['vertretung'] ?? []];
        }
        /* Der Merker ist nach dem Namen des FAMILIENMITGLIEDS abgelegt; das
           Briefing fragt aber mit dem Namen, den das Kind in der
           Stundenplan-Instanz traegt. Heissen die beiden verschieden („Tim" hier,
           „Tim Luca" dort), fand die Abfrage nichts — und im Briefing fehlten
           Entfall und Vertretung genau des Tages, wortlos. Deshalb liegen die
           Meldungen zusaetzlich unter dem Plan-Namen. */
        foreach ($this->UntisKinder() as $k) {
            $planName = (string)($k['child'] ?? '');
            $eigen    = (string)($k['name'] ?? '');
            if ($planName === '' || $planName === $eigen) {
                continue;
            }
            if (isset($raus[$eigen]) && !isset($raus[$planName])) {
                $raus[$planName] = $raus[$eigen];
            }
        }
        return $raus;
    }

    private function UntisPushen(array $kind, array $neu): void
    {
        if (!(bool)$this->UntisProp('UntisPush', true)) {
            return;
        }
        $zeilen = [];
        foreach (array_slice($neu, 0, 4) as $a) {
            $tag = strtotime((string)$a['datum']);
            $wann = $tag ? date('d.m.', $tag) : '';
            $zeilen[] = sprintf('%s %s %s%s', $wann, $a['start'],
                $a['status'] === 'entfall'
                    ? sprintf($this->Translate('%s cancelled'), $a['subject'])
                    : sprintf($this->Translate('%s substituted'), $a['subject']),
                ($a['grund'] ?? '') !== '' ? ' (' . $a['grund'] . ')' : '');
        }
        if (count($neu) > 4) {
            $zeilen[] = sprintf($this->Translate('and %d more'), count($neu) - 4);
        }
        try {
            $this->SendPush(
                sprintf($this->Translate('Timetable %s'), $kind['name']),
                implode("\n", $zeilen),
                (string)($kind['userId'] ?? ''),
                ''
            );
        } catch (\Throwable $e) {
            $this->SendDebug('WebUntis', 'Push warf: ' . $e->getMessage(), 0);
        }
    }

    // ─────────────────────────────── Kleinkram ───────────────────────────────

    /** @return list<array{name:string,stpl:int,child:string,type:int,id:int,userId:string}> */
    private function UntisKinder(): array
    {
        $roh = json_decode((string)$this->UntisProp('UntisStudents', '[]'), true);
        $mitglieder = $this->UntisMitglieder();
        $raus = [];
        foreach (is_array($roh) ? $roh : [] as $z) {
            if (!is_array($z)) {
                continue;
            }
            $userId = trim((string)($z['userId'] ?? ''));
            $stpl   = (int)($z['stpl'] ?? 0);
            /* EIN Feld bestimmt das Kind: das Familienmitglied. Daraus folgen
               der Anzeigename, das Kind in der Zielinstanz und das Ziel der
               Meldung. Frueher standen dafuer drei Spalten da (name, child,
               userId) — dreimal dasselbe Kind, und ein Tippfehler im Freitext
               „child" endete in „unknown_child". Die alten Felder gelten
               weiter als Rueckfall, damit vorhandene Zeilen nicht ausfallen. */
            $name = $userId !== '' ? (string)($mitglieder[$userId] ?? '') : '';
            if ($name === '') {
                $name = trim((string)($z['name'] ?? ''));
            }
            if ($name === '' && $userId !== '') {
                /* Mitglied geloescht oder umbenannt: die Zeile trotzdem
                   mitnehmen. Still uebergehen waere schlimmer — dann fehlte der
                   Plan, und in der Statuszeile stuende nicht, warum. */
                $name = '#' . $userId;
            }
            if ($name === '') {
                continue;
            }
            $kind = $this->UntisKindImPlan($stpl, $userId, $name);
            if ($kind === '') {
                $kind = trim((string)($z['child'] ?? ''));
            }
            /* Steht eine Nummer da, ist es ein SCHUELER — die Auswahl im
               Formular kennt nur Kinder des Kontos. Ein alter Datensatz mit
               ausdruecklichem Typ (etwa „Klasse") behaelt seinen: das Feld gibt
               es nicht mehr im Formular, seine Zeilen laufen aber weiter. */
            $nummer = (int)($z['elementId'] ?? 0);
            $typ    = (int)($z['type'] ?? 0);
            if ($typ <= 0 && $nummer > 0) {
                $typ = self::UNTIS_TYP_SCHUELER;
            }
            $raus[] = [
                'name'   => $name,
                'stpl'   => $stpl,
                'child'  => $kind,
                'type'   => $typ,
                'id'     => $nummer,
                'kurse'  => trim((string)($z['kurse'] ?? '')),
                'userId' => $userId,
            ];
        }
        return $raus;
    }

    /**
     * Familienmitglieder als id => Name.
     *
     * @return array<string, string>
     */
    private function UntisMitglieder(): array
    {
        $raus = [];
        try {
            foreach ($this->LoadUsers() as $u) {
                $id = trim((string)($u['id'] ?? ''));
                $n  = trim((string)($u['name'] ?? ''));
                if ($id !== '' && $n !== '') {
                    $raus[$id] = $n;
                }
            }
        } catch (\Throwable $e) {
            $this->SendDebug('WebUntis', 'Mitgliederliste nicht lesbar: ' . $e->getMessage(), 0);
        }
        return $raus;
    }

    /**
     * Wie heisst das Kind in der Ziel-Instanz?
     *
     * Das Stundenplan-Modul verknuepft seine Kinder selbst mit Mitgliedern
     * (`Children[].userId`) — dieselbe Zuordnung, die auch die App benutzt.
     * Darum wird zuerst darueber gesucht und nur zweitens ueber den Namen: ein
     * Kind kann in der Instanz anders heissen als das Mitglied.
     */
    private function UntisKindImPlan(int $stpl, string $userId, string $name): string
    {
        if ($stpl <= 0 || !IPS_InstanceExists($stpl)) {
            return '';
        }
        $kinder = json_decode((string)@IPS_GetProperty($stpl, 'Children'), true);
        if (!is_array($kinder)) {
            return '';
        }
        $ueberNamen = '';
        foreach ($kinder as $k) {
            if (!is_array($k)) {
                continue;
            }
            $n = trim((string)($k['name'] ?? ''));
            if ($n === '') {
                continue;
            }
            // Die Kennungen sind unterschiedlich entstanden: einmal Ziffern,
            // einmal Hex — deshalb als Zeichenkette vergleichen.
            if ($userId !== '' && trim((string)($k['userId'] ?? '')) === $userId) {
                return $n;
            }
            if (mb_strtolower($n) === mb_strtolower($name)) {
                $ueberNamen = $n;
            }
        }
        return $ueberNamen;
    }

    private function UntisIsEnabled(): bool
    {
        return (bool)$this->UntisProp('UntisEnabled', false);
    }

    private function UntisStatusSchreiben(string $text): void
    {
        @$this->WriteAttributeString('UntisStatus', (string)json_encode(
            ['t' => time(), 'text' => $text], JSON_UNESCAPED_UNICODE));
        $this->SendDebug('WebUntis', $text, 0);
    }
}

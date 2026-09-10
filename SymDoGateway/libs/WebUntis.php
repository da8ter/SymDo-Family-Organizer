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
    private const UNTIS_CLIENT      = 'SymDo';   // Selbstauskunft in den Zugriffen der Schule
    private const UNTIS_TAGE_VOR    = 14;        // so weit im Voraus wird geholt
    private const UNTIS_FEHLER_MAX  = 3;         // danach steht der Timer (Kontosperre!)
    /* So lange bleibt der Riegel zu, wenn ihn niemand von Hand loest. Danach
       gibt es wieder DREI Versuche, mehr nicht. Ohne diese Frist muesste man das
       Formular aufsuchen, nur weil das Kennwort einmal falsch stand. */
    private const UNTIS_SPERRE_FRIST = 6 * 3600;
    /* Element-Typen von WebUntis. Nur diese beiden taugen als „wessen Plan?":
       ein Erziehungsberechtigten-Konto meldet Personentyp 12 und ist selbst
       KEIN Element — getTimetable antwortet dort „invalid elementType: 12". */
    private const UNTIS_TYP_KLASSE   = 1;
    private const UNTIS_TYP_SCHUELER = 5;
    private const UNTIS_HTTP_FRIST  = 20;
    private const UNTIS_INTERVALL_STD = 60;      // Minuten

    private ?array $untisConfigCache = null;
    private string $untisSession = '';
    /* Wer ist angemeldet? `authenticate` liefert personId und personType mit —
       und getTimetable verlangt IMMER ein Element, auch fuer den eigenen Plan
       („no element provided"). Das ist der Rueckfall, wenn in der Kinderliste
       nichts steht. */
    private array $untisIch = ['id' => 0, 'type' => 0];
    /* Der Bearer der REST-Ansicht (siehe UntisRest). Er entsteht aus der
       laufenden Sitzung und lebt genauso lange — einmal je Anmeldung holen
       genuegt. */
    private string $untisBearer = '';

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
        /* Je Kind: Anzeigename, Ziel-Stundenplan und wessen Plan geholt wird.
           Leerer Elementtyp = der Plan des angemeldeten Kontos selbst. */
        $this->RegisterPropertyString('UntisStudents', '[]');
        /* Das alte Suchfeld. Es steht nicht mehr im Formular (die Suche las die
           Schuelerliste der ganzen Schule), bleibt aber REGISTRIERT: ein
           Formularfeld ohne Eigenschaft laesst „Uebernehmen" fuer die GANZE
           Konfiguration scheitern, eine Eigenschaft ohne Feld ist harmlos. */
        $this->RegisterPropertyString('UntisSearchName', '');
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

    /**
     * Ein RPC-Aufruf. `params` IMMER als Objekt — als Liste findet der Verteiler
     * die Methode nicht (siehe Kopf dieser Datei).
     *
     * @return array{ok:bool, result?:mixed, code?:int, message?:string}
     */
    private function UntisRpc(string $methode, array $params = []): array
    {
        $server = trim((string)$this->UntisProp('UntisServer', ''));
        $schule = trim((string)$this->UntisProp('UntisSchool', ''));
        if ($server === '' || $schule === '') {
            return ['ok' => false, 'message' => $this->Translate('Server or school is missing.')];
        }
        $url = 'https://' . $server . '/WebUntis/jsonrpc.do?school=' . rawurlencode($schule);
        $rumpf = (string)json_encode([
            'id'      => 'symdo',
            'method'  => $methode,
            'params'  => (object)$params,   // (object): ein leeres Array waere „[]"
            'jsonrpc' => '2.0',
        ], JSON_UNESCAPED_UNICODE);

        $kopf = ['Content-Type: application/json'];
        if ($this->untisSession !== '') {
            $kopf[] = 'Cookie: JSESSIONID=' . $this->untisSession;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $rumpf,
            CURLOPT_HTTPHEADER     => $kopf,
            CURLOPT_TIMEOUT        => self::UNTIS_HTTP_FRIST,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT      => self::UNTIS_CLIENT,
        ]);
        $antwort = curl_exec($ch);
        $fehler  = ($antwort === false) ? curl_error($ch) : '';
        $status  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($fehler !== '' || $status !== 200) {
            return ['ok' => false, 'message' => sprintf(
                $this->Translate('WebUntis is not answering (HTTP %d).'), $status)];
        }
        $d = json_decode((string)$antwort, true);
        if (!is_array($d)) {
            return ['ok' => false, 'message' => $this->Translate('WebUntis sent no valid answer.')];
        }
        if (isset($d['error'])) {
            return ['ok' => false, 'code' => (int)($d['error']['code'] ?? 0),
                    'message' => (string)($d['error']['message'] ?? '?')];
        }
        return ['ok' => true, 'result' => $d['result'] ?? null];
    }

    /** Anmelden. Die Sitzung reist danach als Keks in jedem weiteren Aufruf. */
    private function UntisLogin(): array
    {
        $this->untisSession = '';
        $this->untisBearer  = '';
        $benutzer = trim((string)$this->UntisProp('UntisUser', ''));
        $kennwort = (string)$this->UntisProp('UntisPassword', '');
        if ($benutzer === '' || $kennwort === '') {
            return ['ok' => false, 'message' => $this->Translate('User or password is missing.')];
        }
        $r = $this->UntisRpc('authenticate', [
            'user' => $benutzer, 'password' => $kennwort, 'client' => self::UNTIS_CLIENT,
        ]);
        if (($r['ok'] ?? false) !== true) {
            return $r;
        }
        $sid = (string)(($r['result']['sessionId']) ?? '');
        if ($sid === '') {
            return ['ok' => false, 'message' => $this->Translate('WebUntis returned no session.')];
        }
        $this->untisSession = $sid;
        $this->untisIch = [
            'id'   => (int)(($r['result']['personId']) ?? 0),
            'type' => (int)(($r['result']['personType']) ?? 0),
        ];
        return ['ok' => true, 'result' => $r['result']];
    }

    private function UntisLogout(): void
    {
        if ($this->untisSession !== '') {
            $this->UntisRpc('logout');
            $this->untisSession = '';
        }
    }

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
     * Trifft ein Suchwort einen Namen? Verglichen wird am WORTANFANG.
     *
     * Irgendwo im Namen zu suchen, ergab Unsinn: ein kurzes Suchwort traf auch
     * in der MITTE eines anderen Namens (etwa „Ina" in „Martina") — und die Liste
     * einer ganzen Schule liefert damit Namen, die niemand gesucht hat.
     */
    private function UntisNameTrifft(string $name, string $suche): bool
    {
        $suche = trim($suche);
        if ($suche === '' || $name === '') {
            return false;
        }
        foreach (preg_split('/\s+/u', $name) ?: [] as $wort) {
            if ($wort !== '' && mb_stripos($wort, $suche) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Ein Lesezugriff auf die REST-Ansicht von WebUntis (die, die die
     * Untis-App benutzt).
     *
     * Der alte JSON-RPC weiss von Kindern nichts: ein Erziehungsberechtigten-
     * Konto ist dort Personentyp 12 und kein Element. Die REST-Ansicht kennt
     * sie — unter `user.students`, genau wie die App sie zeigt. Der Weg dorthin
     * ist ein Bearer, den `/WebUntis/api/token/new` gegen die laufende Sitzung
     * ausgibt; der JSESSIONID-Keks allein genuegt dafuer (gemessen 04.09.2026).
     *
     * Zwei Wurzeln, und der Unterschied ist keine Kosmetik: die neue Ansicht
     * liegt unter `api/rest/view/v1/`, aeltere Endpunkte wie die Hausaufgaben
     * unter `api/`. Unter der neuen Wurzel antwortet `homeworks` mit HTTP 404
     * (am 09.09.2026 gemessen) — wer das fuer „gibt es nicht" nimmt, sucht an
     * der falschen Stelle.
     *
     * @return array<string,mixed>|null null bei jedem Fehlschlag — der Aufrufer
     *         faellt dann auf die Angaben im Formular zurueck.
     */
    private function UntisRest(string $pfad, string $wurzel = 'api/rest/view/v1/'): ?array
    {
        $server = trim((string)$this->UntisProp('UntisServer', ''));
        if ($server === '' || $this->untisSession === '') {
            return null;
        }
        $basis = 'https://' . $server;
        $keks  = 'Cookie: JSESSIONID=' . $this->untisSession;
        $hol = function (string $url, array $kopf) use (&$hol): array {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $kopf,
                CURLOPT_TIMEOUT        => self::UNTIS_HTTP_FRIST,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_USERAGENT      => self::UNTIS_CLIENT,
            ]);
            $antwort = curl_exec($ch);
            $status  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return ['status' => $status, 'text' => $antwort === false ? '' : (string)$antwort];
        };
        if ($this->untisBearer === '') {
            $t = $hol($basis . '/WebUntis/api/token/new', [$keks]);
            if ($t['status'] !== 200 || trim($t['text']) === '') {
                $this->SendDebug('WebUntis', 'token/new: HTTP ' . $t['status'], 0);
                return null;
            }
            $this->untisBearer = trim($t['text']);
        }
        $r = $hol($basis . '/WebUntis/' . trim($wurzel, '/') . '/' . ltrim($pfad, '/'),
            [$keks, 'Authorization: Bearer ' . $this->untisBearer, 'Accept: application/json']);
        if ($r['status'] !== 200) {
            $this->SendDebug('WebUntis', $pfad . ': HTTP ' . $r['status'], 0);
            return null;
        }
        $d = json_decode($r['text'], true);
        return is_array($d) ? $d : null;
    }

    /**
     * Die Kinder, die AM KONTO haengen — bei einem Elternzugang genau die, die
     * die Untis-App anzeigt.
     *
     * @return list<array{id:int,name:string}>
     */
    private function UntisKinderDesKontos(): array
    {
        $d = $this->UntisRest('app/data');
        $raus = [];
        foreach ((array)((($d['user'] ?? [])['students']) ?? []) as $k) {
            if (!is_array($k) || (int)($k['id'] ?? 0) <= 0) {
                continue;
            }
            $raus[] = ['id' => (int)$k['id'], 'name' => trim((string)($k['displayName'] ?? ''))];
        }
        if ($raus !== []) {
            return $raus;
        }
        /* Kein Kind am Konto: dann ist das Konto SELBST das Element — ein
           Schuelerzugang. Auch dessen Name gehoert in die Auswahl, denn die
           Spalte soll einen Namen zeigen und keine Nummer; „automatisch" gibt
           es dort nicht mehr.

           Der Anzeigename steht je nach Fassung von WebUntis an verschiedenen
           Stellen der Antwort. Die erste, die etwas hergibt, gewinnt; findet
           sich keine, traegt die Auswahl die Elementnummer. Geraten wird hier
           nichts: nur gelesen, was da ist. */
        $ich = (int)$this->untisIch['id'];
        if ($ich <= 0 || !in_array((int)$this->untisIch['type'],
                [self::UNTIS_TYP_KLASSE, self::UNTIS_TYP_SCHUELER], true)) {
            return [];
        }
        $u = (array)($d['user'] ?? []);
        $p = (array)($u['person'] ?? []);
        $name = '';
        foreach ([$p['displayName'] ?? '', $u['displayName'] ?? '',
                  $p['name'] ?? '', $u['name'] ?? ''] as $kandidat) {
            if (trim((string)$kandidat) !== '') {
                $name = trim((string)$kandidat);
                break;
            }
        }
        return [['id' => $ich, 'name' => $name]];
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
            /* Der Plan des Kontos ist der KLASSENplan: Religions- und
               Foerderkurse stehen alle nebeneinander. Hier steht, welche das
               Kind besucht — nur bei Ueberschneidungen wird gewaehlt. */
            ['caption' => $this->Translate('Courses (chosen course, or -Subject to drop)'), 'name' => 'kurse',
             'width' => 'auto', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
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
        $an = $this->UntisLogin();
        if (($an['ok'] ?? false) !== true) {
            $this->UntisFehlerZaehlen((int)($an['code'] ?? 0));
            $text = $this->Translate('Login failed: ') . (string)($an['message'] ?? '?');
            $this->UntisStatusSchreiben($text);
            return $text;
        }
        @$this->WriteAttributeInteger('UntisFails', 0);

        $teile = [];
        try {
            $raster = $this->UntisRaster();
            foreach ($kinder as $kind) {
                $teile[] = $this->UntisKindLesen($kind, $raster, $trocken);
            }
        } finally {
            $this->UntisLogout();
        }
        $text = implode(' | ', array_filter($teile));
        $this->UntisStatusSchreiben($text);
        return $text;
    }

    /**
     * Ein Kind: Plan holen, abbilden, einspielen, Aenderungen melden.
     *
     * @param array{name:string,stpl:int,child:string,type:int,id:int} $kind
     * @param array<int,array{start:string,end:string}> $raster
     */
    private function UntisKindLesen(array $kind, array $raster, bool $trocken): string
    {
        /* Ohne Zuordnung in der Zielinstanz wuerde der Import mit
           „unknown_child" abgewiesen — dann lieber gleich sagen, was fehlt,
           und die Anfrage an die Schule sparen. */
        if ((int)$kind['stpl'] > 0 && (string)$kind['child'] === '') {
            return sprintf($this->Translate('%s: no child of that member in the timetable instance — link the member there.'),
                $kind['name']);
        }
        $von = (int)date('Ymd');
        $bis = (int)date('Ymd', strtotime('+' . self::UNTIS_TAGE_VOR . ' days'));
        $params = ['options' => [
            'startDate' => $von, 'endDate' => $bis,
            'showInfo' => true, 'showSubstText' => true, 'showLsText' => true,
            'klasseFields'  => ['id', 'name'],
            'subjectFields' => ['id', 'name', 'longname'],
            'roomFields'    => ['id', 'name'],
            'teacherFields' => ['id', 'name'],
        ]];
        $typ = (int)$kind['type'];
        $nr  = (int)$kind['id'];
        /* Ohne JEDE Angabe: der Plan des angemeldeten Kontos. Vorher wurden die
           beiden Felder EINZELN aufgefuellt — bei „Typ Schueler, Nummer leer"
           trug der Abruf damit die Personennummer des ELTERNKONTOS als
           Schuelernummer ein, und WebUntis antwortete „no such element
           elementId:2683, elementType:5". Beides kommt jetzt aus derselben
           Quelle. */
        if ($typ <= 0 && $nr <= 0) {
            $typ = (int)$this->untisIch['type'];
            $nr  = (int)$this->untisIch['id'];
            if (!in_array($typ, [self::UNTIS_TYP_KLASSE, self::UNTIS_TYP_SCHUELER], true) || $nr <= 0) {
                /* Elternzugang: das Konto ist kein Element, aber es HAT Kinder —
                   dieselben, die die Untis-App zeigt. Also selbst nachsehen,
                   statt eine Nummer zu verlangen. Bei mehreren entscheidet der
                   Name aus der Zeile; passt keiner, sagt die Meldung, welche
                   Nummern es gibt. */
                $kinder = $this->UntisKinderDesKontos();
                if (count($kinder) === 1) {
                    $typ = self::UNTIS_TYP_SCHUELER;
                    $nr  = (int)$kinder[0]['id'];
                } elseif (count($kinder) > 1) {
                    $passend = array_values(array_filter($kinder,
                        fn(array $k): bool => $this->UntisNameTrifft($k['name'], (string)$kind['name'])));
                    if (count($passend) === 1) {
                        $typ = self::UNTIS_TYP_SCHUELER;
                        $nr  = (int)$passend[0]['id'];
                    } else {
                        /* NUR die Nummern, keine Namen: diese Zeile wird gemerkt
                           (UntisStatusSchreiben) und landet damit in der
                           settings.json — die ist Klartext und weltlesbar. Die
                           Namen nennt „Verbindung testen", und die stehen nur im
                           offenen Formular. */
                        return sprintf($this->Translate('%1$s: the account has several children — enter the element number (%2$s); „Test connection" names them.'),
                            $kind['name'],
                            implode(', ', array_map(
                                static fn(array $k): string => (string)$k['id'], $kinder)));
                    }
                } else {
                    return sprintf($this->Translate('%1$s: the account itself is not a timetable element (person type %2$d) and names no child — enter element type and number; the search in the form finds it.'),
                        $kind['name'], $typ);
                }
            }
        }
        if ($typ <= 0 || $nr <= 0) {
            return sprintf($this->Translate('%s: element type and number belong together — enter both, or leave both empty.'),
                $kind['name']);
        }
        /* ERST die neue Ansicht: nur sie unterscheidet eine Veranstaltung von
           einer Vertretung und nennt die ersetzte Lehrkraft. Sie ist nicht
           öffentlich dokumentiert, deshalb bleibt die JSON-RPC als Rückfall
           stehen — fällt die Ansicht aus, ändert sich am Plan nur, dass
           Termine wieder wie Vertretungen aussehen. */
        $stunden = $this->UntisPlanRest($typ, $nr, $von, $bis);
        $quelle = 'rest';
        if ($stunden === null || $stunden === []) {
            $quelle = 'rpc';
            $params['options']['element'] = ['id' => $nr, 'type' => $typ];
            $r = $this->UntisRpc('getTimetable', $params);
            if (($r['ok'] ?? false) !== true) {
                return $kind['name'] . ': ' . (string)($r['message'] ?? '?');
            }
            $stunden = is_array($r['result'] ?? null) ? $r['result'] : [];
        }
        $this->SendDebug('WebUntis', sprintf('%s: %d Stunden über %s',
            (string)$kind['name'], count($stunden), $quelle), 0);
        if ($stunden === []) {
            return sprintf($this->Translate('%s: no lessons in the period.'), $kind['name']);
        }

        [$tage, $datiert, $auffaellig, $offen, $verworfen] = $this->UntisAbbilden($stunden, $raster, (string)$kind['kurse']);
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
            /* Der Trockenlauf holt die Hausaufgaben ABSICHTLICH nicht: er
               schreibt nichts, und ein Abruf, dessen Ergebnis niemand sieht,
               ist nur ein Zugriff mehr auf das Konto der Schule. */
            return sprintf($this->Translate('%1$s: %2$d lesson(s), %3$d change(s), %4$d unresolved overlap(s) — dry run, nothing written'),
                $kind['name'], count($stunden), count($auffaellig), $offen) . $verlust;
        }
        $eingespielt = ((int)$kind['stpl'] > 0 && $tage !== []) ? $this->UntisEinspielen($kind, $tage) : 0;
        /* Zweiter Aufruf, datiert: der Wochenplan zeigt die REGELWOCHE, die
           Ebene darueber den einzelnen Tag mit Entfall, Vertretung und
           Projekttag. Beides zusammen, weil beides etwas anderes beantwortet:
           „wie sieht ein Dienstag aus" und „was ist am Dienstag". */
        $datierteTage = ((int)$kind['stpl'] > 0 && $datiert !== []) ? $this->UntisEinspielen($kind, $datiert) : 0;
        $neu = $this->UntisAenderungenMelden($kind, $auffaellig);
        /* Die Hausaufgaben ZULETZT, und in derselben Sitzung. Zuletzt, weil der
           Plan die Hauptsache ist: faellt der Zusatz aus, steht der Stundenplan
           trotzdem. In derselben Sitzung, weil jede weitere Anmeldung an der
           Kontosperre nach drei Fehlversuchen kratzt. */
        $hausaufgaben = '';
        if ((bool)$this->UntisProp('UntisHomework', false)) {
            try {
                $hausaufgaben = $this->UntisHausaufgabenLesen(
                    $kind, $nr, $von, $bis, $this->UntisFachKurzformen($stunden));
            } catch (\Throwable $e) {
                $this->SendDebug('WebUntis', 'Hausaufgaben: ' . $e->getMessage(), 0);
                $hausaufgaben = $this->Translate('homework: not available');
            }
        }

        return sprintf($this->Translate('%1$s: %2$d lesson(s), %3$d change(s), %4$d new, %5$d weekday(s) + %6$d date(s) written, %7$d overlap(s) unresolved'),
            $kind['name'], count($stunden), count($auffaellig), $neu, $eingespielt, $datierteTage, $offen)
            . $verlust . ($hausaufgaben === '' ? '' : ', ' . $hausaufgaben);
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
     * Der Plan aus der NEUEN Ansicht (`timetable/entries`).
     *
     * Warum überhaupt: die alte JSON-RPC kann eine Veranstaltung nicht von
     * einer Vertretung unterscheiden. Am 09.09.2026 gemessen — der Ausflug
     * „Waldschule" kam als `code: irregular` mit `activityType: Unterricht`,
     * also Zeichen für Zeichen wie eine Vertretung. Die neue Ansicht sagt
     * `type: EVENT` und nennt die ersetzte Lehrkraft in `removed` mit.
     *
     * Die Antwort wird in die FORM der alten Schnittstelle gebracht, damit
     * UntisAbbilden und die Kurswahl unverändert bleiben; zwei Felder kommen
     * hinzu: `kind` (der Eintragstyp) und `insteadOf`.
     *
     * Nicht öffentlich dokumentiert: das ist der Weg, den die Weboberfläche
     * von WebUntis selbst nimmt. Deshalb NUR als erster Versuch — schlägt er
     * fehl, holt der Aufrufer den Plan wie bisher über die JSON-RPC.
     *
     * @return list<array<string,mixed>>|null null = nicht verfügbar
     */
    private function UntisPlanRest(int $typ, int $nr, int $von, int $bis): ?array
    {
        $art = $typ === self::UNTIS_TYP_KLASSE ? 'CLASS'
            : ($typ === self::UNTIS_TYP_SCHUELER ? 'STUDENT' : '');
        if ($art === '' || $nr <= 0) {
            return null;
        }
        $iso = static fn(int $ymd): string => substr((string)$ymd, 0, 4) . '-'
            . substr((string)$ymd, 4, 2) . '-' . substr((string)$ymd, 6, 2);
        $d = $this->UntisRest('timetable/entries?start=' . $iso($von) . '&end=' . $iso($bis)
            . '&format=2&resourceType=' . $art . '&resources=' . $nr
            . '&periodTypes=&timetableType=MY_TIMETABLE');
        if (!is_array($d) || !is_array($d['days'] ?? null)) {
            return null;
        }
        $raus = [];
        foreach ($d['days'] as $tag) {
            if (!is_array($tag)) {
                continue;
            }
            foreach ((array)($tag['gridEntries'] ?? []) as $e) {
                if (!is_array($e)) {
                    continue;
                }
                $zeile = $this->UntisRestEintrag($e);
                if ($zeile !== null) {
                    $raus[] = $zeile;
                }
            }
        }
        return $raus;
    }

    /**
     * Ein Eintrag der neuen Ansicht in der Form der alten.
     *
     * Die Reihen heißen dort position1..position7 und tragen je Reihe
     * `current` und `removed`. Welche Reihe welches Element trägt, steht NICHT
     * fest — jede Reihe nennt ihren `type` selbst (SUBJECT, TEACHER, ROOM,
     * CLASS, INFO). Also alle Reihen durchgehen und nach Typ einsammeln.
     */
    private function UntisRestEintrag(array $e): ?array
    {
        $start = (string)($e['duration']['start'] ?? '');
        $ende  = (string)($e['duration']['end'] ?? '');
        if (strlen($start) < 16 || strlen($ende) < 16) {
            return null;
        }
        $sammeln = [];
        $ersetzt = [];
        for ($i = 1; $i <= 7; $i++) {
            foreach ((array)($e['position' . $i] ?? []) as $x) {
                if (!is_array($x)) {
                    continue;
                }
                $c = is_array($x['current'] ?? null) ? $x['current'] : [];
                $r = is_array($x['removed'] ?? null) ? $x['removed'] : [];
                $art = strtoupper(trim((string)($c['type'] ?? ($r['type'] ?? ''))));
                if ($art === '') {
                    continue;
                }
                if ($c !== []) {
                    $sammeln[$art][] = [
                        'name'     => trim((string)($c['shortName'] ?? '')),
                        'longname' => trim((string)($c['longName'] ?? ($c['displayName'] ?? ''))),
                    ];
                }
                /* Der ersetzte Wert. Nur bei Lehrkräften interessant: „statt
                   Kais" ist eine Auskunft, ein ersetzter Raum ist ohnehin
                   sichtbar. */
                if ($r !== [] && $art === 'TEACHER') {
                    $weg = trim((string)($r['longName'] ?? ($r['displayName'] ?? ($r['shortName'] ?? ''))));
                    if ($weg !== '') {
                        $ersetzt[] = $weg;
                    }
                }
            }
        }
        $typ    = strtoupper(trim((string)($e['type'] ?? '')));
        $status = strtoupper(trim((string)($e['status'] ?? '')));
        $code   = $status === 'CANCELLED' ? 'cancelled' : ($status === 'CHANGED' ? 'irregular' : '');
        $info   = trim((string)($e['lessonInfo'] ?? ''));
        if ($info === '' && isset($sammeln['INFO'][0])) {
            // Bei einer Veranstaltung steht der Name als INFO-Element dort,
            // wo sonst der Raum steht.
            $info = (string)($sammeln['INFO'][0]['longname'] ?: $sammeln['INFO'][0]['name']);
        }
        $zeit = static fn(string $iso): int
            => (int)(substr($iso, 11, 2) . substr($iso, 14, 2));
        return [
            'date'      => (int)str_replace('-', '', substr($start, 0, 10)),
            'startTime' => $zeit($start),
            'endTime'   => $zeit($ende),
            'code'      => $code,
            'kind'      => $typ,
            'su'        => $sammeln['SUBJECT'] ?? [],
            'te'        => $sammeln['TEACHER'] ?? [],
            'ro'        => $sammeln['ROOM'] ?? [],
            'kl'        => $sammeln['CLASS'] ?? [],
            'lstext'    => trim((string)($e['lessonText'] ?? '')),
            'substText' => trim((string)($e['substitutionText'] ?? '')),
            'info'      => $info,
            'insteadOf' => implode(', ', array_unique($ersetzt)),
            'activityType' => 'Unterricht',
        ];
    }

    /**
     * Die Hausaufgaben eines Kindes aus WebUntis holen und einrechnen.
     *
     * Warum ueberhaupt: die Schule traegt sie dort ohnehin ein. Sieben Eintraege
     * standen am 09.09.2026 im Konto des Nutzers, mit Faelligkeit, Text und
     * Haekchen — Feld fuer Feld dasselbe, was der Hausaufgaben-Bestand von SymDo
     * fuehrt. Abtippen ist dafuer kein guter Grund.
     *
     * Der Endpunkt liegt NICHT unter der neuen Ansicht, sondern unter
     * `/WebUntis/api/homeworks/lessons` (unter `rest/view/v1` antwortet er 404).
     * Er kostet EINEN Zugriff je Kind und laeuft in der Sitzung, die der Plan
     * schon geoeffnet hat — keine zweite Anmeldung, denn nach drei
     * Fehlanmeldungen sperrt WebUntis das Konto.
     *
     * Das Fach kommt als KUERZEL („M", „Bi"). Die Kurzform-Erkennung des
     * Bestands greift erst ab drei Zeichen, „M" traefe „Mathematik" also nie.
     * Deshalb wird die Zuordnung aus dem Plan mitgenommen, der beides nennt —
     * das kostet keinen weiteren Zugriff.
     *
     * @param array<string,string> $kurz Kuerzel => langer Fachname, aus dem Plan
     * @return string Zeile fuer die Statuszeile, leer wenn es nichts zu sagen gibt
     */
    private function UntisHausaufgabenLesen(array $kind, int $nr, int $von, int $bis, array $kurz): string
    {
        $userId = trim((string)($kind['userId'] ?? ''));
        if ($userId === '') {
            /* Ohne Familienmitglied gibt es kein Kind, an dem eine Hausaufgabe
               haengen koennte. Der Plan geht auch ohne — deshalb ein Hinweis
               und kein Fehler. */
            return $this->Translate('homework: no family member assigned');
        }
        $d = $this->UntisRest('homeworks/lessons?startDate=' . $von . '&endDate=' . $bis, 'api/');
        $daten = is_array($d['data'] ?? null) ? $d['data'] : (is_array($d) ? $d : []);
        if ($daten === []) {
            return $this->Translate('homework: not available');
        }

        /* Welche Liste die Hausaufgaben traegt, ist nicht dokumentiert. Erst am
           Namen, dann am INHALT — und wenn sich WEDER das eine noch das andere
           findet, wird ABGEBROCHEN.
           Der Unterschied ist wichtig: eine leere Liste heisst „diese zwei
           Wochen sind aufgabenfrei" und zieht die uebernommenen Aufgaben im
           Fenster zurueck. Eine unverstaendliche Antwort heisst gar nichts —
           sie darf auf keinen Fall dasselbe bewirken und den Bestand
           ausraeumen. */
        $hw = null;
        foreach (['homeworks', 'homeWorks', 'homework'] as $name) {
            if (is_array($daten[$name] ?? null)) {
                $hw = array_values($daten[$name]);
                break;
            }
        }
        if ($hw === null) {
            foreach ($daten as $wert) {
                if (!is_array($wert) || $wert === []) {
                    continue;
                }
                $erste = reset($wert);
                if (is_array($erste) && (array_key_exists('dueDate', $erste) || array_key_exists('text', $erste))) {
                    $hw = array_values($wert);
                    break;
                }
            }
        }
        if ($hw === null) {
            $this->SendDebug('WebUntis', 'Hausaufgaben: unbekannte Antwortform ('
                . implode(', ', array_slice(array_keys($daten), 0, 8)) . ')', 0);
            return $this->Translate('homework: not available');
        }
        $lektionen = [];
        foreach ((array)($daten['lessons'] ?? []) as $l) {
            if (!is_array($l)) {
                continue;
            }
            $f = $l['subject'] ?? '';
            $name = is_array($f)
                ? trim((string)($f['longName'] ?? ($f['name'] ?? ($f['shortName'] ?? ''))))
                : trim((string)$f);
            if ((int)($l['id'] ?? 0) > 0 && $name !== '') {
                $lektionen[(int)$l['id']] = $name;
            }
        }
        /* Ein Elternkonto sieht bei mehreren Kindern alle Hausaufgaben in einer
           Antwort. `records[]` sagt, zu welchem Element eine gehoert. Nennt
           KEIN Datensatz die Nummer dieses Kindes, wird nicht gefiltert — dann
           haengt das Konto an genau einem Kind, und ein Filter auf eine nirgends
           genannte Nummer liesse alles verschwinden. */
        $meine = [];
        $fremdeGesehen = false;
        foreach ((array)($daten['records'] ?? []) as $r) {
            if (!is_array($r)) {
                continue;
            }
            $ids = array_map('intval', (array)($r['elementIds'] ?? []));
            if ($ids === []) {
                continue;
            }
            if ($nr > 0 && in_array($nr, $ids, true)) {
                $meine[(int)($r['homeworkId'] ?? 0)] = true;
            } elseif ($nr > 0) {
                $fremdeGesehen = true;
            }
        }

        $roh = [];
        foreach ($hw as $h) {
            if (!is_array($h)) {
                continue;
            }
            $id = (int)($h['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            if ($meine !== [] && $fremdeGesehen && !isset($meine[$id])) {
                continue;                       // gehoert einem Geschwisterkind
            }
            $tag = (int)($h['dueDate'] ?? 0) ?: (int)($h['date'] ?? 0);
            if ($tag < 10000000) {
                continue;                       // ohne Tag kein Platz im Plan
            }
            $datum = substr((string)$tag, 0, 4) . '-' . substr((string)$tag, 4, 2) . '-' . substr((string)$tag, 6, 2);
            $kuerzel = (string)($lektionen[(int)($h['lessonId'] ?? 0)] ?? '');
            $fach = $kurz[mb_strtolower($kuerzel)] ?? $kuerzel;
            $text = trim((string)($h['text'] ?? ''));
            $bem  = trim((string)($h['remark'] ?? ''));
            if ($bem !== '' && $bem !== $text) {
                $text = $text === '' ? $bem : ($text . ' — ' . $bem);
            }
            $roh[] = [
                'srcId'   => $id,
                'subject' => $fach,
                'due'     => $datum,
                'note'    => $text,
                'done'    => ($h['completed'] ?? false) === true,
            ];
        }

        $iso = static fn(int $ymd): string => substr((string)$ymd, 0, 4) . '-'
            . substr((string)$ymd, 4, 2) . '-' . substr((string)$ymd, 6, 2);
        $e = $this->HomeworkImportieren($userId, $roh, $iso((int)$von), $iso((int)$bis));
        if (($e['ok'] ?? false) !== true) {
            return sprintf($this->Translate('homework: not taken over (%s)'), (string)($e['fehler'] ?? '?'));
        }
        if ((int)$e['neu'] === 0 && (int)$e['geaendert'] === 0 && (int)$e['entfernt'] === 0) {
            return sprintf($this->Translate('%d homework item(s), unchanged'), count($roh));
        }
        return sprintf($this->Translate('%1$d homework item(s): %2$d new, %3$d updated, %4$d withdrawn'),
            count($roh), (int)$e['neu'], (int)$e['geaendert'], (int)$e['entfernt']);
    }

    /**
     * Kuerzel => langer Fachname, aus den eben geholten Stunden.
     *
     * @param list<array<string,mixed>> $stunden
     * @return array<string,string>
     */
    private function UntisFachKurzformen(array $stunden): array
    {
        $raus = [];
        foreach ($stunden as $st) {
            foreach ((array)($st['su'] ?? []) as $f) {
                if (!is_array($f)) {
                    continue;
                }
                $k = mb_strtolower(trim((string)($f['name'] ?? '')));
                $l = trim((string)($f['longname'] ?? ''));
                if ($k !== '' && $l !== '' && !isset($raus[$k])) {
                    $raus[$k] = $l;
                }
            }
        }
        return $raus;
    }

    private function UntisAbbilden(array $stunden, array $raster, string $kurse): array
    {
        usort($stunden, static fn(array $a, array $b): int
            => [(int)($a['date'] ?? 0), (int)($a['startTime'] ?? 0)]
            <=> [(int)($b['date'] ?? 0), (int)($b['startTime'] ?? 0)]);

        $tage = [];
        $auffaellig = [];
        $ersteWoche = [];
        foreach ($stunden as $st) {
            $datum = (string)($st['date'] ?? '');
            if (strlen($datum) !== 8) {
                continue;
            }
            $zeit = strtotime(substr($datum, 0, 4) . '-' . substr($datum, 4, 2) . '-' . substr($datum, 6, 2));
            $wochentag = (int)date('N', (int)$zeit);        // 1 = Montag
            if ($wochentag > 6) {
                continue;                                    // Sonntag kennt das Modul nicht
            }
            $code = strtolower(trim((string)($st['code'] ?? '')));
            /* Der Eintragstyp entscheidet ZUERST: eine Veranstaltung ist keine
               Vertretung, auch wenn WebUntis sie als „geändert" führt. Das Feld
               gibt es nur aus der neuen Ansicht; ohne es gilt wie bisher der
               Code allein. */
            $art = strtoupper(trim((string)($st['kind'] ?? '')));
            if ($art === 'EVENT') {
                $status = 'termin';
            } else {
                $status = $code === 'cancelled' ? 'entfall' : ($code === 'irregular' ? 'vertretung' : 'normal');
            }
            /* Ganztagsblöcke wie „Projekttag" kommen OHNE Fach, aber mit Text.
               Ohne diesen Rueckfall stuende dort ein Fragezeichen im Plan. */
            $fach = $this->UntisFeld($st, 'su', 'longname') ?: $this->UntisFeld($st, 'su', 'name');
            /* Bei einer Veranstaltung gewinnt IHR Name: der Ausflug heißt
               „Waldschule" und nicht „Biologie", auch wenn er an der
               Biologiestunde hängt. */
            if ($status === 'termin' && trim((string)($st['info'] ?? '')) !== '') {
                $fach = trim((string)$st['info']);
            }
            if ($fach === '') {
                $fach = trim((string)($st['substText'] ?? ($st['info'] ?? '')));
            }
            if ($fach === '') {
                $fach = $this->Translate('Lesson');
            }
            $slot = [
                'subject' => $fach,
                'start'   => $this->UntisZeit((int)($st['startTime'] ?? 0)),
                'end'     => $this->UntisZeit((int)($st['endTime'] ?? 0)),
                'room'    => $this->UntisFeld($st, 'ro', 'name'),
                'teacher' => $this->UntisFeld($st, 'te', 'name'),
                'status'  => $status,
                // Wer ersetzt wurde — nur die neue Ansicht nennt es.
                'insteadOf' => trim((string)($st['insteadOf'] ?? '')),
                // Grund der Abweichung, fuer Meldung und Briefing.
                'grund'   => trim((string)($st['substText'] ?? ($st['info'] ?? ''))),
            ];
            if ($slot['start'] === '' || $slot['end'] === '') {
                continue;
            }
            $ersteWoche[$wochentag][$datum][] = $slot;
        }
        /* Je Wochentag EIN Termin. Genommen wird der fruehste — das ist die
           laufende oder kommende Woche und damit der Plan, der gilt.
           ABER: Tage ohne eine einzige regulaere Stunde werden uebersprungen.
           Am 03.09.2026 lag genau so ein Tag vor: „Projekttag" von 8 bis 13
           Uhr, alle Fachstunden entfallen. Wer den nimmt, hat eine Woche lang
           einen Donnerstag ohne Unterricht im Plan stehen. */
        /* Alle Tage des Zeitraums, jeder unter seinem Datum — die Ebene ueber
           dem Wochenplan. Dieselbe Kurswahl wie unten, sonst staenden auch
           hier fuenf Religionskurse uebereinander. */
        $datiert = [];
        /* Die verworfenen Meldungen kommen aus DIESEM Durchgang, nicht aus dem
           Wochenplan: gemeldet wird, was an einem DATUM geschieht. */
        $verworfen = [];
        foreach ($ersteWoche as $termine) {
            foreach ($termine as $datum => $slots) {
                [$gewaehlt, , $weg] = $this->UntisKurseWaehlen($slots, $kurse);
                foreach ($weg as $w) {
                    $verworfen[] = date('d.m.', (int)strtotime(substr((string)$datum, 0, 4) . '-'
                        . substr((string)$datum, 4, 2) . '-' . substr((string)$datum, 6, 2))) . ' ' . $w;
                }
                if ($gewaehlt !== []) {
                    $datiert[substr((string)$datum, 0, 4) . '-' . substr((string)$datum, 4, 2)
                        . '-' . substr((string)$datum, 6, 2)] = $gewaehlt;
                }
            }
        }
        ksort($datiert);

        /* Gemeldet wird, was das Kind BETRIFFT — also aus den gewaehlten
           Stunden, nicht aus dem Rohplan. Sonst zaehlt der Klassenplan mit:
           am 03.09.2026 waeren es 12 Ausfaelle gewesen, darunter vier fremde
           Religionskurse, beide AGs und der andere Foerderkurs. */
        foreach ($datiert as $datum => $slots) {
            foreach ($slots as $s) {
                if ((string)$s['status'] !== 'normal') {
                    $auffaellig[] = $s + ['datum' => str_replace('-', '', (string)$datum)];
                }
            }
        }

        foreach ($ersteWoche as $wt => $termine) {
            ksort($termine);
            $genommen = '';
            foreach ($termine as $datum => $slots) {
                foreach ($slots as $s) {
                    if ($s['status'] === 'normal') {
                        $genommen = (string)$datum;
                        break 2;
                    }
                }
            }
            if ($genommen === '') {
                $genommen = (string)array_key_first($termine);
            }
            $tage[$wt] = $termine[$genommen];
        }
        ksort($tage);

        // Ueberschneidungen aufloesen, bevor der Plan geschrieben wird.
        $offen = 0;
        foreach ($tage as $wt => $slots) {
            [$tage[$wt], $n] = $this->UntisKurseWaehlen($slots, $kurse);
            $offen += $n;
        }
        return [$tage, $datiert, $auffaellig, $offen, $verworfen];
    }

    /**
     * Bei mehreren Stunden zur SELBEN Zeit entscheidet die Kursliste des Kindes.
     *
     * Der Plan des Kontos ist der KLASSENplan: am 03.09.2026 gemessen standen
     * donnerstags um 13:05 alle fuenf Religions- und Philosophiekurse
     * nebeneinander, um 9:30 beide Foerderkurse und um 14:10 zwei AGs. Wer das
     * ungefiltert einspielt, legt fuenf Stunden uebereinander.
     *
     * Ohne Treffer bleibt die Zeit LEER und wird gezaehlt — lieber eine Luecke,
     * die in der Statuszeile steht, als ein willkuerlich gewaehlter Kurs.
     * Ein Eintrag mit MINUS davor („-AG") wirft ein Fach immer raus, auch wenn
     * es allein steht.
     *
     * @return array{0:list<array<string,mixed>>, 1:int, 2:list<string>}
     */
    private function UntisKurseWaehlen(array $slots, string $kurse): array
    {
        $ja = $nein = [];
        foreach (preg_split('/[;,]/u', $kurse) ?: [] as $eintrag) {
            $e = mb_strtolower(trim((string)$eintrag));
            if ($e === '') {
                continue;
            }
            // Mit Minus davor: dieses Fach gehoert NICHT zum Kind, auch ohne
            // Ueberschneidung. Gemessen: montags steht die Brettspiele-AG
            // allein im Klassenplan, besucht wird sie nicht.
            if (str_starts_with($e, '-')) {
                $nein[] = ltrim($e, '- ');
            } else {
                $ja[] = $e;
            }
        }
        if ($nein !== []) {
            $slots = array_values(array_filter($slots, function (array $s) use ($nein): bool {
                foreach ($nein as $n) {
                    // Wortgrenze, sonst faengt „ag" auch „Tagesbetreuung".
                    if (preg_match('/\b' . preg_quote($n, '/') . '\b/ui', (string)$s['subject']) === 1) {
                        return false;
                    }
                }
                return true;
            }));
        }

        $nachZeit = [];
        foreach ($slots as $s) {
            $nachZeit[(string)$s['start']][] = $s;
        }
        $raus = [];
        $offen = 0;
        /* Was beim Verwerfen an MELDUNGEN verloren geht. Eine ungeklaerte
           Ueberschneidung leert die Zeit — steckte darin ein Entfall oder eine
           Vertretung, faellt damit auch die Nachricht darueber weg. Das durfte
           nicht lautlos passieren: der Nutzer sah einen freien Platz im Plan und
           erfuhr nie, dass zu dieser Zeit etwas ausfiel. */
        $verworfen = [];
        foreach ($nachZeit as $zeit => $gruppe) {
            /* ZUERST gleiche Faecher zusammenfassen: zweimal derselbe Name zur
               selben Zeit ist keine Wahl, sondern eine Doppelung (gemessen:
               „Individuelle Foerderung D/E/M" mittwochs 10:35 in zwei Gruppen).
               Erst was danach uebrig bleibt, ist wirklich eine Wahl. */
            $nachFach = [];
            foreach ($gruppe as $s) {
                $nachFach[mb_strtolower((string)$s['subject'])] ??= $s;
            }
            $gruppe = array_values($nachFach);
            /* Ein Entfall und ein Ersatz zur selben Zeit sind keine Wahl
               zwischen Kursen — beides ist wahr. Am Projekttag stand um 8 Uhr
               der Block „Projekttag" NEBEN der entfallenen Mathematik; die
               Kursliste kannte beides nicht und hat die Zeit geleert.
               Also: was stattfindet, schlaegt was ausfaellt. Faellt alles aus,
               bleibt der Entfall stehen — durchgestrichen ist die Auskunft. */
            // Vor dem Filtern merken: bleibt die Zeit spaeter ganz ungeklaert,
            // zaehlt fuer den Verlust ALLES, was hier stand — auch der Entfall,
            // den dieser Filter gerade weggenommen hat.
            $alle = $gruppe;
            $stattfindend = array_values(array_filter($gruppe,
                static fn(array $s): bool => (string)$s['status'] !== 'entfall'));
            if ($stattfindend !== []) {
                $gruppe = $stattfindend;
            }
            if (count($gruppe) === 1) {
                $raus[] = $gruppe[0];
                continue;
            }
            $passend = [];
            foreach ($gruppe as $s) {
                $fach = mb_strtolower((string)$s['subject']);
                foreach ($ja as $k) {
                    if (str_contains($fach, $k) || str_contains($k, $fach)) {
                        $passend[] = $s;
                        break;
                    }
                }
            }
            if ($passend === []) {
                $offen++;
                $this->SendDebug('WebUntis', 'Ueberschneidung ' . $zeit . ' ungeklaert: '
                    . implode(', ', array_map(static fn(array $s): string => (string)$s['subject'], $gruppe)), 0);
                foreach ($alle as $s) {
                    if ((string)$s['status'] !== 'normal') {
                        $verworfen[] = (string)$zeit . ' ' . (string)$s['subject'];
                    }
                }
                continue;
            }
            if (count($passend) > 1) {
                // Mehrere ECHT verschiedene Treffer: die Kursliste entscheidet
                // nicht. Die erste Stunde steht, der Rest wird gemeldet statt
                // uebereinandergestapelt.
                $offen++;
                $this->SendDebug('WebUntis', 'Ueberschneidung ' . $zeit . ' mehrdeutig: '
                    . implode(', ', array_map(static fn(array $s): string => (string)$s['subject'], $passend)), 0);
            }
            $raus[] = $passend[0];
        }
        usort($raus, static fn(array $a, array $b): int => strcmp((string)$a['start'], (string)$b['start']));
        return [$raus, $offen, $verworfen];
    }

    /** Ein Feld aus der Elementliste einer Stunde („su", „ro", „te"). */
    private function UntisFeld(array $stunde, string $art, string $feld): string
    {
        $werte = [];
        foreach ((array)($stunde[$art] ?? []) as $e) {
            $v = trim((string)($e[$feld] ?? ''));
            if ($v !== '' && !in_array($v, $werte, true)) {
                $werte[] = $v;
            }
        }
        return implode(' / ', $werte);
    }

    /** Untis zaehlt Zeiten als 800 oder 1345. */
    private function UntisZeit(int $wert): string
    {
        if ($wert <= 0) {
            return '';
        }
        return sprintf('%02d:%02d', intdiv($wert, 100), $wert % 100);
    }

    /** Stundenraster — heute nur fuers Protokoll, spaeter fuer Stundennummern. */
    private function UntisRaster(): array
    {
        $r = $this->UntisRpc('getTimegridUnits');
        return is_array($r['result'] ?? null) ? $r['result'] : [];
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

    private function UntisProp(string $name, mixed $vorgabe): mixed
    {
        if ($this->untisConfigCache === null) {
            $cfg = json_decode((string)@IPS_GetConfiguration($this->InstanceID), true);
            $this->untisConfigCache = is_array($cfg) ? $cfg : [];
        }
        return array_key_exists($name, $this->untisConfigCache)
            ? $this->untisConfigCache[$name] : $vorgabe;
    }

    private function UntisStatusSchreiben(string $text): void
    {
        @$this->WriteAttributeString('UntisStatus', (string)json_encode(
            ['t' => time(), 'text' => $text], JSON_UNESCAPED_UNICODE));
        $this->SendDebug('WebUntis', $text, 0);
    }
}

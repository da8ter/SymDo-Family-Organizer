<?php

declare(strict_types=1);

/* Das Rechenwerk der Pruefungen. HIER eingebunden und nicht im Modul: die
   Scanner-Instanz bindet nur diese Datei ein, und eine fehlende Klasse waere
   dort ein Fatal mitten im Lauf. */
require_once __DIR__ . '/UntisPruefungCalc.php';

/**
 * WebUntis — die LESENDE Haelfte.
 *
 * Anmelden, den Plan holen, ihn auf die Slot-Form des Stundenplan-Moduls
 * abbilden, die Hausaufgaben holen. Alles, was Zeit kostet und nichts
 * schreibt — damit es in einer eigenen Instanz laufen kann, waehrend das
 * Gateway Hooks bedient.
 *
 * Die Regel dieser Datei: **kein Attribut, kein Bestand, keine Sperre, kein
 * `STPL_`.** Was hier entsteht, sind Werte. Wer sie ablegt, steht in
 * `WebUntis.php` und laeuft im Gateway: der Stundenplan, der Merker der schon
 * gemeldeten Aenderungen (`UntisLast`), die Kursliste des Formulars und der
 * Fehlerzaehler, der das Konto vor der Sperre schuetzt.
 *
 * ZUGANGSDATEN reisen NICHT mit dem Auftrag. Server, Schule, Benutzer und
 * Kennwort sind EIGENSCHAFTEN des Gateways, und `IPS_GetConfiguration` auf
 * eine fremde Instanz liefert sie — `UntisProp` geht deshalb ueber
 * `KonfigID()`. Das Kennwort bleibt damit, wo es ohnehin steht, und wandert in
 * keine Datei.
 *
 * Und eine Warnung, die hier wichtiger ist als anderswo: WebUntis SPERRT das
 * Konto nach drei Fehlanmeldungen. Deshalb meldet sich diese Haelfte genau
 * einmal je Lauf an (`UntisKontoErnten`), und ob sie ueberhaupt darf,
 * entscheidet das Gateway vorher (`UntisGesperrt`).
 */
trait UntisLesen
{
    /* Die Konstanten der lesenden Haelfte ziehen MIT. Sie standen in
       `WebUntis.php`, und die hat eine Scanner-Instanz nicht — eine
       undefinierte Konstante ist derselbe stille Fatal wie eine
       undefinierte Methode. */
    private const UNTIS_CLIENT      = 'SymDo';   // Selbstauskunft in den Zugriffen der Schule
    private const UNTIS_TAGE_VOR    = 14;        // so weit im Voraus wird geholt
    /**
     * So weit voraus werden PRUEFUNGEN gelesen — und nur sie (24.09.2026).
     *
     * Der Stundenplan bleibt beim Fenster von 14 Tagen: er wird geschrieben,
     * und alles dahinter (Wochenvorlage, Kurswahl, datierte Tage) soll genau
     * das sehen, was es bisher sah. Eine Klassenarbeit steht dagegen oft Wochen
     * vorher im Plan, und genau diese Vorwarnung ist der Wert. Gemessen: 98 Tage
     * kamen in EINEM Abruf, 216 KB.
     */
    private const UNTIS_PRUEFUNG_TAGE = 56;
    /** Hoechstens so viele Detailabrufe (Thema) je Kind und Lauf. */
    private const UNTIS_PRUEFUNG_DETAILS_MAX = 10;
    /**
     * So weit ZURUECK werden die Hausaufgaben geholt — und NUR sie.
     *
     * Eine Lehrkraft hakt waehrend oder nach der Stunde ab, also fruehestens am
     * Faelligkeitstag, oft am Tag darauf. Holte man nur ab heute, waere die
     * Aufgabe in genau diesem Augenblick schon aus dem Fenster gefallen: das
     * Haekchen der Schule kaeme nie an, die Zeile bliebe fuer immer bei den
     * offenen stehen (sie wartet ja auf die Bestaetigung) und verschwaende
     * schliesslich ungeklaert. Gemeldet am 15.09.2026.
     *
     * Gemessen am selben Tag: `homeworks/lessons` beantwortet einen vergangenen
     * Zeitraum VOLLSTAENDIG — 4 Aufgaben ab heute, 13 ab minus sieben Tagen,
     * 16 ab minus einundzwanzig. Das Rueckgriff-Fenster laesst also nichts
     * verschwinden.
     *
     * Der STUNDENPLAN bleibt bewusst draussen: er wird geschrieben, und ein
     * Rueckgriff ueberschriebe vergangene Tage im Stundenplan-Modul.
     */
    private const UNTIS_TAGE_ZURUECK = 7;
    /* Element-Typen von WebUntis. Nur diese beiden taugen als „wessen Plan?":
       ein Erziehungsberechtigten-Konto meldet Personentyp 12 und ist selbst
       KEIN Element — getTimetable antwortet dort „invalid elementType: 12". */
    private const UNTIS_TYP_KLASSE   = 1;
    private const UNTIS_TYP_SCHUELER = 5;
    /* Die OBERGRENZE eines Aufrufs, keine Dauer. Am 15.09.2026 am lebenden
       System gemessen: ein VOLLSTAENDIGER Lauf — Anmeldung, Wochenplan,
       datierte Tage, Hausaufgaben und das Einspielen in den Stundenplan —
       kostet 1,5 bis 2,0 Sekunden. Der Plan rechnete hier mit 20 Sekunden; die
       Zahl stammte aus genau dieser Konstante und war eine Frist, kein
       Messwert. Umgezogen ist WebUntis trotzdem — nicht wegen der Zeit,
       sondern damit alle Schulquellen denselben Weg nehmen. */
    private const UNTIS_HTTP_FRIST  = 20;

    /* Der Zustand EINER Sitzung. Er lebt nur waehrend eines Laufs und
       ueberlebt die Prozessgrenze nicht — deshalb reist alles, was das
       Gateway davon braucht, als Wert im Umschlag. */
    private array $untisSlots = [];
    private string $untisSession = '';
    private string $untisBearer = '';
    private array $untisIch = ['id' => 0, 'type' => 0];
    private ?array $untisConfigCache = null;

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
     * Die Kurswahl eines Kindes als Kursliste in der alten Schreibweise.
     *
     * Angehakte Faecher werden „ja"-Eintraege, abgehakte „-Fach". Damit bleibt
     * der geprueft Parser (UntisKurseWaehlen) unberuehrt: die Liste im Formular
     * ist eine andere BEDIENUNG derselben Sache, keine andere Rechnung.
     *
     * Gibt es fuer dieses Kind keine Zeile, gilt die getippte Kursliste aus dem
     * Datensatz — Bestandszeilen laufen unveraendert weiter.
     */
    private function UntisKurstext(array $kind): string
    {
        $userId = trim((string)($kind['userId'] ?? ''));
        $teile = [];
        foreach ((array)json_decode((string)$this->UntisProp('UntisCourses', '[]'), true) as $z) {
            if (!is_array($z) || trim((string)($z['userId'] ?? '')) !== $userId) {
                continue;
            }
            $kurs = trim((string)($z['kurs'] ?? ''));
            if ($kurs !== '') {
                $teile[] = $kurs;
            }
        }
        /* Die getippte Liste kommt HINZU und weicht nicht: die Auswahl loest
           Ueberschneidungen, ein einzeln stehendes Fach nimmt nur ein
           Minus-Eintrag heraus („-AG"). Doppelte schaden nicht — der Parser
           sammelt Ja und Nein als Listen. */
        $getippt = trim((string)($kind['kurse'] ?? ''));
        if ($getippt !== '') {
            $teile[] = $getippt;
        }
        return implode('; ', $teile);
    }

    /**
     * Ein Kind: Plan holen, abbilden, einspielen, Aenderungen melden.
     *
     * @param array{name:string,stpl:int,child:string,type:int,id:int} $kind
     * @param array<int,array{start:string,end:string}> $raster
     */
    /**
     * Ein Kind ERNTEN: Plan holen, abbilden — und nichts schreiben.
     *
     * Das ist die Arbeit, die Zeit kostet: ein bis zwei Abrufe fuer den Plan,
     * einer fuer die Hausaufgaben. Eingespielt und gemeldet wird in
     * `UntisKindEinpflegen`, im Gateway — dort liegen der Stundenplan, der
     * Merker der schon gemeldeten Aenderungen und die Kursliste.
     *
     * @return array{ok:bool,meldung:string,stunden:int,tage:array,datiert:array,
     *               auffaellig:list<array<string,mixed>>,offen:int,verworfen:list<string>,
     *               slots:array<string,mixed>,klassen:string,hausaufgaben:?array,nr:int}
     */
    /**
     * Das Fenster, ueber das der Plan geholt wird.
     *
     * Der STUNDENPLAN beginnt HEUTE — ein Rueckgriff ueberschriebe vergangene
     * Tage im Stundenplan-Modul. Die Hausaufgaben holen weiter mit Rueckgriff
     * (siehe UntisHausaufgabenZeilen); nur das ZURUECKZIEHEN gilt in diesem
     * engen Fenster hier.
     *
     * @return array{0:int,1:int}
     */
    private function UntisFenster(): array
    {
        return [(int)date('Ymd'), (int)date('Ymd', strtotime('+' . self::UNTIS_TAGE_VOR . ' days'))];
    }

    /**
     * Alle Kinder ERNTEN — mit genau EINER Anmeldung.
     *
     * Das ist der Grund, warum diese Klammer existiert und nicht je Kind
     * angemeldet wird: WebUntis sperrt das Konto nach drei Fehlanmeldungen,
     * und jede zusaetzliche Anmeldung kratzt daran. Eine Sitzung, alle Kinder,
     * dann abmelden — auch wenn dazwischen etwas wirft (`finally`).
     *
     * Ob ueberhaupt angemeldet werden DARF, entscheidet das Gateway vorher
     * (`UntisGesperrt`): der Fehlerzaehler ist ein Attribut und von hier aus
     * nicht zu lesen.
     *
     * @param list<array<string,mixed>> $kinder
     * @return array{ok:bool,meldung:string,kinder:list<array<string,mixed>>}
     */
    private function UntisKontoErnten(array $kinder, bool $mitHausaufgaben = true): array
    {
        $an = $this->UntisLogin();
        if (($an['ok'] ?? false) !== true) {
            return ['ok' => false, 'code' => (int)($an['code'] ?? 0),
                    'meldung' => (string)($an['message'] ?? '?'), 'kinder' => []];
        }
        $raus = [];
        try {
            $raster = $this->UntisRaster();
            foreach ($kinder as $kind) {
                if (!is_array($kind)) {
                    continue;
                }
                $ernte = $this->UntisKindErnten($kind, $raster, $mitHausaufgaben);
                /* Das KIND reist mit: drueben muss zuzuordnen sein, welche
                   Ernte zu welcher Zeile gehoert — und die Zeile traegt die
                   Kennung der Stundenplan-Instanz, das Mitglied und den Namen. */
                $ernte['kind'] = $kind;
                $raus[] = $ernte;
            }
        } finally {
            /* IMMER abmelden. Eine liegengebliebene Sitzung ist kein
               Fehlversuch, aber sie haelt am Server einen Platz — und der
               naechste Lauf faengt sauber an. */
            $this->UntisLogout();
        }
        return ['ok' => true, 'meldung' => '', 'kinder' => $raus];
    }

    private function UntisKindErnten(array $kind, array $raster, bool $mitHausaufgaben): array
    {
        /* `pruefungen` reist auch in der Absage mit: „keine Stunden im
           Zeitraum" heisst in den Ferien, dass das PLANfenster leer ist — die
           Klassenarbeit am ersten Tag danach liegt trotzdem im weiten Fenster.
           null = unbekannt (dann fasst das Gateway den Stand nicht an). */
        $nein = static fn(string $m, ?array $pruefungen = null): array => ['ok' => false, 'meldung' => $m,
            'stunden' => 0, 'tage' => [], 'datiert' => [], 'auffaellig' => [], 'offen' => 0,
            'verworfen' => [], 'slots' => [], 'klassen' => '', 'hausaufgaben' => null, 'nr' => 0,
            'pruefungen' => $pruefungen];

        /* Ohne Zuordnung in der Zielinstanz wuerde der Import mit
           „unknown_child" abgewiesen — dann lieber gleich sagen, was fehlt,
           und die Anfrage an die Schule sparen. */
        if ((int)$kind['stpl'] > 0 && (string)$kind['child'] === '') {
            return $nein(sprintf($this->Translate('%s: no child of that member in the timetable instance — link the member there.'),
                $kind['name']));
        }
        [$von, $bis] = $this->UntisFenster();
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
                        return $nein(sprintf($this->Translate('%1$s: the account has several children — enter the element number (%2$s); „Test connection" names them.'),
                            $kind['name'],
                            implode(', ', array_map(
                                static fn(array $k): string => (string)$k['id'], $kinder))));
                    }
                } else {
                    return $nein(sprintf($this->Translate('%1$s: the account itself is not a timetable element (person type %2$d) and names no child — enter element type and number; the search in the form finds it.'),
                        $kind['name'], $typ));
                }
            }
        }
        if ($typ <= 0 || $nr <= 0) {
            return $nein(sprintf($this->Translate('%s: element type and number belong together — enter both, or leave both empty.'),
                $kind['name']));
        }
        /* ERST die neue Ansicht: nur sie unterscheidet eine Veranstaltung von
           einer Vertretung und nennt die ersetzte Lehrkraft. Sie ist nicht
           öffentlich dokumentiert, deshalb bleibt die JSON-RPC als Rückfall
           stehen — fällt die Ansicht aus, ändert sich am Plan nur, dass
           Termine wieder wie Vertretungen aussehen. */
        /* Das WEITE Fenster: bis UNTIS_PRUEFUNG_TAGE, damit eine Klassenarbeit
           Wochen vorher bekannt ist. Scheitert DIESER Abruf, wird die Ansicht mit
           dem alten Fenster wiederholt, BEVOR die JSON-RPC einspringt — sonst
           risse ein zu grosses Fenster den ganzen Plan mit (Termine saehen dann
           wieder wie Vertretungen aus). Die Pruefungen gelten in dem Fall als
           unbekannt. */
        $bisWeit = (int)date('Ymd', strtotime('+' . self::UNTIS_PRUEFUNG_TAGE . ' days'));
        $alle = $this->UntisPlanRest($typ, $nr, $von, $bisWeit);
        $pruefungenBekannt = $alle !== null && $alle !== [];
        if (!$pruefungenBekannt) {
            $alle = $this->UntisPlanRest($typ, $nr, $von, $bis);
        }
        $quelle = 'rest';
        if ($alle === null || $alle === []) {
            $quelle = 'rpc';
            /* Die RPC kennt Pruefungen nur ungefaehr (lstype „ex"). Ein Rueckfall
               darf den gemerkten Stand deshalb nicht leeren: unbekannt. */
            $pruefungenBekannt = false;
            $params['options']['element'] = ['id' => $nr, 'type' => $typ];
            $r = $this->UntisRpc('getTimetable', $params);
            if (($r['ok'] ?? false) !== true) {
                return $nein($kind['name'] . ': ' . (string)($r['message'] ?? '?'));
            }
            $alle = is_array($r['result'] ?? null) ? $r['result'] : [];
        }
        /* Der PLAN sieht genau das bisherige Fenster: Wochenvorlage, datierte
           Tage, Meldungen, die Kurswahl-Liste des Formulars und die Leer-Pruefung
           unten bleiben damit, wie sie waren. Nur die Pruefungen lesen weiter. */
        $stunden = array_values(array_filter($alle,
            static fn(mixed $st): bool => is_array($st) && (int)($st['date'] ?? 0) <= $bis));
        $pruefungen = null;
        $pruefungWeg = [];
        if ($pruefungenBekannt) {
            [$pruefungen, $pruefungWeg] = $this->UntisPruefungenLesen($alle, $this->UntisKurstext($kind), $bis);
            $pruefungen = $this->UntisPruefungenDetails($pruefungen, $typ, $nr);
        }
        $this->SendDebug('WebUntis', sprintf('%s: %d Stunden über %s, %s Prüfung(en)',
            (string)$kind['name'], count($stunden), $quelle,
            $pruefungen === null ? '?' : (string)count($pruefungen)), 0);
        /* Die Klasse steht in den Stunden des Kindes. Sie gehoert in den
           Bericht: dort steht dann, WELCHER Plan geholt wurde — und wer den
           Klassenplan statt des Kindplans will, findet die Nummer, ohne sie in
           WebUntis zu suchen. */
        $klassen = [];
        foreach ($stunden as $st) {
            foreach ((array)($st['kl'] ?? []) as $k) {
                $kn = trim((string)($k['name'] ?? ''));
                $ki = (int)($k['id'] ?? 0);
                if ($kn !== '' && !array_key_exists($kn, $klassen)) {
                    $klassen[$kn] = $ki;
                }
            }
        }
        /* GEKAPPT: im Klassenplan haengen an den Kursstunden alle beteiligten
           Klassen — gemessen vierzehn (05a bis 08d). Der Bericht soll sagen,
           welcher Plan geholt wurde, und nicht das halbe Schuljahrbuch. */
        $teileK = [];
        foreach ($klassen as $kn => $ki) {
            $teileK[] = $kn . ($ki > 0 ? ' (' . $ki . ')' : '');
        }
        $klassenText = implode(', ', array_slice($teileK, 0, 3))
            . (count($teileK) > 3 ? ' …' : '');
        if ($klassenText !== '') {
            $this->SendDebug('WebUntis', 'Klasse(n): ' . $klassenText, 0);
        }
        if ($stunden === []) {
            return $nein(sprintf($this->Translate('%s: no lessons in the period.'), $kind['name']), $pruefungen);
        }

        /* Der Merker gilt je Kind: leeren, abbilden, mitgeben. Frueher stand er
           im Objektfeld `untisSlots`; seit die Haelften getrennt sind, reist er
           als Wert — ein Objektfeld ueberlebt die Prozessgrenze nicht. */
        $this->untisSlots = [];
        [$tage, $datiert, $auffaellig, $offen, $verworfen] = $this->UntisAbbilden($stunden, $raster, $this->UntisKurstext($kind));
        $slots = $this->untisSlots;
        $this->untisSlots = [];
        /* Pruefungen melden sich SELBST (Gateway, UntisPruefungenEinpflegen) —
           auch eine geaenderte. Blieben sie hier, kaeme zur Meldung „Pruefung
           entfaellt" noch „Deutsch entfaellt" bzw. „Deutsch vertreten". */
        $auffaellig = array_values(array_filter($auffaellig,
            static fn(array $a): bool => ($a['exam'] ?? false) !== true));
        // Pruefungen HINTER dem Planfenster, die an einer ungeklaerten
        // Ueberschneidung scheiterten — die davor zaehlt UntisAbbilden selbst.
        $verworfen = array_merge($verworfen, $pruefungWeg);

        /* Die Hausaufgaben ZULETZT, und in derselben Sitzung. Zuletzt, weil der
           Plan die Hauptsache ist: faellt der Zusatz aus, steht der Stundenplan
           trotzdem. In derselben Sitzung, weil jede weitere Anmeldung an der
           Kontosperre nach drei Fehlversuchen kratzt.

           Der Trockenlauf holt sie ABSICHTLICH nicht: er schreibt nichts, und
           ein Abruf, dessen Ergebnis niemand sieht, ist nur ein Zugriff mehr
           auf das Konto der Schule. */
        $hausaufgaben = null;
        if ($mitHausaufgaben && (bool)$this->UntisProp('UntisHomework', false)) {
            try {
                $hausaufgaben = $this->UntisHausaufgabenZeilen(
                    $nr, $bis, $this->UntisFachKurzformen($stunden));
            } catch (\Throwable $e) {
                $this->SendDebug('WebUntis', 'Hausaufgaben: ' . $e->getMessage(), 0);
                $hausaufgaben = null;
            }
        }

        return ['ok' => true, 'meldung' => '', 'stunden' => count($stunden),
                'tage' => $tage, 'datiert' => $datiert, 'auffaellig' => $auffaellig,
                'offen' => $offen, 'verworfen' => $verworfen, 'slots' => $slots,
                'klassen' => $klassenText, 'hausaufgaben' => $hausaufgaben, 'nr' => $nr,
                'pruefungen' => $pruefungen];
    }

    /**
     * Die Pruefungen eines Kindes aus dem WEITEN Fenster.
     *
     * Dieselbe Kurswahl wie beim Plan — der Klassenplan traegt die
     * Religionsarbeit aller Kurse —, aber ohne ihre Nebenwirkung: mit Wochentag
     * 0 sammelt UntisKurseWaehlen keine Ueberschneidungen fuer das Formular.
     * Sonst wuchsen der Kurswahl-Liste Zeilen aus den Wochen drei bis acht.
     *
     * @param list<array<string,mixed>> $alle Stunden des weiten Fensters
     * @return array{0:list<array<string,mixed>>, 1:list<string>} Pruefungen und,
     *         HINTER dem Planfenster, die an einer ungeklaerten Ueberschneidung
     *         gescheiterten (als Zeile fuer die Statusmeldung)
     */
    private function UntisPruefungenLesen(array $alle, string $kurse, int $planBis): array
    {
        $jeDatum = [];
        foreach ($alle as $st) {
            $b = is_array($st) ? $this->UntisSlotBauen($st) : null;
            if ($b !== null) {
                $jeDatum[$b['datum']][] = $b['slot'];
            }
        }
        ksort($jeDatum);
        $roh = [];
        $weg = [];
        foreach ($jeDatum as $datum => $slots) {
            $mitPruefung = array_filter($slots, static fn(array $s): bool => ($s['exam'] ?? false) === true);
            if ($mitPruefung === []) {
                continue;
            }
            [$gewaehlt, , , $pruefungWeg] = $this->UntisKurseWaehlen($slots, $kurse, 0);
            $iso = substr((string)$datum, 0, 4) . '-' . substr((string)$datum, 4, 2) . '-' . substr((string)$datum, 6, 2);
            foreach ($gewaehlt as $s) {
                if (($s['exam'] ?? false) !== true) {
                    continue;
                }
                $roh[] = [
                    'ids'     => (int)($s['pid'] ?? 0) > 0 ? [(int)$s['pid']] : [],
                    'date'    => $iso,
                    'start'   => (string)$s['start'],
                    'end'     => (string)$s['end'],
                    'subject' => (string)$s['subject'],
                    'title'   => (string)($s['examTitle'] ?? ''),
                    'room'    => (string)($s['room'] ?? ''),
                    'teacher' => (string)($s['teacher'] ?? ''),
                    'status'  => (string)$s['status'],
                ];
            }
            if ((int)$datum > $planBis) {
                foreach ($pruefungWeg as $s) {
                    $weg[] = date('d.m.', (int)strtotime($iso)) . ' ' . (string)$s['start'] . ' '
                        . $this->Translate('Exam') . ' ' . (string)$s['subject'];
                }
            }
        }
        return [UntisPruefungCalc::Zusammenfassen($roh), $weg];
    }

    /**
     * Thema, Name und Art jeder Pruefung aus der Detailansicht (24.09.2026).
     *
     * Der Stundenplan traegt nur `lessonInfo` („KA 1. KA Englisch"). Das THEMA
     * („Mich Vorstellen, meine Familie, mein Zimmer") steht allein in
     * `calendar-entry/detail` unter `exam.description` — gemessen an der
     * SymBox, nur in v2 (v1 antwortet 404 „no longer supported"). Dort auch
     * der saubere Name („1. KA Englisch") und die Art („Klassenarbeit").
     *
     * Ein Abruf je Pruefung, in derselben Sitzung, gedeckelt: jede Anfrage ist
     * ein Zugriff mehr auf das Konto der Schule. Scheitert einer, bleibt die
     * Pruefung, wie sie ist — das Thema ist ein Zusatz.
     *
     * @param list<array<string,mixed>> $pruefungen
     * @return list<array<string,mixed>>
     */
    private function UntisPruefungenDetails(array $pruefungen, int $typ, int $nr): array
    {
        if ($typ !== self::UNTIS_TYP_SCHUELER && $typ !== self::UNTIS_TYP_KLASSE) {
            return $pruefungen;
        }
        foreach (array_slice(array_keys($pruefungen), 0, self::UNTIS_PRUEFUNG_DETAILS_MAX) as $i) {
            $p = $pruefungen[$i];
            $d = $this->UntisRest('calendar-entry/detail?elementId=' . $nr . '&elementType=' . $typ
                . '&startDateTime=' . $p['date'] . 'T' . $p['start'] . ':00'
                . '&endDateTime=' . $p['date'] . 'T' . $p['end'] . ':00&homeworkOption=DUE', 'api/rest/view/v2/');
            foreach ((array)($d['calendarEntries'] ?? []) as $e) {
                $x = is_array($e) ? ($e['exam'] ?? null) : null;
                if (!is_array($x)) {
                    continue;
                }
                $name = trim((string)($x['name'] ?? ''));
                if ($name !== '') {
                    $pruefungen[$i]['title'] = mb_substr($name, 0, UntisPruefungCalc::TITEL_MAX);
                }
                $pruefungen[$i]['topic'] = mb_substr(trim((string)($x['description'] ?? '')), 0, UntisPruefungCalc::THEMA_MAX);
                $pruefungen[$i]['examType'] = mb_substr(trim((string)($x['typeLongName'] ?? '')), 0, 40);
                break;
            }
        }
        return $pruefungen;
    }

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
                        /* Die KENNUNG gehoert zum Element. Der REST-Weg liess sie
                           fallen, und damit war die Klasse eines Kindes nur als
                           Name bekannt — fuer den Klassenplan braucht es die
                           Nummer. Kostet keinen Aufruf, sie steht schon da. */
                        'id'       => (int)($c['id'] ?? 0),
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
        /* Eine KLASSENARBEIT ist ein eigener Eintragstyp (am 24.09.2026
           gemessen: `type: EXAM`, der Titel in `lessonInfo` und noch einmal als
           INFO-Element). Die Nummer (`ids[0]`) haelt sie ueber mehrere Abrufe
           wieder erkennbar. */
        $pruefung = $typ === 'EXAM';
        $titel = '';
        if ($pruefung) {
            $titel = trim((string)($e['lessonInfo'] ?? ''));
            if ($titel === '' && isset($sammeln['INFO'][0])) {
                $titel = (string)($sammeln['INFO'][0]['longname'] ?: $sammeln['INFO'][0]['name']);
            }
            if ($titel === '') {
                $titel = trim((string)($e['lessonText'] ?? ''));
            }
        }
        $pid = 0;
        foreach ((array)($e['ids'] ?? []) as $x) {
            if (is_numeric($x) && (int)$x > 0) {
                $pid = (int)$x;
                break;
            }
        }
        /* Die FACHFARBE, wie die Untis-App sie zeigt (24.09.2026 gemessen: je
           Eintrag `color` als „4da9ff", ohne Raute). Unbrauchbares bleibt leer. */
        $farbe = trim((string)($e['color'] ?? ''), " #");
        $farbe = preg_match('/^[0-9A-Fa-f]{6}$/', $farbe) === 1 ? '#' . strtoupper($farbe) : '';
        /* Die STUNDEN-NOTIZ der Lehrkraft („Vokabeltest Unit 1 Station 1",
           „Heute Filmdreh! … Materialien denken"), 24.09.2026 gemessen: in
           `notesAll`, dieselbe noch einmal als `texts` vom Typ NOTES_FOR_ALL. */
        $notiz = trim((string)($e['notesAll'] ?? ''));
        if ($notiz === '') {
            $teile = [];
            foreach ((array)($e['texts'] ?? []) as $t) {
                if (is_array($t) && (string)($t['type'] ?? '') === 'NOTES_FOR_ALL'
                    && trim((string)($t['text'] ?? '')) !== '') {
                    $teile[] = trim((string)$t['text']);
                }
            }
            $notiz = implode("\n", $teile);
        }
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
            'exam'      => $pruefung,
            'examTitle' => mb_substr($titel, 0, UntisPruefungCalc::TITEL_MAX),
            'pid'       => $pid,
            'color'     => $farbe,
            'notes'     => UntisPruefungCalc::NotizSauber($notiz),
        ];
    }

    /**
     * Die LESENDE Haelfte: die Hausaufgaben holen und in Zeilen bringen.
     *
     * Sie schreibt nichts — genau deshalb kann sie spaeter in einer eigenen
     * Instanz laufen, waehrend das Gateway Hooks bedient. Der Unterschied
     * zwischen `null` und `[]` traegt dabei eine Loeschung: `null` heisst „die
     * Antwort war nicht zu verstehen" und darf NICHTS bewirken, `[]` heisst
     * „diese zwei Wochen sind aufgabenfrei" und zieht die uebernommenen
     * Aufgaben im Fenster zurueck.
     *
     * @param array<string,string> $kurz Kuerzel => langer Fachname
     * @return list<array<string,mixed>>|null null = Antwort unverstaendlich
     */
    private function UntisHausaufgabenZeilen(int $nr, int $bis, array $kurz): ?array
    {
        /* GEHOLT wird mit Rueckgriff, ZURUECKGEZOGEN nur im Vorwaertsfenster —
           die beiden Fenster sind absichtlich verschieden.

           `Zusammenfuehren` fuehrt jeden hereinkommenden Satz ueber seine
           Herkunftsnummer zusammen, ganz gleich wo er liegt; das Fenster
           entscheidet allein darueber, was als VERSCHWUNDEN gilt und geloescht
           wird. Waere der Rueckgriff auch dort gueltig, loeschte eine Antwort,
           die einen alten Tag einmal nicht mitbringt, die Aufgaben dieses Tages
           aus dem Bestand. So kommt das spaete Haekchen an, und nichts Altes
           kann dabei verlorengehen. */
        $holVon = (int)date('Ymd', strtotime('-' . self::UNTIS_TAGE_ZURUECK . ' days'));
        $d = $this->UntisRest('homeworks/lessons?startDate=' . $holVon . '&endDate=' . $bis, 'api/');
        $daten = is_array($d['data'] ?? null) ? $d['data'] : (is_array($d) ? $d : []);
        if ($daten === []) {
            return null;
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
            return null;
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

        return $roh;
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
            $b = is_array($st) ? $this->UntisSlotBauen($st) : null;
            if ($b !== null) {
                $ersteWoche[$b['wt']][$b['datum']][] = $b['slot'];
            }
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
                /* Der Wochentag kommt MIT: gesammelt wird ueber alle Termine
                   und vereinigt je Wochentag und Uhrzeit. Nur die Musterwoche
                   zu nehmen liess Konkurrenten fehlen — am 10.09.2026 standen
                   donnerstags um 13:05 sechs Kurse, in der einen genommenen
                   Woche aber nur zwei davon (die anderen entfallen oder in
                   einer anderen Woche). Der Schluessel ist ein Wochentag, also
                   zaehlt jede Wiederholung nur einmal. */
                $wtDatum = (int)date('N', (int)strtotime(substr((string)$datum, 0, 4) . '-'
                    . substr((string)$datum, 4, 2) . '-' . substr((string)$datum, 6, 2)));
                [$gewaehlt, , $weg] = $this->UntisKurseWaehlen($slots, $kurse, $wtDatum);
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
            /* Eine Notiz auch nicht („Vokabeltest") — sie gilt dem einen Tag.
               Eine Klassenarbeit ist kein WOCHENmuster: faellt der Tag, der die
               Vorlage stellt, auf eine Pruefung, steht dort die Stunde — sonst
               stuende die KA jeden Donnerstag im Plan. */
            $tage[$wt] = array_map(static fn(array $s): array
                => ['exam' => false, 'examTitle' => '', 'notes' => ''] + $s, $termine[$genommen]);
        }
        ksort($tage);

        // Ueberschneidungen aufloesen, bevor der Plan geschrieben wird.
        $offen = 0;
        foreach ($tage as $wt => $slots) {
            [$tage[$wt], $n] = $this->UntisKurseWaehlen($slots, $kurse, (int)$wt);
            $offen += $n;
        }
        return [$tage, $datiert, $auffaellig, $offen, $verworfen];
    }

    /**
     * EINE Stunde aus WebUntis in die Slot-Form des Stundenplan-Moduls.
     *
     * Herausgezogen aus UntisAbbilden, weil zwei Durchgaenge sie brauchen: der
     * Plan (14 Tage) und die Pruefungen (UNTIS_PRUEFUNG_TAGE). Zwei Fassungen
     * derselben Zuordnung liefen irgendwann auseinander.
     *
     * @param array<string,mixed> $st
     * @return array{datum:string, wt:int, slot:array<string,mixed>}|null
     *         null: kein Datum, Sonntag oder keine Uhrzeit
     */
    private function UntisSlotBauen(array $st): ?array
    {
        $datum = (string)($st['date'] ?? '');
        if (strlen($datum) !== 8) {
            return null;
        }
        $zeit = strtotime(substr($datum, 0, 4) . '-' . substr($datum, 4, 2) . '-' . substr($datum, 6, 2));
        $wochentag = (int)date('N', (int)$zeit);        // 1 = Montag
        if ($wochentag > 6) {
            return null;                                 // Sonntag kennt das Modul nicht
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
        /* Die Pruefung: aus der neuen Ansicht als Merkmal, aus der alten
           JSON-RPC als `lstype: ex`. Dort steht der Titel, wenn ueberhaupt, im
           Stundentext. */
        $pruefung = ($st['exam'] ?? false) === true
            || strtolower(trim((string)($st['lstype'] ?? ''))) === 'ex';
        $titel = $pruefung
            ? (trim((string)($st['examTitle'] ?? '')) ?: trim((string)($st['lstext'] ?? '')))
            : '';
        /* Ganztagsblöcke wie „Projekttag" kommen OHNE Fach, aber mit Text.
           Ohne diesen Rueckfall stuende dort ein Fragezeichen im Plan. */
        $fach = $this->UntisFeld($st, 'su', 'longname') ?: $this->UntisFeld($st, 'su', 'name');
        /* Bei einer Veranstaltung gewinnt IHR Name: der Ausflug heißt
           „Waldschule" und nicht „Biologie", auch wenn er an der
           Biologiestunde hängt. */
        if ($status === 'termin' && trim((string)($st['info'] ?? '')) !== '') {
            $fach = trim((string)$st['info']);
        }
        // Eine Pruefung ohne Fach heisst wie sie selbst, nicht „Stunde".
        if ($fach === '' && $pruefung) {
            $fach = $titel;
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
            'exam'      => $pruefung,
            'examTitle' => mb_substr($titel, 0, UntisPruefungCalc::TITEL_MAX),
            // Die Nummer aus WebUntis; das Stundenplan-Modul laesst sie fallen.
            'pid'       => (int)($st['pid'] ?? ($st['id'] ?? 0)),
            // Fachfarbe aus WebUntis; das Stundenplan-Modul merkt sie je Fach.
            'color'     => (string)($st['color'] ?? ''),
            // Notiz der Lehrkraft zu DIESER Stunde an DIESEM Tag.
            'notes'     => (string)($st['notes'] ?? ''),
        ];
        if ($slot['start'] === '' || $slot['end'] === '') {
            return null;
        }
        return ['datum' => $datum, 'wt' => $wochentag, 'slot' => $slot];
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
     * @return array{0:list<array<string,mixed>>, 1:int, 2:list<string>, 3:list<array<string,mixed>>}
     *         3 = Pruefungen, die an einer ungeklaerten Ueberschneidung scheiterten
     */
    private function UntisKurseWaehlen(array $slots, string $kurse, int $wochentag = 0): array
    {
        /* Zweimal dasselbe Fach zur selben Zeit: welcher Eintrag bleibt? Bis zum
           24.09.2026 der ERSTE — und stand die entfallene Deutschstunde vor der
           Klassenarbeit, verschluckte sie die Arbeit. Jetzt eine Rangfolge:
           Pruefung findet statt > Stunde findet statt > Pruefung entfaellt >
           Stunde entfaellt. Bei gleichem Rang bleibt es beim ersten. */
        $rang = static fn(array $s): int => ((string)($s['status'] ?? '') !== 'entfall' ? 2 : 0)
            + (($s['exam'] ?? false) === true ? 1 : 0);
        $einmalJeFach = static function (array $gruppe) use ($rang): array {
            $nachFach = [];
            foreach ($gruppe as $s) {
                $k = mb_strtolower((string)$s['subject']);
                if (!isset($nachFach[$k]) || $rang($s) > $rang($nachFach[$k])) {
                    $nachFach[$k] = $s;
                }
            }
            return $nachFach;
        };
        /* ZUERST sammeln, WO sich Stunden ueberschneiden — und zwar bevor der
           Minus-Filter zuschlaegt: sonst faellt ein bereits abgewaehltes Fach
           aus der Auswahl heraus und liesse sich nie wieder waehlen.

           Der Schluessel ist Wochentag + Uhrzeit, kein Datum: derselbe
           Donnerstag aus zwei Wochen ist EINE Entscheidung. Deshalb duerfen
           beide Wege sammeln — das Wochenraster und die datierten Tage —, und
           erst ihre Vereinigung nennt alle Konkurrenten. */
        if ($wochentag > 0) {
            $zeiten = [];
            foreach ($slots as $s) {
                if (trim((string)($s['subject'] ?? '')) === '') {
                    continue;
                }
                $zeiten[(string)($s['start'] ?? '')][] = $s;
            }
            foreach ($zeiten as $zeit => $gruppe) {
                /* DIESELBE Reduktion wie unten im Filter, sonst verspricht die
                   Liste eine Wahl, die es nicht gibt: zweimal derselbe
                   Fachname ist eine Doppelung, und ein Entfall neben einer
                   stattfindenden Stunde ist keine Wahl zwischen Kursen. */
                $nachFach = $einmalJeFach($gruppe);
                $stattfindend = array_filter($nachFach,
                    static fn(array $s): bool => (string)$s['status'] !== 'entfall');
                $wahl = $stattfindend !== [] ? $stattfindend : $nachFach;
                if (count($wahl) < 2) {
                    continue;
                }
                $schluessel = $wochentag . '|' . (string)$zeit;
                $this->untisSlots[$schluessel] ??= ['wt' => $wochentag,
                                                    'zeit' => (string)$zeit, 'faecher' => []];
                foreach ($wahl as $s) {
                    $this->untisSlots[$schluessel]['faecher'][trim((string)$s['subject'])] = true;
                }
            }
        }

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
        $pruefungWeg = [];
        foreach ($nachZeit as $zeit => $gruppe) {
            /* ZUERST gleiche Faecher zusammenfassen: zweimal derselbe Name zur
               selben Zeit ist keine Wahl, sondern eine Doppelung (gemessen:
               „Individuelle Foerderung D/E/M" mittwochs 10:35 in zwei Gruppen).
               Erst was danach uebrig bleibt, ist wirklich eine Wahl. Welcher der
               beiden bleibt, sagt die Rangfolge oben. */
            $gruppe = array_values($einmalJeFach($gruppe));
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
                    /* Eine PRUEFUNG, die hier verschwindet, ist die schlimmste
                       Luecke von allen — sie steht auch mit Status „normal" in
                       der Meldung. */
                    if (($s['exam'] ?? false) === true) {
                        $pruefungWeg[] = $s;
                        $verworfen[] = (string)$zeit . ' ' . $this->Translate('Exam') . ' ' . (string)$s['subject'];
                    } elseif ((string)$s['status'] !== 'normal') {
                        $verworfen[] = (string)$zeit . ' ' . (string)$s['subject'];
                    }
                }
                continue;
            }
            if (count($passend) > 1) {
                // Mehrere ECHT verschiedene Treffer: die Kursliste entscheidet
                // nicht. Die erste Stunde steht, der Rest wird gemeldet statt
                // uebereinandergestapelt — eine Pruefung darunter kommt nach
                // vorn (stabil sortiert: sonst bleibt die Reihenfolge).
                $offen++;
                $this->SendDebug('WebUntis', 'Ueberschneidung ' . $zeit . ' mehrdeutig: '
                    . implode(', ', array_map(static fn(array $s): string => (string)$s['subject'], $passend)), 0);
                usort($passend, static fn(array $a, array $b): int => $rang($b) <=> $rang($a));
            }
            $raus[] = $passend[0];
        }
        usort($raus, static fn(array $a, array $b): int => strcmp((string)$a['start'], (string)$b['start']));
        return [$raus, $offen, $verworfen, $pruefungWeg];
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

    /**
     * Eine Eigenschaft der Instanz, deren Konfiguration gilt.
     *
     * Ueber `AiProp` und damit ueber `KonfigID()`: im Gateway die eigene, in
     * einer Scanner-Instanz die des Gateways. Ohne das laese die lesende
     * Haelfte drueben ihre EIGENE Konfiguration — und die kennt weder Server
     * noch Schule noch Zugangsdaten.
     *
     * Genau deshalb muss der Auftrag sie NICHT mitschicken: es sind
     * Eigenschaften, keine Attribute, und `IPS_GetConfiguration` auf eine
     * fremde Instanz liefert sie. Das Kennwort bleibt damit dort, wo es
     * ohnehin steht, und wandert in keine Datei.
     */
    private function UntisProp(string $name, mixed $vorgabe): mixed
    {
        $wert = $this->AiProp($name);
        return $wert === null ? $vorgabe : $wert;
    }

}

<?php

declare(strict_types=1);

require_once __DIR__ . '/MoodleCalc.php';
require_once __DIR__ . '/../../libs/AiRecipePage.php';

/**
 * LOGINEO NRW LMS (Moodle) — die LESENDE Haelfte.
 *
 * Alles, was Zeit kostet und nichts schreibt: die Web-Service-Aufrufe, das
 * Zerlegen der Kurse in Karten, das Sammeln von Aufgaben, Abstimmungen und
 * Terminen. Genau dieser Teil kann in einer eigenen Instanz laufen, damit die
 * Gateway-Spur frei bleibt — ein Konto kostet je Aufgabe einen eigenen Abruf
 * (Abgabestand), und ein Abruf darf 25 Sekunden brauchen.
 *
 * Die Regel dieser Datei: **kein Attribut, kein Bestand, keine Sperre.** Was
 * hier entsteht, sind Werte. Wer sie ablegt, steht in `Moodle.php` und laeuft
 * im Gateway — dort liegen Tokens, Merker, Hausaufgaben und Vorschlaege.
 * `KompositionTest` haelt die Regel nach.
 *
 * Der ZUGANG, den diese Haelfte bekommt, traegt alles Noetige bei sich:
 * `{site, userId, name, token}`. Sie fragt keinen Tokenspeicher — den gibt es
 * in einer Scanner-Instanz gar nicht, und `IPS_GetProperty` auf eine fremde
 * Instanz zeigt einen hinterlegten Wert ohnehin nicht.
 */
trait MoodleLesen
{
    /** Sekunden je Aufruf an die Schule. Sie ist gemuetlich. */
    private const MOODLE_HTTP_FRIST    = 25;

    /* So weit im Voraus werden Aufgaben geholt — und genau dieses Fenster gilt
       auch beim Zurueckziehen. Nicht das Jahr aus HomeworkCalc: ein halb
       gelesener Abruf soll nicht ein Jahr Hausaufgaben wegnehmen koennen. */
    private const MOODLE_TAGE_VOR      = 90;

    /**
     * Ein Web-Service-Aufruf. Gibt die Antwort als Karte zurück, `null` bei
     * jedem Fehlschlag — der Aufrufer entscheidet, ob das schlimm ist.
     *
     * HIER sitzt die weisse Liste. Sie ist der Grund, warum dieses Modul in der
     * Schule nichts anrichten kann: was nicht in MoodleCalc::ERLAUBT steht,
     * verlässt diese Funktion nicht.
     */
    private function MoodleRest(array $zugang, string $funktion, array $params = []): ?array
    {
        if (!MoodleCalc::Erlaubt($funktion)) {
            /* Kein stilles Nein: das ist ein Fehler im Modul, nicht im Netz. */
            $this->SendDebug('Moodle', 'ABGEWIESEN (nicht auf der weissen Liste): ' . $funktion, 0);
            $this->LogMessage('SymDo: Moodle-Aufruf „' . $funktion
                . '" ist nicht freigegeben — nur lesende Funktionen sind erlaubt.', KL_ERROR);
            return null;
        }
        /* Der Token kommt MIT dem Zugang, nicht aus einem Speicher: in einer
           Scanner-Instanz gibt es keinen, und `IPS_GetProperty` auf eine fremde
           Instanz zeigt einen hinterlegten Wert ohnehin nicht. Wer diese
           Haelfte ruft, legt ihn dazu (MoodleZugaenge im Gateway). */
        $token = trim((string)($zugang['token'] ?? ''));
        if ($token === '') {
            $this->SendDebug('Moodle', 'kein Token für ' . (string)($zugang['name'] ?? '?'), 0);
            return null;
        }
        $antwort = $this->MoodleHttp((string)$zugang['site'] . '/webservice/rest/server.php',
            array_merge([
                'wstoken'            => $token,
                'wsfunction'         => $funktion,
                'moodlewsrestformat' => 'json',
            ], $this->MoodleFlach($params)));
        if ($antwort === null) {
            return null;
        }
        $d = json_decode((string)$antwort['body'], true);
        if ($d === null) {
            $this->SendDebug('Moodle', $funktion . ': keine JSON-Antwort (HTTP '
                . (int)$antwort['status'] . ')', 0);
            return null;
        }
        /* Moodle meldet Fehler als 200 mit `exception`. Ohne diese Weiche sähe
           ein abgelaufener Token wie eine leere Antwort aus — und der Lauf
           räumte auf, statt zu klagen. */
        if (is_array($d) && isset($d['exception'])) {
            $code = trim((string)($d['errorcode'] ?? ''));
            $this->SendDebug('Moodle', $funktion . ': ' . $code . ' — '
                . trim((string)($d['message'] ?? '')), 0);
            return null;
        }
        // Eine Liste kommt als Liste, eine Karte als Karte — beides ist ein array.
        return is_array($d) ? $d : null;
    }

    /**
     * Moodle nimmt verschachtelte Parameter nur flach: `courseids[0]=77`.
     *
     * @return array<string,string>
     */
    private function MoodleFlach(array $params, string $praefix = ''): array
    {
        $raus = [];
        foreach ($params as $k => $v) {
            $name = $praefix === '' ? (string)$k : $praefix . '[' . $k . ']';
            if (is_array($v)) {
                $raus += $this->MoodleFlach($v, $name);
                continue;
            }
            $raus[$name] = is_bool($v) ? ($v ? '1' : '0') : (string)$v;
        }
        return $raus;
    }

    /**
     * POST mit Formularfeldern. Eigenes curl wie in jedem anderen Trait hier —
     * ein gemeinsamer HTTP-Helfer gibt es im Gateway nicht.
     *
     * @return array{status:int,body:string}|null
     */
    private function MoodleHttp(string $url, array $felder): ?array
    {
        /* Dieselbe Wache wie beim Abruf fremder Seiten: die Adresse kommt aus
           dem Formular, sie darf nicht ins eigene Netz zeigen. */
        /* Der SSRF-Riegel direkt aus dem Rechenkern, NICHT ueber die
           Gateway-Hilfe `AiIsPublicUrl`: die steht in `AiExtract`, und dieser
           Trait laeuft auch in einer Scanner-Instanz, die den nicht hat. Genau
           daran ist der erste LOGINEO-Lauf aus der zweiten Spur gestorben —
           „Call to undefined method", still, in einem Zeitgeber. */
        if (!AiRecipePage::istOeffentlich($url)) {
            $this->SendDebug('Moodle', 'Adresse nicht zulässig: ' . $url, 0);
            return null;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($felder),
            CURLOPT_TIMEOUT        => self::MOODLE_HTTP_FRIST,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT      => 'SymDo',
        ]);
        $body   = curl_exec($ch);
        $fehler = ($body === false) ? curl_error($ch) : '';
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($fehler !== '') {
            $this->SendDebug('Moodle', 'HTTP-Fehler: ' . $fehler, 0);
            return null;
        }
        return ['status' => $status, 'body' => (string)$body];
    }

    /**
     * Ein Konto ERNTEN: alles holen, nichts ablegen.
     *
     * Das ist die ganze Arbeit eines LOGINEO-Laufs — Standort, Kurse, je Kurs
     * die Inhalte, dazu Aufgaben (je Aufgabe ein eigener Abruf für den
     * Abgabestand), Abstimmungen und Termine. Ein Abruf darf 25 Sekunden
     * brauchen; bei acht Kursen ist das die Spur für Minuten.
     *
     * Was herauskommt, sind Werte in genau der Form, die der Kanal trägt:
     * `seiten` wie bei den Klassenseiten, `hausaufgaben` und `vorschlaege` als
     * Rohzeilen. Abgelegt wird nichts — das tut `MoodleErnteEinpflegen` im
     * Gateway.
     *
     * @param array{site:string,userId:string,name:string,token:string} $zugang
     * @param list<string> $gesperrt Adressen, die der Nutzer in der App gelöscht hat
     * `aufgabenFehler` zaehlt Konten, deren Aufgaben- oder Abstimmungsabruf die
     * Schule schuldig geblieben ist. Fuer sie steht KEIN Eintrag in
     * `hausaufgaben` — der Abgleich beim Einpflegen hielte eine leere Liste
     * sonst fuer „alles erledigt" und zoege die Aufgaben im Fenster zurueck.
     *
     * @return array{ok:bool,kurse:int,karten:int,seiten:list<array<string,mixed>>,
     *               hausaufgaben:list<array<string,mixed>>,vorschlaege:list<array<string,mixed>>,
     *               gesperrt:int,rueckmeldungen:int,aufgabenFehler:int}
     */
    private function MoodleKontoErnten(array $zugang, array $gesperrt = [],
        bool $hausaufgabenAn = true, bool $termineAn = true): array
    {
        $leer = ['ok' => false, 'kurse' => 0, 'karten' => 0, 'seiten' => [],
                 'hausaufgaben' => [], 'vorschlaege' => [], 'gesperrt' => 0,
                 'rueckmeldungen' => 0, 'aufgabenFehler' => 0];

        $info = $this->MoodleRest($zugang, 'core_webservice_get_site_info');
        if (!is_array($info)) {
            return $leer;
        }
        $kurse = $this->MoodleRest($zugang, 'core_enrol_get_users_courses',
            ['userid' => (int)($info['userid'] ?? 0)]);
        if (!is_array($kurse)) {
            return $leer;
        }

        /* Die Sperrliste reist MIT dem Auftrag. Sie steht im Bestand des
           Gateways, und der ist hier nicht zu erreichen — ohne sie holte
           dieser Lauf die Inhalte eines Kurses, den der Nutzer in der App
           laengst geloescht hat. Geprueft wird sie beim Einpflegen ein zweites
           Mal: zwischen Auftrag und Ergebnis koennen Minuten liegen. */
        $sperre = [];
        foreach ($gesperrt as $u) {
            $sperre[trim((string)$u)] = true;
        }

        $seiten = [];
        $karten = 0;
        $uebersprungen = 0;
        $kursListe = [];
        foreach ($kurse as $kurs) {
            if (!is_array($kurs) || (int)($kurs['id'] ?? 0) <= 0) {
                continue;
            }
            $seite = $this->MoodleSeite($zugang, $kurs);
            if (isset($sperre[(string)$seite['url']])) {
                $uebersprungen++;
                continue;
            }
            $liste = $this->MoodleKarten($zugang, (int)$kurs['id']);
            $karten += count($liste);
            $seiten[] = ['seite' => $seite, 'quelle' => 'moodle',
                         'karten' => array_values($liste), 'funde' => []];
            $kursListe[(int)$kurs['id']] = $seite;
        }

        /* Aufgaben und Termine haengen NICHT an einer Seite, sondern am Konto:
           beide Abrufe nennen ihre Kurse selbst. Deshalb hier, nach der
           Schleife, und in EINEM Aufruf je Konto. */
        $hausaufgaben = [];
        $rueckmeldungen = [];
        $aufgabenFehler = 0;
        if ($hausaufgabenAn) {
            $namen = array_map(static fn($f): string => (string)($f['name'] ?? ''),
                (array)($info['functions'] ?? []));
            $rueckmeldungen = $this->MoodleAbstimmungen($zugang, $kursListe, $namen);
            $zeilen = $rueckmeldungen === null
                ? null
                : $this->MoodleAufgabenZeilen($zugang, $kursListe, $rueckmeldungen);
            if ($zeilen === null) {
                /* Ein Abruf blieb aus. Das ist KEINE leere Liste: `HomeworkImportieren`
                   gleicht im Fenster ab, und eine leere Liste hiesse dort „die
                   Schule hat alle Aufgaben zurueckgezogen". Bis zum 18.09.2026
                   wurde aus dem Fehler ein leeres Feld — ein einzelner
                   fehlgeschlagener Abruf loeschte die Hausaufgaben des Kindes.
                   Gefunden vom externen Codereview (F11). Also: kein Eintrag,
                   der Bestand bleibt, der naechste Lauf versucht es wieder. */
                $aufgabenFehler = 1;
                $rueckmeldungen = [];
            } else {
                [$von, $bis] = $this->MoodleFenster();
                /* EIN Eintrag je Konto, nicht je Kurs: `HomeworkImportieren` raeumt
                   im Fenster auf, und zwei Aufrufe mit derselben Quelle hielten die
                   Zeilen des jeweils anderen fuer verschwunden. */
                $hausaufgaben[] = [
                    'userId' => (string)$zugang['userId'],
                    'von'    => $von,
                    'bis'    => $bis,
                    'zeilen' => $zeilen,
                ];
            }
        }

        $vorschlaege = [];
        if ($termineAn) {
            foreach ($this->MoodleTermineZeilen($zugang, $kursListe) as $z) {
                $vorschlaege[] = $z + ['userId' => (string)$zugang['userId']];
            }
        }

        return ['ok' => true, 'kurse' => count($kurse), 'karten' => $karten,
                'seiten' => $seiten, 'hausaufgaben' => $hausaufgaben,
                'vorschlaege' => $vorschlaege, 'gesperrt' => $uebersprungen,
                'rueckmeldungen' => count($rueckmeldungen),
                'aufgabenFehler' => $aufgabenFehler];
    }

    /**
     * Ein Kurs als „Seite" im Sinne der Klassenseiten.
     *
     * Die Adresse ist die ECHTE Kursadresse: sie ist eindeutig, sie oeffnet den
     * Kurs im Browser, und sie ist damit derselbe Schluessel, den die
     * Klassenseiten schon benutzen (`edupage:` + md5 der Adresse). Deshalb
     * braucht EduOrdner() keine Zeile Aenderung — Ebene 1 ist der Ordner des
     * Kindes, den es fuer Edumaps schon gibt, Ebene 2 dieser Kurs.
     *
     * @return array{name:string,url:string,userId:string}
     */
    private function MoodleSeite(array $zugang, array $kurs): array
    {
        $name = trim((string)($kurs['shortname'] ?? ''));
        if ($name === '') {
            $name = trim((string)($kurs['fullname'] ?? ''));
        }
        return [
            'name'   => $name !== '' ? $name : $this->Translate('Course'),
            'url'    => (string)$zugang['site'] . '/course/view.php?id=' . (int)$kurs['id'],
            'userId' => (string)$zugang['userId'],
        ];
    }

    /**
     * Die Karten eines Kurses: Dateien und Forumsbeitraege.
     *
     * Eine Karte je MODUL und nicht je Datei: ein „Material" kann mehrere
     * Dateien tragen, und in der App gehoeren sie zusammen (die Karte haelt sie
     * als Anhaenge). Ein Modul ohne Datei und ohne Text faellt weg — eine leere
     * Karte ist keine Auskunft.
     *
     * @return list<array<string,mixed>>
     */
    private function MoodleKarten(array $zugang, int $kursId): array
    {
        $raus = [];
        $inhalt = $this->MoodleRest($zugang, 'core_course_get_contents', ['courseid' => $kursId]);
        $foren = [];
        foreach ((array)$inhalt as $abschnitt) {
            if (!is_array($abschnitt)) {
                continue;
            }
            $wo = trim((string)($abschnitt['name'] ?? ''));
            /* Die Beschreibung des Abschnitts. Sie steht schon in dieser
               Antwort, kostet also keinen Aufruf — und dort steht oft das
               Eigentliche („bitte bis Freitag zurueck").
               NUR wenn Text uebrig bleibt: an der geprueften Grundschule
               bestehen alle vier Beschreibungen ausschliesslich aus einem
               Kopfbild (205-274 Zeichen HTML, 0 Zeichen Text). Eine Karte
               daraus waere eine leere Karte, und das Bild haengt an einer
               Adresse, die ohne Token nichts liefert. */
            $vorwort = $this->EduText((string)($abschnitt['summary'] ?? ''));
            if ($vorwort !== '' && $wo !== '') {
                $raus[] = [
                    // Dieselbe Form wie in MoodleModulKarte, siehe dort.
                    'quelle' => 'moodle',
                    'srcId'  => 'moodlesec:' . (int)($abschnitt['id'] ?? 0),
                    /* Ein Abschnitt hat kein `timemodified`. Die Fassung ist
                       deshalb der Fingerabdruck des Textes: aendert die Schule
                       ihn, ist es eine neue Fassung — sonst nicht. */
                    'updated'   => (int)crc32((string)($abschnitt['summary'] ?? '')),
                    /* Ein Abschnitt hat kein Datum — dann gilt das des Bestands.
                       AUSDRUECKLICH 0, nicht weggelassen: sonst faellt das Feld
                       auf die Fassung zurueck, und die ist hier ein
                       Fingerabdruck und keine Zeit. */
                    'srcAt'     => 0,
                    // Kein eigener Weg — dann zeigt die Karte auf die Kursseite.
                    'srcUrl'    => '',
                    'titel'     => $wo,
                    'text'      => $vorwort,
                    'html'      => $this->EduHtml((string)($abschnitt['summary'] ?? '')),
                    'abschnitt' => $wo,
                    'abschnittFarbe' => '',
                    'farbe'     => '',
                    'buchung'   => null,
                    'anhaenge'  => [],
                ];
            }
            foreach ((array)($abschnitt['modules'] ?? []) as $modul) {
                if (!is_array($modul)) {
                    continue;
                }
                $art = (string)($modul['modname'] ?? '');
                if ($art === 'forum') {
                    // Foren kommen unten, in einem Zug je Kurs.
                    $foren[(int)($modul['instance'] ?? 0)] = $wo;
                    continue;
                }
                $karte = $this->MoodleModulKarte($modul, $wo);
                if ($karte !== null) {
                    $raus[] = $karte;
                }
            }
        }
        foreach ($this->MoodleForenKarten($zugang, $kursId, $foren) as $k) {
            $raus[] = $k;
        }
        /* Der Token wandert JETZT in die Dateiadressen, nicht erst beim
           Herunterladen: die Karte geht danach durch denselben Weg wie eine
           Klassenseite (`EduDateiHolen`) und — nach dem Umzug — als Datei in
           eine andere Instanz. Dort gibt es keinen Zugang mehr, wohl aber die
           fertige Adresse. Begruendung und Ablageort: MoodleCalc::MitToken. */
        $token = trim((string)($zugang['token'] ?? ''));
        foreach ($raus as $i => $karte) {
            foreach ((array)($karte['anhaenge'] ?? []) as $k => $a) {
                $raus[$i]['anhaenge'][$k]['url'] = MoodleCalc::MitToken((string)($a['url'] ?? ''), $token);
            }
        }
        return $raus;
    }

    /**
     * Ein Modul (Material, Ordner, Seite, Verweis) als Karte.
     *
     * @return array<string,mixed>|null null = nichts drin
     */
    private function MoodleModulKarte(array $modul, string $abschnitt): ?array
    {
        $dateien = [];
        $stand = (int)($modul['timemodified'] ?? 0);
        foreach ((array)($modul['contents'] ?? []) as $c) {
            if (!is_array($c) || (string)($c['type'] ?? '') === 'url') {
                continue;
            }
            $name = trim((string)($c['filename'] ?? ''));
            $url  = trim((string)($c['fileurl'] ?? ''));
            if ($name === '' || $url === '' || $this->EduArt($name) === '') {
                // Nur Bild und PDF: mehr kann die Karte nicht halten.
                continue;
            }
            /* In der Form, die `EduNotizAnhaenge` erwartet. `preview` bleibt
               leer: LOGINEO liefert kein Vorschaubild, und der Nachzug fragt
               dann gar nicht erst danach. */
            $dateien[] = ['name' => $name, 'datei' => $name, 'url' => $url,
                          'preview' => '', 'bytes' => (int)($c['filesize'] ?? 0)];
            $stand = max($stand, (int)($c['timemodified'] ?? 0));
        }
        $text = $this->EduText((string)($modul['description'] ?? ''));
        if ($dateien === [] && $text === '') {
            return null;
        }
        return [
            /* Die Form der KLASSENSEITEN-Karte, plus die vier Felder, die eine
               fremde Quelle mitbringen muss. Nur so geht LOGINEO durch dieselbe
               Tuer in den Bestand (`EduEinpflegen`) — vorher hatte es einen
               eigenen Spiegel, und jede Korrektur musste zweimal gemacht
               werden. */
            'quelle'    => 'moodle',
            'srcId'     => 'moodle:' . (int)($modul['id'] ?? 0),
            'updated'   => $stand,
            // Wann die Schule das Modul (oder seine neueste Datei) angefasst hat.
            'srcAt'     => $stand,
            'srcUrl'    => trim((string)($modul['url'] ?? '')),
            'titel'     => trim((string)($modul['name'] ?? '')),
            'text'      => $text,
            /* Durch die WEISSE LISTE, wie bei Edumaps. Die formatierte Fassung
               geht in der App durch `innerHTML`, und dort wird nichts mehr
               geprueft („weil hier nichts mehr geprueft werden kann", sagt der
               Kommentar in der Web-App). Dieser Text ist von Lehrkraeften in
               Moodle geschrieben — er darf kein Skript und kein Ereignis-
               Attribut mitbringen. Vorher stand er hier ROH: mein Fehler von
               heute Nachmittag. */
            'html'      => $this->EduHtml((string)($modul['description'] ?? '')),
            'abschnitt' => $abschnitt,
            'abschnittFarbe' => '',
            'farbe'     => '',
            'buchung'   => null,
            'anhaenge'  => $dateien,
        ];
    }

    /**
     * Die Beitraege der Foren eines Kurses als Karten.
     *
     * @param array<int,string> $foren Forum-Kennung → Abschnittsname
     * @return list<array<string,mixed>>
     */
    private function MoodleForenKarten(array $zugang, int $kursId, array $foren): array
    {
        if ($foren === []) {
            return [];
        }
        $meta = $this->MoodleRest($zugang, 'mod_forum_get_forums_by_courses',
            ['courseids' => [$kursId]]);
        $raus = [];
        foreach ((array)$meta as $forum) {
            if (!is_array($forum) || (int)($forum['id'] ?? 0) <= 0) {
                continue;
            }
            /* Ein leeres Forum gar nicht erst abfragen: `numdiscussions` steht
               schon hier, und jeder Aufruf ist eine Anfrage an die Schule. */
            if ((int)($forum['numdiscussions'] ?? 0) <= 0) {
                continue;
            }
            $wo = trim((string)($foren[(int)$forum['id']] ?? ''));
            if ($wo === '') {
                $wo = trim((string)($forum['name'] ?? ''));
            }
            $antwort = $this->MoodleRest($zugang, 'mod_forum_get_forum_discussions',
                ['forumid' => (int)$forum['id'], 'perpage' => 20]);
            foreach ((array)($antwort['discussions'] ?? []) as $d) {
                if (!is_array($d)) {
                    continue;
                }
                $dateien = [];
                foreach ((array)($d['attachments'] ?? []) as $a) {
                    $name = trim((string)($a['filename'] ?? ''));
                    $url  = trim((string)($a['fileurl'] ?? ''));
                    if ($name !== '' && $url !== '' && $this->EduArt($name) !== '') {
                        $dateien[] = ['name' => $name, 'datei' => $name, 'url' => $url,
                                      'preview' => '', 'bytes' => (int)($a['filesize'] ?? 0)];
                    }
                }
                $stand = (int)($d['timemodified'] ?? ($d['modified'] ?? 0));
                $raus[] = [
                    // Dieselbe Form wie in MoodleModulKarte, siehe dort.
                    'quelle'    => 'moodle',
                    'srcId'     => 'moodlepost:' . (int)($d['discussion'] ?? ($d['id'] ?? 0)),
                    'updated'   => $stand,
                    'srcAt'     => $stand,
                    'srcUrl'    => (string)$zugang['site'] . '/mod/forum/discuss.php?d='
                                   . (int)($d['discussion'] ?? ($d['id'] ?? 0)),
                    'titel'     => trim((string)($d['subject'] ?? '')),
                    'text'      => $this->EduText((string)($d['message'] ?? '')),
                    // Weisse Liste, siehe MoodleModulKarte: ein Forumsbeitrag
                    // ist fremdes HTML.
                    'html'      => $this->EduHtml((string)($d['message'] ?? '')),
                    'abschnitt' => $wo,
                    'abschnittFarbe' => '',
                    'farbe'     => '',
                    'buchung'   => null,
                    'anhaenge'  => $dateien,
                ];
            }
        }
        return $raus;
    }

    /**
     * Aufgaben der Plattform als Hausaufgaben.
     *
     * Das Fach ist der Kursname: eine Schule nennt ihren Kurs „Mathematik 5a",
     * und HomeworkCalc::FachAufloesen findet daraus „Mathematik", wenn der
     * Stundenplan es kennt — sonst bleibt der Kursname stehen. Erledigt ist,
     * was abgegeben wurde; das Haekchen gehoert damit der Schule und laesst
     * sich zu Hause nicht zuruecknehmen (Sperrklinke in HomeworkCalc).
     *
     * Diese Haelfte LIEST nur — sie laeuft dort, wo die Zeit verbraucht wird
     * (je Aufgabe ein eigener Abruf fuer den Abgabestand). Eingepflegt wird in
     * `MoodleAufgabenEinpflegen`, und zwar dort, wo die Hausaufgaben stehen.
     *
     * Ausgebliebener Abruf und leere Liste sind ZWEI Antworten: `null` heisst
     * „die Schule hat nicht geantwortet", `[]` heisst „es gibt keine Aufgaben".
     * Nur die zweite darf den Bestand abgleichen.
     *
     * @param array<int,array<string,mixed>> $kurse Kurskennung → Seite
     * @return list<array<string,mixed>>|null Zeilen fuer HomeworkImportieren, null bei Abruffehler
     */
    private function MoodleAufgabenZeilen(array $zugang, array $kurse, array $zusatz = []): ?array
    {
        if ($kurse === []) {
            return [];
        }
        $antwort = $this->MoodleRest($zugang, 'mod_assign_get_assignments',
            ['courseids' => array_values(array_map('intval', array_keys($kurse)))]);
        if (!is_array($antwort)) {
            return null;
        }
        [$von, $bis] = $this->MoodleFenster();
        $roh = [];
        foreach ((array)($antwort['courses'] ?? []) as $kurs) {
            $fach = trim((string)($kurs['shortname'] ?? ($kurs['fullname'] ?? '')));
            foreach ((array)($kurs['assignments'] ?? []) as $a) {
                if (!is_array($a)) {
                    continue;
                }
                $faellig = (int)($a['duedate'] ?? 0);
                /* Ohne Faelligkeit ist es keine Hausaufgabe, sondern
                   Kursmaterial — das steht als Karte schon da. */
                if ($faellig <= 0) {
                    continue;
                }
                $tag = date('Y-m-d', $faellig);
                if ($tag < $von || $tag > $bis) {
                    continue;
                }
                /* Der Abgabestand kostet einen Aufruf JE Aufgabe — deshalb erst
                   hier, nachdem Fenster und Faelligkeit stimmen. */
                $erledigt = false;
                $stand = $this->MoodleRest($zugang, 'mod_assign_get_submission_status',
                    ['assignid' => (int)($a['id'] ?? 0)]);
                if (is_array($stand)) {
                    $status = (string)((($stand['lastattempt']['submission']['status']) ?? ''));
                    $erledigt = in_array($status, ['submitted', 'graded'], true);
                }
                $roh[] = [
                    'srcId'   => (int)($a['id'] ?? 0),
                    'subject' => $fach,
                    'due'     => $tag,
                    'note'    => trim((string)($a['name'] ?? '')),
                    'done'    => $erledigt,
                ];
            }
        }
        /* Die Abstimmungen kommen MIT — in EINEM Import.
           Zwei Aufrufe von HomeworkImportieren mit derselben Quelle wuerden
           sich gegenseitig aufraeumen: der zweite haelt die Zeilen des ersten
           fuer verschwunden und loescht sie im Fenster. Genau dieser Fehler ist
           heute zwischen UNTIS und LOGINEO aufgefallen; er gilt innerhalb einer
           Quelle genauso. */
        foreach ($zusatz as $z) {
            if (!is_array($z)) {
                continue;
            }
            $tag = (string)($z['due'] ?? '');
            if ($tag < $von || $tag > $bis) {
                continue;                       // dasselbe Fenster wie fuer Aufgaben
            }
            $roh[] = $z;
        }
        return $roh;
    }

    /** Das Fenster, in dem Aufgaben und Abstimmungen zaehlen. */
    private function MoodleFenster(): array
    {
        return [date('Y-m-d'), date('Y-m-d', strtotime('+' . self::MOODLE_TAGE_VOR . ' days'))];
    }

    /**
     * Abstimmungen als Aufgabe mit Frist.
     *
     * Eine Abstimmung ist das einzige, was diese Plattform an ECHTEN Fristen
     * hergibt: „Fotos der Kinder auf LOGINEO?", „Betreuung in den
     * Winterferien?". Ein Dokument kann man nachlesen, eine Frist verpasst man.
     *
     * Zwei Aufrufe, beide lesend: einmal alle Abstimmungen der Kurse, und je
     * OFFENER Abstimmung ihre Optionen — denn nur dort steht, ob das eigene
     * Konto schon geantwortet hat. Geschlossene kosten keinen zweiten Aufruf;
     * die Frist wird VORHER geprueft.
     *
     * Wie bei den Aufgaben: `null` ist ein ausgebliebener Abruf, `[]` sind
     * keine Abstimmungen. Eine Schule ohne die Funktion hat keine — das ist `[]`.
     *
     * @param array<int,array<string,mixed>> $kurse       Kurskennung → Seite
     * @param list<string>                   $funktionen  was der Server hergibt
     * @return list<array<string,mixed>>|null Zeilen für HomeworkImportieren, null bei Abruffehler
     */
    private function MoodleAbstimmungen(array $zugang, array $kurse, array $funktionen): ?array
    {
        if ($kurse === [] || !MoodleCalc::KannAbstimmungen($funktionen)) {
            return [];
        }
        $antwort = $this->MoodleRest($zugang, 'mod_choice_get_choices_by_courses',
            ['courseids' => array_values(array_map('intval', array_keys($kurse)))]);
        if (!is_array($antwort)) {
            return null;
        }
        $jetzt = time();
        $raus = [];
        foreach ((array)($antwort['choices'] ?? []) as $ab) {
            if (!is_array($ab)) {
                continue;
            }
            // Erst die Frist, dann der Aufruf: eine abgelaufene Abstimmung
            // interessiert niemanden mehr, und jeder Aufruf geht an die Schule.
            if (MoodleCalc::AbstimmungAufgabe($ab, [], $jetzt) === null) {
                continue;
            }
            $opt = $this->MoodleRest($zugang, 'mod_choice_get_choice_options',
                ['choiceid' => (int)($ab['id'] ?? 0)]);
            $zeile = MoodleCalc::AbstimmungAufgabe($ab,
                is_array($opt) ? (array)($opt['options'] ?? []) : [], $jetzt);
            if ($zeile === null) {
                continue;
            }
            $kursId = (int)($ab['course'] ?? 0);
            $fach = trim((string)($kurse[$kursId]['name'] ?? ''));
            $raus[] = [
                'srcId'   => (int)$zeile['srcId'],
                'subject' => $fach !== '' ? $fach : $this->Translate('LOGINEO'),
                'due'     => (string)$zeile['due'],
                'note'    => $this->Translate('Reply needed:') . ' ' . (string)$zeile['name'],
                'done'    => (bool)$zeile['done'],
            ];
        }
        return $raus;
    }

    /**
     * Termine der Plattform als Vorschlag — OHNE KI-Aufruf.
     *
     * Ein Termin aus dem Kalender ist schon strukturiert: Name, Zeitpunkt,
     * Kurs. Ihn durch die Auswertung zu schicken kostete Geld und koennte ihn
     * nur schlechter machen. Er wird deshalb direkt zu einem Vorschlag —
     * angelegt wird er erst, wenn jemand ihn uebernimmt („nichts entsteht
     * ungefragt" gilt auch hier).
     *
     * Diese Haelfte LIEST nur — zwei Abrufe an die Schule. Abgelegt wird in
     * `MoodleTermineEinpflegen`, dort steht der Vorschlagsbestand.
     *
     * @param array<int,array<string,mixed>> $kurse Kurskennung → Seite
     * @return list<array<string,mixed>> Vorschlaege, jeder mit seinem Merker
     */
    private function MoodleTermineZeilen(array $zugang, array $kurse): array
    {
        $jetzt = time();
        /* Weg 1: die Zeitleiste. Sie nennt FRISTEN von Aktivitaeten — Aufgaben,
           Abstimmungen — und nur die des eigenen Kontos. */
        $a = $this->MoodleRest($zugang, 'core_calendar_get_action_events_by_timesort',
            ['timesortfrom' => $jetzt, 'limitnum' => 20]);
        /* Weg 2: der Kalender. Hier liegen Kurs-, Nutzer- und SEITENtermine —
           Schliesstage, Ferien, Elternabende. Zwei Wege, weil die Zeitleiste
           davon nichts weiss.
           An der geprueften Grundschule sind beide leer (gemessen ueber ein
           Jahr in beide Richtungen: 0 Eintraege). Der Weg ist trotzdem da: er
           kostet einen Aufruf, und eine Schule, die ihren Kalender pflegt,
           braucht keine Codeaenderung dafuer. */
        $kalender = $this->MoodleRest($zugang, 'core_calendar_get_calendar_events', [
            'events'  => ['courseids' => array_values(array_map('intval', array_keys($kurse)))],
            'options' => [
                'userevents' => 1,
                'siteevents' => 1,
                'timestart'  => $jetzt,
                'timeend'    => $jetzt + self::MOODLE_TAGE_VOR * 86400,
            ],
        ]);
        if (!is_array($a) && !is_array($kalender)) {
            return [];
        }
        $liste = MoodleCalc::TermineVereinen(
            is_array($a) ? (array)($a['events'] ?? []) : [],
            is_array($kalender) ? (array)($kalender['events'] ?? []) : []);
        $topf = 'moodleevents:' . mb_strtolower((string)$zugang['site']);
        $raus = [];
        foreach ($liste as $e) {
            if (!is_array($e)) {
                continue;
            }
            $ts = (int)($e['timesort'] ?? ($e['timestart'] ?? 0));
            $titel = trim((string)($e['name'] ?? ''));
            if ($ts <= 0 || $titel === '') {
                continue;
            }
            $schluessel = 'ev:' . (int)($e['id'] ?? 0) . ':' . $ts;
            $kursName = trim((string)((($e['course']['shortname']) ?? ($e['course']['fullname'] ?? ''))));
            $raus[] = ['topf' => $topf, 'schluessel' => $schluessel, 'satz' => [
                'id'        => 'moodleevent:' . (int)($e['id'] ?? 0) . ':' . $ts,
                'at'        => $ts,
                'created'   => time(),
                'from'      => '',
                'fromName'  => $kursName !== '' ? $kursName : $this->Translate('LOGINEO'),
                'subject'   => $titel,
                'recipient' => '',
                'userId'    => (string)$zugang['userId'],
                'origin'    => null,
                'items'     => [[
                    'title'      => mb_substr($titel, 0, 120),
                    'info'       => $kursName,
                    'due'        => date('Y-m-d', $ts),
                    /* Mitternacht heisst „ganztaegig": Moodle setzt fuer einen
                       Tagestermin 00:00, und eine Uhrzeit „0:00" im Kalender
                       waere eine Behauptung. */
                    'time'       => date('H:i', $ts) === '00:00' ? null : date('H:i', $ts),
                    'priority'   => 'normal',
                    'kind'       => 'event',
                    'end'        => null,
                    'allDay'     => date('H:i', $ts) === '00:00',
                    'recurrence' => null,
                    'assignedTo' => [(string)$zugang['userId']],
                    'taken'      => false,
                ]],
            ]];
        }
        return $raus;
    }

}

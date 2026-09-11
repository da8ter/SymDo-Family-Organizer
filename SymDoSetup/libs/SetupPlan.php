<?php

declare(strict_types=1);

/**
 * Der Installationsassistent — das Rechenwerk, ohne Symcon.
 *
 * Keine Symcon-Aufrufe, kein Zufall, keine Uhr: alles kommt als Parameter
 * herein. Damit läuft es im Prüfstand (SymDoSetup/tests/SetupPlanTest.php).
 * Vorbild: SymDoGateway/libs/MoodleCalc.php und HomeworkCalc.php.
 *
 * Hier entsteht der PLAN, nicht die Wirkung: eine Liste von Absichten, die der
 * Ausführer der Reihe nach abarbeitet. Der Grund für diese Trennung ist der
 * Auftrag selbst — der Assistent setzt alles in EINEM Zug um, unbeobachtet und
 * genau einmal. Was man daran nicht offline prüfen kann, prüft niemand.
 */
class SetupPlan
{
    /** Das Gateway. Es ist die Voraussetzung für alles andere. */
    public const GATEWAY_GUID = '{E677FE7B-28C9-4124-8B58-8A1FE2657E8D}';

    /**
     * Die Bausteine — Schlüssel, Modul-GUID, Vorgabename und Querverweise.
     *
     * DIE REIHENFOLGE DIESES FELDES IST DIE REIHENFOLGE DES LAUFS, und sie ist
     * nicht Geschmack: `meal` braucht `shopping`, `chores` braucht `routines`,
     * `voice` braucht beide Listen, und `webapp` steht zuletzt, damit ihr
     * erster Lauf alles schon sieht.
     *
     * Bei Listen darf es MEHRERE geben (ein Satz je Konto). Für den Assistenten
     * genügt trotzdem eine: er legt nur an, was FEHLT. Eine Erkennung am Namen
     * wäre hier falsch — sie hat im Prüflauf eine zweite Einkaufsliste neben
     * die vorhandenen gesetzt, nur weil die des Nutzers anders heisst.
     *
     * `nutzer` nennt die Property, in die die Kennung des ersten Kindes (sonst
     * des ersten Mitglieds) gehört. `verweise` bildet Property → Baustein ab.
     */
    public const BAUSTEINE = [
        'shopping' => [
            'guid'     => '{A5D3F2E1-7B4C-4E8A-9D6F-1C2B3A4E5F6D}',
            'name'     => 'SymDo - Einkaufsliste',
        ],
        'todo' => [
            'guid'     => '{E0E38D9B-31BC-4F5E-A6CA-91A2A60C7C46}',
            'name'     => 'SymDo - ToDo Liste',
        ],
        'routines' => [
            'guid' => '{B1DF065E-80F5-49DF-B2B8-3CE657ED23BB}',
            'name' => 'SymDo - Routinen',
        ],
        'meal' => [
            'guid'     => '{94E5ED88-1017-4A73-B9BF-2B2010679022}',
            'name'     => 'SymDo - Essensplan',
            'verweise' => ['ShoppingListInstanceID' => 'shopping'],
        ],
        'chores' => [
            'guid'     => '{EE6DEDE0-C67E-42A7-A797-3B155611B8DB}',
            'name'     => 'SymDo - Ämtchenplan',
            'verweise' => ['RoutinesInstanceID' => 'routines'],
        ],
        'timetable' => [
            'guid' => '{C22E0A96-1BC7-4029-B8C5-7E94E4F2A9D9}',
            'name' => 'SymDo - Stundenplan',
        ],
        'notes' => [
            'guid'   => '{061491BA-F95D-425A-95FA-C3D0D1CFFB7B}',
            'name'   => 'SymDo - Notizen',
            'nutzer' => 'DefaultUserID',
        ],
        'homework' => [
            'guid' => '{44D18479-4BC8-4468-8F3B-08515D322318}',
            'name' => 'SymDo - Hausaufgaben',
        ],
        'edumaps' => [
            'guid' => '{60BD47B7-215A-4198-8CA9-417B549E3969}',
            'name' => 'SymDo - Klassenseiten',
        ],
        'voice' => [
            'guid'     => '{1F413A34-452C-4A8D-BEFC-CA7CB9DBB1BB}',
            'name'     => 'SymDo - Sprachassistent',
            'nutzer'   => 'UserID',
            'verweise' => [
                'DefaultTodoID'     => 'todo',
                'DefaultShoppingID' => 'shopping',
            ],
        ],
        'webapp' => [
            'guid'   => '{6703A24A-E9E9-44D3-AB21-27176BF224AA}',
            'name'   => 'SymDo - Web App',
            'nutzer' => 'DefaultUserID',
        ],
    ];

    /**
     * Was ein Baustein zum Arbeiten braucht.
     *
     * Keine Geschmacksfragen, sondern harte Abhängigkeiten im Bestand:
     *  - Hausaufgaben holen Fach und Farbe aus dem Stundenplan, und der Abruf
     *    aus WebUntis legt seine Stunden in eine Stundenplan-Instanz.
     *  - Essensplan und Ämtchenplan tragen die Kennung ihrer Quelle als
     *    Eigenschaft (Einkaufsliste bzw. Routinen).
     *  - Der Sprachassistent braucht die beiden Listen als Vorgabe.
     *  - Klassenseiten zeigen, was die Schulanbindung spiegelt.
     *
     * Wer einen Baustein wählt, bekommt seine Voraussetzungen automatisch mit.
     */
    public const ABHAENGIG = [
        'homework' => ['timetable'],
        'meal'     => ['shopping'],
        'chores'   => ['routines'],
        'voice'    => ['todo', 'shopping'],
    ];

    /** Was eine Schulanbindung zusätzlich braucht. */
    public const SCHULE_BRAUCHT = [
        'untis'  => ['timetable', 'homework'],
        'moodle' => ['edumaps', 'homework'],
    ];

    /**
     * Die Wahl um ihre Voraussetzungen ergänzen — so oft, bis nichts mehr
     * dazukommt (eine Voraussetzung kann selbst eine haben).
     *
     * @param array<string,bool> $gewaehlt
     * @return array{bausteine:array<string,bool>,dazu:list<string>}
     */
    public static function AbhaengigkeitenSchliessen(array $gewaehlt, string $schule = 'none'): array
    {
        $an = [];
        foreach ($gewaehlt as $key => $wert) {
            if ($wert === true && isset(self::BAUSTEINE[$key])) {
                $an[$key] = true;
            }
        }
        foreach ((array)(self::SCHULE_BRAUCHT[$schule] ?? []) as $key) {
            if (isset(self::BAUSTEINE[$key])) {
                $an[$key] = true;
            }
        }
        $dazu = [];
        for ($runde = 0; $runde < 10; $runde++) {
            $neu = false;
            foreach (array_keys($an) as $key) {
                foreach ((array)(self::ABHAENGIG[$key] ?? []) as $braucht) {
                    if (!isset($an[$braucht]) && isset(self::BAUSTEINE[$braucht])) {
                        $an[$braucht] = true;
                        $dazu[] = $braucht;
                        $neu = true;
                    }
                }
            }
            if (!$neu) {
                break;
            }
        }
        // In der Reihenfolge der Tabelle, damit der Plan planbar bleibt.
        $sortiert = [];
        foreach (array_keys(self::BAUSTEINE) as $key) {
            if (isset($an[$key])) {
                $sortiert[$key] = true;
            }
        }
        return ['bausteine' => $sortiert, 'dazu' => array_values(array_unique($dazu))];
    }

    /** Die Rollen aus der Mitgliederliste des Gateways (Spalte `persona`). */
    public const ROLLEN = ['', 'father', 'mother', 'child', 'grandmother', 'grandfather', 'uncle', 'aunt'];

    // ─────────────────────────────── Abbruchgründe ───────────────────────────
    /** Der Kernel ist nicht bereit — vor KR_READY schreibt hier niemand. */
    public const ABBRUCH_KERNEL = 'kernel';
    /**
     * Zwei Gateways. Der Assistent würde ins eine schreiben, während die
     * Kacheln aus dem anderen lesen: die App-Hoheit hängt an der NIEDRIGSTEN
     * Instanz-ID, und Symcon vergibt IDs zufällig (gemessen: 1477 Objekte
     * gleichmässig über 10.000-59.986). Das ist kein Komfortproblem.
     */
    public const ABBRUCH_ZWEI_GATEWAYS = 'zwei_gateways';
    /** Zwei Web-App-Instanzen: `GetWebAppTabs()` nimmt `$ids[0]` UNSORTIERT. */
    public const ABBRUCH_ZWEI_WEBAPPS = 'zwei_webapps';
    /** Ohne ein Mitglied mit Namen ist in SymDo fast nichts sinnvoll. */
    public const ABBRUCH_KEIN_MITGLIED = 'kein_mitglied';
    /** KI gewählt, aber kein Schlüssel — das wäre ein Schalter ohne Wirkung. */
    public const ABBRUCH_KI_OHNE_SCHLUESSEL = 'ki_ohne_schluessel';

    // ─────────────────────────────── Mitglieder ──────────────────────────────

    /**
     * Der Schlüssel, unter dem zwei Zeilen dasselbe Mitglied sind.
     *
     * Kleingeschrieben und getrimmt — GENAU der Schlüssel, den das Gateway in
     * seinem Rettungsnetz `UserIDShadow` benutzt (AppCore.php:225). Damit
     * trifft der Assistent dieselbe Identität wie das Gateway selbst; eine
     * eigene Regel hier liefe irgendwann auseinander.
     */
    public static function Schluessel(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    /**
     * Die Mitgliederliste zusammenführen.
     *
     * Drei Regeln, und sie sind alle defensiv:
     *  - Eine vorhandene Zeile GEWINNT. Ergänzt werden nur LEERE Felder —
     *    Nachname, Geburtstag, Rolle, Foto und Push-Visualisierung.
     *  - Die `id` wird NIE angefasst: an ihr hängt jede Zuordnung (Aufgaben,
     *    Termine, Notizordner, Push).
     *  - Gelöscht wird nie. Es gibt im Gateway auch keine Funktion dafür.
     *
     * Die Kennungen für NEUE Zeilen kommen als Parameter herein (der Aufrufer
     * würfelt sie). Zweierlei Grund: der Prüfstand braucht feste Werte, und der
     * Assistent braucht die Kennung SOFORT — er setzt sie im selben Zug als
     * `DefaultUserID` in die Web-App und als `UserID` in den Sprachassistenten.
     * `EnsureUserIDs()` im Gateway würde sie auch vergeben, aber erst über
     * einen Einmal-Timer, also zu spät.
     *
     * @param list<array<string,mixed>> $vorhanden die Zeilen der Property `Users`
     * @param list<array<string,mixed>> $neue      die Zeilen aus dem Assistenten
     * @param list<string>              $kennungen so viele, wie es neue Zeilen gibt
     * @return array{users:list<array<string,mixed>>,neu:int,ergaenzt:int,uebergangen:int}
     */
    public static function MitgliederZusammenfuehren(array $vorhanden, array $neue, array $kennungen): array
    {
        $raus = [];
        $stelle = [];
        foreach ($vorhanden as $z) {
            if (!is_array($z)) {
                continue;
            }
            $raus[] = $z;
            $s = self::Schluessel((string)($z['name'] ?? ''));
            if ($s !== '') {
                // Der ERSTE Treffer gilt: eine doppelte Zeile im Bestand ist
                // nicht unser Problem, und wir machen sie nicht schlimmer.
                $stelle[$s] ??= count($raus) - 1;
            }
        }

        $neuZahl = 0;
        $ergaenzt = 0;
        $uebergangen = 0;
        $naechste = 0;
        foreach ($neue as $z) {
            if (!is_array($z)) {
                $uebergangen++;
                continue;
            }
            $name = trim((string)($z['name'] ?? ''));
            if ($name === '') {
                // Eine Zeile ohne Vornamen ist keine Person. Das Gateway wirft
                // sie in LoadUsers() ohnehin weg.
                $uebergangen++;
                continue;
            }
            $s = self::Schluessel($name);
            if (isset($stelle[$s])) {
                $i = $stelle[$s];
                $vorher = $raus[$i];
                foreach (['lastName', 'birthday', 'persona', 'photo', 'visu'] as $feld) {
                    if (self::Leer($raus[$i][$feld] ?? null) && !self::Leer($z[$feld] ?? null)) {
                        $raus[$i][$feld] = self::Feld($feld, $z[$feld]);
                    }
                }
                if ($raus[$i] !== $vorher) {
                    $ergaenzt++;
                }
                continue;
            }
            $kennung = (string)($kennungen[$naechste] ?? '');
            if ($kennung === '') {
                // Ohne Kennung keine Zeile: das Gateway filtert sie sonst
                // stillschweigend aus LoadUsers() heraus, und niemand sieht es.
                $uebergangen++;
                continue;
            }
            $naechste++;
            /* Dieselben sieben Spalten wie die Mitgliederliste des Gateways —
               `id` zuletzt und unsichtbar, genau wie dort. */
            $raus[] = [
                'name'      => $name,
                'lastName'  => trim((string)($z['lastName'] ?? '')),
                // SelectDate liefert ein OBJEKT. Ein String hier zerlegt den
                // Listeneditor der Konsole.
                'birthday'  => self::Geburtstag($z['birthday'] ?? null),
                'persona'   => self::Rolle((string)($z['persona'] ?? '')),
                'photo'     => max(0, (int)($z['photo'] ?? 0)),
                'visu'      => max(0, (int)($z['visu'] ?? 0)),
                'id'        => $kennung,
            ];
            $stelle[$s] = count($raus) - 1;
            $neuZahl++;
        }

        return ['users' => $raus, 'neu' => $neuZahl, 'ergaenzt' => $ergaenzt, 'uebergangen' => $uebergangen];
    }

    /** Ein Feld in der Form, die die Mitgliederliste des Gateways erwartet. */
    private static function Feld(string $name, mixed $wert): mixed
    {
        return match ($name) {
            'birthday' => self::Geburtstag($wert),
            'persona'  => self::Rolle((string)$wert),
            'photo', 'visu' => max(0, (int)$wert),
            default    => trim((string)$wert),
        };
    }

    /** Leer heisst: nicht gesetzt, leerer Text, eine Null — oder ein Datum aus Nullen. */
    private static function Leer(mixed $wert): bool
    {
        if ($wert === null) {
            return true;
        }
        if (is_string($wert)) {
            return trim($wert) === '';
        }
        if (is_array($wert)) {
            return ((int)($wert['year'] ?? 0)) === 0
                && ((int)($wert['month'] ?? 0)) === 0
                && ((int)($wert['day'] ?? 0)) === 0;
        }
        if (is_int($wert)) {
            // Foto und Push-Visualisierung sind Objekt-Kennungen: 0 = keine.
            return $wert <= 0;
        }
        return false;
    }

    /** Ein Geburtstag in der Form, die `SelectDate` erwartet. */
    public static function Geburtstag(mixed $roh): array
    {
        $leer = ['year' => 0, 'month' => 0, 'day' => 0];
        if (!is_array($roh)) {
            return $leer;
        }
        return [
            'year'  => max(0, (int)($roh['year'] ?? 0)),
            'month' => max(0, min(12, (int)($roh['month'] ?? 0))),
            'day'   => max(0, min(31, (int)($roh['day'] ?? 0))),
        ];
    }

    /** Eine Rolle, die es gibt. Alles andere wird zu „keine Angabe". */
    public static function Rolle(string $roh): string
    {
        $r = trim($roh);
        return in_array($r, self::ROLLEN, true) ? $r : '';
    }

    /**
     * Die Kennung, die in eine `nutzer`-Property gehört: das erste KIND, sonst
     * das erste Mitglied. Begründung: die Kacheln, die eine Vorgabe brauchen
     * (Web-App, Notizen, Sprache), sind im Zweifel für ein Kind gedacht — und
     * ohne Kind gibt es in der Web-App gar keinen Kindmodus.
     */
    public static function VorgabeKennung(array $users): string
    {
        $erste = '';
        foreach ($users as $u) {
            if (!is_array($u)) {
                continue;
            }
            $id = trim((string)($u['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            if ($erste === '') {
                $erste = $id;
            }
            if ((string)($u['persona'] ?? '') === 'child') {
                return $id;
            }
        }
        return $erste;
    }

    // ─────────────────────────────── Der Plan ────────────────────────────────

    /**
     * Der Plan eines Laufs: was zu tun ist, in welcher Reihenfolge — und ob
     * überhaupt.
     *
     * `$bestand` ist die MESSUNG des Systems (der Ausführer erhebt sie):
     *   [
     *     'runlevel'  => int,                      // KR_READY = 10103
     *     'gateways'  => list<int>,                // Instanzen der Gateway-GUID
     *     'instanzen' => array<string,list<int>>,  // Baustein => Instanzen
     *     'module'    => array<string,bool>,       // Baustein => Modul installiert?
     *     'users'     => list<array>,              // Property `Users` des Gateways
     *   ]
     *
     * `$antworten` ist, was der Assistent gesammelt hat:
     *   [
     *     'familyName' => string,
     *     'members'    => list<array>,
     *     'bausteine'  => array<string,bool>,
     *     'access'     => bool,
     *     'school'     => 'none'|'untis'|'moodle',
     *     'ai'         => ['provider' => string, 'hasKey' => bool],
     *   ]
     *
     * @param list<string> $kennungen Vorrat für neue Mitglieder
     * @return array{abbruch:string,schritte:list<array<string,mixed>>}
     */
    public static function Absichten(array $antworten, array $bestand, array $kennungen = []): array
    {
        $abbruch = self::Vorpruefen($antworten, $bestand);
        if ($abbruch !== '') {
            return ['abbruch' => $abbruch, 'schritte' => []];
        }

        $schritte = [];

        // ── Das Gateway: übernehmen oder anlegen, aber niemals ein zweites ──
        $gateways = self::Zahlen($bestand['gateways'] ?? []);
        if ($gateways === []) {
            $schritte[] = ['art' => 'gateway', 'aktion' => 'anlegen',
                           'guid' => self::GATEWAY_GUID, 'name' => 'SymDo Gateway'];
        } else {
            $schritte[] = ['art' => 'gateway', 'aktion' => 'vorhanden', 'id' => min($gateways)];
        }

        // ── Die Mitglieder, VOR dem Übernehmen des Gateways ─────────────────
        $zusammen = self::MitgliederZusammenfuehren(
            self::Liste($bestand['users'] ?? []),
            self::Liste($antworten['members'] ?? []),
            $kennungen
        );
        $schritte[] = [
            'art'         => 'mitglieder',
            'aktion'      => ($zusammen['neu'] + $zusammen['ergaenzt']) > 0 ? 'schreiben' : 'unveraendert',
            'users'       => $zusammen['users'],
            'neu'         => $zusammen['neu'],
            'ergaenzt'    => $zusammen['ergaenzt'],
            'uebergangen' => $zusammen['uebergangen'],
        ];

        // ── Die Stammdaten des Gateways (Familienname, KI, Schule) ──────────
        $schritte[] = ['art' => 'gateway-daten', 'aktion' => 'schreiben',
                       'familyName' => trim((string)($antworten['familyName'] ?? '')),
                       'ai' => self::KiFelder($antworten),
                       'school' => self::Schulwahl($antworten)];

        // ── Die Kacheln, in der Reihenfolge der Tabelle ─────────────────────
        $vorgabe = self::VorgabeKennung($zusammen['users']);
        $wahl = self::AbhaengigkeitenSchliessen(
            (array)($antworten['bausteine'] ?? []), self::Schulwahl($antworten));
        foreach ($wahl['bausteine'] as $key => $an) {
            $schritte[] = self::BausteinSchritt($key, (array)self::BAUSTEINE[$key], $bestand, $vorgabe);
        }

        // ── Die Schulanbindung ──────────────────────────────────────────────
        $schule = self::Schulwahl($antworten);
        if ($schule !== 'none') {
            $schritte[] = [
                'art'    => 'schule',
                'aktion' => 'einrichten',
                'system' => $schule,
                'kind'   => trim((string)($antworten['schoolChild'] ?? '')),
            ];
        }

        // ── Die KI-Einwilligung, wenn sie auf der Seite erteilt wurde ───────
        if (($antworten['aiConsent'] ?? false) === true) {
            $schritte[] = ['art' => 'einwilligung', 'aktion' => 'erteilen'];
        }

        // ── Der Zugang: höchstens EINER, und zuletzt ────────────────────────
        if (($antworten['access'] ?? false) === true) {
            /* Zuletzt, weil der Code 600 Sekunden lebt — und weil jeder Aufruf
               die EINE ausstehende Kopplung ersetzt, womöglich eine, die gerade
               jemand in der Familie benutzt. */
            $schritte[] = ['art' => 'zugang', 'aktion' => 'erzeugen'];
        }

        return ['abbruch' => '', 'schritte' => $schritte];
    }

    /** Ein Baustein: vorhanden, anlegen — oder gar nicht möglich. */
    private static function BausteinSchritt(string $key, array $baustein, array $bestand, string $vorgabe): array
    {
        $grund = ['art' => 'baustein', 'key' => $key, 'guid' => (string)$baustein['guid'],
                  'name' => (string)$baustein['name']];

        if ((((array)($bestand['module'] ?? []))[$key] ?? true) !== true) {
            // Das Modul steckt nicht in dieser Installation. Versuchen wäre ein
            // sicherer Fehlschlag; der Bericht sagt es lieber vorher.
            return $grund + ['aktion' => 'uebersprungen', 'weil' => 'modul_fehlt'];
        }

        /* Gibt es IRGENDEINE Instanz dieses Moduls, gilt der Baustein als
           vorhanden — auch wenn sie anders heisst. „Angelegt wird nur, was
           fehlt" heisst genau das; alles andere erzeugt Doppel in einem
           Haushalt, der seine Listen längst benannt hat. */
        $da = self::Zahlen(((array)($bestand['instanzen'] ?? []))[$key] ?? []);
        if ($da !== []) {
            return $grund + ['aktion' => 'vorhanden', 'id' => min($da)];
        }

        return $grund + [
            'aktion'   => 'anlegen',
            // Der Ausführer löst die Ziele erst auf, wenn sie entstanden sind.
            'verweise' => (array)($baustein['verweise'] ?? []),
            'nutzer'   => isset($baustein['nutzer'])
                ? [(string)$baustein['nutzer'] => $vorgabe] : [],
        ];
    }

    /**
     * Die harten Abbruchgründe. Sie werden VOR dem ersten Schreibvorgang
     * geprüft — das ist die einzige Stelle, an der Aufhören nichts hinterlässt.
     */
    public static function Vorpruefen(array $antworten, array $bestand): string
    {
        if ((int)($bestand['runlevel'] ?? 0) !== 10103) {
            return self::ABBRUCH_KERNEL;
        }
        if (count(self::Zahlen($bestand['gateways'] ?? [])) > 1) {
            return self::ABBRUCH_ZWEI_GATEWAYS;
        }
        if (count(self::Zahlen(((array)($bestand['instanzen'] ?? []))['webapp'] ?? [])) > 1) {
            return self::ABBRUCH_ZWEI_WEBAPPS;
        }
        $mitNamen = 0;
        foreach (self::Liste($antworten['members'] ?? []) as $m) {
            if (is_array($m) && trim((string)($m['name'] ?? '')) !== '') {
                $mitNamen++;
            }
        }
        foreach (self::Liste($bestand['users'] ?? []) as $u) {
            if (is_array($u) && trim((string)($u['name'] ?? '')) !== '') {
                $mitNamen++;
            }
        }
        if ($mitNamen === 0) {
            return self::ABBRUCH_KEIN_MITGLIED;
        }
        $ki = (array)($antworten['ai'] ?? []);
        if (trim((string)($ki['provider'] ?? '')) !== '' && ($ki['hasKey'] ?? false) !== true) {
            return self::ABBRUCH_KI_OHNE_SCHLUESSEL;
        }
        return '';
    }

    /**
     * Die KI-Felder des Gateways.
     *
     * `AiEnabled` bleibt AUS: ohne Einwilligung sperrt das Formular den
     * Schalter ohnehin (AppCore.php:561), und ein eingeschalteter, aber
     * gesperrter Schalter ist eine Falle — der Nutzer sucht den Grund. Die
     * Einwilligung erteilt der Mensch dort, wo der Text steht.
     */
    private static function KiFelder(array $antworten): array
    {
        $ki = (array)($antworten['ai'] ?? []);
        $anbieter = trim((string)($ki['provider'] ?? ''));
        /* Der Schalter geht an, sobald Anbieter, Schlüssel UND Einwilligung
           da sind — vorher sperrt ihn das Gateway ohnehin. */
        $an = $anbieter !== '' && ($ki['hasKey'] ?? false) === true
            && ($antworten['aiConsent'] ?? false) === true;
        return ['provider' => $anbieter, 'enabled' => $an];
    }

    /** Die Schulwahl, auf die drei zulässigen Werte reduziert. */
    private static function Schulwahl(array $antworten): string
    {
        $s = trim((string)($antworten['school'] ?? 'none'));
        return in_array($s, ['none', 'untis', 'moodle'], true) ? $s : 'none';
    }

    /** @return list<int> nur echte Instanz-Kennungen, doppelte fallen weg */
    private static function Zahlen(mixed $roh): array
    {
        $raus = [];
        foreach (is_array($roh) ? $roh : [] as $w) {
            $i = (int)$w;
            if ($i > 0 && !in_array($i, $raus, true)) {
                $raus[] = $i;
            }
        }
        return $raus;
    }

    /** @return list<mixed> */
    private static function Liste(mixed $roh): array
    {
        return is_array($roh) ? array_values($roh) : [];
    }
}

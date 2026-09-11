<?php

declare(strict_types=1);

require_once __DIR__ . '/libs/SetupPlan.php';

/**
 * SymDo — Installationsassistent.
 *
 * Ein Discovery-Modul (type 5): es ist kein Gerät, es richtet ein. Der
 * Assistent fragt in Seiten (`popup.pages`, Symcon 9.1), sammelt die Antworten
 * in PUFFERN und setzt am Ende alles in EINEM Zug um.
 *
 * Zwei Entscheidungen tragen den ganzen Aufbau:
 *
 * 1. Der AUSFÜHRER hängt an keiner Stelle an `pages`. Er nimmt ein
 *    Antwort-JSON und arbeitet es ab (`Apply()`, öffentlich als SDSU_Apply).
 *    Damit ist der Zug per Skript prüfbar — und das muss er sein: er läuft
 *    genau einmal, unbeobachtet, in einem eingerichteten Haushalt.
 * 2. Der ZUSTAND liegt in Puffern (SetBuffer), nicht in Attributen. Ein Puffer
 *    braucht keine Registrierung und übersteht keinen Kernel-Neustart — beides
 *    ist für einen Assistenten richtig. Nur der letzte BERICHT gehört in ein
 *    Attribut, damit er den Neustart überlebt.
 *
 * Die Entscheidungslogik selbst steht in libs/SetupPlan.php und ist dort ohne
 * Symcon geprüft (tests/SetupPlanTest.php).
 */
class SymDoSetup extends IPSModuleStrict
{
    /** Ab dieser Symcon-Version gibt es `popup.pages`. */
    private const PAGES_AB = '9.1';

    /** Puffer: die gesammelten Antworten (JSON). */
    private const P_ANTWORTEN = 'Answers';
    /** Attribut: der Bericht des letzten Laufs (JSON). */
    private const A_BERICHT = 'SetupReport';
    /** Attribut: was dieser Assistent selbst angelegt hat (JSON-Liste von IDs). */
    private const A_ANGELEGT = 'CreatedObjects';

    /** KR_READY. Als Zahl, damit SetupPlan ohne Symcon-Konstanten rechnen kann. */
    private const BEREIT = 10103;

    public function Create(): void
    {
        parent::Create();
        /* Der Bericht überlebt den Kernel-Neustart, der Zwischenstand nicht.
           Genau so soll es sein: ein halb ausgefüllter Assistent nach einem
           Neustart wäre eine Falle, ein verschwundener Bericht ein Ärgernis. */
        $this->RegisterAttributeString(self::A_BERICHT, '');
        $this->RegisterAttributeString(self::A_ANGELEGT, '[]');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
    }

    // ─────────────────────────────── Formular ────────────────────────────────

    /**
     * Das Formular entsteht in PHP, nicht in einer form.json — aus EINER
     * Seitendefinition werden zwei Darstellungen:
     *
     *  - ab Symcon 9.1 die echten `popup.pages` mit Weiter/Zurück,
     *  - darunter dieselben Felder als Aufklapp-Panels in `popup.items`.
     *
     * Der Rückfall ist kein Alibi: die Bibliothek bedient Symcon ab 8.1, und
     * ein Popup, das dort leer aufgeht, wäre der schlechteste aller Zustände.
     *
     * Das ganze Zusammenbauen liegt in einem try/catch — dieselbe Begründung
     * wie im Gateway (module.php:318): direkt nach einem Modul-Update liefert
     * Translate() `false`, und ein durchgeschlagener Fehler zeigt dem Nutzer
     * eine rohe PHP-Seite statt eines Formulars.
     */
    public function GetConfigurationForm(): string
    {
        try {
            $seiten = $this->Seiten();
            $popup = [
                'caption'      => $this->Translate('Set up SymDo'),
                'closeCaption' => $this->Translate('Cancel'),
            ];
            if ($this->KannSeiten()) {
                $popup['pages'] = $seiten;
                $popup['items'] = [[
                    'type'    => 'Label',
                    'caption' => $this->Translate('The guided pages are shown instead of this content.'),
                ]];
            } else {
                $popup['items'] = $this->RueckfallItems($seiten);
            }

            return json_encode([
                'elements' => [],
                'actions'  => array_merge($this->Einleitung(), [
                    [
                        'type'    => 'PopupButton',
                        'name'    => 'SetupWizard',
                        'caption' => $this->Translate('Start the SymDo setup assistant'),
                        'popup'   => $popup,
                    ],
                    [
                        'type'    => 'Label',
                        'name'    => 'ReportLabel',
                        'caption' => $this->BerichtText(),
                    ],
                    [
                        'type'    => 'Button',
                        'name'    => 'ReportRefresh',
                        'caption' => $this->Translate('Refresh status'),
                        'onClick' => 'IPS_RequestAction($id, "ReportRefresh", "");',
                    ],
                ]),
                'status'   => [],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            return json_encode([
                'elements' => [],
                'actions'  => [['type' => 'Label', 'caption' => 'Setup assistant: ' . $e->getMessage()]],
                'status'   => [],
            ]);
        }
    }

    /** Kann diese Symcon-Version mehrseitige Popups? */
    private function KannSeiten(): bool
    {
        return version_compare($this->KernelVersion(), self::PAGES_AB, '>=');
    }

    private function KernelVersion(): string
    {
        try {
            return (string)@IPS_GetKernelVersion();
        } catch (\Throwable $e) {
            return '0';
        }
    }

    /** Was der Assistent tut — vor dem Knopf, damit niemand blind klickt. */
    private function Einleitung(): array
    {
        $zeilen = [
            $this->Translate('SymDo brings the whole family together: tasks, shopping, appointments, timetable, homework, notes — and a smart helper that turns letters from school into appointments.'),
            $this->Translate('The assistant asks a handful of questions and sets everything up for you.'),
            $this->Translate('You can start it again whenever you like. Nothing is created twice.'),
        ];
        if (!$this->KannSeiten()) {
            array_unshift($zeilen, $this->Translate('The guided pages with Back and Next need Symcon 9.1 — here the same steps are listed one below the other.'));
        }
        return array_map(static fn(string $t): array => ['type' => 'Label', 'caption' => $t], $zeilen);
    }

    /**
     * Die Seiten des Assistenten. EINE Definition, zwei Darstellungen.
     *
     * Die Mitglieder werden EINZELN gesammelt (Felder plus „Übernehmen"-Knopf)
     * und nicht in einer Liste: ein Knopf, der Feldwerte als `$Feld` an
     * RequestAction gibt, ist bewiesenes Gelände — beim Wert einer Liste mit
     * `add: true` in einer Popup-Seite ist es das nicht. Der Assistent zeigt
     * stattdessen nach jedem Übernehmen, wer bisher drinsteht.
     */
    private function Seiten(): array
    {
        $ich = '$id';
        return [
            [
                'name'    => 'welcome',
                'caption' => $this->Translate('Welcome to SymDo'),
                'items'   => [
                    ['type' => 'Label', 'caption' => $this->Translate('The family centre for Symcon: tasks, shopping, appointments, timetable, homework and notes — on every phone, tablet and screen in the house.')],
                    ['type' => 'Label', 'caption' => $this->Translate('A few minutes and you are set up. Let us go.')],
                    ['type' => 'Label', 'name' => 'WelcomeState', 'caption' => $this->BestandText()],
                ],
                'nextPage' => 'family',
            ],
            [
                'name'    => 'family',
                'caption' => $this->Translate('Your family'),
                'items'   => [
                    ['type' => 'Label', 'caption' => $this->Translate('Everyone gets their own face, their own tasks and their own view. Add one member at a time.')],
                    ['type' => 'Label', 'name' => 'MemberExisting', 'caption' => $this->VorhandeneMitgliederText()],
                    ['type' => 'ValidationTextBox', 'name' => 'MemberName', 'caption' => $this->Translate('First name')],
                    ['type' => 'ValidationTextBox', 'name' => 'MemberLastName', 'caption' => $this->Translate('Last name')],
                    ['type' => 'SelectDate', 'name' => 'MemberBirthday', 'caption' => $this->Translate('Date of birth')],
                    ['type' => 'Select', 'name' => 'MemberPersona', 'caption' => $this->Translate('Persona'),
                     'options' => $this->RollenOptionen()],
                    ['type' => 'SelectMedia', 'name' => 'MemberPhoto', 'caption' => $this->Translate('Photo')],
                    ['type' => 'SelectInstance', 'name' => 'MemberVisu', 'caption' => $this->Translate('Push visualization')],
                    ['type' => 'Label', 'caption' => $this->Translate('Photo and push visualization are optional — you can add them later at any time. The push visualization decides which visualization receives the push messages for this family member.')],
                    /* Alle sechs Felder der Mitgliederliste des Gateways. Der
                       Geburtstag ist ein SelectDate und kommt als PHP-Array im
                       Skript an — so indexiert OpenCalendar seine Listenzeile. */
                    ['type' => 'Button', 'name' => 'MemberAdd', 'caption' => $this->Translate('Add member'),
                     'onClick' => 'IPS_RequestAction(' . $ich . ', "MemberAdd", json_encode(['
                        . '"name" => $MemberName, "lastName" => $MemberLastName,'
                        . '"birthday" => $MemberBirthday, "persona" => $MemberPersona,'
                        . '"photo" => $MemberPhoto, "visu" => $MemberVisu]));'],
                    ['type' => 'Label', 'name' => 'MemberList', 'caption' => $this->MitgliederText()],
                    ['type' => 'Button', 'name' => 'MemberClear', 'caption' => $this->Translate('Start the list over'),
                     'onClick' => 'IPS_RequestAction(' . $ich . ', "MemberClear", "");'],
                ],
                'validate' => 'return SDSU_ValidateFamily(' . $ich . ');',
                'nextPage' => 'modules',
            ],
            [
                'name'    => 'modules',
                'caption' => $this->Translate('What would you like to use?'),
                'items'   => array_merge(
                    [['type' => 'Label', 'caption' => $this->Translate('Every building block is its own tile. Pick what fits — you can add more at any time.')]],
                    $this->BausteinKaestchen(),
                    [['type' => 'Label', 'name' => 'ModuleHint', 'caption' => $this->BausteinHinweis()]]
                ),
                'onConfirm' => 'IPS_RequestAction(' . $ich . ', "Modules", json_encode(' . $this->BausteinPayload() . '));',
                'nextPage'  => 'access',
            ],
            [
                'name'    => 'access',
                'caption' => $this->Translate('Your access'),
                'items'   => [
                    ['type' => 'Label', 'caption' => $this->Translate('The SymDo web app needs an access: it is what opens SymDo on a phone, a tablet or any browser. Without it the app cannot connect.')],
                    ['type' => 'Label', 'caption' => $this->Translate('The assistant creates the code at the very end and shows it in the report. It is valid for ten minutes — scan it and you are in.')],
                    ['type' => 'CheckBox', 'name' => 'WantAccess', 'caption' => $this->Translate('Create an access for the web app'), 'value' => true],
                ],
                'onConfirm' => 'IPS_RequestAction(' . $ich . ', "Access", json_encode(["want" => $WantAccess]));',
                'nextPage'  => 'school',
            ],
            [
                'name'    => 'school',
                'caption' => $this->Translate('School'),
                'items'   => [
                    ['type' => 'Label', 'caption' => $this->Translate('Timetable, substitutions and homework straight from school — every morning, without typing.')],
                    ['type' => 'Select', 'name' => 'School', 'caption' => $this->Translate('School system'),
                     'options' => [
                         ['value' => 'none', 'caption' => $this->Translate('Not now')],
                         ['value' => 'untis', 'caption' => 'WebUntis'],
                         ['value' => 'moodle', 'caption' => 'LOGINEO NRW LMS'],
                     ]],
                    ['type' => 'Select', 'name' => 'SchoolChild', 'caption' => $this->Translate('For which child?'),
                     'options' => $this->KinderOptionen()],
                    ['type' => 'ValidationTextBox', 'name' => 'SchoolServer', 'caption' => $this->Translate('Server or address'),
                     'validate' => '^$|^[A-Za-z0-9.\\-]+(/.*)?$'],
                    ['type' => 'ValidationTextBox', 'name' => 'SchoolName', 'caption' => $this->Translate('School name (WebUntis)')],
                    ['type' => 'ValidationTextBox', 'name' => 'SchoolUser', 'caption' => $this->Translate('User name')],
                    ['type' => 'PasswordTextBox', 'name' => 'SchoolPassword', 'caption' => $this->Translate('Password')],
                    ['type' => 'Label', 'caption' => $this->Translate('WebUntis: server such as herakles.webuntis.com and the school name. LOGINEO: the address of your school server.')],
                ],
                'validate'  => 'return SDSU_ValidateSchool(' . $ich . ', $School, $SchoolServer, $SchoolUser, $SchoolPassword);',
                'onConfirm' => 'IPS_RequestAction(' . $ich . ', "School", json_encode(["school" => $School,'
                    . ' "child" => $SchoolChild, "server" => $SchoolServer, "name" => $SchoolName,'
                    . ' "user" => $SchoolUser, "password" => $SchoolPassword]));',
                'nextPage'  => 'ai',
            ],
            [
                'name'    => 'ai',
                'caption' => $this->Translate('Smart helpers'),
                'items'   => [
                    ['type' => 'Label', 'caption' => $this->Translate('Photograph a letter from school, forward an e-mail, drop in a recipe link — SymDo turns it into appointments, tasks and notes. You confirm, SymDo files it.')],
                    ['type' => 'Select', 'name' => 'AiProvider', 'caption' => $this->Translate('AI provider'),
                     'options' => [
                         ['value' => '', 'caption' => $this->Translate('Not now')],
                         ['value' => 'anthropic', 'caption' => 'Anthropic (Claude)'],
                         ['value' => 'openai', 'caption' => 'OpenAI'],
                         ['value' => 'local', 'caption' => $this->Translate('My own server')],
                     ]],
                    ['type' => 'PasswordTextBox', 'name' => 'AiKey', 'caption' => $this->Translate('API key')],
                    ['type' => 'Label', 'caption' => $this->Translate('Privacy notice')],
                    ['type' => 'Label', 'name' => 'AiPrivacy', 'caption' => $this->DatenschutzText()],
                    ['type' => 'CheckBox', 'name' => 'AiConsent', 'caption' => $this->Translate('I have read this and agree')],
                ],
                'validate'  => 'return SDSU_ValidateAi(' . $ich . ', $AiProvider, $AiKey, $AiConsent);',
                'onConfirm' => 'IPS_RequestAction(' . $ich . ', "Ai", json_encode(["provider" => $AiProvider,'
                    . ' "key" => $AiKey, "consent" => $AiConsent]));',
                'nextPage'  => 'summary',
            ],
            [
                'name'    => 'summary',
                'caption' => $this->Translate('All set?'),
                'items'   => [
                    ['type' => 'Label', 'name' => 'SummaryText', 'caption' => $this->VorschauText()],
                    ['type' => 'Label', 'caption' => $this->Translate('Press Next — SymDo does the rest.')],
                ],
                'onConfirm' => 'IPS_RequestAction(' . $ich . ', "SetupApply", "");',
                'nextPage'  => 'result',
            ],
            [
                'name'    => 'result',
                'caption' => $this->Translate('Ready to go'),
                'items'   => [
                    ['type' => 'Label', 'name' => 'ResultText', 'caption' => $this->BerichtText()],
                    ['type' => 'Label', 'name' => 'ResultHint', 'caption' => $this->Translate('Have fun with SymDo. You can start the assistant again at any time — nothing is created twice.')],
                ],
            ],
        ];
    }

    /** Die Seiten als Aufklapp-Panels — der Rückfall unter Symcon 9.1. */
    private function RueckfallItems(array $seiten): array
    {
        $raus = [];
        foreach ($seiten as $seite) {
            if ((string)($seite['name'] ?? '') === 'result') {
                continue;                       // der Bericht steht im Hauptformular
            }
            $items = (array)($seite['items'] ?? []);
            /* Was auf einer Seite `onConfirm` erledigt, braucht hier einen
               eigenen Knopf: ohne Weiter-Knopf gibt es keinen Zeitpunkt, an dem
               die Seite bestätigt wird. */
            if (isset($seite['onConfirm']) && (string)($seite['name'] ?? '') !== 'summary') {
                $items[] = [
                    'type'    => 'Button',
                    'caption' => $this->Translate('Save this step'),
                    'onClick' => (string)$seite['onConfirm'],
                ];
            }
            $raus[] = [
                'type'    => 'ExpansionPanel',
                'caption' => (string)($seite['caption'] ?? ''),
                'items'   => $items,
            ];
        }
        $raus[] = [
            'type'    => 'Button',
            'caption' => $this->Translate('Set up now'),
            'onClick' => 'IPS_RequestAction($id, "SetupApply", "");',
        ];
        return $raus;
    }

    private function RollenOptionen(): array
    {
        $namen = [
            ''            => $this->Translate('No role'),
            'mother'      => $this->Translate('Mother'),
            'father'      => $this->Translate('Father'),
            'child'       => $this->Translate('Child'),
            'grandmother' => $this->Translate('Grandmother'),
            'grandfather' => $this->Translate('Grandfather'),
            'aunt'        => $this->Translate('Aunt'),
            'uncle'       => $this->Translate('Uncle'),
        ];
        $raus = [];
        foreach ($namen as $wert => $text) {
            $raus[] = ['value' => $wert, 'caption' => $text];
        }
        return $raus;
    }

    /**
     * Ein Kästchen je Baustein. Was es schon gibt, ist angehakt und gesperrt —
     * der Assistent legt an, er räumt nicht ab.
     */
    private function BausteinKaestchen(): array
    {
        $gewaehlt = (array)($this->Antworten()['bausteine'] ?? []);
        $raus = [];
        foreach (SetupPlan::BAUSTEINE as $key => $baustein) {
            $da = $this->Instanzen((string)$baustein['guid']) !== [];
            $raus[] = [
                'type'    => 'CheckBox',
                'name'    => 'Use_' . $key,
                'caption' => (string)$baustein['name']
                    . ($da ? ' — ' . $this->Translate('already there') : ''),
                'value'   => $da || (($gewaehlt[$key] ?? false) === true),
                'enabled' => !$da,
                'onChange' => 'IPS_RequestAction($id, "ModuleToggle", json_encode('
                    . '["key" => "' . $key . '", "on" => $Use_' . $key . ']));',
            ];
        }
        return $raus;
    }

    /** Was beim Ankreuzen automatisch mitkam. */
    private function BausteinHinweis(): string
    {
        return (string)($this->Antworten()['hinweis'] ?? '');
    }

    /** Die Mitglieder, die schon im Gateway stehen. */
    private function VorhandeneMitgliederText(): string
    {
        $teile = [];
        foreach ($this->GatewayMitglieder() as $u) {
            if (trim((string)($u['name'] ?? '')) === '') {
                continue;
            }
            // Nur die Namen: wer schon steht, braucht hier keine Einzelheiten.
            $teile[] = trim((string)$u['name'] . ' ' . (string)($u['lastName'] ?? ''));
        }
        return $teile === []
            ? $this->Translate('Nobody is set up yet.')
            : sprintf($this->Translate('Already set up: %s'), implode(' · ', $teile));
    }

    /** Ein Mitglied in einer Zeile: Name, Rolle, Geburtstag, Foto, Push. */
    private function MitgliedZeile(array $m): string
    {
        $text = trim((string)($m['name'] ?? '') . ' ' . (string)($m['lastName'] ?? ''));
        $zusatz = [];
        $rolle = trim((string)($m['persona'] ?? ''));
        if ($rolle !== '') {
            $zusatz[] = $this->Translate($rolle);
        }
        $gb = SetupPlan::Geburtstag($m['birthday'] ?? null);
        if ((int)$gb['year'] > 0) {
            $zusatz[] = sprintf('%02d.%02d.%04d', (int)$gb['day'], (int)$gb['month'], (int)$gb['year']);
        }
        if ((int)($m['photo'] ?? 0) > 0) {
            $zusatz[] = $this->Translate('with photo');
        }
        if ((int)($m['visu'] ?? 0) > 0) {
            $zusatz[] = $this->Translate('with push');
        }
        return $text . ($zusatz === [] ? '' : ' (' . implode(', ', $zusatz) . ')');
    }

    /**
     * Die Auswahl „für welches Kind" — vorhandene und gerade übernommene
     * Mitglieder. Gewählt wird der NAME: die Kennung eines neuen Mitglieds
     * entsteht erst im Lauf.
     */
    private function KinderOptionen(): array
    {
        $raus = [['value' => '', 'caption' => $this->Translate('Please select')]];
        $gesehen = [];
        $anhaengen = function (string $name, string $rolle) use (&$raus, &$gesehen): void {
            $s = SetupPlan::Schluessel($name);
            if ($name === '' || isset($gesehen[$s])) {
                return;
            }
            $gesehen[$s] = true;
            $raus[] = ['value' => $name,
                       'caption' => $name . ($rolle !== '' ? ' (' . $this->Translate($rolle) . ')' : '')];
        };
        // Kinder zuerst — die Schule betrifft sie.
        foreach ([true, false] as $nurKinder) {
            foreach ($this->GatewayMitglieder() as $u) {
                $rolle = trim((string)($u['persona'] ?? ''));
                if (($rolle === 'child') === $nurKinder) {
                    $anhaengen(trim((string)($u['name'] ?? '')), $rolle);
                }
            }
            foreach ((array)($this->Antworten()['members'] ?? []) as $m) {
                $rolle = trim((string)($m['persona'] ?? ''));
                if (($rolle === 'child') === $nurKinder) {
                    $anhaengen(trim((string)($m['name'] ?? '')), $rolle);
                }
            }
        }
        return $raus;
    }

    /**
     * Der Datenschutzhinweis — derselbe Text wie im Gateway, aus dessen
     * Formular gelesen. So gibt es ihn nur einmal im Haus.
     */
    private function DatenschutzText(): string
    {
        try {
            $roh = json_decode((string)@file_get_contents(__DIR__ . '/../SymDoGateway/form.json'), true);
            $laengster = '';
            $suche = function ($knoten) use (&$suche, &$laengster): void {
                foreach (is_array($knoten) ? $knoten : [] as $k => $v) {
                    if ($k === 'caption' && is_string($v) && mb_strlen($v) > mb_strlen($laengster)) {
                        $laengster = $v;
                    } elseif (is_array($v)) {
                        $suche($v);
                    }
                }
            };
            $suche(is_array($roh) ? $roh : []);
            if ($laengster === '') {
                return $this->Translate('The full privacy notice stands in the gateway, under AI features.');
            }
            /* Übersetzt wird aus der locale.json des GATEWAYS: dort steht der
               deutsche Text, und er soll nicht zweimal im Haus liegen. Ob
               Deutsch gilt, verrät eine Probe an einem eigenen Schlüssel. */
            if ($this->Translate('Cancel') === 'Abbrechen') {
                $lok = json_decode((string)@file_get_contents(__DIR__ . '/../SymDoGateway/locale.json'), true);
                $de = (array)((($lok['translations'] ?? [])['de']) ?? []);
                if (isset($de[$laengster]) && is_string($de[$laengster])) {
                    return (string)$de[$laengster];
                }
            }
            return $laengster;
        } catch (\Throwable $e) {
            return $this->Translate('The full privacy notice stands in the gateway, under AI features.');
        }
    }

    /** Das PHP-Fragment, das die Kästchen als Feldvariablen einsammelt. */
    private function BausteinPayload(): string
    {
        $teile = [];
        foreach (array_keys(SetupPlan::BAUSTEINE) as $key) {
            $teile[] = '"' . $key . '" => $Use_' . $key;
        }
        return '[' . implode(', ', $teile) . ']';
    }

    // ─────────────────────────── Seiten-Handler ──────────────────────────────

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'MemberAdd':
                $this->MitgliedAufnehmen((string)$Value);
                break;

            case 'MemberClear':
                $this->AntwortSetzen('members', []);
                $this->UpdateFormField('MemberList', 'caption', $this->MitgliederText());
                break;

            case 'ModuleToggle':
                $this->BausteinUmschalten($this->JsonArray((string)$Value));
                break;

            case 'Modules':
                /* Die Kästchen kommen gesammelt; Abhängigkeiten schliessen
                   trotzdem, denn ein gesperrtes Kästchen meldet sich nie. */
                $this->AntwortSetzen('bausteine', $this->JsonArray((string)$Value));
                $this->UpdateFormField('SummaryText', 'caption', $this->VorschauText());
                break;

            case 'Access':
                $this->AntwortSetzen('access', ($this->JsonArray((string)$Value)['want'] ?? false) === true);
                break;

            case 'School':
                $roh = $this->JsonArray((string)$Value);
                $this->AntwortSetzen('school', (string)($roh['school'] ?? 'none'));
                $this->AntwortSetzen('schoolChild', trim((string)($roh['child'] ?? '')));
                /* Zugangsdaten liegen im Puffer — Arbeitsspeicher der Instanz,
                   nicht die Konfiguration. Nach dem Lauf werden sie geleert. */
                $this->AntwortSetzen('schoolAccess', [
                    'server'   => trim((string)($roh['server'] ?? '')),
                    'name'     => trim((string)($roh['name'] ?? '')),
                    'user'     => trim((string)($roh['user'] ?? '')),
                    'password' => (string)($roh['password'] ?? ''),
                ]);
                $this->UpdateFormField('SummaryText', 'caption', $this->VorschauText());
                break;

            case 'Ai':
                $roh = $this->JsonArray((string)$Value);
                $anbieter = trim((string)($roh['provider'] ?? ''));
                $schluessel = (string)($roh['key'] ?? '');
                $this->AntwortSetzen('ai', [
                    'provider' => $anbieter,
                    'key'      => $schluessel,
                    'hasKey'   => trim($schluessel) !== '',
                ]);
                $this->AntwortSetzen('aiConsent', ($roh['consent'] ?? false) === true);
                $this->UpdateFormField('SummaryText', 'caption', $this->VorschauText());
                break;

            case 'SetupApply':
                $bericht = $this->Apply('');
                // Die Ergebnisseite LIVE füllen — sie steht schon im offenen
                // Popup und wird nicht neu geliefert.
                $this->UpdateFormField('ResultText', 'caption', $bericht);
                $this->UpdateFormField('ReportLabel', 'caption', $bericht);
                break;

            case 'ReportRefresh':
                $this->UpdateFormField('ReportLabel', 'caption', $this->BerichtText());
                break;

            default:
                throw new \InvalidArgumentException('Unknown ident: ' . $Ident);
        }
    }

    /**
     * Ein Kästchen wurde umgelegt: merken — und die Voraussetzungen gleich
     * mit ankreuzen, sichtbar im Formular.
     */
    private function BausteinUmschalten(array $roh): void
    {
        $key = trim((string)($roh['key'] ?? ''));
        if ($key === '' || !isset(SetupPlan::BAUSTEINE[$key])) {
            return;
        }
        $an = ($roh['on'] ?? false) === true;
        $gewaehlt = (array)($this->Antworten()['bausteine'] ?? []);
        $gewaehlt[$key] = $an;

        $hinweis = '';
        if ($an) {
            $vorher = $gewaehlt;
            $zu = SetupPlan::AbhaengigkeitenSchliessen($gewaehlt,
                (string)($this->Antworten()['school'] ?? 'none'));
            $namen = [];
            foreach ($zu['bausteine'] as $k => $ignoriert) {
                if (($vorher[$k] ?? false) === true) {
                    continue;
                }
                $gewaehlt[$k] = true;
                // Das Kästchen im offenen Formular mitziehen.
                $this->UpdateFormField('Use_' . $k, 'value', true);
                /* Was schon steht, ist angehakt und gesperrt — darauf muss
                   niemand hingewiesen werden. */
                if ($this->Instanzen((string)SetupPlan::BAUSTEINE[$k]['guid']) === []) {
                    $namen[] = (string)SetupPlan::BAUSTEINE[$k]['name'];
                }
            }
            if ($namen !== []) {
                $hinweis = sprintf(
                    count($namen) === 1
                        ? $this->Translate('%1$s also needs %2$s — added for you.')
                        : $this->Translate('%1$s also needs %2$s — added for you.'),
                    (string)SetupPlan::BAUSTEINE[$key]['name'], implode(', ', $namen));
            }
        }
        $this->AntwortSetzen('bausteine', $gewaehlt);
        $this->AntwortSetzen('hinweis', $hinweis);
        $this->UpdateFormField('ModuleHint', 'caption', $hinweis);
        $this->UpdateFormField('SummaryText', 'caption', $this->VorschauText());
    }

    /** Ein Mitglied in den Puffer aufnehmen (und die Zeile darunter nachziehen). */
    private function MitgliedAufnehmen(string $json): void
    {
        $roh = $this->JsonArray($json);
        $name = trim((string)($roh['name'] ?? ''));
        if ($name === '') {
            $this->UpdateFormField('MemberList', 'caption', $this->Translate('Please enter a first name.'));
            return;
        }
        $liste = (array)($this->Antworten()['members'] ?? []);
        foreach ($liste as $v) {
            if (SetupPlan::Schluessel((string)($v['name'] ?? '')) === SetupPlan::Schluessel($name)) {
                // Zweimal derselbe Name ist ein Versehen, kein zweites Kind.
                $this->UpdateFormField('MemberList', 'caption',
                    sprintf($this->Translate('%s is already in the list.'), $name) . "\n" . $this->MitgliederText());
                return;
            }
        }
        $liste[] = [
            'name'     => $name,
            'lastName' => trim((string)($roh['lastName'] ?? '')),
            'birthday' => SetupPlan::Geburtstag($roh['birthday'] ?? null),
            'persona'  => SetupPlan::Rolle((string)($roh['persona'] ?? '')),
            'photo'    => max(0, (int)($roh['photo'] ?? 0)),
            'visu'     => max(0, (int)($roh['visu'] ?? 0)),
        ];
        $this->AntwortSetzen('members', $liste);
        $this->UpdateFormField('MemberList', 'caption', $this->MitgliederText());
        // Die Schulseite fragt „für welches Kind" — die Auswahl wächst mit.
        $this->UpdateFormField('SchoolChild', 'options',
            (string)json_encode($this->KinderOptionen(), JSON_UNESCAPED_UNICODE));
        // Die Felder leeren, damit das nächste Mitglied nicht auf dem vorigen sitzt.
        foreach (['MemberName', 'MemberLastName', 'MemberPersona'] as $feld) {
            $this->UpdateFormField($feld, 'value', '');
        }
        foreach (['MemberPhoto', 'MemberVisu'] as $feld) {
            $this->UpdateFormField($feld, 'value', 0);
        }
        $this->UpdateFormField('MemberBirthday', 'value',
            (string)json_encode(['year' => 0, 'month' => 0, 'day' => 0]));
    }

    // ────────────────── Öffentliche Funktionen für die Seiten ────────────────

    /** Die Seite „Familie" hält, solange niemand dasteht. */
    public function ValidateFamily(): string
    {
        $gesammelt = count((array)($this->Antworten()['members'] ?? []));
        if ($gesammelt > 0) {
            return '';
        }
        foreach ($this->GatewayMitglieder() as $u) {
            if (trim((string)($u['name'] ?? '')) !== '') {
                return '';                      // im Gateway stehen schon welche
            }
        }
        return $this->Translate('Please add at least one family member.');
    }

    /** Eine Schulanbindung braucht Adresse, Benutzer und Kennwort. */
    public function ValidateSchool(string $School, string $Server, string $User, string $Password): string
    {
        if (trim($School) === '' || trim($School) === 'none') {
            return '';
        }
        if (trim($Server) === '') {
            return $this->Translate('Please enter the server or address of your school.');
        }
        if (trim($User) === '' || trim($Password) === '') {
            return $this->Translate('Please enter user name and password.');
        }
        return '';
    }

    /** Die KI braucht Schlüssel und Einwilligung — oder gar nichts. */
    public function ValidateAi(string $Provider, string $Key, bool $Consent): string
    {
        if (trim($Provider) === '') {
            return '';
        }
        if (trim($Key) === '') {
            return $this->Translate('The chosen AI provider needs an API key.');
        }
        if (!$Consent) {
            return $this->Translate('Please agree to the privacy notice to use the smart helpers.');
        }
        return '';
    }

    /**
     * Der Ausführer — öffentlich, damit der Zug per Skript prüfbar ist:
     *
     *   SDSU_Apply($id, '{"members":[…],"bausteine":{…}}')
     *
     * Mit leerem Parameter nimmt er die gesammelten Antworten aus dem Puffer.
     * Rückgabe ist der Bericht als Text — dieselbe Zeichenkette, die im
     * Formular steht.
     */
    public function Apply(string $AnswersJson): string
    {
        $antworten = trim($AnswersJson) === '' ? $this->Antworten() : $this->JsonArray($AnswersJson);
        $bestand = $this->Bestand();
        /* Was schon steht, gehört in die Wahl: sein Kästchen ist gesperrt und
           meldet sich nie, im Bericht soll es aber auftauchen. */
        $gewaehlt = (array)($antworten['bausteine'] ?? []);
        foreach ((array)($bestand['instanzen'] ?? []) as $key => $ids) {
            if (is_array($ids) && $ids !== []) {
                $gewaehlt[$key] = true;
            }
        }
        $antworten['bausteine'] = $gewaehlt;

        // Kennungen für neue Mitglieder: so viele, wie es Zeilen gibt. Selbst
        // gewürfelt, weil sie noch im selben Zug in fremde Properties müssen.
        $vorrat = [];
        for ($i = 0, $n = count((array)($antworten['members'] ?? [])); $i < $n; $i++) {
            $vorrat[] = bin2hex(random_bytes(4));
        }

        $plan = SetupPlan::Absichten($antworten, $bestand, $vorrat);
        $bericht = [
            'at'       => time(),
            'abbruch'  => (string)$plan['abbruch'],
            'zeilen'   => [],
            'gateway'  => 0,
            'kennungen' => [],
        ];

        if ($plan['abbruch'] !== '') {
            $bericht['zeilen'][] = $this->AbbruchText((string)$plan['abbruch']);
            return $this->BerichtSchreiben($bericht);
        }

        $gateway = 0;
        $ids = [];                              // Baustein → Instanz
        $users = [];
        $mitgliederGeschrieben = false;
        try {
            foreach ($plan['schritte'] as $schritt) {
                switch ((string)$schritt['art']) {
                    case 'gateway':
                        $gateway = $this->SchrittGateway($schritt, $bericht);
                        if ($gateway <= 0) {
                            // Die BARRIERE: ohne Gateway darf keine Kachel
                            // entstehen. Sie verbrennt sonst beim ersten
                            // ApplyChanges ihr ParentMigrated-Flag und bleibt
                            // dauerhaft elternlos.
                            $bericht['zeilen'][] = $this->Translate('Aborted: without a gateway no tile may be created.');
                            return $this->BerichtSchreiben($bericht);
                        }
                        $bericht['gateway'] = $gateway;
                        break;

                    case 'mitglieder':
                        $users = (array)$schritt['users'];
                        /* Das Ergebnis wandert in den naechsten Schritt: die
                           Mitglieder und die Stammdaten teilen sich EIN
                           ApplyChanges, und dort laeuft EnsureUserIDs fuer
                           beides. Ohne diese Weitergabe blieben geschriebene
                           Mitglieder ungeuebernommen, wenn sich sonst nichts
                           aendert. */
                        $mitgliederGeschrieben = $this->SchrittMitglieder($gateway, $schritt, $bericht);
                        break;

                    case 'gateway-daten':
                        $this->SchrittGatewayDaten($gateway, $schritt, $antworten, $bericht, $mitgliederGeschrieben);
                        break;

                    case 'baustein':
                        $this->SchrittBaustein($gateway, $schritt, $ids, $bericht);
                        break;

                    case 'schule':
                        $this->SchrittSchule($gateway, $schritt, $antworten, $users, $ids, $bericht);
                        break;

                    case 'einwilligung':
                        $this->SchrittEinwilligung($gateway, $bericht);
                        break;

                    case 'zugang':
                        $this->SchrittZugang($gateway, $bericht);
                        break;
                }
            }
        } catch (\Throwable $e) {
            /* Kein Fehler darf entkommen: sonst zeigt die Konsole eine rohe
               PHP-Seite, und der gesammelte Bericht ist weg. */
            $bericht['zeilen'][] = sprintf($this->Translate('Unexpected error: %s'), $e->getMessage());
        }

        foreach ($users as $u) {
            if (trim((string)($u['id'] ?? '')) !== '') {
                $bericht['kennungen'][(string)$u['name']] = (string)$u['id'];
            }
        }
        /* Schlüssel und Kennwörter haben ihren Weg genommen; im Puffer haben
           sie nichts mehr zu suchen. */
        $ai = (array)($this->Antworten()['ai'] ?? []);
        if (($ai['key'] ?? '') !== '') {
            $ai['key'] = '';
            $this->AntwortSetzen('ai', $ai);
        }
        $zugang = (array)($this->Antworten()['schoolAccess'] ?? []);
        if (($zugang['password'] ?? '') !== '') {
            $zugang['password'] = '';
            $this->AntwortSetzen('schoolAccess', $zugang);
        }
        return $this->BerichtSchreiben($bericht);
    }

    // ──────────────────────────── Die Schritte ───────────────────────────────

    private function SchrittGateway(array $schritt, array &$bericht): int
    {
        if ((string)$schritt['aktion'] === 'vorhanden') {
            $id = (int)$schritt['id'];
            $bericht['zeilen'][] = sprintf($this->Translate('Gateway #%d found and used.'), $id);
            return $id;
        }
        try {
            $id = $this->InstanzAnlegen((string)$schritt['guid'], (string)$schritt['name'], 0);
            IPS_ApplyChanges($id);
            $bericht['zeilen'][] = sprintf($this->Translate('Gateway created: #%d.'), $id);
            return $id;
        } catch (\Throwable $e) {
            $bericht['zeilen'][] = sprintf($this->Translate('Gateway could not be created: %s'), $e->getMessage());
            return 0;
        }
    }

    /**
     * Die Mitglieder — VOR dem Übernehmen des Gateways.
     *
     * Begründung im Code des Gateways: `LoadUsers()` liest mit
     * IPS_GetProperty (sieht also gestagte Zeilen sofort), `EnsureUserIDs()`
     * läuft IN diesem ApplyChanges, und `NotesApplyChanges()` braucht die
     * Kennungen. Danach geschrieben, bekäme der Stundenplan im ersten Lauf
     * keine Kinder.
     */
    private function SchrittMitglieder(int $gateway, array $schritt, array &$bericht): bool
    {
        if ((string)$schritt['aktion'] !== 'schreiben') {
            $bericht['zeilen'][] = $this->Translate('Members unchanged.');
            return false;
        }
        try {
            $this->PropertySetzen($gateway, 'Users',
                (string)json_encode($schritt['users'], JSON_UNESCAPED_UNICODE));
            /* Das Übernehmen des Gateways macht der nächste Schritt
               (gateway-daten) — die Mitglieder gehören mit den Stammdaten in
               EIN ApplyChanges, und dort läuft EnsureUserIDs für beides. */
            $bericht['zeilen'][] = sprintf(
                $this->Translate('Members: %1$d new, %2$d completed, %3$d skipped.'),
                (int)$schritt['neu'], (int)$schritt['ergaenzt'], (int)$schritt['uebergangen']);
            return true;
        } catch (\Throwable $e) {
            $bericht['zeilen'][] = sprintf($this->Translate('Members could not be written: %s'), $e->getMessage());
            return false;
        }
    }

    /** Familienname, KI-Anbieter und Schlüssel, Schulschalter — dann EIN Übernehmen. */
    private function SchrittGatewayDaten(int $gateway, array $schritt, array $antworten, array &$bericht, bool $mitgliederGeschrieben): void
    {
        /* Gezählt wird, was WIRKLICH geschrieben wurde: ein IPS_ApplyChanges
           auf ein laufendes Gateway ist kein Nullvorgang — es meldet die Hooks
           neu an und lässt jeden Trait seinen ApplyChanges laufen. Ohne
           Änderung hat es hier nichts zu suchen. */
        $geschrieben = $mitgliederGeschrieben ? 1 : 0;
        try {
            if ((string)$schritt['familyName'] !== ''
                && $this->PropertySetzen($gateway, 'FamilyName', (string)$schritt['familyName'])) {
                $geschrieben++;
            }
            $ai = (array)$schritt['ai'];
            $anbieter = (string)($ai['provider'] ?? '');
            if ($anbieter !== '') {
                $gesetzt = $this->PropertySetzen($gateway, 'AiProvider', $anbieter);
                $schluessel = (string)(((array)($antworten['ai'] ?? []))['key'] ?? '');
                $feld = ['anthropic' => 'AiAnthropicKey', 'openai' => 'AiOpenAIKey', 'local' => 'AiLocalKey'][$anbieter] ?? '';
                if ($feld !== '' && $schluessel !== '' && $this->PropertySetzen($gateway, $feld, $schluessel)) {
                    $geschrieben++;
                }
                // AUS: ohne Einwilligung sperrt das Formular den Schalter ohnehin.
                if ($this->PropertySetzen($gateway, 'AiEnabled', false)) {
                    $geschrieben++;
                }
                $geschrieben += $gesetzt ? 1 : 0;
                $bericht['zeilen'][] = $gesetzt
                    ? sprintf($this->Translate('AI provider set to %s; the AI itself stays off until you accept the privacy notice in the gateway.'), $anbieter)
                    : $this->Translate('AI provider was already set in the gateway and stays as it is.');
            }
            if ($geschrieben === 0) {
                $bericht['zeilen'][] = $this->Translate('Gateway settings already as wanted — nothing written, no restart of the gateway.');
                return;
            }
            IPS_ApplyChanges($gateway);
            $bericht['zeilen'][] = $this->Translate('Gateway settings applied.');
        } catch (\Throwable $e) {
            $bericht['zeilen'][] = sprintf($this->Translate('Gateway settings failed: %s'), $e->getMessage());
        }
    }

    /** Eine Kachel: vorhanden, angelegt — oder übersprungen. */
    private function SchrittBaustein(int $gateway, array $schritt, array &$ids, array &$bericht): void
    {
        $key = (string)$schritt['key'];
        $name = (string)$schritt['name'];
        $aktion = (string)$schritt['aktion'];

        if ($aktion === 'vorhanden') {
            $ids[$key] = (int)$schritt['id'];
            $bericht['zeilen'][] = sprintf($this->Translate('%1$s: found #%2$d, used.'), $name, (int)$schritt['id']);
            return;
        }
        if ($aktion === 'uebersprungen') {
            $bericht['zeilen'][] = sprintf($this->Translate('%s: skipped, module not installed.'), $name);
            return;
        }

        $id = 0;
        try {
            $id = $this->InstanzAnlegen((string)$schritt['guid'], $name, $gateway);
            /* Verbinden, BEVOR die Eigenschaften gesetzt werden — und immer
               selbst: vier Kacheln verbinden sich gar nicht, und bei den
               übrigen ist das Einmal-Recht nach dem ersten ApplyChanges
               verbraucht. */
            @IPS_ConnectInstance($id, $gateway);
            foreach ((array)($schritt['verweise'] ?? []) as $prop => $ziel) {
                $zielId = (int)($ids[(string)$ziel] ?? 0);
                if ($zielId > 0) {
                    $this->PropertySetzen($id, (string)$prop, $zielId);
                }
            }
            foreach ((array)($schritt['nutzer'] ?? []) as $prop => $kennung) {
                if ((string)$kennung !== '') {
                    $this->PropertySetzen($id, (string)$prop, (string)$kennung);
                }
            }
            IPS_ApplyChanges($id);
            $ids[$key] = $id;
            $this->Angelegt($id);
            $bericht['zeilen'][] = sprintf($this->Translate('%1$s: created #%2$d.'), $name, $id);
        } catch (\Throwable $e) {
            /* Die halb angelegte Instanz wieder wegräumen — eine Leiche im
               Objektbaum ist schlimmer als ein fehlender Baustein. Aber:
               IPS_DeleteInstance verweigert, solange Unterobjekte hängen, und
               eine Kachel legt ihre Variablen im ersten ApplyChanges an
               (im Prüflauf gemessen: zwei Unterobjekte). Also von innen nach
               aussen — und wenn es nicht gelingt, sagt der Bericht es, statt
               den Fehlschlag zu verschweigen. */
            $rest = $id > 0 ? $this->InstanzWegraeumen($id) : true;
            $bericht['zeilen'][] = sprintf($this->Translate('%1$s: FAILED — %2$s'), $name, $e->getMessage())
                . ($rest ? '' : ' ' . sprintf($this->Translate('The half-created instance #%d is still there and needs to be removed by hand.'), $id));
        }
    }

    /**
     * Die Schulanbindung: Zugangsdaten setzen und einschalten.
     *
     * @param list<array<string,mixed>> $users  die zusammengeführten Mitglieder
     * @param array<string,int>         $ids    Baustein → Instanz
     */
    private function SchrittSchule(int $gateway, array $schritt, array $antworten,
                                   array $users, array $ids, array &$bericht): void
    {
        $system = (string)$schritt['system'];
        $zugang = (array)($antworten['schoolAccess'] ?? []);
        $server = trim((string)($zugang['server'] ?? ''));
        $benutzer = trim((string)($zugang['user'] ?? ''));
        $kennwort = (string)($zugang['password'] ?? '');
        if ($server === '' || $benutzer === '' || $kennwort === '') {
            $bericht['zeilen'][] = $this->Translate('School: nothing entered, skipped.');
            return;
        }
        // Das gewählte Kind steht als NAME da; seine Kennung gibt es erst jetzt.
        $kind = SetupPlan::Schluessel((string)$schritt['kind']);
        $kennung = '';
        $kindName = '';
        foreach ($users as $u) {
            if (SetupPlan::Schluessel((string)($u['name'] ?? '')) === $kind && $kind !== '') {
                $kennung = trim((string)($u['id'] ?? ''));
                $kindName = trim((string)($u['name'] ?? ''));
                break;
            }
        }

        try {
            if ($system === 'untis') {
                $this->PropertySetzen($gateway, 'UntisServer', $server);
                $this->PropertySetzen($gateway, 'UntisSchool', trim((string)($zugang['name'] ?? '')));
                $this->PropertySetzen($gateway, 'UntisUser', $benutzer);
                $this->PropertySetzen($gateway, 'UntisPassword', $kennwort);
                $this->PropertySetzen($gateway, 'UntisHomework', true);
                $this->PropertySetzen($gateway, 'UntisEnabled', true);
                IPS_ApplyChanges($gateway);
                $bericht['zeilen'][] = $this->Translate('WebUntis is set up. One step is left for you: in the gateway, fetch the pupils and pick your child — WebUntis needs that choice.');
                return;
            }

            // LOGINEO: eine Zeile je Kind, und das Kennwort gegen einen Token tauschen.
            $liste = json_decode((string)(json_decode((string)@IPS_GetConfiguration($gateway), true)['MoodleAccounts'] ?? '[]'), true);
            $liste = is_array($liste) ? array_values(array_filter($liste, 'is_array')) : [];
            $adresse = str_starts_with($server, 'http') ? $server : 'https://' . $server;
            $schonDa = false;
            foreach ($liste as $z) {
                if (trim((string)($z['user'] ?? '')) === $benutzer) {
                    $schonDa = true;
                    break;
                }
            }
            if (!$schonDa) {
                $liste[] = [
                    'name'   => $kindName !== '' ? $kindName : $benutzer,
                    'site'   => $adresse,
                    'user'   => $benutzer,
                    'userId' => $kennung,
                    'stpl'   => (int)($ids['timetable'] ?? 0),
                ];
                IPS_SetProperty($gateway, 'MoodleAccounts',
                    (string)json_encode($liste, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
            $this->PropertySetzen($gateway, 'MoodleEnabled', true);
            IPS_ApplyChanges($gateway);
            if ($kennung === '') {
                $bericht['zeilen'][] = $this->Translate('LOGINEO is prepared. Pick the family member for the access in the gateway, then fetch the token.');
                return;
            }
            // Der Knopf des Gateways tauscht Kennwort gegen Token.
            @IPS_RequestAction($gateway, 'MoodleToken',
                (string)json_encode(['account' => $kennung, 'password' => $kennwort]));
            $bericht['zeilen'][] = sprintf(
                $this->Translate('LOGINEO is set up for %s and the token has been fetched.'),
                $kindName !== '' ? $kindName : $benutzer);
        } catch (\Throwable $e) {
            $bericht['zeilen'][] = sprintf($this->Translate('School setup failed: %s'), $e->getMessage());
        }
    }

    /** Die Einwilligung — erteilt über denselben Weg wie der Knopf im Gateway. */
    private function SchrittEinwilligung(int $gateway, array &$bericht): void
    {
        try {
            @IPS_RequestAction($gateway, 'AiPrivacyConsent', true);
            // Nachlesen, ob sie wirklich angekommen ist: das Attribut des
            // Gateways ist von hier aus nur über diese Auskunft sichtbar.
            if (function_exists('TGW_GetSetupState')) {
                $stand = json_decode((string)@TGW_GetSetupState($gateway), true);
                if (is_array($stand) && ($stand['aiAccepted'] ?? false) !== true) {
                    $bericht['zeilen'][] = $this->Translate('The consent did not stick — please accept it once in the gateway, under AI features.');
                    return;
                }
            }
            $bericht['zeilen'][] = $this->Translate('Privacy notice accepted — the smart helpers are ready.');
        } catch (\Throwable $e) {
            $bericht['zeilen'][] = sprintf($this->Translate('The consent could not be stored: %s'), $e->getMessage());
        }
    }

    /** Der Zugang, ganz zuletzt. */
    private function SchrittZugang(int $gateway, array &$bericht): void
    {
        if (!function_exists('TGW_CreateWebAccess')) {
            $bericht['zeilen'][] = $this->Translate('Access: the gateway function is not available yet — a kernel restart is needed.');
            return;
        }
        try {
            $antwort = json_decode((string)@TGW_CreateWebAccess($gateway), true);
            $code = is_array($antwort) ? trim((string)($antwort['code'] ?? '')) : '';
            $url = is_array($antwort) ? trim((string)($antwort['url'] ?? '')) : '';
            if ($code === '') {
                $bericht['zeilen'][] = $this->Translate('Access: no code was returned. Create it in the gateway.');
                return;
            }
            $bericht['zeilen'][] = sprintf(
                $this->Translate('Browser access: code %1$s, valid for ten minutes. Address: %2$s'),
                $code, $url !== '' ? $url : $this->Translate('(see gateway)'));
        } catch (\Throwable $e) {
            $bericht['zeilen'][] = sprintf($this->Translate('Access failed: %s'), $e->getMessage());
        }
    }

    // ──────────────────────────── Werkzeuge ──────────────────────────────────

    /**
     * Eine Instanz anlegen und ablegen.
     *
     * Sie hängt NICHT unter dem Assistenten: Symcon räumt Kinder mit der
     * Instanz weg, und ein gelöschter Assistent nähme die ganze Einrichtung
     * mit. Erste Wahl ist deshalb der Platz NEBEN dem Gateway.
     */
    private function InstanzAnlegen(string $guid, string $name, int $neben): int
    {
        $id = IPS_CreateInstance($guid);
        IPS_SetName($id, $name);
        $eltern = 0;
        if ($neben > 0 && @IPS_InstanceExists($neben)) {
            $eltern = (int)@IPS_GetParent($neben);
        }
        if ($eltern > 0) {
            @IPS_SetParent($id, $eltern);
        }
        $this->Angelegt($id);
        return $id;
    }

    /**
     * Eine angelegte Instanz wieder wegräumen, von innen nach aussen.
     *
     * @return bool true = weg, false = da ist noch etwas
     */
    private function InstanzWegraeumen(int $id): bool
    {
        try {
            if (!@IPS_InstanceExists($id)) {
                return true;
            }
            foreach ((array)@IPS_GetChildrenIDs($id) as $kind) {
                $o = @IPS_GetObject((int)$kind);
                $typ = is_array($o) ? (int)($o['ObjectType'] ?? -1) : -1;
                match ($typ) {
                    0 => @IPS_DeleteCategory((int)$kind),
                    2 => @IPS_DeleteVariable((int)$kind),
                    3 => @IPS_DeleteScript((int)$kind, true),
                    4 => @IPS_DeleteEvent((int)$kind),
                    5 => @IPS_DeleteMedia((int)$kind, true),
                    6 => @IPS_DeleteLink((int)$kind),
                    default => null,
                };
            }
            @IPS_DeleteInstance($id);
            return !@IPS_InstanceExists($id);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Eine fremde Property setzen — lesen, prüfen, nur bei Bedarf schreiben. */
    private function PropertySetzen(int $instanz, string $name, mixed $wert): bool
    {
        $cfg = json_decode((string)@IPS_GetConfiguration($instanz), true);
        if (!is_array($cfg) || !array_key_exists($name, $cfg)) {
            throw new \RuntimeException(sprintf('property %s not found on #%d', $name, $instanz));
        }
        if ($cfg[$name] === $wert) {
            return true;                         // schon richtig
        }
        /* Eine bestehende Wahl des Nutzers wird nicht überschrieben: ein
           gesetzter Verweis bleibt, wie er ist. */
        if (is_int($wert) && (int)$cfg[$name] > 0) {
            return false;
        }
        if (is_string($wert) && trim((string)$cfg[$name]) !== '' && $name !== 'Users') {
            return false;
        }
        IPS_SetProperty($instanz, $name, $wert);
        return true;
    }

    private function Angelegt(int $id): void
    {
        $liste = json_decode($this->AttributLesen(self::A_ANGELEGT, '[]'), true);
        $liste = is_array($liste) ? $liste : [];
        if (!in_array($id, $liste, true)) {
            $liste[] = $id;
        }
        @$this->WriteAttributeString(self::A_ANGELEGT, (string)json_encode(array_values($liste)));
    }

    // ───────────────────────────── Der Bestand ───────────────────────────────

    /**
     * Die Messung des Systems, wie SetupPlan sie erwartet. Rein lesend.
     */
    private function Bestand(): array
    {
        $instanzen = [];
        $module = [];
        foreach (SetupPlan::BAUSTEINE as $key => $baustein) {
            $module[$key] = $this->ModulVorhanden((string)$baustein['guid']);
            $instanzen[$key] = $module[$key] ? $this->Instanzen((string)$baustein['guid']) : [];
        }
        return [
            'runlevel'  => (int)@IPS_GetKernelRunlevel(),
            'gateways'  => $this->Instanzen(SetupPlan::GATEWAY_GUID),
            'instanzen' => $instanzen,
            'module'    => $module,
            'users'     => $this->GatewayMitglieder(),
        ];
    }

    /** @return list<int> */
    private function Instanzen(string $guid): array
    {
        try {
            $ids = @IPS_GetInstanceListByModuleID($guid);
            return is_array($ids) ? array_values(array_map('intval', $ids)) : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function ModulVorhanden(string $guid): bool
    {
        try {
            return is_array(@IPS_GetModule($guid));
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Die Mitgliederliste des Gateways — oder eine leere, wenn es keines gibt. */
    private function GatewayMitglieder(): array
    {
        $gateways = $this->Instanzen(SetupPlan::GATEWAY_GUID);
        if ($gateways === []) {
            return [];
        }
        $cfg = json_decode((string)@IPS_GetConfiguration(min($gateways)), true);
        $roh = json_decode((string)(is_array($cfg) ? ($cfg['Users'] ?? '[]') : '[]'), true);
        return is_array($roh) ? array_values(array_filter($roh, 'is_array')) : [];
    }

    // ───────────────────────────── Texte ─────────────────────────────────────

    private function BestandText(): string
    {
        $gateways = $this->Instanzen(SetupPlan::GATEWAY_GUID);
        $zeilen = [];
        if ($gateways === []) {
            $zeilen[] = $this->Translate('No gateway yet — the assistant creates one.');
        } elseif (count($gateways) > 1) {
            $zeilen[] = $this->Translate('There is more than one gateway. The assistant stops in that case: which one serves the app depends on the lowest instance ID, and it would write into the wrong one.');
        } else {
            $zeilen[] = sprintf($this->Translate('Gateway #%d found.'), min($gateways));
        }
        $mitglieder = count($this->GatewayMitglieder());
        $zeilen[] = $mitglieder === 0
            ? $this->Translate('No family member yet.')
            : sprintf($this->Translate('%d family member(s) already entered.'), $mitglieder);
        $fehlt = [];
        foreach (SetupPlan::BAUSTEINE as $key => $baustein) {
            if ($this->Instanzen((string)$baustein['guid']) === []) {
                $fehlt[] = (string)$baustein['name'];
            }
        }
        if ($fehlt !== []) {
            $zeilen[] = sprintf($this->Translate('Not present yet: %s'), implode(', ', $fehlt));
        }
        if (!$this->ModulVorhanden('{227B63E4-4223-316B-76E9-FD3849689562}')) {
            $zeilen[] = $this->Translate('The calendar needs the OpenCalendar module from the store — the assistant cannot install it.');
        }
        return implode("\n", $zeilen);
    }

    private function MitgliederText(): string
    {
        $liste = (array)($this->Antworten()['members'] ?? []);
        if ($liste === []) {
            return $this->Translate('No member collected yet.');
        }
        $teile = [];
        foreach ($liste as $m) {
            $teile[] = $this->MitgliedZeile($m);
        }
        return sprintf($this->Translate('Collected: %s'), implode(' · ', $teile));
    }

    /** Was der Zug tun würde — aus demselben Plan, der ihn danach ausführt. */
    private function VorschauText(): string
    {
        try {
            $antworten = $this->Antworten();
            $plan = SetupPlan::Absichten($antworten, $this->Bestand(),
                array_map(static fn(int $i): string => 'vorschau' . $i,
                    range(0, max(0, count((array)($antworten['members'] ?? [])) - 1))));
            if ($plan['abbruch'] !== '') {
                return $this->AbbruchText((string)$plan['abbruch']);
            }
            $anlegen = [];
            foreach ($plan['schritte'] as $s) {
                if ((string)($s['aktion'] ?? '') === 'anlegen') {
                    $anlegen[] = (string)($s['name'] ?? $s['art']);
                }
            }
            $zeilen = [];
            $zeilen[] = $anlegen === []
                ? $this->Translate('Nothing to create — everything is already there.')
                : sprintf($this->Translate('Will be created: %s'), implode(', ', $anlegen));
            foreach ($plan['schritte'] as $s) {
                if ((string)($s['art'] ?? '') === 'mitglieder' && (string)$s['aktion'] === 'schreiben') {
                    $zeilen[] = sprintf($this->Translate('Members: %1$d new, %2$d completed.'),
                        (int)$s['neu'], (int)$s['ergaenzt']);
                }
            }
            return implode("\n", $zeilen);
        } catch (\Throwable $e) {
            return $this->Translate('Press Next to set everything up.');
        }
    }

    private function AbbruchText(string $grund): string
    {
        return match ($grund) {
            SetupPlan::ABBRUCH_KERNEL => $this->Translate('The kernel is not ready yet. Please try again in a moment.'),
            SetupPlan::ABBRUCH_ZWEI_GATEWAYS => $this->Translate('Stopped: there is more than one SymDo gateway. Which one serves the app depends on the lowest instance ID — the assistant would write into the wrong one. Please remove the extra gateway first.'),
            SetupPlan::ABBRUCH_ZWEI_WEBAPPS => $this->Translate('Stopped: there is more than one SymDo Web App instance, and which one decides the visible areas is not predictable. Please remove the extra one first.'),
            SetupPlan::ABBRUCH_KEIN_MITGLIED => $this->Translate('Stopped: not a single family member with a name.'),
            SetupPlan::ABBRUCH_KI_OHNE_SCHLUESSEL => $this->Translate('Stopped: the chosen AI provider needs an API key.'),
            default => $this->Translate('Stopped.'),
        };
    }

    /** Den Bericht ablegen und als Text zurückgeben. */
    private function BerichtSchreiben(array $bericht): string
    {
        @$this->WriteAttributeString(self::A_BERICHT, (string)json_encode($bericht, JSON_UNESCAPED_UNICODE));
        $text = $this->BerichtAlsText($bericht);
        $this->LogMessage('SymDo Setup: ' . str_replace("\n", ' | ', $text), KL_NOTIFY);
        $this->SendDebug('Setup', $text, 0);
        return $text;
    }

    private function BerichtText(): string
    {
        $roh = json_decode($this->AttributLesen(self::A_BERICHT, ''), true);
        if (!is_array($roh) || ($roh['zeilen'] ?? []) === []) {
            return $this->Translate('The assistant has not run yet.');
        }
        return $this->BerichtAlsText($roh);
    }

    private function BerichtAlsText(array $bericht): string
    {
        $zeilen = array_map('strval', (array)($bericht['zeilen'] ?? []));
        $kopf = sprintf($this->Translate('Last run: %s'),
            date('d.m.Y H:i', (int)($bericht['at'] ?? time())));
        $fuss = [];
        foreach ((array)($bericht['kennungen'] ?? []) as $name => $id) {
            $fuss[] = $name . ': ' . $id;
        }
        $text = $kopf . "\n" . implode("\n", $zeilen);
        if ($fuss !== []) {
            $text .= "\n" . sprintf($this->Translate('Member IDs: %s'), implode(', ', $fuss));
        }
        if ((string)($bericht['abbruch'] ?? '') === '') {
            $text .= "\n" . $this->Translate('Running the assistant again is harmless — what exists is recognised and never duplicated.');
        }
        return $text;
    }

    // ─────────────────────────── Puffer und Attribute ────────────────────────

    private function Antworten(): array
    {
        $roh = json_decode((string)$this->GetBuffer(self::P_ANTWORTEN), true);
        return is_array($roh) ? $roh : [];
    }

    private function AntwortSetzen(string $schluessel, mixed $wert): void
    {
        $a = $this->Antworten();
        $a[$schluessel] = $wert;
        $this->SetBuffer(self::P_ANTWORTEN, (string)json_encode($a, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Ein Attribut lesen, das es vielleicht noch nicht gibt: direkt nach dem
     * ersten Ausrollen fehlt es bis zum Kernel-Neustart, und ReadAttribute*
     * wirft dann eine Warnung in die AUSGABE — im Formularaufbau zerlegt das
     * die Antwort.
     */
    private function AttributLesen(string $name, string $vorgabe): string
    {
        try {
            $wert = @$this->ReadAttributeString($name);
            return is_string($wert) ? $wert : $vorgabe;
        } catch (\Throwable $e) {
            return $vorgabe;
        }
    }

    private function JsonArray(string $json): array
    {
        $roh = json_decode($json, true);
        return is_array($roh) ? $roh : [];
    }
}

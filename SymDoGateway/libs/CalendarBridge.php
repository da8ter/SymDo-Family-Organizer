<?php

declare(strict_types=1);

/**
 * Kalenderbereich — angedockt an das Store-Modul OpenCalendar (de.burki24.opencalendar).
 *
 * Die gesamte Anbieterseite bleibt dort: Apple iCloud, CalDAV, Google Calendar,
 * Microsoft 365 und ICS/Webcal samt OAuth, Zeitzonen, Wiederholungen und
 * Synchronisationsplan. Dieser Trait liest nur, was OpenCalendar bereits im Cache
 * hat, und liefert es der App in derselben Form wie alles andere.
 *
 * Gemessen an der installierten Fassung, nicht der Doku entnommen:
 *  - Je Kalender eine Instanz des Moduls „Kalender" (Praefix IPSKAL).
 *  - IPSKAL_GetCalendarStatus($id) → calendarId, calendarColor, canWrite,
 *    eventCount, todayEventCount, lastSynchronization, lastError.
 *  - Lesen ueber die seitenweise Uebertragung, im Quelltext ausdruecklich als
 *    „the preferred API for module consumers" bezeichnet, weil jede Seite unter
 *    Symcons Ausgabegrenze bleibt:
 *      IPSKAL_BeginEventsTransfer($id, $von, $bis) → {Token, PageCount, ItemCount, ExpiresAt}
 *      IPSKAL_ReadEventsTransferPage($id, $token, $seite) → {…, Items: [...]}
 *      IPSKAL_FinishEventsTransfer($id, $token)
 *  - Ereignisfelder: uid, summary, description, location, start, end,
 *    startTimestamp, endTimestamp, allDay, timezone (dazu id, etag, status).
 *
 * Zwei Grundsaetze, weil OpenCalendar ein FREMDES Modul ist:
 *  - Jeder Aufruf haengt hinter function_exists(). Eine fehlende Praefix-Funktion
 *    wirft in Symcon; ohne Wache wuerde die App abbrechen, nur weil ein Nutzer
 *    OpenCalendar nicht installiert hat.
 *  - Felder werden tolerant gelesen. Aendert OpenCalendar seine Struktur, fehlt
 *    hier ein Wert — es bricht nicht die Oberflaeche.
 */
trait CalendarBridge
{
    /** OpenCalendar, Modul „Kalender" — eine Instanz je Kalender. */
    private const CAL_MODULE_GUID = '{227B63E4-4223-316B-76E9-FD3849689562}';

    /** Obergrenze je Abfrage. Schuetzt Speicher und Oberflaeche vor einem Vieljahres-Kalender. */
    private const CAL_MAX_EVENTS = 400;

    /** Zeitraum, den die App hoechstens auf einmal anfragen darf (Tage). */
    private const CAL_MAX_RANGE_DAYS = 400;

    /** Obergrenzen: die Zuordnungen wachsen mit jedem angelegten Termin. */
    private const CAL_MEMBERS_MAX   = 500;
    private const CAL_REMINDERS_MAX = 300;

    /**
     * Hoechstzahl Einzeltermine, die aus EINER Reihe entstehen duerfen. Jeder davon
     * ist ein eigener Schreibzugriff beim Anbieter — ein Kurs mit „jede Woche, ohne
     * Ende" wuerde ohne Deckel hunderte Aufrufe ausloesen. 60 deckt ein Schuljahr
     * wochenweise ab. Der Deckel wird gemeldet und nicht verschwiegen.
     */
    private const CAL_SERIES_MAX = 60;

    /**
     * Die vier Arten von Jahresereignissen, die OpenCalendar kennt. Andere Werte
     * weist es zurueck; wir pruefen sie hier, damit die Meldung an den Nutzer
     * verstaendlich bleibt statt aus dem fremden Modul zu kommen.
     */
    private const CAL_ANNIVERSARY_TYPES = ['birthday', 'anniversary', 'wedding', 'death'];

    /**
     * Dauer eines Termins, wenn keine genannt ist (Sekunden). Eine Stunde ist die
     * Annahme, die auch Kalender-Apps treffen — sie muss hier nur ausdruecklich
     * getroffen werden, weil OpenCalendar ein Ende VERLANGT.
     */
    private const CAL_DEFAULT_DURATION = 3600;

    /**
     * Zuordnung und Erinnerung gehoeren NICHT in den Kalender.
     *
     * OpenCalendar schreibt aus einem Termin ein VEVENT mit Titel, Zeitraum, Ort und
     * Beschreibung — Mitglieder und Vorlaufzeiten kennt das Format an dieser Stelle
     * nicht, und ein fremdes Modul laesst sich dafuer nicht erweitern. Beides liegt
     * deshalb hier, verschluesselt ueber „Kalender-ID:UID": die UID liefert
     * OpenCalendar beim Anlegen zurueck und bleibt beim Anbieter stabil.
     */
    private function CalCreateProps(): void
    {
        // Ziel der Erinnerungen. Denselben Weg nimmt die ToDo-Liste
        // (VISU_PostNotification an eine Visualisierungsinstanz) — eine zweite
        // Zustellart waere ein zweiter Ort, an dem Erinnerungen ausfallen koennen.
        $this->RegisterPropertyInteger('CalNotifyVisuID', 0);
        $this->RegisterAttributeString('CalMembers', '{}');
        $this->RegisterAttributeString('CalReminders', '{}');
        $this->RegisterTimer('CalNotify', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'CalNotify\', 0);');
    }

    private function CalApplyChanges(): void
    {
        // Eine Minute: feiner als die Vorlaufzeiten, die zur Auswahl stehen, und
        // billig — der Lauf liest nur ein Attribut.
        try {
            $this->SetTimerInterval('CalNotify', $this->CalReminderStore() === [] ? 0 : 60000);
        } catch (Throwable $e) {
            $this->SendDebug('Calendar', 'Erinnerungs-Timer fehlt noch', 0);
        }
    }

    private function CalRequestAction(string $Ident, mixed $Value): bool
    {
        if ($Ident === 'CalNotify') {
            $this->CalProcessReminders();
            return true;
        }
        return false;
    }

    /** @return array<string, mixed> */
    private function CalMemberStore(): array
    {
        try {
            $d = json_decode((string)@$this->ReadAttributeString('CalMembers'), true);
            return is_array($d) ? $d : [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /** @return array<string, mixed> */
    private function CalReminderStore(): array
    {
        try {
            $d = json_decode((string)@$this->ReadAttributeString('CalReminders'), true);
            return is_array($d) ? $d : [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Schreibt und prueft zurueck — ein Attribut, das der Kernel nicht kennt, schluckt still. */
    private function CalWriteStore(string $name, array $daten, int $max): bool
    {
        if (count($daten) > $max) {
            $daten = array_slice($daten, -$max, null, true);
        }
        $json = json_encode($daten, JSON_UNESCAPED_UNICODE);
        @$this->WriteAttributeString($name, $json);
        try {
            if ((string)@$this->ReadAttributeString($name) === $json) {
                /* Signal fuer die anderen Geraete. ACHTUNG, Reichweite: hier liegen
                   nur UNSERE Zusaetze (Erinnerungen, Zuordnungen). Die Termine
                   selbst stehen in OpenCalendar — ein Termin, den jemand dort oder
                   in einem fremden Kalender anlegt, laeuft nie durch diese Stelle
                   und kommt weiter nur ueber den Minutentakt im offenen Bereich. */
                $this->WsPushDirty();
                return true;
            }
        } catch (Throwable $e) {
            // faellt unten durch
        }
        $this->LogMessage(sprintf(
            'SymDo: Attribut %s ist nicht speicherbar — der Symcon-Kernel muss nach dem Modul-Update einmal neu gestartet werden.',
            $name
        ), KL_ERROR);
        return false;
    }

    /**
     * Faellige Erinnerungen zustellen. Gleicher Weg wie bei den Aufgaben:
     * VISU_PostNotification an die eingestellte Visualisierungsinstanz.
     */
    private function CalProcessReminders(): void
    {
        $liste = $this->CalReminderStore();
        if ($liste === []) {
            try {
                $this->SetTimerInterval('CalNotify', 0);
            } catch (Throwable $e) {
            }
            return;
        }
        $visuID = (int)$this->CalProp('CalNotifyVisuID', 0);
        $jetzt = time();
        $geaendert = false;

        foreach ($liste as $schluessel => $eintrag) {
            if (!is_array($eintrag)) {
                unset($liste[$schluessel]);
                $geaendert = true;
                continue;
            }
            $start = (int)($eintrag['start'] ?? 0);
            // Aufgeraeumt wird nach dem Termin, nicht nach der Erinnerung — sonst
            // waechst die Liste bei abgeschalteter Zustellung endlos. Gehoert der
            // Eintrag zu einer Serie, wandert er stattdessen auf das naechste
            // Vorkommen weiter; verworfen wird er erst, wenn die Serie zu Ende ist.
            if ($start > 0 && $jetzt > $start + 86400) {
                $weiter = $this->CalReminderAdvance($eintrag, $jetzt);
                if ($weiter === null) {
                    unset($liste[$schluessel]);
                } else {
                    $liste[$schluessel] = $weiter;
                }
                $geaendert = true;
                continue;
            }
            if (($eintrag['sent'] ?? false) === true) {
                continue;
            }
            $wann = $start - max(0, (int)($eintrag['lead'] ?? 0));
            if ($jetzt < $wann || $start <= 0) {
                continue;
            }
            if ($visuID <= 0 || !function_exists('VISU_PostNotification')) {
                continue; // kein Ziel eingestellt bzw. keine Kachel-Visu installiert: liegen lassen, nicht verwerfen
            }
            $titel = $this->Translate('Appointment');
            $lead = max(0, (int)($eintrag['lead'] ?? 0));
            if ($lead > 0) {
                $titel = sprintf($this->Translate('Appointment in %s'), $this->CalLeadText($lead));
            }
            // try/catch zwingend: eine geloeschte oder falsch gewaehlte Instanz
            // laesst den Aufruf werfen — ungefangen braeche der Minutentimer hier
            // jede Minute ab und keine weitere Erinnerung wuerde zugestellt.
            try {
                $ergebnis = @VISU_PostNotification($visuID, mb_substr($titel, 0, 32),
                    mb_substr((string)($eintrag['title'] ?? ''), 0, 256), 'Info', $this->InstanceID);
            } catch (Throwable $e) {
                $this->SendDebug('CalNotify', sprintf('Zustellung an #%d fehlgeschlagen: %s', $visuID, $e->getMessage()), 0);
                continue;
            }
            if ($ergebnis !== false) {
                $liste[$schluessel]['sent'] = true;
                $geaendert = true;
            }
        }

        if ($geaendert) {
            $this->CalWriteStore('CalReminders', $liste, self::CAL_REMINDERS_MAX);
        }
        if ($liste === []) {
            try {
                $this->SetTimerInterval('CalNotify', 0);
            } catch (Throwable $e) {
            }
        }
    }

    /**
     * Den Erinnerungs-Eintrag einer Serie auf das naechste Vorkommen setzen, das
     * noch bevorsteht. Null heisst: die Serie ist zu Ende, der Eintrag darf weg.
     *
     * Gerechnet wird auf der ORTSZEIT (strtotime mit „+7 days" behaelt die Uhrzeit
     * ueber die Zeitumstellung). Monatlich wird auf die Laenge des Zielmonats
     * begrenzt — sonst wuerde aus dem 31. Januar der 3. Maerz.
     *
     * @param array<string, mixed> $eintrag
     * @return array<string, mixed>|null
     */
    private function CalReminderAdvance(array $eintrag, int $jetzt): ?array
    {
        $serie = $eintrag['series'] ?? null;
        if (!is_array($serie)) {
            return null;
        }
        $freq  = (string)($serie['freq'] ?? '');
        $bis   = trim((string)($serie['until'] ?? ''));
        $rest  = array_key_exists('left', $serie) && $serie['left'] !== null ? (int)$serie['left'] : null;
        $start = (int)($eintrag['start'] ?? 0);
        if ($start <= 0 || !in_array($freq, ['weekly', 'biweekly', 'monthly'], true)) {
            return null;
        }

        // Ein Deckel gegen eine Regel, die nie in die Zukunft laeuft: lieber den
        // Eintrag verlieren als den Minutentimer in einer Endlosschleife.
        for ($runde = 0; $runde < 500; $runde++) {
            if ($rest !== null && $rest <= 0) {
                return null;
            }
            if ($freq === 'monthly') {
                $tag     = (int)date('j', $start);
                $monat   = (int)date('n', $start) + 1;
                $jahr    = (int)date('Y', $start) + intdiv($monat - 1, 12);
                $monat   = (($monat - 1) % 12) + 1;
                $letzter = (int)date('t', (int)mktime(12, 0, 0, $monat, 1, $jahr));
                $start   = (int)mktime((int)date('H', $start), (int)date('i', $start), 0,
                                       $monat, min($tag, $letzter), $jahr);
            } else {
                $start = (int)strtotime($freq === 'biweekly' ? '+14 days' : '+7 days', $start);
            }
            if ($rest !== null) {
                $rest--;
            }
            if ($bis !== '' && date('Y-m-d', $start) > $bis) {
                return null;
            }
            if ($jetzt <= $start + 86400) {
                $eintrag['start'] = $start;
                // Wieder scharf: das naechste Vorkommen hat noch nicht gemeldet.
                $eintrag['sent'] = false;
                $eintrag['series']['left'] = $rest;
                return $eintrag;
            }
        }
        return null;
    }

    private function CalLeadText(int $sekunden): string
    {
        if ($sekunden % 86400 === 0) {
            return sprintf($this->Translate('%d d'), intdiv($sekunden, 86400));
        }
        if ($sekunden % 3600 === 0) {
            return sprintf($this->Translate('%d h'), intdiv($sekunden, 3600));
        }
        return sprintf($this->Translate('%d min'), max(1, intdiv($sekunden, 60)));
    }

    /** Eigenschaft lesen, die es vielleicht noch nicht gibt (neu in Create()). */
    private function CalProp(string $name, mixed $vorgabe): mixed
    {
        $cfg = json_decode((string)@IPS_GetConfiguration($this->InstanceID), true);
        return (is_array($cfg) && array_key_exists($name, $cfg)) ? $cfg[$name] : $vorgabe;
    }

    /** Ist OpenCalendar installiert und mindestens ein Kalender eingerichtet? */
    private function CalAvailable(): bool
    {
        return function_exists('IPSKAL_GetCalendarStatus') && $this->CalInstanceIDs() !== [];
    }

    /** @return list<int> */
    /**
     * Geburtstage und Jahrestage, die IM KALENDER gepflegt sind, fuer einen Tag.
     *
     * Das Briefing kannte Geburtstage bisher nur aus den Stammdaten der
     * Familienmitglieder. Wer sie in OpenCalendar pflegt — und wer ueberhaupt
     * Hochzeits-, Jahres- oder Todestage fuehrt —, kam darin nicht vor.
     *
     * Doppelte werden weggelassen: steht derselbe Name schon in den Stammdaten,
     * gewinnt die Stammdaten-Zeile. Sonst gratulierte das Briefing zweimal.
     *
     * @param string $datum      Y-m-d, der Tag um den es geht
     * @param string $wort       „heute" oder „morgen", wie im uebrigen Briefing
     * @param list<string> $schonGenannt Namen, die bereits eine Zeile haben
     * @return list<string>
     */
    private function CalAnnualLines(string $datum, string $wort, array $schonGenannt = []): array
    {
        if (!function_exists('IPSKAL_GetAnniversaryList')) {
            return [];
        }
        $klein = array_map('mb_strtolower', $schonGenannt);
        $raus  = [];
        foreach ($this->CalInstanceIDs() as $id) {
            try {
                // 0 Tage = alles; gefiltert wird unten auf den GENAUEN Tag. Ein
                // Fenster waere hier falsch: die Abendvorschau spricht ueber
                // morgen, nicht ueber „die naechsten sieben Tage".
                $roh = json_decode((string)IPSKAL_GetAnniversaryList($id, 0, ''), true);
            } catch (Throwable $e) {
                $this->SendDebug('Calendar', 'GetAnniversaryList geworfen: ' . $e->getMessage(), 0);
                continue;
            }
            foreach ((array)$roh as $e) {
                if (!is_array($e) || (string)($e['nextDate'] ?? '') !== $datum) {
                    continue;
                }
                $name = trim((string)($e['name'] ?? ''));
                if ($name === '' || in_array(mb_strtolower($name), $klein, true)) {
                    continue;
                }
                $jahre = (int)($e['years'] ?? 0);
                switch ((string)($e['anniversaryType'] ?? '')) {
                    case 'birthday':
                        $raus[] = $jahre > 0
                            ? sprintf('%s wird %s %d', $name, $wort, $jahre)
                            : sprintf('%s hat %s Geburtstag', $name, $wort);
                        break;
                    case 'wedding':
                        $raus[] = $jahre > 0
                            ? sprintf('%s: %s %d. Hochzeitstag', $name, $wort, $jahre)
                            : sprintf('%s: %s Hochzeitstag', $name, $wort);
                        break;
                    case 'death':
                        // Bewusst ohne Jahreszahl und ohne Gratulation — die
                        // Tonregel im Briefing sagt „gratuliere zuerst", und das
                        // waere hier grob daneben.
                        $raus[] = sprintf('%s: %s Todestag (KEIN Anlass zu gratulieren)', $name, $wort);
                        break;
                    default:
                        $raus[] = $jahre > 0
                            ? sprintf('%s: %s %d. Jahrestag', $name, $wort, $jahre)
                            : sprintf('%s: %s Jahrestag', $name, $wort);
                }
            }
        }
        return $raus;
    }

    private function CalInstanceIDs(): array
    {
        $ids = @IPS_GetInstanceListByModuleID(self::CAL_MODULE_GUID);
        if (!is_array($ids)) {
            return [];
        }
        sort($ids);
        return array_values(array_map('intval', $ids));
    }

    /**
     * Kalenderliste mit Zustand. `lastError` wird bewusst mitgeliefert: eine stumme,
     * seit Tagen nicht mehr synchronisierende Quelle ist schlimmer als eine
     * sichtbare Fehlermeldung.
     *
     * @return list<array<string, mixed>>
     */
    private function CalCalendars(): array
    {
        if (!$this->CalAvailable()) {
            return [];
        }
        $raus = [];
        foreach ($this->CalInstanceIDs() as $id) {
            $status = [];
            try {
                $roh = IPSKAL_GetCalendarStatus($id);
                $status = is_string($roh) ? (json_decode($roh, true) ?: []) : [];
            } catch (Throwable $e) {
                $this->SendDebug('Calendar', sprintf('#%d Status nicht lesbar: %s', $id, $e->getMessage()), 0);
            }
            $raus[] = [
                'id'        => $id,
                'name'      => (string)@IPS_GetName($id),
                'color'     => (string)($status['calendarColor'] ?? ''),
                'canWrite'  => (bool)($status['canWrite'] ?? false),
                // BEWUSST keine canUpdateOccurrence/canDeleteOccurrence von hier:
                // die Instanz-Statusabfrage meldet false, obwohl der Schreibzugriff
                // geht — massgeblich ist das Flag AM TERMIN-Datensatz (am 18.08.2026
                // live gemessen). Siehe CalNormalize.
                /* Darf dieser Kalender ECHTE Serien anlegen? Bis Build 750 legten
                   wir aus einer Reihe bis zu 60 Einzeltermine an, weil wir den
                   falschen Schluessel geprueft hatten (`recurrenceRule` statt
                   `recurrence`). Wo der Anbieter es kann, entsteht jetzt EINE
                   Serie; wo nicht, bleibt der alte Weg. */
                'canCreateRecurrence' => (bool)($status['canCreateRecurrence'] ?? false),
                'count'     => (int)($status['eventCount'] ?? 0),
                'today'     => (int)($status['todayEventCount'] ?? 0),
                'lastSync'  => (int)($status['lastSynchronization'] ?? 0),
                'lastError' => (string)($status['lastError'] ?? ''),
            ];
        }
        return $raus;
    }

    /**
     * Termine aus einem oder mehreren Kalendern, nach Beginn sortiert.
     *
     * @param list<int> $nurIDs Leer = alle eingerichteten Kalender.
     * @return array{events: list<array<string, mixed>>, truncated: bool}
     */
    private function CalEvents(int $von, int $bis, array $nurIDs = []): array
    {
        if (!$this->CalAvailable()) {
            return ['events' => [], 'truncated' => false];
        }
        $ids = $this->CalInstanceIDs();
        if ($nurIDs !== []) {
            $ids = array_values(array_intersect($ids, array_map('intval', $nurIDs)));
        }

        $alle = [];
        $gekappt = false;
        // Einmal lesen, nicht je Kalender: beides sind Attribute dieser Instanz.
        $mitglieder   = $this->CalMemberStore();
        $erinnerungen = $this->CalReminderStore();
        foreach ($ids as $id) {
            foreach ($this->CalReadOne($id, $von, $bis, $mitglieder, $erinnerungen) as $e) {
                if (count($alle) >= self::CAL_MAX_EVENTS) {
                    $gekappt = true;
                    break 2;
                }
                $alle[] = $e;
            }
        }
        usort($alle, static function (array $a, array $b): int {
            return [$a['start'], $a['title']] <=> [$b['start'], $b['title']];
        });
        return ['events' => $alle, 'truncated' => $gekappt];
    }

    /**
     * Ein Kalender, seitenweise, in OpenCalendars eigener Form. Der Token wird IMMER
     * wieder abgeraeumt — auch wenn mitten in der Uebertragung etwas schiefgeht.
     *
     * Rohdaten und nicht CalNormalize-Form, weil Aendern und Loeschen den fremden
     * Datensatz zurueckgeben muessen (siehe CalFindRaw). Fuer die Oberflaeche legt
     * CalReadOne die eigene Form darueber.
     *
     * @return list<array<string, mixed>>
     */
    private function CalRawItems(int $id, int $von, int $bis): array
    {
        $token = '';
        $raus  = [];
        try {
            $meta = json_decode((string)IPSKAL_BeginEventsTransfer($id, $von, $bis), true);
            if (!is_array($meta)) {
                return [];
            }
            $token  = (string)($meta['Token'] ?? '');
            $seiten = (int)($meta['PageCount'] ?? 0);
            for ($p = 0; $p < $seiten; $p++) {
                $seite = json_decode((string)IPSKAL_ReadEventsTransferPage($id, $token, $p), true);
                foreach ((array)($seite['Items'] ?? []) as $e) {
                    if (!is_array($e)) {
                        continue;
                    }
                    $raus[] = $e;
                    if (count($raus) >= self::CAL_MAX_EVENTS) {
                        return $raus;
                    }
                }
            }
        } catch (Throwable $e) {
            $this->SendDebug('Calendar', sprintf('#%d Termine nicht lesbar: %s', $id, $e->getMessage()), 0);
        } finally {
            if ($token !== '') {
                try {
                    IPSKAL_FinishEventsTransfer($id, $token);
                } catch (Throwable $e) {
                    // Der Token verfaellt ohnehin (ExpiresAt) — kein Grund zu laermen.
                }
            }
        }
        return $raus;
    }

    /**
     * Ein Kalender in unserer Form.
     *
     * @return list<array<string, mixed>>
     */
    private function CalReadOne(int $id, int $von, int $bis, array $mitglieder = [], array $erinnerungen = []): array
    {
        $raus = [];
        foreach ($this->CalRawItems($id, $von, $bis) as $e) {
            $raus[] = $this->CalNormalize($e, $id, $mitglieder, $erinnerungen);
        }
        return $raus;
    }

    /**
     * Fremdes Ereignis auf unsere Form bringen. Bewusst wenige, klar benannte Felder:
     * die Oberflaeche soll nicht von OpenCalendars Struktur abhaengen.
     *
     * @param array<string, mixed> $e
     * @return array<string, mixed>
     */
    private function CalNormalize(array $e, int $calendarID, array $mitglieder = [], array $erinnerungen = []): array
    {
        $start = (int)($e['startTimestamp'] ?? 0);
        $ende  = (int)($e['endTimestamp'] ?? $start);
        if ($ende < $start) {
            $ende = $start;
        }
        $zeile = [
            'id'         => (string)($e['id'] ?? ($e['uid'] ?? '')),
            'uid'        => (string)($e['uid'] ?? ''),
            'calendarID' => $calendarID,
            'title'      => trim((string)($e['summary'] ?? '')),
            'info'       => trim((string)($e['description'] ?? '')),
            'location'   => trim((string)($e['location'] ?? '')),
            'start'      => $start,
            'end'        => $ende,
            'allDay'     => (bool)($e['allDay'] ?? false),
            // Zuordnung aus unserer eigenen Ablage; der Kalender kennt sie nicht.
            'members'    => (array)($mitglieder[$calendarID . ':' . (string)($e['uid'] ?? '')] ?? []),
            // Vorlaufzeit der Erinnerung, -1 = keine. Muss mitkommen, damit der
            // Bearbeiten-Dialog sie vorbelegen kann — sonst haette ein Speichern
            // die Erinnerung stillschweigend auf „keine" gesetzt.
            'reminder'   => (int)($erinnerungen[$calendarID . ':' . (string)($e['uid'] ?? '')]['lead'] ?? -1),
            // Teil einer Serie? Die Oberflaeche sperrt damit Bearbeiten/Loeschen,
            // wenn dieses Vorkommen nicht einzeln beschreibbar ist. Massgeblich
            // sind die Flags AM DATENSATZ — die Instanz-Statusabfrage meldet false,
            // obwohl der Schreibzugriff geht (am 18.08.2026 live gemessen).
            'recurring'  => $this->CalIsOccurrence($e),
            'canUpdateOccurrence' => ($e['canUpdateOccurrence'] ?? false) === true,
            'canDeleteOccurrence' => ($e['canDeleteOccurrence'] ?? false) === true,
            /* Seit Build 750 meldet der Anbieter auch, ob „dieser und alle
               folgenden" und „die ganze Serie" erlaubt sind. Wir reichten das
               nicht durch — die App konnte deshalb immer nur EIN Vorkommen
               aendern, obwohl mehr erlaubt war. */
            'canUpdateFollowing'  => ($e['canUpdateFollowing'] ?? false) === true,
            'canUpdateSeries'     => ($e['canUpdateSeries'] ?? false) === true,
            'canDeleteSeries'     => ($e['canDeleteSeries'] ?? false) === true,
        ];

        /* Jahresereignis (Geburtstag, Jahrestag, Hochzeits-, Todestag). Die Art
           steht NICHT beim Anbieter, sondern in einer Ablage der Kalender-Instanz;
           OpenCalendar haengt sie beim Lesen an jeden Datensatz. Nur mitschicken,
           wenn es eines ist — sonst traegt jeder gewoehnliche Termin drei leere
           Felder durch die Leitung. */
        $art = trim((string)($e['anniversaryType'] ?? ''));
        if (in_array($art, self::CAL_ANNIVERSARY_TYPES, true)) {
            $zeile['anniversaryType'] = $art;
            $zeile['anniversaryDate'] = trim((string)($e['anniversaryDate'] ?? ''));
            // Wie viele Jahre dieses Vorkommen zaehlt — rechnet OpenCalendar aus
            // dem Jahr des Vorkommens gegen das Ursprungsjahr.
            $zeile['years'] = (int)($e['years'] ?? 0);
        }
        return $zeile;
    }

    /**
     * Gehoert der Rohdatensatz zu einer Serie? Das `recurring`-Flag traegt der
     * Serienkopf, ein ausgerechnetes Vorkommen hilfsweise die recurrenceId.
     *
     * @param array<string, mixed> $e
     */
    private function CalIsOccurrence(array $e): bool
    {
        return ($e['recurring'] ?? false) === true
            || trim((string)($e['recurrenceId'] ?? '')) !== ''
            || trim((string)($e['occurrenceId'] ?? '')) !== '';
    }

    /**
     * Ist der Datensatz ein Jahresereignis? Massgeblich ist der Wert, der beim
     * Schreiben gilt: die Oberflaeche kann ihn setzen (dann steht er in den
     * geprueften Feldern) oder abwaehlen (dann steht dort ein leerer Wert, der den
     * Wert aus dem Rohdatensatz ueberschreibt).
     *
     * @param array<string, mixed> $e
     */
    private function CalIsAnniversary(array $e): bool
    {
        return in_array(trim((string)($e['anniversaryType'] ?? '')), self::CAL_ANNIVERSARY_TYPES, true);
    }

    /**
     * Eingaben der Oberflaeche auf die Felder bringen, die OpenCalendar erwartet.
     *
     * Gemeinsame Quelle fuer Anlegen und Aendern: liefen die Pruefungen doppelt,
     * wuerde ein Datum, das beim Anlegen abgelehnt wird, beim Aendern durchgehen.
     *
     * @param array<string, mixed> $daten
     * @return array{ok: bool, code?: string, message?: string, fields?: array<string, mixed>}
     */
    private function CalInputFields(array $daten): array
    {
        $fehler = static fn(string $code, string $text): array => ['ok' => false, 'code' => $code, 'message' => $text];

        $titel = trim((string)($daten['title'] ?? ''));
        if ($titel === '') {
            return $fehler('invalid_payload', $this->Translate('The appointment needs a title.'));
        }
        /* Jahresereignis? Dann bestimmt das Ursprungsdatum alles Weitere.
           OpenCalendar baut daraus selbst einen ganztaegigen, jaehrlich
           wiederkehrenden Termin ohne Ende (applyAnniversaryEventDefaults) —
           Beginn, Ende und „ganztaegig" aus der Oberflaeche waeren hier also
           bestenfalls wirkungslos und schlimmstenfalls widerspruechlich. Wir
           rechnen sie trotzdem aus und schicken sie mit, damit der Datensatz auch
           dann stimmt, wenn das fremde Modul seine Vorgabe einmal aendert.

           Der Schluessel muss VORHANDEN sein, damit ein leerer Wert etwas bedeutet:
           „ist keiner (mehr)". Fehlt er ganz, sagt die Oberflaeche nichts dazu und
           ein bestehendes Jahresereignis bleibt unangetastet — sonst loeschte jede
           Aenderung aus einem aelteren Client die Einordnung. */
        $jahrestag = null;
        if (array_key_exists('anniversaryType', $daten)) {
            $art = strtolower(trim((string)$daten['anniversaryType']));
            if ($art !== '' && !in_array($art, self::CAL_ANNIVERSARY_TYPES, true)) {
                return $fehler('invalid_payload', $this->Translate('This kind of annual event is unknown.'));
            }
            $jahrestag = ['type' => $art, 'date' => ''];
            if ($art !== '') {
                $datum = trim((string)($daten['anniversaryDate'] ?? ''));
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum) !== 1 || $datum > date('Y-m-d')) {
                    // OpenCalendar verlangt ein Datum in der Vergangenheit: gezaehlt
                    // werden Jahre SEIT dem Ereignis.
                    return $fehler('invalid_payload', $this->Translate('The date of the annual event must be in the past.'));
                }
                $jahrestag['date'] = $datum;
            }
        }
        if ($jahrestag !== null && $jahrestag['type'] !== '') {
            return ['ok' => true, 'fields' => [
                'summary'         => $titel,
                'start'           => $jahrestag['date'],
                'end'             => date('Y-m-d', (int)strtotime($jahrestag['date'] . ' +1 day')),
                'allDay'          => true,
                'description'     => trim((string)($daten['info'] ?? '')),
                'location'        => trim((string)($daten['location'] ?? '')),
                'anniversaryType' => $jahrestag['type'],
                'anniversaryDate' => $jahrestag['date'],
            ]];
        }

        $ganztags = ($daten['allDay'] ?? false) === true;
        $start = trim((string)($daten['start'] ?? ''));
        $ende  = trim((string)($daten['end'] ?? ''));
        // Ganztaegig erwartet Y-m-d, mit Uhrzeit einen vollstaendigen Zeitpunkt.
        // OpenCalendar wirft bei ungueltigen Angaben; hier vorher pruefen, damit die
        // Meldung an den Nutzer verstaendlich bleibt.
        $musterTag  = '/^\d{4}-\d{2}-\d{2}$/';
        $musterZeit = '/^\d{4}-\d{2}-\d{2}[T ][0-2]\d:[0-5]\d/';
        if ($ganztags ? preg_match($musterTag, $start) !== 1 : preg_match($musterZeit, $start) !== 1) {
            return $fehler('invalid_payload', $this->Translate('The appointment start is invalid.'));
        }
        if ($ende !== '' && ($ganztags ? preg_match($musterTag, $ende) !== 1 : preg_match($musterZeit, $ende) !== 1)) {
            return $fehler('invalid_payload', $this->Translate('The appointment end is invalid.'));
        }

        $felder = [
            'summary'     => $titel,
            'start'       => $start,
            'allDay'      => $ganztags,
            'description' => trim((string)($daten['info'] ?? '')),
            'location'    => trim((string)($daten['location'] ?? '')),
        ];
        if ($ende !== '') {
            // OpenCalendar verlangt Ende NACH dem Beginn. Bei einem ganztaegigen
            // Termin ist das Ende exklusiv — der letzte Tag muss also einen Tag
            // weitergerechnet werden, sonst fehlt er im Kalender.
            $felder['end'] = $ganztags
                ? date('Y-m-d', (int)strtotime($ende . ' +1 day'))
                : $ende;
        } else {
            // OHNE Ende lehnt OpenCalendar ab: „Terminbeginn und -ende muessen
            // gemeinsam geaendert werden." (am 18.08.2026 ueber die REST-API
            // gemessen). Betroffen war jeder Vorschlag ohne Enddatum — also die
            // Mehrheit — und jeder Termin einer Reihe. Deshalb hier eine Dauer
            // setzen statt sie dem Anbieter zu ueberlassen: eine Stunde mit
            // Uhrzeit, ein Tag ganztaegig (Ende exklusiv).
            $felder['end'] = $ganztags
                ? date('Y-m-d', (int)strtotime($start . ' +1 day'))
                : date('Y-m-d\TH:i', (int)strtotime($start) + self::CAL_DEFAULT_DURATION);
        }
        if ($jahrestag !== null) {
            // Ausdruecklich abgewaehlt: der leere Wert nimmt OpenCalendar die
            // Einordnung wieder ab. Der Termin selbst bleibt, was er ist.
            $felder['anniversaryType'] = '';
        }
        return ['ok' => true, 'fields' => $felder];
    }

    /**
     * Legt einen Termin an.
     *
     * Geschrieben wird ausschliesslich auf ausdrueckliche Bestaetigung des Nutzers;
     * es gibt keinen Weg, auf dem hier von allein ein Termin entsteht. Der Eintrag
     * landet beim Anbieter (Google, iCloud, CalDAV) und ist damit fuer alle
     * sichtbar, die den Kalender teilen — deshalb wird vorher geprueft, ob der
     * Kalender ueberhaupt beschreibbar ist, statt es zu versuchen und den Fehler
     * des Anbieters durchzureichen. Dasselbe gilt fuer CalUpdateEvent und
     * CalDeleteEvent weiter unten.
     *
     * @param array<string, mixed> $daten
     * @return array<string, mixed>
     */
    private function CalCreateEvent(int $calendarID, array $daten): array
    {
        $fehler = static fn(string $code, string $text): array =>
            ['ok' => false, 'error' => ['code' => $code, 'message' => $text]];

        // Reihe? Dann wird daraus eine Folge von Einzelterminen. Echte Serien kann
        // OpenCalendar nicht anlegen (`recurrenceRule` wird beim Anlegen verworfen —
        // am 17.08.2026 in beiden Schreibweisen geprueft), Einzeltermine schon.
        // Ein Jahresereignis bringt seinen Takt selbst mit (jaehrlich, ohne Ende) —
        // eine zusaetzliche Reihe waere ein zweiter Takt auf demselben Termin.
        $eigenerTakt = trim((string)($daten['anniversaryType'] ?? '')) !== '';
        $reihe = $eigenerTakt ? null : $this->CalSeriesRule($daten['recurrence'] ?? null);
        if ($reihe !== null) {
            return $this->CalCreateSeries($calendarID, $daten, $reihe);
        }

        $wache = $this->CalWritableOne($calendarID, 'IPSKAL_CreateEvent');
        if (($wache['ok'] ?? false) !== true) {
            return $fehler((string)$wache['code'], (string)$wache['message']);
        }
        return $this->CalCreateEventChecked($calendarID, $daten, $wache['calendar']);
    }

    /**
     * Anlegen OHNE die Kalender-Wache — die hat der Aufrufer bereits erledigt.
     * Getrennt, damit eine Reihe die Statusabfrage aller Kalender nicht je Termin
     * wiederholt (bis zu 60 Termine in EINEM Hook-Aufruf).
     *
     * @param array<string, mixed> $daten
     * @param array<string, mixed> $kalender Zeile aus CalCalendars
     * @return array<string, mixed>
     */
    private function CalCreateEventChecked(int $calendarID, array $daten, array $kalender, array $zusatz = []): array
    {
        $fehler = static fn(string $code, string $text): array =>
            ['ok' => false, 'error' => ['code' => $code, 'message' => $text]];

        $geprueft = $this->CalInputFields($daten);
        if (($geprueft['ok'] ?? false) !== true) {
            return $fehler((string)$geprueft['code'], (string)$geprueft['message']);
        }
        // $zusatz traegt Felder, die nicht aus der Oberflaeche kommen, sondern hier
        // errechnet werden — heute die Wiederholungsregel einer echten Serie.
        $ereignis = $zusatz === [] ? $geprueft['fields'] : array_merge($geprueft['fields'], $zusatz);
        $titel    = (string)$ereignis['summary'];
        $ganztags = (bool)$ereignis['allDay'];
        $start    = (string)$ereignis['start'];

        try {
            $roh = IPSKAL_CreateEvent($calendarID, json_encode($ereignis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $antwort = json_decode((string)$roh, true);
        } catch (Throwable $e) {
            $this->SendDebug('Calendar', 'CreateEvent geworfen: ' . $e->getMessage(), 0);
            return $fehler('calendar_error', $e->getMessage());
        }
        if (!is_array($antwort) || ($antwort['success'] ?? false) !== true) {
            $meldung = is_array($antwort) ? (string)($antwort['error'] ?? '') : '';
            $this->SendDebug('Calendar', 'CreateEvent abgelehnt: ' . $meldung, 0);
            return $fehler('calendar_error', $meldung !== '' ? $meldung : $this->Translate('The calendar rejected the appointment.'));
        }
        // UID des angelegten Termins: OpenCalendar liefert sie zurueck (beide Anbieter).
        // Ohne sie gibt es keinen Anker fuer Zuordnung und Erinnerung — dann wird der
        // Termin trotzdem als angelegt gemeldet, nur ohne die Zusaetze.
        $uid = trim((string)($antwort['event']['uid'] ?? ''));
        $mitglieder = [];
        foreach ((array)($daten['members'] ?? []) as $m) {
            $m = trim((string)$m);
            if ($m !== '') {
                $mitglieder[] = $m;
            }
        }
        $vorlauf = array_key_exists('reminder', $daten) ? (int)$daten['reminder'] : -1;

        if ($uid !== '' && ($mitglieder !== [] || $vorlauf >= 0)) {
            $schluessel = $calendarID . ':' . $uid;
            if ($mitglieder !== []) {
                $karte = $this->CalMemberStore();
                $karte[$schluessel] = array_values(array_unique($mitglieder));
                $this->CalWriteStore('CalMembers', $karte, self::CAL_MEMBERS_MAX);
            }
            if ($vorlauf >= 0) {
                $start = (int)strtotime($ganztags ? $start . ' 00:00' : $start);
                $erinnerungen = $this->CalReminderStore();
                $erinnerungen[$schluessel] = [
                    'start' => $start, 'lead' => $vorlauf, 'title' => $titel, 'sent' => false,
                ];
                if ($this->CalWriteStore('CalReminders', $erinnerungen, self::CAL_REMINDERS_MAX)) {
                    try {
                        $this->SetTimerInterval('CalNotify', 60000);
                    } catch (Throwable $e) {
                        $this->SendDebug('Calendar', 'Erinnerungs-Timer fehlt, Erinnerung bleibt liegen', 0);
                    }
                }
            }
        } elseif ($uid === '' && ($mitglieder !== [] || $vorlauf >= 0)) {
            $this->SendDebug('Calendar', 'Keine UID zurueckgemeldet — Zuordnung und Erinnerung entfallen', 0);
        }

        $this->LogMessage(sprintf(
            'SymDo: Termin „%s" in %s angelegt%s%s',
            $titel, (string)$kalender['name'],
            $mitglieder !== [] ? sprintf(' (%d Mitglied(er))', count($mitglieder)) : '',
            $vorlauf >= 0 ? ' (mit Erinnerung)' : ''
        ), KL_NOTIFY);
        return ['ok' => true, 'event' => $antwort['event'] ?? null];
    }

    /**
     * Wiederholungsangabe pruefen. Dieselbe Form, die AiParseRecurrence liefert —
     * hier noch einmal, weil der Rumpf auch aus der App kommen kann und nicht nur
     * aus einem Vorschlag.
     *
     * @return array{freq: string, count?: int, until?: string}|null
     */
    private function CalSeriesRule(mixed $roh): ?array
    {
        if (!is_array($roh)) {
            return null;
        }
        $freq = strtolower(trim((string)($roh['freq'] ?? '')));
        if (!in_array($freq, ['weekly', 'biweekly', 'monthly'], true)) {
            return null;
        }
        $count = (int)($roh['count'] ?? 0);
        if ($count > 1) {
            return ['freq' => $freq, 'count' => min($count, self::CAL_SERIES_MAX)];
        }
        $until = trim((string)($roh['until'] ?? ''));
        // checkdate wie in AiParseRecurrence: die Regex allein liesse 2026-13-45
        // durch, und strtotime macht daraus stillschweigend einen anderen Tag.
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $until, $m) === 1
            && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            return ['freq' => $freq, 'until' => $until];
        }
        return null;
    }

    /**
     * Die Einzeltermine einer Reihe ausrechnen.
     *
     * Bewusst hier und nicht im Sprachmodell: „dienstags, 15 Termine ab 01.09." ist
     * eine Rechnung, kein Textverstaendnis. Gerechnet wird auf dem TAG, die Uhrzeit
     * bleibt unberuehrt.
     *
     * Gerechnet wird immer vom ERSTEN Termin aus und nicht vom jeweils vorigen — so
     * kann sich kein Fehler aufaddieren.
     *
     * Bei "monthly" reicht strtotime dafuer nicht: „31.01. + 1 Monat" ergibt dort den
     * 03.03., weil der Februar keinen 31. hat und PHP ueberlaeuft. Der Monatstag wird
     * deshalb selbst gesetzt und auf den letzten Tag des Zielmonats begrenzt — aus dem
     * 31.01. wird der 28.02. und im Maerz wieder der 31.
     *
     * @param array{freq: string, count?: int, until?: string} $reihe
     * @return array{days: list<string>, capped: bool} Tage im Format Y-m-d (mindestens
     *         der erste); capped = das Bis-Datum haette mehr Termine ergeben als der
     *         Deckel CAL_SERIES_MAX erlaubt.
     */
    private function CalExpandSeries(string $ersterTag, array $reihe): array
    {
        // Mittag als Bezug: immun gegen die Zeitumstellung, die um Mitternacht einen
        // Tag kippen kann.
        $basis = strtotime($ersterTag . ' 12:00');
        if ($basis === false) {
            return ['days' => [$ersterTag], 'capped' => false];
        }
        $anzahl = isset($reihe['count']) ? min((int)$reihe['count'], self::CAL_SERIES_MAX) : self::CAL_SERIES_MAX;
        $bis    = isset($reihe['until']) ? (string)$reihe['until'] : '';

        $monatlich  = $reihe['freq'] === 'monthly';
        $tagImMonat = (int)date('j', $basis);
        $jahr0      = (int)date('Y', $basis);
        $monat0     = (int)date('n', $basis);
        $tageSchritt = $reihe['freq'] === 'biweekly' ? 14 : 7;

        $tage = [];
        $gekappt = false;
        // Eine Runde ueber den Deckel hinaus rechnen: nur so ist unterscheidbar, ob
        // eine Nur-bis-Reihe am Bis-Datum endet oder still am Deckel gekappt wurde.
        for ($n = 0; $n <= $anzahl; $n++) {
            if ($monatlich) {
                $lauf    = $monat0 - 1 + $n;
                $jahr    = $jahr0 + intdiv($lauf, 12);
                $monat   = ($lauf % 12) + 1;
                $letzter = (int)date('t', mktime(12, 0, 0, $monat, 1, $jahr));
                $zeit    = mktime(12, 0, 0, $monat, min($tagImMonat, $letzter), $jahr);
            } else {
                $zeit = $basis + $n * $tageSchritt * 86400;
            }
            $tag = date('Y-m-d', $zeit);
            if ($bis !== '' && $tag > $bis) {
                break;
            }
            if ($n === $anzahl) {
                // Der Termin haette noch ins Bis-Datum gepasst, faellt aber dem
                // Deckel zum Opfer. Nur bei Nur-bis-Reihen moeglich: mit count ist
                // $anzahl bereits auf den Deckel begrenzt.
                $gekappt = $bis !== '';
                break;
            }
            $tage[] = $tag;
        }
        return ['days' => $tage === [] ? [$ersterTag] : $tage, 'capped' => $gekappt];
    }

    /**
     * Eine Reihe anlegen: je Termin ein eigener Eintrag beim Anbieter.
     *
     * Teilerfolge werden gemeldet und nicht verschluckt — bricht der Anbieter nach
     * dem siebten von fuenfzehn ab, stehen sieben im Kalender, und genau das steht
     * dann auch in der Antwort. Ein stilles „ok" waere hier die schlechteste
     * Auskunft, weil niemand nachzaehlt.
     *
     * @param array<string, mixed> $daten
     * @param array{freq: string, count?: int, until?: string} $reihe
     * @return array<string, mixed>
     */
    private function CalCreateSeries(int $calendarID, array $daten, array $reihe): array
    {
        // Kalender-Wache und Eingabepruefung einmal VOR der Schleife: ein Tippfehler
        // soll nicht erst nach dem ersten geschriebenen Termin auffallen, und die
        // Statusabfrage aller Kalender nicht 60-mal laufen.
        $wache = $this->CalWritableOne($calendarID, 'IPSKAL_CreateEvent');
        if (($wache['ok'] ?? false) !== true) {
            return ['ok' => false, 'error' => ['code' => (string)$wache['code'], 'message' => (string)$wache['message']]];
        }
        $geprueft = $this->CalInputFields($daten);
        if (($geprueft['ok'] ?? false) !== true) {
            return ['ok' => false, 'error' => ['code' => (string)$geprueft['code'], 'message' => (string)$geprueft['message']]];
        }
        /* Der kurze Weg: EINE echte Serie statt vieler Einzeltermine. Moeglich,
           seit klar ist, dass OpenCalendar den Schluessel `recurrence` liest — wir
           hatten `recurrenceRule` geprueft, den es nirgends anfasst, und daraus
           geschlossen, es koenne keine Serien anlegen. Der Unterschied ist nicht
           kosmetisch: ein Schreibzugriff statt sechzig, eine Serie, die sich am
           Stueck aendern und loeschen laesst, und kein Deckel. */
        if (($wache['calendar']['canCreateRecurrence'] ?? false) === true) {
            return $this->CalCreateRealSeries($calendarID, $daten, $reihe, $wache['calendar'], $geprueft);
        }

        $start = (string)$geprueft['fields']['start'];
        $reihenPlan = $this->CalExpandSeries(substr($start, 0, 10), $reihe);
        $tage = $reihenPlan['days'];
        $uhr   = strlen($start) > 10 ? substr($start, 10) : '';   // „T18:45“ bleibt gleich

        $angelegt = 0;
        $letzterFehler = '';
        $erster = null;
        foreach ($tage as $tag) {
            // Ein Termin der Reihe: gleiche Angaben, nur der Tag wandert. `end` gibt
            // es hier nicht (siehe AiSeriesRule) — die Dauer setzt der Anbieter.
            $einzel = $daten;
            unset($einzel['recurrence'], $einzel['end']);
            $einzel['start'] = $tag . $uhr;
            $antwort = $this->CalCreateEventChecked($calendarID, $einzel, $wache['calendar']);
            if (($antwort['ok'] ?? false) === true) {
                $angelegt++;
                $erster = $erster ?? ($antwort['event'] ?? null);
                continue;
            }
            $letzterFehler = (string)($antwort['error']['message'] ?? '');
            $this->SendDebug('Calendar', sprintf('Reihe: %s fehlgeschlagen — %s', $tag, $letzterFehler), 0);
            break;
        }

        if ($angelegt === 0) {
            return ['ok' => false, 'error' => [
                'code'    => 'calendar_error',
                'message' => $letzterFehler !== '' ? $letzterFehler : $this->Translate('The calendar rejected the appointment.'),
            ]];
        }
        $this->LogMessage(sprintf(
            'SymDo: Reihe „%s" angelegt — %d von %d Termin(en)%s',
            (string)$geprueft['fields']['summary'],
            $angelegt,
            count($tage),
            $letzterFehler !== '' ? ' (abgebrochen: ' . $letzterFehler . ')' : ''
        ), $angelegt === count($tage) ? KL_NOTIFY : KL_WARNING);

        return [
            'ok'      => true,
            'created' => $angelegt,
            'planned' => count($tage),
            'event'   => $erster,
            // Nur gesetzt, wenn wirklich etwas schiefging — die Oberflaeche macht
            // daraus einen sichtbaren Hinweis statt einer Erfolgsmeldung.
            'partial' => $angelegt < count($tage) ? ($letzterFehler !== '' ? $letzterFehler : true) : null,
            // Eine Nur-bis-Reihe endet still am Deckel (CAL_SERIES_MAX), lange vor
            // ihrem Bis-Datum — das gehoert gesagt, sonst zaehlt niemand nach.
            'capped'  => $reihenPlan['capped'] ? self::CAL_SERIES_MAX : null,
        ];
    }

    /**
     * Beschreibbaren Kalender heraussuchen — gemeinsame Wache der drei schreibenden
     * Wege.
     *
     * @return array{ok: bool, code?: string, message?: string, calendar?: array<string, mixed>}
     */
    /**
     * Eine echte Serie: ein Termin, eine Wiederholungsregel.
     *
     * @param array<string, mixed> $daten     Eingabe der Oberflaeche
     * @param array<string, mixed> $reihe     Regel aus CalSeriesRule
     * @param array<string, mixed> $kalender  Zeile aus CalCalendars
     * @param array<string, mixed> $geprueft  Ergebnis von CalInputFields
     * @return array<string, mixed>
     */
    private function CalCreateRealSeries(int $calendarID, array $daten, array $reihe,
                                         array $kalender, array $geprueft): array
    {
        $antwort = $this->CalCreateEventChecked($calendarID, $daten, $kalender, [
            'recurrence' => $this->CalRecurrenceFields($reihe),
        ]);
        if (($antwort['ok'] ?? false) !== true) {
            return $antwort;
        }
        $this->LogMessage(sprintf(
            'SymDo: Serie „%s" in %s angelegt (%s)',
            (string)$geprueft['fields']['summary'],
            (string)$kalender['name'],
            $this->CalSeriesLog($reihe)
        ), KL_NOTIFY);

        return [
            'ok'      => true,
            'created' => 1,
            // Die Oberflaeche sagt damit „Serie angelegt" statt „1 Termin
            // hinzugefuegt" — es ist ein anderes Ergebnis.
            'series'  => true,
            'event'   => $antwort['event'] ?? null,
        ];
    }

    /**
     * Unsere Regel in die Form, die OpenCalendar erwartet.
     *
     * Die Wochentage bleiben leer: fehlen sie, nimmt OpenCalendar den Wochentag des
     * Beginns — genau das, was eine Reihe „ab diesem Termin" meint.
     *
     * @param array<string, mixed> $reihe
     * @return array<string, mixed>
     */
    private function CalRecurrenceFields(array $reihe): array
    {
        $freq = (string)($reihe['freq'] ?? '');
        $regel = [
            'frequency' => $freq === 'monthly' ? 'MONTHLY' : 'WEEKLY',
            // Vierzehntaegig ist woechentlich mit Schrittweite zwei — eine eigene
            // Frequenz dafuer kennt weder RFC 5545 noch OpenCalendar.
            'interval'  => $freq === 'biweekly' ? 2 : 1,
        ];
        $anzahl = (int)($reihe['count'] ?? 0);
        if ($anzahl > 1) {
            $regel['endMode'] = 'count';
            $regel['count']   = $anzahl;
        } elseif (trim((string)($reihe['until'] ?? '')) !== '') {
            $regel['endMode'] = 'until';
            $regel['until']   = (string)$reihe['until'];
        } else {
            $regel['endMode'] = 'never';
        }
        return $regel;
    }

    /** Kurzform der Regel fuers Protokoll. */
    private function CalSeriesLog(array $reihe): string
    {
        $takt = ['weekly' => 'woechentlich', 'biweekly' => 'zweiwoechentlich',
                 'monthly' => 'monatlich'][(string)($reihe['freq'] ?? '')] ?? '?';
        if ((int)($reihe['count'] ?? 0) > 1) {
            return $takt . ', ' . (int)$reihe['count'] . 'x';
        }
        return trim((string)($reihe['until'] ?? '')) !== ''
            ? $takt . ', bis ' . (string)$reihe['until']
            : $takt . ', ohne Ende';
    }

    private function CalWritableOne(int $calendarID, string $benoetigt): array
    {
        $fehler = static fn(string $code, string $text): array => ['ok' => false, 'code' => $code, 'message' => $text];

        if (!$this->CalAvailable() || !function_exists($benoetigt)) {
            return $fehler('calendar_unavailable', $this->Translate('No calendar available.'));
        }
        if (!in_array($calendarID, $this->CalInstanceIDs(), true)) {
            return $fehler('unknown_calendar', $this->Translate('Unknown calendar.'));
        }
        foreach ($this->CalCalendars() as $k) {
            if ((int)$k['id'] === $calendarID) {
                if (($k['canWrite'] ?? false) !== true) {
                    return $fehler('read_only', $this->Translate('This calendar is read-only.'));
                }
                return ['ok' => true, 'calendar' => $k];
            }
        }
        return $fehler('unknown_calendar', $this->Translate('Unknown calendar.'));
    }

    /**
     * Den Rohdatensatz eines Termins wiederbeschaffen.
     *
     * OpenCalendar verlangt zum Aendern und Loeschen genau den Datensatz, den es
     * selbst ausgeliefert hat: die Termin-ID des Anbieters steckt in `resourceUrl`,
     * die Aenderungsmarke in `etag`. Fehlt die URL, lautet die Antwort „Die
     * Google-Termin-ID fehlt"; ist das etag veraltet, verweigert der Anbieter mit
     * „von einem anderen Client geaendert". Beides faellt in CalNormalize weg, weil
     * die Oberflaeche die fremde Struktur nicht kennen soll — also wird hier frisch
     * gelesen und der Treffer ueber `id`, sonst `uid`, gesucht.
     *
     * @return array<string, mixed>|null
     */
    private function CalFindRaw(int $calendarID, string $id, string $uid, int $start): ?array
    {
        if ($id === '' && $uid === '') {
            return null;
        }
        // Eng um den bekannten Beginn herum lesen: ein Kalender mit Jahren an
        // Terminen soll fuer eine einzelne Zeile nicht komplett durchlaufen werden.
        // Ohne brauchbaren Beginn bleibt der weite Weg.
        if ($start > 0) {
            $von = $start - 2 * 86400;
            $bis = $start + 2 * 86400;
        } else {
            $von = (int)(strtotime('-30 days') ?: time());
            $bis = $von + self::CAL_MAX_RANGE_DAYS * 86400;
        }
        // Vorsicht beim UID-Rueckfall: alle Vorkommen einer Serie teilen sich die
        // UID, und im ±2-Tage-Fenster einer dichten Serie liegen mehrere. Deshalb
        // gewinnt der Treffer mit passendem Beginn; ohne einen solchen wird ein
        // UID-Treffer nur genommen, wenn er im Fenster eindeutig ist.
        $uidTreffer = [];
        foreach ($this->CalRawItems($calendarID, $von, $bis) as $e) {
            if ($id !== '' && (string)($e['id'] ?? '') === $id) {
                return $e;
            }
            if ($uid !== '' && (string)($e['uid'] ?? '') === $uid) {
                if ($start > 0 && (int)($e['startTimestamp'] ?? 0) === $start) {
                    return $e;
                }
                $uidTreffer[] = $e;
            }
        }
        return count($uidTreffer) === 1 ? $uidTreffer[0] : null;
    }

    /**
     * Aendert einen bestehenden Termin.
     *
     * Geschickt wird der gelesene Datensatz MIT seinen Erkennungsfeldern und nur den
     * geaenderten Inhalten darueber. Die Zeitstempel-Spiegel fliegen raus: sie
     * gehoeren zum alten Beginn und wuerden dem neuen widersprechen.
     *
     * @param array<string, mixed> $daten
     * @return array<string, mixed>
     */
    private function CalUpdateEvent(int $calendarID, array $daten): array
    {
        $fehler = static fn(string $code, string $text): array =>
            ['ok' => false, 'error' => ['code' => $code, 'message' => $text]];

        $wache = $this->CalWritableOne($calendarID, 'IPSKAL_UpdateEvent');
        if (($wache['ok'] ?? false) !== true) {
            return $fehler((string)$wache['code'], (string)$wache['message']);
        }
        $geprueft = $this->CalInputFields($daten);
        if (($geprueft['ok'] ?? false) !== true) {
            return $fehler((string)$geprueft['code'], (string)$geprueft['message']);
        }

        $id     = trim((string)($daten['id'] ?? ''));
        $uidIn  = trim((string)($daten['uid'] ?? ''));
        $startTs = (int)($daten['startTimestamp'] ?? 0);

        /* Zwei Anlaeufe. Der erste nimmt den Stand aus dem Zwischenspeicher (7 ms);
           wird er abgelehnt, holt der zweite GENAU DIESEN Termin frisch vom
           Anbieter (491 ms) statt den ganzen Kalender abzugleichen (882 ms).
           Wiederholt wird nach JEDER Ablehnung und nicht nur nach der
           Konflikt-Meldung: die kommt uebersetzt vom fremden Modul, ein
           Textvergleich darauf waere bruechig. Der zweite Versuch schickt
           dieselben Inhalte an denselben Termin, kann also nichts anrichten, was
           der erste nicht auch getan haette. */
        $letzteMeldung = '';
        $roh = $this->CalFindRaw($calendarID, $id, $uidIn, $startTs);
        if ($roh === null) {
            return $fehler('calendar_error', $this->Translate('This appointment no longer exists.'));
        }
        for ($versuch = 1; $versuch <= 2; $versuch++) {
            if ($versuch === 2) {
                $frisch = $this->CalFreshRaw($calendarID, $roh);
                if ($frisch === null) {
                    // Nichts Besseres zu holen — ein zweiter Anlauf mit demselben
                    // Stand waere nur dieselbe Ablehnung.
                    break;
                }
                $roh = $frisch;
            }

            $ereignis = array_merge($roh, $geprueft['fields']);
            unset($ereignis['startTimestamp'], $ereignis['endTimestamp']);
            // Vorkommen einer Serie: Die ganze Serie ist nie in Gefahr — ohne
            // ausdruecklichen writeScope=series ruehrt OpenCalendar den Serienkopf
            // nicht an (am 18.08.2026 im Quelltext von Build 588 verifiziert, Google
            // wie CalDAV). Ob DIESES Vorkommen geaendert werden darf, steht am
            // Datensatz selbst (canUpdateOccurrence; die Instanz-Statusabfrage sagt
            // dazu nichts Belastbares — live gemessen: dort false, am Termin true).
            // Ohne die Faehigkeit klar ablehnen statt den rohen Anbieter-Fehler samt
            // sinnlosem Sync-Wiederholungslauf zu ernten; der writeScope kommt mit
            // dem Rohdatensatz mit und wird nur gesetzt, falls er fehlt (der
            // CalDAV-Zweig verlangt ihn).
            if ($this->CalIsOccurrence($roh)) {
                /* Reichweite: nur dieses Vorkommen, dieses und alle folgenden, oder
                   die ganze Serie. Sie kommt aus der Oberflaeche; fehlt sie, bleibt
                   es beim einzelnen Vorkommen — das ist die vorsichtige Wahl und
                   das bisherige Verhalten. */
                $scope = trim((string)($daten['scope'] ?? 'occurrence'));
                if (!in_array($scope, ['occurrence', 'following', 'series'], true)) {
                    $scope = 'occurrence';
                }
                /* Jahresereignisse kennen nur EINE Reichweite. OpenCalendar lehnt
                   „Annual-event settings can only be changed for a complete
                   recurring series." ab, sobald ein Vorkommen mit anniversaryType
                   geschrieben wird — und der Rohdatensatz traegt das Feld bei jedem
                   Geburtstag mit. Ohne diese Zeile scheiterte also schon das
                   Umbenennen eines Geburtstags, den jemand im Kalender pflegt. */
                if ($this->CalIsAnniversary($ereignis)) {
                    $scope = 'series';
                }
                $recht = ['occurrence' => 'canUpdateOccurrence',
                          'following'  => 'canUpdateFollowing',
                          'series'     => 'canUpdateSeries'][$scope];
                if (($roh[$recht] ?? false) !== true) {
                    return $fehler('series_locked', $this->Translate(
                        'This appointment is part of a series. This calendar does not allow editing it that way — please edit it in your calendar app.'
                    ));
                }
                if ($scope !== 'occurrence') {
                    // Fuer „folgende" und „ganze Serie" ist ein ANDERER Datensatz
                    // das Ziel — siehe CalScopeTarget.
                    $ziel = $this->CalScopeTarget($calendarID, $roh, $scope);
                    if (($ziel['ok'] ?? false) !== true) {
                        return $fehler((string)$ziel['code'], (string)$ziel['message']);
                    }
                    $ereignis = array_merge($ziel['event'], $geprueft['fields']);
                    unset($ereignis['startTimestamp'], $ereignis['endTimestamp']);
                }
                $ereignis['writeScope'] = $scope;
            }
            try {
                $antwort = json_decode((string)IPSKAL_UpdateEvent(
                    $calendarID,
                    json_encode($ereignis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                ), true);
            } catch (Throwable $e) {
                $this->SendDebug('Calendar', 'UpdateEvent geworfen: ' . $e->getMessage(), 0);
                return $fehler('calendar_error', $e->getMessage());
            }
            if (!is_array($antwort) || ($antwort['success'] ?? false) !== true) {
                $letzteMeldung = is_array($antwort) ? (string)($antwort['error'] ?? '') : '';
                $this->SendDebug('Calendar', sprintf('UpdateEvent Versuch %d abgelehnt: %s', $versuch, $letzteMeldung), 0);
                continue;
            }

            // Zuordnung und Erinnerung haengen an „Kalender-ID:UID" und wandern mit:
            // ohne das behielte ein verschobener Termin seine alte Erinnerungszeit.
            $uid = (string)($roh['uid'] ?? '');
            if ($uid !== '') {
                $this->CalStoreExtras(
                    $calendarID,
                    $uid,
                    (string)$ereignis['summary'],
                    (bool)$ereignis['allDay'],
                    (string)$ereignis['start'],
                    $daten
                );
            }

            $this->LogMessage(sprintf(
                'SymDo: Termin „%s" in %s geaendert%s',
                (string)$ereignis['summary'],
                (string)$wache['calendar']['name'],
                $versuch > 1 ? ' (nach Abgleich)' : ''
            ), KL_NOTIFY);
            return ['ok' => true, 'event' => $antwort['event'] ?? null];
        }

        return $fehler(
            'calendar_error',
            $letzteMeldung !== '' ? $letzteMeldung : $this->Translate('The calendar rejected the appointment.')
        );
    }

    /**
     * Der Datensatz, auf den eine Serien-Aenderung wirken soll.
     *
     * Drei Reichweiten, drei Datensaetze — das ist der Punkt: OpenCalendar
     * entscheidet NICHT am writeScope allein, sondern will fuer „ganze Serie" den
     * Serienkopf und fuer „dieser und folgende" einen eigens dafuer gebauten
     * Datensatz. Wer dem Vorkommen bloss writeScope=series anhaengt, aendert
     * weiterhin nur das Vorkommen.
     *
     * @param array<string, mixed> $roh das Vorkommen aus dem Zwischenspeicher
     * @return array{ok:bool,event?:array<string,mixed>,code?:string,message?:string}
     */
    private function CalScopeTarget(int $calendarID, array $roh, string $scope): array
    {
        $fehler = fn(string $code, string $text): array => ['ok' => false, 'code' => $code, 'message' => $text];

        if ($scope === '' || $scope === 'occurrence') {
            return ['ok' => true, 'event' => $roh];
        }
        $serie = trim((string)($roh['seriesId'] ?? ''));
        $url   = (string)($roh['resourceUrl'] ?? '');
        if ($serie === '') {
            return $fehler('series_locked', $this->Translate(
                'This appointment does not belong to a series that can be edited as a whole.'));
        }
        try {
            if ($scope === 'series') {
                if (!function_exists('IPSKAL_GetRecurringSeries')) {
                    return $fehler('series_locked', $this->Translate(
                        'This calendar module is too old to edit a whole series.'));
                }
                $ziel = json_decode((string)IPSKAL_GetRecurringSeries($calendarID, $serie, $url), true);
            } else {
                if (!function_exists('IPSKAL_GetRecurringFollowing')) {
                    return $fehler('series_locked', $this->Translate(
                        'This calendar module is too old to edit an appointment and the ones after it.'));
                }
                $ziel = json_decode((string)IPSKAL_GetRecurringFollowing(
                    $calendarID, $serie,
                    (string)($roh['occurrenceId'] ?? ''),
                    (string)($roh['originalStart'] ?? ''),
                    $url), true);
            }
        } catch (Throwable $e) {
            $this->SendDebug('Calendar', 'Serienziel geworfen: ' . $e->getMessage(), 0);
            return $fehler('calendar_error', $e->getMessage());
        }
        if (!is_array($ziel) || trim((string)($ziel['etag'] ?? '')) === '') {
            return $fehler('calendar_error', $this->Translate('The calendar did not return the series.'));
        }
        // Die Reichweite MUSS mit: ohne sie ruehrt OpenCalendar den Serienkopf
        // nicht an, und die Aenderung traefe wieder nur das eine Vorkommen.
        $ziel['writeScope'] = $scope;
        return ['ok' => true, 'event' => $ziel];
    }

    /**
     * EINEN Termin frisch vom Anbieter holen — mit dessen aktuellem etag.
     *
     * Ersetzt im Schreibpfad den vollstaendigen Abgleich. Der war die einzige
     * Moeglichkeit, solange OpenCalendar nichts Gezielteres anbot: ein veraltetes
     * etag im Zwischenspeicher laesst den Anbieter jede Aenderung ablehnen, also
     * wurde der GANZE Kalender abgeglichen, um eine einzige Zeile aufzufrischen.
     *
     * Gemessen am 26.08.2026 an „Schule und Kita": voller Abgleich 882 ms, dieser
     * gezielte Griff 491 ms — und er ruehrt die uebrigen Termine nicht an.
     *
     * Gibt null zurueck, wenn es die Funktion nicht gibt (aeltere Fassung des
     * Kalender-Moduls) oder der Anbieter nichts Brauchbares liefert. Der Aufrufer
     * bricht dann ab, statt denselben Stand ein zweites Mal zu schicken.
     *
     * @param array<string, mixed> $roh der Datensatz, wie er im Zwischenspeicher steht
     * @return array<string, mixed>|null
     */
    private function CalFreshRaw(int $calendarID, array $roh): ?array
    {
        if (!function_exists('IPSKAL_GetEventForEdit')) {
            return null;
        }
        // Nur die Kennungsfelder mitgeben: die Funktion sucht den Termin damit beim
        // Anbieter und liefert ihn mit aktuellem etag zurueck.
        $kennung = [
            'id'             => (string)($roh['id'] ?? ''),
            'uid'            => (string)($roh['uid'] ?? ''),
            'resourceUrl'    => (string)($roh['resourceUrl'] ?? ''),
            'startTimestamp' => (int)($roh['startTimestamp'] ?? 0),
            'endTimestamp'   => (int)($roh['endTimestamp'] ?? 0),
        ];
        try {
            $frisch = json_decode((string)IPSKAL_GetEventForEdit(
                $calendarID, (string)json_encode($kennung, JSON_UNESCAPED_UNICODE)), true);
        } catch (Throwable $e) {
            $this->SendDebug('Calendar', 'GetEventForEdit geworfen: ' . $e->getMessage(), 0);
            return null;
        }
        // Ohne etag waere der Griff wertlos — dann lieber abbrechen.
        if (!is_array($frisch) || trim((string)($frisch['etag'] ?? '')) === '') {
            return null;
        }
        return $frisch;
    }

    /**
     * Loescht einen Termin beim Anbieter.
     *
     * Endgueltig und fuer alle, die den Kalender teilen — die Rueckfrage dazu stellt
     * die Oberflaeche, hier wird sie nicht wiederholt. IPSKAL_DeleteEvent antwortet
     * mit true/false statt mit JSON, deshalb der eigene Zweig.
     *
     * @param array<string, mixed> $daten
     * @return array<string, mixed>
     */
    private function CalDeleteEvent(int $calendarID, array $daten): array
    {
        $fehler = static fn(string $code, string $text): array =>
            ['ok' => false, 'error' => ['code' => $code, 'message' => $text]];

        $wache = $this->CalWritableOne($calendarID, 'IPSKAL_DeleteEvent');
        if (($wache['ok'] ?? false) !== true) {
            return $fehler((string)$wache['code'], (string)$wache['message']);
        }
        $id      = trim((string)($daten['id'] ?? ''));
        $uidIn   = trim((string)($daten['uid'] ?? ''));
        $startTs = (int)($daten['startTimestamp'] ?? 0);

        // Zwei Anlaeufe wie beim Aendern (siehe CalFreshRaw): ein veraltetes etag im
        // Zwischenspeicher laesst den Anbieter auch das Loeschen ablehnen.
        // IPSKAL_DeleteEvent antwortet mit true/false und nennt keinen Grund — umso
        // mehr Grund, es nach einem Abgleich noch einmal zu versuchen.
        $roh = $this->CalFindRaw($calendarID, $id, $uidIn, $startTs);
        $erfolg = false;
        for ($versuch = 1; $roh !== null && $versuch <= 2; $versuch++) {
            if ($versuch === 2) {
                // Gezielt statt vollstaendig — siehe CalFreshRaw.
                $frisch = $this->CalFreshRaw($calendarID, $roh);
                if ($frisch === null) {
                    break;
                }
                $roh = $frisch;
            }
            // Serien-Vorkommen: gleiche Wache wie beim Aendern (siehe CalUpdateEvent),
            // massgeblich ist das Flag am Datensatz.
            if ($this->CalIsOccurrence($roh)) {
                /* Beim Loeschen gibt es nur ZWEI Reichweiten: dieses Vorkommen oder
                   die ganze Serie. Ein „und alle folgenden" meldet der Anbieter
                   nicht (es gibt kein canDeleteFollowing), also bieten wir es auch
                   nicht an. */
                $scope = trim((string)($daten['scope'] ?? 'occurrence')) === 'series' ? 'series' : 'occurrence';
                /* Ein Jahresereignis wird als Ganzes geloescht. Ein einzelnes
                   Vorkommen liesse einen Geburtstag zurueck, dem genau ein Jahr
                   fehlt — ein Zustand, den niemand absichtlich herstellt. Darf die
                   Serie nicht geloescht werden, sagen wir das, statt hilfsweise ein
                   Jahr zu entfernen. */
                if ($this->CalIsAnniversary($roh)) {
                    $scope = 'series';
                }
                $recht = $scope === 'series' ? 'canDeleteSeries' : 'canDeleteOccurrence';
                if (($roh[$recht] ?? false) !== true) {
                    return $fehler('series_locked', $this->Translate(
                        'This appointment is part of a series. This calendar does not allow deleting it that way — please delete it in your calendar app.'
                    ));
                }
                if ($scope === 'series') {
                    $ziel = $this->CalScopeTarget($calendarID, $roh, 'series');
                    if (($ziel['ok'] ?? false) !== true) {
                        return $fehler((string)$ziel['code'], (string)$ziel['message']);
                    }
                    $roh = $ziel['event'];
                }
                $roh['writeScope'] = $scope;
            }
            try {
                $erfolg = IPSKAL_DeleteEvent(
                    $calendarID,
                    json_encode($roh, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                );
            } catch (Throwable $e) {
                $this->SendDebug('Calendar', 'DeleteEvent geworfen: ' . $e->getMessage(), 0);
                return $fehler('calendar_error', $e->getMessage());
            }
            if ($erfolg === true) {
                break;
            }
            $this->SendDebug('Calendar', sprintf('DeleteEvent Versuch %d abgelehnt', $versuch), 0);
        }
        if ($roh === null) {
            // Auch nach dem Abgleich nicht da: schon weg ist auch weg. Die Zeile ist
            // in der Oberflaeche verschwunden, ein Fehler waere hier nur verwirrend.
            return ['ok' => true, 'gone' => true];
        }
        if ($erfolg !== true) {
            return $fehler('calendar_error', $this->Translate('The calendar rejected the deletion.'));
        }

        // Zuordnung und Erinnerung mitnehmen — sonst meldete sich spaeter eine
        // Erinnerung fuer einen Termin, den es nicht mehr gibt.
        $uid = (string)($roh['uid'] ?? '');
        if ($uid !== '') {
            $schluessel = $calendarID . ':' . $uid;
            $karte = $this->CalMemberStore();
            if (array_key_exists($schluessel, $karte)) {
                unset($karte[$schluessel]);
                $this->CalWriteStore('CalMembers', $karte, self::CAL_MEMBERS_MAX);
            }
            $erinnerungen = $this->CalReminderStore();
            if (array_key_exists($schluessel, $erinnerungen)) {
                unset($erinnerungen[$schluessel]);
                $this->CalWriteStore('CalReminders', $erinnerungen, self::CAL_REMINDERS_MAX);
            }
        }

        $this->LogMessage(sprintf(
            'SymDo: Termin „%s" in %s geloescht',
            trim((string)($roh['summary'] ?? '')),
            (string)$wache['calendar']['name']
        ), KL_NOTIFY);
        return ['ok' => true];
    }

    /**
     * Mitglieder und Erinnerung eines Termins ablegen. Nur aus CalUpdateEvent — beim
     * Anlegen steht dieselbe Logik noch am Ort, weil dort erst die frische UID aus
     * der Antwort des Anbieters kommt.
     *
     * @param array<string, mixed> $daten
     */
    private function CalStoreExtras(int $calendarID, string $uid, string $titel, bool $ganztags, string $start, array $daten): void
    {
        $schluessel = $calendarID . ':' . $uid;

        if (array_key_exists('members', $daten)) {
            $mitglieder = [];
            foreach ((array)$daten['members'] as $m) {
                $m = trim((string)$m);
                if ($m !== '') {
                    $mitglieder[] = $m;
                }
            }
            $karte = $this->CalMemberStore();
            if ($mitglieder === []) {
                unset($karte[$schluessel]);
            } else {
                $karte[$schluessel] = array_values(array_unique($mitglieder));
            }
            $this->CalWriteStore('CalMembers', $karte, self::CAL_MEMBERS_MAX);
        }

        if (!array_key_exists('reminder', $daten)) {
            return;
        }
        $vorlauf = (int)$daten['reminder'];
        $erinnerungen = $this->CalReminderStore();
        if ($vorlauf < 0) {
            if (array_key_exists($schluessel, $erinnerungen)) {
                unset($erinnerungen[$schluessel]);
                $this->CalWriteStore('CalReminders', $erinnerungen, self::CAL_REMINDERS_MAX);
            }
            return;
        }
        /* Bei einer echten Serie gibt es nur EINEN Termin und damit nur EINEN
           Eintrag — die Erinnerung muss deshalb weiterwandern statt nach dem ersten
           Vorkommen zu verfallen. Frueher entstanden bis zu 60 Eintraege, einer je
           Einzeltermin; das erledigte sich von selbst und faellt jetzt weg.
           Nennt die Eingabe keine Regel (z. B. beim Aendern eines Termins), bleibt
           die bisherige stehen: sonst verlernte eine Serie ihr Weiterwandern,
           sobald jemand den Titel aendert. */
        $regel = $this->CalSeriesRule($daten['recurrence'] ?? null);
        $serie = $regel !== null
            ? ['freq'  => (string)$regel['freq'],
               'until' => (string)($regel['until'] ?? ''),
               // Verbleibende Vorkommen NACH dem ersten; null = ohne Ende.
               'left'  => isset($regel['count']) ? max(0, (int)$regel['count'] - 1) : null]
            : ($erinnerungen[$schluessel]['series'] ?? null);

        $erinnerungen[$schluessel] = [
            // „sent" bewusst zurueck auf false: ein verschobener Termin soll erneut
            // melden, auch wenn die alte Erinnerung schon raus war.
            'start' => (int)strtotime($ganztags ? $start . ' 00:00' : $start),
            'lead'  => $vorlauf,
            'title' => $titel,
            'sent'  => false,
        ];
        if (is_array($serie)) {
            $erinnerungen[$schluessel]['series'] = $serie;
        }
        if ($this->CalWriteStore('CalReminders', $erinnerungen, self::CAL_REMINDERS_MAX)) {
            try {
                $this->SetTimerInterval('CalNotify', 60000);
            } catch (Throwable $e) {
                $this->SendDebug('Calendar', 'Erinnerungs-Timer fehlt, Erinnerung bleibt liegen', 0);
            }
        }
    }

    /**
     * Gemeinsamer Verteiler fuer REST und Kachel-Relay — gleiche Bauart wie bei den
     * Mail-Vorschlaegen: EIN Pfad, die Aktion steht im Rumpf.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function CalHandleAction(array $body): array
    {
        $aktion = strtolower(trim((string)($body['action'] ?? 'calendars')));
        if ($aktion === 'calendars') {
            return ['ok' => true, 'available' => $this->CalAvailable(), 'calendars' => $this->CalCalendars()];
        }
        if ($aktion === 'events') {
            $von = (int)($body['from'] ?? 0);
            $bis = (int)($body['to'] ?? 0);
            if ($von <= 0) {
                $von = strtotime('today') ?: time();
            }
            if ($bis <= $von) {
                $bis = $von + 30 * 86400;
            }
            // Zeitraum begrenzen: OpenCalendar lehnt sehr grosse Spannen selbst ab,
            // aber eine klare eigene Grenze erspart die Ausnahme.
            $maximum = $von + self::CAL_MAX_RANGE_DAYS * 86400;
            if ($bis > $maximum) {
                $bis = $maximum;
            }
            $ids = [];
            foreach ((array)($body['ids'] ?? []) as $eintrag) {
                $ids[] = (int)$eintrag;
            }
            $ergebnis = $this->CalEvents($von, $bis, array_values(array_filter($ids)));
            return [
                'ok'        => true,
                'from'      => $von,
                'to'        => $bis,
                'events'    => $ergebnis['events'],
                'truncated' => $ergebnis['truncated'],
            ];
        }
        if ($aktion === 'create') {
            return $this->CalCreateEvent((int)($body['calendarID'] ?? 0), is_array($body['event'] ?? null) ? $body['event'] : []);
        }
        if ($aktion === 'update') {
            return $this->CalUpdateEvent((int)($body['calendarID'] ?? 0), is_array($body['event'] ?? null) ? $body['event'] : []);
        }
        if ($aktion === 'delete') {
            return $this->CalDeleteEvent((int)($body['calendarID'] ?? 0), is_array($body['event'] ?? null) ? $body['event'] : []);
        }
        return ['ok' => false, 'error' => ['code' => 'invalid_payload', 'message' => $this->Translate('Unknown action.')]];
    }
}

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

    /** Ist OpenCalendar installiert und mindestens ein Kalender eingerichtet? */
    private function CalAvailable(): bool
    {
        return function_exists('IPSKAL_GetCalendarStatus') && $this->CalInstanceIDs() !== [];
    }

    /** @return list<int> */
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
        foreach ($ids as $id) {
            foreach ($this->CalReadOne($id, $von, $bis) as $e) {
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
     * Ein Kalender, seitenweise. Der Token wird IMMER wieder abgeraeumt — auch wenn
     * mitten in der Uebertragung etwas schiefgeht.
     *
     * @return list<array<string, mixed>>
     */
    private function CalReadOne(int $id, int $von, int $bis): array
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
                    $raus[] = $this->CalNormalize($e, $id);
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
     * Fremdes Ereignis auf unsere Form bringen. Bewusst wenige, klar benannte Felder:
     * die Oberflaeche soll nicht von OpenCalendars Struktur abhaengen.
     *
     * @param array<string, mixed> $e
     * @return array<string, mixed>
     */
    private function CalNormalize(array $e, int $calendarID): array
    {
        $start = (int)($e['startTimestamp'] ?? 0);
        $ende  = (int)($e['endTimestamp'] ?? $start);
        if ($ende < $start) {
            $ende = $start;
        }
        return [
            'id'         => (string)($e['id'] ?? ($e['uid'] ?? '')),
            'uid'        => (string)($e['uid'] ?? ''),
            'calendarID' => $calendarID,
            'title'      => trim((string)($e['summary'] ?? '')),
            'info'       => trim((string)($e['description'] ?? '')),
            'location'   => trim((string)($e['location'] ?? '')),
            'start'      => $start,
            'end'        => $ende,
            'allDay'     => (bool)($e['allDay'] ?? false),
        ];
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
        return ['ok' => false, 'error' => ['code' => 'invalid_payload', 'message' => $this->Translate('Unknown action.')]];
    }
}

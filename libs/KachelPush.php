<?php

declare(strict_types=1);

require_once __DIR__ . '/KachelStand.php';

/**
 * Kachel-Push nur bei echter Änderung (26.09.2026) — für die kleinen Kacheln,
 * deren Kachel ihren Stand NICHT selbst per Prüfwert abgleicht (dafür gibt es
 * KachelStand): Einkaufs- und ToDo-Übersicht, Essensplan, Routinen, VRR.
 *
 * Bis hierher schob jedes Ereignis den kompletten Stand an jede offene Kachel:
 * eine Einkaufsliste setzt bei jedem Speichern zwei Zähler, eine ToDo-Liste
 * drei, und jede Aktualisierung — auch eine ohne neuen Wert — war ein Push.
 *
 * Drei Regeln:
 *  1. Ein VM_UPDATE zählt nur mit `$Data[1] === true` (der Wert hat sich
 *     geändert); fehlt `$Data[1]`, zählt es (Beachten()). Wo die Variable nur
 *     ein Auslöser für ANDERE Inhalte ist, entscheidet das Modul selbst, siehe
 *     dort.
 *  2. Eine Nachricht, die der zuletzt gesendeten gleicht, geht nicht erneut
 *     hinaus. Ihr Prüfwert steht im Puffer; der Erstaufbau (ApplyChanges) leert
 *     ihn (Entscheiden()).
 *  3. KORREKTURZUSTAND nach einer Aktion aus der Kachel: Kacheln wie die
 *     Übersicht oder die Routinen zeigen einen Tipp sofort an, bevor der Server
 *     ihn bestätigt. Scheitert die Aktion, bestätigt oft nur ein UNVERÄNDERTER
 *     Stand den alten Zustand — genau die Nachricht, die Regel 1 und 2
 *     wegfiltern würden. Deshalb gelten beide nicht, solange der Puffer den
 *     Zeitpunkt der letzten Aktion trägt: jede Aktualisierung geht hinaus, wie
 *     vorher. Der Zustand endet erst mit einer Nachricht, die mindestens
 *     KORREKTUR_S nach der letzten Aktion hinausgeht — manche Kacheln
 *     übergehen Echos kurz nach dem Tipp, manche Quellen bestätigen den alten
 *     Stand erst später. Das kostet höchstens eine Nachricht mehr.
 *
 * Gerechnet wird über die DATEN (KachelStand::Pruefwert, feste Kodier-
 * Optionen), damit ein Anfangsstand mit JSON_HEX_TAG und ein Push ohne
 * denselben Wert tragen.
 */
final class KachelPush
{
    /** Prüfwert der zuletzt gesendeten Nachricht. */
    public const PUFFER_PRUEFWERT = 'KachelPushPruefwert';
    /** Zeitpunkt der letzten Aktion aus der Kachel (microtime) — leer: kein Korrekturzustand. */
    public const PUFFER_AKTION = 'KachelPushAktion';
    /** Frühestens so lange nach der letzten Aktion endet der Korrekturzustand. */
    public const KORREKTUR_S = 1.5;
    /** Erst eine Uhr, die mehr als das zurückspringt, beendet ihn vorzeitig. */
    private const UHR_TOLERANZ_S = 5.0;
    /** Kennung des Einmal-Timers, der mehrere Ereignisse zu einem Push bündelt. */
    public const TIMER = 'KachelPush';

    /**
     * Soll ein VM_UPDATE beachtet werden? `$data` ist das `$Data` aus MessageSink,
     * `$aktion` der Inhalt von PUFFER_AKTION.
     */
    public static function Beachten(array $data, string $aktion): bool
    {
        if ($aktion !== '') {
            return true;                        // Korrekturzustand: alles, wie vorher
        }
        return !array_key_exists(1, $data) || $data[1] === true;
    }

    /**
     * Prüfwert einer Nachricht über ihre Daten.
     *
     * @param list<string> $ohne    Felder, die nichts Sichtbares tragen und nicht
     *                              zählen sollen (Routinen: `now`, die Kachel geht
     *                              nach der eigenen Uhr)
     * @param list<string> $minuten Zeitstempel in Sekunden, die nur minutengenau
     *                              zählen (VRR: `now` — die Kachel rechnet damit
     *                              Minuten, jede Sekunde wäre sonst ein neuer Stand)
     */
    public static function Pruefwert(array $daten, array $ohne = [], array $minuten = []): string
    {
        foreach ($ohne as $feld) {
            unset($daten[$feld]);
        }
        foreach ($minuten as $feld) {
            if (isset($daten[$feld]) && is_numeric($daten[$feld])) {
                $daten[$feld] = intdiv((int)$daten[$feld], 60);
            }
        }
        return KachelStand::Pruefwert($daten);
    }

    /**
     * Senden oder nicht — und was danach in den beiden Puffern steht.
     *
     * @param string $pruefwert Prüfwert der Nachricht, die gesendet werden soll
     * @param string $letzter   PUFFER_PRUEFWERT ('' = nichts bekannt, z. B. nach dem Erstaufbau)
     * @param string $aktion    PUFFER_AKTION
     * @return array{0: bool, 1: string, 2: string} [senden, neuer PUFFER_PRUEFWERT, neuer PUFFER_AKTION]
     */
    public static function Entscheiden(string $pruefwert, string $letzter, string $aktion, float $jetzt): array
    {
        if ($aktion !== '') {
            $alter = $jetzt - (float)$aktion;
            // Eine deutlich zurückgestellte Uhr (Aktion weit "in der Zukunft") beendet ihn
            // ebenfalls, sonst liefe der Korrekturzustand, bis die Uhr aufgeholt hat.
            $vorbei = $alter < -self::UHR_TOLERANZ_S || $alter >= self::KORREKTUR_S;
            return [true, $pruefwert, $vorbei ? '' : $aktion];
        }
        if ($letzter !== '' && hash_equals($letzter, $pruefwert)) {
            return [false, $letzter, ''];
        }
        return [true, $pruefwert, ''];
    }

    /**
     * Inhalt für PUFFER_AKTION bei einer Aktion aus der Kachel. Auf Mikrosekunden:
     * auf Millisekunden gerundet lag der Zeitpunkt bis zu einer halben
     * Millisekunde in der Zukunft.
     */
    public static function Aktion(float $jetzt): string
    {
        return sprintf('%.6F', $jetzt);
    }
}

/**
 * Die Symcon-Seite der Regeln — für Module, die KachelPush benutzen.
 *
 * Nur Puffer: die Entscheidung muss über Aufrufe hinweg halten, und ein
 * verschachtelter Rückruf landet auf einem anderen Objekt derselben Instanz
 * (ein Objektfeld überlebte das nicht).
 */
trait KachelPushWeg
{
    /**
     * Die Nachricht senden, wenn die Regeln es verlangen. `$immer` übergeht Regel 2
     * (ausdrückliche Anfrage, z. B. GetState aus einem Skript).
     */
    private function KachelSenden(string $nachricht, string $pruefwert, bool $immer = false): bool
    {
        $aktion = $this->GetBuffer(KachelPush::PUFFER_AKTION);
        [$senden, $merken, $weiter] = KachelPush::Entscheiden($pruefwert,
            $immer ? '' : $this->GetBuffer(KachelPush::PUFFER_PRUEFWERT), $aktion, microtime(true));
        if (!$senden) {
            return false;
        }
        $this->UpdateVisualizationValue($nachricht);
        $this->SetBuffer(KachelPush::PUFFER_PRUEFWERT, $merken);
        /* Den Korrekturzustand nur beenden, wenn inzwischen keine neue Aktion
           kam — sonst verschluckte das Ende genau deren Korrektur. */
        if ($weiter !== $aktion && $this->GetBuffer(KachelPush::PUFFER_AKTION) === $aktion) {
            $this->SetBuffer(KachelPush::PUFFER_AKTION, $weiter);
        }
        return true;
    }

    /** Eine Aktion aus der Kachel: ab jetzt Korrekturzustand (Regel 3). */
    private function KachelAktion(): void
    {
        $this->SetBuffer(KachelPush::PUFFER_PRUEFWERT, '');
        $this->SetBuffer(KachelPush::PUFFER_AKTION, KachelPush::Aktion(microtime(true)));
    }

    /** Erstaufbau: die nächste Nachricht geht in jedem Fall hinaus. */
    private function KachelErstaufbau(): void
    {
        $this->SetBuffer(KachelPush::PUFFER_PRUEFWERT, '');
    }

    /** Regel 1 für MessageSink. */
    private function KachelVmBeachten(array $Data): bool
    {
        return KachelPush::Beachten($Data, $this->GetBuffer(KachelPush::PUFFER_AKTION));
    }

    /**
     * Mehrere Ereignisse zu einem Push bündeln: der Einmal-Timer läuft HINTER dem
     * laufenden Aufruf, erneutes Anstoßen setzt ihn nur zurück. Das Modul
     * beantwortet dafür `RequestAction(KachelPush::TIMER)`. Kein registrierter
     * Timer — es braucht dafür weder Create() noch ein Neuladen.
     */
    private function KachelNachziehen(): void
    {
        @$this->RegisterOnceTimer(KachelPush::TIMER,
            'IPS_RequestAction($_IPS[\'TARGET\'], \'' . KachelPush::TIMER . '\', 0);');
    }
}

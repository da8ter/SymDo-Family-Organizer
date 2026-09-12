<?php

declare(strict_types=1);

/**
 * Wessen Konfiguration gilt, und unter welcher Instanz der Bestand liegt.
 *
 * Zwei Fragen, die ein Modul normalerweise nie stellt — es ist ja immer es
 * selbst. Beim Umbau „Gateway frei halten" ziehen die langlaufenden Scans aus
 * dem Gateway in eigene Instanzen (SymDoScanner), damit sie die Hook-Spur nicht
 * mehr blockieren. Diese Scans laufen mit FREMDER Konfiguration: die Zugaenge
 * (KI-Schluessel, Klassenseiten, Mailkonten) bleiben dort, wo der Nutzer sie
 * eingetragen hat — im Gateway. Und ihr Bestand (Medienobjekte, Kategorien,
 * Zwischendateien) bleibt dort, wo er heute schon liegt, damit ein Umzug keine
 * Datei verwaisen laesst.
 *
 * Deshalb hier, in `List/libs/` neben `ListSource.php`: beide Seiten binden
 * DIESELBE Datei ein. Ein Trait, der in zwei Modulordnern liegt, laeuft
 * auseinander; einer, der einmal liegt, kann das nicht.
 *
 * Im Gateway liefern beide Methoden die eigene Instanz — nichts aendert sich.
 * Der Scanner ueberschreibt sie mit der Gateway-ID; eine Klassenmethode
 * gewinnt ueber eine Trait-Methode, das ist in PHP ausdruecklich erlaubt und
 * braucht kein `insteadof`.
 */
trait Konfig
{
    /**
     * Die Instanz, deren KONFIGURATION gilt. Im Gateway die eigene; ein
     * ausgelagerter Scanner ueberschreibt das mit der Gateway-ID, damit er
     * dieselben Zugaenge benutzt, ohne sie zu duplizieren.
     */
    protected function KonfigID(): int
    {
        return $this->InstanceID;
    }

    /**
     * Die Instanz, unter der der BESTAND liegt (Medienobjekte, Kategorien,
     * Zwischendateien). Im Gateway die eigene; der Scanner zeigt hierher, damit
     * ein von ihm angelegtes Medienobjekt am selben Ort landet wie bisher.
     *
     * Achtung beim spaeteren Umzug des Doku-Index: SymconDoku.php baut den
     * Dateinamen aus der InstanzID. Wer das nicht auf BestandID() umstellt,
     * laesst den gebauten Index verwaisen — er zeigte dann auf eine Datei,
     * die niemand mehr liest.
     */
    protected function BestandID(): int
    {
        return $this->InstanceID;
    }

    /** @var array<string,mixed>|null Zwischenspeicher der Fremdkonfiguration je Aufruf. */
    private ?array $konfigCache = null;

    /**
     * Eine Eigenschaft der KonfigID lesen — die eine Stelle, ueber die aller
     * Zugriff auf die Gateway-Konfiguration laeuft.
     *
     * Fuer die eigene Instanz `IPS_GetProperty` (sieht gestagte Werte sofort,
     * wie `LoadUsers`); fuer eine fremde Instanz einmal `IPS_GetConfiguration`
     * und danach aus dem Zwischenspeicher. Der Cache lebt nur, solange dieser
     * PHP-Aufruf laeuft — Symcon baut je Hook/Aktion einen frischen Kontext,
     * also nie ein veralteter Wert ueber Aufrufe hinweg.
     */
    protected function AiProp(string $name): mixed
    {
        $ziel = $this->KonfigID();
        if ($ziel === $this->InstanceID) {
            return @IPS_GetProperty($ziel, $name);
        }
        if ($this->konfigCache === null) {
            $roh = json_decode((string) @IPS_GetConfiguration($ziel), true);
            $this->konfigCache = is_array($roh) ? $roh : [];
        }
        return $this->konfigCache[$name] ?? null;
    }
}

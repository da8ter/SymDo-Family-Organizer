<?php

declare(strict_types=1);

/**
 * Eine Sprachliste als Gegenstelle — Alexa oder Bring.
 *
 * Warum eine eigene Schicht und nicht der direkte Aufruf von ALEXALIST_*: die
 * beiden Fremdmodule koennen fast dasselbe, aber nicht ganz. Alexa hakt Eintraege
 * ab und kennt eine stabile Kennung fuer jede Operation; Bring kann nur entfernen
 * und arbeitet ueber den Namen. Ohne diese Schicht stuende jede dieser
 * Abweichungen als `if` mitten in der Abgleich-Logik.
 *
 * Die Klassen sind absichtlich duenn: sie uebersetzen, sie entscheiden nichts.
 * Jede Entscheidung (was angelegt, abgehakt, entfernt wird) faellt in
 * VoiceListSync — und ist dadurch ohne Cloud pruefbar, weil der Pruefstand hier
 * eine Attrappe einsetzt.
 */
abstract class VoiceSource
{
    /** GUID des Fremdmoduls AlexaList (Bibliothek „Echo Remote"). */
    public const GUID_ALEXA = '{7129178B-E633-238A-0851-2F1B5A09805E}';

    /** GUID des Fremdmoduls „Bring List". */
    public const GUID_BRING = '{44D63530-0E14-8B8F-3E1A-A79728240524}';

    /**
     * Hoechstzahl an Eintraegen, die eine Abfrage sicher vollstaendig liefert.
     *
     * AlexaList holt `…/items/fetch?limit=100` OHNE Seitenfortsetzung
     * (AlexaList/module.php:308-323 — kein Cursor, anders als GoogleFetchTasks).
     * Bei mehr als 100 Eintraegen kommt also stillschweigend ein Ausschnitt
     * zurueck, nicht erkennbar als Fehler. Deshalb darf „Eintrag fehlt" nur
     * dann als „von der Liste genommen" gelten, wenn die Antwort nachweislich
     * vollstaendig war — siehe VoiceListSync.
     */
    public const READ_LIMIT = 100;

    protected int $instanceID;

    public function __construct(int $instanceID)
    {
        $this->instanceID = $instanceID;
    }

    /**
     * Passende Gegenstelle zur gewaehlten Instanz, oder null.
     *
     * Entschieden wird nach der moduleID, nicht nach einer eigenen Einstellung:
     * das Formular laesst beide Modularten zu (ein Feld statt zwei), und was der
     * Nutzer dort waehlt, sagt bereits alles.
     */
    public static function For(int $instanceID): ?self
    {
        if ($instanceID <= 0 || !@IPS_InstanceExists($instanceID)) {
            return null;
        }
        $guid = (string)(@IPS_GetInstance($instanceID)['ModuleInfo']['ModuleID'] ?? '');
        if ($guid === self::GUID_ALEXA) {
            return new VoiceSourceAlexa($instanceID);
        }
        if ($guid === self::GUID_BRING) {
            return new VoiceSourceBring($instanceID);
        }
        return null;
    }

    public function InstanceID(): int
    {
        return $this->instanceID;
    }

    /** Kurzname fuer die Ablage am Eintrag (`voiceSource`) und fuer Protokollzeilen. */
    abstract public function Key(): string;

    /**
     * Der Bestand der Gegenstelle, vereinheitlicht auf
     * `[['id' => string, 'name' => string, 'done' => bool, 'at' => int], …]`.
     *
     * `false` heisst „nicht lesbar" und ist NICHT dasselbe wie eine leere Liste.
     * Diese Unterscheidung ist die wichtigste des ganzen Bausteins: bei `false`
     * darf der Abgleich nichts loeschen, sonst raeumt ein Netzfehler die Liste ab.
     */
    abstract public function Read(): array|false;

    /** Legt an. `$spec` ist die Mengenangabe; wer sie nicht trennen kann, haengt sie an. */
    abstract public function Add(string $name, string $spec): bool;

    /** Nimmt den Eintrag von der Liste (abhaken, wo moeglich, sonst entfernen). */
    abstract public function Complete(string $id, string $name): bool;

    /** Entfernt den Eintrag ganz. */
    abstract public function Remove(string $id, string $name): bool;

    /** Stoesst eine Aktualisierung der Gegenstelle an (kostet dort einen Cloud-Aufruf). */
    abstract public function Refresh(): void;

    /**
     * Die Variable, deren Aenderung einen Abgleich ausloest — 0, wenn es keine gibt.
     *
     * Wir nehmen bewusst den Takt des Fremdmoduls statt eines eigenen Timers:
     * dessen Aktualisierung kostet ohnehin einen Cloud-Aufruf, ein zweiter
     * daneben brachte nur Last auf einer inoffiziellen Schnittstelle.
     */
    abstract public function TriggerVariableID(): int;
}

/**
 * Alexas Einkaufs- bzw. Aufgabenliste ueber das Modul AlexaList.
 *
 * Am echten Konto gemessen (24.08.2026): ein Eintrag traegt `itemId` (stabile
 * UUID), `itemName`, `itemStatus` (ACTIVE/COMPLETE) und `createAt` in
 * MILLISEKUNDEN. Die Felder `quantity` und `note` sind durchgaengig null — eine
 * gesprochene Menge steckt im Namen („3 Milch"). Deshalb kann `Add()` auch nur
 * einen Text uebergeben und muss die Menge wieder hineinschreiben.
 */
class VoiceSourceAlexa extends VoiceSource
{
    public function Key(): string
    {
        return 'alexa';
    }

    public function Read(): array|false
    {
        // Mit erledigten Eintraegen: nur so ist „abgehakt" von „verschwunden" zu
        // unterscheiden. Ohne sie sahen beide Faelle gleich aus.
        //
        // try/catch, nicht nur @: das Fremdmodul bildet eine inoffizielle
        // Amazon-Schnittstelle ab. Eine Ausnahme von dort darf den Abgleich
        // beenden, aber nicht den ganzen ApplyChanges der Liste zerlegen.
        try {
            $roh = ALEXALIST_GetItems($this->instanceID, true);
        } catch (\Throwable $e) {
            return false;
        }
        if (!is_array($roh)) {
            return false;
        }
        $raus = [];
        foreach ($roh as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id   = trim((string)($item['itemId'] ?? ''));
            $name = trim((string)($item['itemName'] ?? ''));
            if ($id === '' || $name === '') {
                continue;
            }
            $raus[] = [
                'id'   => $id,
                'name' => $name,
                'done' => ((string)($item['itemStatus'] ?? '')) === 'COMPLETE',
                // Millisekunden auf Sekunden: alles andere im Repo rechnet in Sekunden.
                'at'   => (int)round(((float)($item['createAt'] ?? 0)) / 1000),
            ];
        }
        return $raus;
    }

    public function Add(string $name, string $spec): bool
    {
        // Alexa hat kein Mengenfeld — die Menge geht voran, so wie Alexa selbst
        // es schreibt („3 Milch"). Damit liest die Ansage sich richtig vor.
        $text = trim($spec) !== '' ? trim($spec) . ' ' . trim($name) : trim($name);
        return $text !== '' && @ALEXALIST_AddItem($this->instanceID, $text) !== false;
    }

    public function Complete(string $id, string $name): bool
    {
        return $id !== '' && @ALEXALIST_CheckItemByID($this->instanceID, $id) !== false;
    }

    public function Remove(string $id, string $name): bool
    {
        return $id !== '' && @ALEXALIST_DeleteItemByID($this->instanceID, $id) !== false;
    }

    public function Refresh(): void
    {
        @ALEXALIST_Update($this->instanceID);
    }

    public function TriggerVariableID(): int
    {
        // Die Variable „Liste" wird bei JEDEM Takt des Fremdmoduls geschrieben
        // (SetValue), also auch unveraendert. Der Abgleich prueft deshalb das
        // Changed-Flag der Nachricht, nicht bloss deren Eingang.
        return (int)@IPS_GetObjectIDByIdent('List', $this->instanceID);
    }
}

/**
 * Eine Bring-Liste ueber das Modul „Bring List" (Nall-chan/bring-symcon).
 *
 * Zwei Unterschiede zu Alexa, die diese Klasse ausgleicht:
 *  - Bring TRENNT Name und Menge von sich aus (`AddItem(name, spec)`), das
 *    Mengenfeld heisst dort `specification`. Das ist genau unser `amount`.
 *  - Bring kann nicht abhaken. Ein gekaufter Artikel wird ENTFERNT; er wandert
 *    bei Bring in „kuerzlich gekauft". Deshalb faellt Complete() auf Remove()
 *    zurueck — nicht als Notloesung, sondern weil es dort dieselbe Bedeutung hat.
 *
 * Nicht am lebenden System geprueft: das Modul ist hier nicht installiert
 * (Stand 24.08.2026). Alle Signaturen stammen aus dem Quelltext des Moduls,
 * `RemoveItem()` steht dort in module.php:603 — die README fuehrt sie nicht auf.
 */
class VoiceSourceBring extends VoiceSource
{
    public function Key(): string
    {
        return 'bring';
    }

    public function Read(): array|false
    {
        try {
            $roh = BRING_GetList($this->instanceID);
        } catch (\Throwable $e) {
            return false;
        }
        if (!is_array($roh)) {
            return false;
        }
        // Bring liefert zwei Faecher: `purchase` (zu kaufen) und `recently`
        // (kuerzlich gekauft). Das zweite ist unser „erledigt".
        $raus = [];
        foreach (['purchase' => false, 'recently' => true] as $fach => $erledigt) {
            foreach ((array)($roh[$fach] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $name = trim((string)($item['itemId'] ?? $item['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                // Bei Bring IST der Artikelname die Kennung (itemId). Eine eigene
                // UUID gibt es nicht, deshalb steht hier derselbe Wert in beiden
                // Feldern — die Abgleich-Logik braucht nur, dass er stabil ist.
                $raus[] = [
                    'id'   => $name,
                    'name' => $name,
                    'done' => $erledigt,
                    'spec' => trim((string)($item['specification'] ?? '')),
                    'at'   => 0,
                ];
            }
        }
        return $raus;
    }

    public function Add(string $name, string $spec): bool
    {
        $name = trim($name);
        return $name !== '' && @BRING_AddItem($this->instanceID, $name, trim($spec)) !== false;
    }

    public function Complete(string $id, string $name): bool
    {
        return $this->Remove($id, $name);
    }

    public function Remove(string $id, string $name): bool
    {
        $wert = trim($name) !== '' ? trim($name) : trim($id);
        return $wert !== '' && @BRING_RemoveItem($this->instanceID, $wert) !== false;
    }

    public function Refresh(): void
    {
        @BRING_UpdateList($this->instanceID);
    }

    public function TriggerVariableID(): int
    {
        // Die Textbox ist bei Bring ABSCHALTBAR (EnableTextboxVariable). Fehlt
        // sie, gibt es keinen Auslöser — das Formular sagt das dann auch.
        return (int)@IPS_GetObjectIDByIdent('TextBox', $this->instanceID);
    }
}

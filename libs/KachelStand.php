<?php

declare(strict_types=1);

/**
 * Prüfwert des Kachelstands (26.09.2026): die Kachel bekommt den vollen Stand
 * nur, wenn er sich geändert hat.
 *
 * Die Kacheln, die aus der Web-App übernommen sind (ToDoList, ShoppingList,
 * Notizen, Hausaufgaben, Klassenseiten), fragen alle 15 Sekunden und bei jedem
 * Sichtbarwerden nach dem Stand — und bekamen jedes Mal die ganze Liste. Jetzt
 * trägt jeder Stand `stateHash`; die Kachel schickt den zuletzt gesehenen mit,
 * und bei Gleichheit geht nichts hinaus. Ein Push nach einer Änderung geht
 * weiterhin an alle offenen Kacheln.
 *
 * Gerechnet wird über die DATEN mit festen Kodier-Optionen, nicht über den
 * versandten Text: der Anfangsstand im <script>-Block wird mit JSON_HEX_TAG
 * kodiert, der Push ohne — derselbe Stand ergäbe sonst zwei Prüfwerte.
 */
final class KachelStand
{
    public static function Pruefwert(array $daten): string
    {
        unset($daten['stateHash']);
        return substr(md5((string)json_encode($daten,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)), 0, 16);
    }

    /** Derselbe Stand, mit seinem Prüfwert. */
    public static function MitPruefwert(array $daten): array
    {
        $daten['stateHash'] = self::Pruefwert($daten);
        return $daten;
    }

    /**
     * Kennt die Kachel den Stand mit diesem Prüfwert schon? `$wert` ist, was sie per
     * requestAction schickt: `{"hash": "…"}` als JSON — oder etwas anderes
     * (0 vom Formular-Knopf, eine alte Kachel ohne Prüfwert), dann nie.
     */
    public static function Kennt(string $pruefwert, mixed $wert): bool
    {
        if ($pruefwert === '' || !is_string($wert) || $wert === '' || $wert[0] !== '{') {
            return false;
        }
        $d = json_decode($wert, true);
        $alt = is_array($d) && is_string($d['hash'] ?? null) ? $d['hash'] : '';
        return $alt !== '' && hash_equals($pruefwert, $alt);
    }
}

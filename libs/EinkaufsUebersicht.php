<?php

declare(strict_types=1);

/**
 * Was die Einkaufs-Übersichtskachel (ShoppingListOverview) von einer
 * Einkaufsliste braucht — und nur das (26.09.2026).
 *
 * Bis hierher holte die Übersicht bei jedem Ereignis den VOLLEN Zustand der
 * Liste (SL_GetAppState: Vorschläge, Favoriten, Käufe, Kategorie-Stile …) und
 * schickte die komplette Bild- und Markenkarte — rund 3 300 Einträge, 100 KB — an jede
 * offene Kachel, für einen Streifen mit einer Handvoll offener Artikel.
 *
 * OffeneArtikel() nimmt die offenen Artikel in Kategorien-Reihenfolge und nur
 * die Felder der Kachel. Bildkarten() lässt von beiden Karten nur, was einer
 * dieser Artikel TREFFEN KANN.
 *
 * Warum die Auswahl kein Bild ändert: die Kachel löst ein Bild über Kandidaten
 * des Namens auf (imageUrlFor/_piResolve in ShoppingListOverview/module.html,
 * Spiegel der Einkaufsliste und der App): der Name, seine ae/ä-Faltung hin und
 * zurück, und Stämme ohne Endung. Jeder Treffer — genau, Marke als ganzes Wort,
 * Endung, ganzes Wort — ist ein TEILSTÜCK eines Kandidaten. Ein Eintrag, der in
 * keinem Kandidaten steckt, trifft nie; ihn wegzulassen ändert keinen Treffer,
 * und die Rangfolge (Länge, dann Alphabet) rechnet die Kachel selbst über das,
 * was ankommt. Kandidaten() erzeugt dafür bewusst MEHR als die Kachel (jede
 * Vorsilbe statt nur der Stämme bekannter Endungen): eine neue Endung in der
 * Kachel darf die Auswahl nicht still zu knapp machen.
 */
final class EinkaufsUebersicht
{
    private const FALTEN   = ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss'];
    private const ENTFALTEN_VON = ['ae', 'oe', 'ue', 'ss'];
    private const ENTFALTEN_ZU  = ['ä', 'ö', 'ü', 'ß'];

    /**
     * Offene Artikel in der Reihenfolge der Kategorien-Sortierung — identisch zur
     * Einkaufslisten-Kachel und zur App. `$state` ist der Zustand der Liste (voll
     * oder der Auszug aus SL_GetOverviewState).
     *
     * @return list<array{id: string, name: string, amount: string, imageUrl: string}>
     */
    public static function OffeneArtikel(array $state): array
    {
        $gruppen = [];
        foreach ((array)($state['items'] ?? []) as $item) {
            if (!is_array($item) || !empty($item['inCart'])) {
                continue;
            }
            $kategorie = trim((string)($item['category'] ?? ''));
            $gruppen[$kategorie === '' ? 'Sonstiges' : $kategorie][] = [
                // Die Kennung braucht die Kachel zum Abhaken; der Name ist nicht eindeutig.
                'id'       => (string)($item['id'] ?? ''),
                'name'     => (string)($item['name'] ?? ''),
                'amount'   => (string)($item['amount'] ?? ''),
                'imageUrl' => (string)($item['imageUrl'] ?? ''),
            ];
        }

        $reihenfolge = [];
        foreach ((array)($state['categoryOrder'] ?? []) as $kategorie) {
            $reihenfolge[] = (string)$kategorie;
        }
        /* (string): eine Kategorie aus Ziffern wäre als Array-Schlüssel eine Zahl
           geworden, und in_array(…, true) fände sie nie. */
        foreach (array_map('strval', array_keys($gruppen)) as $kategorie) {
            if (!in_array($kategorie, $reihenfolge, true)) {
                $reihenfolge[] = $kategorie;
            }
        }

        $sortiert = [];
        foreach ($reihenfolge as $kategorie) {
            foreach ($gruppen[$kategorie] ?? [] as $item) {
                $sortiert[] = $item;
            }
        }
        return $sortiert;
    }

    /**
     * Nur die Einträge von Bild- und Markenkarte, die ein Artikel OHNE eigenes Bild
     * treffen kann (ein eigenes `imageUrl` nimmt die Kachel direkt).
     *
     * @param list<array<string,mixed>> $artikel
     * @param array<string,string>      $bilder  Name => Datei (availableImages)
     * @param array<string,string>      $marken  Marke => Datei (availableBrands)
     * @return array{bilder: array<string,string>, marken: array<string,string>}
     */
    public static function Bildkarten(array $artikel, array $bilder, array $marken): array
    {
        $kandidaten = [];
        foreach ($artikel as $item) {
            if (!is_array($item) || trim((string)($item['imageUrl'] ?? '')) !== '') {
                continue;
            }
            foreach (self::Kandidaten((string)($item['name'] ?? '')) as $k) {
                $kandidaten[$k] = true;
            }
        }
        if ($kandidaten === []) {
            return ['bilder' => [], 'marken' => []];
        }
        // Zeilenumbruch als Trenner: ein Schlüssel ist ein Dateiname oder Alias, nie mehrzeilig.
        $heuhaufen = "\n" . implode("\n", array_map('strval', array_keys($kandidaten))) . "\n";
        return [
            'bilder' => self::Auswahl($bilder, $heuhaufen),
            'marken' => self::Auswahl($marken, $heuhaufen),
        ];
    }

    /**
     * Alle Zeichenfolgen, in denen ein Treffer der Kachel stecken kann: der Name
     * so klein geschrieben und normalisiert wie in der Kachel (NFC, toLowerCase),
     * seine Faltung (ä → ae) und Entfaltung (ae → ä), und die Entfaltung jeder
     * Vorsilbe (die Kachel entfaltet auch Stämme, und eine Entfaltung trennt ein
     * „ae" an der Schnittstelle anders als die des ganzen Namens).
     *
     * @return list<string>
     */
    public static function Kandidaten(string $name): array
    {
        $roh = trim($name) === '' ? '' : mb_strtolower($name);
        $nfc = $roh;
        if ($roh !== '' && class_exists('Normalizer')) {
            $n = \Normalizer::normalize($roh, \Normalizer::FORM_C);
            $nfc = is_string($n) && $n !== '' ? mb_strtolower($n) : $roh;
        }
        $raus = [];
        foreach (array_unique([$nfc, $roh]) as $schluessel) {
            if ($schluessel === '') {
                continue;
            }
            $raus[] = $schluessel;
            $raus[] = strtr($schluessel, self::FALTEN);
            $raus[] = self::Entfalten($schluessel);
            $laenge = mb_strlen($schluessel);
            for ($i = 1; $i < $laenge; $i++) {
                $raus[] = self::Entfalten(mb_substr($schluessel, 0, $i));
            }
        }
        return array_values(array_unique($raus));
    }

    /** ae → ä usw., in derselben Reihenfolge wie `_piUnfold` der Kachel (nacheinander). */
    private static function Entfalten(string $s): string
    {
        return str_replace(self::ENTFALTEN_VON, self::ENTFALTEN_ZU, $s);
    }

    /** @return array<string,string> */
    private static function Auswahl(array $karte, string $heuhaufen): array
    {
        $raus = [];
        foreach ($karte as $schluessel => $datei) {
            // (string): ein Schlüssel aus Ziffern ist als Array-Schlüssel eine Zahl.
            $schluessel = (string)$schluessel;
            if ($schluessel !== '' && str_contains($heuhaufen, $schluessel)) {
                $raus[$schluessel] = (string)$datei;
            }
        }
        return $raus;
    }
}

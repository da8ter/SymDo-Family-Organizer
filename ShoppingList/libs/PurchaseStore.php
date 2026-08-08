<?php

declare(strict_types=1);

/**
 * Kaufhistorie: welche Artikel wurden schon einmal abgehakt.
 *
 * Bewusst getrennt von der Häufigkeit in SuggestionEngine: die zählt das
 * HINZUFÜGEN auf die Liste (TrackFrequency), nicht das Kaufen. Von den ~540
 * Vorschlägen haben die allermeisten die Häufigkeit 0, weil sie aus dem
 * mitgelieferten Grundkatalog stammen — als „schon gekauft" taugt das nicht.
 *
 * Die Historie wird NICHT als Favoritenliste abgelegt. Zwei Gründe:
 * SyncFavoriteListsFromConfig baut das Favoriten-Attribut komplett aus dem
 * Konfigurationsformular neu auf (eine Systemliste wäre beim ersten Speichern
 * weg), und Favoriten-Mutationen laufen ohne Semaphore. Ausgeliefert wird sie
 * als eigener Payload-Schlüssel `purchased`, damit sie gar nicht erst in die
 * Pfade gerät, die über favoriteLists laufen — insbesondere die Herz-Anzeige
 * an jeder Einkaufszeile, die sich aus den Namen ALLER Favoritenlisten speist.
 */
trait PurchaseStore
{
    /** Obergrenze im Attribut. Beim Überlauf fliegt das Seltenste und Älteste. */
    private const PURCHASE_STORE_LIMIT = 500;

    /** Obergrenze im State-Payload — der geht nach JEDER Mutation komplett raus. */
    private const PURCHASE_PAYLOAD_LIMIT = 200;

    /**
     * Lesen und Schreiben laufen über diese beiden Kapseln, weil das Attribut erst
     * beim nächsten Kernel-Start existiert: Create() läuft bei einem reinen
     * Modul-Update NICHT erneut. ReadAttributeString liefert dann `false`, und
     * json_decode(false) wirft unter strict_types einen TypeError — das würde hier
     * das Abhaken selbst zerlegen. Bis zum Neustart zeichnet das Modul also nichts
     * auf, statt zu scheitern.
     */
    private function ReadPurchaseAttribute(): string
    {
        try {
            $raw = $this->ReadAttributeString('PurchaseHistory');
        } catch (\Throwable $e) {
            return '{}';
        }
        return is_string($raw) ? $raw : '{}';
    }

    private function WritePurchaseAttribute(array $History): bool
    {
        try {
            $this->WriteAttributeString(
                'PurchaseHistory',
                json_encode($History, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
            return true;
        } catch (\Throwable $e) {
            $this->SendDebug('PurchaseStore', 'Attribut noch nicht vorhanden (Kernel-Neustart nötig)', 0);
            return false;
        }
    }

    /** @return array<string, array{name:string,category:string,count:int,last:int}> */
    private function LoadPurchaseHistory(): array
    {
        $data = json_decode($this->ReadPurchaseAttribute(), true);
        return is_array($data) ? $data : [];
    }

    /**
     * Verbucht Käufe. Erwartet die Artikel-Datensätze, die gerade in den Wagen
     * gewandert sind — gesammelt und in EINEM Schreibvorgang, nicht je Artikel
     * (MarkAllDone trifft sonst dutzendfach das Attribut).
     *
     * @param list<array> $Rows
     */
    private function TrackPurchases(array $Rows): void
    {
        if ($Rows === []) {
            return;
        }
        $semaphoreKey = 'SL_Purchases_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($semaphoreKey, 500)) {
            $this->SendDebug('PurchaseStore', 'Semaphore timeout on TrackPurchases', 0);
            return;
        }
        try {
            $history = $this->LoadPurchaseHistory();
            $now     = time();
            $touched = false;
            foreach ($Rows as $row) {
                $name = trim((string)($row['name'] ?? ''));
                $key  = mb_strtolower($name);
                if ($key === '') {
                    continue;
                }
                $category = trim((string)($row['category'] ?? ''));
                if ($category === '') {
                    // Ohne Kategorie bliebe die Kachel in der Liste grau. Nur im
                    // Bedarfsfall nachschlagen — LookupCategory sortiert das ganze
                    // Vokabular und ist der teuerste Pfad im Modul.
                    $category = (string)$this->LookupCategory($name);
                }
                $existing = is_array($history[$key] ?? null) ? $history[$key] : null;
                // Menge und Notiz des gekauften Artikels übernehmen — beim nächsten
                // Mal ist das der Vorschlag. Eine von Hand gesetzte Menge überschreibt
                // der Kauf allerdings; das ist gewollt, „zuletzt so gekauft" ist die
                // brauchbarere Vorbelegung als ein alter Wunschwert.
                $amount = trim((string)($row['amount'] ?? ''));
                $history[$key] = [
                    // Letzte Schreibweise gewinnt: wer „Vollmilch" statt „vollmilch"
                    // tippt, soll das auch in der Liste sehen.
                    'name'     => $name,
                    'category' => $category !== '' ? $category : (string)($existing['category'] ?? ''),
                    'amount'   => $amount !== '' ? $amount : (string)($existing['amount'] ?? '1'),
                    'notes'    => trim((string)($row['notes'] ?? '')) !== ''
                                  ? trim((string)$row['notes'])
                                  : (string)($existing['notes'] ?? ''),
                    'count'    => (int)($existing['count'] ?? 0) + 1,
                    'last'     => $now,
                ];
                $touched = true;
            }
            if ($touched) {
                $this->WritePurchaseAttribute($this->PrunePurchaseHistory($history));
            }
        } finally {
            IPS_SemaphoreLeave($semaphoreKey);
        }
    }

    /** Beim Überlauf zuerst das Seltenste, bei Gleichstand das Älteste verwerfen. */
    private function PrunePurchaseHistory(array $History): array
    {
        if (count($History) <= self::PURCHASE_STORE_LIMIT) {
            return $History;
        }
        uasort($History, static function (array $a, array $b): int {
            return [(int)($b['count'] ?? 0), (int)($b['last'] ?? 0)]
                <=> [(int)($a['count'] ?? 0), (int)($a['last'] ?? 0)];
        });
        return array_slice($History, 0, self::PURCHASE_STORE_LIMIT, true);
    }

    /**
     * Einträge für den State. Auswahl nach Rezenz (das Aktuelle fällt nie heraus),
     * Ausgabe alphabetisch — nach Rezenz sortiert wanderte der frisch gekaufte
     * Artikel bei jedem Abhaken auf Platz 1 und der Zeilen-Abgleich der Oberfläche
     * müsste sämtliche Knoten umhängen.
     *
     * array_values ist Pflicht, nicht Kosmetik: die Historie ist eine Map, und
     * json_encode macht daraus `{}` statt `[]`. Das strikte Decoding der iOS-App
     * lässt daran den GESAMTEN Zustand scheitern (vgl. den Kommentar zu notes in
     * ItemStore::LoadItems).
     *
     * @return list<array{name:string,category:string}>
     */
    private function BuildPurchasedPayload(): array
    {
        $history = $this->LoadPurchaseHistory();
        if ($history === []) {
            return [];
        }
        uasort($history, static fn(array $a, array $b): int => (int)($b['last'] ?? 0) <=> (int)($a['last'] ?? 0));
        $recent = array_slice($history, 0, self::PURCHASE_PAYLOAD_LIMIT, true);

        $out = [];
        foreach ($recent as $entry) {
            $name = trim((string)($entry['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            // count und last bleiben im Attribut: die Oberflächen zeigen sie nicht,
            // und jedes Feld geht nach jeder Mutation erneut über die Leitung.
            // amount und notes gehen mit, weil die Zeile einen Mengenregler hat und
            // der Editor beides bearbeitet.
            $out[] = [
                'name'     => $name,
                'category' => (string)($entry['category'] ?? ''),
                'amount'   => (string)($entry['amount'] ?? '1'),
                'notes'    => (string)($entry['notes'] ?? ''),
            ];
        }
        usort($out, static fn(array $a, array $b): int => strcoll($a['name'], $b['name']));
        return $out;
    }

    /**
     * Einen Eintrag ändern. Ein geänderter Name zieht den Schlüssel mit — sonst
     * stünden Alt und Neu nebeneinander in der Liste.
     *
     * Trifft der neue Name einen bestehenden Eintrag, werden beide zusammengeführt
     * (höherer Zähler, jüngeres Datum gewinnen); zwei Zeilen mit demselben Namen
     * wären in allen drei Oberflächen ein Problem, iOS führt seine Zeilen sogar über
     * den Namen (ForEach id: \.name).
     */
    private function UpdatePurchaseInternal(string $OldName, string $Name, string $Category, string $Amount, string $Notes): bool
    {
        $oldKey = mb_strtolower(trim($OldName));
        $name   = trim($Name) !== '' ? trim($Name) : trim($OldName);
        $newKey = mb_strtolower($name);
        if ($oldKey === '' || $newKey === '') {
            return false;
        }
        $semaphoreKey = 'SL_Purchases_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($semaphoreKey, 500)) {
            $this->SendDebug('PurchaseStore', 'Semaphore timeout on UpdatePurchase', 0);
            return false;
        }
        try {
            $history = $this->LoadPurchaseHistory();
            if (!isset($history[$oldKey])) {
                return false;
            }
            $entry = $history[$oldKey];
            $merge = ($newKey !== $oldKey && isset($history[$newKey])) ? $history[$newKey] : null;
            unset($history[$oldKey]);
            $history[$newKey] = [
                'name'     => $name,
                'category' => trim($Category),
                'amount'   => trim($Amount) !== '' ? trim($Amount) : '1',
                'notes'    => trim($Notes),
                'count'    => max((int)($entry['count'] ?? 1), (int)($merge['count'] ?? 0)),
                'last'     => max((int)($entry['last'] ?? 0), (int)($merge['last'] ?? 0)),
            ];
            return $this->WritePurchaseAttribute($history);
        } finally {
            IPS_SemaphoreLeave($semaphoreKey);
        }
    }

    /** Einen Eintrag vergessen (Fehlkauf, Tippfehler). */
    private function ForgetPurchaseInternal(string $Name): bool
    {
        $key = mb_strtolower(trim($Name));
        if ($key === '') {
            return false;
        }
        $semaphoreKey = 'SL_Purchases_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($semaphoreKey, 500)) {
            $this->SendDebug('PurchaseStore', 'Semaphore timeout on ForgetPurchase', 0);
            return false;
        }
        try {
            $history = $this->LoadPurchaseHistory();
            if (!isset($history[$key])) {
                return false;
            }
            unset($history[$key]);
            return $this->WritePurchaseAttribute($history);
        } finally {
            IPS_SemaphoreLeave($semaphoreKey);
        }
    }

    /** Diagnose/Test: die vollständige Historie als JSON. */
    public function GetPurchaseHistory(): string
    {
        return json_encode(array_values($this->LoadPurchaseHistory()), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}

<?php

declare(strict_types=1);

/**
 * Der Essensplan für die Web-App.
 *
 * Die Web-App zeigt den Plan als eigenen Bereich und fragt dafür das Gateway
 * (Route `mealplan`), das hierher weiterreicht. Anders als die Kachel braucht
 * sie eine ANTWORT auf jede Anfrage — RequestAction gibt nichts zurück, deshalb
 * läuft der Weg über die öffentliche Funktion MPL_AppAction. Die Aktionen sind
 * dieselben wie die Kachel-Idents und rechnen mit denselben Helfern.
 *
 * Bewusst NICHT dabei: der Rezept-Scan (SaveScan). Neue Rezepte entstehen in
 * der Kachel oder in der Einkaufsliste.
 */
trait MealApp
{
    /**
     * Eine Anfrage der Web-App beantworten.
     *
     * @param array<string,mixed> $anfrage {action, …}
     * @return array<string,mixed> {ok:true, …} oder {ok:false, error:{code,message}}
     */
    private function AppAntwort(array $anfrage, int $jetzt): array
    {
        $aktion = trim((string)($anfrage['action'] ?? 'state'));
        switch ($aktion) {
            case 'state':
                return $this->AppStand($jetzt);

            case 'setMeal':
                $datum  = trim((string)($anfrage['date'] ?? ''));
                $listId = trim((string)($anfrage['listId'] ?? ''));
                if (!$this->AppIstDatum($datum)) {
                    return $this->AppFehler('invalid_payload', 'Invalid date');
                }
                if ($this->QuellListe() <= 0) {
                    return $this->AppFehler('no_source', 'No shopping list selected');
                }
                // Nur Rezepte, die es gibt — ein erfundener Verweis zeigte sonst
                // als „(Rezept gelöscht)" auf.
                if ($listId !== '' && !isset($this->Favoriten()[$listId])) {
                    return $this->AppFehler('not_found', 'Unknown recipe');
                }
                $this->GerichtSetzen($datum, $listId, (string)($anfrage['text'] ?? ''), $jetzt);
                // Die Kachel zieht mit, als hätte man dort geplant.
                $this->PushState();
                return $this->AppStand($jetzt);

            case 'detail':
                $datum = trim((string)($anfrage['date'] ?? ''));
                if (!$this->AppIstDatum($datum)) {
                    return $this->AppFehler('invalid_payload', 'Invalid date');
                }
                $detail = $this->GerichtDetail($datum, true);
                unset($detail['type']);
                return ['ok' => true, 'detail' => $detail];

            case 'weekDetail':
                $montag = trim((string)($anfrage['monday'] ?? ''));
                if (!$this->AppIstDatum($montag)) {
                    return $this->AppFehler('invalid_payload', 'Invalid date');
                }
                return ['ok' => true, 'monday' => $montag, 'ingredients' => $this->WochenZutaten($montag)];

            case 'addToCart':
                $ok = $this->ZutatenUebernehmen((string)($anfrage['listId'] ?? ''));
                return $ok ? ['ok' => true, 'count' => 1] : $this->AppFehler('failed', 'Nothing added');

            case 'addItemsToCart':
                $anzahl = $this->KorbUebernehmen($anfrage);
                return $anzahl > 0 ? ['ok' => true, 'count' => $anzahl] : $this->AppFehler('failed', 'Nothing added');

            case 'setServings':
                return $this->PortionenSetzen($anfrage)
                    ? ['ok' => true]
                    : $this->AppFehler('failed', 'Servings not saved');
        }
        return $this->AppFehler('unknown_action', 'Unknown action');
    }

    /** Der Wochenstand ohne die Kachel-Texte — die Web-App übersetzt selbst. */
    private function AppStand(int $jetzt): array
    {
        $stand = $this->PayloadBauen($jetzt, true);
        unset($stand['type'], $stand['texts']);
        return ['ok' => true, 'mealplan' => $stand];
    }

    /**
     * Die gewählten Zutaten in den Einkaufswagen — {targetId?, listId?, items[]}.
     * Jede Zeile trägt ihre Herkunft (listId), damit auch eine ganze Woche in
     * einem Aufruf gehen kann; ohne eigene gilt die des Aufrufs. Übernommen
     * wird je Rezept.
     *
     * @param array<string,mixed> $daten
     * @return int Zahl der Rezepte, aus denen etwas übernommen wurde
     */
    private function KorbUebernehmen(array $daten): int
    {
        $ziel = (int)($daten['targetId'] ?? 0);
        $nachRezept = [];
        foreach (is_array($daten['items'] ?? null) ? $daten['items'] : [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $lid = trim((string)($item['listId'] ?? ($daten['listId'] ?? '')));
            if ($lid !== '') {
                $nachRezept[$lid][] = $item;
            }
        }
        $erfolg = 0;
        foreach ($nachRezept as $lid => $zeilen) {
            if ($this->KorbAuswahl((string)$lid, $ziel, $zeilen)) {
                $erfolg++;
            }
        }
        return $erfolg;
    }

    /**
     * Portionszahl eines Rezepts dauerhaft ändern — {listId, servings, amounts}.
     * Gespeichert wird an der Favoritenliste der Einkaufsliste.
     *
     * @param array<string,mixed> $daten
     */
    private function PortionenSetzen(array $daten): bool
    {
        $sl = $this->QuellListe();
        $listId = trim((string)($daten['listId'] ?? ''));
        $portionen = (int)($daten['servings'] ?? 0);
        if ($sl <= 0 || $listId === '' || $portionen < 1 || $portionen > 99) {
            return false;
        }
        $mengen = [];
        foreach (is_array($daten['amounts'] ?? null) ? $daten['amounts'] : [] as $name => $menge) {
            if (is_string($name) && (is_string($menge) || is_int($menge) || is_float($menge))) {
                $mengen[mb_substr($name, 0, 120)] = mb_substr(trim((string)$menge), 0, 40);
            }
        }
        try {
            IPS_RequestAction($sl, 'SetFavoriteServings', json_encode([
                'listId'   => $listId,
                'servings' => $portionen,
                'amounts'  => $mengen,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return true;
        } catch (\Throwable $e) {
            $this->SendDebug('MealPlan', 'Portionen speichern fehlgeschlagen: ' . $e->getMessage(), 0);
            return false;
        }
    }

    private function AppIstDatum(string $datum): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum) === 1
            && checkdate((int)substr($datum, 5, 2), (int)substr($datum, 8, 2), (int)substr($datum, 0, 4));
    }

    /** @return array{ok:false, error:array{code:string,message:string}} */
    private function AppFehler(string $code, string $text): array
    {
        return ['ok' => false, 'error' => ['code' => $code, 'message' => $text]];
    }
}

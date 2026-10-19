<?php

declare(strict_types=1);

/**
 * Der Essensplan fürs Briefing.
 *
 * Das Gateway pflegt hier nichts — es fragt die Essensplan-Instanz nach dem
 * Gericht des Tages und reicht die Zeile ans Briefing weiter. Muster wie die
 * Stundenplan-Brücke: jeder Aufruf hängt hinter function_exists, ein fehlendes
 * oder kaputtes Modul kostet höchstens die Zeile, nie das Briefing.
 */
trait MealPlanBridge
{
    private const MEALPLAN_MODULE_GUID = '{94E5ED88-1017-4A73-B9BF-2B2010679022}';

    /**
     * Die Gericht-Zeile(n) für einen Tag („YYYY-MM-DD") — leer, wenn kein
     * Essensplan existiert oder nichts geplant ist.
     *
     * @return list<string>
     */
    private function MealPlanLines(string $datum): array
    {
        if (!function_exists('MPL_GetMealForDate')) {
            return [];
        }
        foreach ((array)@IPS_GetInstanceListByModuleID(self::MEALPLAN_MODULE_GUID) as $id) {
            try {
                $gericht = json_decode((string)@MPL_GetMealForDate((int)$id, $datum), true);
            } catch (\Throwable $e) {
                continue;
            }
            $titel = is_array($gericht) ? trim((string)($gericht['title'] ?? '')) : '';
            if ($titel !== '') {
                // Die erste Instanz mit einem Gericht gewinnt — mehrere
                // Essensplaene je Haushalt sind kein gedachter Fall.
                return [$titel];
            }
        }
        return [];
    }

    /** Die Aktionen, die die Web-App stellen darf — alles andere kommt nicht durch. */
    private const MEALPLAN_AKTIONEN = ['state', 'setMeal', 'detail', 'weekDetail',
                                      'addToCart', 'addItemsToCart', 'setServings'];

    /** Die Felder, die eine Aktion mitbringen darf. */
    private const MEALPLAN_FELDER = ['date', 'listId', 'text', 'monday', 'targetId', 'items', 'servings', 'amounts'];

    /**
     * Der Essensplan für die Web-App: REST `POST /v1/mealplan` und der
     * Kachel-Weg über AiTileRequest. Gerechnet und gespeichert wird im
     * Essensplan-Modul (MPL_AppAction) — das Gateway reicht nur durch.
     *
     * Ohne Essensplan-Instanz: {ok:true, mealplan:null} — die Web-App zeigt
     * den Bereich dann nicht. Bei mehreren Instanzen gilt die niedrigste
     * Kennung (mehrere Essenspläne je Haushalt sind kein gedachter Fall).
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function MealPlanHandleAction(array $body): array
    {
        $id = $this->MealPlanInstanz();
        if ($id === 0) {
            return ['ok' => true, 'mealplan' => null];
        }
        $aktion = trim((string)($body['action'] ?? 'state'));
        if (!in_array($aktion, self::MEALPLAN_AKTIONEN, true)) {
            return ['ok' => false, 'error' => ['code' => 'unknown_action', 'message' => 'Unknown action']];
        }
        $anfrage = ['action' => $aktion];
        foreach (self::MEALPLAN_FELDER as $feld) {
            if (array_key_exists($feld, $body)) {
                $anfrage[$feld] = $body[$feld];
            }
        }
        try {
            $antwort = json_decode((string)MPL_AppAction($id, (string)json_encode($anfrage,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), true);
        } catch (\Throwable $e) {
            $this->SendDebug('MealPlan', $e->getMessage(), 0);
            $antwort = null;
        }
        return is_array($antwort)
            ? $antwort
            : ['ok' => false, 'error' => ['code' => 'unavailable', 'message' => 'Meal plan not reachable']];
    }

    /** Die Essensplan-Instanz der Web-App (niedrigste Kennung) — 0, wenn keine da ist. */
    private function MealPlanInstanz(): int
    {
        if (!function_exists('MPL_AppAction')) {
            return 0;
        }
        $ids = array_map('intval', (array)@IPS_GetInstanceListByModuleID(self::MEALPLAN_MODULE_GUID));
        sort($ids);
        return (int)($ids[0] ?? 0);
    }
}

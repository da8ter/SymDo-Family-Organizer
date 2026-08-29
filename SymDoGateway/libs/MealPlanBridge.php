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
}

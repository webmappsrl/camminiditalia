<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Percorrenze di una tappa o di un cammino (oc:8169, ciclo 4). La tabella dei legami tappa-registrazione
 * contiene le registrazioni di qualsiasi anno: i giorni si dividono in percorrenze, separate da più di
 * GAP_DAYS giorni, e si usa la più recente.
 */
class WalkPeriods
{
    /** Giorni oltre i quali due date appartengono a percorrenze diverse. */
    public const GAP_DAYS = 30;

    /**
     * @param  array<int, string>  $dates  giorni `Y-m-d`
     * @return array<int, string> i giorni dell'ultima percorrenza, ordinati e senza doppioni
     */
    public static function latest(array $dates): array
    {
        $days = array_values(array_unique($dates));
        sort($days);
        $count = count($days);
        if ($count === 0) {
            return [];
        }
        $start = $count - 1;
        while ($start > 0 && Carbon::parse($days[$start - 1])->diffInDays(Carbon::parse($days[$start])) <= self::GAP_DAYS) {
            $start--;
        }

        return array_slice($days, $start);
    }
}

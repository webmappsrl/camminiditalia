<?php

namespace App\Support;

use Carbon\Carbon;
use Throwable;

/**
 * Giorno di una registrazione (oc:8169): il primo punto, poi `properties.createdAt`, poi la riga, nel
 * fuso di Roma. Unico calcolo per le date note del Passaporto e per il collegamento dei diari.
 */
class UgcRecordingDate
{
    private const TIMEZONE = 'Europe/Rome';

    /**
     * @param  string|int|float|null  $firstPointMs  `locations[0].time` in millisecondi
     * @return string|null il giorno `Y-m-d`, `null` se nessun valore è leggibile
     */
    public static function day(string|int|float|null $firstPointMs, ?string $createdProp, ?string $createdAt): ?string
    {
        if (is_numeric($firstPointMs)) {
            return Carbon::createFromTimestampMs((float) $firstPointMs)->setTimezone(self::TIMEZONE)->format('Y-m-d');
        }
        foreach ([$createdProp, $createdAt] as $value) {
            if (is_string($value) && $value !== '') {
                try {
                    return Carbon::parse($value)->setTimezone(self::TIMEZONE)->format('Y-m-d');
                } catch (Throwable) {
                    continue;
                }
            }
        }

        return null;
    }
}

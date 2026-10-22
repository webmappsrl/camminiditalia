<?php

namespace App\Support;

use Wm\WmPackage\Models\Layer;

/**
 * Regole «cammino acceso» del passaporto (oc:8763, separate in oc:8809): due
 * flag indipendenti in `properties` del cammino, ciascuno valido solo se è il
 * booleano `true` (stringhe, numeri e valori mancanti contano come spento).
 *
 * - `passport_enabled`: passaporto e richieste di certificazione cartacea;
 * - `passport_gps_enabled`: tappe sbloccate in automatico dalle tracce GPS.
 *
 * Vincolo: il GPS acceso implica il cartaceo acceso (`normalize()`, applicata al
 * salvataggio da `PassportLayerObserver::saving()`). Regole uniche per middleware API e query.
 */
class PassportLayer
{
    public const PAPER_KEY = 'passport_enabled';

    public const GPS_KEY = 'passport_gps_enabled';

    public static function isPaperEnabled(Layer $layer): bool
    {
        return self::paperEnabledIn($layer->properties ?? []);
    }

    public static function isGpsEnabled(Layer $layer): bool
    {
        return self::gpsEnabledIn($layer->properties ?? []);
    }

    /**
     * La regola del flag cartaceo su un array di properties: solo il booleano `true` vale.
     *
     * @param  array<string, mixed>  $properties
     */
    public static function paperEnabledIn(array $properties): bool
    {
        return ($properties[self::PAPER_KEY] ?? null) === true;
    }

    /**
     * La regola del flag GPS su un array di properties: solo il booleano `true` vale.
     *
     * @param  array<string, mixed>  $properties
     */
    public static function gpsEnabledIn(array $properties): bool
    {
        return ($properties[self::GPS_KEY] ?? null) === true;
    }

    /**
     * Applica il vincolo «GPS acceso ⇒ cartaceo acceso»: con il GPS a `true` il cartaceo
     * diventa `true`, qualunque valore avesse. Altrimenti l'array resta invariato (un GPS non
     * booleano conta come spento, come in `isGpsEnabled()`).
     *
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    public static function normalize(array $properties): array
    {
        if (self::gpsEnabledIn($properties)) {
            $properties[self::PAPER_KEY] = true;
        }

        return $properties;
    }

    /**
     * Condizione SQL (PostgreSQL) equivalente a `isPaperEnabled()`, per le query.
     */
    public static function paperEnabledSql(string $alias = 'layers'): string
    {
        return self::flagSql(self::PAPER_KEY, $alias);
    }

    /**
     * Condizione SQL (PostgreSQL) equivalente a `isGpsEnabled()`, per le query.
     */
    public static function gpsEnabledSql(string $alias = 'layers'): string
    {
        return self::flagSql(self::GPS_KEY, $alias);
    }

    /**
     * Confronta il valore jsonb con il booleano jsonb `true` (`->`, non `->>`):
     * la stringa "true", il numero 1 e il valore mancante non coincidono,
     * come in PHP con `=== true`. `properties` è jsonb; il cast è esplicito
     * per restare corretto anche se la colonna fosse json.
     */
    private static function flagSql(string $key, string $alias): string
    {
        return "(({$alias}.properties)::jsonb -> '{$key}') = 'true'::jsonb";
    }
}

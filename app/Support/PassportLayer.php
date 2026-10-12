<?php

namespace App\Support;

use Wm\WmPackage\Models\Layer;

/**
 * Regola «cammino acceso» del passaporto (oc:8763): il passaporto vale solo
 * sui cammini con `properties->passport_enabled` uguale al booleano `true`.
 * Stringhe, numeri e valori mancanti contano come spento. Regola unica per
 * middleware API e query.
 */
class PassportLayer
{
    public static function isEnabled(Layer $layer): bool
    {
        return ($layer->properties['passport_enabled'] ?? null) === true;
    }

    /**
     * Condizione SQL (PostgreSQL) equivalente a `isEnabled()`, per le query.
     * Confronta il valore jsonb con il booleano jsonb `true` (`->`, non `->>`):
     * la stringa "true", il numero 1 e il valore mancante non coincidono,
     * come in PHP con `=== true`. `properties` è jsonb; il cast è esplicito
     * per restare corretto anche se la colonna fosse json.
     */
    public static function enabledSql(string $alias = 'layers'): string
    {
        return "(({$alias}.properties)::jsonb -> 'passport_enabled') = 'true'::jsonb";
    }
}

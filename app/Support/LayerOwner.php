<?php

namespace App\Support;

use Wm\WmPackage\Models\Layer;

/**
 * Proprietario effettivo di un layer: `user_id`, oppure il proprietario di
 * default di camminiditalia (CAMMINIDITALIA_DEFAULT_OWNER_ID) se il layer non
 * ne ha uno. Regola unica per vista Nova del layer, trasferimento ownership e
 * tappe selezionabili nella decisione del passaporto.
 */
class LayerOwner
{
    public static function idFor(Layer $layer): ?int
    {
        $ownerId = $layer->user_id ?? config('camminiditalia.default_owner_id');

        return $ownerId !== null ? (int) $ownerId : null;
    }
}

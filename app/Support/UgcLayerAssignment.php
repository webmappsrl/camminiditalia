<?php

namespace App\Support;

class UgcLayerAssignment
{
    /**
     * Applica un'assegnazione di layer a un array di properties UGC,
     * allineando anche properties.form.layer_id quando la chiave `form`
     * esiste — stessa logica riusata sia dalla risoluzione automatica
     * (ResolveUgcLayerJob) sia dall'override manuale da Nova.
     */
    public static function apply(array $properties, ?int $layerId, bool $autoResolved): array
    {
        $properties['layer_id'] = $layerId;
        $properties['layer_id_auto_resolved'] = $autoResolved;

        if (isset($properties['form']) && is_array($properties['form'])) {
            $properties['form']['layer_id'] = $layerId;
        }

        return $properties;
    }
}

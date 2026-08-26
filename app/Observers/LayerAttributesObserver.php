<?php

namespace App\Observers;

use App\Jobs\RecalculateLayerAttributesJob;
use Wm\WmPackage\Models\Layerable;

/**
 * Accoda il ricalcolo degli attributi di un cammino quando cambia l'insieme
 * delle sue tappe.
 *
 * Classe separata da LayerableObserver di proposito: i guard di
 * quest'ultimo (esce se il layer non ha owner, o se la traccia non ha
 * POI) servono alla sua logica di ownership/POI e scarterebbero i casi
 * più comuni per il ricalcolo. Qui si valida solo ciò che serve al
 * calcolo: il tipo di risorsa e l'esistenza del layer.
 */
class LayerAttributesObserver
{
    public function created(Layerable $layerable): void
    {
        $this->dispatchRecalculation($layerable);
    }

    public function deleted(Layerable $layerable): void
    {
        $this->dispatchRecalculation($layerable);
    }

    private function dispatchRecalculation(Layerable $layerable): void
    {
        $trackType = config('wm-package.ec_track_model', 'App\Models\EcTrack');

        // Solo le tappe influenzano gli attributi calcolati: i POI no.
        if ($layerable->layerable_type !== $trackType) {
            return;
        }

        // layer_id è NOT NULL a livello di schema (verificato), quindi qui
        // non serve un guard: il dispatch avviene sempre.
        RecalculateLayerAttributesJob::dispatch((int) $layerable->layer_id);
    }
}

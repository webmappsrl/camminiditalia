<?php

namespace App\Observers;

use App\Jobs\RecalculateLayerAttributesJob;
use App\Models\EcTrackVariant;
use Illuminate\Support\Facades\DB;

/**
 * Accoda il ricalcolo degli attributi dei cammini coinvolti quando cambia una variante
 * (oc:8918).
 *
 * `stage_count` e `distance` di un cammino contano una sola volta una tappa e le sue
 * varianti, quindi dichiarare, cambiare o togliere una variante ne cambia il valore anche se
 * l'insieme delle tappe del layer non si muove (e `LayerAttributesObserver` non scatta).
 *
 * I layer da ricalcolare sono quelli della variante, della principale e, se la principale
 * è cambiata, di quella precedente: un dispatch per layer distinto.
 */
class EcTrackVariantObserver
{
    public function created(EcTrackVariant $variant): void
    {
        $this->dispatchRecalculation([$variant->ec_track_id, $variant->main_ec_track_id]);
    }

    public function updated(EcTrackVariant $variant): void
    {
        $this->dispatchRecalculation([
            $variant->ec_track_id,
            $variant->main_ec_track_id,
            $variant->getOriginal('main_ec_track_id'),
        ]);
    }

    public function deleted(EcTrackVariant $variant): void
    {
        $this->dispatchRecalculation([$variant->ec_track_id, $variant->main_ec_track_id]);
    }

    /**
     * @param  array<int|null>  $trackIds
     */
    private function dispatchRecalculation(array $trackIds): void
    {
        $trackType = config('wm-package.ec_track_model', 'App\Models\EcTrack');

        $layerIds = DB::table('layerables')
            ->where('layerable_type', $trackType)
            ->whereIn('layerable_id', array_filter(array_unique($trackIds)))
            ->distinct()
            ->pluck('layer_id');

        foreach ($layerIds as $layerId) {
            RecalculateLayerAttributesJob::dispatch((int) $layerId);
        }
    }
}

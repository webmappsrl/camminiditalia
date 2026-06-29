<?php

namespace App\Observers;

use App\Models\EcPoi;
use App\Models\EcTrack;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Models\EcPoiEcTrack;
use Wm\WmPackage\Models\Layerable;

class LayerableObserver
{
    public function created(Layerable $layerable): void
    {
        $allowedTypes = [EcTrack::class, EcPoi::class];

        if (! in_array($layerable->layerable_type, $allowedTypes)) {
            return;
        }

        $layer = $layerable->layer;

        if (! $layer || is_null($layer->user_id)) {
            return;
        }

        $model = $layerable->model;

        if (! $model) {
            return;
        }

        $model->update(['user_id' => $layer->user_id]);

        Log::info('Layerable ownership transfer', [
            'layer_id' => $layer->id,
            'layer_owner_id' => $layer->user_id,
            'resource_type' => $layerable->layerable_type,
            'resource_id' => $layerable->layerable_id,
        ]);
    }

    public function deleted(Layerable $layerable): void
    {
        if ($layerable->layerable_type !== EcTrack::class) {
            return;
        }

        $layer = $layerable->layer;
        if (! $layer) {
            return;
        }

        $ecTrack = EcTrack::find($layerable->layerable_id);
        if (! $ecTrack) {
            return;
        }

        $trackPoiIds = $ecTrack->ecPois()->pluck('ec_pois.id')->toArray();
        if (empty($trackPoiIds)) {
            return;
        }

        $poiIdsToRemove = array_values(array_filter($trackPoiIds, fn ($poiId) => ! EcPoiEcTrack::poiStillLinkedToLayerViaOtherTrack(
            $layer->id, $poiId, $layerable->layerable_id
        )));

        if (! empty($poiIdsToRemove)) {
            $layer->ecPois()->detach($poiIdsToRemove);

            Log::info('LayerableObserver: removed orphan POIs from layer after track detach', [
                'layer_id' => $layer->id,
                'removed_ec_track_id' => $layerable->layerable_id,
                'poi_ids_removed' => $poiIdsToRemove,
            ]);
        }
    }
}

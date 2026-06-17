<?php

namespace App\Observers;

use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Observers\LayerObserver as WmLayerObserver;

class LayerObserver extends WmLayerObserver
{
    public function saved(Layer $layer): void
    {
        parent::saved($layer);

        if (! $layer->wasRecentlyCreated && ! $layer->wasChanged('user_id')) {
            return;
        }

        $newOwnerId = $layer->user_id ?? config('camminiditalia.default_owner_id');
        $oldOwnerId = $layer->getOriginal('user_id');

        $trackIds = $layer->ecTracks()->pluck('ec_tracks.id')->toArray();
        $poiIds = $layer->manualEcPois()->pluck('ec_pois.id')->toArray();

        if (! empty($trackIds)) {
            $layer->ecTracks()->update(['user_id' => $newOwnerId]);
        }

        if (! empty($poiIds)) {
            $layer->manualEcPois()->update(['user_id' => $newOwnerId]);
        }

        Log::info('Layer ownership transfer', [
            'layer_id' => $layer->id,
            'old_owner_id' => $oldOwnerId,
            'new_owner_id' => $newOwnerId,
            'track_ids' => $trackIds,
            'poi_ids' => $poiIds,
        ]);
    }
}

<?php

namespace App\Observers;

use App\Jobs\RecalculateLayerAttributesJob;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Observers\LayerObserver as WmLayerObserver;

class LayerObserver extends WmLayerObserver
{
    public function saved(Layer $layer): void
    {
        parent::saved($layer);

        // Auto-riparazione (oc:8180): HasTranslations su name/title/subtitle/
        // description fa marcare `properties` come dirty su QUALSIASI save(),
        // anche quando non si tocca `properties`. persistCalculatedValues()
        // scrive le chiavi calcolate con un UPDATE SQL diretto, fuori da
        // Eloquent: se un save() successivo (es. da Nova) riscrive il blob
        // properties letto prima del ricalcolo, le chiavi calcolate vengono
        // silenziosamente perse. Riaccodare qui il ricalcolo ad ogni save()
        // le riscrive subito dopo. Nessun rischio di ciclo: il job scrive via
        // SQL diretto, non tramite Eloquent, quindi non fa scattare di nuovo
        // questo observer (verificato con test dedicato).
        RecalculateLayerAttributesJob::dispatch($layer->id);

        if (! $layer->wasRecentlyCreated && ! $layer->wasChanged('user_id')) {
            return;
        }

        $newOwnerId = $layer->user_id ?? config('camminiditalia.default_owner_id');
        $oldOwnerId = $layer->getOriginal('user_id');

        $trackIds = $layer->ecTracks()->pluck('ec_tracks.id')->toArray();
        $poiIds = $layer->ecPois()->pluck('ec_pois.id')->toArray();

        if (! empty($trackIds)) {
            $layer->ecTracks()->update(['user_id' => $newOwnerId]);
        }

        if (! empty($poiIds)) {
            $layer->ecPois()->update(['user_id' => $newOwnerId]);
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

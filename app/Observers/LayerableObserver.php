<?php

namespace App\Observers;

use App\Models\EcPoi;
use App\Models\EcTrack;
use Illuminate\Support\Facades\Log;
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
}

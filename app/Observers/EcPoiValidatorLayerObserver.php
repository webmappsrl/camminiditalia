<?php

namespace App\Observers;

use App\Models\User;
use Illuminate\Support\Collection;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\Layer;

class EcPoiValidatorLayerObserver
{
    public function created(EcPoi $ecPoi): void
    {
        $user = $ecPoi->user_id ? User::find($ecPoi->user_id) : null;

        if (! $user || ! $user->hasRole('Validator')) {
            return;
        }

        /** @var Collection<int, Layer> $layers */
        $layers = $user->layers()->get();

        if ($layers->isEmpty()) {
            return;
        }

        if ($layers->count() === 1) {
            $layers->first()->ecPois()->syncWithoutDetaching([$ecPoi->id]);

            return;
        }

        $layerId = $ecPoi->properties['layer_id'] ?? null;

        if (is_numeric($layerId) && $layers->pluck('id')->contains((int) $layerId)) {
            $layers->firstWhere('id', (int) $layerId)?->ecPois()->syncWithoutDetaching([$ecPoi->id]);
        }
    }
}

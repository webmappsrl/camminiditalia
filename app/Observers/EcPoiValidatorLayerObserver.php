<?php

namespace App\Observers;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class EcPoiValidatorLayerObserver
{
    public function created(Model $ecPoi): void
    {
        $user = $ecPoi->user_id ? User::find($ecPoi->user_id) : null;

        if (! $user || ! $user->hasRole('Validator')) {
            return;
        }

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

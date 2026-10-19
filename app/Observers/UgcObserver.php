<?php

namespace App\Observers;

use App\Jobs\ResolveUgcLayerJob;
use App\Jobs\SendUgcReportMailJob;
use Illuminate\Database\Eloquent\Model;
use Wm\WmPackage\Models\Abstracts\GeometryModel;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Observers\UgcObserver as BaseUgcObserver;

class UgcObserver extends BaseUgcObserver
{
    public function created(Model $model): void
    {
        parent::created($model);

        // oc:8773: una UgcTrack non ha layer_id: la corrispondenza con i cammini è calcolata dal
        // package (MatchUgcTrackToEcTracksJob), quindi niente lettura di layer_id, mail o
        // ResolveUgcLayerJob.
        if ($model instanceof UgcTrack) {
            return;
        }

        if (! $model instanceof GeometryModel) {
            return;
        }

        $formId = $model->properties['form']['id'] ?? null;
        if ($formId !== 'report') {
            return;
        }

        // Se form.layer_id è esplicitamente null, l'utente ha scelto di non specificarlo
        $form = $model->properties['form'] ?? [];
        if (array_key_exists('layer_id', $form) && $form['layer_id'] === null) {
            return;
        }

        $layerId = $model->properties['layer_id'] ?? null;

        if ($layerId) {
            $layer = Layer::find($layerId);
            if ($layer) {
                SendUgcReportMailJob::dispatch($model, $layer);
            }

            return;
        }

        // layer_id assente: risoluzione automatica via job
        ResolveUgcLayerJob::dispatch($model);
    }
}

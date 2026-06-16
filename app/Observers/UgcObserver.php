<?php

namespace App\Observers;

use App\Jobs\ResolveUgcLayerJob;
use App\Jobs\SendUgcReportMailJob;
use Illuminate\Database\Eloquent\Model;
use Wm\WmPackage\Models\Abstracts\GeometryModel;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Observers\UgcObserver as BaseUgcObserver;

class UgcObserver extends BaseUgcObserver
{
    public function created(Model $model): void
    {
        parent::created($model);

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

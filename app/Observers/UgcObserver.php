<?php

namespace App\Observers;

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

        $layerId = $model->properties['layer_id'] ?? null;
        if (! $layerId) {
            return;
        }

        $layer = Layer::find($layerId);
        if (! $layer) {
            return;
        }

        SendUgcReportMailJob::dispatch($model, $layer);
    }
}

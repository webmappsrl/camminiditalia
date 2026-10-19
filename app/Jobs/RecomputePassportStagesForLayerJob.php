<?php

namespace App\Jobs;

use App\Services\StageValidationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Popola il passaporto di un cammino appena acceso (oc:8165).
 *
 * Tocca tutte le persone che hanno camminato sulle sue tappe, quindi sta in coda e non nella
 * richiesta che accende il flag.
 */
class RecomputePassportStagesForLayerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $layerId)
    {
        $this->onQueue(config('wm-package.ugc_track_matching.queue', 'ugc-track-matching'));
    }

    public function handle(StageValidationService $service): void
    {
        $service->recomputeForLayer($this->layerId);
    }
}

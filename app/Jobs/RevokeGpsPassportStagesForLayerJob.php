<?php

namespace App\Jobs;

use App\Services\StageValidationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Revoca le tappe sbloccate dal GPS su un cammino su cui il GPS è appena stato spento (oc:8809).
 *
 * Tocca le tappe di tutte le persone che le hanno percorse, quindi sta in coda.
 */
class RevokeGpsPassportStagesForLayerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $layerId)
    {
        $this->onQueue(config('wm-package.ugc_track_matching.queue', 'ugc-track-matching'));
    }

    public function handle(StageValidationService $service): void
    {
        $service->revokeGpsForLayer($this->layerId);
    }
}

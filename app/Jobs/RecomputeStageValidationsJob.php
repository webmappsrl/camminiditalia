<?php

namespace App\Jobs;

use App\Services\StageValidationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Ricalcola l'avanzamento del passaporto di una persona (oc:8165).
 *
 * Parte quando la corrispondenza di una sua UGC track è stata calcolata
 * (`UgcTrackMatchingRunObserver`): a quel punto i segmenti percorsi sono aggiornati e il
 * verdetto può cambiare. Unico per persona finché non parte, perché chi sincronizza più
 * UGC track di fila ne accoderebbe uno per ciascuna e il risultato sarebbe lo stesso.
 *
 * Non copre il caso in cui cambi la geometria di una tappa: lì il package ricalcola le
 * corrispondenze senza toccare lo stato delle UGC track, e il riallineamento si fa con
 * `camminiditalia:compute-stage-validations`.
 */
class RecomputeStageValidationsJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 3600;

    public function __construct(public int $userId)
    {
        // Coda propria: un ricalcolo di massa non deve mettersi in fila davanti al resto.
        $this->onQueue(config('wm-package.ugc_track_matching.queue', 'ugc-track-matching'));
    }

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    /**
     * Su `CACHE_STORE=database` il lock di unicità, dentro una transazione, fa fallire Postgres
     * con 25P02: si usa Redis, come già in wm-package.
     */
    public function uniqueVia()
    {
        return Cache::store('redis');
    }

    public function handle(StageValidationService $service): void
    {
        $service->recomputeForUser($this->userId);
    }
}

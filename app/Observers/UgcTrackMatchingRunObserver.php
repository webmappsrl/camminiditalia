<?php

namespace App\Observers;

use App\Jobs\RecomputeStageValidationsJob;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Models\UgcTrackMatchingRun;

/**
 * Aggancia il passaporto alla fine del calcolo di corrispondenza (oc:8165).
 *
 * `UgcTrackMatchingService::markComputed()` scrive questa riga con Eloquent quando i segmenti
 * percorsi da una UGC track sono pronti: è il momento in cui il verdetto sulle tappe di quella
 * persona può cambiare. Agganciarsi qui evita di toccare `wm-package`, che non emette eventi a
 * fine calcolo, e di accodare il ricalcolo prima che ci sia qualcosa da aggregare.
 *
 * Le UGC track importate non concorrono al passaporto, quindi per loro non si accoda nulla.
 */
class UgcTrackMatchingRunObserver
{
    public function saved(UgcTrackMatchingRun $run): void
    {
        if ($run->status !== UgcTrackMatchingRun::STATUS_COMPUTED) {
            return;
        }

        // Solo le chiavi, non il modello intero: `properties` porta con sé i punti grezzi del
        // GPS, e durante un ricalcolo di massa sarebbero centinaia di letture pesanti per
        // rispondere a una domanda di una riga.
        $track = UgcTrack::recorded()->whereKey($run->ugc_track_id)->first(['id', 'user_id']);

        if ($track === null) {
            return;
        }

        RecomputeStageValidationsJob::dispatch((int) $track->user_id)->afterCommit();
    }
}

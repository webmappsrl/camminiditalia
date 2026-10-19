<?php

namespace App\Observers;

use App\Jobs\RecomputePassportStagesForLayerJob;
use App\Support\PassportLayer;
use Wm\WmPackage\Models\Layer;

/**
 * Accensione del passaporto su un cammino (oc:8165).
 *
 * Quando il gestore accende il flag, le tappe di quel cammino entrano nel calcolo per la prima
 * volta: chi ci ha già camminato deve trovarsi il passaporto popolato, non una pagina vuota
 * fino alla sua prossima uscita. Il calcolo gira in coda, perché tocca tutte le persone che
 * hanno camminato su quelle tappe.
 *
 * Allo spegnimento non si cancella nulla: una validazione non si revoca, e se il cammino viene
 * riacceso torna a vedersi.
 */
class PassportLayerObserver
{
    public function updated(Layer $layer): void
    {
        if (! $layer->wasChanged('properties')) {
            return;
        }

        $before = $layer->getOriginal('properties');
        $before = is_string($before) ? json_decode($before, true) : $before;

        $wasEnabled = ($before['passport_enabled'] ?? null) === true;

        if (! $wasEnabled && PassportLayer::isEnabled($layer)) {
            RecomputePassportStagesForLayerJob::dispatch($layer->id)->afterCommit();
        }
    }
}

<?php

namespace App\Observers;

use App\Jobs\RecomputePassportStagesForLayerJob;
use App\Support\PassportLayer;
use Wm\WmPackage\Models\Layer;

/**
 * Flag del passaporto su un cammino (oc:8165, oc:8809).
 *
 * `saving()` fa rispettare il vincolo «GPS acceso ⇒ cartaceo acceso».
 *
 * Accensione del GPS: le tappe di quel cammino entrano nel calcolo per la prima volta, e chi ci
 * ha già camminato deve trovarsi il passaporto popolato, non una pagina vuota fino alla sua
 * prossima uscita. Il calcolo gira in coda, perché tocca tutte le persone che hanno camminato
 * su quelle tappe.
 *
 * Spegnimento del GPS: non cancella niente, nemmeno le tappe sbloccate dal GPS («una volta che
 * c'è il timbro è fatta»). Ferma solo il calcolo automatico su quel cammino, già garantito da
 * `PassportLayer::gpsEnabledSql()` (oc:8809, scrum 23/10). Il cartaceo non muove nulla.
 */
class PassportLayerObserver
{
    public function saving(Layer $layer): void
    {
        // Il cast `array` di Layer restituisce già un array; vuoto o null non ha flag da sistemare.
        if (! $layer->properties) {
            return;
        }

        $layer->properties = PassportLayer::normalize($layer->properties);
    }

    public function updated(Layer $layer): void
    {
        if (! $layer->wasChanged('properties')) {
            return;
        }

        $before = $layer->getOriginal('properties');
        $before = is_string($before) ? json_decode($before, true) : $before;

        $this->dispatchOnGpsTurnedOn($layer, $before ?? []);
    }

    /**
     * Accoda il ricalcolo se il GPS è passato da spento ad acceso, confrontando il GPS di prima
     * con quello attuale.
     *
     * È separato da `updated()` per chi salva in silenzio (`saveQuietly()`): a salvataggio
     * concluso `getOriginal()` ha già il valore nuovo, quindi il «prima» va passato da fuori.
     *
     * @param  array<string, mixed>  $before  properties prima del salvataggio
     */
    public function dispatchOnGpsTurnedOn(Layer $layer, array $before): void
    {
        $wasGps = PassportLayer::gpsEnabledIn($before);
        $isGps = PassportLayer::isGpsEnabled($layer);

        if (! $wasGps && $isGps) {
            RecomputePassportStagesForLayerJob::dispatch($layer->id)->afterCommit();
        }
    }
}

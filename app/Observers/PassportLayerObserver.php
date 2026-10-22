<?php

namespace App\Observers;

use App\Jobs\RecomputePassportStagesForLayerJob;
use App\Jobs\RevokeGpsPassportStagesForLayerJob;
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
 * Spegnimento del GPS: si revocano le tappe sbloccate dal GPS (non quelle validate a mano), a
 * meno che la tappa non appartenga anche a un altro cammino con il GPS acceso. Il motivo
 * (oc:8809): il cliente non vuole il GPS attivo su quel cammino, quindi le validazioni
 * automatiche non devono restare.
 *
 * Il cartaceo, acceso o spento con il GPS spento, non muove nulla: spegnerlo non revoca niente.
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

        $this->dispatchForFlagChange($layer, $before ?? []);
    }

    /**
     * Accoda ricalcolo o revoca confrontando il GPS di prima con quello attuale. È separato da
     * `updated()` per chi salva in silenzio (`saveQuietly()`): a salvataggio concluso
     * `getOriginal()` ha già il valore nuovo, quindi il «prima» va passato da fuori.
     *
     * @param  array<string, mixed>  $before  properties prima del salvataggio
     */
    public function dispatchForFlagChange(Layer $layer, array $before): void
    {
        $wasGps = PassportLayer::gpsEnabledIn($before);
        $isGps = PassportLayer::isGpsEnabled($layer);

        if (! $wasGps && $isGps) {
            RecomputePassportStagesForLayerJob::dispatch($layer->id)->afterCommit();
        } elseif ($wasGps && ! $isGps) {
            RevokeGpsPassportStagesForLayerJob::dispatch($layer->id)->afterCommit();
        }
    }
}

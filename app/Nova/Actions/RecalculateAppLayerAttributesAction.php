<?php

namespace App\Nova\Actions;

use App\Jobs\RecalculateLayerAttributesJob;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Wm\WmPackage\Jobs\UpdateAppConfigJob;
use Wm\WmPackage\Models\App as AppModel;

/**
 * Accoda il ricalcolo degli attributi per tutti i cammini di un'app.
 *
 * Sostituisce il command artisan di backfill: il primo popolamento dei
 * layer esistenti si lancia da qui. Accoda un job per layer invece di
 * calcolare in-process perché ogni layer comporta una ST_Union PostGIS e
 * una chiamata HTTP a osmfeatures.
 */
class RecalculateAppLayerAttributesAction extends Action
{
    use InteractsWithQueue, Queueable;

    public function name(): string
    {
        return __('Recalculate route attributes');
    }

    public function handle(ActionFields $fields, Collection $models)
    {
        $queued = 0;

        foreach ($models as $app) {
            if (! $app instanceof AppModel) {
                continue;
            }

            $queuedForApp = 0;

            foreach ($app->layers()->pluck('id') as $layerId) {
                // regenerateConfig: false — la rigenerazione del config e'
                // accodata una sola volta per app qui sotto, invece di una per
                // cammino: sono rebuild integrali dello stesso file, quindi
                // N-1 sarebbero lavoro buttato con esito dipendente
                // dall'ordine di esecuzione.
                RecalculateLayerAttributesJob::dispatch((int) $layerId, false);
                $queuedForApp++;
            }

            // Accodata in coda ai ricalcoli. Non c'e' garanzia d'ordine tra
            // job diversi, quindi il config potrebbe essere generato prima che
            // gli ultimi ricalcoli abbiano scritto: i valori mancanti entrano
            // alla rigenerazione successiva. E' il compromesso accettato per
            // non serializzare 121 job in una chain.
            UpdateAppConfigJob::dispatch($app->id);

            $queued += $queuedForApp;

            Log::info('RecalculateAppLayerAttributesAction: ricalcolo accodato', [
                'app_id' => $app->id,
                'layers' => $queuedForApp,
            ]);
        }

        return Action::message(__('Attribute recalculation queued for :count routes', ['count' => $queued]));
    }
}

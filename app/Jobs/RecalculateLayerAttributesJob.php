<?php

namespace App\Jobs;

use App\Services\LayerAttributesService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Jobs\UpdateAppConfigJob;
use Wm\WmPackage\Models\Layer;

/**
 * Ricalcola tutti i valori di filtro calcolati di un cammino (Layer).
 *
 * Un solo job per layer: i valori condividono la stessa aggregazione
 * delle tappe (costruirla è la parte costosa) e la scrittura è una sola
 * operazione atomica.
 *
 * Riceve l'ID e non il modello: SerializesModels con un modello
 * serializzato porterebbe in coda uno snapshot di properties che a
 * runtime sarebbe già stale.
 */
class RecalculateLayerAttributesJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * $regenerateConfig a false: il chiamante si occupa lui di rigenerare il
     * config una volta sola. Serve ai ricalcoli in blocco, dove N job che
     * accodano N rigenerazioni integrali dello STESSO config sono N-1 rebuild
     * e upload inutili (e l'esito finale dipende dall'ordine di esecuzione).
     */
    public function __construct(public int $layerId, public bool $regenerateConfig = true)
    {
        // Il push in coda avviene dopo il commit della transazione che ha
        // accodato il job. Senza questo, un worker puo' prenderlo mentre la
        // transazione di Nova e' ancora aperta e leggere lo stato PRECEDENTE:
        // i temi non ancora sincronizzati e i valori manuali
        // (walking_network, season) non ancora scritti dalle closure di
        // fillUsing, che Nova esegue a fine transazione. Il risultato sarebbe
        // un config rigenerato senza le modifiche appena salvate.
        //
        // Impostato qui e non come proprieta': Illuminate\Bus\Queueable
        // dichiara gia' $afterCommit senza valore di default, e ridichiararla
        // con un default e' un errore di composizione del trait.
        //
        // Nota: il lock di unicita' viene comunque preso al dispatch (Laravel
        // lo acquisisce in PendingDispatch::__destruct, non al push), quindi
        // se la transazione fa rollback il job non parte ma il lock resta
        // preso fino a uniqueFor. Accettabile: il dispatch al save successivo
        // ricalcola.
        $this->afterCommit = true;
    }

    /**
     * Con ShouldBeUnique "semplice" il lock veniva rilasciato solo a fine
     * job (o dopo uniqueFor secondi), quindi due dispatch consecutivi per
     * lo stesso layer (ricalcolo massivo lanciato due volte, o un
     * dispatch dell'observer subito dopo un altro) scartavano in silenzio
     * il secondo: osservato in produzione, due layer rimasti indietro
     * dopo un ricalcolo massivo. ShouldBeUniqueUntilProcessing rilascia
     * il lock quando il job INIZIA l'esecuzione, non quando finisce: la
     * deduplica dei doppioni ancora in coda (unico scopo utile qui, dato
     * che il job ricalcola sempre tutto da zero) resta intatta, ma un
     * ricalcolo successivo non viene più bloccato da uno già in
     * esecuzione o già concluso.
     *
     * Di conseguenza la finestra di rischio di un lock rimasto preso non
     * gestito si riduce al solo tempo di esecuzione del job (non più fino
     * a uniqueFor secondi dopo il completamento), quindi un valore più
     * basso è sufficiente come rete di sicurezza: 120s, ampiamente oltre
     * il tempo normale di esecuzione di un singolo ricalcolo.
     */
    public int $uniqueFor = 120;

    public function uniqueId(): string
    {
        return "recalculate-layer-attributes-{$this->layerId}";
    }

    public function handle(LayerAttributesService $service): void
    {
        $layer = Layer::find($this->layerId);

        if ($layer === null) {
            Log::info('RecalculateLayerAttributesJob: layer non trovato, skip', ['layer_id' => $this->layerId]);

            return;
        }

        $values = $service->computeCalculatedValues($layer);

        // Nulla e' cambiato: non si riscrive e soprattutto non si rigenera il
        // config. Il ricalcolo viene accodato anche da eventi che non toccano
        // gli attributi (auto-riparazione a ogni save del cammino, dispatch
        // multipli durante un import), e ogni rigenerazione e' un rebuild
        // completo del config con upload: senza questo guard un ricalcolo
        // massivo sui 121 cammini produceva 121 rebuild identici.
        if ($service->calculatedValuesAreUnchanged($layer, $values)) {
            Log::info('RecalculateLayerAttributesJob: nessuna variazione, skip', [
                'layer_id' => $layer->id,
            ]);

            return;
        }

        $service->persistCalculatedValues($layer, $values);

        Log::info('RecalculateLayerAttributesJob: attributi ricalcolati', [
            'layer_id' => $layer->id,
            'keys' => array_keys($values),
        ]);

        // Obbligatorio: persistCalculatedValues() scrive via SQL diretto,
        // quindi non scatta LayerObserver::saved() del package, che
        // rigenera la config solo su wasChanged('properties'). Senza
        // questo dispatch i valori resterebbero corretti in DB e assenti
        // nel config.json servito all'app.
        // app_id è NOT NULL a livello di schema (verificato), quindi qui
        // non serve un guard.
        if ($this->regenerateConfig) {
            UpdateAppConfigJob::dispatch($layer->app_id);
        }
    }
}

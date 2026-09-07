<?php

namespace App\Observers;

use App\Jobs\RecalculateLayerAttributesJob;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Accoda il ricalcolo degli attributi dei cammini che contengono una tappa
 * la cui geometria o la cui distanza è cambiata, o che viene cancellata:
 * forma e regioni dipendono dalla geometria, la lunghezza dalle distanze
 * delle tappe, quindi un tracciato ridisegnato, una distanza corretta a mano
 * o una tappa rimossa renderebbero gli attributi stali.
 *
 * Il layer_id si legge dal pivot con una query diretta e non via
 * relazione Eloquent: serve solo l'id, non i modelli.
 *
 * `EcTrack` non usa soft delete e la tabella `layerables` non ha foreign
 * key né ON DELETE CASCADE: la riga del pivot resta orfana dopo la
 * cancellazione (difetto preesistente, fuori scope), ma senza l'hook su
 * `deleting` nessun evento scatterebbe affatto e gli attributi dei cammini
 * che contenevano la tappa resterebbero stali a tempo indefinito. Si usa
 * `deleting` (non `deleted`) per leggere gli id dei layer dal pivot
 * PRIMA che la traccia sia rimossa.
 */
class EcTrackGeometryAttributesObserver
{
    public function saved($track): void
    {
        if (! $track->wasChanged('geometry') && ! $this->distanceChanged($track)) {
            return;
        }

        $this->dispatchRecalculationForTrack($track);
    }

    /**
     * La lunghezza del cammino NON deriva dalla geometria: e' la somma delle
     * distanze delle tappe, e la distanza di una tappa viene letta da
     * `properties` (manual_data > osm_data > dem_data, vedi classifyField).
     * Un redattore che corregge a mano la distanza di una tappa lascia la
     * geometria intatta, quindi senza questo controllo il cammino resterebbe
     * nel range di filtro sbagliato fino al salvataggio successivo del layer.
     *
     * Il confronto usa lo stesso classifyField() del calcolo, applicato al
     * valore originale di `properties`, invece di reimplementarne la priorita':
     * se in futuro la priorita' delle sorgenti cambia nel package, qui non
     * serve fare nulla.
     */
    private function distanceChanged($track): bool
    {
        if (! $track->wasChanged('properties')) {
            return false;
        }

        $before = (clone $track)->forceFill(['properties' => $track->getOriginal('properties')]);

        $currentValue = $track->classifyField($track, 'distance')['currentValue'] ?? null;
        $previousValue = $track->classifyField($before, 'distance')['currentValue'] ?? null;

        return $currentValue !== $previousValue;
    }

    /**
     * Non chiamato "deleting": un metodo con quel nome verrebbe agganciato
     * automaticamente una seconda volta da Model::observe() (usato più sotto
     * solo per "saved"), duplicando il dispatch. Questo metodo è invocato
     * esplicitamente da un Event::listen() grezzo registrato il più presto
     * possibile in AppServiceProvider::boot() — vedi il commento lì per il
     * motivo (ordine di boot vs EcTrackObserver del package).
     */
    public function handleDeleting($track): void
    {
        $this->dispatchRecalculationForTrack($track);
    }

    private function dispatchRecalculationForTrack($track): void
    {
        foreach ($this->layerIdsForTrack($track) as $layerId) {
            RecalculateLayerAttributesJob::dispatch((int) $layerId);
        }
    }

    /**
     * @return Collection<int, mixed>
     */
    private function layerIdsForTrack($track)
    {
        $trackType = config('wm-package.ec_track_model', 'App\Models\EcTrack');

        return DB::table('layerables')
            ->where('layerable_type', $trackType)
            ->where('layerable_id', $track->id)
            ->pluck('layer_id');
    }
}

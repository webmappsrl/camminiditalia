<?php

namespace App\Services;

use App\Models\Experience;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;
use Wm\WmPackage\Models\Layer;

/**
 * Collega un'esperienza alle registrazioni (UgcTrack) del suo autore (oc:8169).
 *
 * Algoritmo:
 * 1. tappe candidate: la tappa dell'esperienza se indicata, altrimenti tutte le tappe del cammino;
 * 2. registrazioni candidate: quelle dell'autore che hanno **percorso** almeno una tappa
 *    candidata secondo il criterio del passaporto (oc:8165), cioè
 *    `StageValidationService::stagesWalkedByTrack()`: segmenti distinti coperti sul totale
 *    della tappa, oltre `camminiditalia.passport.coverage_threshold`, solo registrazioni vere
 *    (non file caricati) e corrispondenze con i parametri correnti dell'App.
 *    Una corrispondenza qualsiasi non basta: una registrazione che sfiora la tappa accanto
 *    per un segmento verrebbe legata a un'esperienza su quella tappa.
 *    Il passaporto unisce i segmenti di tutte le registrazioni di una persona; qui la regola
 *    vale per la singola registrazione, perché si decide se *quella* registrazione racconta
 *    la tappa: una tappa percorsa in due uscite parziali, nessuna oltre soglia, non lega
 *    nessuna delle due;
 * 3. data di registrazione: `properties->createdAt`, cioè l'istante in cui il camminatore
 *    ha registrato; `created_at` della riga è l'istante di arrivo sul server e dai dati locali
 *    risulta spesso posteriore (sincronizzazione differita), quindi è solo il ripiego
 *    quando `createdAt` manca o non è una data valida;
 * 4. ancora: la data massima fra le candidate non successive alla creazione dell'esperienza
 *    (nessuna, nessun legame);
 * 5. si legano le candidate con data in `[ancora - N giorni, ancora]`, N da
 *    `camminiditalia.experiences.ugc_link_window_days`.
 *
 * Il legame è ricalcolato con `sync()`: ogni esecuzione sostituisce il precedente.
 */
class ExperienceUgcLinkService
{
    public function __construct(private StageValidationService $stageValidation) {}

    /**
     * Ricalcola e salva le registrazioni legate all'esperienza.
     */
    public function link(Experience $experience): void
    {
        $experience->ugcTracks()->sync($this->resolveUgcTrackIds($experience));
    }

    /**
     * @return int[]
     */
    private function resolveUgcTrackIds(Experience $experience): array
    {
        $stageIds = $this->candidateStageIds($experience);
        if ($stageIds === []) {
            return [];
        }

        $walked = $this->stageValidation->stagesWalkedByTrack((int) $experience->user_id, $stageIds);
        if ($walked === []) {
            return [];
        }

        $rows = DB::table('ugc_tracks')
            ->whereIn('id', array_keys($walked))
            ->get(['id', 'properties', 'created_at']);

        $dated = [];
        foreach ($rows as $row) {
            $date = $this->recordedAt($row);
            if ($date !== null) {
                $dated[(int) $row->id] = $date;
            }
        }

        $limit = $experience->created_at;
        $before = array_filter($dated, fn (Carbon $d) => $d->lessThanOrEqualTo($limit));
        if ($before === []) {
            return [];
        }

        $anchor = collect($before)->max();
        $from = $anchor->copy()->subDays((int) config('camminiditalia.experiences.ugc_link_window_days', 30));

        return array_keys(array_filter($before, fn (Carbon $d) => $d->greaterThanOrEqualTo($from)));
    }

    /**
     * @return int[]
     */
    private function candidateStageIds(Experience $experience): array
    {
        if ($experience->ec_track_id !== null) {
            return [(int) $experience->ec_track_id];
        }

        $layer = Layer::find($experience->layer_id);

        return $layer === null
            ? []
            : $layer->ecTracks()->pluck('ec_tracks.id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Data di registrazione: `properties->createdAt`, ripiego su `created_at`.
     */
    private function recordedAt(object $row): ?Carbon
    {
        $properties = is_string($row->properties) ? json_decode($row->properties, true) : (array) $row->properties;
        $raw = $properties['createdAt'] ?? null;

        if (is_string($raw) && $raw !== '') {
            try {
                return Carbon::parse($raw);
            } catch (Throwable $e) {
                // Data illeggibile: si ripiega su created_at.
            }
        }

        return $row->created_at !== null ? Carbon::parse($row->created_at) : null;
    }
}

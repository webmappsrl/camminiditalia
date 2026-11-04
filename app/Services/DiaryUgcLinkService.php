<?php

namespace App\Services;

use App\Models\Diary;
use App\Support\UgcRecordingDate;
use App\Support\WalkPeriods;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\User;

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
 *    `camminiditalia.diaries.ugc_link_window_days`.
 *
 * Ciclo 4: i punti 3-5 valgono solo senza date. Con le date scritte nel diario, oppure senza ma con
 * l'ultima percorrenza nota dal Passaporto, si legano le candidate registrate in quei giorni ±1
 * (giorno del primo punto nel fuso di Roma, come nel Passaporto), senza il limite della creazione.
 *
 * Il legame è ricalcolato con `sync()`: ogni esecuzione sostituisce il precedente.
 */
class DiaryUgcLinkService
{
    /** Giorni di margine prima e dopo il periodo (oc:8169, ciclo 4). */
    public const MARGIN_DAYS = 1;

    public function __construct(
        private StageValidationService $stageValidation,
        private StageProgressService $stageProgress,
    ) {}

    /**
     * Ricalcola e salva le registrazioni legate all'esperienza.
     */
    public function link(Diary $diary): void
    {
        $diary->ugcTracks()->sync($this->resolveUgcTrackIds($diary));
    }

    /**
     * @return int[]
     */
    private function resolveUgcTrackIds(Diary $diary): array
    {
        $stageIds = $this->candidateStageIds($diary);
        if ($stageIds === []) {
            return [];
        }

        $walked = $this->stageValidation->stagesWalkedByTrack((int) $diary->user_id, $stageIds);
        if ($walked === []) {
            return [];
        }

        $rows = DB::table('ugc_tracks')
            ->whereIn('id', array_keys($walked))
            ->get([
                'id', 'properties', 'created_at',
                DB::raw("properties->'locations'->0->>'time' as first_time"),
                DB::raw("properties->>'createdAt' as created_prop"),
            ]);

        // Ciclo 4: con un periodo (date scritte, altrimenti ultima percorrenza nota) si legano le
        // registrazioni di quei giorni ±1, anche se successive alla creazione del diario.
        $period = $this->period($diary, $stageIds);
        if ($period !== null) {
            [$from, $to] = $period;
            $ids = [];
            foreach ($rows as $row) {
                $day = UgcRecordingDate::day($row->first_time, $row->created_prop, $row->created_at);
                if ($day !== null && $day >= $from && $day <= $to) {
                    $ids[] = (int) $row->id;
                }
            }
            sort($ids);

            return $ids;
        }

        $dated = [];
        foreach ($rows as $row) {
            $date = $this->recordedAt($row);
            if ($date !== null) {
                $dated[(int) $row->id] = $date;
            }
        }

        $limit = $diary->created_at;
        $before = array_filter($dated, fn (Carbon $d) => $d->lessThanOrEqualTo($limit));
        if ($before === []) {
            return [];
        }

        $anchor = collect($before)->max();
        $from = $anchor->copy()->subDays((int) config('camminiditalia.diaries.ugc_link_window_days', 30));

        return array_keys(array_filter($before, fn (Carbon $d) => $d->greaterThanOrEqualTo($from)));
    }

    /**
     * Periodo di ricerca con il margine: le date scritte nel diario, altrimenti l'ultima percorrenza
     * delle tappe candidate.
     *
     * @param  int[]  $stageIds
     * @return array{0: string, 1: string}|null `[da, a]` in `Y-m-d`, `null` senza date
     */
    private function period(Diary $diary, array $stageIds): ?array
    {
        $start = $diary->walk_start_date?->format('Y-m-d');
        $end = $diary->walk_end_date?->format('Y-m-d') ?? $start;
        if ($start === null) {
            $known = $this->knownDates($diary, $stageIds);
            if ($known === []) {
                return null;
            }
            [$start, $end] = [$known[0], $known[count($known) - 1]];
        }
        $margin = self::MARGIN_DAYS;

        return [
            Carbon::parse($start)->subDays($margin)->format('Y-m-d'),
            Carbon::parse((string) $end)->addDays($margin)->format('Y-m-d'),
        ];
    }

    /**
     * Giorni dell'ultima percorrenza nota delle tappe candidate, dal progresso del Passaporto.
     *
     * @param  int[]  $stageIds
     * @return array<int, string>
     */
    private function knownDates(Diary $diary, array $stageIds): array
    {
        $user = User::find($diary->user_id);
        $layer = Layer::find($diary->layer_id);
        if ($user === null || $layer === null) {
            return [];
        }
        $dates = [];
        foreach ($this->stageProgress->progressFor($user, $layer)['tracks'] as $track) {
            if (in_array((int) $track['id'], $stageIds, true)) {
                $dates = array_merge($dates, $track['walked_dates']);
            }
        }

        return WalkPeriods::latest($dates);
    }

    /**
     * @return int[]
     */
    private function candidateStageIds(Diary $diary): array
    {
        if ($diary->ec_track_id !== null) {
            return [(int) $diary->ec_track_id];
        }

        $layer = Layer::find($diary->layer_id);

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

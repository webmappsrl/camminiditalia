<?php

namespace App\Services;

use App\Models\PassportStage;
use App\Support\PassportLayer;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Services\Models\UgcTrackMatchingService;

/**
 * Decide quali tappe una persona ha percorso, dai segmenti delle sue UGC track (oc:8165).
 *
 * Il lavoro geometrico è già fatto: `ugc_track_ec_track_matches` (oc:8773, wm-package) dice
 * quali segmenti di quali tappe ogni UGC track ha toccato. Qui si uniscono i segmenti di tutte
 * le UGC track **registrate** di una persona sulla stessa tappa e si confronta la quota coperta
 * con la soglia configurata.
 *
 * Due garanzie, scritte nella clausola dell'upsert e non in un `if`, così reggono anche se il
 * job e l'azione Nova della certificazione cartacea scrivono insieme:
 * - una validazione concessa a mano non viene mai toccata;
 * - il calcolo non toglie mai una validazione e non sposta la sua data: se la copertura scende
 *   la tappa resta validata. L'unica revoca è lo spegnimento del GPS sul cammino (oc:8809),
 *   che passa da `revokeGpsForLayer()`.
 */
class StageValidationService
{
    public const COVERAGE_THRESHOLD_KEY = 'camminiditalia.passport.coverage_threshold';

    public function __construct(private UgcTrackMatchingService $matching) {}

    public function threshold(): int
    {
        return (int) config(self::COVERAGE_THRESHOLD_KEY, 80);
    }

    /**
     * Ricalcola e scrive l'avanzamento di una persona.
     *
     * @param  ?array<int>  $ecTrackIds  limita il calcolo a queste tappe
     * @return array{validated:int, updated:int}
     */
    public function recomputeForUser(int $userId, ?array $ecTrackIds = null): array
    {
        $rows = $this->coverage($userId, $ecTrackIds);

        if ($rows === []) {
            return ['validated' => 0, 'updated' => 0];
        }

        $threshold = $this->threshold();
        $now = now();
        $validated = 0;

        foreach (array_chunk($rows, 200) as $chunk) {
            $values = [];
            foreach ($chunk as $row) {
                $overThreshold = $row->progress >= $threshold;
                $validated += $overThreshold ? 1 : 0;

                $values[] = [
                    'user_id' => $userId,
                    'ec_track_id' => $row->ec_track_id,
                    'layer_id' => $row->layer_id,
                    'source' => PassportStage::SOURCE_GPS,
                    'progress' => $row->progress,
                    'validated_at' => $overThreshold ? $now : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            $this->upsert($values);
        }

        $this->syncUgcTracks($userId, $rows);

        return ['validated' => $validated, 'updated' => count($rows)];
    }

    /**
     * Ricalcola il passaporto di tutte le persone che hanno camminato sulle tappe di un cammino
     * (oc:8165). Serve quando il gestore accende il GPS su quel cammino: senza, chi ci ha
     * già camminato non vedrebbe nulla fino alla sua prossima uscita.
     *
     * @return array{validated:int, updated:int}
     */
    public function recomputeForLayer(int $layerId): array
    {
        $ecTrackIds = DB::table('layerables')
            ->where('layer_id', $layerId)
            ->where('layerable_type', $this->matching->ecTrackMorphClass())
            ->pluck('layerable_id')
            ->all();

        if ($ecTrackIds === []) {
            return ['validated' => 0, 'updated' => 0];
        }

        $userIds = DB::query()
            ->fromSub(UgcTrack::recorded()->getQuery()->select('ugc_tracks.id', 'ugc_tracks.user_id'), 'u')
            ->join('ugc_track_ec_track_matches as m', 'm.ugc_track_id', '=', 'u.id')
            ->whereIn('m.ec_track_id', $ecTrackIds)
            ->distinct()
            ->pluck('u.user_id');

        $totals = ['validated' => 0, 'updated' => 0];
        foreach ($userIds as $userId) {
            $outcome = $this->recomputeForUser((int) $userId, $ecTrackIds);
            $totals['validated'] += $outcome['validated'];
            $totals['updated'] += $outcome['updated'];
        }

        return $totals;
    }

    /**
     * Revoca le tappe sbloccate dal GPS quando il GPS di un cammino viene spento (oc:8809).
     *
     * Si cancellano le righe `source = 'gps'` delle tappe del cammino, salvo quelle che
     * appartengono a un cammino con il GPS acceso **nel momento in cui il job gira**, quel
     * cammino stesso compreso: se è stato riacceso nel frattempo, protegge le sue tappe e la
     * revoca in coda non cancella niente.
     *
     * Le validazioni a mano non si toccano. Non si usa `passport_stages.layer_id`: è il cammino
     * in cui la tappa è stata vista per prima, non un'appartenenza. Le righe del pivot con le
     * UGC track spariscono a cascata.
     *
     * @return int righe cancellate
     */
    public function revokeGpsForLayer(int $layerId): int
    {
        $morph = $this->matching->ecTrackMorphClass();

        return PassportStage::where('source', PassportStage::SOURCE_GPS)
            ->whereIn('ec_track_id', DB::table('layerables')
                ->select('layerable_id')
                ->where('layer_id', $layerId)
                ->where('layerable_type', $morph))
            ->whereNotExists(fn ($q) => $q
                ->select(DB::raw(1))
                ->from('layerables as other')
                ->join('layers', 'layers.id', '=', 'other.layer_id')
                ->whereColumn('other.layerable_id', 'passport_stages.ec_track_id')
                ->where('other.layerable_type', $morph)
                ->whereRaw(PassportLayer::gpsEnabledSql('layers')))
            ->delete();
    }

    /**
     * Quante tappe si sbloccherebbero e a quante persone, senza scrivere nulla. È il `--dry-run`
     * del command, utile soprattutto prima del primo lancio: il calcolo non si annulla da solo,
     * perché non toglie validazioni (le toglie solo lo spegnimento del GPS).
     *
     * @return array{tappe:int, persone:int}
     */
    public function previewForAllUsers(): array
    {
        $threshold = $this->threshold();

        // Solo le tappe che si sbloccherebbero **adesso**: quelle già validate — a mano o da un
        // calcolo precedente — non si sbloccano una seconda volta, e contarle gonficerebbe il
        // numero a ogni rilancio, proprio quello su cui si decide se procedere.
        $already = DB::table('passport_stages')
            ->whereNotNull('validated_at')
            ->get(['user_id', 'ec_track_id'])
            ->map(fn ($row) => $row->user_id.':'.$row->ec_track_id)
            ->flip();

        $rows = array_filter(
            $this->coverage(null, null),
            fn ($row) => $row->progress >= $threshold && ! $already->has($row->user_id.':'.$row->ec_track_id)
        );

        return [
            'tappe' => count($rows),
            'persone' => count(array_unique(array_map(fn ($row) => $row->user_id, $rows))),
        ];
    }

    /**
     * Copertura per coppia persona-tappa: segmenti distinti percorsi da tutte le UGC track
     * registrate, sul totale dei segmenti della tappa.
     *
     * Restano fuori le corrispondenze calcolate con parametri diversi da quelli correnti
     * dell'App: unire indici riferiti a divisioni diverse della stessa tappa darebbe un numero
     * privo di significato, che potrebbe anche superare la soglia.
     *
     * @param  ?array<int>  $ecTrackIds
     * @return array<int, object>
     */
    private function coverage(?int $userId, ?array $ecTrackIds): array
    {
        $segments = DB::query()
            ->fromSub(
                UgcTrack::recorded()
                    ->getQuery()
                    ->select('ugc_tracks.id', 'ugc_tracks.user_id')
                    ->when($userId !== null, fn ($q) => $q->where('ugc_tracks.user_id', $userId)),
                'u'
            )
            ->join('ugc_track_ec_track_matches as m', 'm.ugc_track_id', '=', 'u.id')
            ->joinSub($this->currentParams(), 'p', fn ($join) => $join
                ->on('p.app_id', '=', 'm.app_id')
                ->on('p.segment_length_meters', '=', 'm.segment_length_meters')
                ->on('p.max_distance_meters', '=', 'm.max_distance_meters'))
            ->when($ecTrackIds !== null, fn ($q) => $q->whereIn('m.ec_track_id', $ecTrackIds))
            ->crossJoin(DB::raw('LATERAL jsonb_array_elements_text(m.matched_segments) AS seg(idx)'))
            ->groupBy('u.user_id', 'm.ec_track_id')
            ->selectRaw('u.user_id, m.ec_track_id, COUNT(DISTINCT seg.idx) AS covered, MAX(m.total_segments) AS total');

        // Solo i cammini con il GPS acceso (oc:8809): su quelli senza GPS le tappe non si sbloccano
        // da sole, e una tappa che appartiene solo a cammini spenti non va calcolata.
        // La join è sui cammini accesi, quindi `layer_id` della riga è per forza uno di quelli.
        return DB::query()
            ->fromSub($segments, 'c')
            ->join('layerables as l', fn ($join) => $join
                ->on('l.layerable_id', '=', 'c.ec_track_id')
                ->where('l.layerable_type', $this->matching->ecTrackMorphClass()))
            ->join('layers', 'layers.id', '=', 'l.layer_id')
            ->whereRaw(PassportLayer::gpsEnabledSql('layers'))
            ->groupBy('c.user_id', 'c.ec_track_id', 'c.covered', 'c.total')
            ->selectRaw('c.user_id, c.ec_track_id, MIN(l.layer_id) AS layer_id,
                         LEAST(100, FLOOR(c.covered * 100.0 / NULLIF(c.total, 0)))::int AS progress')
            ->get()
            ->map(fn ($row) => (object) [
                'user_id' => (int) $row->user_id,
                'ec_track_id' => (int) $row->ec_track_id,
                'layer_id' => (int) $row->layer_id,
                'progress' => (int) $row->progress,
            ])
            ->all();
    }

    /**
     * I parametri correnti di ciascuna App, come tabella derivata: serve a scartare in SQL le
     * corrispondenze vecchie senza caricarle.
     */
    private function currentParams(): Builder
    {
        $appIds = DB::table('ugc_track_ec_track_matches')->distinct()->pluck('app_id');

        $rows = $appIds->map(function ($appId) {
            $params = $this->matching->params((int) $appId);

            return sprintf('(%d, %d, %d)', $appId, $params['segment_length_meters'], $params['max_distance_meters']);
        })->all();

        if ($rows === []) {
            $rows = ['(0, 0, 0)'];
        }

        return DB::query()->fromRaw(
            '(VALUES '.implode(', ', $rows).') AS p(app_id, segment_length_meters, max_distance_meters)'
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $values
     */
    private function upsert(array $values): void
    {
        $columns = implode(', ', array_keys($values[0]));
        $placeholders = implode(', ', array_fill(0, count($values), '('.implode(', ', array_fill(0, count($values[0]), '?')).')'));
        $bindings = [];
        foreach ($values as $row) {
            foreach ($row as $value) {
                $bindings[] = $value;
            }
        }

        $manual = PassportStage::SOURCE_MANUAL;

        // `layer_id` resta quello della prima scrittura: è il cammino in cui la tappa è stata
        // vista per prima, non un'appartenenza (vedi il docblock del model).
        DB::statement(
            "INSERT INTO passport_stages ({$columns}) VALUES {$placeholders}
             ON CONFLICT (user_id, ec_track_id) DO UPDATE SET
                progress = CASE WHEN passport_stages.source = '{$manual}'
                                THEN passport_stages.progress ELSE EXCLUDED.progress END,
                source = CASE WHEN passport_stages.source = '{$manual}'
                              THEN passport_stages.source ELSE EXCLUDED.source END,
                validated_at = COALESCE(passport_stages.validated_at, EXCLUDED.validated_at),
                updated_at = CASE WHEN passport_stages.source = '{$manual}'
                                  THEN passport_stages.updated_at ELSE EXCLUDED.updated_at END",
            $bindings
        );
    }

    /**
     * Collega ogni riga alle UGC track che l'hanno prodotta, rifacendo l'elenco da zero: una
     * UGC track cancellata sparisce di qui, mentre la validazione resta.
     *
     * @param  array<int, object>  $rows
     */
    private function syncUgcTracks(int $userId, array $rows): void
    {
        $ecTrackIds = array_map(fn ($row) => $row->ec_track_id, $rows);

        // Le validazioni concesse a mano restano fuori: il calcolo non le tocca, e un gestore
        // che valida una credenziale cartacea non sta dicendo da quali uscite nasce la tappa.
        $ids = PassportStage::where('user_id', $userId)
            ->whereIn('ec_track_id', $ecTrackIds)
            ->where('source', '!=', PassportStage::SOURCE_MANUAL)
            ->pluck('id', 'ec_track_id');

        if ($ids->isEmpty()) {
            return;
        }

        // Le stesse corrispondenze che hanno prodotto il verdetto: con i parametri correnti,
        // altrimenti si attribuirebbe la tappa a uscite che non hanno contribuito a nulla.
        $pairs = DB::query()
            ->fromSub(
                UgcTrack::recorded()->getQuery()->select('ugc_tracks.id')->where('ugc_tracks.user_id', $userId),
                'u'
            )
            ->join('ugc_track_ec_track_matches as m', 'm.ugc_track_id', '=', 'u.id')
            ->joinSub($this->currentParams(), 'p', fn ($join) => $join
                ->on('p.app_id', '=', 'm.app_id')
                ->on('p.segment_length_meters', '=', 'm.segment_length_meters')
                ->on('p.max_distance_meters', '=', 'm.max_distance_meters'))
            ->whereIn('m.ec_track_id', $ecTrackIds)
            ->get(['m.ec_track_id', 'm.ugc_track_id']);

        $insert = $pairs
            ->filter(fn ($row) => $ids->has($row->ec_track_id))
            ->map(fn ($row) => [
                'passport_stage_id' => $ids[$row->ec_track_id],
                'ugc_track_id' => $row->ugc_track_id,
            ])
            ->all();

        // Svuotare e riscrivere in una transazione: due ricalcoli dello stesso utente possono
        // sovrapporsi — il job rilascia il lock quando parte, e il command può girare insieme —
        // e fra il delete e l'insert il pivot resterebbe vuoto o andrebbe in conflitto di chiave.
        DB::transaction(function () use ($ids, $insert) {
            DB::table('passport_stage_ugc_track')
                ->whereIn('passport_stage_id', $ids->values())
                ->delete();

            if ($insert !== []) {
                DB::table('passport_stage_ugc_track')->insert($insert);
            }
        });
    }
}

<?php

namespace App\Services;

use App\Models\PassportStage;
use App\Support\PassportLayer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\Media;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\Models\MediaService;

/**
 * Progresso di un camminatore sulle tappe validate (oc:8676), sempre
 * calcolato sulle tappe di oggi (nessun completamento salvato). Due regole:
 *
 * - A, «tappe del cammino» (routeTracksQuery()): tutte le tappe associate al
 *   layer, a prescindere dal proprietario. È la regola dell'utente finale:
 *   API progress/passport e numeri della Lens. Una tappa è validata se esiste
 *   una riga `passport_stages` per (utente, tappa), qualunque sia il
 *   `layer_id`/`source` della riga: una tappa condivisa risulta validata in
 *   tutti i cammini che la contengono.
 * - B, «tappe del gestore» (managedTracksQuery()): tappe associate al layer
 *   il cui `user_id` è il proprietario effettivo del layer, cioè
 *   `COALESCE(layers.user_id, camminiditalia.default_owner_id)` — la stessa
 *   regola di App\Support\LayerOwner, in SQL. È la regola di Nova: scoping
 *   dell'elenco «Tappe validate», filtro cammino sulla risorsa, colonna
 *   «Routes» e tappe selezionabili nella decisione della richiesta
 *   (CertificationRequestService::ownedLayerTracks(), che deriva da qui).
 */
class StageProgressService
{
    public const STATUS_VALIDATED = 'validated';

    public const STATUS_NOT_VALIDATED = 'not_validated';

    /**
     * Bit di cui si sposta `user_id` nell'id delle righe di summaryQuery():
     * `(user_id << SUMMARY_ID_USER_SHIFT) | layer_id`.
     */
    public const SUMMARY_ID_USER_SHIFT = 32;

    /**
     * Colonne di `ec_tracks` che bastano a stageDetails(): `properties`
     * (ref, from, to, manual/osm/dem_data) e `osmid` (priorità di
     * classifyField()). Niente geometria: su un cammino di 99 tappe pesa più
     * di un megabyte e qui non serve.
     */
    public const STAGE_DETAIL_COLUMNS = ['id', 'properties', 'osmid'];

    /**
     * Regola A: una riga per ogni tappa associata a ogni layer, qualunque sia
     * il suo proprietario: `layer_id`, `ec_track_id`, `distance_km` (float,
     * precisione piena).
     */
    public function routeTracksQuery(): QueryBuilder
    {
        return $this->layerTracksBaseQuery()
            ->select('layerables.layer_id', 'ec_tracks.id as ec_track_id')
            ->selectRaw($this->distanceSql().' as distance_km')
            ->selectRaw($this->rootSql().' as root_ec_track_id')
            ->distinct();
    }

    /**
     * La radice del gruppo di una tappa dentro il suo cammino (oc:8165): la tappa principale se
     * è presente in **quel** cammino, altrimenti la tappa stessa.
     *
     * La condizione sul cammino non è un dettaglio: una variante associata a un cammino che non
     * contiene la sua principale non è un'alternativa di niente, ed è una tappa come le altre.
     */
    private function rootSql(): string
    {
        $morphClass = (new (config('wm-package.ec_track_model')))->getMorphClass();

        return "COALESCE((
            SELECT tv.main_ec_track_id
            FROM ec_track_variants tv
            JOIN layerables lp ON lp.layerable_id = tv.main_ec_track_id
                AND lp.layerable_type = '{$morphClass}'
                AND lp.layer_id = layerables.layer_id
            WHERE tv.ec_track_id = ec_tracks.id
            LIMIT 1
        ), ec_tracks.id)";
    }

    /**
     * Regola B: una riga per ogni tappa del proprietario effettivo in ogni
     * layer: `layer_id`, `ec_track_id`, `distance_km` (float, precisione
     * piena).
     */
    public function managedTracksQuery(): QueryBuilder
    {
        return $this->layerTracksBaseQuery()
            ->whereRaw('ec_tracks.user_id = COALESCE(layers.user_id, ?)', [$this->defaultOwnerId()])
            ->select('layerables.layer_id', 'ec_tracks.id as ec_track_id')
            ->selectRaw($this->distanceSql().' as distance_km')
            ->distinct();
    }

    /**
     * Regola A: tutte le tappe associate al layer, qualunque sia il
     * proprietario: `ec_track_id`, `name_raw` (testo grezzo), `distance_km`.
     */
    public function layerTracksQuery(int $layerId): QueryBuilder
    {
        return $this->layerTracksBaseQuery()
            ->where('layerables.layer_id', $layerId)
            ->select('ec_tracks.id as ec_track_id', 'ec_tracks.name as name_raw')
            ->selectRaw($this->distanceSql().' as distance_km')
            ->distinct();
    }

    /**
     * Risposta di GET /api/layer/{layer}/progress. Regola A: totali e
     * `tracks` comprendono tutte le tappe del cammino; ogni tappa è
     * `validated` o `not_validated`.
     *
     * @return array<string, mixed>
     */
    public function progressFor(User $user, Layer $layer): array
    {
        $totals = $this->userTotalsQuery($user)
            ->where('g.layer_id', $layer->id)
            ->first();

        // Qui la join NON filtra sulle sole validate (oc:8165): serve anche la riga di una
        // tappa in corso, per mostrarne la percentuale. Chi distingue è `validated_at`.
        $rows = DB::query()
            ->fromSub($this->layerTracksQuery($layer->id), 'lt')
            ->leftJoin('passport_stages as v', function (JoinClause $join) use ($user) {
                $join->on('v.ec_track_id', '=', 'lt.ec_track_id')
                    ->where('v.user_id', '=', $user->id);
            })
            ->orderBy('lt.ec_track_id')
            ->get(['lt.ec_track_id', 'lt.name_raw', 'lt.distance_km', 'v.validated_at', 'v.source', 'v.progress']);

        // Una sola query per i model delle tappe (con media): niente N+1, e
        // solo le colonne di stageDetails(), senza geometria.
        $models = EcTrack::query()
            ->select(self::STAGE_DETAIL_COLUMNS)
            ->with('media')
            ->whereIn('id', $rows->pluck('ec_track_id'))
            ->get()
            ->keyBy('id');

        $tracks = $rows
            ->map(function (object $row) use ($models) {
                $validated = $row->validated_at !== null;

                return [
                    'id' => (int) $row->ec_track_id,
                    'name' => $this->translations($row->name_raw),
                    'distance' => round((float) $row->distance_km, 1),
                    'status' => $validated ? self::STATUS_VALIDATED : self::STATUS_NOT_VALIDATED,
                    'progress' => $this->trackProgress($validated, $row->progress ?? null),
                    'validated_at' => $validated ? $this->isoDate($row->validated_at) : null,
                    'source' => $validated ? $row->source : null,
                ] + $this->stageDetails($models->get($row->ec_track_id))
                  + ['shareable' => $validated];
            })
            ->all();

        return ['layer_id' => $layer->id]
            + $this->progressFields($totals)
            + ['tracks' => $tracks];
    }

    /**
     * Dati tecnici della tappa, unica derivazione per la pagina di dettaglio
     * dell'app (progressFor()) e per l'immagine di condivisione
     * (StageShareImageService::snapshot()), con la stessa logica di
     * EcTrack::toSearchableArray() (wm-package): `ref`, `from`/`to` (ripiego
     * su osmfeatures_data), `ascent`/`descent` secondo la priorità di
     * classifyField(), `image` = miniatura della prima media in ordine di
     * `order_column` (getMedia('*'), come toSearchableArray()).
     * I valori vuoti (`''`, `0`) o assenti diventano `null`. Al model bastano
     * le colonne STAGE_DETAIL_COLUMNS.
     *
     * @return array{ref: ?string, from: ?string, to: ?string, ascent: ?int, descent: ?int, image: ?string}
     */
    public function stageDetails(?EcTrack $track): array
    {
        if ($track === null) {
            return ['ref' => null, 'from' => null, 'to' => null, 'ascent' => null, 'descent' => null, 'image' => null];
        }

        $text = fn (mixed $value): ?string => is_scalar($value) && trim((string) $value) !== '' ? (string) $value : null;
        $number = fn (string $field): ?int => ((int) ($track->classifyField($track, $field)['currentValue'] ?? 0)) ?: null;
        $firstMedia = $track->getMedia('*')->first();

        return [
            'ref' => $text($track->properties['ref'] ?? null),
            'from' => $text($track->properties['from'] ?? data_get($track->osmfeatures_data ?? null, 'properties.from')),
            'to' => $text($track->properties['to'] ?? data_get($track->osmfeatures_data ?? null, 'properties.to')),
            'ascent' => $number('ascent'),
            'descent' => $number('descent'),
            'image' => $firstMedia instanceof Media ? $text(MediaService::make()->getThumbnailUrl($firstMedia)) : null,
        ];
    }

    /**
     * Percentuale (0-100) della tappa percorsa.
     */
    /**
     * Quanto della tappa risulta percorso. Una tappa validata vale 100 anche se la copertura
     * calcolata è minore: può essere stata validata a mano dal gestore, o esserlo stata quando
     * il tracciato era diverso, e una validazione non si rimangia (oc:8165).
     */
    private function trackProgress(bool $validated, ?int $progress): int
    {
        if ($validated) {
            return 100;
        }

        return max(0, min(100, (int) $progress));
    }

    /**
     * Elementi di `routes` in GET /api/passport (oc:8763): un elemento per
     * ogni cammino con il passaporto acceso (`properties->passport_enabled`,
     * PassportLayer::paperEnabledSql()) e almeno una tappa associata (regola A),
     * anche con 0 tappe validate dall'utente; mai un cammino spento. Campi:
     * totali della regola A, `last_validated_at` e `validated_tracks`, mappa
     * `{<id tappa>: <data di convalida ISO 8601>}` (oggetto vuoto `{}`
     * senza convalide). Una sola query, qualunque sia il numero di cammini.
     *
     * @return array<int, array<string, mixed>>
     */
    public function passportFor(User $user): array
    {
        // L'elenco delle tappe validate è una sottoquery correlata al cammino e non
        // un'aggregazione sulla join (oc:8165): dal raggruppamento per gruppi di varianti le
        // singole righe di validazione non arrivano più al livello esterno, e un gruppo con due
        // tappe validate — chi ha percorso entrambi i rami — le perderebbe entrambe tranne una.
        // Il DISTINCT dentro serve perché `layerables` non ha un vincolo di unicità (oc:8139):
        // un pivot duplicato produrrebbe chiavi ripetute nel JSON.
        $morphClass = (new (config('wm-package.ec_track_model')))->getMorphClass();

        $rows = $this->userTotalsQuery($user)
            ->join('layers', 'layers.id', '=', 'g.layer_id')
            ->whereRaw(PassportLayer::paperEnabledSql('layers'))
            ->selectRaw('(
                SELECT json_object_agg(x.ec_track_id, x.validated_at)
                FROM (
                    SELECT DISTINCT vt.ec_track_id, vt.validated_at
                    FROM passport_stages vt
                    JOIN layerables lt ON lt.layerable_id = vt.ec_track_id
                        AND lt.layerable_type = ?
                        AND lt.layer_id = g.layer_id
                    WHERE vt.user_id = ? AND vt.validated_at IS NOT NULL
                ) x
            ) as validated_tracks', [$morphClass, $user->id])
            ->orderBy('g.layer_id')
            ->get();

        $layers = Layer::whereIn('id', $rows->pluck('layer_id'))->get()->keyBy('id');

        return $rows
            ->map(fn (object $row) => ['layer_id' => (int) $row->layer_id, 'name' => $layers[$row->layer_id]->getStringName()]
                + $this->progressFields($row)
                + [
                    'last_validated_at' => $this->isoDate($row->last_validated_at),
                    'validated_tracks' => $this->validatedTracks($row->validated_tracks),
                ])
            ->values()
            ->all();
    }

    /**
     * Mappa `{<id tappa>: <data ISO 8601>}` dal JSON di json_object_agg;
     * NULL (nessuna convalida) → oggetto vuoto, mai `[]`.
     */
    private function validatedTracks(?string $json): object
    {
        return (object) array_map(fn ($date) => $this->isoDate($date), json_decode($json ?? '{}', true));
    }

    /**
     * Una riga per coppia utente/layer (ogni layer che contiene la tappa
     * secondo la regola A, non `passport_stages.layer_id`): `id`,
     * `user_id`, `layer_id`,
     * `validated`, `total`, `last_validated_at`. `id` = `(user_id <<
     * SUMMARY_ID_USER_SHIFT) | layer_id`: unico e stabile per coppia (con MIN(v.id) una tappa condivisa
     * fra due layer darebbe lo stesso id a due
     * righe). Avvolta in una subquery con alias `passport_stages`, così
     * filtri e ordinamenti lavorano su colonne semplici. Base della Lens.
     * I numeri (`validated`, `total`, `last_validated_at`) seguono la regola
     * A, identici a quelli dell'app.
     *
     * Con `$viewer` lo scoping di ruolo sulle righe è applicato dentro
     * l'aggregato:
     * Administrator tutto, Validator solo i layer di cui è proprietario
     * effettivo (ownedLayerIdsQuery()), ogni altro ruolo nulla. Senza `$viewer` nessuno scoping.
     * scopeVisibleTo() non si applica qui: le righe aggregate non hanno
     * `ec_track_id`.
     *
     * @return Builder<PassportStage>
     */
    public function summaryQuery(?User $viewer = null): Builder
    {
        // Gruppi, non tappe (oc:8165): gli stessi numeri che l'app mostra al camminatore.
        $layerTotals = DB::query()
            ->fromSub($this->routeTracksQuery(), 'ct_all')
            ->groupBy('ct_all.layer_id')
            ->select('ct_all.layer_id')
            ->selectRaw('COUNT(DISTINCT ct_all.root_ec_track_id) as total');

        $grouped = DB::table('passport_stages as v')
            ->joinSub($this->routeTracksQuery(), 'ct', 'ct.ec_track_id', '=', 'v.ec_track_id')
            ->joinSub($layerTotals, 'lt', 'lt.layer_id', '=', 'ct.layer_id')
            ->whereRaw(PassportStage::validatedSql('v'))
            ->groupBy('v.user_id', 'ct.layer_id', 'lt.total')
            ->selectRaw('(v.user_id::bigint << '.self::SUMMARY_ID_USER_SHIFT.') | ct.layer_id as id, v.user_id, ct.layer_id, COUNT(DISTINCT ct.root_ec_track_id) as validated, lt.total, MAX(v.validated_at) as last_validated_at');

        if ($viewer !== null && ! $viewer->hasRole('Administrator')) {
            if ($viewer->hasRole('Validator')) {
                $grouped->whereIn('ct.layer_id', $this->ownedLayerIdsQuery($viewer));
            } else {
                $grouped->whereRaw('1 = 0');
            }
        }

        return PassportStage::query()->fromSub($grouped, 'passport_stages');
    }

    /**
     * Scoping di ruolo su una query di `passport_stages` (righe singole,
     * con `ec_track_id`), regola B: Administrator vede tutto; Validator solo
     * le validazioni di tappe di sua gestione (managedTracksQuery()) in un
     * layer di cui è proprietario effettivo (ownedLayerIdsQuery()), qualunque
     * sia la fonte; ogni altro
     * ruolo nulla. Esclude sempre le righe non validate (PassportStage::scopeValidated()).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        // Solo tappe validate (non le righe parziali di oc:8165), per ogni ruolo.
        $query->scopes(['validated']);

        if ($user->hasRole('Administrator')) {
            return $query;
        }

        if ($user->hasRole('Validator')) {
            return $query->whereIn(
                'passport_stages.ec_track_id',
                $this->managedTracksQuery()
                    ->whereIn('layerables.layer_id', $this->ownedLayerIdsQuery($user))
                    ->select('ec_tracks.id'),
            );
        }

        return $query->whereRaw('1 = 0');
    }

    /**
     * Id dei layer di cui `$viewer` è proprietario effettivo:
     * `COALESCE(layers.user_id, camminiditalia.default_owner_id)` = suo id,
     * stessa regola di managedTracksQuery() e di App\Support\LayerOwner.
     * Scoping del Validator su Lens, elenco e filtro cammino.
     */
    public function ownedLayerIdsQuery(User $viewer): QueryBuilder
    {
        return DB::table('layers')
            ->whereRaw('COALESCE(layers.user_id, ?) = ?', [$this->defaultOwnerId(), $viewer->id])
            ->select('layers.id');
    }

    /**
     * Cammino completato: almeno una tappa e tutte validate. Regola unica per
     * le API (progressFields()) e per lo stato della Lens.
     */
    public static function isCompleted(int $validated, int $total): bool
    {
        return $total > 0 && $validated === $total;
    }

    /**
     * Per layer (`layer_id`): `total`, `validated`, `km_total`,
     * `km_validated`, `last_validated_at` delle tappe del cammino (regola A),
     * con le validazioni del solo utente indicato.
     */
    private function userTotalsQuery(User $user): QueryBuilder
    {
        // Due livelli (oc:8165). Prima si riduce ogni gruppo di tappe alternative a una riga
        // sola: il gruppo è percorso se lo è una qualsiasi delle sue tappe, e i suoi chilometri
        // si contano una volta sola. Poi si somma per cammino. Senza questo passaggio un
        // cammino con rami alternativi non raggiungerebbe mai il 100%, perché le varianti
        // stanno nello stesso cammino della principale e ne gonfiano il totale.
        $groups = DB::query()
            ->fromSub($this->routeTracksQuery(), 'ct')
            ->leftJoin('passport_stages as v', function (JoinClause $join) use ($user) {
                $join->on('v.ec_track_id', '=', 'ct.ec_track_id')
                    ->where('v.user_id', '=', $user->id)
                    ->whereRaw(PassportStage::validatedSql('v'));
            })
            ->groupBy('ct.layer_id', 'ct.root_ec_track_id')
            ->select('ct.layer_id')
            // I km di un gruppo sono quelli della sua tappa principale: è il percorso ufficiale
            // del cammino, dichiarato dal gestore, e dà un totale uguale per tutti invece di uno
            // che dipende da quale ramo è il più lungo. Su Via Vandelli i due rami di partenza
            // differiscono di 19 km, quindi la scelta si vede. Il COALESCE copre il caso in cui
            // la radice sia la tappa stessa (nessuna variante, o principale fuori dal cammino).
            ->selectRaw('COALESCE(MAX(ct.distance_km) FILTER (WHERE ct.ec_track_id = ct.root_ec_track_id), MAX(ct.distance_km)) as distance_km')
            ->selectRaw('COUNT(v.id) > 0 as validated')
            ->selectRaw('MAX(v.validated_at) as validated_at');

        return DB::query()
            ->fromSub($groups, 'g')
            ->groupBy('g.layer_id')
            ->select('g.layer_id')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('COUNT(*) FILTER (WHERE g.validated) as validated')
            ->selectRaw('COALESCE(SUM(g.distance_km), 0) as km_total')
            ->selectRaw('COALESCE(SUM(CASE WHEN g.validated THEN g.distance_km ELSE 0 END), 0) as km_validated')
            ->selectRaw('MAX(g.validated_at) as last_validated_at');
    }

    /**
     * @return array{validated: int, total: int, percentage: int, completed: bool, km_validated: float, km_total: float}
     */
    private function progressFields(?object $row): array
    {
        $validated = (int) ($row->validated ?? 0);
        $total = (int) ($row->total ?? 0);

        return [
            'validated' => $validated,
            'total' => $total,
            'percentage' => $total > 0 ? intdiv($validated * 100, $total) : 0,
            'completed' => self::isCompleted($validated, $total),
            'km_validated' => round((float) ($row->km_validated ?? 0), 1),
            'km_total' => round((float) ($row->km_total ?? 0), 1),
        ];
    }

    private function layerTracksBaseQuery(): QueryBuilder
    {
        $morphClass = (new (config('wm-package.ec_track_model')))->getMorphClass();

        return DB::table('layerables')
            ->join('layers', 'layers.id', '=', 'layerables.layer_id')
            ->join('ec_tracks', 'ec_tracks.id', '=', 'layerables.layerable_id')
            ->where('layerables.layerable_type', $morphClass);
    }

    /**
     * Distanza corrente della tappa, stessa priorità di
     * HasDemClassification::classifyField() (wm-package): manual_data se non
     * vuoto, poi osm_data se la tappa ha un osmid, poi dem_data, altrimenti 0.
     * I valori non numerici (es. "12,5") valgono 0, senza correzioni.
     */
    private function distanceSql(): string
    {
        $num = fn (string $x) => "(CASE WHEN {$x} ~ '^-{0,1}[0-9]+(\\.[0-9]+){0,1}$' THEN ({$x})::double precision ELSE 0 END)";

        $manual = "ec_tracks.properties->'manual_data'->>'distance'";
        $osm = "ec_tracks.properties->'osm_data'->>'distance'";
        $dem = "ec_tracks.properties->'dem_data'->>'distance'";

        return 'CASE'
            ." WHEN NULLIF({$manual}, '') IS NOT NULL THEN {$num($manual)}"
            ." WHEN ec_tracks.osmid IS NOT NULL AND {$osm} IS NOT NULL THEN {$num($osm)}"
            ." ELSE {$num($dem)}"
            .' END';
    }

    private function defaultOwnerId(): ?int
    {
        $id = config('camminiditalia.default_owner_id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * Traduzioni della tappa da `ec_tracks.name` (JSON traducibile o stringa
     * semplice → `{"it": ...}`), senza lingue nulle o vuote. Mai array vuoto:
     * senza nome torna un oggetto vuoto, così nel JSON esce `{}`.
     */
    private function translations(?string $raw): object
    {
        if ($raw === null || trim($raw) === '') {
            return new \stdClass;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return (object) ['it' => $raw];
        }

        return (object) array_filter(
            $decoded,
            fn ($v) => is_string($v) && trim($v) !== '',
        );
    }

    private function isoDate(?string $value): ?string
    {
        return $value !== null ? Carbon::parse($value)->toIso8601String() : null;
    }
}

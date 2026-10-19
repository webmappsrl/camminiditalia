<?php

namespace App\Nova\Filters;

use App\Nova\Filters\Concerns\DeduplicatesOptionLabels;
use App\Services\StageProgressService;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\LensRequest;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\Layer;

/**
 * Cammino (oc:8676). Sulla risorsa (regola B, «tappe del gestore») una
 * validazione appartiene a ogni layer in cui la sua tappa è del proprietario
 * effettivo (managedTracksQuery(); non `validated_ec_tracks.layer_id`, che è
 * solo il primo cammino della validazione). Su una Lens (`LensRequest`) le
 * righe sono già aggregate per layer con la regola A (summaryQuery()), quindi
 * si filtra direttamente su `validated_ec_tracks.layer_id` della riga.
 *
 * Opzioni: Administrator tutti i layer con almeno una tappa (regola B sulla
 * risorsa, regola A sulla Lens, così ogni riga della Lens ha la sua opzione),
 * Validator i layer di cui è proprietario effettivo
 * (StageProgressService::ownedLayerIdsQuery()), altri nessuno.
 */
class ValidatedEcTrackLayerFilter extends Filter
{
    use DeduplicatesOptionLabels;

    public function __construct()
    {
        $this->searchable();
    }

    public function name(): string
    {
        return __('Route');
    }

    public function apply(NovaRequest $request, Builder $query, mixed $value): Builder
    {
        if (! is_numeric($value)) {
            return $query;
        }

        if ($request instanceof LensRequest) {
            return $query->where('validated_ec_tracks.layer_id', (int) $value);
        }

        return $query->whereIn(
            'validated_ec_tracks.ec_track_id',
            app(StageProgressService::class)->managedTracksQuery()
                ->where('layerables.layer_id', (int) $value)
                ->select('ec_tracks.id'),
        );
    }

    /**
     * @return array<string, int>
     */
    public function options(NovaRequest $request): array
    {
        $viewer = $request->user();

        if ($viewer === null) {
            return [];
        }

        $service = app(StageProgressService::class);

        if ($viewer->hasRole('Administrator')) {
            $tracks = $request instanceof LensRequest ? $service->routeTracksQuery() : $service->managedTracksQuery();
            $layers = Layer::whereIn(
                'id',
                DB::query()->fromSub($tracks, 'ct')->select('ct.layer_id'),
            );
        } elseif ($viewer->hasRole('Validator')) {
            $layers = Layer::whereIn('id', $service->ownedLayerIdsQuery($viewer));
        } else {
            return [];
        }

        return $this->optionsFromLabels(
            $layers->get()
                ->map(fn (Layer $layer) => ['id' => $layer->id, 'label' => $layer->getStringName() !== '' ? $layer->getStringName() : '#'.$layer->id]),
        );
    }
}

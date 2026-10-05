<?php

namespace App\Nova\Lenses;

use App\Models\ValidatedEcTrack as ValidatedEcTrackModel;
use App\Nova\Filters\ValidatedEcTrackLayerFilter;
use App\Nova\Filters\ValidatedEcTrackUserFilter;
use App\Nova\ValidatedEcTrack;
use App\Services\StageProgressService;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\LensRequest;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Lenses\Lens;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\User;

/**
 * Tappe validate per camminatore e cammino (oc:8676): una riga per coppia
 * utente/layer da StageProgressService::summaryQuery(), che applica già lo
 * scoping di ruolo dentro l'aggregato (Administrator tutto, Validator i suoi
 * layer, altri nulla). L'`id` delle righe è `(user_id << StageProgressService::SUMMARY_ID_USER_SHIFT) | layer_id`, non
 * una validazione: ValidatedEcTrack::authorizedToView() è false sulle
 * LensRequest, quindi nessun link al dettaglio. Nessuna action.
 *
 * Paginazione: summaryQuery() avvolge il GROUP BY in una subquery con alias
 * `validated_ec_tracks`, così simplePaginate(), filtri e ordinamenti lavorano
 * su colonne semplici.
 */
class ValidatedEcTrackSummary extends Lens
{
    public function name(): string
    {
        return __('Per user and route');
    }

    public function uriKey(): string
    {
        return 'validated-ec-track-summary';
    }

    public static function query(LensRequest $request, Builder $query): Builder
    {
        $viewer = $request->user();

        $summary = $viewer instanceof User
            ? app(StageProgressService::class)->summaryQuery($viewer)
            : ValidatedEcTrackModel::query()->whereRaw('1 = 0');

        $summary->with(['user', 'layer'])->withCasts(['last_validated_at' => 'datetime']);

        return $request->withOrdering(
            $request->withFilters($summary),
            fn (Builder $q) => $q->orderByDesc('validated_ec_tracks.last_validated_at'),
        )->orderBy('validated_ec_tracks.id');
    }

    public function fields(NovaRequest $request): array
    {
        /** @var ValidatedEcTrackModel|\stdClass $row */
        $row = $this->resource;

        return [
            ValidatedEcTrack::userField($request, $row),

            Text::make(__('Route'), 'layer_id', fn () => $row->layer instanceof Layer
                ? $row->layer->getStringName()
                : '—'),

            Text::make(__('Stages'), 'validated', fn () => sprintf('%d / %d', $row->getAttribute('validated'), $row->getAttribute('total'))),

            Text::make(__('Status'), fn () => StageProgressService::isCompleted((int) $row->getAttribute('validated'), (int) $row->getAttribute('total'))
                ? __('Completed')
                : __('In progress')),

            DateTime::make(__('Last validation'), 'last_validated_at')
                ->sortable(),
        ];
    }

    public function filters(NovaRequest $request): array
    {
        return [
            new ValidatedEcTrackUserFilter,
            new ValidatedEcTrackLayerFilter,
        ];
    }

    public function actions(NovaRequest $request): array
    {
        return [];
    }
}

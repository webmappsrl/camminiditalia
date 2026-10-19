<?php

namespace App\Nova\Lenses;

use App\Models\PassportStage as PassportStageModel;
use App\Nova\Filters\PassportStageLayerFilter;
use App\Nova\Filters\PassportStageUserFilter;
use App\Nova\PassportStage;
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
 * una validazione: PassportStage::authorizedToView() è false sulle
 * LensRequest, quindi nessun link al dettaglio. Nessuna action.
 *
 * Paginazione: summaryQuery() avvolge il GROUP BY in una subquery con alias
 * `passport_stages`, così simplePaginate(), filtri e ordinamenti lavorano
 * su colonne semplici.
 */
class PassportStageSummary extends Lens
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
            : PassportStageModel::query()->whereRaw('1 = 0');

        $summary->with(['user', 'layer'])->withCasts(['last_validated_at' => 'datetime']);

        return $request->withOrdering(
            $request->withFilters($summary),
            fn (Builder $q) => $q->orderByDesc('passport_stages.last_validated_at'),
        )->orderBy('passport_stages.id');
    }

    public function fields(NovaRequest $request): array
    {
        /** @var PassportStageModel|\stdClass $row */
        $row = $this->resource;

        return [
            PassportStage::userField($request, $row),

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
            new PassportStageUserFilter,
            new PassportStageLayerFilter,
        ];
    }

    public function actions(NovaRequest $request): array
    {
        return [];
    }
}

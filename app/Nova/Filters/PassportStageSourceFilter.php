<?php

namespace App\Nova\Filters;

use App\Models\PassportStage;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * Origine della validazione (oc:8676): credenziale cartacea o GPS. Solo per
 * la risorsa: le righe aggregate della Lens non hanno `source`.
 */
class PassportStageSourceFilter extends Filter
{
    public function name(): string
    {
        return __('Source');
    }

    public function apply(NovaRequest $request, Builder $query, mixed $value): Builder
    {
        if (! in_array($value, [PassportStage::SOURCE_MANUAL, PassportStage::SOURCE_GPS], true)) {
            return $query;
        }

        return $query->where('passport_stages.source', $value);
    }

    /**
     * @return array<string, string>
     */
    public function options(NovaRequest $request): array
    {
        return [
            __('Paper passport') => PassportStage::SOURCE_MANUAL,
            __('GPS') => PassportStage::SOURCE_GPS,
        ];
    }
}

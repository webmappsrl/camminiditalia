<?php

namespace App\Nova\Filters;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * Diari con o senza consenso alla ripubblicazione sui canali di Cammini d'Italia (oc:8169).
 */
class DiaryPublicationConsentFilter extends Filter
{
    public function name(): string
    {
        return __('Publication consent');
    }

    public function apply(NovaRequest $request, Builder $query, mixed $value): Builder
    {
        return match ($value) {
            'yes' => $query->where('publication_consent', true),
            'no' => $query->where('publication_consent', false),
            default => $query,
        };
    }

    /**
     * @return array<string, string>
     */
    public function options(NovaRequest $request): array
    {
        return [
            __('With consent') => 'yes',
            __('Without consent') => 'no',
        ];
    }
}

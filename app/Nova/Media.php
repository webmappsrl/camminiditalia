<?php

namespace App\Nova;

use App\Models\Experience;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Nova\Media as WmNovaMedia;

/**
 * Media di Nova. La MediaPolicy del package dà al Validator accesso a tutti i
 * media: le foto delle esperienze (oc:8169) sono private, quindi chi non è
 * Administrator non le vede in nessuna query di Nova. I media delle richieste
 * di certificazione restano visibili: il Validator li usa per decidere.
 */
class Media extends WmNovaMedia
{
    public static function indexQuery(NovaRequest $request, Builder $query): Builder
    {
        return self::withoutExperienceMedia($request, parent::indexQuery($request, $query));
    }

    public static function detailQuery(NovaRequest $request, Builder $query): Builder
    {
        return self::withoutExperienceMedia($request, parent::detailQuery($request, $query));
    }

    public static function editQuery(NovaRequest $request, Builder $query): Builder
    {
        return self::withoutExperienceMedia($request, parent::editQuery($request, $query));
    }

    public static function replicateQuery(NovaRequest $request, Builder $query): Builder
    {
        return self::withoutExperienceMedia($request, parent::replicateQuery($request, $query));
    }

    public static function relatableQuery(NovaRequest $request, Builder $query): Builder
    {
        return self::withoutExperienceMedia($request, parent::relatableQuery($request, $query));
    }

    /**
     * Esclude i media delle esperienze per chi non è Administrator.
     */
    private static function withoutExperienceMedia(NovaRequest $request, Builder $query): Builder
    {
        if ($request->user()?->hasRole('Administrator')) {
            return $query;
        }

        return $query->where('model_type', '!=', (new Experience)->getMorphClass());
    }
}

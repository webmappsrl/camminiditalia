<?php

namespace App\Nova;

use App\Nova\Traits\FiltersUsersByRoleTrait;
use Illuminate\Support\Facades\Auth;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Nova\Layer as WmNovaLayer;

class Layer extends WmNovaLayer
{
    use FiltersUsersByRoleTrait;

    public static function indexQuery(NovaRequest $request, $query)
    {
        $user = Auth::user();

        if ($user && ! $user->hasRole('Administrator')) {
            return $query->where('user_id', $user->id);
        }

        return $query;
    }

    public function fields(NovaRequest $request): array
    {
        return [
            ...parent::fields($request),
        ];
    }
}

<?php

namespace App\Nova;

use App\Nova\Traits\HidesAppFromIndexTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Nova\UgcTrack as WmNovaUgcTrack;

class UgcTrack extends WmNovaUgcTrack
{
    use HidesAppFromIndexTrait;

    public static function label(): string
    {
        return __('Tracks');
    }

    public static function availableForNavigation(Request $request): bool
    {
        return $request->user()->hasRole('Administrator');
    }

    public static function indexQuery(NovaRequest $request, $query): Builder
    {
        if ($request->user()->hasRole('Administrator')) {
            return $query;
        }

        return $query->whereRaw('1=0');
    }
}

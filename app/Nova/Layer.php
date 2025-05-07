<?php

namespace App\Nova;

use Illuminate\Support\Facades\Auth;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Panel;
use Wm\LayerFeatures\LayerFeatures;
use Wm\WmPackage\Models\EcTrack as WmEcTrack;
use Wm\WmPackage\Nova\Layer as WmNovaLayer;

class Layer extends WmNovaLayer
{
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
            Panel::make('Ec Tracks', [
                LayerFeatures::make('ecTracks', $this->resource, WmEcTrack::class)->hideWhenCreating(),
            ]),
        ];
    }
}

<?php

namespace App\Nova;

use Laravel\Nova\Fields\MorphToMany;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\LayerFeatures\LayerFeatures;
use Wm\WmPackage\Models\EcTrack as WmEcTrack;
use Wm\WmPackage\Nova\Layer as WmNovaLayer;

class Layer extends WmNovaLayer
{
    public function fields(NovaRequest $request): array
    {
        return [
            ...parent::fields($request),
            //  MorphToMany::make("ecTracks"),
            LayerFeatures::make('ecTracks', $this->resource, WmEcTrack::class)->hideWhenCreating(),
        ];
    }
}

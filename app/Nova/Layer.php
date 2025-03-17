<?php

namespace App\Nova;

use Laravel\Nova\Fields\MorphToMany;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\LayerFeatures\LayerFeatures;
use Wm\WmPackage\Nova\Layer as WmNovaLayer;
use \Wm\WmPackage\Models\EcTrack as WmEcTrack;

class Layer extends WmNovaLayer
{
    public static $model = \Wm\WmPackage\Models\Layer::class;


    public function fields(NovaRequest $request): array
    {
        return [
            ...parent::fields($request),
            //  MorphToMany::make("ecTracks"),
            LayerFeatures::make("ecTracks", $this->resource, WmEcTrack::class)->hideWhenCreating(),
        ];
    }
}

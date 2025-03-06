<?php

namespace App\Nova;

use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\LayerFeatures\LayerFeatures;
use Wm\WmPackage\Nova\Layer as WmNovaLayer;

class Layer extends WmNovaLayer
{
    public static $model = \Wm\WmPackage\Models\Layer::class;


    public function fields(NovaRequest $request): array
    {
        return [
            ...parent::fields($request),
            LayerFeatures::make("layer_features")->onlyOnForms(),
        ];
    }
}

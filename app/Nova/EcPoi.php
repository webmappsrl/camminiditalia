<?php

namespace App\Nova;

use Wm\WmPackage\Nova\EcPoi as WmNovaEcPoi;
use App\Models\EcPoi as EcPoiModel;

class EcPoi extends WmNovaEcPoi 
{
    public static $model = EcPoiModel::class;

    public static function label(): string
    {
        return __('Pois');
    }
}

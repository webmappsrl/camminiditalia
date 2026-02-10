<?php

namespace App\Nova;

use App\Models\EcPoi as EcPoiModel;
use Wm\WmPackage\Nova\EcPoi as WmNovaEcPoi;

class EcPoi extends WmNovaEcPoi
{
    public static $model = EcPoiModel::class;

    public static function label(): string
    {
        return __('Pois');
    }
}

<?php

namespace App\Nova;

use Wm\WmPackage\Nova\EcPoi as WmNovaEcPoi;

class EcPoi extends WmNovaEcPoi {

    public static function label(): string
    {
        return __('Pois');
    }
}

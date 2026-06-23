<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Wm\WmPackage\Database\Factories\EcPoiFactory;
use Wm\WmPackage\Models\EcPoi as WmEcPoi;

class EcPoi extends WmEcPoi
{
    protected static function newFactory(): Factory
    {
        return EcPoiFactory::new();
    }
}

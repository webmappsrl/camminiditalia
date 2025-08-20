<?php

namespace App\Nova;

use App\Nova\Traits\HidesAppFromIndexTrait;
use Wm\WmPackage\Nova\UgcPoi as WmNovaUgcPoi;

class UgcPoi extends WmNovaUgcPoi
{
    use HidesAppFromIndexTrait;
}

<?php

namespace App\Nova;

use App\Nova\Traits\HidesAppFromIndexTrait;
use Wm\WmPackage\Nova\UgcTrack as WmNovaUgcTrack;

class UgcTrack extends WmNovaUgcTrack
{
    use HidesAppFromIndexTrait;
}

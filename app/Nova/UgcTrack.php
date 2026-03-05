<?php

namespace App\Nova;

use App\Nova\Traits\HidesAppFromIndexTrait;
use Wm\WmPackage\Nova\UgcTrack as WmNovaUgcTrack;

class UgcTrack extends WmNovaUgcTrack
{
    use HidesAppFromIndexTrait;

    public static function label(): string
    {
        return __('Tracks');
    }
}

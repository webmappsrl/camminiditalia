<?php

namespace App\Nova;

use App\Nova\Traits\FiltersUsersByRoleTrait;
use Wm\WmPackage\Nova\EcTrack as WmNovaEcTrack;

class EcTrack extends WmNovaEcTrack
{
    use FiltersUsersByRoleTrait;
}

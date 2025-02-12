<?php

namespace App\Nova;

use Wm\WmPackage\Nova\Media as WmNovaMedia;
use Laravel\Nova\Fields\Code;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Http\Requests\NovaRequest;

class Media extends WmNovaMedia
{
    public static $model = \Wm\WmPackage\Models\Media::class;
}

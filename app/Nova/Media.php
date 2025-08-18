<?php

namespace App\Nova;

use Wm\WmPackage\Nova\Media as WmNovaMedia;

class Media extends WmNovaMedia
{
    public static function label(): string
    {
        return __('Media');
    }

    public static function singularLabel(): string
    {
        return __('Media');
    }
}

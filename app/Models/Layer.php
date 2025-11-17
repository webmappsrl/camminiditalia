<?php

namespace App\Models;

use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer as WmLayer;

class Layer extends WmLayer
{
    /**
     * Boot the model and register events.
     */
    protected static function booted(): void
    {
        parent::booted();

        static::creating(function ($layer) {
            // Set app_id automatically
            if (empty($layer->app_id)) {
                $layer->app_id = App::first()->id;
            }
        });
    }
}

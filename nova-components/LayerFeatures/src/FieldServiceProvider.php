<?php

namespace Wm\LayerFeatures;

use Illuminate\Support\Facades\Route;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Nova;
use Laravel\Nova\Events\ServingNova;
use Illuminate\Support\ServiceProvider;

class FieldServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Nova::serving(function (ServingNova $event) {
            Nova::mix('layer-features', __DIR__ . '/../dist/mix-manifest.json');
        });

        Route::middleware(['nova'])
            ->prefix('nova-vendor/layer-features')
            ->group(__DIR__ . '/../routes/api.php');
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }
}

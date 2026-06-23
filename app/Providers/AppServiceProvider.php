<?php

namespace App\Providers;

use App\Models\TaxonomyPoiType;
use App\Observers\LayerableObserver;
use App\Observers\LayerObserver;
use App\Observers\UgcObserver;
use App\Policies\EcPoiPolicy;
use App\Policies\LayerPolicy;
use App\Policies\TaxonomyPoiTypePolicy;
use App\Policies\UgcPoiPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\Layerable;
use Wm\WmPackage\Models\UgcPoi;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Policies\PermissionPolicy;
use Wm\WmPackage\Policies\RolePolicy;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(\Wm\WmPackage\Models\UgcPoi::class, UgcPoiPolicy::class);
        Gate::policy(Layer::class, LayerPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(Permission::class, PermissionPolicy::class);
        Gate::policy(TaxonomyPoiType::class, TaxonomyPoiTypePolicy::class);
        Gate::policy(EcPoi::class, EcPoiPolicy::class);

        UgcPoi::observe(UgcObserver::class);
        UgcTrack::observe(UgcObserver::class);
        Layer::observe(LayerObserver::class);
        Layerable::observe(LayerableObserver::class);
    }
}

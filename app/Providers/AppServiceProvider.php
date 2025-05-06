<?php

namespace App\Providers;

use App\Policies\LayerPolicy;
use Wm\WmPackage\Models\Layer;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Gate;
use Wm\WmPackage\Policies\RolePolicy;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Permission;
use Wm\WmPackage\Policies\PermissionPolicy;

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
        Gate::policy(Layer::class, LayerPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(Permission::class, PermissionPolicy::class);
    }
}

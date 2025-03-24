<?php

namespace App\Providers;

use App\Nova\App;
use App\Nova\Media;
use App\Models\User;
use App\Nova\UgcPoi;
use App\Nova\UgcTrack;
use Laravel\Nova\Nova;
use Illuminate\Http\Request;
use Wm\WmPackage\Nova\EcPoi;
use Wm\WmPackage\Nova\Layer;
use App\Nova\Dashboards\Main;
use Laravel\Fortify\Features;
use App\Nova\TaxonomyActivity;
use Wm\WmPackage\Nova\EcTrack;
use Laravel\Nova\Menu\MenuItem;
use Laravel\Nova\Menu\MenuSection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Blade;
use Laravel\Nova\NovaApplicationServiceProvider;

class NovaServiceProvider extends NovaApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        $this->getFooter();

        Nova::mainMenu(function (Request $request) {
            return [
                MenuSection::dashboard(Main::class)->icon('chart-bar'),

                MenuItem::resource(App::class),

                MenuSection::make('UGC', [
                    MenuItem::resource(UgcPoi::class),
                    MenuItem::resource(UgcTrack::class),
                    MenuItem::resource(Media::class),
                ])->icon('document'),

                MenuSection::make('EC', [
                    MenuItem::resource(EcPoi::class),
                    MenuItem::resource(EcTrack::class),
                ])->icon('document'),

                MenuItem::resource(Layer::class),

                MenuSection::make('Taxonomies', [
                    MenuItem::resource(TaxonomyActivity::class),
                ])->icon('document'),

                MenuSection::make('Tools', [
                    MenuItem::externalLink('Horizon', url('/horizon'))->openInNewTab(),
                    MenuItem::externalLink('Telescope', url('/telescope'))->openInNewTab(),
                ])->icon('briefcase')->canSee(function (Request $request) {
                    return $request->user()->email === 'team@webmapp.it';
                }),
            ];
        });
    }

    /**
     * Register the configurations for Laravel Fortify.
     */
    protected function fortify(): void
    {
        Nova::fortify()
            ->features([
                Features::updatePasswords(),
                // Features::emailVerification(),
                // Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true]),
            ])
            ->register();
    }

    /**
     * Register the Nova routes.
     */
    protected function routes(): void
    {
        Nova::routes()
            ->withAuthenticationRoutes(default: true)
            ->withPasswordResetRoutes()
            ->withoutEmailVerificationRoutes()
            ->register();
    }

    /**
     * Register the Nova gate.
     *
     * This gate determines who can access Nova in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewNova', function (User $user) {
            return true;
        });
    }

    /**
     * Get the dashboards that should be listed in the Nova sidebar.
     *
     * @return array<int, \Laravel\Nova\Dashboard>
     */
    protected function dashboards(): array
    {
        return [
            new \App\Nova\Dashboards\Main,
        ];
    }

    /**
     * Get the tools that should be listed in the Nova sidebar.
     *
     * @return array<int, \Laravel\Nova\Tool>
     */
    public function tools(): array
    {
        return [];
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        parent::register();

        //
    }

    private function getFooter()
    {
        Nova::footer(function () {
            return Blade::render('nova/footer');
        });
    }
}

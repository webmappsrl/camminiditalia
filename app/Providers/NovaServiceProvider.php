<?php

namespace App\Providers;

use App\Models\User;
use App\Nova\App;
use App\Nova\Dashboards\Main;
use App\Nova\Media;
use App\Nova\UgcPoi;
use App\Nova\UgcTrack;
use App\Nova\User as NovaUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Laravel\Fortify\Features;
use Laravel\Nova\Menu\MenuItem;
use Laravel\Nova\Menu\MenuSection;
use Laravel\Nova\Nova;
use Laravel\Nova\NovaApplicationServiceProvider;
use Wm\WmPackage\Nova\EcPoi;
use Wm\WmPackage\Nova\EcTrack;
use Wm\WmPackage\Nova\Layer;

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

                MenuSection::make(' ', [
                    MenuItem::resource(App::class),
                    MenuItem::resource(NovaUser::class),
                    MenuItem::resource(Media::class),
                ])->icon(''),

                MenuSection::make('UGC', [
                    MenuItem::resource(UgcPoi::class),
                    MenuItem::resource(UgcTrack::class),
                ])->icon('document'),

                MenuSection::make('EC', [
                    // MenuItem::resource(EcPoi::class),
                    MenuItem::resource(EcTrack::class),
                    MenuItem::resource(Layer::class),
                ])->icon('document'),

                // MenuSection::make('Taxonomies', [
                //     MenuItem::resource(TaxonomyActivity::class),
                // ])->icon('document'),

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
            return ! $user->hasRole('Guest');
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

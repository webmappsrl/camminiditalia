<?php

namespace App\Providers;

use App\Models\CertificationRequest;
use App\Models\TaxonomyPoiType;
use App\Observers\CertificationRequestCleanupObserver;
use App\Observers\EcPoiValidatorLayerObserver;
use App\Observers\EcTrackGeometryAttributesObserver;
use App\Observers\LayerableObserver;
use App\Observers\LayerAttributesObserver;
use App\Observers\LayerObserver;
use App\Observers\UgcObserver;
use App\Policies\CertificationRequestPolicy;
use App\Policies\EcPoiPolicy;
use App\Policies\EcTrackPolicy;
use App\Policies\LayerPolicy;
use App\Policies\TaxonomyPoiTypePolicy;
use App\Policies\UgcPoiPolicy;
use App\Policies\UgcTrackPolicy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\Layerable;
use Wm\WmPackage\Models\UgcPoi;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Models\User as WmUser;
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
        // Registrazione grezza dell'evento "deleting", PRIMA di ogni altro
        // riferimento a EcTrack in questo metodo: Wm\WmPackage\Models\EcTrack
        // ha un proprio EcTrackObserver::deleting() (package) che cancella
        // subito le righe del pivot `layerables` associate alla traccia. Un
        // Model::observe() (incluso il nostro più sotto) innesca il primo
        // boot della classe come effetto collaterale di `new static`, e
        // quel boot registra SEMPRE prima l'observer del package — quindi un
        // secondo Model::observe() nostro arriverebbe sempre troppo tardi per
        // leggere il pivot. Event::listen() diretto, PRIMA di qualunque
        // riferimento che tocchi la classe EcTrack, evita del tutto quel
        // boot cascade e garantisce che il nostro listener legga il pivot
        // mentre è ancora intatto.
        $captureBeforeDelete = function ($track) {
            app(EcTrackGeometryAttributesObserver::class)->handleDeleting($track);
        };
        Event::listen('eloquent.deleting: '.EcTrack::class, $captureBeforeDelete);
        Event::listen('eloquent.deleting: '.\App\Models\EcTrack::class, $captureBeforeDelete);

        Gate::policy(UgcPoi::class, UgcPoiPolicy::class);
        Gate::policy(UgcTrack::class, UgcTrackPolicy::class);
        Gate::policy(Layer::class, LayerPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(Permission::class, PermissionPolicy::class);
        Gate::policy(TaxonomyPoiType::class, TaxonomyPoiTypePolicy::class);
        Gate::policy(EcPoi::class, EcPoiPolicy::class);
        Gate::policy(EcTrack::class, EcTrackPolicy::class);
        Gate::policy(CertificationRequest::class, CertificationRequestPolicy::class);

        UgcPoi::observe(UgcObserver::class);
        UgcTrack::observe(UgcObserver::class);
        Layer::observe(LayerObserver::class);
        Layerable::observe(LayerableObserver::class);
        Layerable::observe(LayerAttributesObserver::class);
        EcPoi::observe(EcPoiValidatorLayerObserver::class);
        \App\Models\EcPoi::observe(EcPoiValidatorLayerObserver::class);
        EcTrack::observe(EcTrackGeometryAttributesObserver::class);
        \App\Models\EcTrack::observe(EcTrackGeometryAttributesObserver::class);

        // Doppia registrazione: la guard `api` usa App\Models\User, mentre Wm\WmPackage\Models\User
        // copre il codice del package che istanzia direttamente quella classe (gli eventi
        // Eloquent sono per classe concreta, un observer non vale per la sottoclasse/parent).
        WmUser::observe(CertificationRequestCleanupObserver::class);
        \App\Models\User::observe(CertificationRequestCleanupObserver::class);
        Layer::observe(CertificationRequestCleanupObserver::class);
    }
}

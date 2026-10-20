<?php

namespace App\Providers;

use App\Models\CertificationRequest;
use App\Models\Experience;
use App\Models\TaxonomyPoiType;
use App\Models\User;
use App\Observers\CertificationRequestCleanupObserver;
use App\Observers\EcPoiValidatorLayerObserver;
use App\Observers\EcTrackGeometryAttributesObserver;
use App\Observers\ExperienceCleanupObserver;
use App\Observers\LayerableObserver;
use App\Observers\LayerAttributesObserver;
use App\Observers\LayerObserver;
use App\Observers\PassportLayerObserver;
use App\Observers\PassportShareCleanupObserver;
use App\Observers\UgcObserver;
use App\Observers\UgcTrackMatchingRunObserver;
use App\Policies\CertificationRequestPolicy;
use App\Policies\EcPoiPolicy;
use App\Policies\EcTrackPolicy;
use App\Policies\ExperiencePolicy;
use App\Policies\LayerPolicy;
use App\Policies\TaxonomyPoiTypePolicy;
use App\Policies\UgcPoiPolicy;
use App\Policies\UgcTrackPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\Layerable;
use Wm\WmPackage\Models\UgcPoi;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Models\UgcTrackMatchingRun;
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
        Gate::policy(Experience::class, ExperiencePolicy::class);

        UgcPoi::observe(UgcObserver::class);
        UgcTrack::observe(UgcObserver::class);
        Layer::observe(LayerObserver::class);
        Layerable::observe(LayerableObserver::class);
        Layerable::observe(LayerAttributesObserver::class);
        EcPoi::observe(EcPoiValidatorLayerObserver::class);
        \App\Models\EcPoi::observe(EcPoiValidatorLayerObserver::class);
        EcTrack::observe(EcTrackGeometryAttributesObserver::class);
        \App\Models\EcTrack::observe(EcTrackGeometryAttributesObserver::class);

        // Passaporto automatico (oc:8165): il verdetto sulle tappe si rifà quando la
        // corrispondenza di una camminata è pronta. wm-package non emette eventi a fine
        // calcolo, ma `markComputed()` scrive questa riga con Eloquent.
        UgcTrackMatchingRun::observe(UgcTrackMatchingRunObserver::class);

        // Accensione del passaporto su un cammino (oc:8165): le sue tappe entrano nel calcolo
        // per la prima volta, e chi ci ha già camminato deve trovarsele popolate.
        Layer::observe(PassportLayerObserver::class);

        // Doppia registrazione: la guard `api` usa App\Models\User, mentre Wm\WmPackage\Models\User
        // copre il codice del package che istanzia direttamente quella classe (gli eventi
        // Eloquent sono per classe concreta, un observer non vale per la sottoclasse/parent).
        WmUser::observe(CertificationRequestCleanupObserver::class);
        User::observe(CertificationRequestCleanupObserver::class);
        Layer::observe(CertificationRequestCleanupObserver::class);

        // Esperienze (oc:8169): stessa doppia registrazione, per ripulire media e file.
        WmUser::observe(ExperienceCleanupObserver::class);
        \App\Models\User::observe(ExperienceCleanupObserver::class);
        Layer::observe(ExperienceCleanupObserver::class);

        // Condivisioni del passaporto (oc:8702, oc:8703): stessa doppia
        // registrazione per User ed EcTrack, perché gli eventi Eloquent sono
        // per classe concreta.
        WmUser::observe(PassportShareCleanupObserver::class);
        User::observe(PassportShareCleanupObserver::class);
        Layer::observe(PassportShareCleanupObserver::class);
        EcTrack::observe(PassportShareCleanupObserver::class);
        \App\Models\EcTrack::observe(PassportShareCleanupObserver::class);

        // Immagine del cammino completato (oc:8703): al più 10 richieste al
        // minuto per utente, con una chiave propria, separata da quella di
        // `throttle:10,1` usata da certificazione e condivisione della tappa.
        RateLimiter::for('passport-route-share', fn (Request $request) => Limit::perMinute(10)
            ->by('passport-route-share:'.($request->user()?->id ?: $request->ip())));

        // Esperienze (oc:8169): limite con nome proprio, per lo stesso motivo di
        // `passport-route-share`: con `throttle:10,1` creazione e modifica
        // consumerebbero le richieste di certificazione e condivisione della tappa.
        RateLimiter::for('experiences', fn (Request $request) => Limit::perMinute(10)
            ->by('experiences:'.($request->user()?->id ?: $request->ip())));
    }
}

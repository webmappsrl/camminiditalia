<?php

use App\Http\Controllers\Api\CertificationRequestController;
use App\Http\Controllers\Api\ExperienceController;
use App\Http\Controllers\Api\PassportRouteShareController;
use App\Http\Controllers\Api\PassportStageShareController;
use App\Http\Controllers\Api\StageProgressController;
use App\Http\Middleware\EnsureExperiencesEnabled;
use App\Http\Middleware\EnsurePassportEnabled;
use Illuminate\Support\Facades\Route;

/*
 * API "passaporto camminatore", tutte con `auth:api` e nomi di route
 * prefissati con `camminiditalia.` per non collidere con quelle del package
 * (es. `layer.favorite.*` su `api/layer/favorite/*`, vedi
 * ../wm-package/routes/api.php):
 *
 * - `camminiditalia.api.layer.*` su `api/layer/{layer}/...`: richiesta di
 *   certificazione della credenziale cartacea (oc:8653) e progresso del
 *   camminatore sul cammino, `GET api/layer/{layer}/progress` (oc:8676),
 *   immagine di condivisione della tappa,
 *   `POST api/layer/{layer}/stage/{track}/share-image` (oc:8702), e del
 *   cammino completato, `POST api/layer/{layer}/share-image` (oc:8703);
 * - `camminiditalia.api.passport`, `GET api/passport` (oc:8676): cammini con
 *   il passaporto acceso (oc:8763) e almeno una tappa, anche con 0 validate,
 *   con i relativi totali e `validated_tracks`.
 */
Route::name('camminiditalia.api.layer.')
    ->prefix('layer')
    // 404 sui cammini senza `passport_enabled` (oc:8763), dopo l'autenticazione.
    ->middleware(['auth:api', EnsurePassportEnabled::class])
    ->group(function () {
        Route::post('/{layer}/certification', [CertificationRequestController::class, 'store'])
            ->whereNumber('layer')
            ->middleware('throttle:10,1')
            ->name('certification.store');
        Route::get('/{layer}/certification', [CertificationRequestController::class, 'show'])
            ->whereNumber('layer')
            ->name('certification.show');
        Route::get('/{layer}/progress', [StageProgressController::class, 'progress'])
            ->whereNumber('layer')
            ->name('progress');
        Route::post('/{layer}/stage/{track}/share-image', [PassportStageShareController::class, 'store'])
            ->whereNumber(['layer', 'track'])
            // Al più 10 richieste al minuto per utente: comporre l'immagine
            // scarica tile e disegna con GD (secondi di CPU), e l'app la
            // chiede solo al tocco su «Condividi»; la cache copre i ripetuti.
            ->middleware('throttle:10,1')
            ->name('stage.share-image');
        Route::post('/{layer}/share-image', [PassportRouteShareController::class, 'store'])
            ->whereNumber('layer')
            // Limite con nome proprio (oc:8703): `throttle:10,1` usa come
            // chiave solo l'utente, quindi certificazione e condivisione
            // della tappa consumerebbero le stesse 10 richieste al minuto.
            ->middleware('throttle:passport-route-share')
            ->name('route.share-image');
    });

Route::get('/passport', [StageProgressController::class, 'passport'])
    ->middleware('auth:api')
    ->name('camminiditalia.api.passport');

/*
 * Esperienze dei camminatori (oc:8169): elenco, creazione, modifica ed
 * eliminazione, solo le proprie. L'interruttore viene prima dell'autenticazione:
 * spento, 404 anche a chi non è autenticato.
 */
Route::name('camminiditalia.api.experiences.')
    ->prefix('experiences')
    ->middleware([EnsureExperiencesEnabled::class, 'auth:api'])
    ->group(function () {
        Route::get('/', [ExperienceController::class, 'index'])->name('index');
        // Upload di foto (ricodifica con GD): al più 10 richieste al minuto per utente,
        // con un limite proprio (`experiences`) che non consuma quello del passaporto.
        Route::post('/', [ExperienceController::class, 'store'])
            ->middleware('throttle:experiences')
            ->name('store');
        // Binding implicito per id: il 404 sull'esperienza altrui sta in controller e request, non dipende dalla cache delle route.
        Route::post('/{experience}', [ExperienceController::class, 'update'])
            ->whereNumber('experience')
            ->middleware('throttle:experiences')
            ->name('update');
        Route::delete('/{experience}', [ExperienceController::class, 'destroy'])
            ->whereNumber('experience')
            ->name('destroy');
    });

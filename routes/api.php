<?php

use App\Http\Controllers\Api\CertificationRequestController;
use App\Http\Controllers\Api\PassportStageShareController;
use App\Http\Controllers\Api\StageProgressController;
use Illuminate\Support\Facades\Route;

/*
 * API "passaporto camminatore", tutte con `auth:api` e nomi di route
 * prefissati con `camminiditalia.` per non collidere con quelle del package
 * (es. `layer.favorite.*` su `api/layer/favorite/*`, vedi
 * ../wm-package/routes/api.php):
 *
 * - `camminiditalia.api.layer.*` su `api/layer/{layer}/...`: richiesta di
 *   certificazione della credenziale cartacea (oc:8653) e progresso del
 *   camminatore sul cammino, `GET api/layer/{layer}/progress` (oc:8676);
 * - `camminiditalia.api.passport`, `GET api/passport` (oc:8676): cammini in
 *   cui l'utente ha almeno una tappa validata, con i relativi totali.
 */
Route::name('camminiditalia.api.layer.')
    ->prefix('layer')
    ->middleware('auth:api')
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
    });

Route::get('/passport', [StageProgressController::class, 'passport'])
    ->middleware('auth:api')
    ->name('camminiditalia.api.passport');

<?php

use App\Http\Controllers\Api\CertificationRequestController;
use Illuminate\Support\Facades\Route;

/*
 * API "passaporto camminatore" (oc:8653) — nomi di route prefissati con
 * `camminiditalia.` per non collidere con le route `layer.favorite.*` già
 * registrate dal package su `api/layer/favorite/*` (vedi
 * ../wm-package/routes/api.php).
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
    });

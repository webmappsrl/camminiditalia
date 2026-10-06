<?php

use App\Http\Controllers\PassportStageSharePageController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    // return view('welcome');
    return redirect('/nova');
});

// Pagina pubblica della tappa condivisa dal passaporto (oc:8702).
Route::get('/share/passport-stage/{uuid}', [PassportStageSharePageController::class, 'show'])
    ->whereUuid('uuid')
    ->name('share.passport-stage');

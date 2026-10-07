<?php

use App\Http\Controllers\PassportSharePageController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    // return view('welcome');
    return redirect('/nova');
});

// Pagina pubblica di una tappa (oc:8702) o di un cammino completato (oc:8703)
// condivisi dal passaporto.
Route::get('/share/passport/{uuid}', [PassportSharePageController::class, 'show'])
    ->whereUuid('uuid')
    ->name('share.passport');

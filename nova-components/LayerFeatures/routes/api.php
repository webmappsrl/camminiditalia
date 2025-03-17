<?php

use Illuminate\Support\Facades\Route;
use Wm\LayerFeatures\Http\Controllers\LayerFeatureController;

Route::post('/sync/{layerId}', [LayerFeatureController::class, 'sync']);

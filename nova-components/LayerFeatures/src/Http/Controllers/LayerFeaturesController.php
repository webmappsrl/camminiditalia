<?php

namespace Wm\WmPackage\NovaComponents\LayerFeatures\Http\Controllers;

use App\Nova\Layer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LayerFeaturesController
{
    public function store(Request $request, $layerId, $relation): JsonResponse
    {
        $layer = Layer::findOrFail($layerId);

        if (!method_exists($layer, $relation)) {
            return response()->json([
                'error' => "La relazione '{$relation}' non esiste nel modello Layer."
            ], 400);
        }

        $validatedData = $request->validate([
            'ec_features_ids' => 'required|array',
            'ec_features_ids.*' => 'id',
        ]);


        // ✅ Sincronizza dinamicamente la relazione
        $layer->{$relation}()->sync($validatedData['ec_features_ids']);

        return response()->json([
            'message' => 'LayerFeaturesController@store'
        ], 200);
    }
}

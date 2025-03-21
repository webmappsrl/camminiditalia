<?php

namespace Wm\LayerFeatures\Http\Controllers;

use Wm\WmPackage\Models\Layer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LayerFeatureController
{

    public function sync(Request $request, $layerId): JsonResponse
    {
        $layer = Layer::findOrFail($layerId);

        $validatedData = $request->validate([
            'features' => 'array',
            'model' => 'required|string'
        ]);

        // Creo un'istanza del modello per ottenere il nome della relazione
        $model = new $validatedData['model'];

        if (!method_exists($model, 'getLayerRelationName')) {
            return response()->json([
                'error' => "Il modello '{$validatedData['model']}' non implementa l'interfaccia LayerRelatedModel."
            ], 400);
        }

        $relationName = $model->getLayerRelationName();

        if (!method_exists($layer, $relationName)) {
            return response()->json([
                'error' => "La relazione '{$relationName}' non esiste nel modello Layer."
            ], 400);
        }

        $layer->{$relationName}()->sync($validatedData['features']);

        return response()->json([
            'message' => 'Features sincronizzate con successo'
        ], 200);
    }
}

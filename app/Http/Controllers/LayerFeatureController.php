<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Nova\Fields\LayerFeatures\Http\Controllers\LayerFeatureController as WmLayerFeatureController;

class LayerFeatureController extends WmLayerFeatureController
{
    public function getFeatures(Request $request, $layerId): JsonResponse
    {
        try {
            $layer = Layer::findOrFail($layerId);

            $validatedData = $request->validate([
                'model' => 'required|string',
                'page' => 'integer|min:1',
                'per_page' => 'integer|min:1|max:100',
                'search' => 'string|nullable',
                'view_mode' => 'string|in:details,edit',
            ]);

            $page = $validatedData['page'] ?? 1;
            $perPage = $validatedData['per_page'] ?? 50;
            $search = $validatedData['search'] ?? '';
            $viewMode = $validatedData['view_mode'] ?? 'edit';

            // Creo un'istanza del modello per ottenere il nome della relazione
            $model = new $validatedData['model'];

            if (! method_exists($model, 'getLayerRelationName')) {
                return response()->json([
                    'error' => "Il modello '{$validatedData['model']}' non implementa l'interfaccia LayerRelatedModel.",
                ], 400);
            }

            // Ottieni l'utente loggato
            /** @var \Wm\WmPackage\Models\User|null $user */
            $user = Auth::user();

            // Funzione helper per caricare le features associate
            $getAssociatedFeatures = function () use ($model, $layerId, $search) {
                $query = $model->newQuery();
                $query->whereHas('associatedLayers', function ($q) use ($layerId) {
                    $q->where('layer_id', $layerId);
                });

                if ($search) {
                    $query->where('name', 'like', "%{$search}%");
                }

                return $query->select(['id', 'name'])->orderBy('name', 'ASC');
            };

            // Query ottimizzata per ottenere le features
            if ($viewMode === 'details') {
                // In modalità details, mostra solo le features associate al layer
                $features = $getAssociatedFeatures()->paginate($perPage, ['*'], 'page', $page);

            } else {
                // In modalità edit, fai due chiamate separate

                // 1. Carica le features associate al layer
                $associatedFeatures = $getAssociatedFeatures()->get();

                // 2. Carica le altre features dell'app (non associate)
                $otherQuery = $model->newQuery();
                if ($layer->app_id) {
                    $otherQuery->where('app_id', $layer->app_id);
                }

                // Filtra per utente loggato (escludi Administrator)
                if ($user && ! $user->hasRole('Administrator')) {
                    $otherQuery->where('user_id', $user->id);
                }

                // Escludi quelle già associate
                if ($associatedFeatures->isNotEmpty()) {
                    $otherQuery->whereNotIn('id', $associatedFeatures->pluck('id'));
                }

                if ($search) {
                    $otherQuery->where('name', 'like', "%{$search}%");
                }

                $otherFeatures = $otherQuery->select(['id', 'name'])
                    ->orderBy('name', 'ASC')
                    ->get();

                // 3. Concatenazione: prima le associate, poi le altre
                $allFeatures = $associatedFeatures->concat($otherFeatures);

                // 4. Paginazione manuale
                $total = $allFeatures->count();
                $offset = ($page - 1) * $perPage;
                $paginatedFeatures = $allFeatures->slice($offset, $perPage);

                // Crea un oggetto paginazione manuale
                $features = new \Illuminate\Pagination\LengthAwarePaginator(
                    $paginatedFeatures,
                    $total,
                    $perPage,
                    $page,
                    ['path' => request()->url(), 'pageName' => 'page']
                );
            }

            return response()->json([
                'features' => array_values($features->items()), // Converte in array e reindirizza
                'pagination' => [
                    'current_page' => $features->currentPage(),
                    'last_page' => $features->lastPage(),
                    'per_page' => $features->perPage(),
                    'total' => $features->total(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('LayerFeatureController::getFeatures error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Errore interno del server: '.$e->getMessage(),
            ], 500);
        }
    }
}

<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Nova\Fields\LayerFeatures\Http\Controllers\LayerFeatureController as WmLayerFeatureController;
use Wm\WmPackage\Services\PBFGeneratorService;

class LayerFeatureController extends WmLayerFeatureController
{
    public function getFeatures(Request $request, $layerId): JsonResponse
    {
        try {
            $layer = Layer::findOrFail($layerId);
            $layerOwnerId = $layer->user_id ?? config('camminiditalia.default_owner_id');

            /** @var User|null $user */
            $user = Auth::user();

            if (! $user || ($layer->user_id !== $user->id && ! $user->hasRole('Administrator'))) {
                abort(403);
            }

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

            if (! in_array($validatedData['model'], [
                \App\Models\EcPoi::class,
                \Wm\WmPackage\Models\EcPoi::class,
                \App\Models\EcTrack::class,
                \Wm\WmPackage\Models\EcTrack::class,
            ], true)) {
                return response()->json([
                    'error' => "Modello '{$validatedData['model']}' non consentito.",
                ], 400);
            }

            // Creo un'istanza del modello per ottenere il nome della relazione
            $model = new $validatedData['model'];

            if (! method_exists($model, 'getLayerRelationName')) {
                return response()->json([
                    'error' => "Il modello '{$validatedData['model']}' non implementa l'interfaccia LayerRelatedModel.",
                ], 400);
            }

            // Funzione helper per caricare le features associate
            $getAssociatedFeatures = function () use ($model, $layerId, $search, $layerOwnerId) {
                $query = $model->newQuery();
                $query->whereHas('associatedLayers', function ($q) use ($layerId) {
                    $q->where('layer_id', $layerId);
                });

                $query->where('user_id', $layerOwnerId);

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

                // Filtra per proprietario del layer (nessuna eccezione di ruolo)
                $otherQuery->where('user_id', $layerOwnerId);

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
                $features = new LengthAwarePaginator(
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
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (\Illuminate\Validation\ValidationException|\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            throw $e;
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

    public function sync(Request $request, $layerId): JsonResponse
    {
        try {
            $layer = Layer::findOrFail($layerId);
            $layerOwnerId = $layer->user_id ?? config('camminiditalia.default_owner_id');

            /** @var \Wm\WmPackage\Models\User|null $user */
            $user = Auth::user();

            if (! $user || ($layer->user_id !== $user->id && ! $user->hasRole('Administrator'))) {
                abort(403);
            }

            $validatedData = $request->validate([
                'features' => 'array',
                'model' => 'required|string',
                'auto' => 'boolean',
            ]);

            if (! in_array($validatedData['model'], [
                \App\Models\EcPoi::class,
                \Wm\WmPackage\Models\EcPoi::class,
                \App\Models\EcTrack::class,
                \Wm\WmPackage\Models\EcTrack::class,
            ], true)) {
                return response()->json([
                    'error' => "Modello '{$validatedData['model']}' non consentito.",
                ], 400);
            }

            $model = new $validatedData['model'];

            if (! method_exists($model, 'getLayerRelationName')) {
                return response()->json([
                    'error' => "Il modello '{$validatedData['model']}' non implementa l'interfaccia LayerRelatedModel.",
                ], 400);
            }

            $relationName = $model->getLayerRelationName();

            if (! method_exists($layer, $relationName)) {
                return response()->json([
                    'error' => "La relazione '{$relationName}' non esiste nel modello Layer.",
                ], 400);
            }

            $isAutoRequest = ! empty($validatedData['auto']) && in_array($relationName, ['ecTracks', 'ecPois']);

            if ($isAutoRequest) {
                $ownedIds = $model->newQuery()->where('user_id', $layerOwnerId)->pluck('id')->toArray();

                $layer->{$relationName}()->sync($ownedIds);
            } else {
                $requestedIds = $validatedData['features'] ?? [];

                $ownedIds = $model->newQuery()->whereIn('id', $requestedIds)->where('user_id', $layerOwnerId)->pluck('id')->toArray();

                $layer->{$relationName}()->sync($ownedIds);
            }

            if ($relationName === 'ecTracks') {
                app(PBFGeneratorService::class)->regeneratePbfsForLayer($layer);
            }

            $tableName = $model->getTable();
            $assignedIds = $layer->{$relationName}()->select($tableName.'.id')->pluck('id')->toArray();

            return response()->json([
                'message' => 'Features sincronizzate con successo',
                'assigned_ids' => $assignedIds,
            ], 200);
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (\Illuminate\Validation\ValidationException|\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('LayerFeatureController::sync error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Errore interno del server: '.$e->getMessage(),
            ], 500);
        }
    }
}

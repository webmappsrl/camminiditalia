<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StageProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Wm\WmPackage\Models\Layer;

/**
 * Progresso del camminatore sulle tappe validate (oc:8676). Tutta la logica
 * sta in StageProgressService: qui solo l'involucro HTTP.
 */
class StageProgressController extends Controller
{
    public function __construct(private readonly StageProgressService $service) {}

    public function progress(Request $request, Layer $layer): JsonResponse
    {
        return response()->json($this->service->progressFor($request->user(), $layer), 200, [], JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * `routes` è un oggetto di primo livello (non un array nudo) per poter
     * aggiungere altri campi senza rompere il contratto (oc:8165).
     */
    public function passport(Request $request): JsonResponse
    {
        return response()->json(['routes' => $this->service->passportFor($request->user())], 200, [], JSON_PRESERVE_ZERO_FRACTION);
    }
}

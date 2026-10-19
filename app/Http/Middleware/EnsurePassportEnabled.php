<?php

namespace App\Http\Middleware;

use App\Support\PassportLayer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Wm\WmPackage\Models\Layer;

/**
 * Risponde 404 sulle route `api/layer/{layer}/...` del passaporto quando il
 * cammino non esiste o non ha il passaporto acceso (oc:8763). Il parametro
 * `layer` può essere già il modello o ancora l'id, a seconda dell'ordine
 * rispetto a SubstituteBindings: si gestiscono entrambi.
 */
class EnsurePassportEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $layer = $request->route('layer');

        if (! $layer instanceof Layer) {
            $layer = Layer::find($layer);
        }

        abort_unless($layer instanceof Layer && PassportLayer::isEnabled($layer), 404);

        return $next($request);
    }
}

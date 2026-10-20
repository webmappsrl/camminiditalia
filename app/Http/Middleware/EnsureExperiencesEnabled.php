<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Interruttore delle API delle esperienze (oc:8169): con
 * `camminiditalia.experiences.enabled` a false le route `api/experiences`
 * rispondono 404, prima ancora dell'autenticazione.
 */
class EnsureExperiencesEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('camminiditalia.experiences.enabled', true), 404);

        return $next($request);
    }
}

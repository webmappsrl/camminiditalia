<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Interruttore delle API delle esperienze (oc:8169): con
 * `camminiditalia.diaries.enabled` a false le route `api/diaries`
 * rispondono 404, prima ancora dell'autenticazione.
 */
class EnsureDiariesEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('camminiditalia.diaries.enabled', true), 404);

        return $next($request);
    }
}

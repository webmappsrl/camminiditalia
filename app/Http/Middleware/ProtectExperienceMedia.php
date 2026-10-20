<?php

namespace App\Http\Middleware;

use App\Models\Experience;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Wm\WmPackage\Models\Media;

/**
 * Foto delle esperienze (oc:8169) sulle API dei media di wm-package, che
 * cercano qualsiasi media per id senza guardare a chi appartiene:
 * `api/media/{media}` e `api/ec/media/{id}` (anche `image/{id}`) non chiedono
 * autenticazione, `api/ugc/media/delete/{media}` cancella anche i media
 * altrui. Per le foto di un'esperienza: lettura all'autore o a tutti se
 * l'esperienza è pubblica, cancellazione solo all'autore; altrimenti 404.
 * I media che non sono di un'esperienza passano invariati.
 */
class ProtectExperienceMedia
{
    public function handle(Request $request, Closure $next): Response
    {
        $media = $this->experienceMedia($request);
        if ($media === null) {
            return $next($request);
        }

        // Le route di lettura non passano da `auth:api`: l'utente si ricava dal token, se c'è.
        $user = auth('api')->user();
        $experience = $media->model;
        $isAuthor = $user !== null && $experience !== null && $experience->user_id === $user->getAuthIdentifier();
        $canRead = $isAuthor || ($experience !== null && $experience->is_public);

        abort_unless($request->routeIs('*.ugc.media.destroy*') ? $isAuthor : $canRead, 404);

        return $next($request);
    }

    /**
     * Media della richiesta se appartiene a un'esperienza, altrimenti null.
     * L'id sta nel parametro `media` (binding di wm-package, già risolto o
     * no) oppure in `id` per le route `ec/media`.
     */
    private function experienceMedia(Request $request): ?Media
    {
        $route = $request->route();
        if ($route === null) {
            return null;
        }

        $param = $route->parameter('media');
        if ($param === null && str_contains($route->uri(), 'ec/media')) {
            $param = $route->parameter('id');
        }
        $id = $param instanceof Model ? $param->getKey() : $param;
        if (! is_numeric($id)) {
            return null;
        }

        return Media::query()
            ->whereKey((int) $id)
            ->where('model_type', (new Experience)->getMorphClass())
            ->first();
    }
}

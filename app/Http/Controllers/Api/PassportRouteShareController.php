<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PassportShare;
use App\Services\PassportShare\PassportShareStore;
use App\Services\PassportShare\RouteShareImageService;
use App\Services\PassportShare\StageShareLocale;
use App\Services\StageProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;
use Wm\WmPackage\Models\Layer;

/**
 * Immagine di condivisione di un cammino completato e link alla pagina
 * pubblica (oc:8703), sullo schema della tappa (PassportStageShareController,
 * oc:8702). `POST /api/layer/{layer}/share-image`: verifica che il cammino sia
 * completato per l'utente, compone l'immagine, la salva (collection
 * `share_image`) insieme allo snapshot e restituisce `{image_url, share_url}`.
 * Se l'impronta dei dati non è cambiata riusa l'immagine già salvata.
 */
class PassportRouteShareController extends Controller
{
    /**
     * Servizi iniettati dal container.
     */
    public function __construct(
        private readonly StageProgressService $progressService,
        private readonly RouteShareImageService $imageService,
        private readonly PassportShareStore $store,
    ) {}

    /**
     * Genera (o riusa dalla cache) l'immagine del cammino `$layer` completato
     * dall'utente autenticato, nella lingua dell'header Accept-Language.
     *
     * Risposte: 200 `{image_url, share_url}`; 403 se il cammino non è
     * completato (stesso criterio di `completed` in `/progress`, calcolato e
     * non salvato); 500 se la composizione fallisce (errore nel log).
     */
    public function store(Request $request, Layer $layer): JsonResponse
    {
        $user = $request->user();

        if (($this->progressService->progressFor($user, $layer)['completed'] ?? false) !== true) {
            return response()->json(['error' => 'Il cammino non è completato per questo utente.'], 403);
        }

        $lang = StageShareLocale::fromAcceptLanguage($request->header('Accept-Language'));

        try {
            $share = PassportShare::forUser($user, $layer, $layer);
            // lo snapshot serve già all'impronta: contiene i dati dell'utente disegnati nell'immagine
            $snapshot = $this->imageService->snapshot($user, $layer, $lang);
            $media = $this->store->save(
                $share,
                $this->fingerprint($layer, $lang, $snapshot),
                $lang,
                'passport-route',
                fn () => $snapshot,
                fn (array $data) => $this->imageService->compose($layer, $lang, $data),
            );
        } catch (Throwable $e) {
            Log::error('[oc:8703] generazione immagine di condivisione del cammino fallita: '.$e->getMessage(), [
                'layer_id' => $layer->id,
                'user_id' => $user->id,
                'exception' => get_class($e),
            ]);

            return response()->json(['error' => 'Errore interno nella generazione dell\'immagine.'], 500);
        }

        return response()->json([
            'image_url' => $media->getUrl(),
            'share_url' => route('share.passport', ['uuid' => $share->uuid]),
        ]);
    }

    /**
     * Impronta dei dati da cui dipende l'immagine, come per la tappa senza la
     * tappa condivisa: cammino (id e ultimo aggiornamento), tutte le tappe
     * (numero e ultimo aggiornamento), loghi del layer e dell'App, lingua,
     * firma del layout del cammino e i dati dell'utente che finiscono
     * nell'immagine (data della validazione più recente, tappe, km, uscite).
     *
     * @param  array{completed_at: ?string, stages_validated: int, stages_total: int, distance_km: ?float, outings: ?int}  $snapshot
     */
    private function fingerprint(Layer $layer, string $lang, array $snapshot): string
    {
        $parts = $this->store->fingerprintParts($layer);

        return hash('sha256', json_encode([
            'layer' => $parts['layer'],
            'route' => $parts['route'],
            'media' => $parts['media'],
            'lang' => $lang,
            'layout' => RouteShareImageService::signature(),
            'user' => [$snapshot['completed_at'], $snapshot['stages_validated'], $snapshot['stages_total'], $snapshot['distance_km'], $snapshot['outings'] ?? null],
        ]));
    }
}

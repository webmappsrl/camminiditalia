<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PassportStageShare;
use App\Models\ValidatedEcTrack;
use App\Services\PassportShare\StageShareImageService;
use App\Services\PassportShare\StageShareLayout;
use App\Services\PassportShare\StageShareLocale;
use App\Services\StageProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;

/**
 * Immagine di condivisione di una tappa percorsa e link alla pagina pubblica
 * (oc:8702). `POST /api/layer/{layer}/stage/{track}/share-image`: verifica che
 * la tappa sia del cammino e validata per l'utente, compone l'immagine,
 * la salva (collection `share_image`) insieme allo snapshot dei dati e
 * restituisce `{image_url, share_url}`. Se l'impronta dei dati (cammino,
 * tappa, percorso, loghi, lingua, layout) non è cambiata dall'ultima volta,
 * restituisce l'immagine già salvata senza ricomporla.
 */
class PassportStageShareController extends Controller
{
    /**
     * Servizi iniettati dal container.
     */
    public function __construct(
        private readonly StageProgressService $progressService,
        private readonly StageShareImageService $imageService,
    ) {}

    /**
     * Genera (o riusa dalla cache) l'immagine di condivisione della tappa
     * `$track` del cammino `$layer` per l'utente autenticato, nella lingua
     * dell'header Accept-Language.
     *
     * Risposte: 200 `{image_url, share_url}`; 404 se la tappa non è del
     * cammino; 403 se non è validata per l'utente; 500 se la composizione
     * fallisce (errore registrato nel log). Con la cache valida aggiorna solo
     * `snapshot.shared_at`, così la pagina pubblica mostra la data
     * dell'ultima condivisione.
     */
    public function store(Request $request, Layer $layer, EcTrack $track): JsonResponse
    {
        $user = $request->user();

        $inLayer = $this->progressService->layerTracksQuery($layer->id)
            ->where('ec_tracks.id', $track->id)
            ->exists();

        if (! $inLayer) {
            return response()->json(['error' => 'La tappa non appartiene a questo cammino.'], 404);
        }

        $validated = DB::table('validated_ec_tracks as v')
            ->where('v.user_id', $user->id)
            ->where('v.ec_track_id', $track->id)
            ->whereRaw(ValidatedEcTrack::validatedSql('v'))
            ->exists();

        if (! $validated) {
            return response()->json(['error' => 'La tappa non è validata per questo utente.'], 403);
        }

        $lang = StageShareLocale::fromAcceptLanguage($request->header('Accept-Language'));

        try {
            $share = PassportStageShare::forUserAndTrack($user, $layer, $track);
            $fingerprint = $this->fingerprint($layer, $track, $lang);
            $media = $share->getFirstMedia(PassportStageShare::MEDIA_COLLECTION);

            // Stessi dati di cammino e tappa, stessa lingua, stesso layout:
            // l'immagine già salvata va bene, non si ricompone.
            if ($media === null || ($share->snapshot['fingerprint'] ?? null) !== $fingerprint) {
                $snapshot = $this->imageService->snapshot($layer, $track, $lang);
                $image = $this->imageService->compose($layer, $track, $lang, $snapshot);
                $media = $share->addMediaFromString($image->getEncoded())
                    ->usingFileName('passport-stage-'.$share->uuid.'.png')
                    ->toMediaCollection(PassportStageShare::MEDIA_COLLECTION);

                // Istantanea statica: la pagina pubblica non ricalcola nulla.
                $share->snapshot = $snapshot + [
                    'lang' => $lang,
                    'shared_at' => now()->toIso8601String(),
                    'fingerprint' => $fingerprint,
                ];
                $share->save();
            } else {
                // Immagine riusata: cambia solo la data dell'ultima condivisione.
                $share->snapshot = ['shared_at' => now()->toIso8601String()] + $share->snapshot;
                $share->save();
            }
        } catch (Throwable $e) {
            Log::error('[oc:8702] generazione immagine di condivisione della tappa fallita: '.$e->getMessage(), [
                'layer_id' => $layer->id,
                'ec_track_id' => $track->id,
                'user_id' => $user->id,
                'exception' => get_class($e),
            ]);

            return response()->json(['error' => 'Errore interno nella generazione dell\'immagine.'], 500);
        }

        return response()->json([
            'image_url' => $media->getUrl(),
            'share_url' => route('share.passport-stage', ['uuid' => $share->uuid]),
        ]);
    }

    /**
     * Impronta dei dati da cui dipende l'immagine. Se non cambia, l'immagine
     * salvata è ancora valida. Comprende:
     * - cammino e tappa: id e ultimo aggiornamento;
     * - il percorso del cammino, cioè le altre tappe disegnate in rosso:
     *   numero di tappe e ultimo aggiornamento fra tutte (una query aggregata);
     * - i loghi: media `logo` del layer e `icon` / `icon_small` dell'App,
     *   con id e ultimo aggiornamento (una query sulla tabella `media`);
     * - lingua e firma del layout (StageShareLayout::signature(): costanti,
     *   contenuto di font, sfondo e traduzioni, VERSION).
     * I valori di data sono quelli grezzi delle colonne: Layer non sempre
     * restituisce `updated_at` come Carbon.
     */
    private function fingerprint(Layer $layer, EcTrack $track, string $lang): string
    {
        $route = DB::query()
            ->fromSub($this->progressService->layerTracksQuery($layer->id), 'lt')
            ->join('ec_tracks as t', 't.id', '=', 'lt.ec_track_id')
            ->selectRaw('count(*) as tracks, max(t.updated_at) as last_update')
            ->first();

        $media = DB::table('media')
            ->where(fn ($query) => $query
                ->where('model_type', $layer->getMorphClass())
                ->where('model_id', $layer->id)
                ->where('collection_name', 'logo'))
            ->orWhere(fn ($query) => $query
                ->where('model_type', (new App)->getMorphClass())
                ->where('model_id', $layer->app_id)
                ->whereIn('collection_name', StageShareLayout::CDI_LOGO_COLLECTIONS))
            ->orderBy('id')
            ->get(['id', 'collection_name', 'updated_at'])
            ->map(fn ($row) => [$row->collection_name, $row->id, (string) $row->updated_at])
            ->all();

        return hash('sha256', json_encode([
            'layer' => [$layer->id, (string) $layer->getRawOriginal('updated_at')],
            'track' => [$track->id, (string) $track->getRawOriginal('updated_at')],
            'route' => [(int) ($route->tracks ?? 0), (string) ($route->last_update ?? '')],
            'media' => $media,
            'lang' => $lang,
            'layout' => StageShareLayout::signature(),
        ]));
    }
}

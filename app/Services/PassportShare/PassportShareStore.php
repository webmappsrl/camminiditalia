<?php

namespace App\Services\PassportShare;

use App\Models\PassportShare;
use App\Services\StageProgressService;
use Illuminate\Support\Facades\DB;
use Intervention\Image\Image as InterventionImage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;

/**
 * Salvataggio delle immagini di condivisione del passaporto con la loro cache
 * (oc:8703): lo stesso flusso per la tappa (oc:8702) e per il cammino
 * completato. Se l'impronta dei dati non è cambiata riusa l'immagine già
 * salvata e aggiorna solo la data dell'ultima condivisione; altrimenti
 * compone, salva e scrive lo snapshot che la pagina pubblica mostra.
 */
class PassportShareStore
{
    /**
     * Dipendenze iniettate dal container.
     */
    public function __construct(
        private readonly StageProgressService $progressService,
    ) {}

    /**
     * Immagine della condivisione `$share`, riusata o ricomposta.
     *
     * @param  callable(): array<string, mixed>  $snapshot  Dati dello snapshot, calcolati solo se serve ricomporre.
     * @param  callable(array<string, mixed>): InterventionImage  $compose  Composizione dell'immagine dallo snapshot.
     */
    public function save(PassportShare $share, string $fingerprint, string $lang, string $filePrefix, callable $snapshot, callable $compose): Media
    {
        $media = $share->getFirstMedia(PassportShare::MEDIA_COLLECTION);

        // Stessi dati, stessa lingua, stesso layout: l'immagine già salvata va
        // bene, cambia solo la data dell'ultima condivisione.
        if ($media !== null && ($share->snapshot['fingerprint'] ?? null) === $fingerprint) {
            $share->snapshot = ['shared_at' => now()->toIso8601String()] + $share->snapshot;
            $share->save();

            return $media;
        }

        $data = $snapshot();
        $media = $share->addMediaFromString($compose($data)->getEncoded())
            ->usingFileName($filePrefix.'-'.$share->uuid.'.png')
            ->toMediaCollection(PassportShare::MEDIA_COLLECTION);

        // Istantanea statica: la pagina pubblica non ricalcola nulla.
        $share->snapshot = $data + [
            'lang' => $lang,
            'shared_at' => now()->toIso8601String(),
            'fingerprint' => $fingerprint,
        ];
        $share->save();

        return $media;
    }

    /**
     * Parti dell'impronta comuni a tappa e cammino:
     * - `layer`: id e ultimo aggiornamento del cammino (il valore grezzo della
     *   colonna: Layer non sempre restituisce `updated_at` come Carbon);
     * - `route`: numero di tappe del cammino e ultimo aggiornamento fra tutte
     *   (una query aggregata);
     * - `media`: logo del layer e `icon` / `icon_small` dell'App, con id e
     *   ultimo aggiornamento (una query sulla tabella `media`).
     *
     * @return array{layer: array{0: int, 1: string}, route: array{0: int, 1: string}, media: list<array{0: string, 1: int, 2: string}>}
     */
    public function fingerprintParts(Layer $layer): array
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

        return [
            'layer' => [$layer->id, (string) $layer->getRawOriginal('updated_at')],
            'route' => [(int) ($route->tracks ?? 0), (string) ($route->last_update ?? '')],
            'media' => $media,
        ];
    }
}

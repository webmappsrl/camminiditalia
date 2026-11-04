<?php

namespace App\Services;

use App\Jobs\LinkDiaryUgcTracksJob;
use App\Models\Diary;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Image\Enums\ImageDriver;
use Spatie\Image\Image;
use Throwable;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\Models\UgcMediaHashService;

/**
 * Creazione delle esperienze dei camminatori (oc:8169).
 */
class DiaryService
{
    /**
     * Crea l'esperienza, o la riprende se l'utente l'ha già creata con lo
     * stesso `uuid` (retry dell'app): in quel caso aggiorna il testo, se
     * inviato, e aggiunge solo le foto mancanti. Se `layer_id` manca lo
     * ricava dalla tappa.
     *
     * @param  array{uuid: string, layer_id?: int|string|null, ec_track_id?: int|string|null, description?: ?string, publication_consent?: bool|int|string|null, rating_route?: int|string|null, rating_hospitality?: int|string|null, rating_difficulty?: int|string|null}  $data
     * @param  UploadedFile[]  $images
     */
    public function create(User $user, array $data, array $images): Diary
    {
        $ecTrackId = ! empty($data['ec_track_id']) ? (int) $data['ec_track_id'] : null;
        $layerId = ! empty($data['layer_id']) ? (int) $data['layer_id'] : ($ecTrackId !== null ? $this->resolveLayerId($ecTrackId) : null);
        if ($layerId === null) {
            throw ValidationException::withMessages(['ec_track_id' => [__('The stage does not belong to any route.')]]);
        }
        $description = isset($data['description']) ? trim((string) $data['description']) : null;

        // createOrFirst: insert e, se scatta il vincolo unico (invii concorrenti dello stesso uuid), rilettura della riga esistente.
        $diary = Diary::createOrFirst(
            ['user_id' => $user->id, 'uuid' => $data['uuid']],
            [
                'layer_id' => $layerId,
                'ec_track_id' => $ecTrackId,
                'description' => $description !== '' ? $description : null,
                'publication_consent' => (bool) ($data['publication_consent'] ?? false),
            ] + $this->extraFields($data),
        );

        // Retry di un invio la cui risposta è andata persa: se l'utente nel frattempo ha corretto il
        // testo, vale quello nuovo, altrimenti la correzione si perderebbe senza errore.
        if (! $diary->wasRecentlyCreated && array_key_exists('description', $data)) {
            $diary->update(['description' => $description !== '' ? $description : null]);
        }
        if (! $diary->wasRecentlyCreated && array_key_exists('publication_consent', $data)) {
            $diary->update(['publication_consent' => (bool) $data['publication_consent']]);
        }
        if (! $diary->wasRecentlyCreated && $this->extraFields($data) !== []) {
            $diary->update($this->extraFields($data));
        }

        try {
            $this->storeImages($diary, $images);
        } catch (Throwable $e) {
            // Esperienza appena creata e foto non salvata (non elaborabile o errore di storage):
            // non resta una riga senza le foto inviate, che l'app mostrerebbe vuota.
            if ($diary->wasRecentlyCreated) {
                $diary->delete();
            }

            throw $e;
        }

        LinkDiaryUgcTracksJob::dispatch($diary->id);

        return $diary;
    }

    /**
     * Aggiorna testo e foto: le foto fuori da `$keepMediaIds` sono rimosse
     * (file compresi), le nuove aggiunte. Le nuove si salvano prima delle
     * rimozioni: se una non è elaborabile (422) l'esperienza resta intatta.
     *
     * @param  int[]  $keepMediaIds
     * @param  UploadedFile[]  $images
     * @param  ?bool  $publicationConsent  null lascia invariato il consenso
     * @param  array<string, int|string|null>  $ratings  solo le valutazioni e le date di percorrenza inviate; null le azzera
     */
    public function update(Diary $diary, ?string $description, array $keepMediaIds, array $images, ?bool $publicationConsent = null, array $ratings = []): Diary
    {
        $toRemove = $diary->getMedia(Diary::MEDIA_COLLECTION)
            ->reject(fn ($media) => in_array((int) $media->id, $keepMediaIds, true));

        // La deduplica guarda solo le foto che restano: una foto tolta e ricaricata deve esserci alla fine.
        $before = $diary->getMedia(Diary::MEDIA_COLLECTION)->pluck('id')->all();
        try {
            $this->storeImages($diary, $images, $toRemove->pluck('id')->map(fn ($id) => (int) $id)->all());
        } catch (ValidationException $e) {
            // Foto già salvate in questa richiesta: si tolgono, l'esperienza resta come prima.
            $diary->unsetRelation('media');
            foreach ($diary->getMedia(Diary::MEDIA_COLLECTION) as $media) {
                if (! in_array($media->id, $before, true)) {
                    $media->delete();
                }
            }
            $diary->unsetRelation('media');

            throw $e;
        }

        foreach ($toRemove as $media) {
            $media->delete();
        }

        $description = $description !== null ? trim($description) : null;
        $attributes = ['description' => $description !== '' ? $description : null];
        if ($publicationConsent !== null) {
            $attributes['publication_consent'] = $publicationConsent;
        }
        $attributes += $this->extraFields($ratings);
        $diary->update($attributes);
        $diary->unsetRelation('media');

        LinkDiaryUgcTracksJob::dispatch($diary->id);

        return $diary;
    }

    /**
     * Cammino di una tappa che non ne indica uno: fra i cammini che la
     * contengono (`layerables`) si sceglie quello con `layer_id` più basso,
     * così il risultato è deterministico.
     */
    public function resolveLayerId(int $ecTrackId): ?int
    {
        $id = Layer::query()
            ->whereHas('ecTracks', fn ($q) => $q->where('ec_tracks.id', $ecTrackId))
            ->min('layers.id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Salva le foto nella collection dell'esperienza: orientamento EXIF
     * applicato e metadati (GPS compreso) rimossi; le immagini già presenti,
     * riconosciute dall'hash del contenuto originale, sono scartate. Se le
     * foto che restano più le nuove superano Diary::MAX_PHOTOS non si
     * salva nulla (422): vale anche per il retry di creazione con uno `uuid`
     * già esistente, dove la request vede solo le foto della richiesta.
     *
     * @param  UploadedFile[]  $images
     * @param  int[]  $ignoreMediaIds  media in uscita: non contano nella deduplica né nel limite
     */
    public function storeImages(Diary $diary, array $images, array $ignoreMediaIds = []): void
    {
        $hashes = UgcMediaHashService::make();
        $seen = [];
        $toStore = [];

        foreach ($images as $file) {
            $hash = $hashes->hashOfFile($file->getRealPath());
            // Doppioni nella stessa richiesta: la cache dei media del modello non li vede.
            if (isset($seen[$hash]) || $this->hasMediaWithHash($diary, $hash, $ignoreMediaIds, $hashes)) {
                continue;
            }
            $seen[$hash] = true;
            $toStore[] = [$hash, $file];
        }

        $remaining = $diary->getMedia(Diary::MEDIA_COLLECTION)
            ->reject(fn ($media) => in_array((int) $media->id, $ignoreMediaIds, true))
            ->count();
        if ($remaining + count($toStore) > Diary::MAX_PHOTOS) {
            $diary->unsetRelation('media');

            throw ValidationException::withMessages([
                'images' => [__('A diary can have at most :max photos.', ['max' => Diary::MAX_PHOTOS])],
            ]);
        }

        foreach ($toStore as [$hash, $file]) {
            $clean = $this->stripMetadata($file);
            try {
                $diary->addMedia($clean)
                    ->preservingOriginal()
                    // Nome casuale: niente nomi personali negli URL pubblici; l'estensione segue il formato reale.
                    ->usingFileName(Str::uuid().'.'.$file->guessExtension())
                    ->withCustomProperties([UgcMediaHashService::HASH_PROPERTY => $hash])
                    ->toMediaCollection(Diary::MEDIA_COLLECTION);
            } finally {
                if (is_file($clean)) {
                    @unlink($clean);
                }
            }
        }

        // I media letti da findByContent() restano in cache sul modello: si scartano perché la risposta li rilegga.
        $diary->unsetRelation('media');
    }

    /**
     * Se l'esperienza ha già una foto con quell'hash, esclusi i media in uscita.
     *
     * @param  int[]  $ignoreMediaIds
     */
    private function hasMediaWithHash(Diary $diary, string $hash, array $ignoreMediaIds, UgcMediaHashService $hashes): bool
    {
        foreach ($diary->getMedia(Diary::MEDIA_COLLECTION) as $media) {
            if (! in_array((int) $media->id, $ignoreMediaIds, true) && $hashes->hashOf($media) === $hash) {
                return true;
            }
        }

        return false;
    }

    /**
     * Copia temporanea della foto con orientamento applicato e senza EXIF:
     * spatie/image con GD ruota l'immagine in lettura secondo l'EXIF e la
     * ricodifica scarta i metadati. Formati diversi da JPEG, PNG e WebP:
     * 422, mai l'originale (conserverebbe i metadati).
     */
    private function stripMetadata(UploadedFile $file): string
    {
        $path = $file->getRealPath();
        if (! in_array($file->getMimeType(), ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw ValidationException::withMessages(['images' => [__('The photo could not be processed.')]]);
        }

        // L'estensione decide il formato di salvataggio: quella del formato reale del file.
        $target = tempnam(sys_get_temp_dir(), 'exp_');
        $typed = $target.'.'.$file->guessExtension();
        rename($target, $typed);
        $target = $typed;
        try {
            Image::useImageDriver(ImageDriver::Gd)->loadFile($path)->save($target);
        } catch (Throwable $e) {
            @unlink($target);

            // Mai salvare l'originale: conserverebbe EXIF e posizione GPS.
            throw ValidationException::withMessages(['images' => [__('The photo could not be processed.')]]);
        }

        return $target;
    }

    /**
     * Valutazioni presenti in `$data`, come interi o null (oc:8169, ciclo 2). Una chiave assente
     * non compare: la valutazione resta invariata.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, ?int>
     */
    private function ratings(array $data): array
    {
        $ratings = [];
        foreach (Diary::RATINGS as $key) {
            if (array_key_exists($key, $data)) {
                $ratings[$key] = $data[$key] === null || $data[$key] === '' ? null : (int) $data[$key];
            }
        }

        return $ratings;
    }

    /**
     * Valutazioni e date di percorrenza inviate (oc:8169, cicli 2 e 4): solo le chiavi presenti,
     * vuoto = azzera.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, int|string|null>
     */
    private function extraFields(array $data): array
    {
        $fields = $this->ratings($data);
        foreach (Diary::WALK_DATES as $key) {
            if (array_key_exists($key, $data)) {
                $fields[$key] = $data[$key] === null || $data[$key] === '' ? null : (string) $data[$key];
            }
        }

        return $fields;
    }
}

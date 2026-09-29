<?php

namespace App\Services;

use App\Exceptions\PendingCertificationRequestExistsException;
use App\Jobs\SendCertificationRequestMailJob;
use App\Models\CertificationRequest;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\User;

class CertificationRequestService
{
    /**
     * Invia una nuova richiesta di certificazione: pre-check di unicità,
     * creazione transazionale della richiesta, poi upload delle foto nella
     * media library (collection MEDIA_COLLECTION, come per gli UGC). Se un upload
     * fallisce la richiesta viene cancellata, e con lei i media già creati
     * e i relativi file (InteractsWithMedia).
     *
     * Solo dopo il successo dispatcha SendCertificationRequestMailJob per
     * notificare via email il gestore del layer (o l'indirizzo di fallback
     * se il layer non ha owner).
     *
     * @param  UploadedFile[]  $images
     *
     * @throws PendingCertificationRequestExistsException se esiste già una richiesta pending per (user, layer)
     */
    public function submit(User $user, Layer $layer, array $images, ?string $serialNumber): CertificationRequest
    {
        if ($this->hasPending($user, $layer)) {
            throw new PendingCertificationRequestExistsException;
        }

        try {
            $request = DB::transaction(fn () => CertificationRequest::create([
                'user_id' => $user->id,
                'layer_id' => $layer->id,
                'app_id' => $layer->app_id,
                'status' => CertificationRequest::STATUS_PENDING,
                'serial_number' => $serialNumber,
                'disclaimer_accepted_at' => now(),
            ]));
        } catch (UniqueConstraintViolationException $e) {
            throw new PendingCertificationRequestExistsException(previous: $e);
        }

        try {
            foreach ($images as $image) {
                $request->addMedia($image)->toMediaCollection(CertificationRequest::MEDIA_COLLECTION);
            }
        } catch (Throwable $e) {
            try {
                $request->delete();
            } catch (Throwable $cleanupError) {
                Log::warning('Impossibile rimuovere la CertificationRequest dopo un upload fallito', [
                    'certification_request_id' => $request->id,
                    'exception' => $cleanupError->getMessage(),
                ]);
            }

            throw $e;
        }

        SendCertificationRequestMailJob::dispatch($request)->afterCommit();

        return $request;
    }

    /**
     * @phpstan-impure Legge dal DB: due chiamate consecutive possono dare risultati diversi.
     */
    public function currentFor(User $user, Layer $layer): ?CertificationRequest
    {
        return CertificationRequest::pendingFor($user, $layer)->latest('id')->first();
    }

    private function hasPending(User $user, Layer $layer): bool
    {
        return CertificationRequest::pendingFor($user, $layer)->exists();
    }
}

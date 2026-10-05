<?php

namespace App\Services;

use App\Exceptions\CertificationDecisionException;
use App\Exceptions\PendingCertificationRequestExistsException;
use App\Jobs\SendCertificationDecisionMailJob;
use App\Jobs\SendCertificationRequestMailJob;
use App\Models\CertificationRequest;
use App\Models\ValidatedEcTrack;
use App\Support\EcTrackLabel;
use App\Support\UserDisplay;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\User;

class CertificationRequestService
{
    public const OUTCOME_APPROVE = 'approve';

    public const OUTCOME_REJECT = 'reject';

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
    public function submit(
        User $user,
        Layer $layer,
        array $images,
        ?string $serialNumber,
        string $locale = CertificationRequest::DEFAULT_LOCALE,
    ): CertificationRequest {
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
                'locale' => $locale,
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
    public function latestFor(User $user, Layer $layer): ?CertificationRequest
    {
        return CertificationRequest::where('user_id', $user->id)
            ->where('layer_id', $layer->id)
            ->latest('id')
            ->first();
    }

    /**
     * Decide in modo irreversibile una richiesta pending (oc:8671).
     *
     * In transazione, con la richiesta riletta sotto lock: così due decisioni
     * contemporanee sulla stessa richiesta non possono passare entrambe il
     * controllo `pending`. Approva scrive una riga in validated_ec_tracks per
     * ogni tappa; Rifiuta ignora tappe e selectAll. La mail al camminatore parte
     * solo dopo il commit.
     *
     * @param  array<int, int|string>  $ecTrackIds
     *
     * @throws CertificationDecisionException
     */
    public function decide(
        CertificationRequest $request,
        User $decider,
        string $outcome,
        array $ecTrackIds,
        bool $selectAll,
        ?string $note,
    ): CertificationRequest {
        $decided = DB::transaction(function () use ($request, $decider, $outcome, $ecTrackIds, $selectAll, $note) {
            /** @var CertificationRequest $locked */
            $locked = CertificationRequest::whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            $tracks = $this->resolveDecisionTracks($locked, $outcome, $ecTrackIds, $selectAll);
            $now = now();

            try {
                foreach ($tracks as $track) {
                    // oc:8165: qui va gestita la promozione di una riga parziale esistente (vincolo unico user_id, ec_track_id).
                    ValidatedEcTrack::create([
                        'user_id' => $locked->user_id,
                        'ec_track_id' => $track->id,
                        'layer_id' => $locked->layer_id,
                        'certification_request_id' => $locked->id,
                        'source' => ValidatedEcTrack::SOURCE_MANUAL,
                        'validated_at' => $now,
                    ]);
                }
            } catch (UniqueConstraintViolationException $e) {
                throw new CertificationDecisionException(__('Some selected stages cannot be validated for this request.'), previous: $e);
            }

            $locked->forceFill([
                'status' => $outcome === self::OUTCOME_APPROVE
                    ? CertificationRequest::STATUS_APPROVED
                    : CertificationRequest::STATUS_REJECTED,
                'decided_at' => $now,
                'decided_by' => $decider->id,
                'decision_note' => $this->normalizeNote($note),
            ])->save();

            return $locked;
        });

        SendCertificationDecisionMailJob::dispatch($decided)->afterCommit();

        return $decided;
    }

    /**
     * Riepilogo della decisione senza scrivere nulla (primo passaggio
     * dell'azione Nova): stesse regole di decide(), così il secondo modale
     * mostra esattamente ciò che la conferma scriverà.
     *
     * @param  array<int, int|string>  $ecTrackIds
     * @return array{outcome: string, ec_track_ids: array<int, int>, track_labels: array<int, string>, user: string, route: string, note: ?string}
     *
     * @throws CertificationDecisionException
     */
    public function previewDecision(CertificationRequest $request, string $outcome, array $ecTrackIds, bool $selectAll, ?string $note): array
    {
        $tracks = $this->resolveDecisionTracks($request, $outcome, $ecTrackIds, $selectAll);

        return [
            'outcome' => $outcome,
            'ec_track_ids' => $tracks->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'track_labels' => $tracks->map(fn (EcTrack $track) => EcTrackLabel::for($track))->all(),
            'user' => UserDisplay::withEmail($request->user),
            'route' => $request->layer?->getStringName() ?? '—',
            'note' => $this->normalizeNote($note),
        ];
    }

    /**
     * Regole di validità comuni a decide() e al riepilogo prima della conferma.
     *
     * @param  array<int, int|string>  $ecTrackIds
     * @return Collection<int, EcTrack> tappe da validare (vuota per Rifiuta)
     *
     * @throws CertificationDecisionException
     */
    private function resolveDecisionTracks(CertificationRequest $request, string $outcome, array $ecTrackIds, bool $selectAll): Collection
    {
        if (! $request->isPending()) {
            throw new CertificationDecisionException(__('This request has already been decided.'));
        }

        if (! in_array($outcome, [self::OUTCOME_APPROVE, self::OUTCOME_REJECT], true)) {
            throw new CertificationDecisionException(__('Choose whether to approve or reject the request.'));
        }

        if ($outcome === self::OUTCOME_REJECT) {
            return collect();
        }

        $selectable = $this->selectableTracks($request);

        if ($selectAll) {
            $chosen = $selectable;
        } else {
            $ids = array_values(array_unique(array_map('intval', $ecTrackIds)));
            $chosen = $selectable->whereIn('id', $ids)->values();

            if ($chosen->count() !== count($ids)) {
                throw new CertificationDecisionException(__('Some selected stages cannot be validated for this request.'));
            }
        }

        if ($chosen->isEmpty()) {
            throw new CertificationDecisionException(__('Select at least one stage to approve.'));
        }

        return $chosen;
    }

    /**
     * Tappe che il gestore può ancora validare per questa richiesta: le EcTrack
     * del layer di proprietà del proprietario effettivo del layer
     * (StageProgressService::managedTracksQuery(), «tappe del gestore»), escluse quelle già validate per il camminatore.
     * Ordinate per nome con ordinamento naturale.
     *
     * @return Collection<int, EcTrack>
     */
    public function selectableTracks(CertificationRequest $request): Collection
    {
        return $this->tracksForDecision($request)['selectable'];
    }

    /**
     * @return Collection<int, EcTrack>
     */
    public function alreadyValidatedTracks(CertificationRequest $request): Collection
    {
        return $this->tracksForDecision($request)['validated'];
    }

    /**
     * Tappe del layer divise in selezionabili e già validate, con una sola
     * lettura delle tappe e delle validazioni (il modale le usa entrambe).
     *
     * @return array{selectable: Collection<int, EcTrack>, validated: Collection<int, EcTrack>}
     */
    public function tracksForDecision(CertificationRequest $request): array
    {
        $validatedIds = $this->validatedTrackIds($request);

        [$validated, $selectable] = $this->ownedLayerTracks($request)
            ->partition(fn (EcTrack $track) => in_array($track->id, $validatedIds, true));

        return [
            'selectable' => EcTrackLabel::sortNatural($selectable),
            'validated' => EcTrackLabel::sortNatural($validated),
        ];
    }

    /**
     * @return Collection<int, EcTrack>
     */
    private function ownedLayerTracks(CertificationRequest $request): Collection
    {
        $layer = $request->layer;

        if ($layer === null) {
            return collect();
        }

        // Regola «tappe del gestore» da un punto solo (StageProgressService).
        $managedIds = app(StageProgressService::class)->managedTracksQuery()
            ->where('layerables.layer_id', $layer->id)
            ->select('ec_tracks.id');

        // Solo le colonne che servono a etichetta e controlli: geometria e
        // properties pesano decine di kB a tappa e i layer arrivano a 99 tappe.
        /** @var Collection<int, EcTrack> */
        return $layer->ecTracks()
            ->whereIn('ec_tracks.id', $managedIds)
            ->get(['ec_tracks.id', 'ec_tracks.name', 'ec_tracks.user_id']);
    }

    /**
     * @return array<int, int>
     */
    private function validatedTrackIds(CertificationRequest $request): array
    {
        return ValidatedEcTrack::validated()->where('user_id', $request->user_id)
            ->pluck('ec_track_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function normalizeNote(?string $note): ?string
    {
        $note = trim((string) $note);

        return $note !== '' ? $note : null;
    }

    private function hasPending(User $user, Layer $layer): bool
    {
        return CertificationRequest::pendingFor($user, $layer)->exists();
    }
}

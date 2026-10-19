<?php

namespace App\Http\Controllers\Nova;

use App\Exceptions\CertificationDecisionException;
use App\Http\Controllers\Controller;
use App\Models\CertificationRequest;
use App\Services\CertificationRequestService;
use App\Support\UserDisplay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Secondo passaggio dell'azione "Decidi richiesta" (oc:8671): esegue la
 * decisione confermata nel modale di riepilogo. È un endpoint a sé, quindi
 * rifà autorizzazione e validazione senza fidarsi del primo passaggio.
 */
class CertificationDecisionController extends Controller
{
    public function __construct(private readonly CertificationRequestService $service) {}

    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate([
            'certification_request_id' => ['required', 'integer'],
            'outcome' => ['required', 'string'],
            'ec_track_ids' => ['array'],
            'ec_track_ids.*' => ['integer'],
            'decision_note' => ['nullable', 'string', 'max:'.CertificationRequest::DECISION_NOTE_MAX_LENGTH],
        ]);

        $certificationRequest = CertificationRequest::findOrFail($data['certification_request_id']);
        $user = $request->user();

        // Lo stato `pending` lo controlla decide() sotto lock: una richiesta già
        // decisa torna come 422 con messaggio leggibile, non come 403 muto.
        abort_unless($user !== null && $user->can('view', $certificationRequest), 403);

        try {
            $decided = $this->service->decide(
                request: $certificationRequest,
                decider: $user,
                outcome: $data['outcome'],
                ecTrackIds: $data['ec_track_ids'] ?? [],
                selectAll: false,
                note: $data['decision_note'] ?? null,
            );
        } catch (CertificationDecisionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $userLabel = UserDisplay::short($decided->user);

        return response()->json([
            'message' => $decided->status === CertificationRequest::STATUS_APPROVED
                ? __('Approved :count stages for :user.', ['count' => $decided->validatedTracks()->count(), 'user' => $userLabel])
                : __('Request from :user rejected.', ['user' => $userLabel]),
        ]);
    }
}

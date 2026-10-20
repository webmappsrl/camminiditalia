<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreExperienceRequest;
use App\Http\Requests\UpdateExperienceRequest;
use App\Models\Experience;
use App\Services\ExperienceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ExperienceController extends Controller
{
    public function __construct(private readonly ExperienceService $service) {}

    /**
     * Esperienze dell'utente autenticato, dalla più recente. Con `layer_id` anche
     * quelle delle tappe del cammino (hanno lo stesso `layer_id`).
     */
    public function index(Request $request): JsonResponse
    {
        $query = Experience::query()->where('user_id', $request->user()->id);

        if ($request->filled('layer_id')) {
            $query->where('layer_id', (int) $request->query('layer_id'));
        }
        if ($request->filled('ec_track_id')) {
            $query->where('ec_track_id', (int) $request->query('ec_track_id'));
        }
        if ($request->filled('ugc_track_id')) {
            $query->whereHas('ugcTracks', fn ($q) => $q->where('ugc_tracks.id', (int) $request->query('ugc_track_id')));
        }

        // `media` caricati in anticipo: toApiArray() legge le foto di ogni esperienza.
        $experiences = $query->with(['layer', 'ecTrack', 'ugcTracks:id', 'media'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $experiences->map(fn (Experience $e) => $e->toApiArray())->all(),
        ]);
    }

    /**
     * 201 alla creazione, 200 se l'`uuid` esisteva già per l'utente.
     */
    public function store(StoreExperienceRequest $request): JsonResponse
    {
        $experience = $this->service->create(
            $request->user(),
            $request->safe()->only(['uuid', 'layer_id', 'ec_track_id', 'description']),
            (array) $request->file('images', []),
        );

        return response()->json(
            $experience->toApiArray(),
            $experience->wasRecentlyCreated ? 201 : 200,
        );
    }

    /**
     * Modifica testo e foto (POST perché multipart). Senza `description`
     * nella richiesta il testo resta invariato.
     */
    public function update(UpdateExperienceRequest $request, Experience $experience): JsonResponse
    {
        abort_unless($experience->user_id === $request->user()->id, 404);

        $experience = $this->service->update(
            $experience,
            $request->exists('description') ? (string) $request->input('description') : $experience->description,
            $request->keepMediaIds(),
            (array) $request->file('images', []),
        );

        return response()->json($experience->toApiArray());
    }

    /**
     * Elimina l'esperienza; la rimozione di media e file la fa il modello.
     */
    public function destroy(Request $request, Experience $experience): Response
    {
        abort_unless($experience->user_id === $request->user()->id, 404);

        $experience->delete();

        return response()->noContent();
    }
}

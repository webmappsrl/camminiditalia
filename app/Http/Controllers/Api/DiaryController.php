<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDiaryRequest;
use App\Http\Requests\UpdateDiaryRequest;
use App\Models\Diary;
use App\Services\DiaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class DiaryController extends Controller
{
    public function __construct(private readonly DiaryService $service) {}

    /**
     * Esperienze dell'utente autenticato, dalla più recente. Con `layer_id` anche
     * quelle delle tappe del cammino (hanno lo stesso `layer_id`).
     */
    public function index(Request $request): JsonResponse
    {
        $query = Diary::query()->where('user_id', $request->user()->id);

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
        $diaries = $query->with(['layer', 'ecTrack', 'ugcTracks:id', 'media'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $diaries->map(fn (Diary $e) => $e->toApiArray())->all(),
        ]);
    }

    /**
     * 201 alla creazione, 200 se l'`uuid` esisteva già per l'utente.
     */
    public function store(StoreDiaryRequest $request): JsonResponse
    {
        $diary = $this->service->create(
            $request->user(),
            $request->safe()->only(['uuid', 'layer_id', 'ec_track_id', 'description', 'publication_consent', ...Diary::RATINGS, ...Diary::WALK_DATES]),
            (array) $request->file('images', []),
        );

        return response()->json(
            $diary->toApiArray(),
            $diary->wasRecentlyCreated ? 201 : 200,
        );
    }

    /**
     * Modifica testo e foto (POST perché multipart). Senza `description`
     * nella richiesta il testo resta invariato.
     */
    public function update(UpdateDiaryRequest $request, Diary $diary): JsonResponse
    {
        abort_unless($diary->user_id === $request->user()->id, 404);

        $diary = $this->service->update(
            $diary,
            $request->exists('description') ? (string) $request->input('description') : $diary->description,
            $request->keepMediaIds(),
            (array) $request->file('images', []),
            $request->exists('publication_consent') ? $request->boolean('publication_consent') : null,
            $request->safe()->only([...Diary::RATINGS, ...Diary::WALK_DATES]),
        );

        return response()->json($diary->toApiArray());
    }

    /**
     * Elimina l'esperienza; la rimozione di media e file la fa il modello.
     */
    public function destroy(Request $request, Diary $diary): Response
    {
        abort_unless($diary->user_id === $request->user()->id, 404);

        $diary->delete();

        return response()->noContent();
    }
}

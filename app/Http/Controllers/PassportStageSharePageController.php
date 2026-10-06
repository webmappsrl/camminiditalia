<?php

namespace App\Http\Controllers;

use App\Models\PassportStageShare;
use App\Services\PassportShare\StageShareLocale;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Pagina pubblica (senza auth) di una tappa condivisa (oc:8702):
 * `GET /share/passport-stage/{uuid}`. Mostra solo lo snapshot salvato al
 * momento della condivisione e le OG tags per l'anteprima del link: solo
 * immagine e dati, nessun link all'app, nessun dato dell'utente, `noindex`.
 */
class PassportStageSharePageController extends Controller
{
    /**
     * Pagina della condivisione `$uuid`: immagine, titolo, dati della tappa
     * (nello stesso formato dell'immagine) e data dell'ultima condivisione,
     * nella lingua salvata nello snapshot.
     *
     * @throws NotFoundHttpException se la condivisione, la sua immagine o lo snapshot mancano.
     */
    public function show(string $uuid): View
    {
        $share = PassportStageShare::where('uuid', $uuid)->first();
        $media = $share?->getFirstMedia(PassportStageShare::MEDIA_COLLECTION);

        if ($share === null || $media === null || empty($share->snapshot)) {
            throw new NotFoundHttpException('Nessuna tappa condivisa per questo identificativo.');
        }

        $snapshot = $share->snapshot;
        $lang = StageShareLocale::normalize($snapshot['lang'] ?? null);

        $label = fn (string $key): string => trans("passport_share.{$key}", [], $lang);

        $title = collect([$snapshot['layer_name'] ?? null, $snapshot['stage_label'] ?? null])->filter()->implode(' · ');
        $entries = array_filter([
            $label('from') => $snapshot['from'] ?? null,
            $label('to') => $snapshot['to'] ?? null,
            $label('distance') => StageShareLocale::formatDistance(isset($snapshot['distance_km']) ? (float) $snapshot['distance_km'] : null),
            $label('ascent') => StageShareLocale::formatAscent(isset($snapshot['ascent_m']) ? (int) $snapshot['ascent_m'] : null),
        ], fn ($value) => $value !== null && $value !== '');

        return view('share.passport-stage', [
            'lang' => $lang,
            'title' => $title !== '' ? $title : "Cammini d'Italia",
            'description' => collect($entries)->map(fn ($v, $k) => "{$k}: {$v}")->implode(' · '),
            'imageUrl' => $media->getUrl(),
            'canonicalUrl' => route('share.passport-stage', ['uuid' => $uuid]),
            'entries' => $entries,
            'sharedAt' => isset($snapshot['shared_at']) ? Carbon::parse($snapshot['shared_at'])->locale($lang)->isoFormat('LL') : null,
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\PassportShare;
use App\Services\PassportShare\RouteShareImageService;
use App\Services\PassportShare\StageShareLocale;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Pagina pubblica (senza auth) di una condivisione del passaporto:
 * `GET /share/passport/{uuid}`, per una tappa percorsa (oc:8702) o un cammino
 * completato (oc:8703). Mostra solo lo snapshot salvato al momento della
 * condivisione e le OG tags per l'anteprima del link: solo immagine e dati,
 * nessun link all'app, nessun dato dell'utente, `noindex`.
 */
class PassportSharePageController extends Controller
{
    /**
     * Pagina della condivisione `$uuid`: immagine, titolo, dati (nello stesso
     * formato dell'immagine) e data dell'ultima condivisione, nella lingua
     * salvata nello snapshot.
     *
     * @throws NotFoundHttpException se la condivisione, la sua immagine o lo snapshot mancano.
     */
    public function show(string $uuid): View
    {
        $share = PassportShare::where('uuid', $uuid)->first();
        $media = $share?->getFirstMedia(PassportShare::MEDIA_COLLECTION);

        if ($share === null || $media === null || empty($share->snapshot)) {
            throw new NotFoundHttpException('Nessuna condivisione per questo identificativo.');
        }

        $snapshot = $share->snapshot;
        $lang = StageShareLocale::normalize($snapshot['lang'] ?? null);
        [$title, $entries] = $share->isRoute() ? $this->routeContent($snapshot, $lang) : $this->stageContent($snapshot, $lang);
        $entries = array_filter($entries, fn ($value) => $value !== null && $value !== '');

        return view('share.passport', [
            'lang' => $lang,
            'title' => $title !== '' ? $title : "Cammini d'Italia",
            'description' => collect($entries)->map(fn ($v, $k) => "{$k}: {$v}")->implode(' · '),
            'imageUrl' => $media->getUrl(),
            'canonicalUrl' => route('share.passport', ['uuid' => $uuid]),
            'entries' => $entries,
            'sharedAt' => isset($snapshot['shared_at']) ? Carbon::parse($snapshot['shared_at'])->locale($lang)->isoFormat('LL') : null,
        ]);
    }

    /**
     * Titolo e voci di una tappa: partenza, arrivo, lunghezza, dislivello (oc:8702).
     *
     * @param  array<string, mixed>  $snapshot
     * @return array{0: string, 1: array<string, ?string>}
     */
    private function stageContent(array $snapshot, string $lang): array
    {
        $label = fn (string $key): string => trans("passport_share.{$key}", [], $lang);

        return [
            collect([$snapshot['layer_name'] ?? null, $snapshot['stage_label'] ?? null])->filter()->implode(' · '),
            [
                $label('from') => $snapshot['from'] ?? null,
                $label('to') => $snapshot['to'] ?? null,
                $label('distance') => StageShareLocale::formatDistance(isset($snapshot['distance_km']) ? (float) $snapshot['distance_km'] : null),
                $label('ascent') => StageShareLocale::formatAscent(isset($snapshot['ascent_m']) ? (int) $snapshot['ascent_m'] : null),
            ],
        ];
    }

    /**
     * Titolo e voci di un cammino completato: data, tappe, lunghezza totale e,
     * solo con la validazione GPS, uscite (oc:8703).
     *
     * @param  array<string, mixed>  $snapshot
     * @return array{0: string, 1: array<string, ?string>}
     */
    private function routeContent(array $snapshot, string $lang): array
    {
        $label = fn (string $key): string => trans("passport_route_share.{$key}", [], $lang);

        return [
            collect([$snapshot['layer_name'] ?? null, $label('completed')])->filter()->implode(' · '),
            [
                $label('completed_on') => RouteShareImageService::formatDate($snapshot['completed_at'] ?? null, $lang),
                $label('stages') => isset($snapshot['stages_validated'], $snapshot['stages_total']) ? "{$snapshot['stages_validated']}/{$snapshot['stages_total']}" : null,
                $label('total_distance') => StageShareLocale::formatDistance(isset($snapshot['distance_km']) ? (float) $snapshot['distance_km'] : null),
                $label('outings') => isset($snapshot['outings']) ? (string) $snapshot['outings'] : null,
            ],
        ];
    }
}

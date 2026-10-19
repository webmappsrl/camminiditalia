<?php

namespace App\Services\PassportShare;

use App\Services\StageProgressService;
use Illuminate\Support\Facades\DB;
use Intervention\Image\Facades\Image;
use Intervention\Image\Image as InterventionImage;
use RuntimeException;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\Models\StoryShare\MapRenderService;

/**
 * Immagine di condivisione di una tappa percorsa (oc:8702), formato storia
 * 1080×1920, sul mockup del cliente. Dall'alto in basso: sfondo con curve di
 * livello, logo del cammino così com'è (omesso se manca), nome del cammino,
 * mappa incorniciata (tutto il cammino rosso sottile, la tappa gialla sopra,
 * inquadratura sulla tappa), «Tappa {ref}», griglia dei dati (partenza,
 * arrivo, lunghezza, dislivello — mai il tempo), logo di Cammini d'Italia in
 * fondo, preso dall'icona dell'App del cammino (omesso se manca). Misure e
 * colori in {@see StageShareLayout}.
 *
 * Testi in {@see StageShareText}, lingua e formato dei numeri in
 * {@see StageShareLocale}; loghi, mappa incorniciata, griglia e icone in
 * {@see PassportShareCommon}, in comune con l'immagine del cammino (oc:8703).
 *
 * Nessuna persistenza: il chiamante salva immagine e snapshot.
 */
class StageShareImageService
{
    /**
     * Dipendenze iniettate dal container.
     */
    public function __construct(
        private readonly MapRenderService $mapRenderService,
        private readonly StageProgressService $stageProgressService,
        private readonly StageShareText $text,
        private readonly PassportShareCommon $common,
    ) {}

    /**
     * Compone l'immagine della tappa nella lingua `$lang` (ripiego `it`) con
     * i dati di `$snapshot`, calcolato dal chiamante con snapshot(): così
     * una richiesta lo calcola una volta sola. Restituisce l'immagine già
     * codificata in PNG, come StoryShareImageService.
     *
     * @param  array{layer_name: ?string, stage_label: string, from: ?string, to: ?string, distance_km: ?float, ascent_m: ?int}  $snapshot
     *
     * @throws RuntimeException se la tappa non ha geometria o se nessun tile della mappa si scarica.
     */
    public function compose(Layer $layer, EcTrack $track, string $lang, array $snapshot): InterventionImage
    {
        $lang = StageShareLocale::normalize($lang);
        $app = $this->common->resolveApp($layer);

        $canvas = Image::make(resource_path(StageShareLayout::BACKGROUND_PATH))
            ->fit(StageShareLayout::CANVAS_WIDTH, StageShareLayout::CANVAS_HEIGHT);

        // Prima si misurano i blocchi, poi si disegnano: il contenuto si
        // centra in verticale nello spazio sopra il logo di Cammini d'Italia,
        // così un blocco assente non lascia un vuoto in fondo.
        $logo = $this->common->loadLayerLogo($layer);
        $cdiLogo = $this->common->loadCdiLogo($app);
        $title = $snapshot['layer_name'] !== null
            ? $this->text->fit($snapshot['layer_name'], StageShareLayout::TITLE_BOX_WIDTH, StageShareLayout::TITLE_MAX_LINES, StageShareLayout::TITLE_FONT_SIZE, StageShareLayout::TITLE_MIN_FONT_SIZE, StageShareLayout::FONT_BLACK)
            : null;
        $stageLabel = $this->fitStageLabel($snapshot['stage_label'], StageShareLayout::STAGE_LABEL_MAX_LINES);
        $entries = $this->gridEntries($snapshot, $this->labels($lang));
        $gridRows = (int) ceil(count($entries) / StageShareLayout::GRID_COLUMNS);

        $otherBlocks = ($logo !== null ? StageShareLayout::LOGO_BOX_SIZE + StageShareLayout::LOGO_TITLE_GAP : 0)
            + ($title !== null ? $this->text->linesHeight($title) + StageShareLayout::TITLE_MAP_GAP : 0)
            + StageShareLayout::MAP_HEIGHT + 2 * StageShareLayout::MAP_BORDER + StageShareLayout::MAP_STAGE_LABEL_GAP
            + ($gridRows > 0 ? StageShareLayout::STAGE_LABEL_GRID_GAP + $gridRows * StageShareLayout::GRID_ROW_HEIGHT + ($gridRows - 1) * StageShareLayout::GRID_ROW_GAP : 0);
        $available = StageShareLayout::CANVAS_HEIGHT - StageShareLayout::BOTTOM_MARGIN - StageShareLayout::TOP_MARGIN
            - ($cdiLogo !== null ? StageShareLayout::CDI_LOGO_SIZE + StageShareLayout::CDI_LOGO_GAP : 0);

        // Con tutti gli altri blocchi al massimo, due righe di etichetta non
        // entrano sopra il logo di Cammini d'Italia: in quel caso l'etichetta
        // torna su una riga (ridotta e poi troncata).
        if (count($stageLabel['lines']) > 1 && $otherBlocks + $this->text->linesHeight($stageLabel) > $available) {
            $stageLabel = $this->fitStageLabel($snapshot['stage_label'], 1);
        }
        $height = $otherBlocks + $this->text->linesHeight($stageLabel);

        $y = StageShareLayout::TOP_MARGIN + max(0, intdiv($available - $height, 2));

        if ($logo !== null) {
            $this->common->drawLayerLogo($canvas, $logo, $y);
            $y += StageShareLayout::LOGO_BOX_SIZE + StageShareLayout::LOGO_TITLE_GAP;
        }

        if ($title !== null) {
            $y = $this->text->draw($canvas, $title, StageShareLayout::FONT_BLACK, StageShareLayout::TEXT_COLOR, StageShareLayout::CANVAS_WIDTH / 2, $y, 'center');
            $y += StageShareLayout::TITLE_MAP_GAP;
        }

        $y = $this->drawMap($canvas, $layer, $track, $app, $y) + StageShareLayout::MAP_STAGE_LABEL_GAP;
        $y = $this->text->draw($canvas, $stageLabel, StageShareLayout::FONT_BLACK, StageShareLayout::TEXT_COLOR, StageShareLayout::CANVAS_WIDTH / 2, $y, 'center');

        $this->common->drawGrid($canvas, $entries, $y + StageShareLayout::STAGE_LABEL_GRID_GAP);
        if ($cdiLogo !== null) {
            $this->common->drawCdiLogo($canvas, $cdiLogo);
        }

        return $canvas->encode('png');
    }

    /**
     * Dati mostrati nell'immagine e nella pagina pubblica, nella lingua
     * `$lang` (ripiego `it`). Ogni dato assente vale `null`.
     *
     * @return array{layer_name: ?string, stage_label: string, from: ?string, to: ?string, distance_km: ?float, ascent_m: ?int}
     */
    public function snapshot(Layer $layer, EcTrack $track, string $lang): array
    {
        $lang = StageShareLocale::normalize($lang);
        $trim = fn (?string $value): ?string => $value !== null ? trim($value) : null;

        // Stessa derivazione della pagina di dettaglio dell'app.
        $details = $this->stageProgressService->stageDetails($track);
        $ref = $trim($details['ref']);

        $distanceRow = $this->stageProgressService->layerTracksQuery($layer->id)
            ->where('ec_tracks.id', $track->id)
            ->first();
        $distance = $distanceRow !== null ? round((float) $distanceRow->distance_km, 1) : 0.0;

        $stageLabel = $ref !== null
            ? $this->common->stageLabel($ref, $lang)
            : ($this->common->translate($track->getRawOriginal('name'), $lang) ?? '');

        return [
            'layer_name' => $this->common->translate($layer->getRawOriginal('name'), $lang),
            'stage_label' => $stageLabel,
            'from' => $trim($details['from']),
            'to' => $trim($details['to']),
            'distance_km' => $distance > 0 ? $distance : null,
            'ascent_m' => $details['ascent'],
        ];
    }

    /**
     * Etichette della griglia nella lingua indicata (già normalizzata).
     *
     * @return array{from: string, to: string, distance: string, ascent: string}
     */
    private function labels(string $lang): array
    {
        return [
            'from' => trans('passport_share.from', [], $lang),
            'to' => trans('passport_share.to', [], $lang),
            'distance' => trans('passport_share.distance', [], $lang),
            'ascent' => trans('passport_share.ascent', [], $lang),
        ];
    }

    /**
     * Etichetta «Tappa {ref}» (o nome della tappa) su al più `$maxLines`
     * righe: a capo, poi corpo ridotto, poi «…», come gli altri testi.
     *
     * @return array{lines: list<string>, fontSize: int}
     */
    private function fitStageLabel(string $label, int $maxLines): array
    {
        return $this->text->fit($label, StageShareLayout::STAGE_LABEL_BOX_WIDTH, $maxLines, StageShareLayout::STAGE_LABEL_FONT_SIZE, StageShareLayout::STAGE_LABEL_MIN_FONT_SIZE, StageShareLayout::FONT_BLACK);
    }

    /**
     * Riquadro mappa con cornice arancione e angoli arrotondati, da `$top`.
     * Restituisce la y sotto la cornice.
     *
     * @throws RuntimeException
     */
    private function drawMap(InterventionImage $canvas, Layer $layer, EcTrack $track, App $app, int $top): int
    {
        $stage = $this->stageGeometry($track);
        $routeLines = $this->common->routeLineStrings($layer);

        $firstLine = $stage['lineStrings'][0];
        $lastLine = $stage['lineStrings'][count($stage['lineStrings']) - 1];
        $start = $firstLine[0];
        $end = $lastLine[count($lastLine) - 1];

        $map = $this->mapRenderService->renderLayers(
            [
                [
                    'lineStrings' => $routeLines,
                    'color' => StageShareLayout::ROUTE_COLOR,
                    'thickness' => StageShareLayout::ROUTE_THICKNESS,
                    'opacity' => StageShareLayout::ROUTE_OPACITY,
                ],
                [
                    'lineStrings' => $stage['lineStrings'],
                    'color' => StageShareLayout::STAGE_COLOR,
                    'thickness' => StageShareLayout::STAGE_THICKNESS,
                    'opacity' => 1.0,
                    'outlineColor' => StageShareLayout::STAGE_OUTLINE_COLOR,
                    'outlineThickness' => StageShareLayout::STAGE_OUTLINE_THICKNESS,
                ],
            ],
            [
                $this->common->marker($start, 'start', StageShareLayout::MARKER_START_COLOR),
                $this->common->marker($end, 'end', StageShareLayout::MARKER_END_COLOR),
            ],
            $stage['bbox'],
            $app,
            StageShareLayout::MAP_WIDTH,
            StageShareLayout::MAP_HEIGHT,
            StageShareLayout::STAGE_FOCUS_MARGIN_RATIO,
        );

        return $this->common->drawFramedMap($canvas, $map, $top);
    }

    /**
     * LineString (lon, lat) e bbox della tappa, da PostGIS.
     *
     * @return array{lineStrings: list<list<array{0: float, 1: float}>>, bbox: array{xmin: float, ymin: float, xmax: float, ymax: float}}
     *
     * @throws RuntimeException se la tappa non ha geometria utilizzabile.
     */
    private function stageGeometry(EcTrack $track): array
    {
        $row = DB::table('ec_tracks')
            ->where('id', $track->id)
            ->whereNotNull('geometry')
            ->selectRaw(
                'ST_AsGeoJSON(ST_Simplify(ST_Force2D(geometry::geometry), ?)) as simplified, ST_AsGeoJSON(ST_Force2D(geometry::geometry)) as raw,'
                .' ST_XMin(geometry::geometry) as xmin, ST_YMin(geometry::geometry) as ymin, ST_XMax(geometry::geometry) as xmax, ST_YMax(geometry::geometry) as ymax',
                [StageShareLayout::STAGE_SIMPLIFY_DEGREES]
            )
            ->first();

        // Una tappa cortissima può sparire con ST_Simplify: si ripiega sulla geometria intera.
        $lineStrings = $row !== null ? ($this->common->lineStrings($row->simplified) ?: $this->common->lineStrings($row->raw)) : [];

        if ($lineStrings === []) {
            throw new RuntimeException("EcTrack #{$track->id} senza geometria: impossibile disegnare la mappa della tappa.");
        }

        return [
            'lineStrings' => $lineStrings,
            'bbox' => ['xmin' => (float) $row->xmin, 'ymin' => (float) $row->ymin, 'xmax' => (float) $row->xmax, 'ymax' => (float) $row->ymax],
        ];
    }

    /**
     * Voci della griglia, nell'ordine Partenza, Arrivo, Lunghezza,
     * Dislivello: le voci nulle si tolgono, senza etichette vuote.
     *
     * @param  array{from: ?string, to: ?string, distance_km: ?float, ascent_m: ?int}  $snapshot
     * @param  array{from: string, to: string, distance: string, ascent: string}  $labels
     * @return list<array{icon: string, label: string, value: string}>
     */
    private function gridEntries(array $snapshot, array $labels): array
    {
        return array_values(array_filter([
            ['icon' => 'from', 'label' => $labels['from'], 'value' => $snapshot['from'] !== null ? mb_strtoupper($snapshot['from']) : null],
            ['icon' => 'to', 'label' => $labels['to'], 'value' => $snapshot['to'] !== null ? mb_strtoupper($snapshot['to']) : null],
            ['icon' => 'distance', 'label' => $labels['distance'], 'value' => StageShareLocale::formatDistance($snapshot['distance_km'])],
            ['icon' => 'ascent', 'label' => $labels['ascent'], 'value' => StageShareLocale::formatAscent($snapshot['ascent_m'])],
        ], fn (array $entry) => $entry['value'] !== null));
    }
}

<?php

namespace App\Services\PassportShare;

use App\Services\StageProgressService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;
use Intervention\Image\Image as InterventionImage;
use RuntimeException;
use Throwable;
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
 * Testi in {@see StageShareText}, icone in {@see StageShareIcons}, lingua e
 * formato dei numeri in {@see StageShareLocale}.
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
        private readonly StageShareIcons $icons,
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
        $app = $this->resolveApp($layer);

        $canvas = Image::make(resource_path(StageShareLayout::BACKGROUND_PATH))
            ->fit(StageShareLayout::CANVAS_WIDTH, StageShareLayout::CANVAS_HEIGHT);

        // Prima si misurano i blocchi, poi si disegnano: il contenuto si
        // centra in verticale nello spazio sopra il logo di Cammini d'Italia,
        // così un blocco assente non lascia un vuoto in fondo.
        $logo = $this->loadLayerLogo($layer);
        $cdiLogo = $this->loadCdiLogo($app);
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
            $this->drawLayerLogo($canvas, $logo, $y);
            $y += StageShareLayout::LOGO_BOX_SIZE + StageShareLayout::LOGO_TITLE_GAP;
        }

        if ($title !== null) {
            $y = $this->text->draw($canvas, $title, StageShareLayout::FONT_BLACK, StageShareLayout::TEXT_COLOR, StageShareLayout::CANVAS_WIDTH / 2, $y, 'center');
            $y += StageShareLayout::TITLE_MAP_GAP;
        }

        $y = $this->drawMap($canvas, $layer, $track, $app, $y) + StageShareLayout::MAP_STAGE_LABEL_GAP;
        $y = $this->text->draw($canvas, $stageLabel, StageShareLayout::FONT_BLACK, StageShareLayout::TEXT_COLOR, StageShareLayout::CANVAS_WIDTH / 2, $y, 'center');

        $this->drawGrid($canvas, $entries, $y + StageShareLayout::STAGE_LABEL_GRID_GAP);
        if ($cdiLogo !== null) {
            $this->drawCdiLogo($canvas, $cdiLogo);
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
            ? $this->stageLabel($ref, $lang)
            : ($this->translate($track->getRawOriginal('name'), $lang) ?? '');

        return [
            'layer_name' => $this->translate($layer->getRawOriginal('name'), $lang),
            'stage_label' => $stageLabel,
            'from' => $trim($details['from']),
            'to' => $trim($details['to']),
            'distance_km' => $distance > 0 ? $distance : null,
            'ascent_m' => $details['ascent'],
        ];
    }

    /**
     * «Tappa {ref}» tradotto. Nei dati `ref` contiene spesso già la parola
     * «Tappa» («Tappa 7b», «Tappa 7: Mezzojuso»): si toglie e si rimette
     * tradotta. Un `ref` che non è un numero di tappa («Percorso Urbano»)
     * resta com'è.
     */
    private function stageLabel(string $ref, string $lang): string
    {
        if (preg_match('/^tappa\b\s*(.*)$/iu', $ref, $matches) === 1 && trim($matches[1]) !== '') {
            return trans('passport_share.stage', ['ref' => trim($matches[1])], $lang);
        }

        if (preg_match('/^\d/u', $ref) === 1) {
            return trans('passport_share.stage', ['ref' => $ref], $lang);
        }

        return $ref;
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
     * Valore di un campo traducibile grezzo (JSON `{"it": …}` o stringa
     * semplice) in `$lang`, ripiego `it`, poi la prima lingua non vuota.
     */
    private function translate(mixed $raw, string $lang): ?string
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return trim($raw);
        }

        $values = array_filter($decoded, fn ($v) => is_string($v) && trim($v) !== '');

        $value = $values[$lang] ?? $values[StageShareLocale::FALLBACK_LANGUAGE] ?? reset($values);

        return $value !== false ? trim($value) : null;
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
     * Logo del cammino adattato al riquadro LOGO_BOX_SIZE × LOGO_BOX_SIZE,
     * con le proporzioni e la trasparenza originali, o null (blocco saltato)
     * se il layer non ha logo o non si riesce a leggerlo.
     */
    private function loadLayerLogo(Layer $layer): ?InterventionImage
    {
        $media = $layer->getFirstMedia('logo');
        if ($media === null) {
            return null;
        }

        try {
            // Lettura dal disco del media (locale o S3), come StoryShareImageService.
            $bytes = Storage::disk($media->disk)->get($media->getPathRelativeToRoot());

            return Image::make($bytes)->resize(StageShareLayout::LOGO_BOX_SIZE, StageShareLayout::LOGO_BOX_SIZE, function ($constraint) {
                $constraint->aspectRatio();
            });
        } catch (Throwable $e) {
            Log::warning('[oc:8702] logo del cammino non leggibile: immagine senza logo', [
                'layer_id' => $layer->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Logo del cammino così com'è, senza cerchio né bordo: centrato nel
     * riquadro LOGO_BOX_SIZE che parte da `$top`. Le parti trasparenti
     * lasciano vedere lo sfondo.
     */
    private function drawLayerLogo(InterventionImage $canvas, InterventionImage $logo, int $top): void
    {
        $size = StageShareLayout::LOGO_BOX_SIZE;

        $canvas->insert(
            $logo,
            'top-left',
            (int) ((StageShareLayout::CANVAS_WIDTH - $logo->width()) / 2),
            $top + (int) (($size - $logo->height()) / 2),
        );
    }

    /**
     * App del cammino: dà i tile della mappa e il logo di Cammini d'Italia.
     * Se il layer punta a un'App inesistente si ripiega sulla prima App
     * (i tile potrebbero non essere quelli del cammino) e lo si segnala.
     */
    private function resolveApp(Layer $layer): App
    {
        $app = App::find($layer->app_id);
        if ($app !== null) {
            return $app;
        }

        Log::warning('[oc:8702] app del layer non trovata: tile della prima App', [
            'layer_id' => $layer->id,
            'app_id' => $layer->app_id,
        ]);

        return App::query()->firstOrFail();
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
        $routeLines = $this->routeLineStrings($layer);

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
                $this->marker($start, 'start', StageShareLayout::MARKER_START_COLOR),
                $this->marker($end, 'end', StageShareLayout::MARKER_END_COLOR),
            ],
            $stage['bbox'],
            $app,
            StageShareLayout::MAP_WIDTH,
            StageShareLayout::MAP_HEIGHT,
            StageShareLayout::STAGE_FOCUS_MARGIN_RATIO,
        );

        $border = StageShareLayout::MAP_BORDER;
        $outerWidth = StageShareLayout::MAP_WIDTH + 2 * $border;
        $outerHeight = StageShareLayout::MAP_HEIGHT + 2 * $border;
        $left = (int) ((StageShareLayout::CANVAS_WIDTH - $outerWidth) / 2);

        $frame = Image::canvas($outerWidth, $outerHeight, StageShareLayout::MAP_BORDER_COLOR);
        $this->roundCorners($frame, StageShareLayout::MAP_CORNER_RADIUS + $border);
        $canvas->insert($frame, 'top-left', $left, $top);

        $map->fit(StageShareLayout::MAP_WIDTH, StageShareLayout::MAP_HEIGHT);
        $this->roundCorners($map, StageShareLayout::MAP_CORNER_RADIUS);
        $canvas->insert($map, 'top-left', $left + $border, $top + $border);

        return $top + $outerHeight;
    }

    /**
     * Marker di partenza o di arrivo nel formato di renderLayers(), con
     * misura, anello e colore di StageShareLayout.
     *
     * @param  array{0: float, 1: float}  $point
     * @param  'start'|'end'  $type
     * @return array{lon: float, lat: float, type: 'start'|'end', size: int, ringWidth: int, color: string}
     */
    private function marker(array $point, string $type, string $color): array
    {
        return [
            'lon' => $point[0],
            'lat' => $point[1],
            'type' => $type,
            'size' => StageShareLayout::MARKER_SIZE,
            'ringWidth' => StageShareLayout::MARKER_RING_WIDTH,
            'color' => $color,
        ];
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
        $lineStrings = $row !== null ? ($this->lineStrings($row->simplified) ?: $this->lineStrings($row->raw)) : [];

        if ($lineStrings === []) {
            throw new RuntimeException("EcTrack #{$track->id} senza geometria: impossibile disegnare la mappa della tappa.");
        }

        return [
            'lineStrings' => $lineStrings,
            'bbox' => ['xmin' => (float) $row->xmin, 'ymin' => (float) $row->ymin, 'xmax' => (float) $row->xmax, 'ymax' => (float) $row->ymax],
        ];
    }

    /**
     * LineString semplificate di tutte le tappe del cammino (regola A di
     * StageProgressService), tappa condivisa compresa.
     *
     * @return list<list<array{0: float, 1: float}>>
     */
    private function routeLineStrings(Layer $layer): array
    {
        $ids = $this->stageProgressService->layerTracksQuery($layer->id)->pluck('ec_track_id');

        $rows = DB::table('ec_tracks')
            ->whereIn('id', $ids)
            ->whereNotNull('geometry')
            ->selectRaw('ST_AsGeoJSON(ST_Simplify(ST_Force2D(geometry::geometry), ?)) as geom', [StageShareLayout::ROUTE_SIMPLIFY_DEGREES])
            ->pluck('geom');

        return $rows->flatMap(fn (?string $geojson) => $this->lineStrings($geojson))->values()->all();
    }

    /**
     * LineString (lon, lat) da un GeoJSON LineString o MultiLineString;
     * scarta le linee con meno di due punti.
     *
     * @return list<list<array{0: float, 1: float}>>
     */
    private function lineStrings(?string $geojson): array
    {
        $decoded = $geojson !== null ? json_decode($geojson, true) : null;
        if (! is_array($decoded) || ! is_array($decoded['coordinates'] ?? null)) {
            return [];
        }

        $lines = match ($decoded['type'] ?? null) {
            'LineString' => [$decoded['coordinates']],
            'MultiLineString' => $decoded['coordinates'],
            default => [],
        };

        $result = [];
        foreach ($lines as $line) {
            $points = array_map(fn (array $p) => [(float) $p[0], (float) $p[1]], $line);
            if (count($points) >= 2) {
                $result[] = $points;
            }
        }

        return $result;
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

    /**
     * Griglia a GRID_COLUMNS colonne da `$top`, centrata come blocco sulla
     * tela: le colonne hanno la larghezza reale delle loro celle e distano
     * GRID_COLUMN_GAP; un'ultima voce dispari si centra da sola sulla sua riga.
     *
     * @param  list<array{icon: string, label: string, value: string}>  $entries
     */
    private function drawGrid(InterventionImage $canvas, array $entries, int $top): void
    {
        $cells = array_map(fn (array $entry) => $this->measureGridCell($entry), $entries);
        $xs = self::gridCellXs(array_column($cells, 'width'));

        foreach ($cells as $index => $cell) {
            $rowTop = $top + intdiv($index, StageShareLayout::GRID_COLUMNS) * (StageShareLayout::GRID_ROW_HEIGHT + StageShareLayout::GRID_ROW_GAP);
            $this->drawGridCell($canvas, $cell, $xs[$index], $rowTop);
        }
    }

    /**
     * Ascissa sinistra di ogni cella della griglia, date le larghezze misurate.
     * Funzione pura: le celle a indice pari stanno nella colonna sinistra, le
     * dispari nella destra; ogni colonna è larga quanto la sua cella più larga
     * e le due colonne, distanti GRID_COLUMN_GAP, si centrano come blocco sulla
     * tela. Un'ultima voce dispari non fa parte delle colonne: si centra sulla
     * tela con la propria larghezza.
     *
     * @param  list<int>  $widths  Larghezza di ciascuna cella (icona + spazio + testo).
     * @return list<int>
     */
    public static function gridCellXs(array $widths): array
    {
        $count = count($widths);
        $paired = $count - ($count % 2);
        $xs = [];

        if ($paired > 0) {
            $left = 0;
            $right = 0;
            for ($i = 0; $i < $paired; $i++) {
                if ($i % 2 === 0) {
                    $left = max($left, $widths[$i]);
                } else {
                    $right = max($right, $widths[$i]);
                }
            }
            $leftX = (int) round((StageShareLayout::CANVAS_WIDTH - ($left + StageShareLayout::GRID_COLUMN_GAP + $right)) / 2);
            for ($i = 0; $i < $paired; $i++) {
                $xs[$i] = $i % 2 === 0 ? $leftX : $leftX + $left + StageShareLayout::GRID_COLUMN_GAP;
            }
        }

        if ($count % 2 === 1) {
            $xs[$count - 1] = (int) round((StageShareLayout::CANVAS_WIDTH - $widths[$count - 1]) / 2);
        }

        return $xs;
    }

    /**
     * Adatta etichetta e valore di una cella (fino a 2 righe) e ne misura la
     * larghezza: icona + spazio + la più larga fra le righe di testo.
     *
     * @param  array{icon: string, label: string, value: string}  $entry
     * @return array{icon: string, label: array{lines: list<string>, fontSize: int}, value: array{lines: list<string>, fontSize: int}, width: int}
     */
    private function measureGridCell(array $entry): array
    {
        $label = $this->text->fit($entry['label'], StageShareLayout::GRID_VALUE_BOX_WIDTH, 1, StageShareLayout::GRID_LABEL_FONT_SIZE, StageShareLayout::GRID_LABEL_FONT_SIZE, StageShareLayout::FONT_REGULAR);
        $value = $this->text->fit(
            $entry['value'],
            StageShareLayout::GRID_VALUE_BOX_WIDTH,
            StageShareLayout::GRID_VALUE_MAX_LINES,
            StageShareLayout::GRID_VALUE_FONT_SIZE,
            StageShareLayout::MIN_FONT_SIZE,
        );

        $textWidth = 0;
        foreach ($label['lines'] as $line) {
            $textWidth = max($textWidth, $this->text->width($line, $label['fontSize'], StageShareLayout::FONT_REGULAR));
        }
        foreach ($value['lines'] as $line) {
            $textWidth = max($textWidth, $this->text->width($line, $value['fontSize'], StageShareLayout::FONT_BOLD));
        }

        return [
            'icon' => $entry['icon'],
            'label' => $label,
            'value' => $value,
            'width' => StageShareLayout::GRID_ICON_SIZE + StageShareLayout::GRID_ICON_TEXT_GAP + $textWidth,
        ];
    }

    /**
     * Una cella della griglia, già misurata, da `$left`: icona, etichetta e
     * valore allineati a sinistra.
     *
     * @param  array{icon: string, label: array{lines: list<string>, fontSize: int}, value: array{lines: list<string>, fontSize: int}, width: int}  $cell
     */
    private function drawGridCell(InterventionImage $canvas, array $cell, int $left, int $top): void
    {
        $textLeft = $left + StageShareLayout::GRID_ICON_SIZE + StageShareLayout::GRID_ICON_TEXT_GAP;

        $canvas->insert(Image::make($this->icons->icon($cell['icon'])), 'top-left', $left, $top + StageShareLayout::GRID_ICON_OFFSET_Y);

        $y = $this->text->draw($canvas, $cell['label'], StageShareLayout::FONT_REGULAR, StageShareLayout::GRID_LABEL_COLOR, $textLeft, $top, 'left');
        $this->text->draw($canvas, $cell['value'], StageShareLayout::FONT_BOLD, StageShareLayout::TEXT_COLOR, $textLeft, $y + StageShareLayout::GRID_LABEL_VALUE_GAP, 'left');
    }

    /**
     * Logo di Cammini d'Italia dall'icona dell'App (collection `icon`, poi
     * `icon_small`), ridotto a CDI_LOGO_SIZE; null (blocco omesso, con un
     * warning) se nessuna delle due è leggibile.
     *
     * L'icona dell'App è un quadrato opaco con il logo tondo e gli angoli
     * bianchi: se l'angolo in alto a sinistra è opaco l'immagine si ritaglia
     * nel cerchio; un'icona con gli angoli già trasparenti resta com'è.
     */
    private function loadCdiLogo(App $app): ?InterventionImage
    {
        foreach (StageShareLayout::CDI_LOGO_COLLECTIONS as $collection) {
            $media = $app->getFirstMedia($collection);
            if ($media === null) {
                continue;
            }

            try {
                $size = StageShareLayout::CDI_LOGO_SIZE;
                $logo = Image::make(Storage::disk($media->disk)->get($media->getPathRelativeToRoot()))
                    ->resize($size, $size, function ($constraint) {
                        $constraint->aspectRatio();
                    });

                if ((imagecolorat($logo->getCore(), 0, 0) >> 24 & 0x7F) === 0) {
                    $this->roundCorners($logo, (int) floor(min($logo->width(), $logo->height()) / 2));
                }

                return $logo;
            } catch (Throwable $e) {
                Log::warning("[oc:8702] icona dell'App non leggibile ({$collection})", [
                    'app_id' => $app->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::warning("[oc:8702] nessuna icona dell'App leggibile: immagine senza logo di Cammini d'Italia", [
            'app_id' => $app->id,
        ]);

        return null;
    }

    /**
     * Logo di Cammini d'Italia centrato in fondo.
     */
    private function drawCdiLogo(InterventionImage $canvas, InterventionImage $logo): void
    {
        $canvas->insert(
            $logo,
            'top-left',
            (int) ((StageShareLayout::CANVAS_WIDTH - $logo->width()) / 2),
            StageShareLayout::CANVAS_HEIGHT - StageShareLayout::BOTTOM_MARGIN - $logo->height(),
        );
    }

    /**
     * Angoli arrotondati con antialias: nei quattro quadrati d'angolo la
     * trasparenza di ogni pixel segue la sua copertura del cerchio. Con
     * raggio = metà del lato, l'immagine diventa un cerchio.
     */
    private function roundCorners(InterventionImage $image, int $radius): void
    {
        $core = $image->getCore();
        $width = imagesx($core);
        $height = imagesy($core);

        imagesavealpha($core, true);
        imagealphablending($core, false);

        $centers = [
            [$radius, $radius, 0, 0],
            [$width - $radius, $radius, $width - $radius, 0],
            [$radius, $height - $radius, 0, $height - $radius],
            [$width - $radius, $height - $radius, $width - $radius, $height - $radius],
        ];

        foreach ($centers as [$cx, $cy, $x0, $y0]) {
            for ($y = $y0; $y < $y0 + $radius; $y++) {
                for ($x = $x0; $x < $x0 + $radius; $x++) {
                    $distance = sqrt(($x + 0.5 - $cx) ** 2 + ($y + 0.5 - $cy) ** 2);
                    $coverage = max(0.0, min(1.0, $radius - $distance + 0.5));
                    if ($coverage >= 1.0) {
                        continue;
                    }

                    $rgba = imagecolorat($core, $x, $y);
                    $alpha = ($rgba >> 24) & 0x7F;
                    $newAlpha = (int) round(127 - (127 - $alpha) * $coverage);
                    imagesetpixel($core, $x, $y, imagecolorallocatealpha($core, ($rgba >> 16) & 0xFF, ($rgba >> 8) & 0xFF, $rgba & 0xFF, $newAlpha));
                }
            }
        }

        imagealphablending($core, true);
    }
}

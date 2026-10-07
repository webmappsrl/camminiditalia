<?php

namespace App\Services\PassportShare;

use App\Services\StageProgressService;
use GdImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;
use Intervention\Image\Image as InterventionImage;
use Throwable;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;

/**
 * Parti comuni alle immagini di condivisione del passaporto (oc:8703),
 * estratte da StageShareImageService (oc:8702) perché le usa anche
 * l'immagine del cammino completato: App e loghi, traduzioni dei campi,
 * tracciato del cammino, mappa incorniciata, griglia dei dati. Misure e
 * colori restano quelli di {@see StageShareLayout}: l'immagine della tappa
 * non cambia di un byte (StageShareImageRegressionTest).
 */
class PassportShareCommon
{
    /**
     * Dipendenze iniettate dal container.
     */
    public function __construct(
        private readonly StageProgressService $stageProgressService,
        private readonly StageShareText $text,
        private readonly StageShareIcons $icons,
    ) {}

    /**
     * «Tappa {ref}» tradotto. Nei dati `ref` contiene spesso già la parola
     * «Tappa» («Tappa 7b», «Tappa 7: Mezzojuso»): si toglie e si rimette
     * tradotta. Un `ref` che non è un numero di tappa («Percorso Urbano»)
     * resta com'è.
     */
    public function stageLabel(string $ref, string $lang): string
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
     * Mappa nel riquadro con cornice arancione e angoli arrotondati, centrata
     * sulla tela da `$top`. Restituisce la y sotto la cornice.
     */
    public function drawFramedMap(InterventionImage $canvas, InterventionImage $map, int $top): int
    {
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
    public function marker(array $point, string $type, string $color): array
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
     * App del cammino: dà i tile della mappa e il logo di Cammini d'Italia.
     * Se il layer punta a un'App inesistente si ripiega sulla prima App
     * (i tile potrebbero non essere quelli del cammino) e lo si segnala.
     */
    public function resolveApp(Layer $layer): App
    {
        $app = App::find($layer->app_id);
        if ($app !== null) {
            return $app;
        }

        Log::warning('[oc:8702, oc:8703] app del layer non trovata: tile della prima App', [
            'layer_id' => $layer->id,
            'app_id' => $layer->app_id,
        ]);

        return App::query()->firstOrFail();
    }

    /**
     * Valore di un campo traducibile grezzo (JSON `{"it": …}` o stringa
     * semplice) in `$lang`, ripiego `it`, poi la prima lingua non vuota.
     */
    public function translate(mixed $raw, string $lang): ?string
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
     * Logo del cammino adattato al riquadro LOGO_BOX_SIZE × LOGO_BOX_SIZE,
     * con le proporzioni e la trasparenza originali, o null (blocco saltato)
     * se il layer non ha logo o non si riesce a leggerlo.
     */
    public function loadLayerLogo(Layer $layer): ?InterventionImage
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
            Log::warning('[oc:8702, oc:8703] logo del cammino non leggibile: immagine senza logo', [
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
    public function drawLayerLogo(InterventionImage $canvas, InterventionImage $logo, int $top): void
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
     * LineString semplificate di tutte le tappe del cammino (regola A di
     * StageProgressService), tappa condivisa compresa.
     *
     * @return list<list<array{0: float, 1: float}>>
     */
    public function routeLineStrings(Layer $layer): array
    {
        return array_merge([], ...array_values($this->stageLineStrings($layer)));
    }

    /**
     * LineString semplificate di ogni tappa del cammino, per id della tappa
     * (oc:8703): la stessa query di routeLineStrings(), che le unisce.
     *
     * @return array<int, list<list<array{0: float, 1: float}>>>
     */
    public function stageLineStrings(Layer $layer): array
    {
        $ids = $this->stageProgressService->layerTracksQuery($layer->id)->pluck('ec_track_id');

        return DB::table('ec_tracks')
            ->whereIn('id', $ids)
            ->whereNotNull('geometry')
            ->selectRaw('id, ST_AsGeoJSON(ST_Simplify(ST_Force2D(geometry::geometry), ?)) as geom', [StageShareLayout::ROUTE_SIMPLIFY_DEGREES])
            ->pluck('geom', 'id')
            ->map(fn (?string $geojson) => $this->lineStrings($geojson))
            ->all();
    }

    /**
     * LineString (lon, lat) da un GeoJSON LineString o MultiLineString;
     * scarta le linee con meno di due punti.
     *
     * @return list<list<array{0: float, 1: float}>>
     */
    public function lineStrings(?string $geojson): array
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
     * Griglia a GRID_COLUMNS colonne da `$top`, centrata come blocco sulla
     * tela: le colonne hanno la larghezza reale delle loro celle e distano
     * GRID_COLUMN_GAP; un'ultima voce dispari si centra da sola sulla sua riga.
     *
     * @param  list<array{icon: string|GdImage, label: string, value: string}>  $entries
     */
    public function drawGrid(InterventionImage $canvas, array $entries, int $top): void
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
     * @param  array{icon: string|GdImage, label: string, value: string}  $entry
     * @return array{icon: string|GdImage, label: array{lines: list<string>, fontSize: int}, value: array{lines: list<string>, fontSize: int}, width: int}
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
     * @param  array{icon: string|GdImage, label: array{lines: list<string>, fontSize: int}, value: array{lines: list<string>, fontSize: int}, width: int}  $cell
     */
    private function drawGridCell(InterventionImage $canvas, array $cell, int $left, int $top): void
    {
        $textLeft = $left + StageShareLayout::GRID_ICON_SIZE + StageShareLayout::GRID_ICON_TEXT_GAP;

        $canvas->insert(Image::make($cell['icon'] instanceof GdImage ? $cell['icon'] : $this->icons->icon($cell['icon'])), 'top-left', $left, $top + StageShareLayout::GRID_ICON_OFFSET_Y);

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
    public function loadCdiLogo(App $app): ?InterventionImage
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
                Log::warning("[oc:8702, oc:8703] icona dell'App non leggibile ({$collection})", [
                    'app_id' => $app->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::warning("[oc:8702, oc:8703] nessuna icona dell'App leggibile: immagine senza logo di Cammini d'Italia", [
            'app_id' => $app->id,
        ]);

        return null;
    }

    /**
     * Logo di Cammini d'Italia centrato in fondo.
     */
    public function drawCdiLogo(InterventionImage $canvas, InterventionImage $logo): void
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

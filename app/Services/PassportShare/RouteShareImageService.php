<?php

namespace App\Services\PassportShare;

use App\Models\ValidatedEcTrack;
use App\Services\StageProgressService;
use Illuminate\Support\Carbon;
use Intervention\Image\Facades\Image;
use Intervention\Image\Image as InterventionImage;
use ReflectionClass;
use RuntimeException;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\Models\StoryShare\MapRenderService;

/**
 * Immagine di condivisione di un cammino completato (oc:8703, vista 7 del
 * wireframe), formato storia 1080×1920 come quella della tappa (oc:8702).
 * Dall'alto in basso: sfondo, logo del cammino (omesso se manca), nome del
 * cammino, mappa incorniciata con il cammino in rosso, un pallino a ogni
 * cambio di tappa e il ref delle tappe, «Cammino completato», griglia con
 * data di completamento, tappe, lunghezza totale (omessa se una tappa non ha
 * la distanza) e uscite (solo se tutte le tappe sono validate col GPS), logo
 * di Cammini d'Italia in fondo.
 *
 * Misure e colori sono quelli di {@see StageShareLayout}, più le poche
 * costanti di questa classe; le parti comuni con la tappa stanno in
 * {@see PassportShareCommon}.
 *
 * Nessuna persistenza: il chiamante salva immagine e snapshot.
 */
class RouteShareImageService
{
    /** Versione del layout del cammino: alzarla invalida le immagini salvate. */
    public const VERSION = 1;

    /** Margine attorno al cammino nella mappa, come frazione del suo ingombro. */
    public const ROUTE_FOCUS_MARGIN_RATIO = 0.03;

    /** Colore del cammino e delle etichette delle tappe, come nella vista 7. */
    public const ROUTE_COLOR = '#E3001B';

    /** Spessore del cammino in px. */
    public const ROUTE_THICKNESS = 5;

    /** Bordo bianco del cammino in px per lato, per staccarlo dalla mappa. */
    public const ROUTE_OUTLINE_THICKNESS = 1;

    /** Colore del bordo del cammino e del contorno delle etichette delle tappe. */
    public const OUTLINE_COLOR = '#FFFFFF';

    /** Pallini a ogni cambio di tappa: colore, diametro e anello bianco in px. */
    public const DOT_COLOR = '#2E7D32';

    public const DOT_SIZE = 16;

    public const DOT_RING_WIDTH = 3;

    /** Due estremi di tappa più vicini di così (gradi) sono lo stesso pallino. */
    public const DOT_MERGE_DEGREES = 0.0005;

    /** Etichette delle tappe: corpo e bordo bianco in px. */
    public const LABEL_FONT_SIZE = 16;

    public const LABEL_OUTLINE_WIDTH = 2;

    /** Oltre questo numero di tappe le etichette non si leggerebbero più: si omettono. */
    public const MAX_LABELS = 20;

    /**
     * Fuso in cui si mostrano le date e si contano le uscite: quello dei cammini, non quello
     * dell'applicazione (UTC), altrimenti una validazione fra mezzanotte e le 2 cadrebbe nel
     * giorno prima.
     */
    public const DISPLAY_TIMEZONE = 'Europe/Rome';

    /** Percorso relativo (lang_path) del file con le etichette del cammino. */
    public const LANG_FILE = 'passport_route_share.php';

    /**
     * Dipendenze iniettate dal container.
     */
    public function __construct(
        private readonly MapRenderService $mapRenderService,
        private readonly StageProgressService $stageProgressService,
        private readonly StageShareText $text,
        private readonly PassportShareCommon $common,
        private readonly RouteShareIcons $icons,
    ) {}

    /**
     * Firma del layout del cammino per l'impronta della cache: la firma della
     * tappa (misure, font, sfondo, traduzioni comuni), le costanti di questa
     * classe e di RouteShareIcons e il contenuto dei suoi file di lingua.
     */
    public static function signature(): string
    {
        $files = [];
        foreach (StageShareLocale::SUPPORTED_LANGUAGES as $lang) {
            $path = lang_path("{$lang}/".self::LANG_FILE);
            $files[$lang] = is_file($path) ? hash_file('sha256', $path) : null;
        }

        return hash('sha256', json_encode([
            'stage' => StageShareLayout::signature(),
            'constants' => self::signatureConstants(),
            'files' => $files,
        ]));
    }

    /**
     * Costanti che entrano nella firma, per classe: quelle di questa classe e quelle di
     * RouteShareIcons (il disegno del calendario delle uscite), private comprese.
     *
     * @return array<class-string, array<string, mixed>>
     */
    public static function signatureConstants(): array
    {
        return [
            self::class => (new ReflectionClass(self::class))->getConstants(),
            RouteShareIcons::class => (new ReflectionClass(RouteShareIcons::class))->getConstants(),
        ];
    }

    /**
     * Dati mostrati nell'immagine e nella pagina pubblica, per `$user` sul
     * cammino `$layer`, nella lingua `$lang` (ripiego `it`).
     *
     * - `completed_at`: la validazione più recente fra le tappe dell'utente;
     * - `distance_km`: somma delle distanze delle tappe, `null` se anche una
     *   sola vale 0 (dato mancante), come nell'app. Le varianti contano nella
     *   somma (limite noto, da rivedere con oc:8165);
     * - `outings`: giorni distinti delle validazioni, solo se tutte le tappe
     *   sono validate col GPS: con una validazione manuale la data è quella
     *   dell'approvazione, non del cammino, e il numero non si sa.
     *
     * @return array{layer_name: ?string, completed_at: ?string, stages_validated: int, stages_total: int, distance_km: ?float, outings: ?int}
     */
    public function snapshot(User $user, Layer $layer, string $lang): array
    {
        $lang = StageShareLocale::normalize($lang);
        $progress = $this->stageProgressService->progressFor($user, $layer);
        $tracks = collect($progress['tracks']);

        $distances = $tracks->pluck('distance')->map(fn ($d) => (float) $d);
        $distance = $distances->isNotEmpty() && $distances->every(fn (float $d) => $d > 0)
            ? round($distances->sum(), 1)
            : null;

        return [
            'layer_name' => $this->common->translate($layer->getRawOriginal('name'), $lang),
            'completed_at' => $tracks->pluck('validated_at')->filter()->max(),
            'stages_validated' => (int) $progress['validated'],
            'stages_total' => (int) $progress['total'],
            'distance_km' => $distance,
            'outings' => $this->outings($tracks->all()),
        ];
    }

    /**
     * Giorni distinti delle validazioni (nel fuso DISPLAY_TIMEZONE), `null` se una
     * tappa non è validata o non è validata col GPS.
     *
     * @param  list<array{validated_at: ?string, source: ?string}>  $tracks
     */
    private function outings(array $tracks): ?int
    {
        if ($tracks === []) {
            return null;
        }

        foreach ($tracks as $track) {
            if ($track['validated_at'] === null || $track['source'] !== ValidatedEcTrack::SOURCE_GPS) {
                return null;
            }
        }

        return collect($tracks)
            ->map(fn (array $track) => Carbon::parse($track['validated_at'])->timezone(self::DISPLAY_TIMEZONE)->toDateString())
            ->unique()
            ->count();
    }

    /**
     * Compone l'immagine del cammino nella lingua `$lang` con i dati di
     * `$snapshot` (calcolato dal chiamante con snapshot()). Restituisce
     * l'immagine già codificata in PNG.
     *
     * @param  array{layer_name: ?string, completed_at: ?string, stages_validated: int, stages_total: int, distance_km: ?float, outings?: ?int}  $snapshot
     *
     * @throws RuntimeException se il cammino non ha geometria o se nessun tile della mappa si scarica.
     */
    public function compose(Layer $layer, string $lang, array $snapshot): InterventionImage
    {
        $lang = StageShareLocale::normalize($lang);
        $app = $this->common->resolveApp($layer);

        $canvas = Image::make(resource_path(StageShareLayout::BACKGROUND_PATH))
            ->fit(StageShareLayout::CANVAS_WIDTH, StageShareLayout::CANVAS_HEIGHT);

        // Come per la tappa: prima si misurano i blocchi, poi si disegnano,
        // centrati in verticale sopra il logo di Cammini d'Italia.
        $logo = $this->common->loadLayerLogo($layer);
        $cdiLogo = $this->common->loadCdiLogo($app);
        $title = $snapshot['layer_name'] !== null
            ? $this->text->fit($snapshot['layer_name'], StageShareLayout::TITLE_BOX_WIDTH, StageShareLayout::TITLE_MAX_LINES, StageShareLayout::TITLE_FONT_SIZE, StageShareLayout::TITLE_MIN_FONT_SIZE, StageShareLayout::FONT_BLACK)
            : null;
        $label = $this->text->fit($this->trans('completed', $lang), StageShareLayout::STAGE_LABEL_BOX_WIDTH, 1, StageShareLayout::STAGE_LABEL_FONT_SIZE, StageShareLayout::STAGE_LABEL_MIN_FONT_SIZE, StageShareLayout::FONT_BLACK);
        $entries = $this->gridEntries($snapshot, $lang);
        $gridRows = (int) ceil(count($entries) / StageShareLayout::GRID_COLUMNS);

        $height = ($logo !== null ? StageShareLayout::LOGO_BOX_SIZE + StageShareLayout::LOGO_TITLE_GAP : 0)
            + ($title !== null ? $this->text->linesHeight($title) + StageShareLayout::TITLE_MAP_GAP : 0)
            + StageShareLayout::MAP_HEIGHT + 2 * StageShareLayout::MAP_BORDER + StageShareLayout::MAP_STAGE_LABEL_GAP
            + $this->text->linesHeight($label)
            + ($gridRows > 0 ? StageShareLayout::STAGE_LABEL_GRID_GAP + $gridRows * StageShareLayout::GRID_ROW_HEIGHT + ($gridRows - 1) * StageShareLayout::GRID_ROW_GAP : 0);
        $available = StageShareLayout::CANVAS_HEIGHT - StageShareLayout::BOTTOM_MARGIN - StageShareLayout::TOP_MARGIN
            - ($cdiLogo !== null ? StageShareLayout::CDI_LOGO_SIZE + StageShareLayout::CDI_LOGO_GAP : 0);

        $y = StageShareLayout::TOP_MARGIN + max(0, intdiv($available - $height, 2));

        if ($logo !== null) {
            $this->common->drawLayerLogo($canvas, $logo, $y);
            $y += StageShareLayout::LOGO_BOX_SIZE + StageShareLayout::LOGO_TITLE_GAP;
        }

        if ($title !== null) {
            $y = $this->text->draw($canvas, $title, StageShareLayout::FONT_BLACK, StageShareLayout::TEXT_COLOR, StageShareLayout::CANVAS_WIDTH / 2, $y, 'center');
            $y += StageShareLayout::TITLE_MAP_GAP;
        }

        $stages = $this->stages($layer);
        $routeLines = $this->routeLines($stages, $layer);
        $map = $this->mapRenderService->renderLayers(
            [[
                'lineStrings' => $routeLines,
                'color' => self::ROUTE_COLOR,
                'thickness' => self::ROUTE_THICKNESS,
                'opacity' => 1.0,
                'outlineColor' => self::OUTLINE_COLOR,
                'outlineThickness' => self::ROUTE_OUTLINE_THICKNESS,
            ]],
            $this->dots($stages),
            $this->routeBbox($routeLines),
            $app,
            StageShareLayout::MAP_WIDTH,
            StageShareLayout::MAP_HEIGHT,
            self::ROUTE_FOCUS_MARGIN_RATIO,
            $this->labels($stages, $lang),
        );
        $y = $this->common->drawFramedMap($canvas, $map, $y) + StageShareLayout::MAP_STAGE_LABEL_GAP;
        $y = $this->text->draw($canvas, $label, StageShareLayout::FONT_BLACK, StageShareLayout::TEXT_COLOR, StageShareLayout::CANVAS_WIDTH / 2, $y, 'center');

        $this->common->drawGrid($canvas, $entries, $y + StageShareLayout::STAGE_LABEL_GRID_GAP);
        if ($cdiLogo !== null) {
            $this->common->drawCdiLogo($canvas, $cdiLogo);
        }

        return $canvas->encode('png');
    }

    /**
     * Data di completamento lunga nella lingua indicata («30 settembre 2026»),
     * stesso formato della pagina pubblica.
     */
    public static function formatDate(?string $iso, string $lang): ?string
    {
        return $iso !== null
            ? Carbon::parse($iso)->timezone(self::DISPLAY_TIMEZONE)->locale(StageShareLocale::normalize($lang))->isoFormat('LL')
            : null;
    }

    /**
     * Etichetta del cammino nella lingua indicata.
     */
    public function trans(string $key, string $lang): string
    {
        return trans('passport_route_share.'.$key, [], $lang);
    }

    /**
     * Voci della griglia: Completato il, Tappe, Lunghezza totale e, solo con
     * la validazione GPS, Uscite; le voci nulle si tolgono. Le icone sono
     * quelle della tappa (bandiera, segnaposto, anello) più il calendario.
     *
     * @param  array{completed_at: ?string, stages_validated: int, stages_total: int, distance_km: ?float, outings?: ?int}  $snapshot
     * @return list<array{icon: string|\GdImage, label: string, value: string}>
     */
    private function gridEntries(array $snapshot, string $lang): array
    {
        return array_values(array_filter([
            ['icon' => 'to', 'label' => $this->trans('completed_on', $lang), 'value' => self::formatDate($snapshot['completed_at'], $lang)],
            ['icon' => 'from', 'label' => $this->trans('stages', $lang), 'value' => "{$snapshot['stages_validated']}/{$snapshot['stages_total']}"],
            ['icon' => 'distance', 'label' => $this->trans('total_distance', $lang), 'value' => StageShareLocale::formatDistance($snapshot['distance_km'])],
            ['icon' => $this->icons->calendar(), 'label' => $this->trans('outings', $lang), 'value' => isset($snapshot['outings']) ? (string) $snapshot['outings'] : null],
        ], fn (array $entry) => $entry['value'] !== null));
    }

    /**
     * Tappe del cammino con il loro `ref` (stessa derivazione della pagina di
     * dettaglio dell'app) e le loro LineString semplificate.
     *
     * @return list<array{ref: ?string, lineStrings: list<list<array{0: float, 1: float}>>}>
     */
    private function stages(Layer $layer): array
    {
        $lines = $this->common->stageLineStrings($layer);

        return EcTrack::query()
            ->select(StageProgressService::STAGE_DETAIL_COLUMNS)
            ->whereIn('id', array_keys($lines))
            ->orderBy('id')
            ->get()
            ->map(fn (EcTrack $track) => [
                'ref' => $this->stageProgressService->stageDetails($track)['ref'],
                'lineStrings' => $lines[$track->id] ?? [],
            ])
            ->filter(fn (array $stage) => $stage['lineStrings'] !== [])
            ->values()
            ->all();
    }

    /**
     * LineString di tutte le tappe del cammino.
     *
     * @param  list<array{ref: ?string, lineStrings: list<list<array{0: float, 1: float}>>}>  $stages
     * @return list<list<array{0: float, 1: float}>>
     *
     * @throws RuntimeException se nessuna tappa ha geometria.
     */
    private function routeLines(array $stages, Layer $layer): array
    {
        $lines = array_merge(...array_column($stages, 'lineStrings'));
        if ($lines === []) {
            throw new RuntimeException("Layer #{$layer->id} senza geometria: impossibile disegnare la mappa del cammino.");
        }

        return $lines;
    }

    /**
     * Un pallino a ogni cambio di tappa: gli estremi di ogni tappa, senza
     * ripetere quelli condivisi fra due tappe contigue.
     *
     * @param  list<array{ref: ?string, lineStrings: list<list<array{0: float, 1: float}>>}>  $stages
     * @return list<array{lon: float, lat: float, type: 'start', size: int, ringWidth: int, color: string}>
     */
    private function dots(array $stages): array
    {
        $points = [];
        foreach ($stages as $stage) {
            $first = $stage['lineStrings'][0];
            $last = $stage['lineStrings'][count($stage['lineStrings']) - 1];
            foreach ([$first[0], $last[count($last) - 1]] as $point) {
                $merged = false;
                foreach ($points as $existing) {
                    if (abs($existing[0] - $point[0]) < self::DOT_MERGE_DEGREES && abs($existing[1] - $point[1]) < self::DOT_MERGE_DEGREES) {
                        $merged = true;
                        break;
                    }
                }
                if (! $merged) {
                    $points[] = $point;
                }
            }
        }

        return array_map(fn (array $point) => [
            'lon' => $point[0],
            'lat' => $point[1],
            'type' => 'start',
            'size' => self::DOT_SIZE,
            'ringWidth' => self::DOT_RING_WIDTH,
            'color' => self::DOT_COLOR,
        ], $points);
    }

    /**
     * «Tappa {ref}» tradotto a metà di ogni tappa che ha un `ref`; nessuna
     * etichetta oltre MAX_LABELS tappe, perché si sovrapporrebbero.
     *
     * @param  list<array{ref: ?string, lineStrings: list<list<array{0: float, 1: float}>>}>  $stages
     * @return list<array{lon: float, lat: float, text: string, color: string, size: int, outlineColor: string, outlineWidth: int, font: string}>
     */
    private function labels(array $stages, string $lang): array
    {
        if (count($stages) > self::MAX_LABELS) {
            return [];
        }

        $labels = [];
        foreach ($stages as $stage) {
            $ref = $stage['ref'] !== null ? trim($stage['ref']) : '';
            if ($ref === '') {
                continue;
            }
            // Punto a metà della linea più lunga della tappa.
            $line = collect($stage['lineStrings'])->sortByDesc(fn (array $l) => count($l))->first();
            $middle = $line[intdiv(count($line), 2)];
            $labels[] = [
                'lon' => $middle[0],
                'lat' => $middle[1],
                'text' => $this->common->stageLabel($ref, $lang),
                'color' => self::ROUTE_COLOR,
                'size' => self::LABEL_FONT_SIZE,
                'outlineColor' => self::OUTLINE_COLOR,
                'outlineWidth' => self::LABEL_OUTLINE_WIDTH,
                'font' => StageShareLayout::FONT_BOLD,
            ];
        }

        return $labels;
    }

    /**
     * Ingombro di tutte le tappe del cammino, dalle LineString già lette:
     * nessuna query in più.
     *
     * @param  list<list<array{0: float, 1: float}>>  $lines
     * @return array{xmin: float, ymin: float, xmax: float, ymax: float}
     */
    private function routeBbox(array $lines): array
    {
        $points = array_merge(...$lines);
        $lons = array_column($points, 0);
        $lats = array_column($points, 1);

        return ['xmin' => min($lons), 'ymin' => min($lats), 'xmax' => max($lons), 'ymax' => max($lats)];
    }
}

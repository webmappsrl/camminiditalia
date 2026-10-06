<?php

namespace Tests\Feature;

use App\Models\EcTrack;
use App\Services\PassportShare\StageShareIcons;
use App\Services\PassportShare\StageShareImageService;
use App\Services\PassportShare\StageShareLayout;
use App\Services\PassportShare\StageShareLocale;
use App\Services\PassportShare\StageShareText;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Intervention\Image\Facades\Image;
use Intervention\Image\Image as InterventionImage;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionMethod;
use Tests\Feature\Helpers\FakesCertificationDisk;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack as WmEcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\Models\StoryShare\MapRenderService;
use Wm\WmPackage\Services\RolesAndPermissionsService;

/**
 * Composizione dell'immagine di condivisione della tappa (oc:8702).
 * I tile della mappa sono finti (Http::fake), come nei test di MapRenderService.
 */
class StageShareImageServiceTest extends TestCase
{
    use DatabaseTransactions, FakesCertificationDisk, LayerTestHelpers;

    private StageShareImageService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $tile = Image::canvas(256, 256, '#cfe3c4')->encode('png')->getEncoded();
        Http::fake(fn () => Http::response($tile, 200, ['Content-Type' => 'image/png']));

        RolesAndPermissionsService::seedDatabase();

        if (App::count() === 0) {
            App::factory()->create();
        }

        $this->fakeCertificationDisk();
        $this->service = app(StageShareImageService::class);
    }

    /**
     * Compone come il controller: snapshot calcolato una volta e passato a compose().
     */
    private function compose(Layer $layer, WmEcTrack $stage, string $lang): InterventionImage
    {
        return $this->service->compose($layer, $stage, $lang, $this->service->snapshot($layer, $stage, $lang));
    }

    /**
     * PNG quadrato a tinta unita, per le icone dell'App.
     */
    private function solidPng(string $color, int $size = 512): string
    {
        return Image::canvas($size, $size, $color)->encode('png')->getEncoded();
    }

    /**
     * Colore RGB al centro del blocco del logo di Cammini d'Italia.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private function cdiLogoCenter(InterventionImage $image): array
    {
        $y = StageShareLayout::CANVAS_HEIGHT - StageShareLayout::BOTTOM_MARGIN - (int) (StageShareLayout::CDI_LOGO_SIZE / 2);

        return array_slice($image->pickColor((int) (StageShareLayout::CANVAS_WIDTH / 2), $y), 0, 3);
    }

    /**
     * App del layer senza media: i test aggiungono le icone che servono.
     */
    private function appWithoutIcons(Layer $layer): App
    {
        $app = App::findOrFail($layer->app_id);
        DB::table('media')->where('model_type', $app->getMorphClass())->where('model_id', $app->id)->delete();

        return $app->fresh();
    }

    /**
     * Tappa con geometria nota, associata al layer con insert diretto su
     * `layerables` (come in StageProgressServiceTest, per non far scattare
     * LayerableObserver).
     *
     * @param  array<string, mixed>  $properties
     * @param  list<array{0: float, 1: float}>  $points
     */
    private function stage(Layer $layer, array $properties, array $points, string $name = 'Da Pacentro a Caramanico'): WmEcTrack
    {
        $track = EcTrack::factory()->create([
            'user_id' => $layer->user_id,
            'osmid' => null,
            'name' => ['it' => $name],
            'properties' => $properties,
        ]);

        $wkt = 'MULTILINESTRING Z(('.implode(', ', array_map(fn ($p) => "{$p[0]} {$p[1]} 0", $points)).'))';
        DB::table('ec_tracks')->where('id', $track->id)->update(['geometry' => DB::raw("ST_GeomFromText('{$wkt}', 4326)")]);

        DB::table('layerables')->insert([
            'layer_id' => $layer->id,
            'layerable_type' => (new (config('wm-package.ec_track_model')))->getMorphClass(),
            'layerable_id' => $track->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return WmEcTrack::find($track->id);
    }

    /**
     * Layer con due tappe: restituisce [layer, tappa da condividere].
     *
     * @param  array<string, mixed>  $properties
     * @return array{0: Layer, 1: WmEcTrack}
     */
    private function layerWithStage(array $properties = ['ref' => '01', 'from' => 'Pacentro', 'to' => 'Caramanico', 'manual_data' => ['distance' => 20.4, 'ascent' => 358]]): array
    {
        $owner = $this->createUserWithRole('Validator');
        $layer = $this->createLayer($owner->id);
        $layer->forceFill(['name' => ['it' => 'Cammino del Gran Sasso', 'de' => 'Gran-Sasso-Weg']])->saveQuietly();

        $stage = $this->stage($layer, $properties, [[13.90, 42.10], [13.95, 42.12], [14.00, 42.15]]);
        $this->stage($layer, ['ref' => '02'], [[14.00, 42.15], [14.08, 42.20], [14.15, 42.22]], 'Seconda tappa');

        return [$layer->fresh(), $stage];
    }

    /**
     * Adatta un testo con StageShareText::fit().
     *
     * @return array{lines: list<string>, fontSize: int}
     */
    private function fitText(string $text, int $boxWidth, int $maxLines, int $fontSize, int $minFontSize): array
    {
        return app(StageShareText::class)->fit($text, $boxWidth, $maxLines, $fontSize, $minFontSize);
    }

    public function test_image_is_1080x1920(): void
    {
        [$layer, $stage] = $this->layerWithStage();

        $image = $this->compose($layer, $stage, 'it');

        $this->assertSame(1080, $image->width());
        $this->assertSame(1920, $image->height());
    }

    public function test_snapshot_uses_ref_for_stage_label(): void
    {
        [$layer, $stage] = $this->layerWithStage();

        $snapshot = $this->service->snapshot($layer, $stage, 'it');

        $this->assertSame([
            'layer_name' => 'Cammino del Gran Sasso',
            'stage_label' => 'Tappa 01',
            'from' => 'Pacentro',
            'to' => 'Caramanico',
            'distance_km' => 20.4,
            'ascent_m' => 358,
        ], $snapshot);
    }

    public function test_snapshot_without_ref_uses_stage_name(): void
    {
        [$layer, $stage] = $this->layerWithStage(['ref' => '', 'from' => 'Pacentro']);

        $snapshot = $this->service->snapshot($layer, $stage, 'it');

        $this->assertSame('Da Pacentro a Caramanico', $snapshot['stage_label']);
    }

    /**
     * Nei dati reali `ref` contiene spesso già la parola «Tappa» («Tappa 7b»):
     * non si ripete, e si traduce come il prefisso.
     */
    public function test_ref_already_prefixed_with_tappa_is_not_repeated(): void
    {
        [$layer, $stage] = $this->layerWithStage(['ref' => 'Tappa 7b']);
        [$otherLayer, $named] = $this->layerWithStage(['ref' => 'Percorso Urbano']);

        $this->assertSame('Tappa 7b', $this->service->snapshot($layer, $stage, 'it')['stage_label']);
        $this->assertSame('Etappe 7b', $this->service->snapshot($layer, $stage, 'de')['stage_label']);
        $this->assertSame('Percorso Urbano', $this->service->snapshot($otherLayer, $named, 'it')['stage_label']);
    }

    public function test_missing_fields_are_null_and_compose_does_not_throw(): void
    {
        [$layer, $stage] = $this->layerWithStage(['ref' => '03', 'to' => 'Caramanico']);

        $snapshot = $this->service->snapshot($layer, $stage, 'it');

        $this->assertNull($snapshot['from']);
        $this->assertNull($snapshot['ascent_m']);
        $this->assertSame('Caramanico', $snapshot['to']);

        $image = $this->compose($layer, $stage, 'it');
        $this->assertSame(1920, $image->height());
    }

    public function test_compose_without_layer_logo_does_not_throw(): void
    {
        [$layer, $stage] = $this->layerWithStage();
        $this->assertNull($layer->getFirstMedia('logo'));

        $image = $this->compose($layer, $stage, 'it');

        $this->assertSame(1080, $image->width());
    }

    public function test_grid_four_items_is_a_centred_block(): void
    {
        $g = StageShareLayout::GRID_COLUMN_GAP;
        // colonna sinistra 300 (max di 300 e 200), destra 400 (max di 400 e 100)
        $xs = StageShareImageService::gridCellXs([300, 400, 200, 100]);
        $x0 = (int) round((StageShareLayout::CANVAS_WIDTH - (300 + $g + 400)) / 2);

        $this->assertSame([$x0, $x0 + 300 + $g, $x0, $x0 + 300 + $g], $xs);
    }

    public function test_grid_three_items_centres_the_last_one_alone(): void
    {
        $g = StageShareLayout::GRID_COLUMN_GAP;
        $xs = StageShareImageService::gridCellXs([300, 400, 250]);
        $x0 = (int) round((StageShareLayout::CANVAS_WIDTH - (300 + $g + 400)) / 2);

        $this->assertSame([$x0, $x0 + 300 + $g, (int) round((StageShareLayout::CANVAS_WIDTH - 250) / 2)], $xs);
    }

    public function test_grid_two_items_are_two_centred_columns(): void
    {
        $g = StageShareLayout::GRID_COLUMN_GAP;
        $xs = StageShareImageService::gridCellXs([200, 300]);
        $x0 = (int) round((StageShareLayout::CANVAS_WIDTH - (200 + $g + 300)) / 2);

        $this->assertSame([$x0, $x0 + 200 + $g], $xs);
    }

    public function test_grid_one_item_is_centred(): void
    {
        $this->assertSame([(int) round((StageShareLayout::CANVAS_WIDTH - 301) / 2)], StageShareImageService::gridCellXs([301]));
    }

    public function test_grid_widest_columns_fit_inside_the_margins(): void
    {
        $max = StageShareLayout::GRID_ICON_SIZE + StageShareLayout::GRID_ICON_TEXT_GAP + StageShareLayout::GRID_VALUE_BOX_WIDTH;
        $xs = StageShareImageService::gridCellXs([$max, $max]);

        $this->assertGreaterThanOrEqual(StageShareLayout::GRID_X, $xs[0]);
        $this->assertLessThanOrEqual(StageShareLayout::GRID_X + StageShareLayout::GRID_WIDTH, $xs[1] + $max);
    }

    public function test_fit_text_wraps_long_place_name_in_two_lines(): void
    {
        $result = $this->fitText(
            'Santa Maria Capua Vetere – Stazione FS',
            StageShareLayout::GRID_VALUE_BOX_WIDTH,
            StageShareLayout::GRID_VALUE_MAX_LINES,
            StageShareLayout::GRID_VALUE_FONT_SIZE,
            StageShareLayout::MIN_FONT_SIZE,
        );

        $this->assertLessThanOrEqual(2, count($result['lines']));
        $this->assertGreaterThanOrEqual(StageShareLayout::MIN_FONT_SIZE, $result['fontSize']);
    }

    public function test_fit_text_truncates_with_ellipsis_beyond_min_font_size(): void
    {
        $text = trim(str_repeat('Lunghissimo nome di località ', 8));
        $text = mb_substr($text, 0, 200);

        $result = $this->fitText(
            $text,
            StageShareLayout::GRID_VALUE_BOX_WIDTH,
            StageShareLayout::GRID_VALUE_MAX_LINES,
            StageShareLayout::GRID_VALUE_FONT_SIZE,
            StageShareLayout::MIN_FONT_SIZE,
        );

        $this->assertCount(2, $result['lines']);
        $this->assertSame(StageShareLayout::MIN_FONT_SIZE, $result['fontSize']);
        $this->assertStringEndsWith('…', end($result['lines']));
    }

    public function test_german_labels(): void
    {
        [$layer, $stage] = $this->layerWithStage();

        $labels = (new ReflectionMethod(StageShareImageService::class, 'labels'))->invoke($this->service, 'de');
        $snapshot = $this->service->snapshot($layer, $stage, 'de');

        $this->assertSame(['from' => 'Start', 'to' => 'Ziel', 'distance' => 'Länge', 'ascent' => 'Höhenmeter'], $labels);
        $this->assertSame('Etappe 01', $snapshot['stage_label']);
        $this->assertSame('Gran-Sasso-Weg', $snapshot['layer_name']);
        // Lingua non supportata: ripiego italiano.
        $this->assertSame('Tappa 01', $this->service->snapshot($layer, $stage, 'xx')['stage_label']);

        // Composizione con le etichette tedesche e testi lunghi in ogni campo.
        $layer->forceFill(['name' => ['de' => 'Der sehr lange Pilgerweg der Heiligen Benedikt und Scholastika durch die Abruzzen']])->saveQuietly();
        $long = $this->stage($layer, [
            'ref' => '12bis',
            'from' => 'Santa Maria Capua Vetere – Stazione FS e piazzale antistante',
            'to' => 'San Benedetto dei Marsi – Chiesa di Santa Sabina e ruderi di Marruvium',
            'manual_data' => ['distance' => 34.75, 'ascent' => 1258],
        ], [[13.62, 42.00], [13.70, 42.05]]);

        $image = $this->compose($layer->fresh(), $long, 'de');
        $this->assertSame(1080, $image->width());
        $this->assertSame(1920, $image->height());
    }

    /**
     * renderLayers() riceve il bbox della tappa così com'è e il margine del
     * 30% per lato come `$marginRatio`: nessuna compensazione del 15% di
     * MapRenderService.
     */
    public function test_map_is_framed_on_the_stage_with_a_30_percent_margin(): void
    {
        [$layer, $stage] = $this->layerWithStage();
        $captured = null;
        $capturedRatio = null;

        $spy = $this->mock(MapRenderService::class);
        $spy->shouldReceive('renderLayers')->once()->andReturnUsing(function ($layers, $markers, $focusBbox, $app, $width, $height, $marginRatio = null) use (&$captured, &$capturedRatio) {
            $captured = $focusBbox;
            $capturedRatio = $marginRatio;

            return Image::canvas($width, $height, '#cfe3c4');
        });

        $service = app(StageShareImageService::class);
        $service->compose($layer, $stage, 'it', $service->snapshot($layer, $stage, 'it'));

        // Tappa del fixture: lon 13.90–14.00, lat 42.10–42.15.
        $this->assertEqualsWithDelta(13.90, $captured['xmin'], 1e-9);
        $this->assertEqualsWithDelta(14.00, $captured['xmax'], 1e-9);
        $this->assertEqualsWithDelta(42.10, $captured['ymin'], 1e-9);
        $this->assertEqualsWithDelta(42.15, $captured['ymax'], 1e-9);
        $this->assertSame(0.30, $capturedRatio);
        $this->assertSame(StageShareLayout::STAGE_FOCUS_MARGIN_RATIO, $capturedRatio);
    }

    /**
     * Un'icona sconosciuta è un errore di programmazione, non un'icona vuota.
     */
    public function test_unknown_icon_type_throws(): void
    {
        $icons = app(StageShareIcons::class);
        foreach (['from', 'to', 'distance', 'ascent'] as $type) {
            $this->assertSame(StageShareLayout::GRID_ICON_SIZE, imagesx($icons->icon($type)));
        }

        $this->expectException(InvalidArgumentException::class);
        $icons->icon('time');
    }

    /**
     * Il nome della tappa senza `ref` va a capo su due righe prima di
     * ridursi, come gli altri testi; oltre, si tronca con «…».
     */
    public function test_stage_label_without_ref_wraps_on_two_lines(): void
    {
        $fit = new ReflectionMethod(StageShareImageService::class, 'fitStageLabel');
        $long = 'Da Pacentro a Caramanico Terme per la Majella';

        $result = $fit->invoke($this->service, $long, StageShareLayout::STAGE_LABEL_MAX_LINES);
        $this->assertCount(2, $result['lines']);
        $this->assertSame(StageShareLayout::STAGE_LABEL_FONT_SIZE, $result['fontSize']);
        $this->assertStringEndsNotWith('…', $result['lines'][1]);

        $tooLong = trim(str_repeat($long.' ', 4));
        $truncated = $fit->invoke($this->service, $tooLong, StageShareLayout::STAGE_LABEL_MAX_LINES);
        $this->assertCount(2, $truncated['lines']);
        $this->assertSame(StageShareLayout::STAGE_LABEL_MIN_FONT_SIZE, $truncated['fontSize']);
        $this->assertStringEndsWith('…', $truncated['lines'][1]);

        // La composizione completa con il nome lungo resta nella tela.
        [$layer] = $this->layerWithStage();
        $stage = $this->stage($layer, ['from' => 'Pacentro', 'to' => 'Caramanico'], [[13.90, 42.10], [13.95, 42.12]], $long);
        $image = $this->compose($layer->fresh(), $stage, 'it');
        $this->assertSame(1920, $image->height());
    }

    /**
     * La firma del layout cambia quando cambia il valore di una costante
     * (oc:8702 review): niente bump manuale di VERSION per le misure.
     */
    public function test_layout_signature_changes_with_any_constant(): void
    {
        $constants = [
            StageShareLayout::class => (new ReflectionClass(StageShareLayout::class))->getConstants(),
            StageShareIcons::class => (new ReflectionClass(StageShareIcons::class))->getConstants(),
            StageShareText::class => (new ReflectionClass(StageShareText::class))->getConstants(),
        ];

        $this->assertSame(StageShareLayout::signature(), StageShareLayout::signature($constants));

        $changed = $constants;
        $changed[StageShareLayout::class]['MAP_BORDER_COLOR'] = '#000000';
        $this->assertNotSame(StageShareLayout::signature($constants), StageShareLayout::signature($changed));

        $changedIcon = $constants;
        $changedIcon[StageShareIcons::class]['PIN_DIAMETER'] = 0.6;
        $this->assertNotSame(StageShareLayout::signature($constants), StageShareLayout::signature($changedIcon));
    }

    /**
     * Sfondo, traduzioni e font entrano nella firma con il loro contenuto:
     * cambiare il file cambia la firma, spostarlo senza cambiarlo no.
     */
    public function test_layout_signature_hashes_file_contents(): void
    {
        $dir = sys_get_temp_dir().'/oc8702-signature-'.uniqid();
        mkdir($dir);
        $write = function (string $name, string $content) use ($dir): string {
            file_put_contents("{$dir}/{$name}", $content);

            return "{$dir}/{$name}";
        };

        try {
            $files = [
                'background' => $write('background.png', 'sfondo-1'),
                'lang.it' => $write('it.php', "<?php return ['from' => 'Partenza'];"),
            ];
            $constants = [StageShareLayout::class => ['FONT_BLACK' => $write('font.ttf', 'font-1'), 'MAP_BORDER' => 12]];
            $base = StageShareLayout::signature($constants, $files);

            // Etichetta tradotta cambiata.
            $write('it.php', "<?php return ['from' => 'Inizio'];");
            $this->assertNotSame($base, $afterLang = StageShareLayout::signature($constants, $files));

            // Sfondo cambiato.
            $write('background.png', 'sfondo-2');
            $this->assertNotSame($afterLang, $afterBackground = StageShareLayout::signature($constants, $files));

            // Font cambiato: conta il contenuto...
            $write('font.ttf', 'font-2');
            $this->assertNotSame($afterBackground, $afterFont = StageShareLayout::signature($constants, $files));

            // ...non il percorso assoluto.
            $moved = [StageShareLayout::class => ['FONT_BLACK' => $write('font-copy.ttf', 'font-2'), 'MAP_BORDER' => 12]];
            $this->assertSame($afterFont, StageShareLayout::signature($moved, $files));
        } finally {
            array_map('unlink', glob("{$dir}/*"));
            rmdir($dir);
        }

        // Di default la firma copre lo sfondo e le traduzioni di ogni lingua supportata.
        $defaults = StageShareLayout::signatureFiles();
        $this->assertSame(resource_path(StageShareLayout::BACKGROUND_PATH), $defaults['background']);
        foreach (StageShareLocale::SUPPORTED_LANGUAGES as $lang) {
            $this->assertFileExists($defaults["lang.{$lang}"]);
        }
    }

    /**
     * Formati e lingua condivisi da immagine, endpoint e pagina pubblica.
     */
    public function test_shared_formats_and_language_normalisation(): void
    {
        $this->assertSame('20,4 km', StageShareLocale::formatDistance(20.4));
        $this->assertSame('+1.258 m', StageShareLocale::formatAscent(1258));
        $this->assertNull(StageShareLocale::formatDistance(null));
        $this->assertNull(StageShareLocale::formatAscent(null));

        $this->assertSame('de', StageShareLocale::fromAcceptLanguage('de-DE,de;q=0.9,en;q=0.8'));
        $this->assertSame('pt', StageShareLocale::fromAcceptLanguage('pr'));
        $this->assertSame('it', StageShareLocale::fromAcceptLanguage('ja'));
        $this->assertSame('it', StageShareLocale::fromAcceptLanguage(null));
        $this->assertSame('fr', StageShareLocale::normalize(' FR '));
        $this->assertSame('it', StageShareLocale::normalize(null));
    }

    /**
     * Il logo del cammino si disegna così com'è: nessun cerchio né bordo
     * bianco, le parti trasparenti lasciano vedere lo sfondo.
     */
    public function test_layer_logo_is_drawn_as_is_without_white_circle(): void
    {
        $canvas = Image::canvas(StageShareLayout::CANVAS_WIDTH, 400, '#000000');
        $size = StageShareLayout::LOGO_BOX_SIZE;
        $logo = Image::canvas($size, $size);
        $logo->rectangle(80, 80, 159, 159, fn ($draw) => $draw->background('#ff0000'));

        (new ReflectionMethod(StageShareImageService::class, 'drawLayerLogo'))->invoke($this->service, $canvas, $logo, 0);

        $left = (int) ((StageShareLayout::CANVAS_WIDTH - $size) / 2);
        // Parte trasparente del logo, dentro il riquadro: resta lo sfondo, nessun bianco.
        foreach ([[$left + 3, 3], [$left + 40, (int) ($size / 2)], [$left + (int) ($size / 2), 20], [$left + $size - 3, $size - 3]] as [$x, $y]) {
            $this->assertSame([0, 0, 0], array_slice($canvas->pickColor($x, $y), 0, 3), "pixel {$x},{$y}");
        }
        // Parte piena del logo.
        $this->assertSame([255, 0, 0], array_slice($canvas->pickColor($left + 120, 120), 0, 3));
    }

    /**
     * Un logo più grande del riquadro si riduce per starci, con le proporzioni.
     */
    public function test_layer_logo_is_scaled_to_fit_the_box(): void
    {
        [$layer] = $this->layerWithStage();
        $layer->addMediaFromString(Image::canvas(800, 400, '#ff0000')->encode('png')->getEncoded())
            ->usingFileName('logo.png')
            ->toMediaCollection('logo');

        $logo = (new ReflectionMethod(StageShareImageService::class, 'loadLayerLogo'))->invoke($this->service, $layer->fresh());

        $this->assertSame(StageShareLayout::LOGO_BOX_SIZE, $logo->width());
        $this->assertSame((int) (StageShareLayout::LOGO_BOX_SIZE / 2), $logo->height());
    }

    /**
     * Il logo di Cammini d'Italia in fondo è l'icona (`icon`) dell'App del cammino.
     */
    public function test_cdi_logo_comes_from_the_app_icon(): void
    {
        [$layer, $stage] = $this->layerWithStage();
        $app = $this->appWithoutIcons($layer);
        $app->addMediaFromString($this->solidPng('#0000ff'))->usingFileName('icon.png')->toMediaCollection('icon');
        $app->addMediaFromString($this->solidPng('#00ff00'))->usingFileName('icon_small.png')->toMediaCollection('icon_small');

        $this->assertSame([0, 0, 255], $this->cdiLogoCenter($this->compose($layer, $stage, 'it')));
    }

    public function test_cdi_logo_falls_back_to_icon_small(): void
    {
        [$layer, $stage] = $this->layerWithStage();
        $app = $this->appWithoutIcons($layer);
        $app->addMediaFromString($this->solidPng('#00ff00'))->usingFileName('icon_small.png')->toMediaCollection('icon_small');

        $this->assertSame([0, 255, 0], $this->cdiLogoCenter($this->compose($layer, $stage, 'it')));
    }

    /**
     * Un'icona quadrata opaca (angoli bianchi intorno al logo tondo) si
     * ritaglia nel cerchio; una con gli angoli trasparenti resta com'è.
     */
    public function test_cdi_logo_is_clipped_to_a_circle_only_when_opaque(): void
    {
        [$layer] = $this->layerWithStage();
        $app = $this->appWithoutIcons($layer);
        $app->addMediaFromString($this->solidPng('#0000ff'))->usingFileName('icon.png')->toMediaCollection('icon');
        $load = new ReflectionMethod(StageShareImageService::class, 'loadCdiLogo');

        $opaque = $load->invoke($this->service, $app->fresh());
        $this->assertSame(127, imagecolorat($opaque->getCore(), 0, 0) >> 24 & 0x7F);
        $this->assertSame(0, imagecolorat($opaque->getCore(), (int) ($opaque->width() / 2), (int) ($opaque->height() / 2)) >> 24 & 0x7F);

        $transparent = Image::canvas(512, 512);
        $transparent->rectangle(0, 200, 511, 311, fn ($draw) => $draw->background('#0000ff'));
        $app->addMediaFromString($transparent->encode('png')->getEncoded())->usingFileName('icon.png')->toMediaCollection('icon');

        $logo = $load->invoke($this->service, $app->fresh());
        // Bordo della fascia, fuori da un cerchio: resta opaco perché non si ritaglia.
        $this->assertSame(0, imagecolorat($logo->getCore(), 0, (int) ($logo->height() / 2)) >> 24 & 0x7F);
    }

    /**
     * Senza icone leggibili il blocco si omette e lo si segnala nel log.
     */
    public function test_cdi_logo_is_omitted_with_a_warning_when_the_app_has_no_icon(): void
    {
        [$layer, $stage] = $this->layerWithStage();
        $app = $this->appWithoutIcons($layer);
        Log::spy();

        $image = $this->compose($layer, $stage, 'it');

        $background = Image::make(resource_path(StageShareLayout::BACKGROUND_PATH))->fit(StageShareLayout::CANVAS_WIDTH, StageShareLayout::CANVAS_HEIGHT);
        $this->assertSame($this->cdiLogoCenter($background), $this->cdiLogoCenter($image));
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => str_starts_with($message, '[oc:8702]') && ($context['app_id'] ?? null) === $app->id)->once();
    }

    /**
     * Stili della mappa approvati: tappa gialla 6 con bordo bianco 2, cammino
     * rosso 2 al 60%, marker da 13 con anello bianco di 2.
     */
    public function test_map_styles_and_markers_follow_the_layout(): void
    {
        [$layer, $stage] = $this->layerWithStage();
        $captured = [];

        $this->mock(MapRenderService::class)->shouldReceive('renderLayers')->once()->andReturnUsing(function ($layers, $markers, $focusBbox, $app, $width, $height) use (&$captured) {
            $captured = ['layers' => $layers, 'markers' => $markers];

            return Image::canvas($width, $height, '#cfe3c4');
        });

        $service = app(StageShareImageService::class);
        $service->compose($layer, $stage, 'it', $service->snapshot($layer, $stage, 'it'));

        [$route, $walked] = $captured['layers'];
        $this->assertSame(['#D93025', 2, 0.6], [$route['color'], $route['thickness'], $route['opacity']]);
        $this->assertSame(['#F2C200', 6, '#FFFFFF', 2], [$walked['color'], $walked['thickness'], $walked['outlineColor'], $walked['outlineThickness']]);
        $this->assertSame(
            [['start', 13, 2, '#2E7D32'], ['end', 13, 2, '#C62828']],
            array_map(fn ($m) => [$m['type'], $m['size'], $m['ringWidth'], $m['color']], $captured['markers'])
        );
    }

    /**
     * Un layer con app inesistente usa la prima App, ma lo segnala nel log.
     */
    public function test_missing_layer_app_falls_back_with_a_warning(): void
    {
        [$layer, $stage] = $this->layerWithStage();
        $layer->app_id = 999999999;
        Log::spy();

        $image = $this->compose($layer, $stage, 'it');

        $this->assertSame(1920, $image->height());
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => str_starts_with($message, '[oc:8702]') && ($context['app_id'] ?? null) === 999999999)->once();
    }
}

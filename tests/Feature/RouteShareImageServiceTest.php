<?php

namespace Tests\Feature;

use App\Models\EcTrack;
use App\Models\ValidatedEcTrack;
use App\Services\PassportShare\RouteShareImageService;
use App\Services\PassportShare\StageShareLayout;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Intervention\Image\Facades\Image;
use Tests\Feature\Helpers\FakesCertificationDisk;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack as WmEcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\Models\StoryShare\MapRenderService;
use Wm\WmPackage\Services\RolesAndPermissionsService;

/**
 * Immagine del cammino completato (oc:8703, vista 7 del wireframe): mappa con
 * il cammino in rosso, un pallino a ogni cambio di tappa e il ref delle tappe;
 * uscite solo con tutte le tappe validate col GPS.
 */
class RouteShareImageServiceTest extends TestCase
{
    use DatabaseTransactions, FakesCertificationDisk, LayerTestHelpers;

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
    }

    /**
     * Tappa con geometria nota, associata al layer con insert diretto su `layerables`.
     *
     * @param  list<array{0: float, 1: float}>  $points
     */
    private function stage(Layer $layer, ?string $ref, array $points): WmEcTrack
    {
        $track = EcTrack::factory()->create([
            'user_id' => $layer->user_id,
            'osmid' => null,
            'name' => ['it' => 'Tappa '.($ref ?? 'senza ref')],
            'properties' => array_filter(['ref' => $ref, 'manual_data' => ['distance' => 10.0]]),
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

        return $track;
    }

    /**
     * Cammino di due tappe contigue: 01 da A a B, 02 da B a C.
     *
     * @return array{0: Layer, 1: list<WmEcTrack>}
     */
    private function route(?string $secondRef = '02'): array
    {
        $layer = $this->createLayer($this->createUserWithRole('Validator')->id);
        $layer->forceFill(['name' => ['it' => 'Cammino del Gran Sasso']])->saveQuietly();
        $stages = [
            $this->stage($layer, '01', [[13.90, 42.10], [13.95, 42.12], [14.00, 42.15]]),
            $this->stage($layer, $secondRef, [[14.00, 42.15], [14.08, 42.20], [14.15, 42.22]]),
        ];

        return [$layer->fresh(), $stages];
    }

    private function validate(User $user, WmEcTrack $track, Layer $layer, string $source, string $at): void
    {
        ValidatedEcTrack::create([
            'user_id' => $user->id,
            'ec_track_id' => $track->id,
            'layer_id' => $layer->id,
            'source' => $source,
            'validated_at' => $at,
        ]);
    }

    /**
     * Snapshot minimo per comporre.
     *
     * @return array<string, mixed>
     */
    private function snapshot(?int $outings = null): array
    {
        return ['layer_name' => 'Cammino del Gran Sasso', 'completed_at' => '2026-09-30T10:00:00+02:00', 'stages_validated' => 2, 'stages_total' => 2, 'distance_km' => 20.0, 'outings' => $outings];
    }

    /**
     * Sostituisce il renderer della mappa e restituisce i suoi argomenti.
     *
     * @return \ArrayObject<string, mixed>
     */
    private function captureMap(): \ArrayObject
    {
        $captured = new \ArrayObject;
        $mock = $this->mock(MapRenderService::class);
        $mock->shouldReceive('renderLayers')->andReturnUsing(function (...$args) use ($captured) {
            [$captured['layers'], $captured['markers'], , , , , , $captured['labels']] = $args + [7 => []];

            return Image::canvas(StageShareLayout::MAP_WIDTH, StageShareLayout::MAP_HEIGHT, '#ffffff');
        });

        return $captured;
    }

    public function test_route_is_a_single_red_line(): void
    {
        [$layer] = $this->route();
        $map = $this->captureMap();

        app(RouteShareImageService::class)->compose($layer, 'it', $this->snapshot());

        $this->assertCount(1, $map['layers']);
        $this->assertSame(RouteShareImageService::ROUTE_COLOR, $map['layers'][0]['color']);
    }

    public function test_a_dot_at_every_stage_change_without_duplicates(): void
    {
        [$layer] = $this->route();
        $map = $this->captureMap();

        app(RouteShareImageService::class)->compose($layer, 'it', $this->snapshot());

        // A, B (condiviso fra le due tappe), C.
        $points = array_map(fn ($m) => [round($m['lon'], 4), round($m['lat'], 4)], $map['markers']);
        $this->assertEqualsCanonicalizing([[13.9, 42.1], [14.0, 42.15], [14.15, 42.22]], $points);
        $this->assertSame(RouteShareImageService::DOT_COLOR, $map['markers'][0]['color']);
    }

    public function test_labels_show_the_stage_ref_translated(): void
    {
        [$layer] = $this->route();
        $map = $this->captureMap();

        app(RouteShareImageService::class)->compose($layer, 'de', $this->snapshot());

        $this->assertEqualsCanonicalizing(['Etappe 01', 'Etappe 02'], array_column($map['labels'], 'text'));
        $this->assertSame(RouteShareImageService::ROUTE_COLOR, $map['labels'][0]['color']);
    }

    public function test_stage_without_ref_has_no_label(): void
    {
        [$layer] = $this->route(secondRef: null);
        $map = $this->captureMap();

        app(RouteShareImageService::class)->compose($layer, 'it', $this->snapshot());

        $this->assertSame(['Tappa 01'], array_column($map['labels'], 'text'));
    }

    public function test_outings_count_distinct_days_when_all_stages_are_gps(): void
    {
        [$layer, $stages] = $this->route();
        $walker = User::factory()->create();
        $this->validate($walker, $stages[0], $layer, ValidatedEcTrack::SOURCE_GPS, '2026-09-28 09:00:00');
        $this->validate($walker, $stages[1], $layer, ValidatedEcTrack::SOURCE_GPS, '2026-09-29 18:00:00');

        $snapshot = app(RouteShareImageService::class)->snapshot($walker, $layer, 'it');

        $this->assertSame(2, $snapshot['outings']);
    }

    public function test_outings_are_null_with_a_manual_stage(): void
    {
        [$layer, $stages] = $this->route();
        $walker = User::factory()->create();
        $this->validate($walker, $stages[0], $layer, ValidatedEcTrack::SOURCE_GPS, '2026-09-28 09:00:00');
        $this->validate($walker, $stages[1], $layer, ValidatedEcTrack::SOURCE_MANUAL, '2026-09-29 18:00:00');

        $snapshot = app(RouteShareImageService::class)->snapshot($walker, $layer, 'it');

        $this->assertNull($snapshot['outings']);
    }

    public function test_image_is_1080x1920_with_and_without_outings(): void
    {
        [$layer] = $this->route();
        $service = app(RouteShareImageService::class);

        foreach ([null, 3] as $outings) {
            $image = Image::make($service->compose($layer, 'it', $this->snapshot($outings))->getEncoded());
            $this->assertSame([StageShareLayout::CANVAS_WIDTH, StageShareLayout::CANVAS_HEIGHT], [$image->width(), $image->height()]);
        }
    }

    public function test_completion_date_is_shown_in_italian_time(): void
    {
        // 23:30 UTC dell'11 aprile è già il 12 aprile in Italia (ora legale, +2).
        $this->assertSame('12 aprile 2026', RouteShareImageService::formatDate('2026-04-11T23:30:00+00:00', 'it'));
    }

    public function test_outings_count_days_in_italian_time(): void
    {
        [$layer, $stages] = $this->route();
        $walker = User::factory()->create();
        // Stesso giorno in Italia (12 aprile), due giorni diversi in UTC.
        $this->validate($walker, $stages[0], $layer, ValidatedEcTrack::SOURCE_GPS, '2026-04-11 23:30:00');
        $this->validate($walker, $stages[1], $layer, ValidatedEcTrack::SOURCE_GPS, '2026-04-12 08:00:00');

        $snapshot = app(RouteShareImageService::class)->snapshot($walker, $layer, 'it');

        $this->assertSame(1, $snapshot['outings']);
    }

    public function test_layout_signature_covers_the_calendar_icon(): void
    {
        $this->assertArrayHasKey(\App\Services\PassportShare\RouteShareIcons::class, RouteShareImageService::signatureConstants());
        $this->assertArrayHasKey(RouteShareImageService::class, RouteShareImageService::signatureConstants());
    }
}

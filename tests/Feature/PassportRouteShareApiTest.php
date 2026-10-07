<?php

namespace Tests\Feature;

use App\Models\EcTrack;
use App\Models\PassportShare;
use App\Models\ValidatedEcTrack;
use App\Services\PassportShare\RouteShareImageService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Intervention\Image\Facades\Image;
use RuntimeException;
use Tests\Feature\Helpers\FakesCertificationDisk;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack as WmEcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\RolesAndPermissionsService;

/**
 * POST /api/layer/{layer}/share-image (oc:8703): immagine di condivisione del
 * cammino completato. I tile della mappa sono finti (Http::fake).
 */
class PassportRouteShareApiTest extends TestCase
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
     * Tappa con geometria nota, associata al layer con insert diretto su
     * `layerables`, come in PassportStageShareApiTest.
     *
     * @param  list<array{0: float, 1: float}>  $points
     */
    private function stage(Layer $layer, string $ref, array $points, ?float $distance): WmEcTrack
    {
        $properties = ['ref' => $ref];
        if ($distance !== null) {
            $properties['manual_data'] = ['distance' => $distance];
        }

        $track = EcTrack::factory()->create([
            'user_id' => $layer->user_id,
            'osmid' => null,
            'name' => ['it' => "Tappa {$ref}"],
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

    private function validate(User $user, WmEcTrack $track, Layer $layer, string $at = '2026-09-30 10:00:00'): void
    {
        ValidatedEcTrack::create([
            'user_id' => $user->id,
            'ec_track_id' => $track->id,
            'layer_id' => $layer->id,
            'source' => ValidatedEcTrack::SOURCE_MANUAL,
            'validated_at' => $at,
        ]);
    }

    /**
     * Cammino di due tappe e camminatore; con `$completed` le valida entrambe.
     *
     * @return array{0: Layer, 1: User, 2: list<WmEcTrack>}
     */
    private function route(bool $completed = true, ?float $secondDistance = 15.1): array
    {
        $owner = $this->createUserWithRole('Validator');
        $layer = $this->createLayer($owner->id);
        $layer->forceFill(['name' => ['it' => 'Cammino del Gran Sasso', 'de' => 'Gran-Sasso-Weg']])->saveQuietly();
        $stages = [
            $this->stage($layer, '01', [[13.90, 42.10], [13.95, 42.12], [14.00, 42.15]], 20.4),
            $this->stage($layer, '02', [[14.00, 42.15], [14.08, 42.20], [14.15, 42.22]], $secondDistance),
        ];
        $walker = User::factory()->create();
        $this->validate($walker, $stages[0], $layer, '2026-09-28 10:00:00');
        if ($completed) {
            $this->validate($walker, $stages[1], $layer, '2026-09-30 10:00:00');
        }

        return [$layer->fresh(), $walker, $stages];
    }

    /**
     * Servizio dell'immagine finto: `snapshot()` resta quello vero,
     * `compose()` restituisce un PNG minuscolo e si attende `$composeTimes` volte.
     */
    private function spyImageService(int $composeTimes): void
    {
        $real = app(RouteShareImageService::class);
        $mock = $this->mock(RouteShareImageService::class);
        $mock->shouldReceive('snapshot')->andReturnUsing(fn (...$args) => $real->snapshot(...$args));
        $mock->shouldReceive('compose')
            ->times($composeTimes)
            ->andReturnUsing(fn () => Image::canvas(10, 10, '#ff0000')->encode('png'));
    }

    public function test_without_token_returns_401(): void
    {
        [$layer] = $this->route();

        $this->postJson("/api/layer/{$layer->id}/share-image")->assertStatus(401);
    }

    public function test_not_completed_route_returns_403(): void
    {
        [$layer, $walker] = $this->route(completed: false);

        $this->actingAs($walker, 'api')->postJson("/api/layer/{$layer->id}/share-image")
            ->assertStatus(403)->assertJsonStructure(['error']);
    }

    public function test_route_without_stages_returns_403(): void
    {
        $layer = $this->createLayer($this->createUserWithRole('Validator')->id);
        $walker = User::factory()->create();

        $this->actingAs($walker, 'api')->postJson("/api/layer/{$layer->id}/share-image")->assertStatus(403);
    }

    public function test_completed_route_returns_image_and_share_url(): void
    {
        [$layer, $walker] = $this->route();

        // Il client di test di Symfony manda `Accept-Language: en-us` di default.
        $response = $this->actingAs($walker, 'api')->postJson("/api/layer/{$layer->id}/share-image", [], ['Accept-Language' => 'it']);

        $response->assertOk()->assertJsonStructure(['image_url', 'share_url']);
        $share = PassportShare::where('user_id', $walker->id)->where('shareable_type', $layer->getMorphClass())->where('shareable_id', $layer->id)->firstOrFail();
        $this->assertSame(route('share.passport', ['uuid' => $share->uuid]), $response->json('share_url'));
        $this->assertSame($share->getFirstMedia('share_image')->getUrl(), $response->json('image_url'));
        $this->assertSame('Cammino del Gran Sasso', $share->snapshot['layer_name']);
        $this->assertSame(2, $share->snapshot['stages_validated']);
        $this->assertSame(2, $share->snapshot['stages_total']);
        $this->assertEqualsWithDelta(35.5, $share->snapshot['distance_km'], 0.001);
        $this->assertStringStartsWith('2026-09-30', $share->snapshot['completed_at']);
        $this->assertNotNull($share->snapshot['shared_at']);
    }

    public function test_composition_failure_returns_500(): void
    {
        [$layer, $walker] = $this->route();
        $mock = $this->mock(RouteShareImageService::class);
        $mock->shouldReceive('snapshot')->andReturn(['layer_name' => 'Cammino', 'completed_at' => null, 'stages_validated' => 2, 'stages_total' => 2, 'distance_km' => null]);
        $mock->shouldReceive('compose')->andThrow(new RuntimeException('tile non scaricabili'));

        $this->actingAs($walker, 'api')->postJson("/api/layer/{$layer->id}/share-image")
            ->assertStatus(500)->assertJsonStructure(['error']);
    }

    public function test_second_call_with_same_data_does_not_recompose(): void
    {
        [$layer, $walker] = $this->route();
        $url = "/api/layer/{$layer->id}/share-image";
        $this->spyImageService(composeTimes: 1);

        $first = $this->actingAs($walker, 'api')->postJson($url)->assertOk();
        $second = $this->actingAs($walker, 'api')->postJson($url)->assertOk();

        $this->assertSame($first->json('image_url'), $second->json('image_url'));
        $this->assertSame($first->json('share_url'), $second->json('share_url'));
    }

    public function test_new_validation_date_changes_fingerprint(): void
    {
        [$layer, $walker, $stages] = $this->route();
        $url = "/api/layer/{$layer->id}/share-image";
        $this->spyImageService(composeTimes: 2);

        $this->actingAs($walker, 'api')->postJson($url)->assertOk();
        DB::table('validated_ec_tracks')
            ->where('user_id', $walker->id)
            ->where('ec_track_id', $stages[0]->id)
            ->update(['validated_at' => '2026-10-02 09:00:00']);
        $this->actingAs($walker, 'api')->postJson($url)->assertOk();

        $share = PassportShare::where('user_id', $walker->id)->where('shareable_type', (new Layer)->getMorphClass())->firstOrFail();
        $this->assertStringStartsWith('2026-10-02', $share->snapshot['completed_at']);
    }

    public function test_snapshot_omits_distance_when_a_stage_has_zero(): void
    {
        [$layer, $walker] = $this->route(secondDistance: null);

        $this->actingAs($walker, 'api')->postJson("/api/layer/{$layer->id}/share-image")->assertOk();

        $share = PassportShare::where('user_id', $walker->id)->where('shareable_type', (new Layer)->getMorphClass())->firstOrFail();
        $this->assertNull($share->snapshot['distance_km']);
    }

    public function test_rate_limit_is_separate_from_stage_share(): void
    {
        [$layer, $walker, $stages] = $this->route();
        $this->spyImageService(composeTimes: 1);
        $stageMock = $this->mock(\App\Services\PassportShare\StageShareImageService::class);
        $stageMock->shouldReceive('snapshot')->andReturn(['layer_name' => 'Cammino', 'stage_label' => 'Tappa 01', 'from' => null, 'to' => null, 'distance_km' => null, 'ascent_m' => null]);
        $stageMock->shouldReceive('compose')->andReturnUsing(fn () => Image::canvas(10, 10, '#ff0000')->encode('png'));

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($walker, 'api')->postJson("/api/layer/{$layer->id}/stage/{$stages[0]->id}/share-image")->assertOk();
        }
        $this->actingAs($walker, 'api')->postJson("/api/layer/{$layer->id}/stage/{$stages[0]->id}/share-image")->assertStatus(429);

        $this->actingAs($walker, 'api')->postJson("/api/layer/{$layer->id}/share-image")->assertOk();
    }

    public function test_deleting_layer_removes_route_share_and_media(): void
    {
        [$layer, $walker] = $this->route();
        $this->actingAs($walker, 'api')->postJson("/api/layer/{$layer->id}/share-image")->assertOk();
        $share = PassportShare::where('user_id', $walker->id)->where('shareable_type', (new Layer)->getMorphClass())->firstOrFail();
        $media = $share->getFirstMedia('share_image');

        $layer->delete();

        $this->assertDatabaseMissing('passport_shares', ['id' => $share->id]);
        $this->assertDatabaseMissing('media', ['id' => $media->id]);
    }

    public function test_deleting_user_removes_route_share_and_media(): void
    {
        [$layer, $walker] = $this->route();
        $this->actingAs($walker, 'api')->postJson("/api/layer/{$layer->id}/share-image")->assertOk();
        $share = PassportShare::where('user_id', $walker->id)->where('shareable_type', (new Layer)->getMorphClass())->firstOrFail();
        $media = $share->getFirstMedia('share_image');

        \App\Models\User::findOrFail($walker->id)->delete();

        $this->assertDatabaseMissing('passport_shares', ['id' => $share->id]);
        $this->assertDatabaseMissing('media', ['id' => $media->id]);
    }
}

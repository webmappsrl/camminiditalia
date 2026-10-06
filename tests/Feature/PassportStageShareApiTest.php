<?php

namespace Tests\Feature;

use App\Models\EcTrack;
use App\Models\PassportStageShare;
use App\Models\ValidatedEcTrack;
use App\Services\PassportShare\StageShareImageService;
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
 * POST /api/layer/{layer}/stage/{track}/share-image (oc:8702).
 * I tile della mappa sono finti (Http::fake).
 */
class PassportStageShareApiTest extends TestCase
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

    private function stage(Layer $layer): WmEcTrack
    {
        $track = EcTrack::factory()->create([
            'user_id' => $layer->user_id,
            'osmid' => null,
            'name' => ['it' => 'Da Pacentro a Caramanico'],
            'properties' => ['ref' => '01', 'from' => 'Pacentro', 'to' => 'Caramanico', 'manual_data' => ['distance' => 20.4, 'ascent' => 358]],
        ]);

        $wkt = 'MULTILINESTRING Z((13.90 42.10 0, 13.95 42.12 0, 14.00 42.15 0))';
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

    private function validate(User $user, WmEcTrack $track, Layer $layer): void
    {
        ValidatedEcTrack::create([
            'user_id' => $user->id,
            'ec_track_id' => $track->id,
            'layer_id' => $layer->id,
            'source' => ValidatedEcTrack::SOURCE_MANUAL,
            'validated_at' => '2026-09-30 10:00:00',
        ]);
    }

    /**
     * @return array{0: Layer, 1: WmEcTrack, 2: User}
     */
    private function validatedStage(): array
    {
        $owner = $this->createUserWithRole('Validator');
        $layer = $this->createLayer($owner->id);
        $layer->forceFill(['name' => ['it' => 'Cammino del Gran Sasso', 'de' => 'Gran-Sasso-Weg']])->saveQuietly();
        $track = $this->stage($layer);
        $walker = User::factory()->create();
        $this->validate($walker, $track, $layer);

        return [$layer->fresh(), $track, $walker];
    }

    public function test_returns_image_and_share_url(): void
    {
        [$layer, $track, $walker] = $this->validatedStage();

        $response = $this->actingAs($walker, 'api')->postJson("/api/layer/{$layer->id}/stage/{$track->id}/share-image");

        $response->assertOk()->assertJsonStructure(['image_url', 'share_url']);
        $share = PassportStageShare::where('user_id', $walker->id)->where('ec_track_id', $track->id)->firstOrFail();
        $this->assertSame(route('share.passport-stage', ['uuid' => $share->uuid]), $response->json('share_url'));
        $this->assertSame($share->getFirstMedia('share_image')->getUrl(), $response->json('image_url'));
        $this->assertNotNull($share->snapshot);
        $this->assertSame('Pacentro', $share->snapshot['from']);
        $this->assertNotNull($share->snapshot['shared_at']);
    }

    public function test_second_call_returns_same_share_url(): void
    {
        [$layer, $track, $walker] = $this->validatedStage();
        $url = "/api/layer/{$layer->id}/stage/{$track->id}/share-image";

        $first = $this->actingAs($walker, 'api')->postJson($url)->assertOk();
        $second = $this->actingAs($walker, 'api')->postJson($url)->assertOk();

        $this->assertSame($first->json('share_url'), $second->json('share_url'));
        $this->assertSame(1, PassportStageShare::where('user_id', $walker->id)->count());
    }

    public function test_not_validated_stage_returns_403(): void
    {
        [$layer, $track] = $this->validatedStage();
        $other = User::factory()->create();

        $this->actingAs($other, 'api')->postJson("/api/layer/{$layer->id}/stage/{$track->id}/share-image")
            ->assertStatus(403)->assertJsonStructure(['error']);
        $this->assertSame(0, PassportStageShare::count());
    }

    public function test_stage_of_another_layer_returns_404(): void
    {
        [$layer, , $walker] = $this->validatedStage();
        [, $foreignTrack] = $this->validatedStage();

        $this->actingAs($walker, 'api')->postJson("/api/layer/{$layer->id}/stage/{$foreignTrack->id}/share-image")
            ->assertStatus(404)->assertJsonStructure(['error']);
    }

    public function test_without_token_returns_401(): void
    {
        [$layer, $track] = $this->validatedStage();

        $this->postJson("/api/layer/{$layer->id}/stage/{$track->id}/share-image")->assertStatus(401);
    }

    public function test_accept_language_de_saves_german_snapshot(): void
    {
        [$layer, $track, $walker] = $this->validatedStage();

        $this->actingAs($walker, 'api')
            ->postJson("/api/layer/{$layer->id}/stage/{$track->id}/share-image", [], ['Accept-Language' => 'de-DE'])
            ->assertOk();

        $share = PassportStageShare::where('user_id', $walker->id)->firstOrFail();
        $this->assertSame('Gran-Sasso-Weg', $share->snapshot['layer_name']);
        $this->assertSame('Etappe 01', $share->snapshot['stage_label']);
    }

    public function test_composition_failure_returns_500(): void
    {
        [$layer, $track, $walker] = $this->validatedStage();
        $mock = $this->mock(StageShareImageService::class);
        $mock->shouldReceive('snapshot')->andReturn(['layer_name' => 'Cammino', 'stage_label' => 'Tappa 01']);
        $mock->shouldReceive('compose')->andThrow(new RuntimeException('tile non scaricabili'));

        $this->actingAs($walker, 'api')->postJson("/api/layer/{$layer->id}/stage/{$track->id}/share-image")
            ->assertStatus(500)->assertJsonStructure(['error']);
    }

    /**
     * Servizio dell'immagine finto: `snapshot()` resta quello vero (calcolato
     * una volta sola per richiesta), `compose()` restituisce un PNG minuscolo
     * e si attende `$composeTimes` volte.
     */
    private function spyImageService(int $composeTimes, ?int $snapshotTimes = null): void
    {
        $real = app(StageShareImageService::class);
        $mock = $this->mock(StageShareImageService::class);

        $snapshot = $mock->shouldReceive('snapshot')->andReturnUsing(fn (...$args) => $real->snapshot(...$args));
        if ($snapshotTimes !== null) {
            $snapshot->times($snapshotTimes);
        }

        $mock->shouldReceive('compose')
            ->times($composeTimes)
            ->andReturnUsing(fn () => Image::canvas(10, 10, '#ff0000')->encode('png'));
    }

    public function test_image_file_name_uses_share_uuid(): void
    {
        [$layer, $track, $walker] = $this->validatedStage();

        $this->actingAs($walker, 'api')->postJson("/api/layer/{$layer->id}/stage/{$track->id}/share-image")->assertOk();

        $share = PassportStageShare::where('user_id', $walker->id)->firstOrFail();
        $this->assertSame("passport-stage-{$share->uuid}.png", $share->getFirstMedia('share_image')->file_name);
    }

    public function test_snapshot_is_computed_once_per_request(): void
    {
        [$layer, $track, $walker] = $this->validatedStage();
        $this->spyImageService(composeTimes: 1, snapshotTimes: 1);

        $this->actingAs($walker, 'api')->postJson("/api/layer/{$layer->id}/stage/{$track->id}/share-image")->assertOk();
    }

    public function test_second_call_with_same_data_does_not_recompose(): void
    {
        [$layer, $track, $walker] = $this->validatedStage();
        $url = "/api/layer/{$layer->id}/stage/{$track->id}/share-image";
        $this->spyImageService(composeTimes: 1);

        $first = $this->actingAs($walker, 'api')->postJson($url)->assertOk();
        $second = $this->actingAs($walker, 'api')->postJson($url)->assertOk();

        $this->assertSame($first->json('image_url'), $second->json('image_url'));
        $this->assertSame($first->json('share_url'), $second->json('share_url'));
        $share = PassportStageShare::where('user_id', $walker->id)->firstOrFail();
        $this->assertIsString($share->snapshot['fingerprint']);
    }

    public function test_changed_language_regenerates_the_image(): void
    {
        [$layer, $track, $walker] = $this->validatedStage();
        $url = "/api/layer/{$layer->id}/stage/{$track->id}/share-image";
        $this->spyImageService(composeTimes: 2);

        $this->actingAs($walker, 'api')->postJson($url, [], ['Accept-Language' => 'it'])->assertOk();
        $this->actingAs($walker, 'api')->postJson($url, [], ['Accept-Language' => 'de'])->assertOk();

        $share = PassportStageShare::where('user_id', $walker->id)->firstOrFail();
        $this->assertSame('de', $share->snapshot['lang']);
    }

    public function test_touched_track_regenerates_the_image(): void
    {
        [$layer, $track, $walker] = $this->validatedStage();
        $url = "/api/layer/{$layer->id}/stage/{$track->id}/share-image";
        $this->spyImageService(composeTimes: 2);

        $this->actingAs($walker, 'api')->postJson($url)->assertOk();
        DB::table('ec_tracks')->where('id', $track->id)->update(['updated_at' => now()->addMinute()]);
        $this->actingAs($walker, 'api')->postJson($url)->assertOk();
    }

    public function test_touched_layer_regenerates_the_image(): void
    {
        [$layer, $track, $walker] = $this->validatedStage();
        $url = "/api/layer/{$layer->id}/stage/{$track->id}/share-image";
        $this->spyImageService(composeTimes: 2);

        $this->actingAs($walker, 'api')->postJson($url)->assertOk();
        DB::table('layers')->where('id', $layer->id)->update(['updated_at' => now()->addMinute()]);
        $this->actingAs($walker, 'api')->postJson($url)->assertOk();
    }

    public function test_missing_image_regenerates_even_with_same_fingerprint(): void
    {
        [$layer, $track, $walker] = $this->validatedStage();
        $url = "/api/layer/{$layer->id}/stage/{$track->id}/share-image";
        $this->spyImageService(composeTimes: 2);

        $this->actingAs($walker, 'api')->postJson($url)->assertOk();
        PassportStageShare::where('user_id', $walker->id)->firstOrFail()->clearMediaCollection('share_image');
        $this->actingAs($walker, 'api')->postJson($url)->assertOk();
    }

    /**
     * PNG minuscolo per i media di layer e App.
     */
    private function png(string $color = '#0000ff'): string
    {
        return Image::canvas(20, 20, $color)->encode('png')->getEncoded();
    }

    /**
     * Due chiamate con `$between` in mezzo: compose atteso `$composeTimes` volte.
     */
    private function shareTwice(int $composeTimes, callable $between): void
    {
        [$layer, $track, $walker] = $this->validatedStage();
        $url = "/api/layer/{$layer->id}/stage/{$track->id}/share-image";
        $this->spyImageService(composeTimes: $composeTimes);

        $this->actingAs($walker, 'api')->postJson($url)->assertOk();
        $between($layer);
        $this->actingAs($walker, 'api')->postJson($url)->assertOk();
    }

    public function test_unchanged_data_with_logo_icons_and_other_stages_hits_the_cache(): void
    {
        [$layer, $track, $walker] = $this->validatedStage();
        $this->stage($layer);
        $layer->addMediaFromString($this->png())->usingFileName('logo.png')->toMediaCollection('logo');
        App::findOrFail($layer->app_id)->addMediaFromString($this->png())->usingFileName('icon.png')->toMediaCollection('icon');
        $url = "/api/layer/{$layer->id}/stage/{$track->id}/share-image";
        $this->spyImageService(composeTimes: 1);

        $this->actingAs($walker, 'api')->postJson($url)->assertOk();
        $this->actingAs($walker, 'api')->postJson($url)->assertOk();
    }

    public function test_replaced_layer_logo_regenerates_the_image(): void
    {
        $this->shareTwice(2, fn (Layer $layer) => $layer->addMediaFromString($this->png('#ff0000'))->usingFileName('logo.png')->toMediaCollection('logo'));
    }

    public function test_replaced_app_icon_regenerates_the_image(): void
    {
        $this->shareTwice(2, fn (Layer $layer) => App::findOrFail($layer->app_id)->addMediaFromString($this->png('#00ff00'))->usingFileName('icon.png')->toMediaCollection('icon'));
    }

    public function test_replaced_app_icon_small_regenerates_the_image(): void
    {
        $this->shareTwice(2, fn (Layer $layer) => App::findOrFail($layer->app_id)->addMediaFromString($this->png('#00ff00'))->usingFileName('icon_small.png')->toMediaCollection('icon_small'));
    }

    public function test_touched_other_stage_of_the_layer_regenerates_the_image(): void
    {
        [$layer, $track, $walker] = $this->validatedStage();
        $other = $this->stage($layer);
        $url = "/api/layer/{$layer->id}/stage/{$track->id}/share-image";
        $this->spyImageService(composeTimes: 2);

        $this->actingAs($walker, 'api')->postJson($url)->assertOk();
        DB::table('ec_tracks')->where('id', $other->id)->update(['updated_at' => now()->addMinute()]);
        $this->actingAs($walker, 'api')->postJson($url)->assertOk();
    }

    public function test_added_stage_regenerates_the_image(): void
    {
        $this->shareTwice(2, fn (Layer $layer) => $this->stage($layer));
    }

    /**
     * Con la cache valida l'immagine non si ricompone, ma `shared_at`
     * diventa la data dell'ultima condivisione (oc:8702 review).
     */
    public function test_cache_hit_updates_shared_at_only(): void
    {
        [$layer, $track, $walker] = $this->validatedStage();
        $url = "/api/layer/{$layer->id}/stage/{$track->id}/share-image";
        $this->spyImageService(composeTimes: 1);

        $this->travelTo('2026-10-01 10:00:00');
        $first = $this->actingAs($walker, 'api')->postJson($url)->assertOk();
        $before = PassportStageShare::where('user_id', $walker->id)->firstOrFail();

        $this->travelTo('2026-10-05 18:30:00');
        $second = $this->actingAs($walker, 'api')->postJson($url)->assertOk();
        $after = $before->fresh();

        $this->assertSame($first->json('image_url'), $second->json('image_url'));
        $this->assertSame($before->getFirstMedia('share_image')->id, $after->getFirstMedia('share_image')->id);
        $this->assertSame('2026-10-01T10:00:00+00:00', $before->snapshot['shared_at']);
        $this->assertSame('2026-10-05T18:30:00+00:00', $after->snapshot['shared_at']);
        $this->assertSame($before->snapshot['fingerprint'], $after->snapshot['fingerprint']);
        $this->assertSame(collect($before->snapshot)->except('shared_at')->all(), collect($after->snapshot)->except('shared_at')->all());
    }

    /**
     * Cancellare la tappa, il layer o l'utente cancella le condivisioni via
     * Eloquent: con il solo cascade SQL resterebbero orfani riga `media` e
     * file dell'immagine.
     *
     * @return array<string, array{0: string}>
     */
    public static function deletedOwners(): array
    {
        return ['tappa' => ['track'], 'layer' => ['layer'], 'utente' => ['user']];
    }

    /**
     * @dataProvider deletedOwners
     */
    public function test_deleting_owner_removes_share_and_its_media(string $owner): void
    {
        [$layer, $track, $walker] = $this->validatedStage();
        $this->actingAs($walker, 'api')->postJson("/api/layer/{$layer->id}/stage/{$track->id}/share-image")->assertOk();
        $share = PassportStageShare::where('user_id', $walker->id)->firstOrFail();
        $media = $share->getFirstMedia('share_image');
        $this->assertTrue($media->exists);

        match ($owner) {
            'track' => \App\Models\EcTrack::findOrFail($track->id)->delete(),
            'layer' => $layer->delete(),
            'user' => \App\Models\User::findOrFail($walker->id)->delete(),
        };

        $this->assertDatabaseMissing('passport_stage_shares', ['id' => $share->id]);
        $this->assertDatabaseMissing('media', ['id' => $media->id]);
    }
}

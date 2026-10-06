<?php

namespace Tests\Feature;

use App\Models\EcTrack;
use App\Models\ValidatedEcTrack;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack as WmEcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class StageProgressApiTest extends TestCase
{
    use DatabaseTransactions, LayerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        RolesAndPermissionsService::seedDatabase();

        if (App::count() === 0) {
            App::factory()->create();
        }
    }

    /**
     * Insert diretto su `layerables`: `attach()` farebbe scattare
     * LayerableObserver (oc:8080), che cambierebbe l'ownership della tappa.
     */
    private function track(int $ownerId, Layer $layer, array $distance = []): WmEcTrack
    {
        $track = EcTrack::factory()->create([
            'user_id' => $ownerId,
            'properties' => $distance === [] ? [] : ['dem_data' => ['distance' => $distance[0]]],
        ]);

        DB::table('layerables')->insert([
            'layer_id' => $layer->id,
            'layerable_type' => (new (config('wm-package.ec_track_model')))->getMorphClass(),
            'layerable_id' => $track->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $track;
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

    public function test_progress_without_auth_returns_401(): void
    {
        $layer = $this->createLayer();

        $this->getJson("/api/layer/{$layer->id}/progress")->assertStatus(401);
    }

    public function test_passport_without_auth_returns_401(): void
    {
        $this->getJson('/api/passport')->assertStatus(401);
    }

    public function test_progress_returns_expected_shape(): void
    {
        $owner = $this->createUserWithRole('Validator');
        $walker = User::factory()->create();
        $layer = $this->createLayer($owner->id);
        $done = $this->track($owner->id, $layer, [10.04]);
        $todo = $this->track($owner->id, $layer, [20]);
        $this->validate($walker, $done, $layer);

        $response = $this->actingAs($walker, 'api')->getJson("/api/layer/{$layer->id}/progress");

        $response->assertOk()
            ->assertExactJsonStructure([
                'layer_id', 'validated', 'total', 'percentage', 'completed', 'km_validated', 'km_total',
                'tracks' => ['*' => ['id', 'name', 'distance', 'status', 'progress', 'validated_at', 'source', 'ref', 'from', 'to', 'ascent', 'descent', 'image', 'shareable']],
            ])
            ->assertJson([
                'layer_id' => $layer->id,
                'validated' => 1,
                'total' => 2,
                'percentage' => 50,
                'completed' => false,
                'km_validated' => 10.0,
                'km_total' => 30.0,
                'tracks' => [
                    ['id' => $done->id, 'status' => 'validated', 'progress' => 100, 'source' => 'manual'],
                    ['id' => $todo->id, 'status' => 'not_validated', 'progress' => 0, 'validated_at' => null, 'source' => null],
                ],
            ]);

        $this->assertStringContainsString('"progress":100', $response->getContent());
        $this->assertStringContainsString('"progress":0', $response->getContent());
    }

    public function test_progress_includes_tracks_of_other_owners_and_never_not_validatable(): void
    {
        $owner = $this->createUserWithRole('Validator');
        $stranger = $this->createUserWithRole('Validator');
        $walker = User::factory()->create();
        $layer = $this->createLayer($owner->id);
        $otherLayer = $this->createLayer($stranger->id);
        $own = $this->track($owner->id, $layer, [10]);
        $foreign = $this->track($stranger->id, $layer, [5]);
        $this->track($stranger->id, $layer, [2]);
        DB::table('layerables')->insert([
            'layer_id' => $otherLayer->id,
            'layerable_type' => (new (config('wm-package.ec_track_model')))->getMorphClass(),
            'layerable_id' => $foreign->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->validate($walker, $own, $layer);
        $this->validate($walker, $foreign, $otherLayer);

        $progress = $this->actingAs($walker, 'api')->getJson("/api/layer/{$layer->id}/progress");
        $passport = $this->actingAs($walker, 'api')->getJson('/api/passport');

        $progress->assertOk()->assertJson([
            'validated' => 2, 'total' => 3, 'percentage' => 66,
            'km_validated' => 15.0, 'km_total' => 17.0,
        ]);
        $this->assertSame(['validated', 'validated', 'not_validated'], array_column($progress->json('tracks'), 'status'));
        $this->assertStringNotContainsString('not_validatable', $progress->getContent());
        $this->assertStringNotContainsString('not_validatable', $passport->getContent());
        $this->assertEqualsCanonicalizing([$layer->id, $otherLayer->id], array_column($passport->json('routes'), 'layer_id'));
    }

    public function test_progress_for_unknown_layer_returns_404(): void
    {
        $this->actingAs(User::factory()->create(), 'api')
            ->getJson('/api/layer/999999999/progress')
            ->assertStatus(404);
    }

    public function test_progress_with_no_validations_returns_zero(): void
    {
        $owner = $this->createUserWithRole('Validator');
        $layer = $this->createLayer($owner->id);
        $this->track($owner->id, $layer);
        $this->track($owner->id, $layer);

        $response = $this->actingAs(User::factory()->create(), 'api')->getJson("/api/layer/{$layer->id}/progress");

        $response->assertOk()->assertJson(['validated' => 0, 'total' => 2, 'percentage' => 0, 'completed' => false]);
        $this->assertSame(['not_validated', 'not_validated'], array_column($response->json('tracks'), 'status'));
    }

    public function test_progress_ignores_other_users_validations(): void
    {
        $owner = $this->createUserWithRole('Validator');
        $walker = User::factory()->create();
        $other = User::factory()->create();
        $layer = $this->createLayer($owner->id);
        $this->validate($other, $this->track($owner->id, $layer), $layer);

        $response = $this->actingAs($walker, 'api')->getJson("/api/layer/{$layer->id}/progress");

        $response->assertOk()->assertJson(['validated' => 0, 'total' => 1]);
        $this->assertSame('not_validated', $response->json('tracks.0.status'));
        $this->assertNull($response->json('tracks.0.validated_at'));
    }

    public function test_passport_returns_routes_of_logged_user_only(): void
    {
        $owner = $this->createUserWithRole('Validator');
        $walker = User::factory()->create();
        $other = User::factory()->create();
        $mine = $this->createLayer($owner->id);
        $theirs = $this->createLayer($owner->id);
        $this->validate($walker, $this->track($owner->id, $mine), $mine);
        $this->validate($other, $this->track($owner->id, $theirs), $theirs);

        $response = $this->actingAs($walker, 'api')->getJson('/api/passport');

        $response->assertOk()
            ->assertExactJsonStructure([
                'routes' => ['*' => [
                    'layer_id', 'name', 'validated', 'total', 'percentage', 'completed',
                    'km_validated', 'km_total', 'last_validated_at',
                ]],
            ]);
        $this->assertSame([$mine->id], array_column($response->json('routes'), 'layer_id'));
        $this->assertSame('2026-09-30T10:00:00+00:00', $response->json('routes.0.last_validated_at'));
    }

    public function test_passport_without_validations_returns_empty_routes(): void
    {
        $this->actingAs(User::factory()->create(), 'api')
            ->getJson('/api/passport')
            ->assertOk()
            ->assertExactJson(['routes' => []]);
    }

    public function test_km_are_serialized_with_one_decimal_even_when_integer(): void
    {
        $owner = $this->createUserWithRole('Validator');
        $walker = User::factory()->create();
        $layer = $this->createLayer($owner->id);
        $this->validate($walker, $this->track($owner->id, $layer, [17]), $layer);

        $progress = $this->actingAs($walker, 'api')->getJson("/api/layer/{$layer->id}/progress");
        $this->assertStringContainsString('"km_validated":17.0', $progress->getContent());
        $this->assertStringContainsString('"km_total":17.0', $progress->getContent());

        $passport = $this->actingAs($walker, 'api')->getJson('/api/passport');
        $this->assertStringContainsString('"km_validated":17.0', $passport->getContent());
    }

    public function test_track_name_is_empty_object_and_distance_has_decimal_in_raw_json(): void
    {
        $owner = $this->createUserWithRole('Validator');
        $walker = User::factory()->create();
        $layer = $this->createLayer($owner->id);
        $track = $this->track($owner->id, $layer, [17]);
        DB::table('ec_tracks')->where('id', $track->id)->update(['name' => '{"it":null,"de":""}']);

        $content = $this->actingAs($walker, 'api')->getJson("/api/layer/{$layer->id}/progress")->getContent();
        $this->assertStringContainsString('"name":{}', $content);
        $this->assertStringContainsString('"distance":17.0', $content);

        DB::table('ec_tracks')->where('id', $track->id)->update(['name' => '{"it":"Tappa 1","de":null}']);
        $content = $this->actingAs($walker, 'api')->getJson("/api/layer/{$layer->id}/progress")->getContent();
        $this->assertStringContainsString('"name":{"it":"Tappa 1"}', $content);
    }

    public function test_passport_routes_are_ordered_by_layer_id_ascending(): void
    {
        $owner = $this->createUserWithRole('Validator');
        $walker = User::factory()->create();
        $first = $this->createLayer($owner->id);
        $second = $this->createLayer($owner->id);
        // validati in ordine inverso rispetto agli id
        $this->validate($walker, $this->track($owner->id, $second), $second, '2026-09-30 09:00:00');
        $this->validate($walker, $this->track($owner->id, $first), $first, '2026-09-30 11:00:00');

        $response = $this->actingAs($walker, 'api')->getJson('/api/passport');

        $this->assertSame([$first->id, $second->id], array_column($response->json('routes'), 'layer_id'));
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Wm\WmPackage\Jobs\UpdateAppConfigJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Nova\Filters\UgcTrackLayerFilter;
use Wm\WmPackage\Services\Models\UgcTrackMatchingService;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class UgcTrackMatchingNovaTest extends TestCase
{
    use DatabaseTransactions;

    private App $app_;

    protected function setUp(): void
    {
        parent::setUp();
        // Layer::factory()->create() fa partire UpdateAppConfigJob (unique): il lock resta su Redis
        // oltre la transazione e farebbe scartare i dispatch di altri test.
        Bus::fake([UpdateAppConfigJob::class]);
        RolesAndPermissionsService::seedDatabase();
        $this->app_ = App::query()->first() ?? App::factory()->create();
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function ecTrack(string $wkt, Layer $layer): int
    {
        $track = EcTrack::withoutEvents(fn () => EcTrack::factory()->create(['app_id' => $this->app_->id]));
        DB::update('UPDATE ec_tracks SET geometry = ST_Force3D(ST_GeomFromText(?, 4326))::geography WHERE id = ?', [$wkt, $track->id]);
        DB::table('layerables')->insert([
            'layer_id' => $layer->id,
            'layerable_id' => $track->id,
            'layerable_type' => UgcTrackMatchingService::make()->ecTrackMorphClass(),
        ]);

        return $track->id;
    }

    private function ugcTrack(string $wkt): UgcTrack
    {
        $track = UgcTrack::withoutEvents(fn () => UgcTrack::factory()->create(['app_id' => $this->app_->id, 'properties' => []]));
        DB::update('UPDATE ugc_tracks SET geometry = ST_Force3D(ST_GeomFromText(?, 4326))::geography WHERE id = ?', [$wkt, $track->id]);
        UgcTrackMatchingService::make()->matchUgcTrack($track->id);

        return $track;
    }

    public function test_administrator_sees_crossed_routes_in_detail(): void
    {
        $layer = Layer::factory()->create(['app_id' => $this->app_->id, 'name' => ['it' => 'Via degli Dei Test']]);
        $this->ecTrack('MULTILINESTRING((11 44, 11 44.018))', $layer);
        $ugc = $this->ugcTrack('MULTILINESTRING((11.00025 44, 11.00025 44.009))');

        $response = $this->actingAs($this->user('Administrator'))->getJson('/nova-api/ugc-tracks/'.$ugc->id);

        $response->assertOk();
        $field = collect($response->json('resource.fields'))->firstWhere('name', __('Layers crossed by the track'));
        $this->assertNotNull($field);
        $this->assertStringContainsString('Via degli Dei Test', $field['value']);
    }

    public function test_layer_link_and_manual_override_are_gone_from_ugc_tracks(): void
    {
        $ugc = $this->ugcTrack('MULTILINESTRING((12 45, 12 45.001))');

        $index = $this->actingAs($this->user('Administrator'))->getJson('/nova-api/ugc-tracks?perPage=25')->assertOk();
        $attributes = collect($index->json('resources'))->flatMap(fn ($r) => collect($r['fields'])->pluck('attribute'))->all();
        $this->assertNotContains('layer_link', $attributes);

        $edit = $this->actingAs($this->user('Administrator'))->getJson('/nova-api/ugc-tracks/'.$ugc->id.'/update-fields')->assertOk();
        $editAttributes = collect($edit->json('fields'))->pluck('attribute')->all();
        $this->assertEmpty(array_filter($editAttributes, fn ($a) => str_contains((string) $a, 'layer_override')));
    }

    public function test_administrator_filters_ugc_tracks_by_route(): void
    {
        $layerA = Layer::factory()->create(['app_id' => $this->app_->id]);
        $layerB = Layer::factory()->create(['app_id' => $this->app_->id]);
        $this->ecTrack('MULTILINESTRING((11 44, 11 44.018))', $layerA);
        $this->ecTrack('MULTILINESTRING((12 45, 12 45.018))', $layerB);
        $onA = $this->ugcTrack('MULTILINESTRING((11.00025 44, 11.00025 44.009))');
        $this->ugcTrack('MULTILINESTRING((12.00025 45, 12.00025 45.009))');

        $filters = base64_encode(json_encode([[UgcTrackLayerFilter::class => $layerA->id]]));
        $response = $this->actingAs($this->user('Administrator'))->getJson('/nova-api/ugc-tracks?perPage=25&filters='.$filters);

        $response->assertOk();
        $this->assertEquals([(string) $onA->id], collect($response->json('resources'))->pluck('id.value')->all());
    }

    public function test_validator_still_gets_an_empty_list(): void
    {
        $this->ugcTrack('MULTILINESTRING((11 44, 11 44.001))');

        $response = $this->actingAs($this->user('Validator'))->getJson('/nova-api/ugc-tracks?perPage=25');

        $response->assertOk();
        $this->assertEmpty($response->json('resources'));
    }
}

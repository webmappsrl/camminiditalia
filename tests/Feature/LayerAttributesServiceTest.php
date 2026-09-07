<?php

namespace Tests\Feature;

use App\Models\EcTrack;
use App\Services\LayerAttributesService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class LayerAttributesServiceTest extends TestCase
{
    use DatabaseTransactions, LayerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::fake();
        RolesAndPermissionsService::seedDatabase();
        if (App::count() === 0) {
            App::factory()->create();
        }
    }

    public function test_total_distance_sums_distances_of_all_associated_tracks(): void
    {
        $layer = $this->createLayer();

        $first = EcTrack::factory()->create([
            'properties' => ['manual_data' => ['distance' => 10.5]],
        ]);
        $second = EcTrack::factory()->create([
            'properties' => ['manual_data' => ['distance' => 4.5]],
        ]);
        $layer->ecTracks()->attach([$first->id, $second->id]);

        $this->assertSame(15.0, app(LayerAttributesService::class)->totalDistance($layer->fresh()));
    }

    public function test_total_distance_ignores_track_ownership(): void
    {
        $layer = $this->createLayer();
        $otherOwner = $this->createUserWithoutRole();
        $otherOwnerTrack = EcTrack::factory()->create([
            'user_id' => $otherOwner->id,
            'properties' => ['manual_data' => ['distance' => 7.0]],
        ]);
        $layer->ecTracks()->attach($otherOwnerTrack->id);

        $this->assertSame(7.0, app(LayerAttributesService::class)->totalDistance($layer->fresh()));
    }

    public function test_total_distance_is_null_when_layer_has_no_tracks(): void
    {
        $layer = $this->createLayer();

        $this->assertNull(app(LayerAttributesService::class)->totalDistance($layer));
    }

    public function test_total_distance_is_null_when_no_track_has_a_distance_value(): void
    {
        $layer = $this->createLayer();
        $first = EcTrack::factory()->create(['properties' => []]);
        $second = EcTrack::factory()->create(['properties' => []]);
        $layer->ecTracks()->attach([$first->id, $second->id]);

        $this->assertNull(app(LayerAttributesService::class)->totalDistance($layer->fresh()));
    }

    public function test_total_distance_counts_a_legitimate_zero_value(): void
    {
        $layer = $this->createLayer();
        $zeroDistance = EcTrack::factory()->create([
            'properties' => ['manual_data' => ['distance' => 0.0]],
        ]);
        $withDistance = EcTrack::factory()->create([
            'properties' => ['manual_data' => ['distance' => 5.0]],
        ]);
        $layer->ecTracks()->attach([$zeroDistance->id, $withDistance->id]);

        $this->assertSame(5.0, app(LayerAttributesService::class)->totalDistance($layer->fresh()));
    }

    public function test_stage_count_returns_number_of_associated_tracks(): void
    {
        $layer = $this->createLayer();
        $tracks = EcTrack::factory()->count(3)->create(['properties' => []]);
        $layer->ecTracks()->attach($tracks->pluck('id')->toArray());

        $this->assertSame(3, app(LayerAttributesService::class)->stageCount($layer->fresh()));
    }

    public function test_stage_count_is_null_when_layer_has_no_tracks(): void
    {
        $layer = $this->createLayer();

        $this->assertNull(app(LayerAttributesService::class)->stageCount($layer));
    }
}

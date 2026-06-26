<?php

namespace Tests\Feature;

use App\Models\EcPoi;
use App\Models\EcTrack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;

class SyncLayerEcPoisCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        App::factory()->create();
        Queue::fake();
        Http::fake();
    }

    private function makeLayer(): Layer
    {
        $owner = User::factory()->create();

        return Layer::factory()->create(['user_id' => $owner->id]);
    }

    /** @test */
    public function command_associates_related_pois_of_tracks_to_their_layer(): void
    {
        $layer = $this->makeLayer();
        $track = EcTrack::factory()->create(['properties' => []]);
        $poi = EcPoi::factory()->create(['properties' => [], 'user_id' => $layer->user_id]);
        $layer->ecTracks()->attach($track->id);
        // Bypass observer: attach POI to track without triggering layer sync
        \DB::table('ec_poi_ec_track')->insert(['ec_track_id' => $track->id, 'ec_poi_id' => $poi->id, 'order' => 0]);

        $this->artisan('camminiditalia:sync-layer-ec-pois')->assertSuccessful();

        $this->assertTrue($layer->ecPois()->where('ec_pois.id', $poi->id)->exists());
    }

    /** @test */
    public function command_updates_poi_user_id_to_layer_owner(): void
    {
        $otherUser = User::factory()->create();
        $layer = $this->makeLayer();
        $ownerId = $layer->user_id;
        $track = EcTrack::factory()->create(['properties' => []]);
        $poi = EcPoi::factory()->create(['properties' => [], 'user_id' => $otherUser->id]);
        $layer->ecTracks()->attach($track->id);
        \DB::table('ec_poi_ec_track')->insert(['ec_track_id' => $track->id, 'ec_poi_id' => $poi->id, 'order' => 0]);

        $this->artisan('camminiditalia:sync-layer-ec-pois')->assertSuccessful();

        $this->assertEquals($ownerId, $poi->fresh()->user_id);
    }

    /** @test */
    public function command_is_idempotent(): void
    {
        $layer = $this->makeLayer();
        $track = EcTrack::factory()->create(['properties' => []]);
        $poi = EcPoi::factory()->create(['properties' => []]);
        $layer->ecTracks()->attach($track->id);
        \DB::table('ec_poi_ec_track')->insert(['ec_track_id' => $track->id, 'ec_poi_id' => $poi->id, 'order' => 0]);

        $this->artisan('camminiditalia:sync-layer-ec-pois')->assertSuccessful();
        $this->artisan('camminiditalia:sync-layer-ec-pois')->assertSuccessful();

        $this->assertCount(1, $layer->ecPois()->where('ec_pois.id', $poi->id)->get());
    }

    /** @test */
    public function command_handles_poi_related_to_tracks_of_multiple_layers(): void
    {
        $layerA = $this->makeLayer();
        $layerB = $this->makeLayer();
        $trackA = EcTrack::factory()->create(['properties' => []]);
        $trackB = EcTrack::factory()->create(['properties' => []]);
        $poi = EcPoi::factory()->create(['properties' => []]);
        $layerA->ecTracks()->attach($trackA->id);
        $layerB->ecTracks()->attach($trackB->id);
        \DB::table('ec_poi_ec_track')->insert(['ec_track_id' => $trackA->id, 'ec_poi_id' => $poi->id, 'order' => 0]);
        \DB::table('ec_poi_ec_track')->insert(['ec_track_id' => $trackB->id, 'ec_poi_id' => $poi->id, 'order' => 0]);

        $this->artisan('camminiditalia:sync-layer-ec-pois')->assertSuccessful();

        $this->assertTrue($layerA->ecPois()->where('ec_pois.id', $poi->id)->exists());
        $this->assertTrue($layerB->ecPois()->where('ec_pois.id', $poi->id)->exists());
    }
}

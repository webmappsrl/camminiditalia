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

class LayerEcPoiSyncObserverTest extends TestCase
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

    private function makeTrack(Layer $layer)
    {
        $track = EcTrack::factory()->create(['properties' => []]);
        $layer->ecTracks()->attach($track->id);

        return $track;
    }

    private function makePoi()
    {
        return EcPoi::factory()->create(['properties' => []]);
    }

    /** @test */
    public function attaching_poi_to_track_associates_it_with_layer(): void
    {
        $layer = $this->makeLayer();
        $track = $this->makeTrack($layer);
        $poi = $this->makePoi();

        $track->ecPois()->attach($poi->id);

        $this->assertTrue($layer->ecPois()->where('ec_pois.id', $poi->id)->exists());
    }

    /** @test */
    public function detaching_poi_from_track_removes_it_from_layer_when_no_other_track_has_it(): void
    {
        $layer = $this->makeLayer();
        $track = $this->makeTrack($layer);
        $poi = $this->makePoi();
        $track->ecPois()->attach($poi->id);

        $track->ecPois()->detach($poi->id);

        $this->assertFalse($layer->ecPois()->where('ec_pois.id', $poi->id)->exists());
    }

    /** @test */
    public function detaching_poi_from_one_track_keeps_it_in_layer_if_another_track_still_has_it(): void
    {
        $layer = $this->makeLayer();
        $track1 = $this->makeTrack($layer);
        $track2 = $this->makeTrack($layer);
        $poi = $this->makePoi();
        $track1->ecPois()->attach($poi->id);
        $track2->ecPois()->attach($poi->id);

        $track1->ecPois()->detach($poi->id);

        $this->assertTrue($layer->ecPois()->where('ec_pois.id', $poi->id)->exists());
    }

    /** @test */
    public function poi_shared_between_tracks_of_different_layers_is_associated_with_all_layers(): void
    {
        $layerA = $this->makeLayer();
        $layerB = $this->makeLayer();
        $trackA = $this->makeTrack($layerA);
        $trackB = $this->makeTrack($layerB);
        $poi = $this->makePoi();

        $trackA->ecPois()->attach($poi->id);
        $trackB->ecPois()->attach($poi->id);

        $this->assertTrue($layerA->ecPois()->where('ec_pois.id', $poi->id)->exists());
        $this->assertTrue($layerB->ecPois()->where('ec_pois.id', $poi->id)->exists());
    }
}

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

class LayerableObserverEcTrackRemovedTest extends TestCase
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
    public function removing_track_from_layer_detaches_orphan_pois(): void
    {
        $layer = $this->makeLayer();
        $track = $this->makeTrack($layer);
        $poi = $this->makePoi();
        $layer->ecPois()->syncWithoutDetaching([$poi->id]);
        $track->ecPois()->attach($poi->id);

        $layer->ecTracks()->detach($track->id);

        $this->assertFalse($layer->ecPois()->where('ec_pois.id', $poi->id)->exists());
    }

    /** @test */
    public function removing_track_from_layer_keeps_pois_still_in_other_tracks(): void
    {
        $layer = $this->makeLayer();
        $track1 = $this->makeTrack($layer);
        $track2 = $this->makeTrack($layer);
        $poi = $this->makePoi();
        $layer->ecPois()->syncWithoutDetaching([$poi->id]);
        $track1->ecPois()->attach($poi->id);
        $track2->ecPois()->attach($poi->id);

        $layer->ecTracks()->detach($track1->id);

        $this->assertTrue($layer->ecPois()->where('ec_pois.id', $poi->id)->exists());
    }
}

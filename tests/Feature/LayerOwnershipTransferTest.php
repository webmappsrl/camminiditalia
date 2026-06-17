<?php

namespace Tests\Feature;

use App\Models\EcPoi;
use App\Models\EcTrack;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class LayerOwnershipTransferTest extends TestCase
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

    // =========================================================================
    // LayerObserver — cambio user_id sul layer
    // =========================================================================

    public function test_tracks_are_transferred_when_layer_owner_changes(): void
    {
        $oldOwner = User::factory()->create();
        $newOwner = User::factory()->create();
        $layer = $this->createLayer($oldOwner->id);

        $track = EcTrack::factory()->create(['user_id' => $oldOwner->id]);
        $layer->ecTracks()->attach($track->id);

        $layer->update(['user_id' => $newOwner->id]);

        $this->assertEquals($newOwner->id, $track->fresh()->user_id);
    }

    public function test_pois_are_transferred_when_layer_owner_changes(): void
    {
        $oldOwner = User::factory()->create();
        $newOwner = User::factory()->create();
        $layer = $this->createLayer($oldOwner->id);

        $poi = EcPoi::factory()->create(['user_id' => $oldOwner->id, 'properties' => []]);
        $layer->manualEcPois()->attach($poi->id);

        $layer->update(['user_id' => $newOwner->id]);

        $this->assertEquals($newOwner->id, $poi->fresh()->user_id);
    }

    public function test_all_tracks_are_transferred_regardless_of_original_owner(): void
    {
        $anotherUser = User::factory()->create();
        $newOwner = User::factory()->create();
        $layer = $this->createLayer(User::factory()->create()->id);

        $track = EcTrack::factory()->create(['user_id' => $anotherUser->id]);
        $layer->ecTracks()->attach($track->id);

        $layer->update(['user_id' => $newOwner->id]);

        $this->assertEquals($newOwner->id, $track->fresh()->user_id);
    }

    public function test_tracks_are_transferred_to_default_owner_when_layer_owner_is_removed(): void
    {
        $oldOwner = User::factory()->create();
        $defaultOwner = User::factory()->create();
        config(['camminiditalia.default_owner_id' => $defaultOwner->id]);

        $layer = $this->createLayer($oldOwner->id);
        $track = EcTrack::factory()->create(['user_id' => $oldOwner->id]);
        $layer->ecTracks()->attach($track->id);

        $layer->update(['user_id' => null]);

        $this->assertEquals($defaultOwner->id, $track->fresh()->user_id);
    }

    public function test_no_transfer_when_user_id_does_not_change(): void
    {
        $owner = User::factory()->create();
        $layer = $this->createLayer($owner->id);

        $track = EcTrack::factory()->create(['user_id' => $owner->id]);
        $layer->ecTracks()->attach($track->id);

        $layer->update(['name' => json_encode(['it' => 'Nuovo nome'])]);

        $this->assertEquals($owner->id, $track->fresh()->user_id);
    }

    public function test_tracks_are_transferred_when_layer_is_created_with_owner(): void
    {
        $owner = User::factory()->create();
        $track = EcTrack::factory()->create(['user_id' => null]);

        $layer = Layer::factory()->create(['user_id' => $owner->id]);
        $layer->ecTracks()->attach($track->id);

        // Simula salvataggio dopo creazione con owner già impostato
        $layer->save();

        $this->assertEquals($owner->id, $track->fresh()->user_id);
    }

    // =========================================================================
    // LayerableObserver — nuova risorsa associata a layer con owner
    // =========================================================================

    public function test_track_gets_layer_owner_when_attached_to_layer_with_owner(): void
    {
        $owner = User::factory()->create();
        $layer = $this->createLayer($owner->id);

        $track = EcTrack::factory()->create(['user_id' => null]);
        $layer->ecTracks()->attach($track->id);

        $this->assertEquals($owner->id, $track->fresh()->user_id);
    }

    public function test_poi_gets_layer_owner_when_attached_to_layer_with_owner(): void
    {
        $owner = User::factory()->create();
        $layer = $this->createLayer($owner->id);

        $poi = EcPoi::factory()->create(['user_id' => null, 'properties' => []]);
        $layer->manualEcPois()->attach($poi->id);

        $this->assertEquals($owner->id, $poi->fresh()->user_id);
    }

    public function test_track_is_not_transferred_when_layer_has_no_owner(): void
    {
        $layer = $this->createLayer(null);
        $originalUser = User::factory()->create();

        $track = EcTrack::factory()->create(['user_id' => $originalUser->id]);
        $layer->ecTracks()->attach($track->id);

        $this->assertEquals($originalUser->id, $track->fresh()->user_id);
    }
}

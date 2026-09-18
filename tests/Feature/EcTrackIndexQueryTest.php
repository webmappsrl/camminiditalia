<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Nova\Http\Requests\NovaRequest;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App as WmApp;
use Wm\WmPackage\Models\EcTrack;

/**
 * Wm\WmPackage\Nova\AbstractEcResource::indexQuery() scopa per app_id posseduto
 * (ownedAppIds()): in camminiditalia esiste una sola App, di proprietà
 * dell'Administrator, quindi nessun Validator ne possiede mai una e la lista
 * Nova "Ec Tracks" risulterebbe sempre vuota (oc:8587, causato dallo stesso
 * commit oc:8162 di EcTrackPolicy). App\Nova\EcTrack::indexQuery() scopa per
 * user_id invece che per app_id.
 */
class EcTrackIndexQueryTest extends TestCase
{
    use DatabaseTransactions, LayerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::fake();

        \Wm\WmPackage\Services\RolesAndPermissionsService::seedDatabase();

        if (WmApp::count() === 0) {
            WmApp::factory()->create();
        }
    }

    private function novaRequestFor($user): NovaRequest
    {
        $request = NovaRequest::create('/');
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    public function test_administrator_sees_all_ec_tracks(): void
    {
        $admin = $this->createUserWithRole('Administrator');
        $track = \App\Models\EcTrack::factory()->createQuietly();

        $results = \App\Nova\EcTrack::indexQuery($this->novaRequestFor($admin), EcTrack::query())->get();

        $this->assertTrue($results->contains($track));
    }

    public function test_validator_sees_only_own_ec_tracks(): void
    {
        $validator = $this->createUserWithRole('Validator');
        $otherValidator = $this->createUserWithRole('Validator');

        $ownTrack = \App\Models\EcTrack::factory()->createQuietly(['user_id' => $validator->id]);
        $otherTrack = \App\Models\EcTrack::factory()->createQuietly(['user_id' => $otherValidator->id]);

        $results = \App\Nova\EcTrack::indexQuery($this->novaRequestFor($validator), EcTrack::query())->get();

        $this->assertTrue($results->contains($ownTrack));
        $this->assertFalse($results->contains($otherTrack));
    }

    public function test_validator_without_ec_tracks_sees_empty_list(): void
    {
        $validator = $this->createUserWithRole('Validator');
        \App\Models\EcTrack::factory()->createQuietly();

        $results = \App\Nova\EcTrack::indexQuery($this->novaRequestFor($validator), EcTrack::query())->get();

        $this->assertCount(0, $results);
    }

    public function test_guest_sees_empty_list(): void
    {
        $guest = $this->createUserWithRole('Guest');
        \App\Models\EcTrack::factory()->createQuietly();

        $results = \App\Nova\EcTrack::indexQuery($this->novaRequestFor($guest), EcTrack::query())->get();

        $this->assertCount(0, $results);
    }

    public function test_validator_sees_ec_track_only_after_layer_ownership_transfer(): void
    {
        $oldOwner = $this->createUserWithRole('Validator');
        $newOwner = $this->createUserWithRole('Validator');
        $layer = $this->createLayer($oldOwner->id);

        $track = \App\Models\EcTrack::factory()->createQuietly(['user_id' => $oldOwner->id]);
        $layer->ecTracks()->attach($track->id);

        $beforeTransfer = \App\Nova\EcTrack::indexQuery($this->novaRequestFor($oldOwner), EcTrack::query())->get();
        $this->assertTrue($beforeTransfer->contains($track));

        $layer->update(['user_id' => $newOwner->id]);

        $afterTransferOldOwner = \App\Nova\EcTrack::indexQuery($this->novaRequestFor($oldOwner), EcTrack::query())->get();
        $afterTransferNewOwner = \App\Nova\EcTrack::indexQuery($this->novaRequestFor($newOwner), EcTrack::query())->get();

        $this->assertFalse($afterTransferOldOwner->contains($track->id), 'Il vecchio owner non deve più vedere la traccia dopo il trasferimento.');
        $this->assertTrue($afterTransferNewOwner->pluck('id')->contains($track->id), 'Il nuovo owner deve vedere la traccia dopo il trasferimento.');
    }
}

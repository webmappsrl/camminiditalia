<?php

namespace Tests\Feature;

use App\Models\CertificationRequest;
use App\Models\ValidatedEcTrack;
use App\Services\CertificationRequestService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Helpers\CreatesCertificationTracks;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class CertificationRequestSelectableTracksTest extends TestCase
{
    use CreatesCertificationTracks, DatabaseTransactions, LayerTestHelpers;

    private CertificationRequestService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::fake();

        RolesAndPermissionsService::seedDatabase();

        if (App::count() === 0) {
            App::factory()->create();
        }

        $this->service = app(CertificationRequestService::class);
    }

    private function makeRequest(Layer $layer, ?User $walker = null): CertificationRequest
    {
        return CertificationRequest::create([
            'user_id' => ($walker ?? User::factory()->create())->id,
            'layer_id' => $layer->id,
            'status' => CertificationRequest::STATUS_PENDING,
            'disclaimer_accepted_at' => now(),
        ]);
    }

    public function test_selectable_tracks_only_owned_by_layer_owner(): void
    {
        $owner = $this->createUserWithRole('Validator');
        $layer = $this->createLayer($owner->id);
        $own = collect(['Tappa 1', 'Tappa 2', 'Tappa 3'])
            ->map(fn ($name) => $this->createTrack($owner->id, $name, $layer)->id);
        $this->createTrack(User::factory()->create()->id, 'Tappa estranea', $layer);
        $this->createTrack($owner->id, 'Tappa di un altro cammino', $this->createLayer($owner->id));

        $ids = $this->service->selectableTracks($this->makeRequest($layer))->pluck('id');

        $this->assertEqualsCanonicalizing($own->all(), $ids->all());
    }

    public function test_selectable_tracks_use_default_owner_when_layer_has_no_owner(): void
    {
        $defaultOwner = $this->createUserWithRole('Administrator');
        config(['camminiditalia.default_owner_id' => $defaultOwner->id]);
        $layer = $this->createLayer();
        $layer->forceFill(['user_id' => null])->saveQuietly();
        $track = $this->createTrack($defaultOwner->id, 'Tappa 1', $layer);
        $this->createTrack(User::factory()->create()->id, 'Tappa 2', $layer);

        $ids = $this->service->selectableTracks($this->makeRequest($layer->fresh()))->pluck('id')->all();

        $this->assertSame([$track->id], $ids);
    }

    public function test_selectable_tracks_natural_order(): void
    {
        $owner = $this->createUserWithRole('Validator');
        $layer = $this->createLayer($owner->id);
        foreach (['Tappa 10', 'Tappa 2', 'tappa 1'] as $name) {
            $this->createTrack($owner->id, $name, $layer);
        }

        $labels = $this->service->selectableTracks($this->makeRequest($layer))
            ->map(fn ($track) => \App\Support\EcTrackLabel::for($track))
            ->all();

        $this->assertSame(['tappa 1', 'Tappa 2', 'Tappa 10'], array_values($labels));
    }

    public function test_already_validated_tracks_are_excluded_and_listed_separately(): void
    {
        $owner = $this->createUserWithRole('Validator');
        $layer = $this->createLayer($owner->id);
        $walker = User::factory()->create();
        $validated = $this->createTrack($owner->id, 'Tappa 1', $layer);
        $open = $this->createTrack($owner->id, 'Tappa 2', $layer);
        ValidatedEcTrack::create([
            'user_id' => $walker->id,
            'ec_track_id' => $validated->id,
            'layer_id' => $layer->id,
            'source' => ValidatedEcTrack::SOURCE_MANUAL,
            'validated_at' => now(),
        ]);
        $request = $this->makeRequest($layer, $walker);

        $this->assertSame([$open->id], $this->service->selectableTracks($request)->pluck('id')->all());
        $this->assertSame([$validated->id], $this->service->alreadyValidatedTracks($request)->pluck('id')->all());
    }

    public function test_selectable_tracks_do_not_load_geometry(): void
    {
        $owner = $this->createUserWithRole('Validator');
        $layer = $this->createLayer($owner->id);
        $this->createTrack($owner->id, 'Tappa 1', $layer);

        $track = $this->service->selectableTracks($this->makeRequest($layer))->first();

        $this->assertArrayNotHasKey('geometry', $track->getAttributes());
        // `properties` non viene letto dal DB: l'hook retrieved di EcTrack (wm-package)
        // lo imposta comunque a vuoto quando manca.
        $this->assertEmpty($track->properties);
    }
}

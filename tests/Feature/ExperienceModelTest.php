<?php

namespace Tests\Feature;

use App\Models\Experience;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Helpers\CreatesCertificationTracks;
use Tests\Feature\Helpers\FakesCertificationDisk;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class ExperienceModelTest extends TestCase
{
    use CreatesCertificationTracks, DatabaseTransactions, FakesCertificationDisk, LayerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        RolesAndPermissionsService::seedDatabase();
        if (App::count() === 0) {
            App::factory()->create();
        }

        $this->fakeCertificationDisk();
        Queue::fake();
        Http::fake();
    }

    public function test_visible_to_rispetta_ruoli(): void
    {
        $validator = $this->createUserWithRole('Validator');
        $layer = $this->createLayer($validator->id);
        $walker = $this->createUserWithoutRole();
        Experience::factory()->count(2)->create(['user_id' => $walker->id, 'layer_id' => $layer->id]);

        $admin = $this->createUserWithRole('Administrator');

        $this->assertSame(2, Experience::visibleTo($admin)->count());
        $this->assertSame(0, Experience::visibleTo($validator)->count());
        $this->assertSame(0, Experience::visibleTo($walker)->count());

        $first = Experience::first();
        $first->forceFill(['is_public' => true])->save();

        $this->assertSame(1, Experience::visibleTo($validator)->count());
        $this->assertTrue($first->isVisibleTo($validator));
        $this->assertFalse(Experience::where('id', '!=', $first->id)->first()->isVisibleTo($validator));
        $this->assertFalse($first->isVisibleTo($walker));
    }

    public function test_to_api_array_ha_tappa_e_foto(): void
    {
        $layer = $this->createLayer();
        $walker = $this->createUserWithoutRole();
        $track = $this->createTrack($walker->id, 'Tappa 1', $layer);

        $experience = Experience::factory()->create([
            'user_id' => $walker->id,
            'layer_id' => $layer->id,
            'ec_track_id' => $track->id,
        ]);

        $data = $experience->toApiArray();

        $this->assertSame('Tappa 1', $data['ec_track']['name']);
        $this->assertSame($track->id, $data['ec_track']['id']);
        $this->assertIsArray($data['photos']);
        $this->assertSame([], $data['ugc_track_ids']);
        $this->assertSame($layer->id, $data['layer']['id']);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Diary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Helpers\CreatesCertificationTracks;
use Tests\Feature\Helpers\FakesCertificationDisk;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class DiaryModelTest extends TestCase
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
        Diary::factory()->count(2)->create(['user_id' => $walker->id, 'layer_id' => $layer->id]);

        $admin = $this->createUserWithRole('Administrator');

        $this->assertSame(2, Diary::visibleTo($admin)->count());
        $this->assertSame(0, Diary::visibleTo($validator)->count());
        $this->assertSame(0, Diary::visibleTo($walker)->count());

        $first = Diary::first();
        $first->forceFill(['is_public' => true])->save();

        // Solo gli amministratori vedono i diari, anche pubblici o con consenso (call del 3 novembre 2026).
        $first->forceFill(['publication_consent' => true])->save();
        $this->assertSame(0, Diary::visibleTo($validator)->count());
        $this->assertFalse($first->isVisibleTo($validator));
        $this->assertTrue($first->isVisibleTo($admin));
        $this->assertFalse($first->isVisibleTo($walker));
    }

    public function test_to_api_array_ha_tappa_e_foto(): void
    {
        $layer = $this->createLayer();
        $walker = $this->createUserWithoutRole();
        $track = $this->createTrack($walker->id, 'Tappa 1', $layer);

        $diary = Diary::factory()->create([
            'user_id' => $walker->id,
            'layer_id' => $layer->id,
            'ec_track_id' => $track->id,
        ]);

        $data = $diary->toApiArray();

        $this->assertSame('Tappa 1', $data['ec_track']['name']);
        $this->assertSame($track->id, $data['ec_track']['id']);
        $this->assertIsArray($data['photos']);
        $this->assertSame([], $data['ugc_track_ids']);
        $this->assertSame($layer->id, $data['layer']['id']);
    }
}

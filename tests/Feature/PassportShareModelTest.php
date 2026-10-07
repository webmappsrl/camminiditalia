<?php

namespace Tests\Feature;

use App\Models\PassportShare;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Helpers\FakesCertificationDisk;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\RolesAndPermissionsService;

/**
 * Modello unico delle condivisioni del passaporto (oc:8702, oc:8703): tappe e
 * cammini nella tabella polimorfica `passport_shares`.
 */
class PassportShareModelTest extends TestCase
{
    use DatabaseTransactions, FakesCertificationDisk, LayerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::fake();

        RolesAndPermissionsService::seedDatabase();

        if (App::count() === 0) {
            App::factory()->create();
        }

        $this->fakeCertificationDisk();
    }

    public function test_for_user_is_idempotent_with_stable_uuid(): void
    {
        $user = User::factory()->create();
        $layer = $this->createLayer();
        $track = EcTrack::factory()->create();

        $first = PassportShare::forUser($user, $layer, $track);
        $second = PassportShare::forUser($user, $layer, $track);

        $this->assertSame($first->id, $second->id);
        $this->assertNotEmpty($first->uuid);
        $this->assertSame($first->uuid, $second->uuid);
        $this->assertSame(1, PassportShare::count());
    }

    public function test_two_users_on_same_stage_get_different_uuids(): void
    {
        $layer = $this->createLayer();
        $track = EcTrack::factory()->create();

        $a = PassportShare::forUser(User::factory()->create(), $layer, $track);
        $b = PassportShare::forUser(User::factory()->create(), $layer, $track);

        $this->assertNotSame($a->id, $b->id);
        $this->assertNotSame($a->uuid, $b->uuid);
    }

    public function test_share_image_collection_keeps_a_single_media(): void
    {
        $share = PassportShare::forUser(
            User::factory()->create(),
            $this->createLayer(),
            EcTrack::factory()->create()
        );

        $share->addMedia(UploadedFile::fake()->image('one.jpg'))->toMediaCollection('share_image');
        $share->addMedia(UploadedFile::fake()->image('two.jpg'))->toMediaCollection('share_image');

        $this->assertCount(1, $share->fresh()->getMedia('share_image'));
    }

    public function test_stage_and_route_of_the_same_layer_are_two_shares(): void
    {
        $user = User::factory()->create();
        $layer = $this->createLayer();
        $track = EcTrack::factory()->create();

        $stage = PassportShare::forUser($user, $layer, $track);
        $route = PassportShare::forUser($user, $layer, $layer);

        $this->assertNotSame($stage->id, $route->id);
        $this->assertFalse($stage->isRoute());
        $this->assertTrue($route->isRoute());
        $this->assertSame($layer->id, $route->shareable->id);
        $this->assertSame($track->id, $stage->shareable->id);
    }
}

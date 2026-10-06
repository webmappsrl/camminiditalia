<?php

namespace Tests\Feature;

use App\Models\PassportStageShare;
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

class PassportStageShareModelTest extends TestCase
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

    public function test_for_user_and_track_is_idempotent_with_stable_uuid(): void
    {
        $user = User::factory()->create();
        $layer = $this->createLayer();
        $track = EcTrack::factory()->create();

        $first = PassportStageShare::forUserAndTrack($user, $layer, $track);
        $second = PassportStageShare::forUserAndTrack($user, $layer, $track);

        $this->assertSame($first->id, $second->id);
        $this->assertNotEmpty($first->uuid);
        $this->assertSame($first->uuid, $second->uuid);
        $this->assertSame(1, PassportStageShare::count());
    }

    public function test_two_users_on_same_stage_get_different_uuids(): void
    {
        $layer = $this->createLayer();
        $track = EcTrack::factory()->create();

        $a = PassportStageShare::forUserAndTrack(User::factory()->create(), $layer, $track);
        $b = PassportStageShare::forUserAndTrack(User::factory()->create(), $layer, $track);

        $this->assertNotSame($a->id, $b->id);
        $this->assertNotSame($a->uuid, $b->uuid);
    }

    public function test_share_image_collection_keeps_a_single_media(): void
    {
        $share = PassportStageShare::forUserAndTrack(
            User::factory()->create(),
            $this->createLayer(),
            EcTrack::factory()->create()
        );

        $share->addMedia(UploadedFile::fake()->image('one.jpg'))->toMediaCollection('share_image');
        $share->addMedia(UploadedFile::fake()->image('two.jpg'))->toMediaCollection('share_image');

        $this->assertCount(1, $share->fresh()->getMedia('share_image'));
    }
}

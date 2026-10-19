<?php

namespace Tests\Feature;

use App\Models\CertificationRequest;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Helpers\FakesCertificationDisk;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Media;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class CertificationRequestModelTest extends TestCase
{
    use DatabaseTransactions, FakesCertificationDisk, LayerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        RolesAndPermissionsService::seedDatabase();

        if (App::count() === 0) {
            App::factory()->create();
        }

        $this->fakeCertificationDisk();
    }

    public function test_second_pending_request_for_same_user_and_layer_violates_unique_index(): void
    {
        $user = User::factory()->create();
        $layer = $this->createLayer();

        CertificationRequest::factory()->create([
            'user_id' => $user->id,
            'layer_id' => $layer->id,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        CertificationRequest::factory()->create([
            'user_id' => $user->id,
            'layer_id' => $layer->id,
        ]);
    }

    public function test_new_pending_request_allowed_when_previous_is_not_pending(): void
    {
        $user = User::factory()->create();
        $layer = $this->createLayer();

        CertificationRequest::factory()->create([
            'user_id' => $user->id,
            'layer_id' => $layer->id,
            'status' => 'rejected',
        ]);

        $second = CertificationRequest::factory()->create([
            'user_id' => $user->id,
            'layer_id' => $layer->id,
        ]);

        $this->assertTrue($second->exists);
        $this->assertDatabaseCount('certification_requests', 2);
    }

    public function test_deleting_request_removes_media_and_files(): void
    {
        $user = User::factory()->create();
        $layer = $this->createLayer();

        $request = CertificationRequest::factory()->create([
            'user_id' => $user->id,
            'layer_id' => $layer->id,
            'app_id' => $layer->app_id,
        ]);

        $request->addMedia(UploadedFile::fake()->image('one.jpg'))->toMediaCollection('default');
        $request->addMedia(UploadedFile::fake()->image('two.jpg'))->toMediaCollection('default');

        $paths = $request->getMedia('default')->map->getPathRelativeToRoot()->all();
        $this->assertCount(2, $paths);

        foreach ($paths as $path) {
            Storage::disk('wmfe')->assertExists($path);
        }

        $request->delete();

        foreach ($paths as $path) {
            Storage::disk('wmfe')->assertMissing($path);
        }
        $this->assertSame(0, Media::where('model_type', CertificationRequest::class)->count());
    }

    public function test_locale_defaults_to_it(): void
    {
        // Creazione diretta senza `locale`: verifica il default della colonna.
        $request = CertificationRequest::create([
            'user_id' => User::factory()->create()->id,
            'layer_id' => $this->createLayer()->id,
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-05',
            'disclaimer_accepted_at' => now(),
        ]);
        $request->refresh();

        $this->assertSame('it', $request->locale);
        $this->assertTrue($request->isPending());
    }

    public function test_decided_by_relation(): void
    {
        $validator = $this->createUserWithRole('Validator');

        $request = CertificationRequest::factory()->create([
            'user_id' => User::factory()->create()->id,
            'layer_id' => $this->createLayer($validator->id)->id,
            'status' => CertificationRequest::STATUS_REJECTED,
            'decided_at' => now(),
            'decided_by' => $validator->id,
            'decision_note' => 'Foto illeggibile',
        ]);

        $fresh = $request->fresh();
        $this->assertSame($validator->id, $fresh->decidedBy->id);
        $this->assertNotNull($fresh->decided_at);
        $this->assertSame('Foto illeggibile', $fresh->decision_note);
        $this->assertFalse($fresh->isPending());
    }

    public function test_start_and_end_date_are_cast_to_dates(): void
    {
        $request = CertificationRequest::factory()->create([
            'user_id' => User::factory()->create()->id,
            'layer_id' => $this->createLayer()->id,
        ])->fresh();

        $this->assertSame('2026-09-01', $request->start_date->toDateString());
        $this->assertSame('2026-09-05', $request->end_date->toDateString());
    }
}

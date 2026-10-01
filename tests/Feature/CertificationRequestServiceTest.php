<?php

namespace Tests\Feature;

use App\Exceptions\PendingCertificationRequestExistsException;
use App\Models\CertificationRequest;
use App\Services\CertificationRequestService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Helpers\FakesCertificationDisk;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Throwable;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Media;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class CertificationRequestServiceTest extends TestCase
{
    use DatabaseTransactions, FakesCertificationDisk, LayerTestHelpers;

    private CertificationRequestService $service;

    protected function setUp(): void
    {
        parent::setUp();

        RolesAndPermissionsService::seedDatabase();

        if (App::count() === 0) {
            App::factory()->create();
        }

        $this->fakeCertificationDisk();

        $this->service = app(CertificationRequestService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function certificationMediaQuery()
    {
        return Media::where('model_type', CertificationRequest::class);
    }

    public function test_submit_stores_request_and_media_with_layer_app_id(): void
    {
        Carbon::setTestNow('2026-01-15 10:00:00');

        $user = User::factory()->create();
        $layer = $this->createLayer();

        $images = [
            UploadedFile::fake()->image('c1.jpg'),
            UploadedFile::fake()->image('c2.jpg'),
        ];

        $request = $this->service->submit($user, $layer, $images, 'SN-001');

        $this->assertTrue($request->exists);
        $this->assertSame(CertificationRequest::STATUS_PENDING, $request->status);
        $this->assertSame('SN-001', $request->serial_number);
        $this->assertSame($layer->app_id, $request->app_id);
        $this->assertTrue(now()->equalTo($request->disclaimer_accepted_at));

        $this->assertDatabaseCount('certification_requests', 1);

        $media = $this->certificationMediaQuery()->get();
        $this->assertCount(2, $media);

        foreach ($media as $item) {
            $this->assertSame($request->id, (int) $item->model_id);
            $this->assertSame('default', $item->collection_name);
            $this->assertSame('wmfe', $item->disk);
            $this->assertSame($layer->app_id, $item->app_id);
            Storage::disk('wmfe')->assertExists($item->getPathRelativeToRoot());
        }
    }

    public function test_submit_with_existing_pending_throws_and_uploads_nothing(): void
    {
        $user = User::factory()->create();
        $layer = $this->createLayer();

        CertificationRequest::create([
            'user_id' => $user->id,
            'layer_id' => $layer->id,
            'status' => CertificationRequest::STATUS_PENDING,
            'disclaimer_accepted_at' => now(),
        ]);

        $images = [UploadedFile::fake()->image('c1.jpg')];

        $this->expectException(PendingCertificationRequestExistsException::class);

        try {
            $this->service->submit($user, $layer, $images, null);
        } finally {
            $this->assertSame(0, $this->certificationMediaQuery()->count());
            $this->assertEmpty(Storage::disk('wmfe')->allFiles());
            $this->assertDatabaseCount('certification_requests', 1);
        }
    }

    public function test_failed_upload_removes_request_media_and_files(): void
    {
        $user = User::factory()->create();
        $layer = $this->createLayer();

        // Il secondo file non esiste più su disco: la media library solleva
        // un'eccezione dopo aver già salvato il primo media.
        $missing = UploadedFile::fake()->image('c2.jpg');
        unlink($missing->getPathname());

        $images = [UploadedFile::fake()->image('c1.jpg'), $missing];

        $thrown = null;

        try {
            $this->service->submit($user, $layer, $images, null);
        } catch (Throwable $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown);
        $this->assertNotInstanceOf(PendingCertificationRequestExistsException::class, $thrown);
        $this->assertDatabaseCount('certification_requests', 0);
        $this->assertSame(0, $this->certificationMediaQuery()->count());
        $this->assertEmpty(Storage::disk('wmfe')->allFiles());
    }

    public function test_unique_violation_after_precheck_returns_conflict_without_media(): void
    {
        $user = User::factory()->create();
        $layer = $this->createLayer();

        $fired = false;

        CertificationRequest::creating(function (CertificationRequest $model) use (&$fired, $user, $layer) {
            if (! $fired) {
                $fired = true;

                DB::table('certification_requests')->insert([
                    'user_id' => $user->id,
                    'layer_id' => $layer->id,
                    'status' => CertificationRequest::STATUS_PENDING,
                    'disclaimer_accepted_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        $images = [UploadedFile::fake()->image('c1.jpg')];

        $this->expectException(PendingCertificationRequestExistsException::class);

        try {
            $this->service->submit($user, $layer, $images, null);
        } finally {
            $this->assertSame(0, $this->certificationMediaQuery()->count());
            $this->assertEmpty(Storage::disk('wmfe')->allFiles());
        }
    }

    public function test_latest_for_returns_latest_request_in_any_status_or_null(): void
    {
        $user = User::factory()->create();
        $layer = $this->createLayer();

        $this->assertNull($this->service->latestFor($user, $layer));

        CertificationRequest::create([
            'user_id' => $user->id,
            'layer_id' => $layer->id,
            'status' => CertificationRequest::STATUS_REJECTED,
            'disclaimer_accepted_at' => now(),
        ]);
        $this->assertSame(CertificationRequest::STATUS_REJECTED, $this->service->latestFor($user, $layer)->status);

        $request = CertificationRequest::create([
            'user_id' => $user->id,
            'layer_id' => $layer->id,
            'status' => CertificationRequest::STATUS_PENDING,
            'disclaimer_accepted_at' => now(),
        ]);

        $this->assertSame($request->id, $this->service->latestFor($user, $layer)->id);
    }

    public function test_submit_saves_locale(): void
    {
        $request = $this->service->submit(
            User::factory()->create(),
            $this->createLayer(),
            [UploadedFile::fake()->image('c1.jpg')],
            null,
            'de',
        );

        $this->assertSame('de', $request->fresh()->locale);
    }
}

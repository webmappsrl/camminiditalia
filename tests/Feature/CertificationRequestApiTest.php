<?php

namespace Tests\Feature;

use App\Models\CertificationRequest;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Helpers\FakesCertificationDisk;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Media;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class CertificationRequestApiTest extends TestCase
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
        Queue::fake();
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'images' => [UploadedFile::fake()->image('c1.jpg')],
            'disclaimer_accepted' => true,
        ], $overrides);
    }

    public function test_store_returns_201_with_pending_status(): void
    {
        $user = User::factory()->create();
        $layer = $this->createLayer();

        $response = $this->actingAs($user, 'api')
            ->postJson(
                "/api/layer/{$layer->id}/certification",
                $this->validPayload(['serial_number' => 'SN-001']),
                ['Accept' => 'application/json']
            );

        $response->assertStatus(201)
            ->assertJson(['status' => 'pending']);

        $this->assertArrayHasKey('submitted_at', $response->json());

        $this->assertDatabaseCount('certification_requests', 1);
        $request = CertificationRequest::first();
        $this->assertSame('SN-001', $request->serial_number);

        $media = $request->getMedia('default');
        $this->assertCount(1, $media);
        $this->assertSame($layer->app_id, $media->first()->app_id);
        Storage::disk('wmfe')->assertExists($media->first()->getPathRelativeToRoot());
    }

    public function test_store_without_auth_returns_401(): void
    {
        $layer = $this->createLayer();

        $response = $this->postJson(
            "/api/layer/{$layer->id}/certification",
            $this->validPayload(),
            ['Accept' => 'application/json']
        );

        $response->assertStatus(401);
    }

    public function test_store_on_missing_layer_returns_404(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'api')
            ->postJson(
                '/api/layer/999999/certification',
                $this->validPayload(),
                ['Accept' => 'application/json']
            );

        $response->assertStatus(404);
    }

    public static function invalidPayloadProvider(): array
    {
        return [
            'zero images' => [
                fn () => ['images' => [], 'disclaimer_accepted' => true],
                'images',
            ],
            'seven images' => [
                fn () => [
                    'images' => array_fill(0, 7, UploadedFile::fake()->image('c.jpg')),
                    'disclaimer_accepted' => true,
                ],
                'images',
            ],
            'pdf file' => [
                fn () => [
                    'images' => [UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf')],
                    'disclaimer_accepted' => true,
                ],
                'images.0',
            ],
            'image too large' => [
                fn () => [
                    'images' => [UploadedFile::fake()->create('big.jpg', 9000, 'image/jpeg')],
                    'disclaimer_accepted' => true,
                ],
                'images.0',
            ],
            'serial number too long' => [
                fn () => [
                    'images' => [UploadedFile::fake()->image('c1.jpg')],
                    'serial_number' => str_repeat('a', 256),
                    'disclaimer_accepted' => true,
                ],
                'serial_number',
            ],
            'disclaimer missing' => [
                fn () => [
                    'images' => [UploadedFile::fake()->image('c1.jpg')],
                ],
                'disclaimer_accepted',
            ],
        ];
    }

    #[DataProvider('invalidPayloadProvider')]
    public function test_store_validation(\Closure $payloadFactory, string $expectedErrorKey): void
    {
        $user = User::factory()->create();
        $layer = $this->createLayer();

        $response = $this->actingAs($user, 'api')
            ->postJson(
                "/api/layer/{$layer->id}/certification",
                $payloadFactory(),
                ['Accept' => 'application/json']
            );

        $response->assertStatus(422)
            ->assertJsonValidationErrors([$expectedErrorKey]);

        $this->assertDatabaseCount('certification_requests', 0);
    }

    public function test_store_accepts_heif_images(): void
    {
        $user = User::factory()->create();
        $layer = $this->createLayer();

        $response = $this->actingAs($user, 'api')
            ->postJson(
                "/api/layer/{$layer->id}/certification",
                [
                    'images' => [UploadedFile::fake()->create('credenziale.heif', 100, 'image/heif')],
                    'disclaimer_accepted' => true,
                ],
                ['Accept' => 'application/json']
            );

        $response->assertStatus(201);
    }

    public function test_store_ignores_notes_field_and_saves_serial_number(): void
    {
        $user = User::factory()->create();
        $layer = $this->createLayer();

        $response = $this->actingAs($user, 'api')
            ->postJson(
                "/api/layer/{$layer->id}/certification",
                $this->validPayload([
                    'notes' => 'una nota che deve essere ignorata',
                    'serial_number' => 'SN-XYZ',
                ]),
                ['Accept' => 'application/json']
            );

        $response->assertStatus(201);

        $request = CertificationRequest::first();
        $this->assertSame('SN-XYZ', $request->serial_number);
    }

    public function test_store_with_existing_pending_returns_409(): void
    {
        $user = User::factory()->create();
        $layer = $this->createLayer();

        CertificationRequest::create([
            'user_id' => $user->id,
            'layer_id' => $layer->id,
            'status' => CertificationRequest::STATUS_PENDING,
            'disclaimer_accepted_at' => now(),
        ]);

        $response = $this->actingAs($user, 'api')
            ->postJson(
                "/api/layer/{$layer->id}/certification",
                $this->validPayload(),
                ['Accept' => 'application/json']
            );

        $response->assertStatus(409)
            ->assertJson(['message' => __('A certification request is already pending for this route.')]);

        $this->assertDatabaseCount('certification_requests', 1);
        $this->assertSame(0, Media::where('model_type', CertificationRequest::class)->count());
        $this->assertEmpty(Storage::disk('wmfe')->allFiles());
    }

    public function test_show_returns_none_without_request(): void
    {
        $user = User::factory()->create();
        $layer = $this->createLayer();

        $response = $this->actingAs($user, 'api')
            ->getJson("/api/layer/{$layer->id}/certification", ['Accept' => 'application/json']);

        $response->assertStatus(200)
            ->assertExactJson(['status' => 'none']);
    }

    public function test_show_returns_pending_with_submitted_at(): void
    {
        $user = User::factory()->create();
        $layer = $this->createLayer();

        $request = CertificationRequest::create([
            'user_id' => $user->id,
            'layer_id' => $layer->id,
            'status' => CertificationRequest::STATUS_PENDING,
            'disclaimer_accepted_at' => now(),
        ]);

        $response = $this->actingAs($user, 'api')
            ->getJson("/api/layer/{$layer->id}/certification", ['Accept' => 'application/json']);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'pending',
                'submitted_at' => $request->fresh()->created_at->toIso8601String(),
            ]);
    }

    public function test_show_does_not_leak_other_users_request(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $layer = $this->createLayer();

        CertificationRequest::create([
            'user_id' => $owner->id,
            'layer_id' => $layer->id,
            'status' => CertificationRequest::STATUS_PENDING,
            'disclaimer_accepted_at' => now(),
        ]);

        $response = $this->actingAs($otherUser, 'api')
            ->getJson("/api/layer/{$layer->id}/certification", ['Accept' => 'application/json']);

        $response->assertStatus(200)
            ->assertExactJson(['status' => 'none']);
    }

    public function test_post_too_large_returns_json_413(): void
    {
        $user = User::factory()->create();
        $layer = $this->createLayer();

        $postMaxSizeBytes = $this->iniSizeToBytes(ini_get('post_max_size'));

        $response = $this->actingAs($user, 'api')
            ->call(
                'POST',
                "/api/layer/{$layer->id}/certification",
                server: array_merge($this->transformHeadersToServerVars(['Accept' => 'application/json']), [
                    'CONTENT_LENGTH' => $postMaxSizeBytes + 1,
                ])
            );

        $response->assertStatus(413);
        $this->assertSame(
            __('The uploaded files are too large.'),
            $response->json('message')
        );
    }

    public function test_eleventh_post_in_a_minute_is_throttled(): void
    {
        $user = User::factory()->create();
        $layer = $this->createLayer();

        for ($i = 0; $i < 10; $i++) {
            $response = $this->actingAs($user, 'api')
                ->postJson("/api/layer/{$layer->id}/certification", [], ['Accept' => 'application/json']);

            $this->assertNotSame(429, $response->status());
        }

        $this->actingAs($user, 'api')
            ->postJson("/api/layer/{$layer->id}/certification", [], ['Accept' => 'application/json'])
            ->assertStatus(429);
    }

    public function test_get_is_not_throttled(): void
    {
        $user = User::factory()->create();
        $layer = $this->createLayer();

        for ($i = 0; $i < 11; $i++) {
            $this->actingAs($user, 'api')
                ->getJson("/api/layer/{$layer->id}/certification", ['Accept' => 'application/json'])
                ->assertStatus(200);
        }
    }

    public function test_non_numeric_layer_id_returns_404(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api')
            ->getJson('/api/layer/abc/certification', ['Accept' => 'application/json'])
            ->assertStatus(404);

        $this->actingAs($user, 'api')
            ->postJson('/api/layer/abc/certification', $this->validPayload(), ['Accept' => 'application/json'])
            ->assertStatus(404);
    }

    public function test_package_layer_favorite_routes_still_registered(): void
    {
        $this->assertTrue(Route::has('default.api.layer.favorite.add'));
        $this->assertTrue(Route::has('default.api.layer.favorite.remove'));
        $this->assertTrue(Route::has('default.api.layer.favorite.toggle'));
        $this->assertTrue(Route::has('default.api.layer.favorite.list'));
    }

    private function iniSizeToBytes(string $value): int
    {
        $value = trim($value);
        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => (int) $value,
        };
    }
}

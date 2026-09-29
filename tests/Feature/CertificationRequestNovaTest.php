<?php

namespace Tests\Feature;

use App\Models\CertificationRequest;
use App\Models\User;
use App\Nova\CertificationRequest as NovaCertificationRequest;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Nova;
use Tests\Feature\Helpers\FakesCertificationDisk;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\Media;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class CertificationRequestNovaTest extends TestCase
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

    private function makeRequest(Layer $layer, int $images = 0): CertificationRequest
    {
        $walker = User::factory()->create();

        $request = CertificationRequest::create([
            'user_id' => $walker->id,
            'layer_id' => $layer->id,
            'app_id' => $layer->app_id,
            'status' => CertificationRequest::STATUS_PENDING,
            'serial_number' => 'SN-'.$walker->id,
            'disclaimer_accepted_at' => now(),
        ]);

        for ($i = 0; $i < $images; $i++) {
            $request->addMedia(UploadedFile::fake()->image('photo-'.$i.'.jpg'))->toMediaCollection('default');
        }

        return $request;
    }

    /**
     * @return array<int, string>
     */
    private function mediaPaths(CertificationRequest $request): array
    {
        return $request->fresh()->getMedia('default')->map->getPathRelativeToRoot()->all();
    }

    private function novaRequestFor(User $user): NovaRequest
    {
        $request = NovaRequest::create('/');
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    /**
     * Crea due layer con owner diversi, 2 richieste sul primo e 1 sul secondo.
     *
     * @return array{0: User, 1: \Illuminate\Support\Collection<int, CertificationRequest>, 2: CertificationRequest}
     */
    private function seedTwoLayers(): array
    {
        $owner = $this->createUserWithRole('Validator');
        $otherOwner = $this->createUserWithRole('Validator');
        $ownLayer = $this->createLayer($owner->id);
        $otherLayer = $this->createLayer($otherOwner->id);

        $own = collect([$this->makeRequest($ownLayer), $this->makeRequest($ownLayer)]);
        $foreign = $this->makeRequest($otherLayer);

        return [$owner, $own, $foreign];
    }

    public function test_administrator_index_query_returns_all(): void
    {
        [, $own, $foreign] = $this->seedTwoLayers();
        $admin = $this->createUserWithRole('Administrator');

        $ids = NovaCertificationRequest::indexQuery($this->novaRequestFor($admin), CertificationRequest::query())
            ->pluck('id');

        foreach ($own as $request) {
            $this->assertTrue($ids->contains($request->id));
        }
        $this->assertTrue($ids->contains($foreign->id));
    }

    public function test_validator_index_query_returns_only_own_layers(): void
    {
        [$owner, $own, $foreign] = $this->seedTwoLayers();
        $novaRequest = $this->novaRequestFor($owner);

        foreach (['indexQuery', 'detailQuery', 'relatableQuery'] as $method) {
            $ids = NovaCertificationRequest::$method($novaRequest, CertificationRequest::query())->pluck('id');

            $this->assertEqualsCanonicalizing($own->pluck('id')->all(), $ids->all(), $method);
            $this->assertFalse($ids->contains($foreign->id), $method);
        }
    }

    public function test_guest_index_query_returns_nothing(): void
    {
        $this->seedTwoLayers();

        foreach ([$this->createUserWithRole('Guest'), $this->createUserWithoutRole()] as $user) {
            $count = NovaCertificationRequest::indexQuery($this->novaRequestFor($user), CertificationRequest::query())
                ->count();

            $this->assertSame(0, $count);
        }
    }

    public function test_validator_cannot_view_request_of_foreign_layer(): void
    {
        $otherOwner = $this->createUserWithRole('Validator');
        $foreign = $this->makeRequest($this->createLayer($otherOwner->id), 2);
        $validator = $this->createUserWithRole('Validator');
        $this->createLayer($validator->id);

        $response = $this->actingAs($validator)
            ->getJson('/nova-api/certification-requests/'.$foreign->id);

        $this->assertContains($response->status(), [403, 404]);

        $paths = $this->mediaPaths($foreign);
        $this->assertCount(2, $paths);
        foreach ($paths as $path) {
            $this->assertStringNotContainsString($path, $response->getContent());
        }
    }

    public function test_validator_index_api_lists_only_own_layers(): void
    {
        [$owner, $own, $foreign] = $this->seedTwoLayers();

        $response = $this->actingAs($owner)->getJson('/nova-api/certification-requests');

        $response->assertOk();
        $ids = collect($response->json('resources'))->pluck('id.value');
        $this->assertEqualsCanonicalizing($own->pluck('id')->all(), $ids->all());
        $this->assertFalse($ids->contains($foreign->id));
    }

    public function test_administrator_sees_walker_as_linked_belongs_to(): void
    {
        $admin = $this->createUserWithRole('Administrator');
        $request = $this->makeRequest($this->createLayer($this->createUserWithRole('Validator')->id));

        $response = $this->actingAs($admin)->getJson('/nova-api/certification-requests/'.$request->id);

        $response->assertOk();
        $walker = collect($response->json('resource.fields'))->firstWhere('name', 'Walker');
        $this->assertNotNull($walker);
        $this->assertSame('belongs-to-field', $walker['component']);
        $this->assertSame($request->user_id, $walker['belongsToId']);
    }

    public function test_validator_sees_walker_as_plain_text_without_link(): void
    {
        $owner = $this->createUserWithRole('Validator');
        $request = $this->makeRequest($this->createLayer($owner->id));
        $request->user->update(['name' => 'Mario Rossi']);

        foreach (['/nova-api/certification-requests/'.$request->id, '/nova-api/certification-requests'] as $uri) {
            $response = $this->actingAs($owner)->getJson($uri);
            $response->assertOk();

            $fields = $uri === '/nova-api/certification-requests'
                ? $response->json('resources.0.fields')
                : $response->json('resource.fields');
            $walkers = collect($fields)->where('name', 'Walker');

            $this->assertCount(1, $walkers);
            $walker = $walkers->first();
            $this->assertSame('text-field', $walker['component']);
            $this->assertStringContainsString('Mario Rossi', $walker['value']);
            $this->assertStringContainsString($request->user->email, $walker['value']);
            $this->assertStringNotContainsString('resources/users', $response->getContent());
            $this->assertStringNotContainsString('resources\\/users', $response->getContent());
        }
    }

    public function test_detail_shows_photo_urls_for_authorized_user(): void
    {
        $owner = $this->createUserWithRole('Validator');
        $request = $this->makeRequest($this->createLayer($owner->id), 2);

        $response = $this->actingAs($owner)
            ->getJson('/nova-api/certification-requests/'.$request->id);

        $response->assertOk();
        $photos = collect($response->json('resource.fields'))->firstWhere('name', 'Photos');
        $this->assertNotNull($photos);
        $this->assertTrue($photos['asHtml']);

        $media = $request->fresh()->getMedia('default');
        $this->assertCount(2, $media);
        foreach ($media as $item) {
            $this->assertStringContainsString(e($item->getUrl()), $photos['value']);
        }
        $this->assertStringContainsString('target="_blank" rel="noopener noreferrer"', $photos['value']);
    }

    public function test_photos_field_is_not_resolved_for_unauthorized_user(): void
    {
        $otherOwner = $this->createUserWithRole('Validator');
        $request = $this->makeRequest($this->createLayer($otherOwner->id), 2);
        $validator = $this->createUserWithRole('Validator');

        $novaRequest = $this->novaRequestFor($validator);
        $resource = new NovaCertificationRequest($request);

        $this->app->instance(NovaRequest::class, $novaRequest);

        $photos = collect($resource->fields($novaRequest))
            ->first(fn ($field) => $field->name === __('Photos'));
        $this->assertNotNull($photos);

        $photos->resolveForDisplay($request);
        $this->assertStringNotContainsString('<img', (string) $photos->value);
        $paths = $this->mediaPaths($request);
        $this->assertCount(2, $paths);
        foreach ($paths as $path) {
            $this->assertStringNotContainsString($path, (string) $photos->value);
        }

        // Controprova: stesso campo, utente proprietario → URL generati.
        $ownerRequest = $this->novaRequestFor($otherOwner);
        $this->app->instance(NovaRequest::class, $ownerRequest);
        $ownerPhotos = collect($resource->fields($ownerRequest))
            ->first(fn ($field) => $field->name === __('Photos'));
        $ownerPhotos->resolveForDisplay($request);
        foreach ($paths as $path) {
            $this->assertStringContainsString($path, (string) $ownerPhotos->value);
        }
    }

    public function test_nobody_can_create_or_update_from_nova(): void
    {
        $admin = $this->createUserWithRole('Administrator');
        $owner = $this->createUserWithRole('Validator');
        $layer = $this->createLayer($owner->id);
        $request = $this->makeRequest($layer);

        $this->actingAs($admin)
            ->postJson('/nova-api/certification-requests', [
                'user' => $admin->id,
                'layer' => $layer->id,
                'serial_number' => 'X',
            ])
            ->assertForbidden();

        $this->actingAs($admin)
            ->putJson('/nova-api/certification-requests/'.$request->id, [
                'serial_number' => 'changed',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('certification_requests', ['id' => $request->id, 'serial_number' => $request->serial_number]);
        $this->assertDatabaseCount('certification_requests', 1);
    }

    public function test_validator_cannot_delete_own_layer_request(): void
    {
        $owner = $this->createUserWithRole('Validator');
        $request = $this->makeRequest($this->createLayer($owner->id), 1);

        $response = $this->actingAs($owner)
            ->deleteJson('/nova-api/certification-requests?resources[]='.$request->id);

        $this->assertContains($response->status(), [200, 403, 404]);
        $this->assertDatabaseHas('certification_requests', ['id' => $request->id]);
        $paths = $this->mediaPaths($request);
        $this->assertCount(1, $paths);
        Storage::disk('wmfe')->assertExists($paths[0]);
    }

    public function test_administrator_delete_removes_media_and_files(): void
    {
        $admin = $this->createUserWithRole('Administrator');
        $owner = $this->createUserWithRole('Validator');
        $request = $this->makeRequest($this->createLayer($owner->id), 2);
        $paths = $this->mediaPaths($request);
        $this->assertCount(2, $paths);

        $this->actingAs($admin)
            ->deleteJson('/nova-api/certification-requests?resources[]='.$request->id)
            ->assertOk();

        $this->assertDatabaseMissing('certification_requests', ['id' => $request->id]);
        $this->assertSame(0, Media::where('model_type', CertificationRequest::class)->where('model_id', $request->id)->count());
        foreach ($paths as $path) {
            Storage::disk('wmfe')->assertMissing($path);
        }
    }

    private function passportMenuItemVisibleFor(User $user): bool
    {
        $request = Request::create('/nova');
        $request->setUserResolver(fn () => $user);

        $section = collect(Nova::resolveMainMenu($request))
            ->first(fn ($section) => (string) $section->name === __('Passport'));

        if ($section === null || ! $section->authorizedToSee($request)) {
            return false;
        }

        $item = collect($section->items)
            ->first(fn ($item) => str_contains((string) $item->path, 'certification-requests'));

        return $item !== null && $item->authorizedToSee($request);
    }

    public function test_passport_menu_visible_only_to_administrator_and_validator(): void
    {
        $this->assertTrue($this->passportMenuItemVisibleFor($this->createUserWithRole('Administrator')));
        $this->assertTrue($this->passportMenuItemVisibleFor($this->createUserWithRole('Validator')));
        $this->assertFalse($this->passportMenuItemVisibleFor($this->createUserWithRole('Guest')));
        $this->assertFalse($this->passportMenuItemVisibleFor($this->createUserWithoutRole()));
    }
}

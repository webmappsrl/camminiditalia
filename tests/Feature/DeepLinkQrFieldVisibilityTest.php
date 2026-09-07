<?php

namespace Tests\Feature;

use App\Models\EcPoi;
use App\Models\EcTrack;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Wm\WmPackage\Http\Clients\DemClient;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class DeepLinkQrFieldVisibilityTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        RolesAndPermissionsService::seedDatabase();
        Storage::fake('public');
        // Creating an App with the toggle already true dispatches SyncWellKnownRegistryJob
        // (see AppObserver) — fake the bus (primary guard) and the disk (defense in depth)
        // so tests never push real jobs or write to the real shared well-known registry.
        Bus::fake();
        Storage::fake('well_known_registry');

        // Avoid real network calls to dem.maphub.it triggered by EcTrack/EcPoi observers on create.
        $this->app->bind(DemClient::class, fn () => new class extends DemClient
        {
            public function getElevation($x, $y)
            {
                return 100;
            }

            public function getTechData($geojson)
            {
                return [
                    'geometry' => $geojson['geometry'] ?? ['type' => 'LineString', 'coordinates' => [[0, 0, 100], [1, 1, 100]]],
                    'properties' => [
                        'duration_forward_hiking' => 60,
                        'duration_backward_hiking' => 60,
                    ],
                ];
            }

            public function getPointMatrix($featureCollection)
            {
                return ['type' => 'FeatureCollection', 'features' => []];
            }
        });
    }

    private function makeApp(bool $enabled): App
    {
        return App::factory()->create([
            'properties' => ['native_app_deep_link_enabled' => $enabled],
        ]);
    }

    private function makeAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('Administrator');

        return $admin;
    }

    private function findField(array $fields, string $attribute): ?array
    {
        foreach ($fields as $field) {
            if (($field['attribute'] ?? null) === $attribute) {
                return $field;
            }
            foreach (($field['fields'] ?? []) as $nested) {
                if (($nested['attribute'] ?? null) === $attribute) {
                    return $nested;
                }
            }
        }

        return null;
    }

    public function test_qr_field_hidden_on_ec_track_when_app_toggle_is_off(): void
    {
        $app = $this->makeApp(false);
        $track = EcTrack::factory()->create(['app_id' => $app->id, 'user_id' => $this->makeAdmin()->id, 'properties' => []]);

        $response = $this->actingAs($this->makeAdmin())
            ->getJson('/nova-api/ec-tracks/'.$track->id);

        $response->assertOk();
        $field = $this->findField($response->json('resource.fields'), 'deep_link_qr_code');
        $this->assertNull($field);
    }

    public function test_qr_field_visible_with_image_on_ec_track_when_app_toggle_is_on(): void
    {
        $app = $this->makeApp(true);
        $track = EcTrack::factory()->create(['app_id' => $app->id, 'user_id' => $this->makeAdmin()->id, 'properties' => []]);

        $response = $this->actingAs($this->makeAdmin())
            ->getJson('/nova-api/ec-tracks/'.$track->id);

        $response->assertOk();
        $field = $this->findField($response->json('resource.fields'), 'deep_link_qr_code');
        $this->assertNotNull($field);
        $this->assertStringContainsString('data:image/svg+xml;base64,', $field['value']);
        $this->assertStringContainsString('/map?track='.$track->id, $field['value']);
        $this->assertStringContainsString('download="track-'.$track->id.'-qrcode.svg"', $field['value']);
        $this->assertStringContainsString('navigator.clipboard.writeText', $field['value']);
    }

    public function test_qr_field_hidden_on_ec_poi_when_app_toggle_is_off(): void
    {
        $app = $this->makeApp(false);
        $poi = EcPoi::factory()->create(['app_id' => $app->id, 'user_id' => $this->makeAdmin()->id, 'properties' => []]);

        $response = $this->actingAs($this->makeAdmin())
            ->getJson('/nova-api/ec-pois/'.$poi->id);

        $response->assertOk();
        $field = $this->findField($response->json('resource.fields'), 'deep_link_qr_code');
        $this->assertNull($field);
    }

    public function test_qr_field_visible_with_image_on_ec_poi_when_app_toggle_is_on(): void
    {
        $app = $this->makeApp(true);
        $poi = EcPoi::factory()->create(['app_id' => $app->id, 'user_id' => $this->makeAdmin()->id, 'properties' => []]);

        $response = $this->actingAs($this->makeAdmin())
            ->getJson('/nova-api/ec-pois/'.$poi->id);

        $response->assertOk();
        $field = $this->findField($response->json('resource.fields'), 'deep_link_qr_code');
        $this->assertNotNull($field);
        $this->assertStringContainsString('data:image/svg+xml;base64,', $field['value']);
        $this->assertStringContainsString('/map?poi='.$poi->id, $field['value']);
    }

    public function test_validator_can_see_qr_field_on_ec_track(): void
    {
        $app = $this->makeApp(true);

        $validator = User::factory()->create();
        $validator->assignRole('Validator');

        $track = EcTrack::factory()->create(['app_id' => $app->id, 'user_id' => $validator->id, 'properties' => []]);

        $response = $this->actingAs($validator)
            ->getJson('/nova-api/ec-tracks/'.$track->id);

        $response->assertOk();
        $field = $this->findField($response->json('resource.fields'), 'deep_link_qr_code');
        $this->assertNotNull($field);
    }

    public function test_validator_can_see_qr_field_on_ec_poi(): void
    {
        $app = $this->makeApp(true);
        $poi = EcPoi::factory()->create(['app_id' => $app->id, 'user_id' => $this->makeAdmin()->id, 'properties' => []]);

        $validator = User::factory()->create();
        $validator->assignRole('Validator');

        $response = $this->actingAs($validator)
            ->getJson('/nova-api/ec-pois/'.$poi->id);

        $response->assertOk();
        $field = $this->findField($response->json('resource.fields'), 'deep_link_qr_code');
        $this->assertNotNull($field);
    }
}

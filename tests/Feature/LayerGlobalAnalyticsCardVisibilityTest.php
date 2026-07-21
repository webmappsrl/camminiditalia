<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class LayerGlobalAnalyticsCardVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RolesAndPermissionsService::seedDatabase();
    }

    private function makeAppWithAnalyticsEnabled(): App
    {
        return App::factory()->create([
            'properties' => ['analytics_app_enabled' => true],
        ]);
    }

    public function test_administrator_sees_global_card_on_index(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Administrator');
        $app = $this->makeAppWithAnalyticsEnabled();
        Layer::factory()->create(['user_id' => $admin->id, 'app_id' => $app->id]);

        $response = $this->actingAs($admin)->getJson('/nova-api/layers/cards');

        $response->assertOk();
        // The Nova cards-index endpoint responds with a flat JSON array of card
        // payloads (no top-level "cards" wrapper key).
        $components = collect($response->json())->pluck('component');
        $this->assertContains('layer-analytics-card', $components);
    }

    public function test_validator_does_not_see_global_card_on_index(): void
    {
        $validator = User::factory()->create();
        $validator->assignRole('Validator');
        $app = $this->makeAppWithAnalyticsEnabled();
        Layer::factory()->create(['user_id' => $validator->id, 'app_id' => $app->id]);

        $response = $this->actingAs($validator)->getJson('/nova-api/layers/cards');

        $response->assertOk();
        $components = collect($response->json())->pluck('component');
        $this->assertNotContains('layer-analytics-card', $components);
    }

    public function test_global_card_hidden_when_analytics_disabled(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Administrator');
        $app = App::factory()->create(['properties' => ['analytics_app_enabled' => false, 'analytics_webapp_enabled' => false]]);
        Layer::factory()->create(['user_id' => $admin->id, 'app_id' => $app->id]);

        $response = $this->actingAs($admin)->getJson('/nova-api/layers/cards');

        $response->assertOk();
        $components = collect($response->json())->pluck('component');
        $this->assertNotContains('layer-analytics-card', $components);
    }
}

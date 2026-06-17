<?php

namespace Tests\Feature;

use App\Models\User;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Wm\WmPackage\Nova\Actions\AddLayersToConfigHomeAction;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class LayerActionsVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RolesAndPermissionsService::seedDatabase();
        App::factory()->create();
    }

    private function makeLayer(): Layer
    {
        $owner = User::factory()->create();
        $owner->assignRole('Administrator');

        return Layer::factory()->create(['user_id' => $owner->id]);
    }

    public function test_validator_cannot_see_add_to_home_action(): void
    {
        $validator = User::factory()->create();
        $validator->assignRole('Validator');
        $layer = $this->makeLayer();

        $response = $this->actingAs($validator)
            ->getJson('/nova-api/layers/actions?resourceId='.$layer->id);

        $response->assertOk();

        $actionNames = collect($response->json('actions'))->pluck('uriKey')->toArray();
        $this->assertNotContains('aggiungi-alla-home', $actionNames);
    }

    public function test_administrator_can_see_add_to_home_action(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Administrator');
        $layer = $this->makeLayer();

        $response = $this->actingAs($admin)
            ->getJson('/nova-api/layers/actions?resourceId='.$layer->id);

        $response->assertOk();

        $actionNames = collect($response->json('actions'))->pluck('uriKey')->toArray();
        $this->assertContains('aggiungi-alla-home', $actionNames);
    }

    public function test_validator_cannot_run_add_to_home_action(): void
    {
        $validator = User::factory()->create();
        $validator->assignRole('Validator');
        $layer = $this->makeLayer();

        $response = $this->actingAs($validator)
            ->postJson('/nova-api/layers/action', [
                'action' => 'aggiungi-alla-home',
                'resources' => (string) $layer->id,
            ]);

        // Nova returns 404 when canSee=false (action not found in availableActions), or 403 when canRun=false
        $this->assertContains($response->status(), [403, 404]);
    }
}

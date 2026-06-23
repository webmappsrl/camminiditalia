<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class EcPoiNovaActionsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        RolesAndPermissionsService::seedDatabase();
        if (App::count() === 0) {
            App::factory()->create();
        }
    }

    private function makeEcPoi(): \Wm\WmPackage\Models\EcPoi
    {
        $admin = User::factory()->create();
        $admin->assignRole('Administrator');

        return \App\Models\EcPoi::factory()->create(['user_id' => $admin->id, 'properties' => []]);
    }

    public function test_validator_cannot_see_modifying_actions(): void
    {
        $validator = User::factory()->create();
        $validator->assignRole('Validator');
        $ecPoi = $this->makeEcPoi();

        $response = $this->actingAs($validator)
            ->getJson('/nova-api/ec-pois/actions?resourceId='.$ecPoi->id);

        $response->assertOk();

        $actionKeys = collect($response->json('actions'))->pluck('uriKey')->toArray();
        $this->assertNotContains('execute-ecpoi-data-chain', $actionKeys);
        $this->assertNotContains('upload-poi-file', $actionKeys);
        $this->assertNotContains('translate-descriptions-names', $actionKeys);
    }

    public function test_validator_can_see_download_action(): void
    {
        $validator = User::factory()->create();
        $validator->assignRole('Validator');
        $ecPoi = $this->makeEcPoi();

        $response = $this->actingAs($validator)
            ->getJson('/nova-api/ec-pois/actions?resourceId='.$ecPoi->id);

        $response->assertOk();

        $actionKeys = collect($response->json('actions'))->pluck('uriKey')->toArray();
        $this->assertContains('download-pois', $actionKeys);
    }

    public function test_validator_cannot_run_modifying_actions(): void
    {
        $validator = User::factory()->create();
        $validator->assignRole('Validator');
        $ecPoi = $this->makeEcPoi();

        foreach (['execute-ecpoi-data-chain', 'upload-poi-file', 'translate-descriptions-names'] as $actionKey) {
            $response = $this->actingAs($validator)
                ->postJson('/nova-api/ec-pois/action', [
                    'action' => $actionKey,
                    'resources' => (string) $ecPoi->id,
                ]);

            $this->assertContains($response->status(), [403, 404],
                "Action $actionKey should be blocked for Validator");
        }
    }

    public function test_administrator_can_see_all_actions(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Administrator');
        $ecPoi = $this->makeEcPoi();

        $response = $this->actingAs($admin)
            ->getJson('/nova-api/ec-pois/actions?resourceId='.$ecPoi->id);

        $response->assertOk();

        $actionKeys = collect($response->json('actions'))->pluck('uriKey')->toArray();
        $this->assertContains('execute-ecpoi-data-chain', $actionKeys);
        $this->assertContains('download-pois', $actionKeys);
        $this->assertContains('upload-poi-file', $actionKeys);
        $this->assertContains('translate-descriptions-names', $actionKeys);
    }
}

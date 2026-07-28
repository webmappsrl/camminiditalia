<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class EcPoiNovaAuthorizationTest extends TestCase
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

    private function makeUser(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_validator_with_layer_can_see_creation_fields(): void
    {
        $validator = $this->makeUser('Validator');
        Layer::factory()->create(['user_id' => $validator->id]);

        $response = $this->actingAs($validator)
            ->getJson('/nova-api/ec-pois/creation-fields');

        $response->assertOk();
    }

    public function test_validator_without_layer_cannot_see_creation_fields(): void
    {
        $validator = $this->makeUser('Validator');

        $response = $this->actingAs($validator)
            ->getJson('/nova-api/ec-pois/creation-fields');

        $this->assertContains($response->status(), [403, 404]);
    }

    public function test_validator_can_update_own_ec_poi_via_nova(): void
    {
        $validator = $this->makeUser('Validator');
        Layer::factory()->create(['user_id' => $validator->id]);
        $ecPoi = EcPoi::factory()->create(['user_id' => $validator->id, 'properties' => []]);

        $response = $this->actingAs($validator)
            ->getJson('/nova-api/ec-pois/'.$ecPoi->id.'/update-fields');

        $response->assertOk();
    }

    public function test_validator_cannot_update_ec_poi_of_another_user_via_nova(): void
    {
        $validator = $this->makeUser('Validator');
        $otherValidator = $this->makeUser('Validator');
        $ecPoi = EcPoi::factory()->create(['user_id' => $otherValidator->id, 'properties' => []]);

        $response = $this->actingAs($validator)
            ->getJson('/nova-api/ec-pois/'.$ecPoi->id.'/update-fields');

        $this->assertContains($response->status(), [403, 404]);
    }

    public function test_administrator_can_see_creation_fields(): void
    {
        $admin = $this->makeUser('Administrator');

        $response = $this->actingAs($admin)
            ->getJson('/nova-api/ec-pois/creation-fields');

        $response->assertOk();
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class EcPoiLayerAssignmentTest extends TestCase
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

    private function createEcPoiPayload(User $user): array
    {
        return [
            'name' => 'Test POI',
            'global' => '1',
            'app' => App::first()->id,
            'user' => $user->id,
            'geometry' => json_encode([
                'type' => 'Point',
                'coordinates' => [11.0, 43.0],
            ]),
        ];
    }

    public function test_validator_with_single_layer_creates_ec_poi_auto_assigned(): void
    {
        $validator = $this->makeUser('Validator');
        $layer = Layer::factory()->create(['user_id' => $validator->id]);

        $response = $this->actingAs($validator)
            ->postJson('/nova-api/ec-pois', $this->createEcPoiPayload($validator));

        $response->assertCreated();

        $poiId = $response->json('id') ?? $response->json('resource.id');
        $this->assertTrue($layer->ecPois()->where('ec_pois.id', $poiId)->exists());
    }

    public function test_validator_with_multiple_layers_must_choose_own_layer(): void
    {
        $validator = $this->makeUser('Validator');
        $layerA = Layer::factory()->create(['user_id' => $validator->id]);
        $layerB = Layer::factory()->create(['user_id' => $validator->id]);

        $payload = $this->createEcPoiPayload($validator);
        $payload['properties->layer_id'] = (string) $layerB->id;

        $response = $this->actingAs($validator)
            ->postJson('/nova-api/ec-pois', $payload);

        $response->assertCreated();

        $poiId = $response->json('id') ?? $response->json('resource.id');
        $this->assertTrue($layerB->ecPois()->where('ec_pois.id', $poiId)->exists());
        $this->assertFalse($layerA->ecPois()->where('ec_pois.id', $poiId)->exists());
    }

    public function test_validator_cannot_assign_layer_not_owned(): void
    {
        $validator = $this->makeUser('Validator');
        Layer::factory()->create(['user_id' => $validator->id]);

        $otherValidator = $this->makeUser('Validator');
        $foreignLayer = Layer::factory()->create(['user_id' => $otherValidator->id]);

        $payload = $this->createEcPoiPayload($validator);
        $payload['properties->layer_id'] = (string) $foreignLayer->id;

        $response = $this->actingAs($validator)
            ->postJson('/nova-api/ec-pois', $payload);

        $response->assertStatus(422);
    }
}

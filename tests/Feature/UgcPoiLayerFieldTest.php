<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\UgcPoi;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class UgcPoiLayerFieldTest extends TestCase
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

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('Administrator');

        return $admin;
    }

    private function validator(): User
    {
        $validator = User::factory()->create();
        $validator->assignRole('Validator');

        return $validator;
    }

    public function test_administrator_sees_layer_link_field_on_index(): void
    {
        $admin = $this->admin();
        $layer = Layer::factory()->create(['name' => ['it' => 'Via Francigena Test', 'en' => 'Via Francigena Test']]);
        UgcPoi::factory()->create(['properties' => ['layer_id' => $layer->id]]);

        $response = $this->actingAs($admin)->getJson('/nova-api/ugc-pois?perPage=25');

        $response->assertOk();

        $fieldAttributes = collect($response->json('resources.0.fields'))->pluck('attribute')->toArray();
        $this->assertContains('layer_link', $fieldAttributes);

        $layerField = collect($response->json('resources.0.fields'))->firstWhere('attribute', 'layer_link');
        $this->assertStringContainsString('Via Francigena Test', $layerField['value']);
        $this->assertStringContainsString('/nova/resources/layers/'.$layer->id, $layerField['value']);
    }

    public function test_layer_link_field_shows_placeholder_when_layer_id_missing(): void
    {
        $admin = $this->admin();
        UgcPoi::factory()->create(['properties' => []]);

        $response = $this->actingAs($admin)->getJson('/nova-api/ugc-pois?perPage=25');

        $response->assertOk();

        $layerField = collect($response->json('resources.0.fields'))->firstWhere('attribute', 'layer_link');
        $this->assertSame('Non assegnato', $layerField['value']);
    }

    public function test_validator_does_not_see_layer_link_field(): void
    {
        $validator = $this->validator();
        UgcPoi::factory()->create(['properties' => []]);

        $response = $this->actingAs($validator)->getJson('/nova-api/ugc-pois?perPage=25');

        $response->assertOk();

        $fieldAttributes = collect($response->json('resources.0.fields'))->pluck('attribute')->toArray();
        $this->assertNotContains('layer_link', $fieldAttributes);
    }

    public function test_administrator_can_filter_ugc_pois_by_layer(): void
    {
        $admin = $this->admin();
        $layerA = Layer::factory()->create();
        $layerB = Layer::factory()->create();
        $poiA = UgcPoi::factory()->create(['properties' => ['layer_id' => $layerA->id]]);
        UgcPoi::factory()->create(['properties' => ['layer_id' => $layerB->id]]);

        $filters = base64_encode(json_encode([['Select:layer_filter' => $layerA->id]]));

        $response = $this->actingAs($admin)->getJson(
            '/nova-api/ugc-pois?perPage=25&filters='.$filters
        );

        $response->assertOk();

        $ids = collect($response->json('resources'))->pluck('id.value')->toArray();
        $this->assertEquals([(string) $poiA->id], $ids);
    }
}

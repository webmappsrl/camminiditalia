<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use App\Models\User;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class UgcTrackLayerFieldTest extends TestCase
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
        UgcTrack::factory()->create(['properties' => ['layer_id' => $layer->id]]);

        $response = $this->actingAs($admin)->getJson('/nova-api/ugc-tracks?perPage=25');

        $response->assertOk();

        $fieldAttributes = collect($response->json('resources.0.fields'))->pluck('attribute')->toArray();
        $this->assertContains('layer_link', $fieldAttributes);

        $layerField = collect($response->json('resources.0.fields'))->firstWhere('attribute', 'layer_link');
        $this->assertStringContainsString('Via Francigena Test', $layerField['value']);
        $this->assertStringContainsString('/nova/resources/layers/'.$layer->id, $layerField['value']);
    }

    public function test_layer_link_field_shows_deleted_placeholder_when_layer_missing(): void
    {
        $admin = $this->admin();
        $missingId = 999999;
        UgcTrack::factory()->create(['properties' => ['layer_id' => $missingId]]);

        $response = $this->actingAs($admin)->getJson('/nova-api/ugc-tracks?perPage=25');

        $response->assertOk();

        $layerField = collect($response->json('resources.0.fields'))->firstWhere('attribute', 'layer_link');
        $this->assertSame("Layer eliminato (ID: {$missingId})", $layerField['value']);
    }

    public function test_validator_does_not_see_layer_link_field_or_filter(): void
    {
        $validator = $this->validator();
        UgcTrack::factory()->create(['properties' => []]);

        // UgcTrack::indexQuery blocca i non-Administrator (query 1=0): verifichiamo solo l'assenza dei field,
        // la request resta 200 con resources vuoti per via dell'indexQuery esistente.
        $response = $this->actingAs($validator)->getJson('/nova-api/ugc-tracks?perPage=25');

        $response->assertOk();
        $this->assertEmpty($response->json('resources'));
    }

    public function test_administrator_can_filter_ugc_tracks_by_layer(): void
    {
        $admin = $this->admin();
        $layerA = Layer::factory()->create();
        $layerB = Layer::factory()->create();
        $trackA = UgcTrack::factory()->create(['properties' => ['layer_id' => $layerA->id]]);
        UgcTrack::factory()->create(['properties' => ['layer_id' => $layerB->id]]);

        $filters = base64_encode(json_encode([['Select:layer_filter' => $layerA->id]]));

        $response = $this->actingAs($admin)->getJson(
            '/nova-api/ugc-tracks?perPage=25&filters='.$filters
        );

        $response->assertOk();

        $ids = collect($response->json('resources'))->pluck('id.value')->toArray();
        $this->assertEquals([(string) $trackA->id], $ids);
    }
}

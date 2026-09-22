<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
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
        Storage::fake('wmfe');
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

    public function test_validator_can_delete_own_ec_poi_via_nova(): void
    {
        $validator = $this->makeUser('Validator');
        Layer::factory()->create(['user_id' => $validator->id]);
        $ecPoi = EcPoi::factory()->create(['user_id' => $validator->id, 'properties' => []]);

        $this->actingAs($validator)
            ->deleteJson('/nova-api/ec-pois', ['resources' => [$ecPoi->id]])
            ->assertOk();

        $this->assertDatabaseMissing('ec_pois', ['id' => $ecPoi->id]);
    }

    public function test_validator_cannot_delete_ec_poi_of_another_user_via_nova(): void
    {
        $validator = $this->makeUser('Validator');
        $otherValidator = $this->makeUser('Validator');
        $ecPoi = EcPoi::factory()->create(['user_id' => $otherValidator->id, 'properties' => []]);

        $this->actingAs($validator)
            ->deleteJson('/nova-api/ec-pois', ['resources' => [$ecPoi->id]])
            ->assertOk();

        $this->assertDatabaseHas('ec_pois', ['id' => $ecPoi->id]);
    }

    public function test_validator_deleting_mixed_ownership_resources_only_deletes_own(): void
    {
        $validator = $this->makeUser('Validator');
        $otherValidator = $this->makeUser('Validator');
        $ownEcPoi = EcPoi::factory()->create(['user_id' => $validator->id, 'properties' => []]);
        $otherEcPoi = EcPoi::factory()->create(['user_id' => $otherValidator->id, 'properties' => []]);

        $this->actingAs($validator)
            ->deleteJson('/nova-api/ec-pois', ['resources' => [$ownEcPoi->id, $otherEcPoi->id]])
            ->assertOk();

        $this->assertDatabaseMissing('ec_pois', ['id' => $ownEcPoi->id]);
        $this->assertDatabaseHas('ec_pois', ['id' => $otherEcPoi->id]);
    }

    public function test_administrator_can_delete_any_ec_poi_via_nova(): void
    {
        $admin = $this->makeUser('Administrator');
        $validator = $this->makeUser('Validator');
        $ecPoi = EcPoi::factory()->create(['user_id' => $validator->id, 'properties' => []]);

        $this->actingAs($admin)
            ->deleteJson('/nova-api/ec-pois', ['resources' => [$ecPoi->id]])
            ->assertOk();

        $this->assertDatabaseMissing('ec_pois', ['id' => $ecPoi->id]);
    }

    public function test_validator_cannot_delete_own_ec_poi_still_linked_to_a_track(): void
    {
        // EcTrackObserver::created() (wm-package) dispatcha una Bus::chain che include
        // UpdateEcTrack3DDemJob, il quale in ambiente di test (QUEUE_CONNECTION=sync) chiama
        // la vera API DEM esterna — Queue::fake()+Http::fake() isolano dalla rete reale,
        // stesso pattern di LayerFeatureControllerTest::test_sync_ec_tracks_triggers_pbf_regeneration().
        Queue::fake();
        Http::fake();

        $validator = $this->makeUser('Validator');
        Layer::factory()->create(['user_id' => $validator->id]);
        $ecPoi = EcPoi::factory()->create(['user_id' => $validator->id, 'properties' => []]);
        $ecTrack = \App\Models\EcTrack::factory()->create(['user_id' => $validator->id, 'properties' => []]);
        $ecPoi->ecTracks()->attach($ecTrack->id);

        $response = $this->actingAs($validator)
            ->deleteJson('/nova-api/ec-pois', ['resources' => [$ecPoi->id]]);

        // Guard preesistente in Wm\WmPackage\Observers\EcPoiObserver::deleting() (wm-package):
        // 500 e non 403/422 perché l'eccezione arriva a runtime durante la cancellazione, dopo
        // che l'autorizzazione ha già dato l'ok — comportamento invariato, non introdotto qui.
        $response->assertStatus(500);
        $this->assertDatabaseHas('ec_pois', ['id' => $ecPoi->id]);
    }

    public function test_guest_cannot_delete_ec_poi_via_nova(): void
    {
        $guest = $this->makeUser('Guest');
        $ecPoi = EcPoi::factory()->create(['properties' => []]);

        // A differenza del Validator non proprietario (che riceve 200 con il record filtrato
        // in silenzio da DeleteResourceRequest::deletableModels()), il Guest viene bloccato
        // prima, a livello di autorizzazione generale della risorsa Nova — 403.
        $response = $this->actingAs($guest)
            ->deleteJson('/nova-api/ec-pois', ['resources' => [$ecPoi->id]]);

        $this->assertContains($response->status(), [403, 404]);
        $this->assertDatabaseHas('ec_pois', ['id' => $ecPoi->id]);
    }
}

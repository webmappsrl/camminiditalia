<?php

namespace Tests\Feature;

use App\Models\EcPoi;
use App\Models\EcTrack;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\PBFGeneratorService;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class LayerFeatureControllerTest extends TestCase
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

    private function makePoi(?int $userId)
    {
        return EcPoi::factory()->create(['user_id' => $userId, 'properties' => []]);
    }

    public function test_validator_does_not_see_ec_poi_of_another_validator_in_associated_features_edit_mode(): void
    {
        $validatorA = $this->makeUser('Validator');
        $validatorB = $this->makeUser('Validator');
        $layer = Layer::factory()->create(['user_id' => $validatorA->id]);

        $poiOfB = $this->makePoi($validatorB->id);
        // Insert diretto per bypassare LayerableObserver, che su attach() riassegnerebbe user_id al proprietario del layer.
        \DB::table('layerables')->insert([
            'layer_id' => $layer->id,
            'layerable_id' => $poiOfB->id,
            'layerable_type' => EcPoi::class,
        ]);

        $response = $this->actingAs($validatorA)
            ->getJson('/nova-vendor/layer-features/features/'.$layer->id.'?model='.urlencode(EcPoi::class).'&view_mode=edit&manual=1');

        $response->assertOk();
        $ids = collect($response->json('features'))->pluck('id')->toArray();
        $this->assertNotContains($poiOfB->id, $ids);
    }

    public function test_validator_does_not_see_ec_poi_of_another_validator_in_details_mode(): void
    {
        $validatorA = $this->makeUser('Validator');
        $validatorB = $this->makeUser('Validator');
        $layer = Layer::factory()->create(['user_id' => $validatorA->id]);

        $poiOfB = $this->makePoi($validatorB->id);
        \DB::table('layerables')->insert([
            'layer_id' => $layer->id,
            'layerable_id' => $poiOfB->id,
            'layerable_type' => EcPoi::class,
        ]);

        $response = $this->actingAs($validatorA)
            ->getJson('/nova-vendor/layer-features/features/'.$layer->id.'?model='.urlencode(EcPoi::class).'&view_mode=details&manual=1');

        $response->assertOk();
        $ids = collect($response->json('features'))->pluck('id')->toArray();
        $this->assertNotContains($poiOfB->id, $ids);
    }

    public function test_administrator_sees_ec_poi_of_layer_owner_in_associated_features(): void
    {
        $admin = $this->makeUser('Administrator');
        $layerOwner = $this->makeUser('Validator');
        $layer = Layer::factory()->create(['user_id' => $layerOwner->id]);

        $poiOfOwner = $this->makePoi($layerOwner->id);
        \DB::table('layerables')->insert([
            'layer_id' => $layer->id,
            'layerable_id' => $poiOfOwner->id,
            'layerable_type' => EcPoi::class,
        ]);

        // POI di proprietà dell'admin stesso, non associato al layer: non deve comparire.
        $poiOfAdmin = $this->makePoi($admin->id);

        $response = $this->actingAs($admin)
            ->getJson('/nova-vendor/layer-features/features/'.$layer->id.'?model='.urlencode(EcPoi::class).'&view_mode=edit&manual=1');

        $response->assertOk();
        $ids = collect($response->json('features'))->pluck('id')->toArray();
        $this->assertContains($poiOfOwner->id, $ids);
        $this->assertNotContains($poiOfAdmin->id, $ids);
    }

    public function test_validator_sees_own_ec_poi_in_associated_features(): void
    {
        $validator = $this->makeUser('Validator');
        $layer = Layer::factory()->create(['user_id' => $validator->id]);

        $ownPoi = $this->makePoi($validator->id);
        $layer->ecPois()->attach($ownPoi->id);

        $response = $this->actingAs($validator)
            ->getJson('/nova-vendor/layer-features/features/'.$layer->id.'?model='.urlencode(EcPoi::class).'&view_mode=edit&manual=1');

        $response->assertOk();
        $ids = collect($response->json('features'))->pluck('id')->toArray();
        $this->assertContains($ownPoi->id, $ids);
    }

    public function test_validator_sync_filters_out_ec_poi_not_owned(): void
    {
        $validatorA = $this->makeUser('Validator');
        $validatorB = $this->makeUser('Validator');
        $layer = Layer::factory()->create(['user_id' => $validatorA->id]);

        $ownPoi = $this->makePoi($validatorA->id);
        $poiOfB = $this->makePoi($validatorB->id);

        $response = $this->actingAs($validatorA)
            ->postJson('/nova-vendor/layer-features/sync/'.$layer->id, [
                'model' => EcPoi::class,
                'features' => [$ownPoi->id, $poiOfB->id],
            ]);

        $response->assertOk();
        $assignedIds = $response->json('assigned_ids');
        $this->assertContains($ownPoi->id, $assignedIds);
        $this->assertNotContains($poiOfB->id, $assignedIds);
        $this->assertTrue($layer->ecPois()->where('ec_pois.id', $ownPoi->id)->exists());
        $this->assertFalse($layer->ecPois()->where('ec_pois.id', $poiOfB->id)->exists());
    }

    public function test_administrator_sync_filters_out_ec_poi_not_owned(): void
    {
        $admin = $this->makeUser('Administrator');
        $otherOwner = $this->makeUser('Validator');
        $layer = Layer::factory()->create(['user_id' => $admin->id]);

        $ownPoi = $this->makePoi($admin->id);
        $poiOfOther = $this->makePoi($otherOwner->id);

        $response = $this->actingAs($admin)
            ->postJson('/nova-vendor/layer-features/sync/'.$layer->id, [
                'model' => EcPoi::class,
                'features' => [$ownPoi->id, $poiOfOther->id],
            ]);

        $response->assertOk();
        $assignedIds = $response->json('assigned_ids');
        $this->assertContains($ownPoi->id, $assignedIds);
        $this->assertNotContains($poiOfOther->id, $assignedIds);
    }

    public function test_sync_auto_true_assigns_only_owned_ec_pois_ignoring_taxonomy(): void
    {
        $validator = $this->makeUser('Validator');
        $otherValidator = $this->makeUser('Validator');
        $layer = Layer::factory()->create(['user_id' => $validator->id]);

        $ownPoiA = $this->makePoi($validator->id);
        $ownPoiB = $this->makePoi($validator->id);
        $poiOfOther = $this->makePoi($otherValidator->id);

        $response = $this->actingAs($validator)
            ->postJson('/nova-vendor/layer-features/sync/'.$layer->id, [
                'model' => EcPoi::class,
                'auto' => true,
            ]);

        $response->assertOk();
        $assignedIds = $response->json('assigned_ids');
        $this->assertContains($ownPoiA->id, $assignedIds);
        $this->assertContains($ownPoiB->id, $assignedIds);
        $this->assertNotContains($poiOfOther->id, $assignedIds);
    }

    public function test_sync_auto_true_replaces_pivot_with_only_callers_ec_pois(): void
    {
        $validator = $this->makeUser('Validator');
        $otherValidator = $this->makeUser('Validator');
        $layer = Layer::factory()->create(['user_id' => $validator->id]);

        $poiOfOtherPreAssigned = $this->makePoi($otherValidator->id);
        // Insert diretto per bypassare LayerableObserver, che su attach() riassegnerebbe user_id al proprietario del layer.
        \DB::table('layerables')->insert([
            'layer_id' => $layer->id,
            'layerable_id' => $poiOfOtherPreAssigned->id,
            'layerable_type' => EcPoi::class,
        ]);

        $ownPoi = $this->makePoi($validator->id);

        $response = $this->actingAs($validator)
            ->postJson('/nova-vendor/layer-features/sync/'.$layer->id, [
                'model' => EcPoi::class,
                'auto' => true,
            ]);

        $response->assertOk();
        $assignedIds = $response->json('assigned_ids');
        $this->assertContains($ownPoi->id, $assignedIds);
        $this->assertNotContains($poiOfOtherPreAssigned->id, $assignedIds);
    }

    public function test_sync_ec_tracks_triggers_pbf_regeneration(): void
    {
        // EcTrackObserver::created() dispatcha una Bus::chain che include UpdateEcTrack3DDemJob,
        // il quale in ambiente di test (QUEUE_CONNECTION=sync) chiama la vera API DEM esterna.
        // Queue::fake()+Http::fake() evitano la chiamata di rete reale (stesso pattern di
        // LayerOwnershipTransferTest::setUp()), rendendo il test deterministico.
        Queue::fake();
        Http::fake();

        $validator = $this->makeUser('Validator');
        $layer = Layer::factory()->create(['user_id' => $validator->id]);
        $ownTrack = EcTrack::factory()->create(['user_id' => $validator->id, 'properties' => []]);

        $pbfMock = \Mockery::mock(PBFGeneratorService::class);
        // Il flusso di sync di una singola traccia invoca regeneratePbfsForLayer da due
        // path indipendenti: LayerFeatureController::sync() (chiamata esplicita per la
        // relazione ecTracks) e Wm\WmPackage\Observers\LayerableObserver::created()
        // (fired dal pivot Layerable creato tramite ->sync(), che usa un custom pivot
        // model e quindi dispatcha eventi Eloquent). Per una sola traccia il totale è 2.
        $pbfMock->shouldReceive('regeneratePbfsForLayer')
            ->twice()
            ->withArgs(fn (Layer $l) => $l->id === $layer->id);
        $this->app->instance(PBFGeneratorService::class, $pbfMock);

        $response = $this->actingAs($validator)
            ->postJson('/nova-vendor/layer-features/sync/'.$layer->id, [
                'model' => EcTrack::class,
                'features' => [$ownTrack->id],
            ]);

        $response->assertOk();
    }

    public function test_validator_gets_403_calling_get_features_on_layer_not_owned(): void
    {
        $validatorA = $this->makeUser('Validator');
        $validatorB = $this->makeUser('Validator');
        $layer = Layer::factory()->create(['user_id' => $validatorB->id]);

        $response = $this->actingAs($validatorA)
            ->getJson('/nova-vendor/layer-features/features/'.$layer->id.'?model='.urlencode(EcPoi::class).'&view_mode=edit&manual=1');

        $response->assertStatus(403);
    }

    public function test_validator_gets_403_calling_sync_on_layer_not_owned(): void
    {
        $validatorA = $this->makeUser('Validator');
        $validatorB = $this->makeUser('Validator');
        $layer = Layer::factory()->create(['user_id' => $validatorB->id]);
        $poi = $this->makePoi($validatorA->id);

        $response = $this->actingAs($validatorA)
            ->postJson('/nova-vendor/layer-features/sync/'.$layer->id, [
                'model' => EcPoi::class,
                'features' => [$poi->id],
            ]);

        $response->assertStatus(403);
    }

    public function test_get_features_rejects_model_not_in_allowlist(): void
    {
        $validator = $this->makeUser('Validator');
        $layer = Layer::factory()->create(['user_id' => $validator->id]);

        $response = $this->actingAs($validator)
            ->getJson('/nova-vendor/layer-features/features/'.$layer->id.'?model='.urlencode(User::class).'&view_mode=edit&manual=1');

        $response->assertStatus(400);
    }

    public function test_sync_rejects_model_not_in_allowlist(): void
    {
        $validator = $this->makeUser('Validator');
        $layer = Layer::factory()->create(['user_id' => $validator->id]);

        $response = $this->actingAs($validator)
            ->postJson('/nova-vendor/layer-features/sync/'.$layer->id, [
                'model' => User::class,
                'features' => [],
            ]);

        $response->assertStatus(400);
    }

    public function test_get_features_accepts_wm_package_ec_poi_model_alias(): void
    {
        // Il frontend Nova invia il valore letto da config('wm-package.ec_poi_model'), che
        // di default (chiave non configurata) risolve a Wm\WmPackage\Models\EcPoi, non
        // App\Models\EcPoi. L'allowlist deve accettare entrambe le varianti.
        $validator = $this->makeUser('Validator');
        $layer = Layer::factory()->create(['user_id' => $validator->id]);

        $response = $this->actingAs($validator)
            ->getJson('/nova-vendor/layer-features/features/'.$layer->id.'?model='.urlencode(\Wm\WmPackage\Models\EcPoi::class).'&view_mode=edit&manual=1');

        $response->assertOk();
    }

    public function test_sync_ec_pois_does_not_trigger_pbf_regeneration(): void
    {
        $validator = $this->makeUser('Validator');
        $layer = Layer::factory()->create(['user_id' => $validator->id]);
        $ownPoi = $this->makePoi($validator->id);

        $pbfMock = \Mockery::mock(PBFGeneratorService::class);
        $pbfMock->shouldNotReceive('regeneratePbfsForLayer');
        $this->app->instance(PBFGeneratorService::class, $pbfMock);

        $response = $this->actingAs($validator)
            ->postJson('/nova-vendor/layer-features/sync/'.$layer->id, [
                'model' => EcPoi::class,
                'features' => [$ownPoi->id],
            ]);

        $response->assertOk();
    }

    public function test_admin_on_orphaned_layer_sees_only_default_owner_ec_poi(): void
    {
        $admin = $this->makeUser('Administrator');
        $fallbackOwner = $this->makeUser('Validator');
        config(['camminiditalia.default_owner_id' => $fallbackOwner->id]);

        $layer = Layer::factory()->create(['user_id' => null]);

        $poiOfFallbackOwner = $this->makePoi($fallbackOwner->id);
        \DB::table('layerables')->insert([
            'layer_id' => $layer->id,
            'layerable_id' => $poiOfFallbackOwner->id,
            'layerable_type' => EcPoi::class,
        ]);

        $otherOrphan = $this->makeUser('Validator');
        $poiOfOtherOrphanOwner = $this->makePoi($otherOrphan->id);

        $response = $this->actingAs($admin)
            ->getJson('/nova-vendor/layer-features/features/'.$layer->id.'?model='.urlencode(EcPoi::class).'&view_mode=edit&manual=1');

        $response->assertOk();
        $ids = collect($response->json('features'))->pluck('id')->toArray();
        $this->assertContains($poiOfFallbackOwner->id, $ids);
        $this->assertNotContains($poiOfOtherOrphanOwner->id, $ids);
    }

    public function test_sync_auto_true_on_orphaned_layer_assigns_only_default_owner_ec_poi(): void
    {
        $admin = $this->makeUser('Administrator');
        $fallbackOwner = $this->makeUser('Validator');
        config(['camminiditalia.default_owner_id' => $fallbackOwner->id]);

        $layer = Layer::factory()->create(['user_id' => null]);

        $ownPoi = $this->makePoi($fallbackOwner->id);
        $otherOrphan = $this->makeUser('Validator');
        $poiOfOtherOrphanOwner = $this->makePoi($otherOrphan->id);

        $response = $this->actingAs($admin)
            ->postJson('/nova-vendor/layer-features/sync/'.$layer->id, [
                'model' => EcPoi::class,
                'auto' => true,
            ]);

        $response->assertOk();
        $assignedIds = $response->json('assigned_ids');
        $this->assertContains($ownPoi->id, $assignedIds);
        $this->assertNotContains($poiOfOtherOrphanOwner->id, $assignedIds);
    }
}

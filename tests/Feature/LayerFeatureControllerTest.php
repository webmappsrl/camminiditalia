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

    public function test_set_track_mode_persists_value_and_preserves_other_configuration_keys(): void
    {
        $layer = Layer::factory()->create([
            'configuration' => ['some_other_key' => 'kept'],
        ]);

        $layer->setTrackMode('manual');

        $fresh = $layer->fresh();
        $this->assertSame('manual', $fresh->configuration['track_mode']);
        $this->assertSame('kept', $fresh->configuration['some_other_key']);
    }

    public function test_set_track_mode_accepts_null_configuration(): void
    {
        $layer = Layer::factory()->create(['configuration' => null]);

        $layer->setTrackMode('manual');

        $fresh = $layer->fresh();
        $this->assertSame('manual', $fresh->configuration['track_mode']);
    }

    public function test_layer_without_configuration_defaults_to_manual_mode_on_camminiditalia(): void
    {
        $layer = Layer::factory()->create(['configuration' => null]);

        $this->assertFalse($layer->isAutoTrackMode());
        $this->assertFalse($layer->isAutoPoiMode());
    }

    public function test_set_track_mode_and_set_poi_mode_do_not_lose_each_other_under_concurrent_writes(): void
    {
        $layer = Layer::factory()->create(['configuration' => null]);

        // Simula due richieste concorrenti: due istanze caricate PRIMA che
        // una delle due scriva, come accadrebbe con due pannelli Nova
        // (Tracce e POI) sulla stessa pagina che POSTano quasi in contemporanea.
        $layerFromRequestA = Layer::find($layer->id);
        $layerFromRequestB = Layer::find($layer->id);

        $layerFromRequestA->setTrackMode('manual');
        $layerFromRequestB->setPoiMode('manual');

        $fresh = $layer->fresh();
        $this->assertSame('manual', $fresh->configuration['track_mode']);
        $this->assertSame('manual', $fresh->configuration['poi_mode']);
    }

    public function test_sync_with_manual_true_persists_manual_mode_for_tracks(): void
    {
        Queue::fake();
        Http::fake();

        $owner = $this->makeUser('Validator');
        $layer = Layer::factory()->create(['user_id' => $owner->id]);
        $track = EcTrack::factory()->create(['user_id' => $owner->id, 'properties' => []]);
        $layer->ecTracks()->sync([$track->id]);

        $this->actingAs($owner)
            ->postJson("/nova-vendor/layer-features/sync/{$layer->id}", [
                'model' => EcTrack::class,
                'manual' => true,
            ])
            ->assertOk();

        $this->assertSame('manual', $layer->fresh()->configuration['track_mode']);
    }

    public function test_sync_with_auto_true_persists_auto_mode_explicitly(): void
    {
        Queue::fake();
        Http::fake();

        $owner = $this->makeUser('Validator');
        $layer = Layer::factory()->create([
            'user_id' => $owner->id,
            'configuration' => ['track_mode' => 'manual'],
        ]);

        // Il pivot ecTracks resta vuoto (nessuna traccia posseduta), ma
        // regeneratePbfsForLayer() viene comunque invocata per la relazione
        // ecTracks: mockata come nel pattern di test_sync_ec_tracks_triggers_pbf_regeneration
        // per evitare la dipendenza dal bounding box reale dell'App.
        $pbfMock = \Mockery::mock(PBFGeneratorService::class);
        $pbfMock->shouldReceive('regeneratePbfsForLayer');
        $this->app->instance(PBFGeneratorService::class, $pbfMock);

        $this->actingAs($owner)
            ->postJson("/nova-vendor/layer-features/sync/{$layer->id}", [
                'model' => EcTrack::class,
                'auto' => true,
                'features' => [],
            ])
            ->assertOk();

        $this->assertSame('auto', $layer->fresh()->configuration['track_mode']);
    }

    public function test_sync_with_manual_true_and_no_features_does_not_touch_pivot(): void
    {
        Queue::fake();
        Http::fake();

        $owner = $this->makeUser('Validator');
        $layer = Layer::factory()->create(['user_id' => $owner->id]);
        $trackA = EcTrack::factory()->create(['user_id' => $owner->id, 'properties' => []]);
        $trackB = EcTrack::factory()->create(['user_id' => $owner->id, 'properties' => []]);
        $layer->ecTracks()->sync([$trackA->id, $trackB->id]);

        $this->actingAs($owner)
            ->postJson("/nova-vendor/layer-features/sync/{$layer->id}", [
                'model' => EcTrack::class,
                'manual' => true,
            ])
            ->assertOk();

        $this->assertEqualsCanonicalizing(
            [$trackA->id, $trackB->id],
            $layer->fresh()->ecTracks()->pluck('ec_tracks.id')->toArray()
        );
    }

    public function test_sync_with_manual_true_and_no_features_does_not_trigger_pbf_regeneration(): void
    {
        Queue::fake();
        Http::fake();

        $owner = $this->makeUser('Validator');
        $layer = Layer::factory()->create(['user_id' => $owner->id]);
        $track = EcTrack::factory()->create(['user_id' => $owner->id, 'properties' => []]);
        $layer->ecTracks()->sync([$track->id]);

        $pbfMock = \Mockery::mock(PBFGeneratorService::class);
        $pbfMock->shouldNotReceive('regeneratePbfsForLayer');
        $this->app->instance(PBFGeneratorService::class, $pbfMock);

        $this->actingAs($owner)
            ->postJson("/nova-vendor/layer-features/sync/{$layer->id}", [
                'model' => EcTrack::class,
                'manual' => true,
            ])
            ->assertOk();
    }

    public function test_sync_rejects_auto_and_manual_together(): void
    {
        $owner = $this->makeUser('Validator');
        $layer = Layer::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($owner)
            ->postJson("/nova-vendor/layer-features/sync/{$layer->id}", [
                'model' => EcTrack::class,
                'auto' => true,
                'manual' => true,
            ])
            ->assertStatus(422);

        $this->assertNull($layer->fresh()->configuration['track_mode'] ?? null);
    }

    public function test_sync_rejects_auto_true_when_layer_owner_is_administrator(): void
    {
        Queue::fake();
        Http::fake();

        $admin = $this->makeUser('Administrator');
        $layer = Layer::factory()->create(['user_id' => $admin->id]);
        $ownedTrack = EcTrack::factory()->create(['user_id' => $admin->id, 'properties' => []]);
        // Traccia estranea, di proprietà dello stesso admin, per dimostrare che
        // il ramo auto (se eseguito) assegnerebbe indiscriminatamente tutto il
        // catalogo dell'admin, non solo le tracce pertinenti a questo layer.
        EcTrack::factory()->create(['user_id' => $admin->id, 'properties' => []]);

        $response = $this->actingAs($admin)
            ->postJson("/nova-vendor/layer-features/sync/{$layer->id}", [
                'model' => EcTrack::class,
                'auto' => true,
            ])
            ->assertStatus(422);

        $this->assertStringContainsString('Amministratore', $response->json('error'));
        $this->assertNull($layer->fresh()->configuration['track_mode'] ?? null);
        $this->assertSame([], $layer->fresh()->ecTracks()->pluck('ec_tracks.id')->toArray());
    }

    public function test_sync_rejects_auto_true_when_resolved_owner_is_administrator_via_default_owner_id(): void
    {
        Queue::fake();
        Http::fake();

        $admin = $this->makeUser('Administrator');
        config(['camminiditalia.default_owner_id' => $admin->id]);
        $layer = Layer::factory()->create(['user_id' => null]);

        $this->actingAs($admin)
            ->postJson("/nova-vendor/layer-features/sync/{$layer->id}", [
                'model' => EcTrack::class,
                'auto' => true,
            ])
            ->assertStatus(422);

        $this->assertNull($layer->fresh()->configuration['track_mode'] ?? null);
    }

    public function test_sync_allows_manual_true_even_when_layer_owner_is_administrator(): void
    {
        Queue::fake();
        Http::fake();

        $admin = $this->makeUser('Administrator');
        $layer = Layer::factory()->create(['user_id' => $admin->id]);
        $track = EcTrack::factory()->create(['user_id' => $admin->id, 'properties' => []]);
        $layer->ecTracks()->sync([$track->id]);

        $this->actingAs($admin)
            ->postJson("/nova-vendor/layer-features/sync/{$layer->id}", [
                'model' => EcTrack::class,
                'manual' => true,
            ])
            ->assertOk();

        $this->assertSame('manual', $layer->fresh()->configuration['track_mode']);
    }
}

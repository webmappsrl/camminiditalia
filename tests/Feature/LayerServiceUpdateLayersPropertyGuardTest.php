<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\Models\LayerService;

class LayerServiceUpdateLayersPropertyGuardTest extends TestCase
{
    use DatabaseTransactions;

    private LayerService $layerService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->layerService = app(LayerService::class);
    }

    public function test_skips_adds_and_cleans_stale_ids_when_no_manual_models_and_no_taxonomy_filter(): void
    {
        $app = App::factory()->createQuietly();
        $layer = Layer::factory()->createQuietly([
            'app_id' => $app->id,
            'properties' => [],
        ]);

        // POI che NON dovrebbe avere il layer (nessuna relazione manuale né taxonomy)
        $poiWithout = EcPoi::factory()->createQuietly([
            'app_id' => $app->id,
            'properties' => ['layers' => []],
        ]);

        // POI con layer ID già corrotto in properties.layers (dato storico da pulire)
        $poiStale = EcPoi::factory()->createQuietly([
            'app_id' => $app->id,
            'properties' => ['layers' => [$layer->id]],
        ]);

        $result = $this->layerService->updateLayersPropertyOnLayeredFeature($layer->fresh(), EcPoi::class);

        // Nessuna aggiunta (guard blocca il path add)
        Assert::assertSame([], $result['added']);

        // Il POI con ID corrotto viene ripulito
        Assert::assertContains($poiStale->id, $result['deleted']);

        $poiStale->refresh();
        Assert::assertNotContains($layer->id, $poiStale->properties['layers'] ?? []);

        // Il POI senza relazione rimane invariato
        $poiWithout->refresh();
        Assert::assertNotContains($layer->id, $poiWithout->properties['layers'] ?? []);
    }

    public function test_skips_adds_for_ec_track_when_no_manual_models_and_no_filter(): void
    {
        $app = App::factory()->createQuietly();
        $layer = Layer::factory()->createQuietly([
            'app_id' => $app->id,
            'properties' => [],
        ]);

        $trackStale = EcTrack::factory()->createQuietly([
            'app_id' => $app->id,
            'properties' => ['layers' => [$layer->id]],
        ]);

        $result = $this->layerService->updateLayersPropertyOnLayeredFeature($layer->fresh(), EcTrack::class);

        Assert::assertSame([], $result['added']);
        Assert::assertContains($trackStale->id, $result['deleted']);

        $trackStale->refresh();
        Assert::assertNotContains($layer->id, $trackStale->properties['layers'] ?? []);
    }

    public function test_updates_when_layer_has_manual_ec_poi(): void
    {
        $app = App::factory()->createQuietly();
        $poi = EcPoi::factory()->createQuietly([
            'app_id' => $app->id,
            'properties' => ['layers' => []],
        ]);
        $layer = Layer::factory()->createQuietly([
            'app_id' => $app->id,
            'properties' => [],
        ]);

        // Il morph map traduce EcPoi::class -> 'App\Models\EcPoi' nella colonna layerable_type
        DB::table('layerables')->insert([
            'layer_id' => $layer->id,
            'layerable_id' => $poi->id,
            'layerable_type' => 'App\Models\EcPoi',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = $this->layerService->updateLayersPropertyOnLayeredFeature($layer->fresh(), EcPoi::class);

        Assert::assertContains($poi->id, $result['added']);
    }

    public function test_updates_when_layer_has_taxonomy_where_in_properties(): void
    {
        $app = App::factory()->createQuietly();
        $layer = Layer::factory()->createQuietly([
            'app_id' => $app->id,
            'properties' => ['taxonomy_where' => ['val1' => 'something']],
        ]);

        $result = $this->layerService->updateLayersPropertyOnLayeredFeature($layer->fresh(), EcPoi::class);

        Assert::assertArrayHasKey('added', $result);
        Assert::assertArrayHasKey('deleted', $result);
    }

    public function test_updates_when_layer_has_taxonomy_activities(): void
    {
        $app = App::factory()->createQuietly();
        $layer = Layer::factory()->createQuietly(['app_id' => $app->id, 'properties' => []]);

        $activityId = DB::table('taxonomy_activities')->insertGetId([
            'name' => json_encode(['it' => 'test-activity-'.uniqid()]),
            'description' => null,
            'excerpt' => null,
            'identifier' => 'test-'.uniqid(),
            'properties' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('taxonomy_activityables')->insert([
            'taxonomy_activity_id' => $activityId,
            'taxonomy_activityable_type' => Layer::class,
            'taxonomy_activityable_id' => $layer->id,
            'duration_forward' => 0,
            'duration_backward' => 0,
        ]);

        $result = $this->layerService->updateLayersPropertyOnLayeredFeature($layer->fresh(), EcPoi::class);

        Assert::assertArrayHasKey('added', $result);
        Assert::assertArrayHasKey('deleted', $result);
    }

}

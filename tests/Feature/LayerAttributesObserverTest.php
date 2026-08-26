<?php

namespace Tests\Feature;

use App\Jobs\RecalculateLayerAttributesJob;
use App\Models\EcPoi;
use App\Models\EcTrack;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class LayerAttributesObserverTest extends TestCase
{
    use DatabaseTransactions, LayerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::fake();
        RolesAndPermissionsService::seedDatabase();
        if (App::count() === 0) {
            App::factory()->create();
        }
    }

    public function test_attaching_a_track_dispatches_the_recalculation(): void
    {
        $layer = $this->createLayer();
        $track = EcTrack::factory()->create(['properties' => []]);

        $layer->ecTracks()->attach($track->id);

        Queue::assertPushed(RecalculateLayerAttributesJob::class, fn ($job) => $job->layerId === $layer->id);
    }

    public function test_attaching_a_track_dispatches_even_when_layer_has_no_owner(): void
    {
        $layer = $this->createLayer();
        $layer->forceFill(['user_id' => null])->saveQuietly();
        $track = EcTrack::factory()->create(['properties' => []]);

        $layer->ecTracks()->attach($track->id);

        Queue::assertPushed(RecalculateLayerAttributesJob::class, fn ($job) => $job->layerId === $layer->id);
    }

    public function test_detaching_a_track_without_pois_dispatches_the_recalculation(): void
    {
        $layer = $this->createLayer();
        $track = EcTrack::factory()->create(['properties' => []]);

        // Inserimento diretto del pivot: se usassimo attach(), dispatcerebbe il job per
        // questo layer e RecalculateLayerAttributesJob (ShouldBeUniqueUntilProcessing) prenderebbe il lock
        // per questo layer_id. Nel Queue::fake(), il job non viene mai eseguito (resta
        // in queue), quindi il lock non viene rilasciato. Quando detach() tenta di
        // dispatchare lo stesso job per lo stesso layer_id, viene scartato come duplicato
        // (unique job id già preso). Inserendo direttamente, il detach è il primo e unico
        // dispatch per questo layer, e il test verifica che il dispatch avviene davvero.
        DB::table('layerables')->insert([
            'layer_id' => $layer->id,
            'layerable_type' => config('wm-package.ec_track_model', 'App\Models\EcTrack'),
            'layerable_id' => $track->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $layer->ecTracks()->detach($track->id);

        Queue::assertPushed(RecalculateLayerAttributesJob::class, fn ($job) => $job->layerId === $layer->id);
    }

    public function test_attaching_a_poi_does_not_dispatch_the_recalculation(): void
    {
        $layer = $this->createLayer();
        $poi = EcPoi::factory()->create(['properties' => []]);
        Queue::fake();

        $layer->ecPois()->attach($poi->id);

        Queue::assertNotPushed(RecalculateLayerAttributesJob::class);
    }
}

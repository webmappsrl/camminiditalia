<?php

namespace Tests\Feature;

use App\Jobs\RecalculateLayerAttributesJob;
use App\Models\EcTrack;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class EcTrackGeometryAttributesObserverTest extends TestCase
{
    use DatabaseTransactions, LayerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::fake();
        // EcTrackObserver::deleting() (wm-package) chiama StorageService->deleteModelFiles()
        // sul disco 'wmfe' (S3/MinIO reale in locale, irraggiungibile in CI dove non esiste
        // il servizio MinIO) — isolare dall'infrastruttura reale, come già fatto per il disco
        // well_known_registry (oc:8251).
        Storage::fake('wmfe');
        RolesAndPermissionsService::seedDatabase();
        if (App::count() === 0) {
            App::factory()->create();
        }
    }

    public function test_changing_track_geometry_recalculates_every_layer_containing_it(): void
    {
        $first = $this->createLayer();
        $second = $this->createLayer();
        $track = EcTrack::factory()->create(['properties' => []]);

        // Associa direttamente al pivot con insert SQL, bypassando l'observer
        // LayerAttributesObserver: altrimenti i job dispatchati durante attach
        // prenderebbero il lock ShouldBeUniqueUntilProcessing prima del Queue::fake() sottostante,
        // causando che il successivo dispatch per il cambio geometria venga scartato.
        DB::table('layerables')->insert([
            'layer_id' => $first->id,
            'layerable_type' => config('wm-package.ec_track_model', 'App\Models\EcTrack'),
            'layerable_id' => $track->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('layerables')->insert([
            'layer_id' => $second->id,
            'layerable_type' => config('wm-package.ec_track_model', 'App\Models\EcTrack'),
            'layerable_id' => $track->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Queue::fake();
        $track->geometry = DB::raw("ST_GeomFromText('MULTILINESTRINGZ((11 43 0, 11.1 43.1 0))', 4326)");
        $track->save();

        Queue::assertPushed(RecalculateLayerAttributesJob::class, fn ($job) => $job->layerId === $first->id);
        Queue::assertPushed(RecalculateLayerAttributesJob::class, fn ($job) => $job->layerId === $second->id);
    }

    /**
     * La lunghezza del cammino è la somma delle distanze delle tappe, e la
     * distanza di una tappa vive in properties (manual_data), non nella
     * geometria: correggerla a mano deve far ricalcolare il cammino.
     */
    public function test_changing_only_the_track_distance_recalculates_the_layer(): void
    {
        $layer = $this->createLayer();
        $track = EcTrack::factory()->create(['properties' => ['manual_data' => ['distance' => 8.0]]]);

        DB::table('layerables')->insert([
            'layer_id' => $layer->id,
            'layerable_type' => config('wm-package.ec_track_model', 'App\Models\EcTrack'),
            'layerable_id' => $track->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Queue::fake();
        $track->properties = ['manual_data' => ['distance' => 21.5]];
        $track->save();

        Queue::assertPushed(
            RecalculateLayerAttributesJob::class,
            fn ($job) => $job->layerId === $layer->id
        );
    }

    /**
     * Contro-prova del test sopra: un campo di properties che NON influenza
     * gli attributi non deve accodare nulla, altrimenti il guard sarebbe
     * equivalente a "dispaccia su ogni salvataggio".
     */
    public function test_changing_an_unrelated_properties_field_does_not_recalculate(): void
    {
        $layer = $this->createLayer();
        $track = EcTrack::factory()->create(['properties' => ['manual_data' => ['distance' => 8.0]]]);

        DB::table('layerables')->insert([
            'layer_id' => $layer->id,
            'layerable_type' => config('wm-package.ec_track_model', 'App\Models\EcTrack'),
            'layerable_id' => $track->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Queue::fake();
        $track->properties = ['manual_data' => ['distance' => 8.0], 'excerpt' => 'testo nuovo'];
        $track->save();

        Queue::assertNotPushed(RecalculateLayerAttributesJob::class);
    }

    public function test_changing_only_a_non_geometry_field_does_not_recalculate(): void
    {
        $layer = $this->createLayer();
        $track = EcTrack::factory()->create(['properties' => []]);

        // Insert diretto sul pivot: usare attach() prenderebbe già il lock
        // ShouldBeUniqueUntilProcessing per questo layer prima di Queue::fake(), rendendo
        // l'asserzione "nessun job accodato" vera anche con un observer che
        // dispaccia sempre (il test non dimostrerebbe nulla).
        DB::table('layerables')->insert([
            'layer_id' => $layer->id,
            'layerable_type' => config('wm-package.ec_track_model', 'App\Models\EcTrack'),
            'layerable_id' => $track->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Queue::fake();
        $track->osmid = 123456;
        $track->save();

        Queue::assertNotPushed(RecalculateLayerAttributesJob::class);
    }

    public function test_deleting_a_track_recalculates_every_layer_that_contained_it(): void
    {
        $first = $this->createLayer();
        $second = $this->createLayer();
        $track = EcTrack::factory()->create(['properties' => []]);

        DB::table('layerables')->insert([
            'layer_id' => $first->id,
            'layerable_type' => config('wm-package.ec_track_model', 'App\Models\EcTrack'),
            'layerable_id' => $track->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('layerables')->insert([
            'layer_id' => $second->id,
            'layerable_type' => config('wm-package.ec_track_model', 'App\Models\EcTrack'),
            'layerable_id' => $track->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Queue::fake();
        $track->delete();

        Queue::assertPushed(RecalculateLayerAttributesJob::class, fn ($job) => $job->layerId === $first->id);
        Queue::assertPushed(RecalculateLayerAttributesJob::class, fn ($job) => $job->layerId === $second->id);
    }
}

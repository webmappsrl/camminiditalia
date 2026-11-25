<?php

namespace Tests\Feature;

use App\Jobs\RecalculateLayerAttributesJob;
use App\Models\EcTrack;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Jobs\UpdateAppConfigJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\TaxonomyWhere;
use Wm\WmPackage\Services\RolesAndPermissionsService;

/**
 * Un nome di regione corretto in Nova deve arrivare da solo agli attributi dei layer e al
 * config.json (oc:8958).
 */
class TaxonomyWhereNameObserverTest extends TestCase
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

    public function test_renaming_a_region_recalculates_the_layers_that_cross_it(): void
    {
        $crossing = $this->layerWithATrack();
        $far = $this->layerWithATrack(far: true);
        $region = $this->regionAround($crossing, 4);
        // L'attach della tappa ha già accodato un ricalcolo (LayerAttributesObserver) che, sotto
        // Queue::fake(), tiene il lock di unicità: senza rilasciarlo il dispatch dell'observer
        // verrebbe scartato come duplicato.
        $this->releaseRecalculateLayerAttributesLock($crossing->id);
        $this->releaseRecalculateLayerAttributesLock($far->id);

        Bus::fake();
        $region->setTranslation('name', 'it', 'Friuli Venezia Giulia');
        $region->save();

        // Job indipendenti, non una chain: in una chain un job scartato dal lock di unicità
        // porterebbe con sé i successivi. Il config lo rigenera ogni job, se gli attributi
        // cambiano; UpdateAppConfigJob è unico e le rigenerazioni si deduplicano.
        Bus::assertDispatched(
            RecalculateLayerAttributesJob::class,
            fn (RecalculateLayerAttributesJob $job) => $job->layerId === $crossing->id
                && $job->regenerateConfig
                && empty($job->chained)
        );
        Bus::assertNotDispatched(
            RecalculateLayerAttributesJob::class,
            fn (RecalculateLayerAttributesJob $job) => $job->layerId === $far->id
        );
    }

    public function test_renaming_a_municipality_does_nothing(): void
    {
        $layer = $this->layerWithATrack();
        $municipality = $this->regionAround($layer, 8);

        Bus::fake();
        $municipality->setTranslation('name', 'it', 'Altro nome');
        $municipality->save();

        Bus::assertNotDispatched(RecalculateLayerAttributesJob::class);
        Bus::assertNotDispatched(UpdateAppConfigJob::class);
    }

    public function test_changing_other_fields_does_nothing(): void
    {
        $layer = $this->layerWithATrack();
        $region = $this->regionAround($layer, 4);

        Bus::fake();
        $region->properties = array_merge($region->properties ?? [], ['note' => 'x']);
        $region->save();

        Bus::assertNotDispatched(RecalculateLayerAttributesJob::class);
        Bus::assertNotDispatched(UpdateAppConfigJob::class);
    }

    /**
     * Layer con una tappa; con `far` la tappa è spostata di 50 gradi di longitudine, fuori da
     * qualsiasi regione costruita intorno all'altro layer.
     */
    private function layerWithATrack(bool $far = false): Layer
    {
        $layer = $this->createLayer();
        $track = EcTrack::factory()->create(['properties' => []]);
        $layer->ecTracks()->attach($track->id);

        if ($far) {
            DB::update(
                'UPDATE ec_tracks SET geometry = ST_Translate(geometry::geometry, 50, 0)::geography WHERE id = ?',
                [$track->id]
            );
        }

        return $layer->fresh();
    }

    /**
     * TaxonomyWhere la cui geometria contiene le tappe del layer dato.
     */
    private function regionAround(Layer $layer, int $adminLevel): TaxonomyWhere
    {
        $taxonomyWhere = TaxonomyWhere::create([
            'name' => ['it' => 'Friuli-Venezia Giulia', 'en' => 'Friuli Venezia Giulia'],
            'properties' => ['osmfeatures_id' => 'R179296', 'admin_level' => $adminLevel],
        ]);

        DB::update(
            'UPDATE taxonomy_wheres SET geometry = (
                SELECT ST_Multi(ST_Buffer(ST_Collect(t.geometry::geometry), 0.01))::geography
                FROM ec_tracks t
                JOIN layerables l ON l.layerable_id = t.id AND l.layer_id = ?
            ) WHERE id = ?',
            [$layer->id, $taxonomyWhere->id]
        );

        return $taxonomyWhere->fresh();
    }
}

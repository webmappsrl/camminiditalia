<?php

namespace Tests\Feature;

use App\Jobs\RecalculateLayerAttributesJob;
use App\Models\EcTrack;
use App\Services\LayerAttributesService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\RolesAndPermissionsService;

/**
 * oc:8180 — auto-riparazione dei filtri calcolati.
 *
 * Il difetto: HasTranslations (su name/title/subtitle/description) marca
 * l'intera colonna `properties` come dirty su QUALSIASI save() di
 * Wm\WmPackage\Models\Layer, indipendentemente da quali campi si stiano
 * toccando davvero. persistCalculatedValues() scrive invece le chiavi
 * calcolate con un UPDATE SQL diretto (fuori da Eloquent). Se un
 * ricalcolo scrive a T0 e poi un save() Eloquent letto prima di T0 (es.
 * l'apertura della scheda Nova) sovrascrive properties per intero, le
 * chiavi calcolate scompaiono senza errori.
 *
 * Mitigazione: LayerObserver::saved() accoda sempre
 * RecalculateLayerAttributesJob, così se un save() ha appena cancellato le
 * chiavi calcolate, il job le riscrive immediatamente dopo.
 */
class LayerObserverAttributesSelfHealingTest extends TestCase
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

    public function test_saving_a_layer_dispatches_the_recalculation_job(): void
    {
        $layer = $this->createLayer();

        Queue::fake();
        $layer->setTranslation('name', 'it', 'Nuovo nome');
        $layer->save();

        Queue::assertPushed(RecalculateLayerAttributesJob::class, fn ($job) => $job->layerId === $layer->id);
    }

    public function test_running_the_job_does_not_dispatch_another_job_itself(): void
    {
        // persistCalculatedValues() scrive via SQL diretto (DB::update), non
        // tramite Eloquent: non dovrebbe far scattare di nuovo
        // LayerObserver::saved(), altrimenti si creerebbe un ciclo silenzioso
        // se in futuro qualcuno cambiasse il writer per passare da Eloquent.
        $layer = $this->createLayer();
        $track = EcTrack::factory()->create(['properties' => ['manual_data' => ['distance' => 5.0]]]);
        $layer->ecTracks()->attach($track->id);

        Queue::fake();

        (new RecalculateLayerAttributesJob($layer->id))->handle(app(LayerAttributesService::class));

        Queue::assertNotPushed(RecalculateLayerAttributesJob::class);
    }

    public function test_self_heals_calculated_filters_erased_by_a_stale_eloquent_save(): void
    {
        $layer = $this->createLayer();
        $track = EcTrack::factory()->create(['properties' => ['manual_data' => ['distance' => 7.5]]]);
        $layer->ecTracks()->attach($track->id);

        // L'attach() sopra dispatcha già un RecalculateLayerAttributesJob per
        // questo layer (LayerAttributesObserver) e, con ShouldBeUniqueUntilProcessing,
        // ne prende il lock fino a quando non viene eseguito. Sotto
        // Queue::fake() non viene mai eseguito: rilasciamo il lock qui per
        // isolare l'asserzione successiva (il dispatch dell'observer sul
        // save() "stale") dal dispatch implicito dell'attach.
        $this->releaseRecalculateLayerAttributesLock($layer->id);

        // Ricalcolo iniziale: popola properties->attributes->distance via SQL diretto.
        (new RecalculateLayerAttributesJob($layer->id))->handle(app(LayerAttributesService::class));

        $propertiesBefore = json_decode(
            DB::selectOne('SELECT properties FROM layers WHERE id = ?', [$layer->id])->properties,
            true
        );
        $this->assertEquals(7.5, $propertiesBefore['attributes']['distance']);

        // Simula un save() Eloquent che ha letto `properties` PRIMA del
        // ricalcolo (es. Nova che apre la scheda a T0): riscrive l'intero
        // blob senza le chiavi calcolate, come farebbe HasTranslations su
        // qualsiasi save(), indipendentemente da cosa si stia modificando.
        $stale = $layer->fresh();
        $stale->properties = array_diff_key($propertiesBefore, ['attributes' => null]);

        Queue::fake();
        $stale->save();

        $propertiesAfterStaleSave = json_decode(
            DB::selectOne('SELECT properties FROM layers WHERE id = ?', [$layer->id])->properties,
            true
        );
        $this->assertArrayNotHasKey('attributes', $propertiesAfterStaleSave);

        Queue::assertPushed(RecalculateLayerAttributesJob::class, fn ($job) => $job->layerId === $layer->id);

        // Esegue il job accodato dal save(): le chiavi calcolate tornano.
        (new RecalculateLayerAttributesJob($layer->id))->handle(app(LayerAttributesService::class));

        $propertiesAfterHeal = json_decode(
            DB::selectOne('SELECT properties FROM layers WHERE id = ?', [$layer->id])->properties,
            true
        );
        $this->assertEquals(7.5, $propertiesAfterHeal['attributes']['distance']);
    }
}

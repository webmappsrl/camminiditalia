<?php

namespace Tests\Feature;

use App\Jobs\RecalculateLayerAttributesJob;
use App\Models\EcTrack;
use App\Services\LayerAttributesService;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Jobs\UpdateAppConfigJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class RecalculateLayerAttributesJobTest extends TestCase
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

    /**
     * Il ricalcolo viene accodato anche da eventi che non toccano gli
     * attributi (auto-riparazione a ogni save del cammino, dispatch multipli
     * durante un import). Se i valori non cambiano il job non deve rigenerare
     * il config: ogni rigenerazione è un rebuild completo con upload, e un
     * ricalcolo massivo sui 121 cammini ne produceva 121 identici.
     */
    public function test_handle_does_not_regenerate_the_config_when_nothing_changed(): void
    {
        // Il fake viene reinstallato all'inizio del test (prima di qualsiasi
        // dispatch) per poterne interrogare i job accodati: Queue::pushed()
        // esiste solo su QueueFake, non sulla facade.
        $queue = Queue::fake();
        $service = app(LayerAttributesService::class);
        $layer = $this->createLayer();
        $track = EcTrack::factory()->create(['properties' => ['manual_data' => ['distance' => 3.0]]]);
        $layer->ecTracks()->attach($track->id);

        (new RecalculateLayerAttributesJob($layer->id))->handle($service);

        $before = $queue->pushed(UpdateAppConfigJob::class)->count();
        $this->releaseUpdateAppConfigLock((int) $layer->app_id);

        (new RecalculateLayerAttributesJob($layer->id))->handle($service);

        $this->assertCount(
            $before,
            $queue->pushed(UpdateAppConfigJob::class),
            'A parità di valori il job non deve accodare una rigenerazione del config.'
        );
    }

    public function test_handle_regenerates_the_config_when_a_value_changes(): void
    {
        $queue = Queue::fake();
        $service = app(LayerAttributesService::class);
        $layer = $this->createLayer();
        $track = EcTrack::factory()->create(['properties' => ['manual_data' => ['distance' => 3.0]]]);
        $layer->ecTracks()->attach($track->id);

        (new RecalculateLayerAttributesJob($layer->id))->handle($service);

        $before = $queue->pushed(UpdateAppConfigJob::class)->count();
        $this->releaseUpdateAppConfigLock((int) $layer->app_id);

        $track->properties = ['manual_data' => ['distance' => 9.0]];
        $track->save();

        (new RecalculateLayerAttributesJob($layer->id))->handle($service);

        $this->assertCount(
            $before + 1,
            $queue->pushed(UpdateAppConfigJob::class),
            'Un valore cambiato deve accodare esattamente una rigenerazione del config.'
        );
    }

    /**
     * UpdateAppConfigJob è deduplicato (ShouldBeUniqueUntilProcessing): il
     * lock si prende al dispatch e si rilascia quando un worker AVVIA il job.
     * Sotto Queue::fake() il job non viene mai eseguito, quindi senza questo
     * rilascio ogni dispatch successivo per la stessa app verrebbe scartato
     * come duplicato — e i due test qui sopra passerebbero entrambi senza
     * dimostrare nulla sul dirty-check.
     */
    private function releaseUpdateAppConfigLock(int $appId): void
    {
        app(UniqueLock::class)->release(new UpdateAppConfigJob($appId));
    }

    public function test_handle_writes_filters_and_dispatches_config_regeneration(): void
    {
        Bus::fake([UpdateAppConfigJob::class]);

        $layer = $this->createLayer();
        $track = EcTrack::factory()->create(['properties' => ['manual_data' => ['distance' => 3.0]]]);
        $layer->ecTracks()->attach($track->id);

        (new RecalculateLayerAttributesJob($layer->id))->handle(app(LayerAttributesService::class));

        $attributes = json_decode(DB::selectOne('SELECT properties FROM layers WHERE id = ?', [$layer->id])->properties, true)['attributes'];
        $this->assertEquals(3.0, $attributes['distance']);

        Bus::assertDispatched(UpdateAppConfigJob::class);
    }

    public function test_handle_is_a_noop_when_layer_no_longer_exists(): void
    {
        Bus::fake([UpdateAppConfigJob::class]);

        (new RecalculateLayerAttributesJob(999999))->handle(app(LayerAttributesService::class));

        Bus::assertNotDispatched(UpdateAppConfigJob::class);
    }

    public function test_unique_id_is_scoped_to_the_layer(): void
    {
        $this->assertSame('recalculate-layer-attributes-42', (new RecalculateLayerAttributesJob(42))->uniqueId());
    }

    public function test_unique_lock_expires_after_two_minutes(): void
    {
        // Rete di sicurezza: senza scadenza, un job morto in modo non
        // gestito (worker ucciso durante l'esecuzione) lascerebbe il lock
        // ShouldBeUniqueUntilProcessing preso per sempre. Con il rilascio
        // già garantito all'avvio dell'esecuzione, la finestra di rischio
        // è solo il tempo di esecuzione del job: 120s è ampiamente
        // sufficiente come rete di sicurezza.
        $this->assertSame(120, (new RecalculateLayerAttributesJob(42))->uniqueFor);
    }
}

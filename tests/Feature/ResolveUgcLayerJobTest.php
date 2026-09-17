<?php

namespace Tests\Feature;

use App\Jobs\ResolveUgcLayerJob;
use App\Jobs\SendUgcReportMailJob;
use App\Nova\Traits\HasLayerOverride;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Wm\WmPackage\Models\App as WmApp;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Services\RolesAndPermissionsService;
use Wm\WmPackage\Services\UgcService;

class ResolveUgcLayerJobTest extends TestCase
{
    use DatabaseTransactions;

    private object $overrideHelper;

    protected function setUp(): void
    {
        parent::setUp();
        RolesAndPermissionsService::seedDatabase();
        if (WmApp::count() === 0) {
            WmApp::factory()->create();
        }

        // Queue::fake()/Mail::fake(): UgcTrack::factory()->create() qui sotto
        // triggera sincronicamente (QUEUE_CONNECTION=sync in phpunit.xml)
        // il dispatch automatico di ResolveUgcLayerJob da UgcObserver, che su
        // un DB di test vuoto finisce nel ramo "nessun layer trovato" e
        // costruisce una NewUgcReportMail su una UgcTrack (geometria linea):
        // ST_X()/ST_Y() assumono un Point e falliscono, lasciando la
        // transazione Postgres del test "aborted" (bug preesistente e non
        // correlato, vedi tests/Feature/UgcNotificationTest.php — tutti gli
        // altri test costruiscono NewUgcReportMail solo su UgcPoi).
        Queue::fake();
        Mail::fake();

        $this->overrideHelper = new class
        {
            use HasLayerOverride;

            public $properties = [];

            public static $model;
        };
    }

    public function test_job_aborts_without_overwriting_when_layer_was_manually_corrected_meanwhile(): void
    {
        $correctLayer = Layer::factory()->create();
        $autoResolvedLayer = Layer::factory()->create();

        $track = UgcTrack::factory()->create([
            'properties' => ['form' => ['id' => 'report']],
        ]);

        $overrideHelper = $this->overrideHelper;

        // Mock di UgcService::resolveLayerByProximity(): come side effect
        // della chiamata, simula la correzione manuale avvenuta DA NOVA
        // esattamente nella finestra di race-condition che la guardia deve
        // coprire — DOPO che il job ha già eseguito $this->ugc->refresh() a
        // inizio handle() (quindi con layer_id ancora assente sia in memoria
        // sia su DB: la idempotency-guard preesistente del job, che si basa
        // su quel refresh(), non scatta), ma PRIMA del salvataggio finale del
        // job stesso. Scriviamo su un'istanza indipendente caricata fresca
        // dal DB (non su $this->ugc, che nel job resta quella pre-correzione
        // in memoria), per riprodurre fedelmente una scrittura concorrente
        // proveniente da un'altra richiesta. Il mock evita anche di dover
        // impostare dati geografici reali (EcTrack/PostGIS) solo per far
        // restare resolveLayerByProximity() non-null.
        $this->mock(UgcService::class, function ($mock) use ($autoResolvedLayer, $track, $correctLayer, $overrideHelper) {
            $mock->shouldReceive('resolveLayerByProximity')
                ->once()
                ->andReturnUsing(function () use ($autoResolvedLayer, $track, $correctLayer, $overrideHelper) {
                    $trackForManualEdit = UgcTrack::find($track->id);
                    $overrideHelper::applyManualLayerOverride($trackForManualEdit, $correctLayer->id, null);
                    $trackForManualEdit->save();

                    return $autoResolvedLayer;
                });
        });

        (new ResolveUgcLayerJob($track))->handle(app(UgcService::class));

        $track->refresh();
        $this->assertSame($correctLayer->id, $track->properties['layer_id']);
        $this->assertFalse($track->properties['layer_id_auto_resolved']);
        Queue::assertNotPushed(SendUgcReportMailJob::class);
    }

    public function test_job_resolves_and_notifies_normally_when_no_manual_correction_happened(): void
    {
        $resolvedLayer = Layer::factory()->create();

        $track = UgcTrack::factory()->create([
            'properties' => ['form' => ['id' => 'report']],
        ]);

        // No side effect this time: resolveLayerByProximity() just returns a
        // layer, no manual correction is injected mid-flight.
        $this->mock(UgcService::class, function ($mock) use ($resolvedLayer) {
            $mock->shouldReceive('resolveLayerByProximity')
                ->once()
                ->andReturn($resolvedLayer);
        });

        (new ResolveUgcLayerJob($track))->handle(app(UgcService::class));

        $track->refresh();
        $this->assertSame($resolvedLayer->id, $track->properties['layer_id']);
        $this->assertTrue($track->properties['layer_id_auto_resolved']);
        Queue::assertPushed(SendUgcReportMailJob::class);
    }

    public function test_job_stays_silent_when_layer_was_manually_corrected_before_the_job_even_started(): void
    {
        $manualLayer = Layer::factory()->create();

        $track = UgcTrack::factory()->create([
            'properties' => ['form' => ['id' => 'report']],
        ]);

        // Correzione manuale avvenuta PRIMA che il job parta (non durante la
        // sua esecuzione): il ramo di idempotenza preesistente (pensato per i
        // retry del job) trova layer_id non vuoto e, prima del fix, lo
        // scambiava per "già risolto automaticamente", inviando comunque la
        // mail — in violazione del requisito "correzione manuale silenziosa"
        // (oc:8575, review formale).
        $overrideHelper = $this->overrideHelper;
        $overrideHelper::applyManualLayerOverride($track, $manualLayer->id, null);
        $track->save();

        (new ResolveUgcLayerJob($track))->handle(app(UgcService::class));

        $track->refresh();
        $this->assertSame($manualLayer->id, $track->properties['layer_id']);
        $this->assertFalse($track->properties['layer_id_auto_resolved']);
        Queue::assertNotPushed(SendUgcReportMailJob::class);
    }
}

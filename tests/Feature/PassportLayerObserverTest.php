<?php

namespace Tests\Feature;

use App\Jobs\RecomputePassportStagesForLayerJob;
use App\Models\PassportStage;
use App\Support\PassportLayer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\User;

/**
 * Cosa muove il cambio dei flag del passaporto sul cammino (oc:8165, oc:8809).
 */
class PassportLayerObserverTest extends TestCase
{
    use DatabaseTransactions, LayerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        if (App::count() === 0) {
            App::factory()->create();
        }
    }

    public function test_gps_on_dispatches_recompute(): void
    {
        Queue::fake();
        $layer = $this->createLayer();

        $layer->properties = [PassportLayer::GPS_KEY => true];
        $layer->save();

        Queue::assertPushed(RecomputePassportStagesForLayerJob::class, fn ($job) => $job->layerId === $layer->id);
    }

    public function test_gps_off_dispatches_nothing(): void
    {
        $layer = $this->enableGpsPassport($this->createLayer());
        Queue::fake();

        $layer->properties = [PassportLayer::PAPER_KEY => true, PassportLayer::GPS_KEY => false];
        $layer->save();

        // Spegnere il GPS non cancella le tappe e non accoda niente del passaporto (oc:8809,
        // scrum 23/10). Gli altri observer del layer accodano i loro job: non sono affar nostro.
        Queue::assertNotPushed(RecomputePassportStagesForLayerJob::class);
    }

    public function test_gps_off_with_real_save_keeps_gps_rows(): void
    {
        $owner = User::factory()->create();
        $layer = $this->enableGpsPassport($this->createLayer($owner->id));
        $track = EcTrack::withoutEvents(fn () => EcTrack::factory()->create(['user_id' => $owner->id, 'properties' => []]));
        // La tappa sta nel cammino, come la cercherebbe una revoca: insert diretto, senza gli
        // observer di layerables e senza la chiamata al DEM che farebbe l'attach.
        DB::table('layerables')->insert([
            'layer_id' => $layer->id,
            'layerable_id' => $track->id,
            'layerable_type' => (new (config('wm-package.ec_track_model')))->getMorphClass(),
        ]);
        $stage = PassportStage::create([
            'user_id' => $owner->id,
            'ec_track_id' => $track->id,
            'layer_id' => $layer->id,
            'source' => PassportStage::SOURCE_GPS,
            'validated_at' => now(),
            'progress' => 100,
        ]);

        // Niente Queue::fake (qui la coda è quella vera) e save() reale: passa da tutti gli
        // observer, e con QUEUE_CONNECTION sync un eventuale job di cancellazione girerebbe davvero.
        $layer->properties = [PassportLayer::PAPER_KEY => true, PassportLayer::GPS_KEY => false];
        $layer->save();

        $this->assertSame(PassportStage::SOURCE_GPS, PassportStage::find($stage->id)?->source);
    }

    public function test_paper_on_alone_dispatches_nothing(): void
    {
        Queue::fake();
        $layer = $this->createLayer();

        $layer->properties = [PassportLayer::PAPER_KEY => true];
        $layer->save();

        Queue::assertNotPushed(RecomputePassportStagesForLayerJob::class);
    }

    public function test_paper_off_dispatches_nothing(): void
    {
        Queue::fake();
        $layer = $this->enablePaperPassport($this->createLayer());

        $layer->properties = [PassportLayer::PAPER_KEY => false];
        $layer->save();

        Queue::assertNotPushed(RecomputePassportStagesForLayerJob::class);
    }
}

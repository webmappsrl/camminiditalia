<?php

namespace Tests\Feature;

use App\Jobs\RecomputePassportStagesForLayerJob;
use App\Jobs\RevokeGpsPassportStagesForLayerJob;
use App\Support\PassportLayer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App;

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

        Queue::fake();
    }

    public function test_gps_on_dispatches_recompute(): void
    {
        $layer = $this->createLayer();

        $layer->properties = [PassportLayer::GPS_KEY => true];
        $layer->save();

        Queue::assertPushed(RecomputePassportStagesForLayerJob::class, fn ($job) => $job->layerId === $layer->id);
        Queue::assertNotPushed(RevokeGpsPassportStagesForLayerJob::class);
    }

    public function test_gps_off_dispatches_revoke(): void
    {
        $layer = $this->enableGpsPassport($this->createLayer());

        $layer->properties = [PassportLayer::PAPER_KEY => true, PassportLayer::GPS_KEY => false];
        $layer->save();

        Queue::assertPushed(RevokeGpsPassportStagesForLayerJob::class, fn ($job) => $job->layerId === $layer->id);
        Queue::assertNotPushed(RecomputePassportStagesForLayerJob::class);
    }

    public function test_paper_on_alone_dispatches_nothing(): void
    {
        $layer = $this->createLayer();

        $layer->properties = [PassportLayer::PAPER_KEY => true];
        $layer->save();

        Queue::assertNotPushed(RecomputePassportStagesForLayerJob::class);
        Queue::assertNotPushed(RevokeGpsPassportStagesForLayerJob::class);
    }

    public function test_paper_off_dispatches_nothing(): void
    {
        $layer = $this->enablePaperPassport($this->createLayer());

        $layer->properties = [PassportLayer::PAPER_KEY => false];
        $layer->save();

        Queue::assertNotPushed(RecomputePassportStagesForLayerJob::class);
        Queue::assertNotPushed(RevokeGpsPassportStagesForLayerJob::class);
    }
}

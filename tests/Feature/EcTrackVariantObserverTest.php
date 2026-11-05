<?php

namespace Tests\Feature;

use App\Jobs\RecalculateLayerAttributesJob;
use App\Models\EcTrackVariant;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Services\RolesAndPermissionsService;

/**
 * Il ricalcolo degli attributi del cammino quando cambia una variante (oc:8918).
 */
class EcTrackVariantObserverTest extends TestCase
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

    private function track(): EcTrack
    {
        return EcTrack::withoutEvents(
            fn () => EcTrack::factory()->create(['properties' => [], 'osmid' => null])
        );
    }

    /** Inserimento diretto del pivot, così non scatta LayerAttributesObserver. */
    private function placeInLayer(EcTrack $track, $layer): void
    {
        DB::table('layerables')->insert([
            'layer_id' => $layer->id,
            'layerable_id' => $track->id,
            'layerable_type' => config('wm-package.ec_track_model', 'App\Models\EcTrack'),
        ]);
    }

    private function pushedLayerIds(): array
    {
        return Queue::pushed(RecalculateLayerAttributesJob::class)
            ->map(fn ($job) => $job->layerId)
            ->sort()
            ->values()
            ->all();
    }

    public function test_creating_a_variant_queues_recalculation_of_its_layers(): void
    {
        $layer = $this->createLayer();
        $main = $this->track();
        $variant = $this->track();
        $this->placeInLayer($main, $layer);
        $this->placeInLayer($variant, $layer);
        Queue::fake();

        EcTrackVariant::create(['ec_track_id' => $variant->id, 'main_ec_track_id' => $main->id]);

        $this->assertSame([$layer->id], $this->pushedLayerIds());
    }

    public function test_changing_the_main_queues_old_and_new_layers(): void
    {
        $layer1 = $this->createLayer();
        $layer2 = $this->createLayer();
        $mainA = $this->track();
        $mainB = $this->track();
        $variant = $this->track();
        $this->placeInLayer($mainA, $layer1);
        $this->placeInLayer($mainB, $layer2);
        EcTrackVariant::create(['ec_track_id' => $variant->id, 'main_ec_track_id' => $mainA->id]);
        $this->releaseRecalculateLayerAttributesLock($layer1->id);
        $this->releaseRecalculateLayerAttributesLock($layer2->id);
        Queue::fake();

        EcTrackVariant::updateOrCreate(
            ['ec_track_id' => $variant->id],
            ['main_ec_track_id' => $mainB->id]
        );

        $expected = [$layer1->id, $layer2->id];
        sort($expected);
        $this->assertSame($expected, $this->pushedLayerIds());
    }

    public function test_deleting_a_variant_queues_recalculation(): void
    {
        $layer = $this->createLayer();
        $main = $this->track();
        $variant = $this->track();
        $this->placeInLayer($variant, $layer);
        $row = EcTrackVariant::create(['ec_track_id' => $variant->id, 'main_ec_track_id' => $main->id]);
        $this->releaseRecalculateLayerAttributesLock($layer->id);
        Queue::fake();

        $row->delete();

        $this->assertSame([$layer->id], $this->pushedLayerIds());
    }

    public function test_variant_without_layers_queues_nothing(): void
    {
        $main = $this->track();
        $variant = $this->track();

        EcTrackVariant::create(['ec_track_id' => $variant->id, 'main_ec_track_id' => $main->id]);

        Queue::assertNothingPushed();
    }
}

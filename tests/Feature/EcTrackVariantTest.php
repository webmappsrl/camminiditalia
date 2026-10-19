<?php

namespace Tests\Feature;

use App\Models\EcTrackVariant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use RuntimeException;
use Tests\TestCase;
use Wm\WmPackage\Models\EcTrack;

/**
 * Varianti di una tappa (oc:8165). Una tappa può essere dichiarata variante di un'altra: le due
 * si equivalgono e nel totale del cammino contano per una sola.
 */
class EcTrackVariantTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Senza observer: la catena di EcTrackService chiama il servizio DEM esterno.
     * `osmid` esplicito perché la factory lo valorizza a caso nel 70% dei casi.
     */
    private function track(): EcTrack
    {
        return EcTrack::withoutEvents(
            fn () => EcTrack::factory()->create(['properties' => [], 'osmid' => null])
        );
    }

    public function test_a_stage_can_be_a_variant_of_another()
    {
        $main = $this->track();
        $variant = $this->track();

        EcTrackVariant::create([
            'ec_track_id' => $variant->id,
            'main_ec_track_id' => $main->id,
        ]);

        $this->assertSame(
            [$variant->id => $main->id],
            EcTrackVariant::mainIdsFor([$variant->id, $main->id])
        );
    }

    public function test_a_stage_cannot_be_a_variant_of_two_stages()
    {
        $first = $this->track();
        $second = $this->track();
        $variant = $this->track();

        EcTrackVariant::create(['ec_track_id' => $variant->id, 'main_ec_track_id' => $first->id]);

        $this->expectException(QueryException::class);

        EcTrackVariant::create(['ec_track_id' => $variant->id, 'main_ec_track_id' => $second->id]);
    }

    public function test_a_stage_cannot_be_a_variant_of_itself()
    {
        $stage = $this->track();

        $this->expectException(RuntimeException::class);

        EcTrackVariant::create(['ec_track_id' => $stage->id, 'main_ec_track_id' => $stage->id]);
    }

    public function test_a_variant_cannot_be_the_main_stage_of_another()
    {
        // Un solo livello: se B è variante di A, nessuno può essere variante di B.
        $a = $this->track();
        $b = $this->track();
        $c = $this->track();

        EcTrackVariant::create(['ec_track_id' => $b->id, 'main_ec_track_id' => $a->id]);

        $this->expectException(RuntimeException::class);

        EcTrackVariant::create(['ec_track_id' => $c->id, 'main_ec_track_id' => $b->id]);
    }

    public function test_a_main_stage_cannot_become_a_variant()
    {
        // L'altro verso della stessa regola: se B è variante di A, A non può diventare
        // variante di C, perché B si ritroverebbe in una catena.
        $a = $this->track();
        $b = $this->track();
        $c = $this->track();

        EcTrackVariant::create(['ec_track_id' => $b->id, 'main_ec_track_id' => $a->id]);

        $this->expectException(RuntimeException::class);

        EcTrackVariant::create(['ec_track_id' => $a->id, 'main_ec_track_id' => $c->id]);
    }

    public function test_main_ids_for_skips_stages_that_are_not_variants()
    {
        $main = $this->track();
        $plain = $this->track();

        $this->assertSame([], EcTrackVariant::mainIdsFor([$main->id, $plain->id]));
    }
}

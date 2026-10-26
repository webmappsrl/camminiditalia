<?php

namespace Tests\Feature;

use App\Support\PassportLayer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App;

/**
 * Vincolo «GPS acceso ⇒ passaporto cartaceo acceso» (oc:8809), applicato al salvataggio.
 */
class PassportLayerFlagsTest extends TestCase
{
    use DatabaseTransactions, LayerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        if (App::count() === 0) {
            App::factory()->create();
        }
    }

    public function test_turning_gps_on_turns_paper_on(): void
    {
        $layer = $this->createLayer();

        $layer->properties = [PassportLayer::GPS_KEY => true];
        $layer->save();

        $fresh = $layer->fresh();
        $this->assertTrue(PassportLayer::isGpsEnabled($fresh));
        $this->assertTrue(PassportLayer::isPaperEnabled($fresh));
    }

    public function test_gps_on_and_paper_off_in_same_save_keeps_paper_on(): void
    {
        $layer = $this->createLayer();

        $layer->properties = [PassportLayer::GPS_KEY => true, PassportLayer::PAPER_KEY => false];
        $layer->save();

        $this->assertTrue(PassportLayer::isPaperEnabled($layer->fresh()));
    }

    public function test_paper_can_be_turned_off_when_gps_is_off(): void
    {
        $layer = $this->createLayer();
        $this->enablePaperPassport($layer);

        $layer->properties = [PassportLayer::PAPER_KEY => false, PassportLayer::GPS_KEY => false];
        $layer->save();

        $this->assertFalse(PassportLayer::isPaperEnabled($layer->fresh()));
    }

    public function test_normalize_ignores_non_boolean_gps(): void
    {
        $properties = [PassportLayer::GPS_KEY => 'true'];

        $this->assertSame($properties, PassportLayer::normalize($properties));
    }
}

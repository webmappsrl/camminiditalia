<?php

namespace Tests\Feature;

use App\Models\CertificationRequest;
use App\Models\EcTrack;
use App\Support\PassportLayer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack as WmEcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\RolesAndPermissionsService;

/**
 * Il passaporto vale solo sui cammini con `properties->passport_enabled === true`
 * (oc:8763): gli endpoint per-cammino rispondono 404 sugli altri.
 */
class PassportLayerGateTest extends TestCase
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

    private function stage(Layer $layer): WmEcTrack
    {
        $track = EcTrack::factory()->create(['user_id' => $layer->user_id, 'osmid' => null]);

        DB::table('layerables')->insert([
            'layer_id' => $layer->id,
            'layerable_type' => (new (config('wm-package.ec_track_model')))->getMorphClass(),
            'layerable_id' => $track->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return WmEcTrack::find($track->id);
    }

    public function test_certification_store_on_disabled_layer_returns_404(): void
    {
        $layer = $this->createLayer();

        $this->actingAs(User::factory()->create(), 'api')
            ->postJson("/api/layer/{$layer->id}/certification", ['disclaimer_accepted' => true])
            ->assertStatus(404);
    }

    public function test_certification_show_on_disabled_layer_returns_404(): void
    {
        $layer = $this->createLayer();

        $this->actingAs(User::factory()->create(), 'api')
            ->getJson("/api/layer/{$layer->id}/certification")
            ->assertStatus(404);
    }

    public function test_progress_on_disabled_layer_returns_404(): void
    {
        $layer = $this->createLayer();

        $this->actingAs(User::factory()->create(), 'api')
            ->getJson("/api/layer/{$layer->id}/progress")
            ->assertStatus(404);
    }

    public function test_stage_share_image_on_disabled_layer_returns_404(): void
    {
        $layer = $this->createLayer();
        $stage = $this->stage($layer);

        $this->actingAs(User::factory()->create(), 'api')
            ->postJson("/api/layer/{$layer->id}/stage/{$stage->id}/share-image")
            ->assertStatus(404);
    }

    public function test_stage_share_image_on_enabled_layer_passes_the_gate(): void
    {
        $layer = $this->enablePassport($this->createLayer());
        $stage = $this->stage($layer);

        // tappa non validata dall'utente: supera il gate e arriva al controller (403, non 404)
        $this->actingAs(User::factory()->create(), 'api')
            ->postJson("/api/layer/{$layer->id}/stage/{$stage->id}/share-image")
            ->assertStatus(403);
    }

    public function test_route_share_image_on_disabled_layer_returns_404(): void
    {
        $layer = $this->createLayer();

        $this->actingAs(User::factory()->create(), 'api')
            ->postJson("/api/layer/{$layer->id}/share-image")
            ->assertStatus(404);
    }

    public function test_progress_on_enabled_layer_returns_200(): void
    {
        $layer = $this->enablePassport($this->createLayer());

        $this->actingAs(User::factory()->create(), 'api')
            ->getJson("/api/layer/{$layer->id}/progress")
            ->assertOk();
    }

    public function test_string_true_is_treated_as_disabled(): void
    {
        $layer = $this->createLayer();
        $layer->forceFill(['properties' => ['passport_enabled' => 'true']])->saveQuietly();

        $this->actingAs(User::factory()->create(), 'api')
            ->getJson("/api/layer/{$layer->id}/progress")
            ->assertStatus(404);
    }

    public function test_pending_request_on_disabled_layer_is_still_decidable_in_nova(): void
    {
        $owner = $this->createUserWithRole('Validator');
        $layer = $this->createLayer($owner->id);
        $request = CertificationRequest::create([
            'user_id' => User::factory()->create()->id,
            'layer_id' => $layer->id,
            'status' => CertificationRequest::STATUS_PENDING,
            'disclaimer_accepted_at' => now(),
        ]);

        $this->actingAs($owner)->postJson('/nova-vendor/certification-decision/confirm', [
            'certification_request_id' => $request->id,
            'outcome' => 'reject',
            'ec_track_ids' => [],
            'decision_note' => 'Foto illeggibile',
        ])->assertOk();

        $this->assertSame(CertificationRequest::STATUS_REJECTED, $request->fresh()->status);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: bool}>
     */
    public static function passportValues(): array
    {
        return [
            'boolean true' => [['passport_enabled' => true], true],
            'stringa "true"' => [['passport_enabled' => 'true'], false],
            'numero 1' => [['passport_enabled' => 1], false],
            'boolean false' => [['passport_enabled' => false], false],
            'mancante' => [[], false],
        ];
    }

    #[DataProvider('passportValues')]
    public function test_php_and_sql_rules_agree(array $properties, bool $expected): void
    {
        $layer = $this->createLayer();
        $layer->forceFill(['properties' => $properties])->saveQuietly();

        $this->assertSame($expected, PassportLayer::isEnabled($layer->fresh()));
        $this->assertSame(
            $expected,
            Layer::whereRaw(PassportLayer::enabledSql())->whereKey($layer->id)->exists()
        );
    }
}

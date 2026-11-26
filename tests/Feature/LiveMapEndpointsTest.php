<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsLiveMapData;
use Tests\Feature\Concerns\CreatesUsersWithRole;
use Tests\TestCase;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\RolesAndPermissionsService;

/**
 * Chi può leggere le posizioni live e i percorsi di tutti i cammini (oc:9040).
 *
 * Gli endpoint nuovi nascono protetti: login obbligatorio; posizioni globali e percorsi di tutti
 * i cammini solo per l'Administrator; posizioni di un layer per l'Administrator o per il suo
 * proprietario.
 */
class LiveMapEndpointsTest extends TestCase
{
    use BuildsLiveMapData;
    use CreatesUsersWithRole;
    use RefreshDatabase;

    private const GLOBAL_POSITIONS = '/nova-vendor/layer-analytics/live-positions';

    private const ALL_ROUTES = '/nova-vendor/layer-analytics/all-routes';

    protected function setUp(): void
    {
        parent::setUp();
        RolesAndPermissionsService::seedDatabase();
    }

    public function test_global_live_positions_route_is_not_captured_by_layer_binding(): void
    {
        $admin = $this->userWithRole('Administrator');

        $this->actingAs($admin)->getJson(self::GLOBAL_POSITIONS)
            ->assertOk()
            ->assertJsonPath('type', 'FeatureCollection');

        $this->actingAs($admin)->getJson(self::ALL_ROUTES)
            ->assertOk()
            ->assertJsonPath('type', 'FeatureCollection');
    }

    public function test_guest_without_login_gets_403(): void
    {
        $layer = Layer::factory()->create();

        $this->getJson(self::GLOBAL_POSITIONS)->assertForbidden();
        $this->getJson(self::ALL_ROUTES)->assertForbidden();
        $this->getJson($this->layerPositions($layer))->assertForbidden();
        $this->getJson($this->layerRoutes($layer))->assertForbidden();
    }

    /**
     * Layer senza proprietario e nessun login: `user_id` null contro un utente null non deve
     * valere come «proprietario». Vale anche per le statistiche del layer, che avevano questo buco.
     */
    public function test_guest_cannot_read_an_ownerless_layer(): void
    {
        $layer = Layer::factory()->create(['user_id' => null]);

        $this->getJson("/nova-vendor/layer-analytics/{$layer->id}")->assertForbidden();
        $this->getJson($this->layerPositions($layer))->assertForbidden();
        $this->getJson($this->layerRoutes($layer))->assertForbidden();
    }

    public function test_validator_cannot_read_global_endpoints(): void
    {
        $validator = $this->userWithRole('Validator');

        $this->actingAs($validator)->getJson(self::GLOBAL_POSITIONS)->assertForbidden();
        $this->actingAs($validator)->getJson(self::ALL_ROUTES)->assertForbidden();
    }

    public function test_validator_reads_only_own_layer_positions(): void
    {
        $validator = $this->userWithRole('Validator');
        $own = Layer::factory()->create(['user_id' => $validator->id]);
        $other = Layer::factory()->create(['user_id' => $this->userWithRole('Validator')->id]);

        $this->actingAs($validator)->getJson($this->layerPositions($own))
            ->assertOk()
            ->assertJsonPath('type', 'FeatureCollection');
        $this->actingAs($validator)->getJson($this->layerPositions($other))->assertForbidden();

        $this->actingAs($validator)->getJson($this->layerRoutes($own))
            ->assertOk()
            ->assertJsonPath('type', 'FeatureCollection');
        $this->actingAs($validator)->getJson($this->layerRoutes($other))->assertForbidden();
    }

    public function test_administrator_reads_any_layer_positions(): void
    {
        $layer = Layer::factory()->create(['user_id' => $this->userWithRole('Validator')->id]);

        $this->actingAs($this->userWithRole('Administrator'))
            ->getJson($this->layerPositions($layer))
            ->assertOk();
    }

    public function test_layer_positions_are_points_with_the_live_marker_style(): void
    {
        app()->setLocale('it');
        $admin = $this->userWithRole('Administrator');
        $layer = Layer::factory()->create(['user_id' => $admin->id]);
        $this->trackOnParallel($layer, 11.0, 11.1);
        $this->fakePostHogPositions([['near', 43.0001, 11.05, null]]);

        $features = $this->actingAs($admin)->getJson($this->layerPositions($layer))->assertOk()->json('features');

        $this->assertCount(1, $features);
        $this->assertSame('Point', $features[0]['geometry']['type']);
        $this->assertSame([11.05, 43.0001], $features[0]['geometry']['coordinates']);
        $this->assertSame('Posizione utente (ultimi 30 minuti)', $features[0]['properties']['tooltip']);
    }

    private function layerPositions(Layer $layer): string
    {
        return "/nova-vendor/layer-analytics/{$layer->id}/live-positions";
    }

    private function layerRoutes(Layer $layer): string
    {
        return "/nova-vendor/layer-analytics/{$layer->id}/routes";
    }
}

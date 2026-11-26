<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Nova\Nova;
use Tests\Feature\Concerns\BuildsLiveMapData;
use Tests\Feature\Concerns\CreatesUsersWithRole;
use Tests\TestCase;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\RolesAndPermissionsService;

/**
 * Interfaccia di Nova con i colori e i caratteri del design system di Cammini d'Italia (oc:9040,
 * terza parte): arancio `primario` #F07821 come colore principale, tracce in arancio quando il
 * layer non ha un colore suo, foglio di stile del brand registrato.
 */
class NovaBrandTest extends TestCase
{
    use BuildsLiveMapData;
    use CreatesUsersWithRole;
    use RefreshDatabase;

    public function test_primary_color_is_the_brand_orange(): void
    {
        $this->assertSame('240, 120, 33', config('nova.brand.colors.500'));
        $this->assertSame('187, 70, 19', config('nova.brand.colors.700'));
    }

    public function test_tracks_without_layer_color_use_the_configured_default(): void
    {
        $layer = Layer::factory()->create(['properties' => []]);
        $this->trackOnParallel($layer, 11.0, 11.1);

        $this->assertSame(['#F07821'], $this->trackColors($layer->getFeatureCollectionMap(withLivePositions: false)));
    }

    public function test_layer_color_wins_over_the_default(): void
    {
        $layer = Layer::factory()->create(['properties' => ['color' => '#C0382B']]);
        $this->trackOnParallel($layer, 11.0, 11.1);

        $colors = $this->trackColors($layer->getFeatureCollectionMap(withLivePositions: false));

        $this->assertCount(1, $colors);
        $this->assertNotSame('#F07821', $colors[0]);
    }

    public function test_all_routes_use_the_configured_default_and_follow_a_change(): void
    {
        RolesAndPermissionsService::seedDatabase();
        $admin = $this->userWithRole('Administrator');
        $this->trackOnParallel(Layer::factory()->create(), 11.0, 11.1);
        Cache::flush();

        $this->assertSame(['#F07821'], $this->trackColors(
            $this->actingAs($admin)->getJson('/nova-vendor/layer-analytics/all-routes')->assertOk()->json()
        ));

        config(['wm-package.map_default_track_color' => '#123456']);

        $this->assertSame(['#123456'], $this->trackColors(
            $this->actingAs($admin)->getJson('/nova-vendor/layer-analytics/all-routes')->assertOk()->json()
        ));
    }

    public function test_brand_stylesheet_is_registered(): void
    {
        $this->get('/nova/login');

        $this->assertContains('camminiditalia-brand', collect(Nova::allStyles())->map->name()->all());
    }

    /**
     * @param  array{features: list<array<string, mixed>>}  $geojson
     * @return list<string>
     */
    private function trackColors(array $geojson): array
    {
        return collect($geojson['features'])
            ->where('geometry.type', 'MultiLineString')
            ->pluck('properties.strokeColor')
            ->unique()
            ->values()
            ->all();
    }
}

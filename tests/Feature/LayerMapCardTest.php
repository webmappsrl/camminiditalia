<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsLiveMapData;
use Tests\Feature\Concerns\CreatesUsersWithRole;
use Tests\TestCase;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Nova\Fields\FeatureCollectionMap\src\LayerMapCard;
use Wm\WmPackage\Services\PostHog\AnalyticsService;
use Wm\WmPackage\Services\RolesAndPermissionsService;

/**
 * La mappa del layer in una card Nova (oc:9040): stessi dati del Field FeatureCollectionMap
 * nella detail view del Layer, ma utilizzabile dove un Field non si può mettere, come una
 * Dashboard.
 *
 * La card sta in wm-package, il test qui: i test del package che usano il suo TestCase non si
 * caricano dal consumer (stessa scelta di oc:8140).
 */
class LayerMapCardTest extends TestCase
{
    use BuildsLiveMapData;
    use CreatesUsersWithRole;
    use RefreshDatabase;

    public function test_card_points_to_the_layer_geojson(): void
    {
        $layer = Layer::factory()->create();

        $payload = (new LayerMapCard($layer))->jsonSerialize();

        $this->assertSame('layer-map-card', $payload['component']);
        $this->assertSame('full', $payload['width']);
        // Senza posizioni: le posizioni arrivano da live_positions_url e si aggiornano da sole.
        $this->assertSame('/nova-vendor/layer-analytics/'.$layer->id.'/routes', $payload['geojson_url']);
        $this->assertSame('layers', $payload['resource_name']);
        $this->assertSame($layer->id, $payload['resource_id']);
        $this->assertSame($layer->getStringName(), $payload['title']);
    }

    public function test_global_card_points_to_all_routes_and_global_positions(): void
    {
        app()->setLocale('it');
        $this->fakePostHogPositions([]);

        $payload = LayerMapCard::global()->jsonSerialize();

        $this->assertSame('layer-map-card', $payload['component']);
        $this->assertSame('Tutti i cammini', $payload['title']);
        $this->assertSame('/nova-vendor/layer-analytics/all-routes', $payload['geojson_url']);
        $this->assertSame('/nova-vendor/layer-analytics/live-positions', $payload['live_positions_url']);
        $this->assertNull($payload['resource_name']);
        $this->assertNull($payload['resource_id']);
    }

    public function test_layer_geojson_keeps_live_positions_by_default(): void
    {
        app()->setLocale('it');
        [$admin, $layer] = $this->layerWithTrackAndOnePersonNearby();

        $features = $this->actingAs($admin)
            ->getJson('/nova-vendor/feature-collection-map/layers/'.$layer->id)
            ->assertOk()
            ->json('features');

        $points = collect($features)->where('geometry.type', 'Point')->values();
        $this->assertCount(1, $points);
        $this->assertSame('Posizione utente (ultimi 30 minuti)', $points[0]['properties']['tooltip']);
    }

    public function test_layer_routes_endpoint_has_no_live_positions(): void
    {
        [$admin, $layer] = $this->layerWithTrackAndOnePersonNearby();

        $features = $this->actingAs($admin)
            ->getJson('/nova-vendor/layer-analytics/'.$layer->id.'/routes')
            ->assertOk()
            ->json('features');

        $types = collect($features)->pluck('geometry.type');
        $this->assertNotContains('Point', $types);
        $this->assertContains('MultiLineString', $types);
    }

    /**
     * @return array{0: User, 1: Layer}
     */
    private function layerWithTrackAndOnePersonNearby(): array
    {
        RolesAndPermissionsService::seedDatabase();
        $admin = $this->userWithRole('Administrator');

        $layer = Layer::factory()->create(['user_id' => $admin->id]);
        $this->trackOnParallel($layer, 11.0, 11.1);
        $this->fakePostHogPositions([['near', 43.0001, 11.05, null]]);

        return [$admin, $layer];
    }

    public function test_card_polls_and_shows_the_legend_only_when_posthog_is_configured(): void
    {
        $layer = Layer::factory()->create();

        $this->mock(AnalyticsService::class)->shouldReceive('isConfigured')->andReturn(true);
        $payload = (new LayerMapCard($layer))->withLiveLegend()->jsonSerialize();
        $this->assertTrue($payload['show_live_legend']);
        $this->assertSame('/nova-vendor/layer-analytics/'.$layer->id.'/live-positions', $payload['live_positions_url']);
        $this->assertSame(AnalyticsService::LIVE_POSITIONS_CACHE_SECONDS, $payload['live_positions_refresh_seconds']);

        // Senza PostHog niente polling: l'endpoint risponderebbe sempre vuoto.
        $this->mock(AnalyticsService::class)->shouldReceive('isConfigured')->andReturn(false);
        // Anche con withLiveLegend(): senza posizioni la legenda non avrebbe niente da spiegare.
        $payload = (new LayerMapCard($layer))->withLiveLegend()->jsonSerialize();
        $this->assertFalse($payload['show_live_legend']);
        $this->assertNull($payload['live_positions_url']);
        $this->assertNull(LayerMapCard::global()->jsonSerialize()['live_positions_url']);
    }

    /**
     * Sotto la mappa un box con il pallino delle persone, quante sono e l'ora dell'ultimo
     * aggiornamento (oc:9040, quarta parte): spento di default, perché è una richiesta di
     * Cammini d'Italia e la card è nel package; lo accende chi costruisce la card. Il numero lo
     * conta il componente sulle posizioni che riceve, i testi arrivano tradotti dalla card.
     */
    public function test_live_legend_is_off_by_default(): void
    {
        $this->assertFalse(LayerMapCard::global()->jsonSerialize()['show_live_legend']);
        $this->assertFalse((new LayerMapCard(Layer::factory()->create()))->jsonSerialize()['show_live_legend']);
    }

    public function test_live_legend_texts_use_the_camminiditalia_wording(): void
    {
        app()->setLocale('it');
        $this->fakePostHogPositions([]);

        $payload = LayerMapCard::global()->withLiveLegend()->jsonSerialize();

        $this->assertTrue($payload['show_live_legend']);
        $this->assertSame([
            'one' => 'persona in cammino',
            'other' => 'persone in cammino',
            'window' => 'negli ultimi 30 minuti',
            'updated' => 'aggiornato alle :time',
        ], $payload['live_count_texts']);
        $this->assertTrue((new LayerMapCard(Layer::factory()->create()))->withLiveLegend()->jsonSerialize()['show_live_legend']);
    }

    /**
     * Il package è usato da altri progetti: le sue traduzioni non parlano di cammini, la parola la
     * mette camminiditalia con le sue (resources/lang/*.json).
     */
    public function test_package_texts_do_not_mention_cammini(): void
    {
        foreach (['it', 'en', 'fr', 'es', 'de'] as $locale) {
            $texts = json_decode((string) file_get_contents(base_path("wm-package/resources/lang/{$locale}.json")), true);

            foreach (['user on the map', 'users on the map', 'in the last 30 minutes', 'Updated at :time'] as $key) {
                $this->assertArrayHasKey($key, $texts, "{$locale}: manca «{$key}»");
                $this->assertStringNotContainsStringIgnoringCase('cammin', $texts[$key], "{$locale}: «{$key}»");
            }
        }
    }
}

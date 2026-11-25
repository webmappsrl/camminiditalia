<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Nova\Fields\FeatureCollectionMap\src\LayerMapCard;
use Wm\WmPackage\Services\PostHog\AnalyticsService;

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
    use RefreshDatabase;

    public function test_card_points_to_the_layer_geojson(): void
    {
        $layer = Layer::factory()->create();

        $payload = (new LayerMapCard($layer))->jsonSerialize();

        $this->assertSame('layer-map-card', $payload['component']);
        $this->assertSame('full', $payload['width']);
        $this->assertSame('/nova-vendor/feature-collection-map/layers/'.$layer->id, $payload['geojson_url']);
        $this->assertSame('layers', $payload['resource_name']);
        $this->assertSame($layer->id, $payload['resource_id']);
        $this->assertSame($layer->getStringName(), $payload['title']);
    }

    public function test_card_announces_live_positions_only_when_posthog_is_configured(): void
    {
        $layer = Layer::factory()->create();

        $this->mock(AnalyticsService::class)->shouldReceive('isConfigured')->andReturn(true);
        $this->assertTrue((new LayerMapCard($layer))->jsonSerialize()['show_live_user_positions']);

        $this->mock(AnalyticsService::class)->shouldReceive('isConfigured')->andReturn(false);
        $this->assertFalse((new LayerMapCard($layer))->jsonSerialize()['show_live_user_positions']);
    }

    public function test_legend_label_is_translated(): void
    {
        $layer = Layer::factory()->create();

        app()->setLocale('it');

        $this->assertSame(
            'Posizione utente (ultimi 30 minuti)',
            (new LayerMapCard($layer))->jsonSerialize()['legend_label'],
        );
    }
}

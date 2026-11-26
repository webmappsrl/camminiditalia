<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\BuildsLiveMapData;
use Tests\TestCase;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\Models\AllLayersRoutesGeojson;
use Wm\WmPackage\Services\PostHog\AnalyticsService;

/**
 * I dati della mappa di tutti i cammini (oc:9040): le persone a meno di 50 m da una traccia di un
 * layer, e i percorsi semplificati delle tracce dei layer.
 *
 * Il codice sta in wm-package, il test qui: i test del package non si caricano dal consumer.
 */
class AllLayersLiveMapDataTest extends TestCase
{
    use BuildsLiveMapData;
    use RefreshDatabase;

    public function test_positions_near_any_layer_track_are_kept(): void
    {
        $this->trackOnParallel(Layer::factory()->create(), 11.0, 11.1);
        $this->trackOnParallel(Layer::factory()->create(), 12.0, 12.1);

        $this->fakePostHogPositions([
            ['near-a', 43.0001, 11.05, 7],
            ['near-b', 42.9999, 12.05, null],
            ['far', 43.05, 11.05, null],
        ]);

        $positions = app(AnalyticsService::class)->getRecentUserPositionsOnAllLayers();

        $this->assertEqualsCanonicalizing(
            [
                ['lat' => 43.0001, 'lng' => 11.05, 'user_id' => 7],
                ['lat' => 42.9999, 'lng' => 12.05, 'user_id' => null],
            ],
            $positions,
        );
    }

    public function test_positions_near_a_track_without_layer_are_dropped(): void
    {
        $this->trackOnParallel(Layer::factory()->create(), 11.0, 11.1);
        $this->trackOnParallel(null, 12.0, 12.1);

        $this->fakePostHogPositions([['near-orphan', 43.0001, 12.05, null]]);

        $this->assertSame([], app(AnalyticsService::class)->getRecentUserPositionsOnAllLayers());
    }

    public function test_positions_are_empty_when_posthog_is_not_configured(): void
    {
        $this->trackOnParallel(Layer::factory()->create(), 11.0, 11.1);
        $this->withoutPostHog();

        $this->assertSame([], app(AnalyticsService::class)->getRecentUserPositionsOnAllLayers());
        Http::assertNothingSent();
    }

    public function test_positions_are_empty_without_layers(): void
    {
        $this->fakePostHogPositions([['someone', 43.0001, 11.05, null]]);

        $this->assertSame([], app(AnalyticsService::class)->getRecentUserPositionsOnAllLayers());
    }

    public function test_all_routes_contains_only_layer_tracks_in_2d(): void
    {
        $inLayer = $this->trackOnParallel(Layer::factory()->create(), 11.0, 11.123456789, 'Prima tappa');
        $this->trackOnParallel(null, 12.0, 12.1, 'Senza cammino');

        $geojson = app(AllLayersRoutesGeojson::class)->get();

        $this->assertSame('FeatureCollection', $geojson['type']);
        $this->assertCount(1, $geojson['features']);

        $feature = $geojson['features'][0];
        $this->assertSame('Prima tappa', $feature['properties']['tooltip']);
        $this->assertSame($inLayer->id, $feature['properties']['id']);

        $coordinates = $this->flattenPositions($feature['geometry']['coordinates']);
        $this->assertNotEmpty($coordinates);
        foreach ($coordinates as $position) {
            $this->assertCount(2, $position, 'Il percorso deve essere senza quota.');
            foreach ($position as $value) {
                $decimals = strlen(substr(strrchr((string) $value, '.') ?: '.', 1));
                $this->assertLessThanOrEqual(5, $decimals);
            }
        }
    }

    public function test_all_routes_is_empty_without_layers(): void
    {
        $this->trackOnParallel(null, 12.0, 12.1);

        $this->assertSame([], app(AllLayersRoutesGeojson::class)->get()['features']);
    }

    /**
     * @return list<list<float>>
     */
    private function flattenPositions(array $coordinates): array
    {
        if (is_numeric($coordinates[0] ?? null)) {
            return [$coordinates];
        }

        return array_merge(...array_map(fn ($item) => $this->flattenPositions($item), $coordinates));
    }
}

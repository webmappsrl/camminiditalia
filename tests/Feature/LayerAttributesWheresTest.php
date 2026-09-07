<?php

namespace Tests\Feature;

use App\Models\EcTrack;
use App\Services\LayerAttributesService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class LayerAttributesWheresTest extends TestCase
{
    use DatabaseTransactions, LayerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        RolesAndPermissionsService::seedDatabase();
        if (App::count() === 0) {
            App::factory()->create();
        }
    }

    public function test_aggregated_geojson_feature_is_null_without_tracks(): void
    {
        Http::fake();
        $layer = $this->createLayer();

        $this->assertNull(app(LayerAttributesService::class)->aggregatedGeojsonFeature($layer));
    }

    public function test_aggregated_geojson_feature_has_empty_properties_and_a_geometry(): void
    {
        Http::fake();
        $layer = $this->createLayer();
        $track = EcTrack::factory()->create(['properties' => []]);
        $layer->ecTracks()->attach($track->id);

        $feature = app(LayerAttributesService::class)->aggregatedGeojsonFeature($layer->fresh());

        $this->assertSame('Feature', $feature['type']);
        $this->assertSame([], $feature['properties']);
        $this->assertArrayHasKey('type', $feature['geometry']);
        $this->assertArrayHasKey('coordinates', $feature['geometry']);
    }

    public function test_wheres_posts_the_aggregated_geometry_to_osmfeatures(): void
    {
        Http::fake([
            '*' => Http::response([
                'features' => [[
                    'properties' => [
                        'osmfeatures_id' => 'R42',
                        'osm_tags' => ['admin_level' => '4', 'name' => 'Toscana'],
                    ],
                ]],
            ], 200),
        ]);

        $layer = $this->createLayer();
        $track = EcTrack::factory()->create(['properties' => []]);
        $layer->ecTracks()->attach($track->id);

        $wheres = app(LayerAttributesService::class)->wheres($layer->fresh());

        $this->assertNotNull($wheres);
        $this->assertNotEmpty($wheres);
        Http::assertSent(function ($request) {
            return isset($request['geojson']['geometry'])
                && $request['geojson']['type'] === 'Feature';
        });
    }

    public function test_wheres_is_null_without_tracks(): void
    {
        Http::fake();
        $layer = $this->createLayer();

        $this->assertNull(app(LayerAttributesService::class)->wheres($layer));
        Http::assertNothingSent();
    }

    public function test_wheres_normalizes_realistic_osmfeatures_response_into_an_ordered_list(): void
    {
        Http::fake(function ($request) {
            $adminLevel = $request['admin_level'];

            if ($adminLevel === 4) {
                return Http::response([
                    'features' => [[
                        'properties' => [
                            'osmfeatures_id' => 'R41977',
                            'osm_tags' => [
                                'name' => 'Toscana',
                                'name:it' => 'Toscana',
                                'name:en' => 'Tuscany',
                                'admin_level' => '4',
                                'boundary' => 'administrative',
                            ],
                        ],
                    ]],
                ], 200);
            }

            return Http::response([
                'features' => [[
                    'properties' => [
                        'osmfeatures_id' => 'R42527',
                        'osm_tags' => [
                            'name' => 'Pisa',
                            'name:it' => 'Pisa',
                            'admin_level' => '8',
                            'boundary' => 'administrative',
                        ],
                    ],
                ]],
            ], 200);
        });

        $layer = $this->createLayer();
        $track = EcTrack::factory()->create(['properties' => []]);
        $layer->ecTracks()->attach($track->id);

        $wheres = app(LayerAttributesService::class)->wheres($layer->fresh());

        // Solo la regione: il comune (admin_level 8) viene scartato.
        $this->assertIsList($wheres);
        $this->assertCount(1, $wheres);

        $region = $wheres[0];

        // Forma { value, name } uniforme agli altri attributi: value derivato
        // dal nome inglese, nessun identifier OSM e nessun admin_level.
        $this->assertArrayNotHasKey('identifier', $region, 'Il codice OSM non va esposto: la chiave del filtro è value.');
        $this->assertArrayNotHasKey('admin_level', $region, 'admin_level vale sempre 4 dopo il filtro: non va esposto.');
        $this->assertSame('tuscany', $region['value']);
        $this->assertSame(['it' => 'Toscana', 'en' => 'Tuscany'], $region['name']);
        $this->assertArrayNotHasKey('_admin_level', $region['name']);
    }

    public function test_persisted_taxonomy_where_is_stored_as_a_json_array(): void
    {
        Http::fake([
            '*' => Http::response([
                'features' => [[
                    'properties' => [
                        'osmfeatures_id' => 'R42',
                        'osm_tags' => ['admin_level' => '4', 'name' => 'Toscana'],
                    ],
                ]],
            ], 200),
        ]);

        $layer = $this->createLayer();
        $track = EcTrack::factory()->create(['properties' => []]);
        $layer->ecTracks()->attach($track->id);
        $layer = $layer->fresh();

        $service = app(LayerAttributesService::class);
        $wheres = $service->wheres($layer);
        $service->persistCalculatedValues($layer, ['taxonomy_where' => $wheres]);

        $type = DB::selectOne(
            "SELECT jsonb_typeof(properties->'attributes'->'taxonomy_where') AS type FROM layers WHERE id = ?",
            [$layer->id]
        )->type;

        $this->assertSame('array', $type);

        $first = DB::selectOne(
            "SELECT properties->'attributes'->'taxonomy_where'->0 AS item FROM layers WHERE id = ?",
            [$layer->id]
        )->item;

        $decoded = json_decode($first, true);
        $this->assertArrayNotHasKey('_admin_level', $decoded['name']);
    }
}

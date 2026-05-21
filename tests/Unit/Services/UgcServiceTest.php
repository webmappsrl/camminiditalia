<?php

namespace Tests\Unit\Services;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\UgcPoi;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\RolesAndPermissionsService;
use Wm\WmPackage\Services\UgcService;

class UgcServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        RolesAndPermissionsService::seedDatabase();

        if (App::count() === 0) {
            App::factory()->create();
        }

        if (User::count() === 0) {
            User::factory()->create();
        }
    }

    public function test_it_returns_layer_from_properties_layer_id_when_present(): void
    {
        $layer = Layer::factory()->create();
        $ugcPoi = UgcPoi::factory()->create([
            'properties' => ['layer_id' => $layer->id],
        ]);

        $result = UgcService::make()->resolveLayer($ugcPoi);

        $this->assertNotNull($result);
        $this->assertEquals($layer->id, $result->id);
    }

    public function test_it_falls_back_to_spatial_query_when_layer_id_missing(): void
    {
        // Layer che interseca: usa un poligono centrato su Roma
        $layer = Layer::factory()->create();
        DB::table('layers')->where('id', $layer->id)->update([
            'geometry' => DB::raw("ST_GeomFromText('POLYGON((12.4 41.8, 12.6 41.8, 12.6 42.0, 12.4 42.0, 12.4 41.8))', 4326)"),
        ]);

        // UgcPoi dentro il poligono
        $ugcPoi = UgcPoi::factory()->create(['properties' => []]);
        DB::table('ugc_pois')->where('id', $ugcPoi->id)->update([
            'geometry' => DB::raw("ST_GeomFromText('POINT(12.5 41.9)', 4326)"),
        ]);
        $ugcPoi->refresh();

        $result = UgcService::make()->resolveLayer($ugcPoi);

        $this->assertNotNull($result);
        $this->assertEquals($layer->id, $result->id);
    }

    public function test_it_returns_closest_layer_by_centroid_distance_when_multiple_intersect(): void
    {
        // Layer 1: poligono più piccolo centrato sul punto (più vicino)
        $layerClose = Layer::factory()->create();
        DB::table('layers')->where('id', $layerClose->id)->update([
            'geometry' => DB::raw("ST_GeomFromText('POLYGON((12.45 41.85, 12.55 41.85, 12.55 41.95, 12.45 41.95, 12.45 41.85))', 4326)"),
        ]);

        // Layer 2: poligono più grande che include anche il punto (centroide più lontano)
        $layerFar = Layer::factory()->create();
        DB::table('layers')->where('id', $layerFar->id)->update([
            'geometry' => DB::raw("ST_GeomFromText('POLYGON((11.0 41.0, 14.0 41.0, 14.0 43.0, 11.0 43.0, 11.0 41.0))', 4326)"),
        ]);

        $ugcPoi = UgcPoi::factory()->create(['properties' => []]);
        DB::table('ugc_pois')->where('id', $ugcPoi->id)->update([
            'geometry' => DB::raw("ST_GeomFromText('POINT(12.5 41.9)', 4326)"),
        ]);
        $ugcPoi->refresh();

        $result = UgcService::make()->resolveLayer($ugcPoi);

        $this->assertNotNull($result);
        $this->assertEquals($layerClose->id, $result->id);
    }

    public function test_it_returns_null_when_no_layer_intersects_and_no_layer_id(): void
    {
        // Layer lontano dal punto
        $layer = Layer::factory()->create();
        DB::table('layers')->where('id', $layer->id)->update([
            'geometry' => DB::raw("ST_GeomFromText('POLYGON((0.0 0.0, 1.0 0.0, 1.0 1.0, 0.0 1.0, 0.0 0.0))', 4326)"),
        ]);

        $ugcPoi = UgcPoi::factory()->create(['properties' => []]);
        DB::table('ugc_pois')->where('id', $ugcPoi->id)->update([
            'geometry' => DB::raw("ST_GeomFromText('POINT(12.5 41.9)', 4326)"),
        ]);
        $ugcPoi->refresh();

        $result = UgcService::make()->resolveLayer($ugcPoi);

        $this->assertNull($result);
    }

    public function test_it_returns_null_when_properties_layer_id_does_not_exist_in_db(): void
    {
        $ugcPoi = UgcPoi::factory()->create([
            'properties' => ['layer_id' => 999999],
        ]);

        $result = UgcService::make()->resolveLayer($ugcPoi);

        $this->assertNull($result);
    }
}

<?php

namespace Tests\Unit\Services;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
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

        Queue::fake();

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
        $layer = Layer::factory()->create();
        $track = EcTrack::factory()->create();

        // Traccia vicina al punto UGC (Roma)
        DB::table('ec_tracks')->where('id', $track->id)->update([
            'geometry' => DB::raw("ST_Force3D(ST_GeomFromText('MULTILINESTRING((12.49 41.89, 12.51 41.91))', 4326))"),
        ]);

        // Associa traccia al layer
        DB::table('layerables')->insert([
            'layer_id' => $layer->id,
            'layerable_type' => 'App\Models\EcTrack',
            'layerable_id' => $track->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('layers')->where('id', $layer->id)->update([
            'geometry' => DB::raw("ST_GeomFromText('POLYGON((12.4 41.8, 12.6 41.8, 12.6 42.0, 12.4 42.0, 12.4 41.8))', 4326)"),
        ]);

        $ugcPoi = UgcPoi::factory()->create(['properties' => []]);
        DB::table('ugc_pois')->where('id', $ugcPoi->id)->update([
            'geometry' => DB::raw("ST_Force3D(ST_GeomFromText('POINT(12.5 41.9)', 4326))"),
        ]);
        $ugcPoi->refresh();

        $result = UgcService::make()->resolveLayer($ugcPoi);

        $this->assertNotNull($result);
        $this->assertEquals($layer->id, $result->id);
    }

    public function test_it_returns_closest_layer_by_centroid_distance_when_multiple_intersect(): void
    {
        // Layer vicino: centroide a ~0.05° dal punto
        $layerClose = Layer::factory()->create();
        $trackClose = EcTrack::factory()->create();
        DB::table('ec_tracks')->where('id', $trackClose->id)->update([
            'geometry' => DB::raw("ST_Force3D(ST_GeomFromText('MULTILINESTRING((12.49 41.89, 12.51 41.91))', 4326))"),
        ]);
        DB::table('layerables')->insert([
            'layer_id' => $layerClose->id,
            'layerable_type' => 'App\Models\EcTrack',
            'layerable_id' => $trackClose->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('layers')->where('id', $layerClose->id)->update([
            'geometry' => DB::raw("ST_GeomFromText('POLYGON((12.45 41.85, 12.55 41.85, 12.55 41.95, 12.45 41.95, 12.45 41.85))', 4326)"),
        ]);

        // Layer lontano: centroide a ~1.5° dal punto
        $layerFar = Layer::factory()->create();
        $trackFar = EcTrack::factory()->create();
        DB::table('ec_tracks')->where('id', $trackFar->id)->update([
            'geometry' => DB::raw("ST_Force3D(ST_GeomFromText('MULTILINESTRING((12.49 41.89, 12.51 41.91))', 4326))"),
        ]);
        DB::table('layerables')->insert([
            'layer_id' => $layerFar->id,
            'layerable_type' => 'App\Models\EcTrack',
            'layerable_id' => $trackFar->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('layers')->where('id', $layerFar->id)->update([
            'geometry' => DB::raw("ST_GeomFromText('POLYGON((11.0 41.0, 14.0 41.0, 14.0 43.0, 11.0 43.0, 11.0 41.0))', 4326)"),
        ]);

        $ugcPoi = UgcPoi::factory()->create(['properties' => []]);
        DB::table('ugc_pois')->where('id', $ugcPoi->id)->update([
            'geometry' => DB::raw("ST_Force3D(ST_GeomFromText('POINT(12.5 41.9)', 4326))"),
        ]);
        $ugcPoi->refresh();

        $result = UgcService::make()->resolveLayer($ugcPoi);

        $this->assertNotNull($result);
        $this->assertEquals($layerClose->id, $result->id);
    }

    public function test_it_returns_null_when_no_layer_intersects_and_no_layer_id(): void
    {
        // Nessuna EcTrack vicina al punto (nel Pacifico)
        $ugcPoi = UgcPoi::factory()->create(['properties' => []]);
        DB::table('ugc_pois')->where('id', $ugcPoi->id)->update([
            'geometry' => DB::raw("ST_Force3D(ST_GeomFromText('POINT(-150.0 0.0)', 4326))"),
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

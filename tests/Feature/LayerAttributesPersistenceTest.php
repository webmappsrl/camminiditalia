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

class LayerAttributesPersistenceTest extends TestCase
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

    public function test_calculated_values_omit_keys_that_cannot_be_computed(): void
    {
        $layer = $this->createLayer();

        $values = app(LayerAttributesService::class)->computeCalculatedValues($layer);

        $this->assertArrayNotHasKey('distance', $values);
        $this->assertArrayNotHasKey('duration', $values);
        $this->assertArrayNotHasKey('type', $values);
        $this->assertArrayNotHasKey('taxonomy_where', $values);
    }

    public function test_persist_preserves_other_properties_keys(): void
    {
        $layer = $this->createLayer();
        DB::update(
            'UPDATE layers SET properties = ?::jsonb WHERE id = ?',
            [json_encode(['title' => ['it' => 'Cammino di prova'], 'name' => 'prova']), $layer->id]
        );

        app(LayerAttributesService::class)->persistCalculatedValues($layer, ['distance' => 12.5]);

        $properties = json_decode(DB::selectOne('SELECT properties FROM layers WHERE id = ?', [$layer->id])->properties, true);

        $this->assertSame(['it' => 'Cammino di prova'], $properties['title']);
        $this->assertSame('prova', $properties['name']);
        $this->assertSame(12.5, $properties['attributes']['distance']);
    }

    public function test_persist_preserves_manual_filter_keys(): void
    {
        $layer = $this->createLayer();
        DB::update(
            'UPDATE layers SET properties = ?::jsonb WHERE id = ?',
            [json_encode(['attributes' => ['walking_network' => 'nwn', 'season' => ['spring']]]), $layer->id]
        );

        app(LayerAttributesService::class)->persistCalculatedValues($layer, ['duration' => 4]);

        $attributes = json_decode(DB::selectOne('SELECT properties FROM layers WHERE id = ?', [$layer->id])->properties, true)['attributes'];

        $this->assertSame('nwn', $attributes['walking_network']);
        $this->assertSame(['spring'], $attributes['season']);
        $this->assertSame(4, $attributes['duration']);
    }

    public function test_persist_removes_stale_calculated_keys_when_no_longer_computable(): void
    {
        $layer = $this->createLayer();
        DB::update(
            'UPDATE layers SET properties = ?::jsonb WHERE id = ?',
            [json_encode(['attributes' => ['distance' => 99.0, 'walking_network' => 'iwn']]), $layer->id]
        );

        // Nessun valore calcolabile: la chiave stale va rimossa, walking_network resta
        app(LayerAttributesService::class)->persistCalculatedValues($layer, []);

        $attributes = json_decode(DB::selectOne('SELECT properties FROM layers WHERE id = ?', [$layer->id])->properties, true)['attributes'];

        $this->assertArrayNotHasKey('distance', $attributes);
        $this->assertSame('iwn', $attributes['walking_network']);
    }

    public function test_persist_keeps_nested_list_values_as_lists_not_objects(): void
    {
        $layer = $this->createLayer();

        app(LayerAttributesService::class)->persistCalculatedValues($layer, [
            'taxonomy_where' => [
                ['value' => 'toscana'],
                ['value' => 'lazio'],
            ],
        ]);

        // La forma va verificata lato database: json_decode normalizza un oggetto
        // con chiavi numeriche e una lista nello stesso array PHP, quindi
        // un'asserzione su array_keys() non distinguerebbe i due casi.
        $type = DB::selectOne(
            "SELECT jsonb_typeof(properties->'attributes'->'taxonomy_where') AS t FROM layers WHERE id = ?",
            [$layer->id]
        )->t;

        $this->assertSame('array', $type);

        $attributes = json_decode(DB::selectOne('SELECT properties FROM layers WHERE id = ?', [$layer->id])->properties, true)['attributes'];
        $this->assertSame('toscana', $attributes['taxonomy_where'][0]['value']);
        $this->assertSame('lazio', $attributes['taxonomy_where'][1]['value']);
    }

    public function test_attributes_key_is_removed_when_nothing_is_left(): void
    {
        $layer = $this->createLayer();
        $service = app(LayerAttributesService::class);

        $service->persistCalculatedValues($layer, ['distance' => 12.5]);
        $service->persistManualValue($layer, 'walking_network', ['value' => 'nwn', 'name' => ['en' => 'National']]);

        // Ripulendo tutto, la chiave attributes non deve restare a {}: un
        // oggetto vuoto verrebbe riserializzato nel config come [] (tipo
        // diverso da quello di tutti gli altri cammini).
        $service->persistManualValue($layer, 'walking_network', null);
        $service->persistCalculatedValues($layer, []);

        // Verifica lato database: json_decode normalizza {} e [] nello stesso
        // array PHP, quindi l'asserzione va fatta sul jsonb.
        $exists = DB::selectOne(
            "SELECT jsonb_exists(properties, 'attributes') AS e FROM layers WHERE id = ?",
            [$layer->id]
        )->e;

        $this->assertFalse($exists, 'La chiave attributes deve essere rimossa, non lasciata a {}.');

        // Le altre chiavi di properties non devono essere toccate.
        $properties = json_decode(DB::selectOne('SELECT properties FROM layers WHERE id = ?', [$layer->id])->properties, true);
        $this->assertIsArray($properties);
    }

    public function test_calculated_values_include_distance_and_stage_count_with_tracks(): void
    {
        $layer = $this->createLayer();
        $track = EcTrack::factory()->create(['properties' => ['manual_data' => ['distance' => 8.0]]]);
        $layer->ecTracks()->attach($track->id);

        $values = app(LayerAttributesService::class)->computeCalculatedValues($layer->fresh());

        $this->assertSame(8.0, $values['distance']);
        $this->assertSame(1, $values['stage_count']);
    }
}

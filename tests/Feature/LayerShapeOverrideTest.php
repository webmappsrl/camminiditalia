<?php

namespace Tests\Feature;

use App\Enums\RouteShape;
use App\Jobs\RecalculateLayerAttributesJob;
use App\Services\LayerAttributesService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;

class LayerShapeOverrideTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        // Il LayerObserver accoda un ricalcolo a ogni save: con un worker
        // reale in ascolto sovrascriverebbe i valori preparati dal test.
        Queue::fake();
        Http::fake();
    }

    /**
     * Service con topologia fissata: evita di costruire tappe con geometrie
     * reali per ogni caso (stesso approccio di LayerConfigJsonShapeDiscontinuousTest).
     */
    private function serviceWithTopology(?RouteShape $calculated): LayerAttributesService
    {
        return new class($calculated) extends LayerAttributesService
        {
            public function __construct(private ?RouteShape $fixed) {}

            public function determineType(array $endpoints): ?RouteShape
            {
                return $this->fixed;
            }
        };
    }

    private function layerWithAttributes(array $attributes): Layer
    {
        $app = App::factory()->createQuietly();

        return Layer::factory()->create([
            'app_id' => $app->id,
            // [] vuoto verrebbe serializzato come array JSON, non come
            // oggetto: nei dati reali attributes è sempre un oggetto o assente.
            'properties' => $attributes === [] ? [] : ['attributes' => $attributes],
        ]);
    }

    private function storedAttributes(Layer $layer): array
    {
        $row = DB::selectOne(
            "SELECT COALESCE(properties->'attributes', '{}'::jsonb) AS a FROM layers WHERE id = ?",
            [$layer->id]
        );

        return json_decode((string) $row->a, true) ?? [];
    }

    public function test_manual_shape_wins_over_calculated_and_keeps_discontinuity_flag(): void
    {
        $service = $this->serviceWithTopology(RouteShape::DISCONTINUOUS);
        $layer = $this->layerWithAttributes([LayerAttributesService::SHAPE_MANUAL_KEY => 'roundtrip']);

        $values = $service->computeCalculatedValues($layer);

        $this->assertSame('roundtrip', $values['shape']['value']);
        $this->assertSame(RouteShape::ROUNDTRIP->labelIn('it'), $values['shape']['name']['it']);
        $this->assertTrue($values['shape_discontinuous']);
    }

    public function test_without_manual_shape_discontinuous_is_still_exposed_as_linear(): void
    {
        $service = $this->serviceWithTopology(RouteShape::DISCONTINUOUS);
        $layer = $this->layerWithAttributes([]);

        $values = $service->computeCalculatedValues($layer);

        $this->assertSame('linear', $values['shape']['value']);
    }

    #[DataProvider('dirtyManualValues')]
    public function test_invalid_manual_values_are_ignored(mixed $dirty): void
    {
        $service = $this->serviceWithTopology(RouteShape::DISCONTINUOUS);
        $layer = $this->layerWithAttributes([LayerAttributesService::SHAPE_MANUAL_KEY => $dirty]);

        $this->assertNull($service->manualShape($layer));
        $this->assertSame('linear', $service->computeCalculatedValues($layer)['shape']['value']);
    }

    public static function dirtyManualValues(): array
    {
        return [
            'discontinuous' => ['discontinuous'],
            'sconosciuto' => ['spiral'],
            'oggetto tradotto' => [['value' => 'roundtrip', 'name' => ['it' => 'Ad anello']]],
            'stringa vuota' => [''],
        ];
    }

    public function test_manual_shape_applies_even_without_stages(): void
    {
        $service = $this->serviceWithTopology(null);
        $layer = $this->layerWithAttributes([LayerAttributesService::SHAPE_MANUAL_KEY => 'roundtrip']);

        $values = $service->computeCalculatedValues($layer);

        $this->assertSame('roundtrip', $values['shape']['value']);
        $this->assertArrayNotHasKey('shape_discontinuous', $values);
    }

    public function test_persisting_calculated_values_preserves_the_manual_key(): void
    {
        $service = $this->serviceWithTopology(RouteShape::LINEAR);
        $layer = $this->layerWithAttributes([LayerAttributesService::SHAPE_MANUAL_KEY => 'roundtrip']);

        $service->persistCalculatedValues($layer, $service->computeCalculatedValues($layer));

        $stored = $this->storedAttributes($layer);
        $this->assertSame('roundtrip', $stored[LayerAttributesService::SHAPE_MANUAL_KEY]);
        $this->assertSame('roundtrip', $stored['shape']['value']);
    }

    public function test_apply_manual_shape_writes_code_and_final_shape_synchronously(): void
    {
        $service = $this->serviceWithTopology(RouteShape::DISCONTINUOUS);
        $layer = $this->layerWithAttributes([]);
        $service->persistCalculatedValues($layer, $service->computeCalculatedValues($layer));

        $changed = $service->applyManualShape($layer, 'roundtrip');

        $stored = $this->storedAttributes($layer);
        $this->assertTrue($changed);
        $this->assertSame('roundtrip', $stored[LayerAttributesService::SHAPE_MANUAL_KEY]);
        $this->assertSame('roundtrip', $stored['shape']['value']);
        $this->assertSame(RouteShape::ROUNDTRIP->labelIn('en'), $stored['shape']['name']['en']);
        $this->assertTrue($stored['shape_discontinuous']);

        $this->assertFalse($service->applyManualShape($layer, 'roundtrip'), 'Stesso valore: nessuna variazione da propagare.');
    }

    public function test_apply_manual_shape_null_restores_the_calculated_value(): void
    {
        $service = $this->serviceWithTopology(RouteShape::DISCONTINUOUS);
        $layer = $this->layerWithAttributes([]);
        $service->applyManualShape($layer, 'roundtrip');

        $changed = $service->applyManualShape($layer, null);

        $stored = $this->storedAttributes($layer);
        $this->assertTrue($changed);
        $this->assertArrayNotHasKey(LayerAttributesService::SHAPE_MANUAL_KEY, $stored);
        $this->assertSame('linear', $stored['shape']['value']);
    }

    public function test_apply_manual_shape_rejects_discontinuous_as_override(): void
    {
        $service = $this->serviceWithTopology(RouteShape::LINEAR);
        $layer = $this->layerWithAttributes([]);
        $service->applyManualShape($layer, 'roundtrip');

        $service->applyManualShape($layer, 'discontinuous');

        $stored = $this->storedAttributes($layer);
        $this->assertArrayNotHasKey(LayerAttributesService::SHAPE_MANUAL_KEY, $stored);
        $this->assertSame('linear', $stored['shape']['value']);
    }

    public function test_unchanged_override_skips_the_recalculation(): void
    {
        $service = $this->serviceWithTopology(RouteShape::DISCONTINUOUS);
        $layer = $this->layerWithAttributes([]);
        $service->applyManualShape($layer, 'roundtrip');

        // Il ricalcolo darebbe `linear` senza override: se il metodo non
        // saltasse, il valore sentinella qui sotto verrebbe riscritto.
        $sentinel = ['value' => 'sentinel', 'name' => []];
        DB::update(
            "UPDATE layers SET properties = jsonb_set(properties, '{attributes,shape}', ?::jsonb) WHERE id = ?",
            [json_encode($sentinel), $layer->id]
        );

        $this->assertFalse($service->applyManualShape($layer, 'roundtrip'));
        $this->assertSame('sentinel', $this->storedAttributes($layer)['shape']['value']);
    }

    public function test_recalculation_job_does_not_change_an_override(): void
    {
        $service = $this->serviceWithTopology(RouteShape::DISCONTINUOUS);
        $this->app->instance(LayerAttributesService::class, $service);
        $layer = $this->layerWithAttributes([]);
        $service->applyManualShape($layer, 'roundtrip');

        (new RecalculateLayerAttributesJob($layer->id))->handle($service);

        $this->assertSame('roundtrip', $this->storedAttributes($layer)['shape']['value']);
    }
}

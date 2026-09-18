<?php

namespace Tests\Feature;

use App\Services\LayerAttributesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer as LayerModel;
use Wm\WmPackage\Services\Models\App\AppConfigService;

class LayerConfigJsonShapeDiscontinuousTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Stesso motivo di LayerAttributesStatePanelTest::setUp(): evita che
        // il RecalculateLayerAttributesJob accodato da LayerObserver::saved()
        // sovrascriva, con un worker reale in ascolto, i valori impostati a
        // mano da questo test.
        Queue::fake();
    }

    public function test_a_discontinuous_layer_never_exposes_shape_discontinuous_in_config_json(): void
    {
        $app = App::factory()->createQuietly();

        $layer = LayerModel::factory()->create([
            'app_id' => $app->id,
            'properties' => [],
        ]);

        $service = new class extends LayerAttributesService
        {
            public function determineType(array $endpoints): \App\Enums\RouteShape
            {
                return \App\Enums\RouteShape::DISCONTINUOUS;
            }
        };

        $values = $service->computeCalculatedValues($layer);
        $service->persistCalculatedValues($layer, $values);
        $layer->refresh();

        $config = (new AppConfigService($app))->config();
        $layerItem = collect($config['MAP']['layers'])->firstWhere('id', $layer->id);

        $this->assertSame('linear', $layerItem['attributes']['shape']['value']);
        $this->assertArrayNotHasKey('shape_discontinuous', $layerItem['attributes']);
    }
}

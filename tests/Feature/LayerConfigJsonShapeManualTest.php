<?php

namespace Tests\Feature;

use App\Enums\RouteShape;
use App\Services\LayerAttributesService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\Models\App\AppConfigService;

class LayerConfigJsonShapeManualTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::fake();
    }

    private function discontinuousService(): LayerAttributesService
    {
        return new class extends LayerAttributesService
        {
            public function determineType(array $endpoints): RouteShape
            {
                return RouteShape::DISCONTINUOUS;
            }
        };
    }

    public function test_manual_key_is_declared_internal(): void
    {
        $this->assertContains(
            LayerAttributesService::SHAPE_MANUAL_KEY,
            config('wm-package.internal_attribute_keys')
        );
    }

    public function test_config_json_exposes_only_the_final_shape(): void
    {
        $app = App::factory()->createQuietly();
        $layer = Layer::factory()->create(['app_id' => $app->id, 'properties' => []]);
        $service = $this->discontinuousService();
        $service->persistCalculatedValues($layer, $service->computeCalculatedValues($layer));
        $service->applyManualShape($layer, 'roundtrip');
        $layer->refresh();

        $config = (new AppConfigService($app))->config();
        $item = collect($config['MAP']['layers'])->firstWhere('id', $layer->id);

        $this->assertSame('roundtrip', $item['attributes']['shape']['value']);
        $this->assertArrayNotHasKey(LayerAttributesService::SHAPE_MANUAL_KEY, $item['attributes']);
        $this->assertArrayNotHasKey('shape_discontinuous', $item['attributes']);
    }

    public function test_layer_api_exposes_only_the_final_shape(): void
    {
        $app = App::factory()->createQuietly();
        $layer = Layer::factory()->create(['app_id' => $app->id, 'properties' => []]);
        $service = $this->discontinuousService();
        $service->persistCalculatedValues($layer, $service->computeCalculatedValues($layer));
        $service->applyManualShape($layer, 'roundtrip');

        $response = $this->getJson('/api/app/webapp/'.$app->id.'/layer/'.$layer->id);

        $response->assertOk();
        $attributes = $response->json('properties.attributes');
        $this->assertSame('roundtrip', $attributes['shape']['value']);
        $this->assertArrayNotHasKey(LayerAttributesService::SHAPE_MANUAL_KEY, $attributes);
    }
}

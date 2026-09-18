<?php

namespace Tests\Unit\Services;

use App\Enums\RouteShape;
use App\Services\LayerAttributesService;
use Tests\TestCase;
use Wm\WmPackage\Models\Layer;

class LayerAttributesServiceShapeDiscontinuousTest extends TestCase
{
    public function test_discontinuous_topology_is_persisted_as_linear_with_internal_flag(): void
    {
        $service = new class extends LayerAttributesService
        {
            public function determineType(array $endpoints): RouteShape
            {
                return RouteShape::DISCONTINUOUS;
            }
        };

        $layer = new Layer;
        $values = $service->computeCalculatedValues($layer);

        $this->assertSame('linear', $values['shape']['value']);
        $this->assertTrue($values['shape_discontinuous']);
    }

    public function test_non_discontinuous_topology_never_sets_the_internal_flag(): void
    {
        $service = new class extends LayerAttributesService
        {
            public function determineType(array $endpoints): RouteShape
            {
                return RouteShape::ROUNDTRIP;
            }
        };

        $layer = new Layer;
        $values = $service->computeCalculatedValues($layer);

        $this->assertSame('roundtrip', $values['shape']['value']);
        $this->assertArrayNotHasKey('shape_discontinuous', $values);
    }
}

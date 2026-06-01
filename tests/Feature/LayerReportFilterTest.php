<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Nova\Http\Requests\NovaRequest;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App as WmApp;
use Wm\WmPackage\Models\UgcPoi;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class LayerReportFilterTest extends TestCase
{
    use DatabaseTransactions, LayerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        RolesAndPermissionsService::seedDatabase();
        if (WmApp::count() === 0) {
            WmApp::factory()->create();
        }
    }

    private function makeRequest(User $user): NovaRequest
    {
        $request = NovaRequest::create('/');
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    public function test_administrator_has_layer_filter_field(): void
    {
        $admin = $this->createUserWithRole('Administrator');
        $request = $this->makeRequest($admin);

        $resource = new \App\Nova\UgcPoi(new UgcPoi);
        $attributes = array_map(fn ($f) => $f->attribute, $resource->fields($request));

        $this->assertContains('layer_filter', $attributes);
    }

    public function test_validator_does_not_have_layer_filter_field(): void
    {
        $validator = $this->createUserWithRole('Validator');
        $request = $this->makeRequest($validator);

        $resource = new \App\Nova\UgcPoi(new UgcPoi);
        $attributes = array_map(fn ($f) => $f->attribute, $resource->fields($request));

        $this->assertNotContains('layer_filter', $attributes);
    }

    public function test_layer_filter_filters_by_layer_id(): void
    {
        $layer = $this->createLayer();
        $otherLayer = $this->createLayer();

        $match = UgcPoi::factory()->create([
            'properties' => ['form' => ['id' => 'report'], 'layer_id' => $layer->id],
        ]);
        $noMatch = UgcPoi::factory()->create([
            'properties' => ['form' => ['id' => 'report'], 'layer_id' => $otherLayer->id],
        ]);

        $results = UgcPoi::query()
            ->whereRaw("(properties->>'layer_id')::integer = ?", [(int) $layer->id])
            ->get();

        $this->assertTrue($results->contains($match));
        $this->assertFalse($results->contains($noMatch));
    }

    public function test_layer_filter_options_includes_only_layers_with_segnalazioni(): void
    {
        $layerWith = $this->createLayer();
        $layerWithout = $this->createLayer();

        UgcPoi::factory()->create([
            'properties' => ['layer_id' => $layerWith->id],
        ]);

        $layerIds = \App\Models\UgcPoi::query()
            ->whereRaw("properties->>'layer_id' IS NOT NULL")
            ->selectRaw("DISTINCT (properties->>'layer_id')::integer AS layer_id")
            ->pluck('layer_id')
            ->toArray();

        $this->assertContains($layerWith->id, $layerIds);
        $this->assertNotContains($layerWithout->id, $layerIds);
    }
}

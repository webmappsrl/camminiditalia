<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Nova\Http\Requests\NovaRequest;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App as WmApp;
use Wm\WmPackage\Models\UgcPoi;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class UgcPoiIndexQueryTest extends TestCase
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

    private function createReportPoi(int $layerId): UgcPoi
    {
        return UgcPoi::factory()->create([
            'properties' => [
                'form' => ['id' => 'report'],
                'layer_id' => $layerId,
            ],
        ]);
    }

    private function createPoiUgc(int $layerId): UgcPoi
    {
        return UgcPoi::factory()->create([
            'properties' => [
                'form' => ['id' => 'poi'],
                'layer_id' => $layerId,
            ],
        ]);
    }

    public function test_administrator_sees_all_ugc_pois(): void
    {
        $admin = $this->createUserWithRole('Administrator');
        $layer = $this->createLayer();

        $report = $this->createReportPoi($layer->id);
        $poi = $this->createPoiUgc($layer->id);

        $request = NovaRequest::create('/');
        $request->setUserResolver(fn () => $admin);

        $results = \App\Nova\UgcPoi::indexQuery($request, UgcPoi::query())->get();

        $this->assertTrue($results->contains($report));
        $this->assertTrue($results->contains($poi));
    }

    public function test_validator_sees_only_reports_of_owned_layers(): void
    {
        $validator = $this->createUserWithRole('Validator');
        $ownedLayer = $this->createLayer($validator->id);
        $otherLayer = $this->createLayer();

        $ownedReport = $this->createReportPoi($ownedLayer->id);
        $otherReport = $this->createReportPoi($otherLayer->id);
        $ownedPoi = $this->createPoiUgc($ownedLayer->id);

        $results = \App\Nova\UgcPoi::filteredQueryForValidator($validator, UgcPoi::query())->get();

        $this->assertTrue($results->contains($ownedReport));
        $this->assertFalse($results->contains($otherReport));
        $this->assertFalse($results->contains($ownedPoi));
    }

    public function test_validator_without_layers_sees_empty_list(): void
    {
        $validator = $this->createUserWithRole('Validator');
        $layer = $this->createLayer();
        $this->createReportPoi($layer->id);

        $results = \App\Nova\UgcPoi::filteredQueryForValidator($validator, UgcPoi::query())->get();

        $this->assertCount(0, $results);
    }
}

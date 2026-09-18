<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Nova\Http\Requests\NovaRequest;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App as WmApp;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Services\RolesAndPermissionsService;

/**
 * Stesso bug di EcTrackIndexQueryTest (oc:8587): Wm\WmPackage\Nova\AbstractEcResource::indexQuery()
 * scopa per app_id posseduto, sempre vuoto per un Validator in camminiditalia
 * (una sola App, di proprietà dell'Administrator). App\Nova\EcPoi::indexQuery()
 * scopa per user_id — condizione necessaria perché oc:8120 (EcPoi sola lettura
 * per Validator) sia davvero visibile in Nova.
 */
class EcPoiIndexQueryTest extends TestCase
{
    use DatabaseTransactions, LayerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::fake();

        RolesAndPermissionsService::seedDatabase();

        if (WmApp::count() === 0) {
            WmApp::factory()->create();
        }
    }

    private function novaRequestFor($user): NovaRequest
    {
        $request = NovaRequest::create('/');
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    private function makeEcPoi(?int $userId = null): EcPoi
    {
        $attrs = ['properties' => []];
        if ($userId) {
            $attrs['user_id'] = $userId;
        }

        return \App\Models\EcPoi::factory()->createQuietly($attrs);
    }

    public function test_administrator_sees_all_ec_pois(): void
    {
        $admin = $this->createUserWithRole('Administrator');
        $poi = $this->makeEcPoi();

        $results = \App\Nova\EcPoi::indexQuery($this->novaRequestFor($admin), EcPoi::query())->get();

        $this->assertTrue($results->contains($poi));
    }

    public function test_validator_sees_only_own_ec_pois(): void
    {
        $validator = $this->createUserWithRole('Validator');
        $otherValidator = $this->createUserWithRole('Validator');

        $ownPoi = $this->makeEcPoi($validator->id);
        $otherPoi = $this->makeEcPoi($otherValidator->id);

        $results = \App\Nova\EcPoi::indexQuery($this->novaRequestFor($validator), EcPoi::query())->get();

        $this->assertTrue($results->contains($ownPoi));
        $this->assertFalse($results->contains($otherPoi));
    }

    public function test_validator_without_ec_pois_sees_empty_list(): void
    {
        $validator = $this->createUserWithRole('Validator');
        $this->makeEcPoi();

        $results = \App\Nova\EcPoi::indexQuery($this->novaRequestFor($validator), EcPoi::query())->get();

        $this->assertCount(0, $results);
    }

    public function test_guest_sees_empty_list(): void
    {
        $guest = $this->createUserWithRole('Guest');
        $this->makeEcPoi();

        $results = \App\Nova\EcPoi::indexQuery($this->novaRequestFor($guest), EcPoi::query())->get();

        $this->assertCount(0, $results);
    }

    public function test_validator_sees_ec_poi_only_after_layer_ownership_transfer(): void
    {
        $oldOwner = $this->createUserWithRole('Validator');
        $newOwner = $this->createUserWithRole('Validator');
        $layer = $this->createLayer($oldOwner->id);

        $poi = $this->makeEcPoi($oldOwner->id);
        $layer->ecPois()->attach($poi->id);

        $beforeTransfer = \App\Nova\EcPoi::indexQuery($this->novaRequestFor($oldOwner), EcPoi::query())->get();
        $this->assertTrue($beforeTransfer->contains($poi));

        $layer->update(['user_id' => $newOwner->id]);

        $afterTransferOldOwner = \App\Nova\EcPoi::indexQuery($this->novaRequestFor($oldOwner), EcPoi::query())->get();
        $afterTransferNewOwner = \App\Nova\EcPoi::indexQuery($this->novaRequestFor($newOwner), EcPoi::query())->get();

        $this->assertFalse($afterTransferOldOwner->contains($poi->id), 'Il vecchio owner non deve più vedere il POI dopo il trasferimento.');
        $this->assertTrue($afterTransferNewOwner->pluck('id')->contains($poi->id), 'Il nuovo owner deve vedere il POI dopo il trasferimento.');
    }
}

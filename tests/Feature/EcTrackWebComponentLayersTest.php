<?php

namespace Tests\Feature;

use App\Models\User;
use App\Nova\EcTrack as EcTrackResource;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Nova\Http\Requests\NovaRequest;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\RolesAndPermissionsService;

/**
 * Layer proposti dal bottone «Copia codice web component» sulla EcTrack (oc:8944): per i
 * non-Administrator solo quelli di cui l'utente è owner, come nella index dei Layer
 * (`App\Nova\Layer::indexQuery()`).
 */
class EcTrackWebComponentLayersTest extends TestCase
{
    use DatabaseTransactions;

    private App $app_;

    private User $admin;

    private User $validator;

    protected function setUp(): void
    {
        parent::setUp();
        RolesAndPermissionsService::seedDatabase();

        $this->app_ = App::factory()->createQuietly([
            'properties' => ['layer_web_component_enabled' => true],
        ]);
        $this->admin = User::factory()->create(['app_id' => $this->app_->id]);
        $this->admin->assignRole('Administrator');
        $this->validator = User::factory()->create(['app_id' => $this->app_->id]);
        $this->validator->assignRole('Validator');
    }

    private function layer(?User $owner): Layer
    {
        return Layer::withoutEvents(fn () => Layer::factory()->create([
            'app_id' => $this->app_->id,
            'user_id' => $owner?->id,
        ]));
    }

    /** @param  array<int, Layer>  $layers */
    private function validatorTrack(array $layers): EcTrack
    {
        $track = EcTrack::withoutEvents(fn () => EcTrack::factory()->create([
            'app_id' => $this->app_->id,
            'user_id' => $this->validator->id,
            'properties' => [],
            'osmid' => null,
        ]));

        foreach ($layers as $layer) {
            DB::table('layerables')->insert([
                'layer_id' => $layer->id,
                'layerable_id' => $track->id,
                'layerable_type' => (new (config('wm-package.ec_track_model')))->getMorphClass(),
            ]);
        }

        return $track;
    }

    private function offeredLayerIds(EcTrack $track, User $as): array
    {
        $request = NovaRequest::create('/nova-api/ec-tracks/'.$track->id);
        $request->setUserResolver(fn () => $as);

        $resource = new EcTrackResource($track);
        $method = new \ReflectionMethod($resource, 'webComponentLayers');

        /** @var Collection $layers */
        $layers = $method->invoke($resource, $request);

        return $layers->pluck('id')->all();
    }

    public function test_administrator_gets_all_layers_of_the_track(): void
    {
        $own = $this->layer($this->validator);
        $adminLayer = $this->layer($this->admin);
        $orphan = $this->layer(null);
        $track = $this->validatorTrack([$orphan, $own, $adminLayer]);

        $expected = collect([$own->id, $adminLayer->id, $orphan->id])->sort()->values()->all();

        $this->assertSame($expected, $this->offeredLayerIds($track, $this->admin));
    }

    public function test_validator_gets_only_owned_layers(): void
    {
        $own = $this->layer($this->validator);
        $track = $this->validatorTrack([$own, $this->layer($this->admin), $this->layer(null)]);

        $this->assertSame([$own->id], $this->offeredLayerIds($track, $this->validator));
    }

    public function test_validator_gets_nothing_when_no_layer_is_owned(): void
    {
        $track = $this->validatorTrack([$this->layer($this->admin), $this->layer(null)]);

        $this->assertSame([], $this->offeredLayerIds($track, $this->validator));
    }

    public function test_flag_off_excludes_layers_also_for_administrator(): void
    {
        $this->app_->properties = ['layer_web_component_enabled' => false];
        $this->app_->saveQuietly();
        $track = $this->validatorTrack([$this->layer($this->admin)]);

        $this->assertSame([], $this->offeredLayerIds($track, $this->admin));
    }
}

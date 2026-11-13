<?php

namespace Tests\Feature;

use App\Models\User;
use App\Nova\EcTrack as EcTrackResource;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Nova\Http\Requests\NovaRequest;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack as EcTrackModel;
use Wm\WmPackage\Services\RolesAndPermissionsService;

/**
 * Alla creazione in Nova, un Validator con un solo cammino entra automaticamente in quel layer (oc:8976).
 */
class EcTrackValidatorLayerAutoAttachTest extends TestCase
{
    use DatabaseTransactions, LayerTestHelpers;

    private App $app_;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::fake();
        RolesAndPermissionsService::seedDatabase();
        $this->app_ = App::factory()->createQuietly();
    }

    private function morphClass(): string
    {
        return (new (config('wm-package.ec_track_model')))->getMorphClass();
    }

    private function layerableCount(EcTrackModel $track): int
    {
        return DB::table('layerables')
            ->where('layerable_id', $track->id)
            ->where('layerable_type', $this->morphClass())
            ->count();
    }

    private function runAfterCreate(EcTrackModel $track, User $user): void
    {
        $request = NovaRequest::create('/nova-api/ec-tracks', 'POST');
        $request->setUserResolver(fn () => $user);
        EcTrackResource::afterCreate($request, $track);
    }

    private function makeTrack(User $owner): EcTrackModel
    {
        return EcTrackModel::factory()->create([
            'app_id' => $this->app_->id,
            'user_id' => $owner->id,
            'properties' => [],
            'osmid' => null,
        ]);
    }

    public function test_validator_with_one_layer_gets_auto_attached(): void
    {
        $validator = $this->createUserWithRole('Validator');
        $layer = $this->createLayer($validator->id);
        $track = $this->makeTrack($validator);

        $this->runAfterCreate($track, $validator);

        $this->assertSame(1, $this->layerableCount($track));
        $this->assertDatabaseHas('layerables', [
            'layer_id' => $layer->id,
            'layerable_id' => $track->id,
            'layerable_type' => $this->morphClass(),
        ]);
    }

    public function test_administrator_does_not_get_auto_attached(): void
    {
        $admin = $this->createUserWithRole('Administrator');
        $this->createLayer($admin->id);
        $track = $this->makeTrack($admin);

        $this->runAfterCreate($track, $admin);

        $this->assertSame(0, $this->layerableCount($track));
    }

    public function test_validator_with_no_layer_stays_orphan(): void
    {
        $validator = $this->createUserWithRole('Validator');
        $track = $this->makeTrack($validator);

        $this->runAfterCreate($track, $validator);

        $this->assertSame(0, $this->layerableCount($track));
    }

    public function test_user_with_both_administrator_and_validator_roles_does_not_get_auto_attached(): void
    {
        $user = $this->createUserWithRole('Validator');
        $user->assignRole('Administrator');
        $this->createLayer($user->id);
        $track = $this->makeTrack($user);

        $this->runAfterCreate($track, $user);

        $this->assertSame(0, $this->layerableCount($track));
    }

    /** Il caso del ticket: dopo la creazione «Variante di» propone le tappe del cammino. */
    public function test_after_auto_attach_variant_of_offers_the_stages_of_the_route(): void
    {
        $validator = $this->createUserWithRole('Validator');
        $layer = $this->createLayer($validator->id);
        $sibling = $this->makeTrack($validator);
        DB::table('layerables')->insert([
            'layer_id' => $layer->id,
            'layerable_id' => $sibling->id,
            'layerable_type' => $this->morphClass(),
        ]);
        $track = $this->makeTrack($validator);
        $this->actingAs($validator);

        $this->runAfterCreate($track, $validator);

        $request = NovaRequest::create('/nova-api/ec-tracks/'.$track->id, 'GET');
        $request->setUserResolver(fn () => $validator);
        $field = (new EcTrackResource($track->fresh()))
            ->availableFields($request)
            ->first(fn ($f) => $f->attribute === 'variant_of_ec_track_id');
        $optionIds = collect($field->jsonSerialize()['options'] ?? [])
            ->map(fn ($o) => (int) (is_array($o) ? ($o['value'] ?? 0) : $o))
            ->all();

        $this->assertContains($sibling->id, $optionIds);
    }

    public function test_validator_with_multiple_layers_stays_orphan(): void
    {
        $validator = $this->createUserWithRole('Validator');
        $this->createLayer($validator->id);
        $this->createLayer($validator->id);
        $track = $this->makeTrack($validator);

        $this->runAfterCreate($track, $validator);

        $this->assertSame(0, $this->layerableCount($track));
    }
}

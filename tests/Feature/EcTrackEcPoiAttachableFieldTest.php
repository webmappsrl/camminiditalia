<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Nova\Fields\BelongsToMany;
use Laravel\Nova\Fields\MorphToMany;
use Laravel\Nova\Http\Requests\NovaRequest;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App as WmApp;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Services\RolesAndPermissionsService;

/**
 * wm-package/src/Nova/EcPoi.php e wm-package/src/Nova/EcTrack.php referenziano
 * EcTrack::class/EcPoi::class/Layer::class senza `use` esplicito: per
 * risoluzione di namespace risolvono alla classe Nova del package (priva
 * dell'override indexQuery() per user_id di oc:8587), non alla sottoclasse
 * locale. Nova chiama sempre indexQuery() per costruire la lista "attaccabile"
 * di un campo BelongsToMany/MorphToMany, quindi per un Validator il campo
 * risultava sempre vuoto (oc:8611).
 */
class EcTrackEcPoiAttachableFieldTest extends TestCase
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
        // AbstractEcResource::indexQuery() (package) legge Auth::user(), non
        // $request->user(): il resolver sulla request da solo non basta, va
        // autenticata anche la sessione Auth reale.
        $this->actingAs($user);

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

    private function findField(array $fields, string $attribute)
    {
        foreach ($fields as $field) {
            if (property_exists($field, 'attribute') && $field->attribute === $attribute) {
                return $field;
            }
        }

        return null;
    }

    public function test_ecpoi_ectracks_field_points_to_local_ectrack_resource(): void
    {
        $admin = $this->createUserWithRole('Administrator');
        $ecPoi = $this->makeEcPoi($admin->id);

        $fields = (new \App\Nova\EcPoi($ecPoi))->fields($this->novaRequestFor($admin));
        $field = $this->findField($fields, 'ecTracks');

        $this->assertInstanceOf(BelongsToMany::class, $field);
        $this->assertSame(\App\Nova\EcTrack::class, $field->resourceClass);
    }

    public function test_ecpoi_ectracks_field_has_exactly_one_occurrence(): void
    {
        $admin = $this->createUserWithRole('Administrator');
        $ecPoi = $this->makeEcPoi($admin->id);

        $fields = (new \App\Nova\EcPoi($ecPoi))->fields($this->novaRequestFor($admin));
        $occurrences = array_filter($fields, fn ($field) => $field instanceof BelongsToMany && $field->attribute === 'ecTracks');

        $this->assertCount(1, $occurrences);
    }

    public function test_validator_finds_own_ectrack_via_ecpoi_attach_field(): void
    {
        $validator = $this->createUserWithRole('Validator');
        $otherValidator = $this->createUserWithRole('Validator');

        $ownTrack = \App\Models\EcTrack::factory()->createQuietly(['user_id' => $validator->id]);
        $otherTrack = \App\Models\EcTrack::factory()->createQuietly(['user_id' => $otherValidator->id]);

        $ecPoi = $this->makeEcPoi($validator->id);
        $fields = (new \App\Nova\EcPoi($ecPoi))->fields($this->novaRequestFor($validator));
        $field = $this->findField($fields, 'ecTracks');

        $results = $field->resourceClass::indexQuery(
            $this->novaRequestFor($validator),
            $field->resourceClass::newModel()->newQuery()
        )->get();

        $this->assertTrue($results->contains($ownTrack));
        $this->assertFalse($results->contains($otherTrack));
    }

    public function test_administrator_finds_all_ectracks_via_ecpoi_attach_field(): void
    {
        $admin = $this->createUserWithRole('Administrator');
        $validator = $this->createUserWithRole('Validator');

        $track = \App\Models\EcTrack::factory()->createQuietly(['user_id' => $validator->id]);

        $ecPoi = $this->makeEcPoi($admin->id);
        $fields = (new \App\Nova\EcPoi($ecPoi))->fields($this->novaRequestFor($admin));
        $field = $this->findField($fields, 'ecTracks');

        $results = $field->resourceClass::indexQuery(
            $this->novaRequestFor($admin),
            $field->resourceClass::newModel()->newQuery()
        )->get();

        $this->assertTrue($results->contains($track));
    }

    public function test_ectrack_ecpois_field_points_to_local_ecpoi_resource(): void
    {
        $admin = $this->createUserWithRole('Administrator');
        $track = \App\Models\EcTrack::factory()->createQuietly(['user_id' => $admin->id]);

        $fields = (new \App\Nova\EcTrack($track))->fields($this->novaRequestFor($admin));
        $field = $this->findField($fields, 'ecPois');

        $this->assertInstanceOf(BelongsToMany::class, $field);
        $this->assertSame(\App\Nova\EcPoi::class, $field->resourceClass);
        $this->assertTrue($field->searchable);
    }

    public function test_ectrack_ecpois_field_has_exactly_one_occurrence(): void
    {
        $admin = $this->createUserWithRole('Administrator');
        $track = \App\Models\EcTrack::factory()->createQuietly(['user_id' => $admin->id]);

        $fields = (new \App\Nova\EcTrack($track))->fields($this->novaRequestFor($admin));
        $occurrences = array_filter($fields, fn ($field) => $field instanceof BelongsToMany && $field->attribute === 'ecPois');

        $this->assertCount(1, $occurrences);
    }

    public function test_validator_finds_own_ecpoi_via_ectrack_attach_field(): void
    {
        $validator = $this->createUserWithRole('Validator');
        $otherValidator = $this->createUserWithRole('Validator');

        $ownPoi = $this->makeEcPoi($validator->id);
        $otherPoi = $this->makeEcPoi($otherValidator->id);

        $track = \App\Models\EcTrack::factory()->createQuietly(['user_id' => $validator->id]);
        $fields = (new \App\Nova\EcTrack($track))->fields($this->novaRequestFor($validator));
        $field = $this->findField($fields, 'ecPois');

        $results = $field->resourceClass::indexQuery(
            $this->novaRequestFor($validator),
            $field->resourceClass::newModel()->newQuery()
        )->get();

        $this->assertTrue($results->contains($ownPoi));
        $this->assertFalse($results->contains($otherPoi));
    }

    public function test_administrator_finds_all_ecpois_via_ectrack_attach_field(): void
    {
        $admin = $this->createUserWithRole('Administrator');
        $validator = $this->createUserWithRole('Validator');

        $poi = $this->makeEcPoi($validator->id);

        $track = \App\Models\EcTrack::factory()->createQuietly(['user_id' => $admin->id]);
        $fields = (new \App\Nova\EcTrack($track))->fields($this->novaRequestFor($admin));
        $field = $this->findField($fields, 'ecPois');

        $results = $field->resourceClass::indexQuery(
            $this->novaRequestFor($admin),
            $field->resourceClass::newModel()->newQuery()
        )->get();

        $this->assertTrue($results->contains($poi));
    }

    public function test_layers_field_hidden_for_validator(): void
    {
        $validator = $this->createUserWithRole('Validator');
        $track = \App\Models\EcTrack::factory()->createQuietly(['user_id' => $validator->id]);

        $request = $this->novaRequestFor($validator);
        $fields = (new \App\Nova\EcTrack($track))->fields($request);
        $field = $this->findField($fields, 'layers');

        $this->assertInstanceOf(MorphToMany::class, $field);
        $this->assertFalse($field->authorizedToSee($request));
    }

    public function test_layers_field_visible_for_administrator(): void
    {
        $admin = $this->createUserWithRole('Administrator');
        $track = \App\Models\EcTrack::factory()->createQuietly(['user_id' => $admin->id]);

        $request = $this->novaRequestFor($admin);
        $fields = (new \App\Nova\EcTrack($track))->fields($request);
        $field = $this->findField($fields, 'layers');

        $this->assertInstanceOf(MorphToMany::class, $field);
        $this->assertTrue($field->authorizedToSee($request));
    }
}

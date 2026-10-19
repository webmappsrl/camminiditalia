<?php

namespace Tests\Feature;

use App\Models\EcTrackVariant;
use App\Models\User;
use App\Nova\EcTrack as EcTrackResource;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Nova\Http\Requests\NovaRequest;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\RolesAndPermissionsService;

/**
 * Il campo con cui il gestore dichiara che una tappa è variante di un'altra (oc:8165).
 *
 * Senza questa informazione nessun cammino con rami alternativi arriva al 100%, e il nome non
 * è un criterio: le tappe con «variante» nel titolo lo scrivono in sei modi diversi, e i rami
 * di partenza di Via Vandelli non lo scrivono affatto.
 */
class EcTrackVariantFieldTest extends TestCase
{
    use DatabaseTransactions;

    private App $app_;

    private User $admin;

    private Layer $layer;

    protected function setUp(): void
    {
        parent::setUp();
        RolesAndPermissionsService::seedDatabase();

        $this->app_ = App::factory()->createQuietly();
        $this->admin = User::factory()->create(['app_id' => $this->app_->id]);
        $this->admin->assignRole('Administrator');
        $this->layer = Layer::factory()->create(['app_id' => $this->app_->id, 'user_id' => $this->admin->id]);
    }

    private function stage(?Layer $layer = null): EcTrack
    {
        $track = EcTrack::withoutEvents(fn () => EcTrack::factory()->create([
            'app_id' => $this->app_->id,
            'user_id' => $this->admin->id,
            'properties' => [],
            'osmid' => null,
        ]));

        DB::table('layerables')->insert([
            'layer_id' => ($layer ?? $this->layer)->id,
            'layerable_id' => $track->id,
            'layerable_type' => (new (config('wm-package.ec_track_model')))->getMorphClass(),
        ]);

        return $track;
    }

    private function variantField(EcTrack $track)
    {
        $request = NovaRequest::create('/nova-api/ec-tracks/'.$track->id, 'GET');
        $request->setUserResolver(fn () => $this->admin);

        return (new EcTrackResource($track))
            ->availableFields($request)
            ->first(fn ($field) => $field->attribute === 'variant_of_ec_track_id');
    }

    /** Gli id proposti dal campo: Nova serializza le opzioni come coppie label/value. */
    private function optionIds(EcTrack $track): array
    {
        $options = $this->variantField($track)->jsonSerialize()['options'] ?? [];

        return collect($options)
            ->map(fn ($o) => (int) (is_array($o) ? ($o['value'] ?? 0) : $o))
            ->all();
    }

    public function test_the_field_exists_on_a_stage()
    {
        $this->actingAs($this->admin);

        $this->assertNotNull($this->variantField($this->stage()));
    }

    public function test_offers_only_stages_of_the_same_route()
    {
        $this->actingAs($this->admin);

        $stage = $this->stage();
        $sibling = $this->stage();
        $foreign = $this->stage(Layer::factory()->create(['app_id' => $this->app_->id]));

        $optionIds = $this->optionIds($stage);

        $this->assertContains($sibling->id, $optionIds);
        $this->assertNotContains($foreign->id, $optionIds);
        $this->assertNotContains($stage->id, $optionIds, 'una tappa non è variante di sé stessa');
    }

    public function test_does_not_offer_a_stage_that_is_already_a_variant()
    {
        $this->actingAs($this->admin);

        $main = $this->stage();
        $alreadyVariant = $this->stage();
        $stage = $this->stage();
        EcTrackVariant::create(['ec_track_id' => $alreadyVariant->id, 'main_ec_track_id' => $main->id]);

        $optionIds = $this->optionIds($stage);

        $this->assertContains($main->id, $optionIds);
        $this->assertNotContains($alreadyVariant->id, $optionIds, 'le varianti vivono su un solo livello');
    }

    public function test_the_field_is_hidden_on_a_stage_that_already_has_variants()
    {
        // Senza questo, il gestore può scegliere una principale, e il guard del model solleva
        // un'eccezione nuda al salvataggio: in Nova diventa un errore 500 invece di un
        // messaggio. Le varianti vivono su un solo livello.
        $this->actingAs($this->admin);

        $main = $this->stage();
        $variant = $this->stage();
        EcTrackVariant::create(['ec_track_id' => $variant->id, 'main_ec_track_id' => $main->id]);

        $request = NovaRequest::create('/nova-api/ec-tracks/'.$main->id, 'GET');
        $request->setUserResolver(fn () => $this->admin);

        $field = (new EcTrackResource($main))
            ->availableFields($request)
            ->first(fn ($f) => $f->attribute === 'variant_of_ec_track_id');

        $this->assertFalse(
            $field->authorizedToSee($request),
            'una tappa che ha già varianti non può diventare variante'
        );
    }

    public function test_shows_the_main_stage_already_declared()
    {
        $this->actingAs($this->admin);

        $main = $this->stage();
        $variant = $this->stage();
        EcTrackVariant::create(['ec_track_id' => $variant->id, 'main_ec_track_id' => $main->id]);

        $field = $this->variantField($variant);
        $field->resolve($variant);

        $this->assertSame($main->id, $field->value);
    }
}

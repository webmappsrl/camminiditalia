<?php

namespace Tests\Feature;

use App\Models\EcTrackVariant;
use App\Models\User;
use App\Nova\EcTrack as EcTrackResource;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
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

    private User $validator;

    private Layer $layer;

    protected function setUp(): void
    {
        parent::setUp();
        RolesAndPermissionsService::seedDatabase();

        $this->app_ = App::factory()->createQuietly();
        $this->admin = User::factory()->create(['app_id' => $this->app_->id]);
        $this->admin->assignRole('Administrator');
        $this->layer = Layer::factory()->create(['app_id' => $this->app_->id, 'user_id' => $this->admin->id]);
        $this->validator = User::factory()->create(['app_id' => $this->app_->id]);
        $this->validator->assignRole('Validator');
    }

    private function stage(?Layer $layer = null, ?User $owner = null): EcTrack
    {
        $track = EcTrack::withoutEvents(fn () => EcTrack::factory()->create([
            'app_id' => $this->app_->id,
            'user_id' => ($owner ?? $this->admin)->id,
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

    private function variantField(EcTrack $track, ?User $as = null)
    {
        $request = $this->novaRequest($track, $as);

        return (new EcTrackResource($track))
            ->availableFields($request)
            ->first(fn ($field) => $field->attribute === 'variant_of_ec_track_id');
    }

    private function novaRequest(EcTrack $track, ?User $as = null, string $method = 'GET', array $input = []): NovaRequest
    {
        $request = NovaRequest::create('/nova-api/ec-tracks/'.$track->id, $method, $input);
        $user = $as ?? $this->admin;
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    /** Gli id proposti dal campo: Nova serializza le opzioni come coppie label/value. */
    private function optionIds(EcTrack $track, ?User $as = null): array
    {
        $options = $this->variantField($track, $as)->jsonSerialize()['options'] ?? [];

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

    public function test_the_field_is_visible_to_a_validator_on_own_stage()
    {
        $this->actingAs($this->validator);

        $layer = Layer::factory()->create(['app_id' => $this->app_->id, 'user_id' => $this->validator->id]);
        $stage = $this->stage($layer, $this->validator);

        $this->assertTrue($this->variantField($stage, $this->validator)->authorizedToSee($this->novaRequest($stage, $this->validator)));
    }

    public function test_the_field_is_hidden_from_a_guest()
    {
        $guest = User::factory()->create(['app_id' => $this->app_->id]);
        $guest->assignRole('Guest');
        $this->actingAs($guest);

        $stage = $this->stage(null, $guest);
        $request = $this->novaRequest($stage, $guest);

        $field = (new EcTrackResource($stage))
            ->availableFields($request)
            ->first(fn ($f) => $f->attribute === 'variant_of_ec_track_id');

        $this->assertTrue($field === null || ! $field->authorizedToSee($request));
    }

    public function test_validator_is_offered_only_own_stages()
    {
        $this->actingAs($this->validator);

        $layer = Layer::factory()->create(['app_id' => $this->app_->id, 'user_id' => $this->validator->id]);
        $stage = $this->stage($layer, $this->validator);
        $own = $this->stage($layer, $this->validator);
        $foreign = $this->stage($layer, $this->admin);

        $optionIds = $this->optionIds($stage, $this->validator);

        $this->assertContains($own->id, $optionIds);
        $this->assertNotContains($foreign->id, $optionIds, 'al Validator solo le tappe sue');
    }

    public function test_admin_is_still_offered_stages_of_any_owner()
    {
        $this->actingAs($this->admin);

        $stage = $this->stage();
        $validatorStage = $this->stage(null, $this->validator);

        $this->assertContains($validatorStage->id, $this->optionIds($stage));
    }

    public function test_validator_is_offered_the_current_foreign_main()
    {
        // Collegamento creato dall'admin verso una principale non sua: deve restare fra le
        // opzioni, altrimenti il form la perde e il Validator non può salvare senza toccarla.
        $this->actingAs($this->validator);

        $layer = Layer::factory()->create(['app_id' => $this->app_->id, 'user_id' => $this->validator->id]);
        $stage = $this->stage($layer, $this->validator);
        $foreignMain = $this->stage($layer, $this->admin);
        EcTrackVariant::create(['ec_track_id' => $stage->id, 'main_ec_track_id' => $foreignMain->id]);

        $this->assertContains($foreignMain->id, $this->optionIds($stage, $this->validator));
    }

    /** Gli errori di validazione del campo per il valore inviato in un update di Nova. */
    private function validationErrors(EcTrack $track, $value, ?User $as = null): array
    {
        $request = $this->novaRequest($track, $as, 'PUT', ['variant_of_ec_track_id' => $value]);
        $field = $this->variantField($track, $as);

        return Validator::make(['variant_of_ec_track_id' => $value], $field->getUpdateRules($request))
            ->errors()
            ->get('variant_of_ec_track_id');
    }

    /** Esegue il salvataggio del campo come fa Nova dopo l'update della tappa. */
    private function fillVariant(EcTrack $track, $value, ?User $as = null): void
    {
        $request = $this->novaRequest($track, $as, 'PUT', ['variant_of_ec_track_id' => $value]);
        $callback = $this->variantField($track, $as)->fill($request, $track);

        if (is_callable($callback)) {
            $callback();
        }
    }

    private function validatorLayer(): Layer
    {
        return Layer::factory()->create(['app_id' => $this->app_->id, 'user_id' => $this->validator->id]);
    }

    public function test_validator_cannot_submit_a_stage_of_another_owner()
    {
        $this->actingAs($this->validator);

        $layer = $this->validatorLayer();
        $stage = $this->stage($layer, $this->validator);
        $foreign = $this->stage($layer, $this->admin);

        $this->assertSame(
            [__('The chosen main stage is not among the stages you can choose.')],
            $this->validationErrors($stage, $foreign->id, $this->validator)
        );
    }

    public function test_admin_cannot_submit_a_stage_outside_the_routes()
    {
        $this->actingAs($this->admin);

        $stage = $this->stage();
        $elsewhere = $this->stage(Layer::factory()->create(['app_id' => $this->app_->id]));

        $this->assertSame(
            [__('The chosen main stage is not among the stages you can choose.')],
            $this->validationErrors($stage, $elsewhere->id)
        );
    }

    public function test_unchanged_foreign_main_passes_validation()
    {
        $this->actingAs($this->validator);

        $layer = $this->validatorLayer();
        $stage = $this->stage($layer, $this->validator);
        $foreignMain = $this->stage($layer, $this->admin);
        EcTrackVariant::create(['ec_track_id' => $stage->id, 'main_ec_track_id' => $foreignMain->id]);

        $this->assertSame([], $this->validationErrors($stage, $foreignMain->id, $this->validator));
    }

    public function test_unchanged_main_outside_routes_passes_validation()
    {
        // Nessun observer pulisce ec_track_variants quando la principale esce dal cammino:
        // il collegamento rimasto non deve impedire di salvare la tappa.
        $this->actingAs($this->admin);

        $stage = $this->stage();
        $main = $this->stage();
        EcTrackVariant::create(['ec_track_id' => $stage->id, 'main_ec_track_id' => $main->id]);
        DB::table('layerables')->where('layerable_id', $main->id)->delete();

        $this->assertSame([], $this->validationErrors($stage, $main->id));
    }

    public function test_a_main_that_became_a_variant_is_a_validation_error()
    {
        // Il form è stato aperto quando la principale era libera; nel frattempo è diventata
        // variante di un'altra tappa. Prima era un errore 500 dal guard del model.
        $this->actingAs($this->admin);

        $stage = $this->stage();
        $main = $this->stage();
        $other = $this->stage();
        $request = $this->novaRequest($stage, null, 'PUT', ['variant_of_ec_track_id' => $main->id]);
        $rules = $this->variantField($stage)->getUpdateRules($request);

        EcTrackVariant::create(['ec_track_id' => $main->id, 'main_ec_track_id' => $other->id]);

        $errors = Validator::make(['variant_of_ec_track_id' => $main->id], $rules)
            ->errors()
            ->get('variant_of_ec_track_id');

        $this->assertNotSame([], $errors);
        $this->assertNotContains(__('The chosen main stage is not among the stages you can choose.'), $errors);
    }

    public function test_clearing_the_field_removes_the_variant()
    {
        $this->actingAs($this->validator);

        $layer = $this->validatorLayer();
        $stage = $this->stage($layer, $this->validator);
        $foreignMain = $this->stage($layer, $this->admin);
        EcTrackVariant::create(['ec_track_id' => $stage->id, 'main_ec_track_id' => $foreignMain->id]);

        $this->assertSame([], $this->validationErrors($stage, null, $this->validator));
        $this->fillVariant($stage, null, $this->validator);

        $this->assertFalse(EcTrackVariant::whereKey($stage->id)->exists());
    }

    public function test_unchanged_value_is_not_rewritten()
    {
        $this->actingAs($this->admin);

        $stage = $this->stage();
        $main = $this->stage();
        EcTrackVariant::create(['ec_track_id' => $stage->id, 'main_ec_track_id' => $main->id]);
        DB::table('ec_track_variants')->where('ec_track_id', $stage->id)->update(['updated_at' => '2020-01-01 00:00:00']);

        $this->fillVariant($stage, (string) $main->id);

        $this->assertSame(
            '2020-01-01 00:00:00',
            (string) DB::table('ec_track_variants')->where('ec_track_id', $stage->id)->value('updated_at')
        );
    }

    public function test_the_field_is_not_offered_when_creating_a_stage()
    {
        // In creazione le regole non hanno una tappa su cui ragionare e le opzioni sono vuote,
        // ma il fill gira dopo il salvataggio: senza nasconderlo, un id mandato a mano
        // finirebbe in ec_track_variants senza alcun controllo.
        $this->actingAs($this->validator);

        $request = NovaRequest::create('/nova-api/ec-tracks', 'POST');
        $request->setUserResolver(fn () => $this->validator);

        $field = (new EcTrackResource(new EcTrack))
            ->creationFields($request)
            ->first(fn ($f) => $f->attribute === 'variant_of_ec_track_id');

        $this->assertNull($field);
    }
}

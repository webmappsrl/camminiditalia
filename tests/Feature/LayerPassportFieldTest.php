<?php

namespace Tests\Feature;

use App\Jobs\RevokeGpsPassportStagesForLayerJob;
use App\Models\User;
use App\Nova\Actions\BulkEditPassportAction;
use App\Nova\Layer as LayerResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Nova\Actions\ActionResponse;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Http\Requests\NovaRequest;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\RolesAndPermissionsService;

/**
 * I campi «Passaporto cartaceo» e «Passaporto automatico (GPS)» del cammino
 * (properties->passport_enabled / passport_gps_enabled): modificabili solo
 * dagli Administrator, sola lettura per gli altri ruoli (la LayerPolicy lascia
 * comunque modificare il cammino al proprietario). Più l'Action di modifica in blocco.
 */
class LayerPassportFieldTest extends TestCase
{
    use RefreshDatabase;

    private const PAPER = 'properties->passport_enabled';

    private const GPS = 'properties->passport_gps_enabled';

    protected function setUp(): void
    {
        parent::setUp();
        RolesAndPermissionsService::seedDatabase();
        App::factory()->create();
    }

    private function makeUser(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function findField(array $fields, string $attribute): ?array
    {
        foreach ($fields as $field) {
            if (($field['attribute'] ?? null) === $attribute) {
                return $field;
            }
            if ($nested = $this->findField($field['fields'] ?? [], $attribute)) {
                return $nested;
            }
        }

        return null;
    }

    private function passportFieldFor(User $user, string $attribute, string $endpoint = '/update-fields'): ?array
    {
        $layer = Layer::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)
            ->getJson('/nova-api/layers/'.$layer->id.$endpoint);

        $response->assertOk();

        return $this->findField($response->json('resource.fields') ?? $response->json('fields'), $attribute);
    }

    public static function attributes(): array
    {
        return [
            'cartaceo' => [self::PAPER],
            'properties->passport_gps_enabled' => [self::GPS],
        ];
    }

    /**
     * @dataProvider attributes
     */
    public function test_admin_sees_editable_passport_fields(string $attribute): void
    {
        $field = $this->passportFieldFor($this->makeUser('Administrator'), $attribute);

        $this->assertNotNull($field);
        $this->assertEmpty($field['readonly']);
    }

    /**
     * @dataProvider attributes
     */
    public function test_validator_sees_readonly_passport_fields(string $attribute): void
    {
        $field = $this->passportFieldFor($this->makeUser('Validator'), $attribute, '');

        $this->assertNotNull($field);
        $this->assertTrue((bool) $field['readonly']);
    }

    /**
     * @dataProvider attributes
     */
    public function test_guest_owner_sees_readonly_passport_fields(string $attribute): void
    {
        // Il Guest non accede a Nova via HTTP (gate di NovaServiceProvider): si
        // verifica direttamente il campo della risorsa, che è dove sta la regola.
        $guest = $this->makeUser('Guest');
        $layer = Layer::factory()->create(['user_id' => $guest->id]);
        $this->actingAs($guest);

        $request = NovaRequest::create('/nova-api/layers/'.$layer->id, 'GET');
        $request->setUserResolver(fn () => $guest);

        $field = collect((new LayerResource($layer))->fields($request))
            ->first(fn ($f) => ($f->attribute ?? null) === $attribute);

        $this->assertNotNull($field);
        $this->assertTrue($field->isReadonly($request));
    }

    public function test_paper_field_becomes_readonly_and_true_when_gps_is_on_in_the_form(): void
    {
        $admin = $this->makeUser('Administrator');
        $layer = Layer::factory()->create(['user_id' => $admin->id]);

        // Come fa il form di Nova: chiede il campo cartaceo con il GPS acceso (PATCH di sync).
        $field = $this->passportFieldFor($admin, self::PAPER);
        $response = $this->actingAs($admin)->patchJson(
            '/nova-api/layers/'.$layer->id.'/update-fields?editing=true&editMode=update&field='
            .urlencode(self::PAPER).'&component='.urlencode($field['dependentComponentKey']),
            [self::GPS => true]
        );

        $response->assertOk();
        $this->assertTrue((bool) $response->json('readonly'));
        $this->assertTrue((bool) $response->json('value'));

        // Con il GPS spento il cartaceo resta libero.
        $off = $this->actingAs($admin)->patchJson(
            '/nova-api/layers/'.$layer->id.'/update-fields?editing=true&editMode=update&field='
            .urlencode(self::PAPER).'&component='.urlencode($field['dependentComponentKey']),
            [self::GPS => false]
        );
        $this->assertFalse((bool) $off->json('readonly'));
    }

    private function adminAction(): BulkEditPassportAction
    {
        $this->actingAs($this->makeUser('Administrator'));

        return new BulkEditPassportAction;
    }

    private function fieldsFor(array $values): ActionFields
    {
        return new ActionFields(collect($values), collect());
    }

    public function test_bulk_edit_passport_visible_only_to_admin(): void
    {
        $layer = Layer::factory()->create(['user_id' => $this->makeUser('Administrator')->id]);

        $names = fn (User $user) => collect(
            $this->actingAs($user)->getJson('/nova-api/layers/actions?resourceId='.$layer->id)->json('actions')
        )->pluck('uriKey')->all();

        $key = (new BulkEditPassportAction)->uriKey();
        $this->assertContains($key, $names($this->makeUser('Administrator')));
        $this->assertNotContains($key, $names($this->makeUser('Validator')));
    }

    public function test_bulk_gps_on_turns_paper_on_and_keeps_other_properties(): void
    {
        $layers = Layer::factory()->count(2)->create([
            'user_id' => $this->makeUser('Administrator')->id,
            'properties' => ['other_key' => 'keep'],
        ]);

        $this->adminAction()->handleForLayers($this->fieldsFor(['properties->passport_gps_enabled' => '1', 'properties->passport_enabled' => null]), $layers);

        foreach ($layers as $layer) {
            $properties = $layer->fresh()->properties;
            $this->assertTrue($properties['passport_gps_enabled']);
            $this->assertTrue($properties['passport_enabled']);
            $this->assertSame('keep', $properties['other_key']);
        }
    }

    public function test_bulk_paper_off_with_gps_on_changes_nothing(): void
    {
        $owner = $this->makeUser('Administrator');
        $ok = Layer::factory()->create(['user_id' => $owner->id, 'properties' => ['passport_enabled' => true]]);
        $gps = Layer::factory()->create(['user_id' => $owner->id, 'properties' => ['passport_enabled' => true, 'passport_gps_enabled' => true]]);

        $result = $this->adminAction()->handleForLayers($this->fieldsFor(['properties->passport_enabled' => '0', 'properties->passport_gps_enabled' => null]), collect([$ok, $gps]));

        $this->assertInstanceOf(ActionResponse::class, $result);
        $this->assertSame(
            __('The paper passport cannot be turned off on :names: the automatic passport is on.', ['names' => $gps->getStringName()]),
            json_decode(json_encode($result), true)['danger']
        );
        $this->assertTrue($ok->fresh()->properties['passport_enabled']);
        $this->assertTrue($gps->fresh()->properties['passport_enabled']);
    }

    public function test_bulk_paper_off_together_with_gps_off_is_allowed(): void
    {
        $layer = Layer::factory()->create([
            'user_id' => $this->makeUser('Administrator')->id,
            'properties' => ['passport_enabled' => true, 'passport_gps_enabled' => true],
        ]);

        $this->adminAction()->handleForLayers($this->fieldsFor(['properties->passport_enabled' => '0', 'properties->passport_gps_enabled' => '0']), collect([$layer]));

        $properties = $layer->fresh()->properties;
        $this->assertFalse($properties['passport_enabled']);
        $this->assertFalse($properties['passport_gps_enabled']);
    }

    public function test_bulk_gps_off_dispatches_revoke(): void
    {
        $layer = Layer::factory()->create([
            'user_id' => $this->makeUser('Administrator')->id,
            'properties' => ['passport_enabled' => true, 'passport_gps_enabled' => true],
        ]);
        Queue::fake();

        $this->adminAction()->handleForLayers($this->fieldsFor(['properties->passport_gps_enabled' => '0', 'properties->passport_enabled' => null]), collect([$layer]));

        Queue::assertPushed(RevokeGpsPassportStagesForLayerJob::class);
    }

    public function test_bulk_null_fields_change_nothing(): void
    {
        $layer = Layer::factory()->create([
            'user_id' => $this->makeUser('Administrator')->id,
            'properties' => ['passport_enabled' => true, 'other_key' => 'keep'],
        ]);

        $this->adminAction()->handleForLayers($this->fieldsFor(['properties->passport_enabled' => null, 'properties->passport_gps_enabled' => '']), collect([$layer]));

        $this->assertEquals($layer->properties, $layer->fresh()->properties);
    }

    public function test_bulk_edit_fields_are_tri_state_selects_with_translated_labels(): void
    {
        $this->actingAs($this->makeUser('Administrator'));

        $fields = (new BulkEditPassportAction)->fields(app(\Laravel\Nova\Http\Requests\NovaRequest::class));

        $this->assertEqualsCanonicalizing(
            ['properties->passport_enabled', 'properties->passport_gps_enabled'],
            collect($fields)->pluck('attribute')->all()
        );
        $names = collect($fields)->pluck('name')->all();
        $this->assertContains(__('Paper passport'), $names);
        $this->assertContains(__('Automatic passport (GPS)'), $names);
        foreach ($fields as $field) {
            $this->assertSame(['1' => __('Yes'), '0' => __('No')], collect($field->jsonSerialize()['options'])->pluck('label', 'value')->all());
        }
    }

    public function test_bulk_edit_does_not_queue_layer_attributes_recalculation(): void
    {
        $owner = $this->makeUser('Administrator');
        $layers = Layer::factory()->count(2)->create([
            'user_id' => $owner->id,
            'properties' => ['passport_enabled' => true],
        ]);
        Queue::fake();

        $this->adminAction()->handleForLayers(
            $this->fieldsFor(['properties->passport_gps_enabled' => '1', 'properties->passport_enabled' => null]),
            $layers
        );

        Queue::assertNotPushed(\App\Jobs\RecalculateLayerAttributesJob::class);
        Queue::assertPushed(\App\Jobs\RecomputePassportStagesForLayerJob::class, 2);
    }
}

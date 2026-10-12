<?php

namespace Tests\Feature;

use App\Models\User;
use App\Nova\Layer as LayerResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Http\Requests\NovaRequest;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Nova\Actions\BulkEditAction;
use Wm\WmPackage\Services\RolesAndPermissionsService;

/**
 * Il campo «Passport enabled» (properties->passport_enabled) del cammino:
 * modificabile solo dagli Administrator, sola lettura per gli altri ruoli
 * (la LayerPolicy lascia comunque modificare il cammino al proprietario).
 */
class LayerPassportFieldTest extends TestCase
{
    use RefreshDatabase;

    private const ATTRIBUTE = 'properties->passport_enabled';

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

    private function passportFieldFor(User $user, string $endpoint = '/update-fields'): ?array
    {
        $layer = Layer::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)
            ->getJson('/nova-api/layers/'.$layer->id.$endpoint);

        $response->assertOk();

        return $this->findField($response->json('resource.fields') ?? $response->json('fields'), self::ATTRIBUTE);
    }

    public function test_admin_sees_editable_passport_field(): void
    {
        $field = $this->passportFieldFor($this->makeUser('Administrator'));

        $this->assertNotNull($field);
        $this->assertEmpty($field['readonly']);
    }

    public function test_validator_sees_readonly_passport_field(): void
    {
        $field = $this->passportFieldFor($this->makeUser('Validator'), '');

        $this->assertNotNull($field);
        $this->assertTrue((bool) $field['readonly']);
    }

    public function test_guest_owner_sees_readonly_passport_field(): void
    {
        // Il Guest non accede a Nova via HTTP (gate di NovaServiceProvider): si
        // verifica direttamente il campo della risorsa, che è dove sta la regola.
        $guest = $this->makeUser('Guest');
        $layer = Layer::factory()->create(['user_id' => $guest->id]);
        $this->actingAs($guest);

        $request = NovaRequest::create('/nova-api/layers/'.$layer->id, 'GET');
        $request->setUserResolver(fn () => $guest);

        $field = collect((new LayerResource($layer))->fields($request))
            ->first(fn ($f) => ($f->attribute ?? null) === self::ATTRIBUTE);

        $this->assertNotNull($field);
        $this->assertTrue($field->isReadonly($request));
    }

    public function test_bulk_edit_passport_visible_only_to_admin(): void
    {
        $layer = Layer::factory()->create(['user_id' => $this->makeUser('Administrator')->id]);

        $names = fn (User $user) => collect(
            $this->actingAs($user)->getJson('/nova-api/layers/actions?resourceId='.$layer->id)->json('actions')
        )->pluck('uriKey')->all();

        $this->assertContains('bulk-edit', $names($this->makeUser('Administrator')));
        $this->assertNotContains('bulk-edit', $names($this->makeUser('Validator')));
    }

    public function test_bulk_edit_sets_passport_enabled_true_on_selected_layers(): void
    {
        $owner = $this->makeUser('Administrator');
        $layers = Layer::factory()->count(2)->create([
            'user_id' => $owner->id,
            'properties' => ['other_key' => 'keep'],
        ]);

        $this->actingAs($owner);
        $action = new BulkEditAction(LayerResource::class, [self::ATTRIBUTE]);
        $this->assertCount(1, $action->fields(app(NovaRequest::class)));

        $action->handle(new ActionFields(collect([self::ATTRIBUTE => '1']), collect()), $layers);

        foreach ($layers as $layer) {
            $properties = $layer->fresh()->properties;
            $this->assertTrue($properties['passport_enabled']);
            $this->assertSame('keep', $properties['other_key']);
        }
    }
}

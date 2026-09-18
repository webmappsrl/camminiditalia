<?php

namespace Tests\Feature;

use App\Models\TaxonomyPoiType;
use App\Models\User;
use App\Policies\TaxonomyPoiTypePolicy;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class TaxonomyPoiTypePolicyTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        RolesAndPermissionsService::seedDatabase();
        if (App::count() === 0) {
            App::factory()->create();
        }
    }

    private function makeUser(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function makeTaxonomyPoiType(): TaxonomyPoiType
    {
        $taxonomyPoiType = new TaxonomyPoiType(['name' => ['it' => 'Test', 'en' => 'Test']]);
        $taxonomyPoiType->saveQuietly();

        return $taxonomyPoiType;
    }

    // --- Policy attiva è quella locale, non del package ---

    public function test_local_taxonomy_poi_type_policy_is_registered(): void
    {
        $policy = Gate::getPolicyFor(TaxonomyPoiType::class);
        $this->assertInstanceOf(TaxonomyPoiTypePolicy::class, $policy);
    }

    // --- Administrator: autorizzato su tutte le ability tranne delete (Task 2) ---

    public function test_administrator_can_view_any_taxonomy_poi_type(): void
    {
        $admin = $this->makeUser('Administrator');
        $this->assertTrue(Gate::forUser($admin)->allows('viewAny', TaxonomyPoiType::class));
    }

    public function test_administrator_can_view_taxonomy_poi_type(): void
    {
        $admin = $this->makeUser('Administrator');
        $taxonomyPoiType = $this->makeTaxonomyPoiType();
        $this->assertTrue(Gate::forUser($admin)->allows('view', $taxonomyPoiType));
    }

    public function test_administrator_can_create_taxonomy_poi_type(): void
    {
        $admin = $this->makeUser('Administrator');
        $this->assertTrue(Gate::forUser($admin)->allows('create', TaxonomyPoiType::class));
    }

    public function test_administrator_can_update_taxonomy_poi_type(): void
    {
        $admin = $this->makeUser('Administrator');
        $taxonomyPoiType = $this->makeTaxonomyPoiType();
        $this->assertTrue(Gate::forUser($admin)->allows('update', $taxonomyPoiType));
    }

    public function test_administrator_can_restore_taxonomy_poi_type(): void
    {
        $admin = $this->makeUser('Administrator');
        $taxonomyPoiType = $this->makeTaxonomyPoiType();
        $this->assertTrue(Gate::forUser($admin)->allows('restore', $taxonomyPoiType));
    }

    public function test_administrator_can_force_delete_taxonomy_poi_type(): void
    {
        $admin = $this->makeUser('Administrator');
        $taxonomyPoiType = $this->makeTaxonomyPoiType();
        $this->assertTrue(Gate::forUser($admin)->allows('forceDelete', $taxonomyPoiType));
    }

    // --- Validator: nessun permesso di scrittura ---

    public function test_validator_cannot_create_taxonomy_poi_type(): void
    {
        $validator = $this->makeUser('Validator');
        $this->assertFalse(Gate::forUser($validator)->allows('create', TaxonomyPoiType::class));
    }

    public function test_validator_cannot_update_taxonomy_poi_type(): void
    {
        $validator = $this->makeUser('Validator');
        $taxonomyPoiType = $this->makeTaxonomyPoiType();
        $this->assertFalse(Gate::forUser($validator)->allows('update', $taxonomyPoiType));
    }

    public function test_validator_cannot_restore_taxonomy_poi_type(): void
    {
        $validator = $this->makeUser('Validator');
        $taxonomyPoiType = $this->makeTaxonomyPoiType();
        $this->assertFalse(Gate::forUser($validator)->allows('restore', $taxonomyPoiType));
    }

    public function test_validator_cannot_force_delete_taxonomy_poi_type(): void
    {
        $validator = $this->makeUser('Validator');
        $taxonomyPoiType = $this->makeTaxonomyPoiType();
        $this->assertFalse(Gate::forUser($validator)->allows('forceDelete', $taxonomyPoiType));
    }

    // --- Guest: nessun permesso di scrittura ---

    public function test_guest_cannot_create_taxonomy_poi_type(): void
    {
        $guest = $this->makeUser('Guest');
        $this->assertFalse(Gate::forUser($guest)->allows('create', TaxonomyPoiType::class));
    }

    public function test_guest_cannot_update_taxonomy_poi_type(): void
    {
        $guest = $this->makeUser('Guest');
        $taxonomyPoiType = $this->makeTaxonomyPoiType();
        $this->assertFalse(Gate::forUser($guest)->allows('update', $taxonomyPoiType));
    }

    // --- viewAny/view: comportamento preesistente invariato, sempre autorizzato ---

    public function test_validator_can_view_any_taxonomy_poi_type(): void
    {
        $validator = $this->makeUser('Validator');
        $this->assertTrue(Gate::forUser($validator)->allows('viewAny', TaxonomyPoiType::class));
    }

    public function test_validator_can_view_taxonomy_poi_type(): void
    {
        $validator = $this->makeUser('Validator');
        $taxonomyPoiType = $this->makeTaxonomyPoiType();
        $this->assertTrue(Gate::forUser($validator)->allows('view', $taxonomyPoiType));
    }

    public function test_guest_can_view_any_taxonomy_poi_type(): void
    {
        $guest = $this->makeUser('Guest');
        $this->assertTrue(Gate::forUser($guest)->allows('viewAny', TaxonomyPoiType::class));
    }

    public function test_guest_can_view_taxonomy_poi_type(): void
    {
        $guest = $this->makeUser('Guest');
        $taxonomyPoiType = $this->makeTaxonomyPoiType();
        $this->assertTrue(Gate::forUser($guest)->allows('view', $taxonomyPoiType));
    }

    // --- delete(): Administrator autorizzato solo se il tipo non è in uso ---

    public function test_administrator_can_delete_taxonomy_poi_type_not_in_use(): void
    {
        $admin = $this->makeUser('Administrator');
        $taxonomyPoiType = $this->makeTaxonomyPoiType();

        $this->assertTrue(Gate::forUser($admin)->allows('delete', $taxonomyPoiType));
    }

    public function test_administrator_cannot_delete_taxonomy_poi_type_linked_to_a_layer(): void
    {
        $admin = $this->makeUser('Administrator');
        $taxonomyPoiType = $this->makeTaxonomyPoiType();
        $layer = Layer::factory()->create();

        DB::table('taxonomy_poi_typeables')->insert([
            'taxonomy_poi_type_id' => $taxonomyPoiType->id,
            'taxonomy_poi_typeable_id' => $layer->id,
            'taxonomy_poi_typeable_type' => Layer::class,
        ]);

        $this->assertFalse(Gate::forUser($admin)->allows('delete', $taxonomyPoiType));
    }

    public function test_delete_denial_message_is_translated(): void
    {
        $admin = $this->makeUser('Administrator');
        $taxonomyPoiType = $this->makeTaxonomyPoiType();
        $layer = Layer::factory()->create();

        DB::table('taxonomy_poi_typeables')->insert([
            'taxonomy_poi_type_id' => $taxonomyPoiType->id,
            'taxonomy_poi_typeable_id' => $layer->id,
            'taxonomy_poi_typeable_type' => Layer::class,
        ]);

        app()->setLocale('it');
        $response = Gate::forUser($admin)->inspect('delete', $taxonomyPoiType);

        $this->assertFalse($response->allowed());
        $this->assertSame('Questo tipo di POI è ancora in uso e non può essere eliminato.', $response->message());
    }

    public function test_validator_cannot_delete_taxonomy_poi_type(): void
    {
        $validator = $this->makeUser('Validator');
        $taxonomyPoiType = $this->makeTaxonomyPoiType();

        $this->assertFalse(Gate::forUser($validator)->allows('delete', $taxonomyPoiType));
    }
}

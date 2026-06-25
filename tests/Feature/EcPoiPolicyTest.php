<?php

namespace Tests\Feature;

use App\Models\User;
use App\Policies\EcPoiPolicy;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class EcPoiPolicyTest extends TestCase
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

    private function makeEcPoi(?int $userId = null): EcPoi
    {
        $attrs = ['properties' => []];
        if ($userId) {
            $attrs['user_id'] = $userId;
        }

        return \App\Models\EcPoi::factory()->create($attrs);
    }

    private function makeUser(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    // --- Policy attiva è quella locale, non del package ---

    public function test_local_ecpoi_policy_is_registered(): void
    {
        $policy = Gate::getPolicyFor(EcPoi::class);
        $this->assertInstanceOf(EcPoiPolicy::class, $policy);
    }

    // --- Administrator ---

    public function test_administrator_can_view_any_ec_poi(): void
    {
        $admin = $this->makeUser('Administrator');
        $this->assertTrue(Gate::forUser($admin)->allows('viewAny', EcPoi::class));
    }

    public function test_administrator_can_view_ec_poi(): void
    {
        $admin = $this->makeUser('Administrator');
        $ecPoi = $this->makeEcPoi();
        $this->assertTrue(Gate::forUser($admin)->allows('view', $ecPoi));
    }

    public function test_administrator_can_create_ec_poi(): void
    {
        $admin = $this->makeUser('Administrator');
        $this->assertTrue(Gate::forUser($admin)->allows('create', EcPoi::class));
    }

    public function test_administrator_can_update_ec_poi(): void
    {
        $admin = $this->makeUser('Administrator');
        $ecPoi = $this->makeEcPoi();
        $this->assertTrue(Gate::forUser($admin)->allows('update', $ecPoi));
    }

    public function test_administrator_can_delete_ec_poi(): void
    {
        $admin = $this->makeUser('Administrator');
        $ecPoi = $this->makeEcPoi();
        $this->assertTrue(Gate::forUser($admin)->allows('delete', $ecPoi));
    }

    // --- Validator: sola lettura ---

    public function test_validator_can_view_any_ec_poi(): void
    {
        $validator = $this->makeUser('Validator');
        $this->assertTrue(Gate::forUser($validator)->allows('viewAny', EcPoi::class));
    }

    public function test_validator_can_view_ec_poi(): void
    {
        $validator = $this->makeUser('Validator');
        $ecPoi = $this->makeEcPoi();
        $this->assertTrue(Gate::forUser($validator)->allows('view', $ecPoi));
    }

    public function test_validator_cannot_create_ec_poi(): void
    {
        $validator = $this->makeUser('Validator');
        $this->assertFalse(Gate::forUser($validator)->allows('create', EcPoi::class));
    }

    public function test_validator_cannot_update_ec_poi(): void
    {
        $validator = $this->makeUser('Validator');
        $ecPoi = $this->makeEcPoi($validator->id);
        $this->assertFalse(Gate::forUser($validator)->allows('update', $ecPoi));
    }

    public function test_validator_cannot_delete_ec_poi(): void
    {
        $validator = $this->makeUser('Validator');
        $ecPoi = $this->makeEcPoi($validator->id);
        $this->assertFalse(Gate::forUser($validator)->allows('delete', $ecPoi));
    }

    // --- Guest: nessun accesso Nova ---

    public function test_guest_cannot_view_any_ec_poi(): void
    {
        $guest = $this->makeUser('Guest');
        $this->assertFalse(Gate::forUser($guest)->allows('viewAny', EcPoi::class));
    }

    public function test_guest_cannot_view_ec_poi(): void
    {
        $guest = $this->makeUser('Guest');
        $ecPoi = $this->makeEcPoi();
        $this->assertFalse(Gate::forUser($guest)->allows('view', $ecPoi));
    }
}

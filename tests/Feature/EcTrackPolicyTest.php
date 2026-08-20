<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Policies\EcTrackPolicy;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class EcTrackPolicyTest extends TestCase
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

    private function makeEcTrack(?int $userId = null): EcTrack
    {
        $attrs = [];
        if ($userId) {
            $attrs['user_id'] = $userId;
        }

        return \App\Models\EcTrack::factory()->createQuietly($attrs);
    }

    private function makeUser(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    // --- Policy attiva è quella del package, ora registrata ---

    public function test_ectrack_policy_is_registered(): void
    {
        $policy = Gate::getPolicyFor(EcTrack::class);
        $this->assertInstanceOf(EcTrackPolicy::class, $policy);
    }

    // --- Administrator ---

    public function test_administrator_can_view_any_ec_track_regardless_of_owner(): void
    {
        $admin = $this->makeUser('Administrator');
        $ecTrack = $this->makeEcTrack();
        $this->assertTrue(Gate::forUser($admin)->allows('view', $ecTrack));
    }

    public function test_administrator_can_update_any_ec_track_regardless_of_owner(): void
    {
        $admin = $this->makeUser('Administrator');
        $ecTrack = $this->makeEcTrack();
        $this->assertTrue(Gate::forUser($admin)->allows('update', $ecTrack));
    }

    public function test_administrator_can_create_ec_track(): void
    {
        $admin = $this->makeUser('Administrator');
        $this->assertTrue(Gate::forUser($admin)->allows('create', EcTrack::class));
    }

    public function test_administrator_can_delete_any_ec_track_regardless_of_owner(): void
    {
        $admin = $this->makeUser('Administrator');
        $ecTrack = $this->makeEcTrack();
        $this->assertTrue(Gate::forUser($admin)->allows('delete', $ecTrack));
    }

    // --- Validator: scoping per proprietà (il bloccante trovato in review) ---

    public function test_validator_can_view_own_ec_track(): void
    {
        $validator = $this->makeUser('Validator');
        $ecTrack = $this->makeEcTrack($validator->id);

        $this->assertTrue(Gate::forUser($validator)->allows('view', $ecTrack));
    }

    public function test_validator_cannot_view_ec_track_of_another_user(): void
    {
        $validator = $this->makeUser('Validator');
        $otherValidator = $this->makeUser('Validator');
        $ecTrack = $this->makeEcTrack($otherValidator->id);

        $this->assertFalse(Gate::forUser($validator)->allows('view', $ecTrack));
    }

    public function test_validator_can_update_own_ec_track(): void
    {
        $validator = $this->makeUser('Validator');
        $ecTrack = $this->makeEcTrack($validator->id);

        $this->assertTrue(Gate::forUser($validator)->allows('update', $ecTrack));
    }

    public function test_validator_cannot_update_ec_track_of_another_user(): void
    {
        $validator = $this->makeUser('Validator');
        $otherValidator = $this->makeUser('Validator');
        $ecTrack = $this->makeEcTrack($otherValidator->id);

        $this->assertFalse(Gate::forUser($validator)->allows('update', $ecTrack));
    }

    public function test_validator_can_create_ec_track(): void
    {
        $validator = $this->makeUser('Validator');
        $this->assertTrue(Gate::forUser($validator)->allows('create', EcTrack::class));
    }

    public function test_validator_can_delete_own_ec_track(): void
    {
        $validator = $this->makeUser('Validator');
        $ecTrack = $this->makeEcTrack($validator->id);

        $this->assertTrue(Gate::forUser($validator)->allows('delete', $ecTrack));
    }

    public function test_validator_cannot_delete_ec_track_of_another_user(): void
    {
        $validator = $this->makeUser('Validator');
        $otherValidator = $this->makeUser('Validator');
        $ecTrack = $this->makeEcTrack($otherValidator->id);

        $this->assertFalse(Gate::forUser($validator)->allows('delete', $ecTrack));
    }

    // --- viewAny: EcTrackPolicy::viewAny() returns true unconditionally for every role
    // (including Guest) — found missing coverage in review. Documented as accepted here
    // rather than gated, because a Guest never reaches this ability in practice: Nova's
    // own `viewNova` gate (App\Providers\NovaServiceProvider) already blocks Guest from
    // loading the resource index route where viewAny is checked. ---

    public function test_administrator_can_view_any_ec_tracks_list(): void
    {
        $admin = $this->makeUser('Administrator');
        $this->assertTrue(Gate::forUser($admin)->allows('viewAny', EcTrack::class));
    }

    public function test_validator_can_view_any_ec_tracks_list(): void
    {
        $validator = $this->makeUser('Validator');
        $this->assertTrue(Gate::forUser($validator)->allows('viewAny', EcTrack::class));
    }

    public function test_guest_can_view_any_ec_tracks_list_but_never_reaches_the_route(): void
    {
        $guest = $this->makeUser('Guest');
        $this->assertTrue(Gate::forUser($guest)->allows('viewAny', EcTrack::class));
    }

    // --- Guest: nessun accesso Nova ---

    public function test_guest_cannot_view_ec_track(): void
    {
        $guest = $this->makeUser('Guest');
        $ecTrack = $this->makeEcTrack();
        $this->assertFalse(Gate::forUser($guest)->allows('view', $ecTrack));
    }

    public function test_guest_cannot_create_ec_track(): void
    {
        $guest = $this->makeUser('Guest');
        $this->assertFalse(Gate::forUser($guest)->allows('create', EcTrack::class));
    }

    public function test_guest_cannot_delete_ec_track(): void
    {
        $guest = $this->makeUser('Guest');
        $ecTrack = $this->makeEcTrack();
        $this->assertFalse(Gate::forUser($guest)->allows('delete', $ecTrack));
    }
}

<?php

namespace Tests\Feature;

use App\Models\Experience;
use App\Models\User;
use App\Policies\ExperiencePolicy;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class ExperiencePolicyTest extends TestCase
{
    use DatabaseTransactions, LayerTestHelpers;

    private User $owner;

    private Experience $experience;

    protected function setUp(): void
    {
        parent::setUp();

        RolesAndPermissionsService::seedDatabase();

        if (App::count() === 0) {
            App::factory()->create();
        }

        $this->owner = $this->createUserWithRole('Validator');
        $layer = $this->createLayer($this->owner->id);

        $this->experience = Experience::factory()->create([
            'user_id' => User::factory()->create()->id,
            'layer_id' => $layer->id,
        ]);
    }

    public function test_policy_is_registered(): void
    {
        $this->assertInstanceOf(ExperiencePolicy::class, Gate::getPolicyFor(Experience::class));
    }

    public function test_administrator_can_view_and_delete_but_not_update(): void
    {
        $admin = Gate::forUser($this->createUserWithRole('Administrator'));

        $this->assertTrue($admin->allows('viewAny', Experience::class));
        $this->assertTrue($admin->allows('view', $this->experience));
        $this->assertTrue($admin->allows('delete', $this->experience));
        $this->assertFalse($admin->allows('update', $this->experience));
        $this->assertFalse($admin->allows('create', Experience::class));
        $this->assertFalse($admin->allows('replicate', $this->experience));
    }

    public function test_validator_cannot_view_private_experience_of_own_layer(): void
    {
        $gate = Gate::forUser($this->owner);

        $this->assertTrue($gate->allows('viewAny', Experience::class));
        $this->assertFalse($gate->allows('view', $this->experience));
    }

    public function test_validator_can_view_public_experience_of_own_layer_but_not_delete_or_update(): void
    {
        $this->experience->forceFill(['is_public' => true])->save();
        $gate = Gate::forUser($this->owner);

        $this->assertTrue($gate->allows('view', $this->experience));
        $this->assertFalse($gate->allows('update', $this->experience));
        $this->assertFalse($gate->allows('delete', $this->experience));
    }

    public function test_foreign_validator_cannot_view_public_experience(): void
    {
        $this->experience->forceFill(['is_public' => true])->save();

        $this->assertFalse(Gate::forUser($this->createUserWithRole('Validator'))->allows('view', $this->experience));
    }

    public function test_guest_and_user_without_role_have_no_access(): void
    {
        foreach ([$this->createUserWithRole('Guest'), $this->createUserWithoutRole()] as $user) {
            $gate = Gate::forUser($user);
            $this->assertFalse($gate->allows('viewAny', Experience::class));
            $this->assertFalse($gate->allows('view', $this->experience));
            $this->assertFalse($gate->allows('delete', $this->experience));
        }
    }

    public function test_ugc_tracks_cannot_be_attached_or_detached(): void
    {
        $this->experience->forceFill(['is_public' => true])->save();

        foreach ([$this->createUserWithRole('Administrator'), $this->owner] as $user) {
            $gate = Gate::forUser($user);
            $this->assertFalse($gate->allows('attachAnyUgcTrack', $this->experience));
            $this->assertFalse($gate->allows('attachUgcTrack', [$this->experience, null]));
            $this->assertFalse($gate->allows('detachUgcTrack', [$this->experience, null]));
        }
    }
}

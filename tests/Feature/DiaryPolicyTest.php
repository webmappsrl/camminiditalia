<?php

namespace Tests\Feature;

use App\Models\Diary;
use App\Models\User;
use App\Policies\DiaryPolicy;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class DiaryPolicyTest extends TestCase
{
    use DatabaseTransactions, LayerTestHelpers;

    private User $owner;

    private Diary $diary;

    protected function setUp(): void
    {
        parent::setUp();

        RolesAndPermissionsService::seedDatabase();

        if (App::count() === 0) {
            App::factory()->create();
        }

        $this->owner = $this->createUserWithRole('Validator');
        $layer = $this->createLayer($this->owner->id);

        $this->diary = Diary::factory()->create([
            'user_id' => User::factory()->create()->id,
            'layer_id' => $layer->id,
        ]);
    }

    public function test_policy_is_registered(): void
    {
        $this->assertInstanceOf(DiaryPolicy::class, Gate::getPolicyFor(Diary::class));
    }

    public function test_administrator_can_view_and_delete_but_not_update(): void
    {
        $admin = Gate::forUser($this->createUserWithRole('Administrator'));

        $this->assertTrue($admin->allows('viewAny', Diary::class));
        $this->assertTrue($admin->allows('view', $this->diary));
        $this->assertTrue($admin->allows('delete', $this->diary));
        $this->assertFalse($admin->allows('update', $this->diary));
        $this->assertFalse($admin->allows('create', Diary::class));
        $this->assertFalse($admin->allows('replicate', $this->diary));
    }

    public function test_validator_non_vede_la_risorsa_dei_diari(): void
    {
        $gate = Gate::forUser($this->owner);

        $this->assertFalse($gate->allows('viewAny', Diary::class));
        $this->assertFalse($gate->allows('view', $this->diary));
    }

    public function test_validator_non_vede_nemmeno_i_diari_pubblici_del_proprio_cammino(): void
    {
        $this->diary->forceFill(['is_public' => true, 'publication_consent' => true])->save();
        $gate = Gate::forUser($this->owner);

        $this->assertFalse($gate->allows('view', $this->diary));
        $this->assertFalse($gate->allows('update', $this->diary));
        $this->assertFalse($gate->allows('delete', $this->diary));
    }

    public function test_foreign_validator_cannot_view_public_diary(): void
    {
        $this->diary->forceFill(['is_public' => true])->save();

        $this->assertFalse(Gate::forUser($this->createUserWithRole('Validator'))->allows('view', $this->diary));
    }

    public function test_guest_and_user_without_role_have_no_access(): void
    {
        foreach ([$this->createUserWithRole('Guest'), $this->createUserWithoutRole()] as $user) {
            $gate = Gate::forUser($user);
            $this->assertFalse($gate->allows('viewAny', Diary::class));
            $this->assertFalse($gate->allows('view', $this->diary));
            $this->assertFalse($gate->allows('delete', $this->diary));
        }
    }

    public function test_ugc_tracks_cannot_be_attached_or_detached(): void
    {
        $this->diary->forceFill(['is_public' => true])->save();

        foreach ([$this->createUserWithRole('Administrator'), $this->owner] as $user) {
            $gate = Gate::forUser($user);
            $this->assertFalse($gate->allows('attachAnyUgcTrack', $this->diary));
            $this->assertFalse($gate->allows('attachUgcTrack', [$this->diary, null]));
            $this->assertFalse($gate->allows('detachUgcTrack', [$this->diary, null]));
        }
    }
}

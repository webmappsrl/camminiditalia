<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class LayerPolicyTest extends TestCase
{
    use DatabaseTransactions, LayerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed roles and permissions
        RolesAndPermissionsService::seedDatabase();

        // Create an App for layers (required by LayerFactory)
        if (App::count() === 0) {
            App::factory()->create();
        }
    }

    // =========================================================================
    // Administrator Tests
    // =========================================================================

    public function test_administrator_can_view_any_layer(): void
    {
        $administrator = $this->createUserWithRole('Administrator');
        $layer = $this->createLayer();

        $this->assertTrue(Gate::forUser($administrator)->allows('view', $layer));
    }

    public function test_administrator_can_view_any_layers_list(): void
    {
        $administrator = $this->createUserWithRole('Administrator');

        $this->assertTrue(Gate::forUser($administrator)->allows('viewAny', Layer::class));
    }

    public function test_administrator_can_create_layers(): void
    {
        $administrator = $this->createUserWithRole('Administrator');

        $this->assertTrue(Gate::forUser($administrator)->allows('create', Layer::class));
    }

    public function test_administrator_can_update_any_layer(): void
    {
        $administrator = $this->createUserWithRole('Administrator');
        $otherUser = User::factory()->create();
        $layer = $this->createLayer($otherUser->id);

        $this->assertTrue(Gate::forUser($administrator)->allows('update', $layer));
    }

    public function test_administrator_can_delete_any_layer(): void
    {
        $administrator = $this->createUserWithRole('Administrator');
        $otherUser = User::factory()->create();
        $layer = $this->createLayer($otherUser->id);

        $this->assertTrue(Gate::forUser($administrator)->allows('delete', $layer));
    }

    public function test_administrator_can_update_own_layer(): void
    {
        $administrator = $this->createUserWithRole('Administrator');
        $layer = $this->createLayer($administrator->id);

        $this->assertTrue(Gate::forUser($administrator)->allows('update', $layer));
    }

    public function test_administrator_can_delete_own_layer(): void
    {
        $administrator = $this->createUserWithRole('Administrator');
        $layer = $this->createLayer($administrator->id);

        $this->assertTrue(Gate::forUser($administrator)->allows('delete', $layer));
    }

    // =========================================================================
    // Validator Tests
    // =========================================================================

    public function test_validator_can_view_any_layer(): void
    {
        $validator = $this->createUserWithRole('Validator');
        $layer = $this->createLayer();

        $this->assertTrue(Gate::forUser($validator)->allows('view', $layer));
    }

    public function test_validator_can_view_any_layers_list(): void
    {
        $validator = $this->createUserWithRole('Validator');

        $this->assertTrue(Gate::forUser($validator)->allows('viewAny', Layer::class));
    }

    public function test_validator_cannot_create_layers(): void
    {
        $validator = $this->createUserWithRole('Validator');

        $this->assertFalse(Gate::forUser($validator)->allows('create', Layer::class));
    }

    public function test_validator_cannot_update_own_layer(): void
    {
        $validator = $this->createUserWithRole('Validator');
        $layer = $this->createLayer($validator->id);

        $this->assertFalse(Gate::forUser($validator)->allows('update', $layer));
    }

    public function test_validator_cannot_update_other_users_layer(): void
    {
        $validator = $this->createUserWithRole('Validator');
        $otherUser = User::factory()->create();
        $layer = $this->createLayer($otherUser->id);

        $this->assertFalse(Gate::forUser($validator)->allows('update', $layer));
    }

    public function test_validator_cannot_delete_own_layer(): void
    {
        $validator = $this->createUserWithRole('Validator');
        $layer = $this->createLayer($validator->id);

        $this->assertFalse(Gate::forUser($validator)->allows('delete', $layer));
    }

    public function test_validator_cannot_delete_other_users_layer(): void
    {
        $validator = $this->createUserWithRole('Validator');
        $otherUser = User::factory()->create();
        $layer = $this->createLayer($otherUser->id);

        $this->assertFalse(Gate::forUser($validator)->allows('delete', $layer));
    }

    // =========================================================================
    // User Without Role Tests
    // =========================================================================

    public function test_user_without_role_can_view_any_layer(): void
    {
        $user = $this->createUserWithoutRole();
        $layer = $this->createLayer();

        $this->assertTrue(Gate::forUser($user)->allows('view', $layer));
    }

    public function test_user_without_role_can_view_any_layers_list(): void
    {
        $user = $this->createUserWithoutRole();

        $this->assertTrue(Gate::forUser($user)->allows('viewAny', Layer::class));
    }

    public function test_user_without_role_cannot_create_layers(): void
    {
        $user = $this->createUserWithoutRole();

        $this->assertFalse(Gate::forUser($user)->allows('create', Layer::class));
    }

    public function test_user_without_role_can_update_own_layer(): void
    {
        $user = $this->createUserWithoutRole();
        $layer = $this->createLayer($user->id);

        $this->assertTrue(Gate::forUser($user)->allows('update', $layer));
    }

    public function test_user_without_role_cannot_update_other_users_layer(): void
    {
        $user = $this->createUserWithoutRole();
        $otherUser = User::factory()->create();
        $layer = $this->createLayer($otherUser->id);

        $this->assertFalse(Gate::forUser($user)->allows('update', $layer));
    }

    public function test_user_without_role_can_delete_own_layer(): void
    {
        $user = $this->createUserWithoutRole();
        $layer = $this->createLayer($user->id);

        $this->assertTrue(Gate::forUser($user)->allows('delete', $layer));
    }

    public function test_user_without_role_cannot_delete_other_users_layer(): void
    {
        $user = $this->createUserWithoutRole();
        $otherUser = User::factory()->create();
        $layer = $this->createLayer($otherUser->id);

        $this->assertFalse(Gate::forUser($user)->allows('delete', $layer));
    }
}

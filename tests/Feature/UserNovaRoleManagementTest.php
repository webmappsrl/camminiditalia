<?php

namespace Tests\Feature;

use App\Models\User;
use App\Nova\User as UserResource;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Laravel\Nova\Http\Requests\NovaRequest;
use Tests\TestCase;
use Vyuldashev\NovaPermission\RoleBooleanGroup;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class UserNovaRoleManagementTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        RolesAndPermissionsService::seedDatabase();
        config(['wm-package.super_admin_emails' => ['superadmin@test.com']]);
    }

    private function makeRoleField(NovaRequest $request, User $contextUser): RoleBooleanGroup
    {
        Auth::login($contextUser);
        $resource = new UserResource($contextUser);
        $fields = $resource->fields($request);

        $field = collect($fields)->first(fn ($field) => $field instanceof RoleBooleanGroup);

        $this->assertInstanceOf(RoleBooleanGroup::class, $field);

        return $field;
    }

    // --- readonly() ---

    public function test_non_super_admin_administrator_can_edit_the_roles_field(): void
    {
        $admin = User::factory()->create(['email' => 'admin@test.com']);
        $admin->assignRole('Administrator');

        $request = NovaRequest::create('/', 'POST', []);
        $request->setUserResolver(fn () => $admin);

        $field = $this->makeRoleField($request, $admin);

        $this->assertFalse($field->isReadonly($request));
    }

    public function test_non_administrator_non_super_admin_cannot_edit_the_roles_field(): void
    {
        $guest = User::factory()->create(['email' => 'guest@test.com']);
        $guest->assignRole('Guest');

        $request = NovaRequest::create('/', 'POST', []);
        $request->setUserResolver(fn () => $guest);

        $field = $this->makeRoleField($request, $guest);

        $this->assertTrue($field->isReadonly($request));
    }

    // --- options() ---

    public function test_non_super_admin_administrator_only_sees_validator_and_guest_as_options(): void
    {
        $admin = User::factory()->create(['email' => 'admin@test.com']);
        $admin->assignRole('Administrator');

        $request = NovaRequest::create('/', 'POST', []);
        $request->setUserResolver(fn () => $admin);

        $field = $this->makeRoleField($request, $admin);

        $optionNames = collect($field->jsonSerialize()['options'])->pluck('name')->sort()->values()->all();

        $this->assertSame(['Guest', 'Validator'], $optionNames);
    }

    public function test_super_admin_still_sees_all_roles_as_options(): void
    {
        $superAdmin = User::factory()->create(['email' => 'superadmin@test.com']);
        $superAdmin->assignRole('Administrator');

        $request = NovaRequest::create('/', 'POST', []);
        $request->setUserResolver(fn () => $superAdmin);

        $field = $this->makeRoleField($request, $superAdmin);

        $optionNames = collect($field->jsonSerialize()['options'])->pluck('name')->all();

        $this->assertContains('Administrator', $optionNames);
    }

    // --- fillUsing() ---

    public function test_non_super_admin_administrator_can_assign_validator(): void
    {
        $admin = User::factory()->create(['email' => 'admin@test.com']);
        $admin->assignRole('Administrator');

        $targetUser = User::factory()->create();

        $request = NovaRequest::create('/', 'POST', [
            'roles' => json_encode(['Validator' => true]),
        ]);
        $request->setUserResolver(fn () => $admin);

        $field = $this->makeRoleField($request, $admin);
        $field->fill($request, $targetUser);
        $targetUser->refresh();

        $this->assertTrue($targetUser->hasRole('Validator'));
    }

    public function test_non_super_admin_administrator_cannot_assign_administrator_even_if_payload_includes_it(): void
    {
        $admin = User::factory()->create(['email' => 'admin@test.com']);
        $admin->assignRole('Administrator');

        $targetUser = User::factory()->create();

        $request = NovaRequest::create('/', 'POST', [
            'roles' => json_encode(['Administrator' => true, 'Validator' => true]),
        ]);
        $request->setUserResolver(fn () => $admin);

        $field = $this->makeRoleField($request, $admin);
        $field->fill($request, $targetUser);
        $targetUser->refresh();

        $this->assertFalse($targetUser->hasRole('Administrator'));
        $this->assertTrue($targetUser->hasRole('Validator'));
    }

    public function test_non_super_admin_administrator_cannot_remove_administrator_from_another_user(): void
    {
        $admin = User::factory()->create(['email' => 'admin@test.com']);
        $admin->assignRole('Administrator');

        $otherAdmin = User::factory()->create();
        $otherAdmin->assignRole('Administrator');

        $request = NovaRequest::create('/', 'POST', [
            'roles' => json_encode(['Validator' => true]),
        ]);
        $request->setUserResolver(fn () => $admin);

        $field = $this->makeRoleField($request, $admin);
        $field->fill($request, $otherAdmin);
        $otherAdmin->refresh();

        $this->assertTrue($otherAdmin->hasRole('Administrator'));
        $this->assertTrue($otherAdmin->hasRole('Validator'));
    }

    public function test_non_super_admin_administrator_cannot_remove_its_own_administrator_role(): void
    {
        $admin = User::factory()->create(['email' => 'admin@test.com']);
        $admin->assignRole('Administrator');

        $request = NovaRequest::create('/', 'POST', [
            'roles' => json_encode(['Validator' => true]),
        ]);
        $request->setUserResolver(fn () => $admin);

        $field = $this->makeRoleField($request, $admin);
        $field->fill($request, $admin);
        $admin->refresh();

        $this->assertTrue($admin->hasRole('Administrator'));
        $this->assertTrue($admin->hasRole('Validator'));
    }

    public function test_non_super_admin_administrator_preserves_a_role_outside_the_allowlist(): void
    {
        $admin = User::factory()->create(['email' => 'admin@test.com']);
        $admin->assignRole('Administrator');

        $targetUser = User::factory()->create();
        $targetUser->assignRole('Editor');

        $request = NovaRequest::create('/', 'POST', [
            'roles' => json_encode(['Validator' => true]),
        ]);
        $request->setUserResolver(fn () => $admin);

        $field = $this->makeRoleField($request, $admin);
        $field->fill($request, $targetUser);
        $targetUser->refresh();

        $this->assertTrue($targetUser->hasRole('Editor'));
        $this->assertTrue($targetUser->hasRole('Validator'));
    }

    public function test_non_super_admin_administrator_removes_validator_when_unchecked_without_touching_other_roles(): void
    {
        $admin = User::factory()->create(['email' => 'admin@test.com']);
        $admin->assignRole('Administrator');

        $targetUser = User::factory()->create();
        $targetUser->assignRole('Validator');
        $targetUser->assignRole('Editor');

        $request = NovaRequest::create('/', 'POST', [
            'roles' => json_encode(['Validator' => false, 'Guest' => true]),
        ]);
        $request->setUserResolver(fn () => $admin);

        $field = $this->makeRoleField($request, $admin);
        $field->fill($request, $targetUser);
        $targetUser->refresh();

        $this->assertFalse($targetUser->hasRole('Validator'));
        $this->assertTrue($targetUser->hasRole('Guest'));
        $this->assertTrue($targetUser->hasRole('Editor'));
    }

    public function test_super_admin_behavior_is_unchanged(): void
    {
        $superAdmin = User::factory()->create(['email' => 'superadmin@test.com']);
        $superAdmin->assignRole('Administrator');

        $targetUser = User::factory()->create();
        $targetUser->assignRole('Validator');

        $request = NovaRequest::create('/', 'POST', [
            'roles' => json_encode(['Administrator' => true, 'Validator' => false]),
        ]);
        $request->setUserResolver(fn () => $superAdmin);

        $field = $this->makeRoleField($request, $superAdmin);
        $field->fill($request, $targetUser);
        $targetUser->refresh();

        $this->assertTrue($targetUser->hasRole('Administrator'));
        $this->assertFalse($targetUser->hasRole('Validator'));
    }
}

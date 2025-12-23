<?php

namespace Tests\Feature\Helpers;

use App\Models\User;
use Wm\WmPackage\Models\Layer;

trait LayerTestHelpers
{
    /**
     * Create a user with the specified role.
     *
     * @param  string  $role  The role name ('Administrator', 'Validator', 'Guest')
     */
    protected function createUserWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    /**
     * Create a user without any role.
     */
    protected function createUserWithoutRole(): User
    {
        return User::factory()->create();
    }

    /**
     * Create a layer with optional user ID.
     *
     * @param  int|null  $userId  The user ID to associate with the layer
     */
    protected function createLayer(?int $userId = null): Layer
    {
        return Layer::factory()->create($userId ? ['user_id' => $userId] : []);
    }
}

<?php

namespace Tests\Feature\Concerns;

use App\Models\User;

/**
 * Un utente con un ruolo Spatie. I ruoli vanno creati prima, con
 * `RolesAndPermissionsService::seedDatabase()`.
 */
trait CreatesUsersWithRole
{
    protected function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }
}

<?php

namespace Tests\Feature\Helpers;

use App\Models\User;
use Wm\WmPackage\Models\Layer;

trait LayerTestHelpers
{
    protected function createUserWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    protected function createLayer(?int $userId = null): Layer
    {
        return Layer::factory()->create($userId ? ['user_id' => $userId] : []);
    }

    protected function createUserWithoutRole(): User
    {
        return User::factory()->create();
    }
}

<?php

use App\Models\User;
use Wm\WmPackage\Models\Layer;

if (! function_exists('createUserWithRole')) {
    function createUserWithRole(string $role): User
    {
        $user = User::factory()->create();
        if ($role !== 'regular') {
            $user->assignRole($role);
        }

        return $user;
    }
}

if (! function_exists('createLayer')) {
    function createLayer(?int $userId = null): Layer
    {
        return Layer::factory()->create($userId ? ['user_id' => $userId] : []);
    }
}

<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Wm\WmPackage\Models\EcPoi;

class EcPoiPolicy
{
    use HandlesAuthorization;

    public function before(User $user, string $ability): ?bool
    {
        if ($user->hasRole('Administrator')) {
            return true;
        }

        if (! $user->hasRole('Validator')) {
            return false;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, EcPoi $ecPoi): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->layers()->exists();
    }

    public function update(User $user, EcPoi $ecPoi): bool
    {
        return $ecPoi->user_id === $user->id;
    }

    public function delete(User $user, EcPoi $ecPoi): bool
    {
        return false;
    }
}

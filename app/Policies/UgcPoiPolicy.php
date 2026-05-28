<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Wm\WmPackage\Models\UgcPoi;

class UgcPoiPolicy
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
        return $user->hasRole('Validator');
    }

    public function view(User $user, UgcPoi $ugcPoi): bool
    {
        return $user->hasRole('Validator');
    }
}

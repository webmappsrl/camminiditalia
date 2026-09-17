<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Wm\WmPackage\Models\UgcTrack;

class UgcTrackPolicy
{
    use HandlesAuthorization;

    public function before(User $user, string $ability): ?bool
    {
        if ($user->hasRole('Administrator')) {
            return true;
        }

        // Replica Wm\WmPackage\Policies\UgcTrackPolicy (auto-risolta da Nova/Gate
        // prima che questa classe fosse registrata esplicitamente): Validator
        // bypassa ogni ability. Qui l'eccezione è "update", l'unica ristretta da
        // questo ticket (oc:8575) — per ogni altra ability il comportamento resta
        // quello preesistente.
        if ($user->hasRole('Validator') && $ability !== 'update') {
            return true;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasRole('Editor') && $user->hasUgcEnabled();
    }

    public function view(User $user, UgcTrack $ugcTrack): bool
    {
        return $user->hasRole('Editor') && $user->hasUgcEnabled() && $user->ownsApp($ugcTrack->app_id);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, UgcTrack $ugcTrack): bool
    {
        return false;
    }

    public function delete(User $user, UgcTrack $ugcTrack): bool
    {
        return false;
    }

    public function restore(User $user, UgcTrack $ugcTrack): bool
    {
        return false;
    }

    public function forceDelete(User $user, UgcTrack $ugcTrack): bool
    {
        return false;
    }
}

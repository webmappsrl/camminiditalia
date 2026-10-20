<?php

namespace App\Policies;

use App\Models\Experience;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Esperienze dei camminatori (oc:8169). In Nova sono di sola lettura: nascono
 * e si modificano solo dall'API. Administrator vede tutto ed è l'unico a
 * poter eliminare; il Validator vede solo le esperienze pubbliche dei layer
 * di cui è proprietario (la regola vive in Experience::isVisibleTo()).
 */
class ExperiencePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['Administrator', 'Validator']);
    }

    public function view(User $user, Experience $experience): bool
    {
        return $experience->isVisibleTo($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Experience $experience): bool
    {
        return false;
    }

    public function delete(User $user, Experience $experience): bool
    {
        return $user->hasRole('Administrator');
    }

    public function replicate(User $user, Experience $experience): bool
    {
        return false;
    }

    public function restore(User $user, Experience $experience): bool
    {
        return false;
    }

    public function forceDelete(User $user, Experience $experience): bool
    {
        return false;
    }

    /**
     * Le registrazioni collegate sono di sola lettura in Nova: il legame lo
     * scrive solo l'API. Senza questi metodi Nova consentirebbe attach/detach
     * sulla pivot `experience_ugc_track` senza controllare `update`.
     */
    public function attachAnyUgcTrack(User $user, Experience $experience): bool
    {
        return false;
    }

    public function attachUgcTrack(User $user, Experience $experience, mixed $ugcTrack): bool
    {
        return false;
    }

    public function detachUgcTrack(User $user, Experience $experience, mixed $ugcTrack): bool
    {
        return false;
    }
}

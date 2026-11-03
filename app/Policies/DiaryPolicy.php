<?php

namespace App\Policies;

use App\Models\Diary;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Diari dei camminatori (oc:8169). In Nova sono di sola lettura: nascono e si
 * modificano solo dall'API. Li vede solo l'Administrator, che è anche l'unico a
 * poterli eliminare; nessun altro ruolo, Validator compreso, con o senza
 * consenso alla ripubblicazione (ciclo 2; la regola vive in Diary::isVisibleTo()).
 */
class DiaryPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->hasRole('Administrator');
    }

    public function view(User $user, Diary $diary): bool
    {
        return $diary->isVisibleTo($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Diary $diary): bool
    {
        return false;
    }

    public function delete(User $user, Diary $diary): bool
    {
        return $user->hasRole('Administrator');
    }

    public function replicate(User $user, Diary $diary): bool
    {
        return false;
    }

    public function restore(User $user, Diary $diary): bool
    {
        return false;
    }

    public function forceDelete(User $user, Diary $diary): bool
    {
        return false;
    }

    /**
     * Le registrazioni collegate sono di sola lettura in Nova: il legame lo
     * scrive solo l'API. Senza questi metodi Nova consentirebbe attach/detach
     * sulla pivot `diary_ugc_track` senza controllare `update`.
     */
    public function attachAnyUgcTrack(User $user, Diary $diary): bool
    {
        return false;
    }

    public function attachUgcTrack(User $user, Diary $diary, mixed $ugcTrack): bool
    {
        return false;
    }

    public function detachUgcTrack(User $user, Diary $diary, mixed $ugcTrack): bool
    {
        return false;
    }
}

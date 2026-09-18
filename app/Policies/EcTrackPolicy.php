<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Wm\WmPackage\Models\EcTrack;

/**
 * Override locale di Wm\WmPackage\Policies\EcTrackPolicy (oc:8181), necessario
 * da quando oc:8162 (wm-package) ha cambiato view()/update()/delete() da un
 * controllo per-traccia (user_id === ecTrack.user_id) a un controllo per-app
 * (ownsApp()), per supportare un ruolo "Editor" con scoping multi-app che
 * qui non esiste (CLAUDE.md: "Non usare ruoli del package come Editor — non
 * esistono in questo progetto"). Il criterio di camminiditalia resta quello
 * originale di oc:8181, verificato da EcTrackPolicyTest.php: il Validator
 * (gestore di cammino) vede/modifica/elimina solo le proprie EcTrack.
 */
class EcTrackPolicy
{
    use HandlesAuthorization;

    public function before(User $user, string $ability): ?bool
    {
        if ($user->hasRole('Administrator')) {
            return true;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, EcTrack $ecTrack): bool
    {
        return $user->id === $ecTrack->user_id;
    }

    public function create(User $user): bool
    {
        return ! $user->hasRole('Guest');
    }

    public function update(User $user, EcTrack $ecTrack): bool
    {
        return $user->id === $ecTrack->user_id;
    }

    public function delete(User $user, EcTrack $ecTrack): bool
    {
        return $user->id === $ecTrack->user_id;
    }

    public function restore(User $user, EcTrack $ecTrack): bool
    {
        return false;
    }

    public function forceDelete(User $user, EcTrack $ecTrack): bool
    {
        return false;
    }
}

<?php

namespace App\Policies;

use App\Models\CertificationRequest;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Richieste di validazione della credenziale cartacea (oc:8653).
 *
 * Administrator: vede tutto e può eliminare. Validator: sola lettura, solo le
 * richieste dei layer di cui è proprietario (layers.user_id). Nessuno può
 * creare o modificare da Nova: le richieste nascono solo dall'API.
 */
class CertificationRequestPolicy
{
    use HandlesAuthorization;

    /**
     * Abilità concesse d'ufficio all'Administrator. Non un before() "tutto
     * true": create/update/replicate/restore/forceDelete devono restare
     * vietate anche a lui. `delete` non è qui: dipende dallo stato (oc:8671).
     */
    private const ADMINISTRATOR_ABILITIES = ['viewAny', 'view'];

    public function before(User $user, string $ability): ?bool
    {
        if ($user->hasRole('Administrator') && in_array($ability, self::ADMINISTRATOR_ABILITIES, true)) {
            return true;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasRole('Validator');
    }

    public function view(User $user, CertificationRequest $request): bool
    {
        return $request->isVisibleTo($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, CertificationRequest $request): bool
    {
        return false;
    }

    /**
     * Solo l'Administrator, e solo finché la richiesta è pending: una
     * richiesta decisa è la prova delle tappe validate e non si cancella.
     */
    public function delete(User $user, CertificationRequest $request): bool
    {
        return $user->hasRole('Administrator') && $request->isPending();
    }

    public function replicate(User $user, CertificationRequest $request): bool
    {
        return false;
    }

    public function restore(User $user, CertificationRequest $request): bool
    {
        return false;
    }

    public function forceDelete(User $user, CertificationRequest $request): bool
    {
        return false;
    }
}

<?php

namespace App\Nova\Traits;

use Laravel\Nova\Http\Requests\NovaRequest;

trait FiltersUsersByRoleTrait
{
    /**
     * Limit relatable users to Administrators and Validators when current user is an Administrator.
     */
    public static function relatableUsers(NovaRequest $request, $query)
    {
        $currentUser = $request->user();

        if ($currentUser && $currentUser->hasRole('Administrator')) {
            return $query->role(['Administrator', 'Validator']);
        }

        return $query;
    }
}

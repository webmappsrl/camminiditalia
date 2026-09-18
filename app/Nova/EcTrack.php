<?php

namespace App\Nova;

use App\Nova\Traits\FiltersUsersByRoleTrait;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Nova\EcTrack as WmNovaEcTrack;

class EcTrack extends WmNovaEcTrack
{
    use FiltersUsersByRoleTrait;

    /**
     * The model the resource corresponds to.
     *
     * @var class-string<\App\Models\EcTrack>
     */
    public static $model = \App\Models\EcTrack::class;

    /**
     * Override locale di Wm\WmPackage\Nova\AbstractEcResource::indexQuery(), che
     * scopa per app_id posseduto (ownedAppIds()) — in camminiditalia c'è una sola
     * App, di proprietà dell'Administrator, quindi nessun Validator ne possiede
     * mai una e la lista risulterebbe sempre vuota (oc:8587). Qui si scopa per
     * user_id, coerente con App\Policies\EcTrackPolicy.
     */
    public static function indexQuery(NovaRequest $request, $query)
    {
        $user = $request->user();

        if ($user->hasRole('Administrator')) {
            return $query;
        }

        if ($user->hasRole('Validator')) {
            return $query->where('user_id', $user->id);
        }

        return $query->whereRaw('1=0');
    }

    /**
     * Get the fields displayed by the resource.
     */
    public function fields(NovaRequest $request): array
    {
        $fields = parent::fields($request);
        $currentUser = $request->user();

        // Remove App field for all users
        $fields = array_filter($fields, function ($field) {
            return ! ($field instanceof BelongsTo && $field->attribute === 'app');
        });

        // Modify User field to be visible only to admins
        $fields = array_map(function ($field) use ($currentUser) {
            if ($field instanceof BelongsTo && $field->attribute === 'user') {
                // Show User field only to admins
                $field->canSee(function () use ($currentUser) {
                    return $currentUser && $currentUser->hasRole('Administrator');
                });
            }

            return $field;
        }, $fields);

        return array_values($fields);
    }
}

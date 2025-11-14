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

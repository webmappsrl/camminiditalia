<?php

namespace App\Nova;

use App\Nova\Traits\FiltersUsersByRoleTrait;
use Illuminate\Support\Facades\Auth;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Nova\Actions\ExecuteEcTrackDataChainAction;
use Wm\WmPackage\Nova\Actions\RegenerateLayerPbfAction;
use Wm\WmPackage\Nova\Layer as WmNovaLayer;

class Layer extends WmNovaLayer
{
    use FiltersUsersByRoleTrait;

    /**
     * The model the resource corresponds to.
     *
     * @var class-string<\App\Models\Layer>
     */

    public static function indexQuery(NovaRequest $request, $query)
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();

        if ($user && ! $user->hasRole('Administrator')) {
            return $query->where('user_id', $user->id);
        }

        return $query;
    }

    public function fields(NovaRequest $request): array
    {
        $fields = parent::fields($request);
        $currentUser = $request->user();

        // Remove App field for all users
        $fields = array_filter($fields, function ($field) {
            return ! ($field instanceof BelongsTo && $field->attribute === 'appOwner');
        });

        // Modify layerOwner field to be visible only to admins
        $fields = array_map(function ($field) use ($currentUser) {
            if ($field instanceof BelongsTo && $field->attribute === 'layerOwner') {
                // Show layerOwner field only to admins
                $field->canSee(function () use ($currentUser) {
                    return $currentUser && $currentUser->hasRole('Administrator');
                });
            }

            return $field;
        }, $fields);

        return array_values($fields);
    }

    public function actions(NovaRequest $request): array
    {
        $actions = parent::actions($request);
        $currentUser = $request->user();

        // Filter actions to show only to administrators
        $actions = array_map(function ($action) use ($currentUser) {
            // Restrict RegenerateLayerPbfAction and ExecuteEcTrackDataChainAction to administrators only
            if ($action instanceof RegenerateLayerPbfAction || $action instanceof ExecuteEcTrackDataChainAction) {
                $action->canSee(function () use ($currentUser) {
                    return $currentUser && $currentUser->hasRole('Administrator');
                });
            }

            return $action;
        }, $actions);

        return $actions;
    }
}

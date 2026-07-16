<?php

namespace App\Nova;

use App\Models\User;
use App\Nova\Traits\FiltersUsersByRoleTrait;
use Illuminate\Support\Facades\Auth;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Nova\Actions\AddLayersToConfigHomeAction;
use Wm\WmPackage\Nova\Actions\ExecuteEcTrackDataChainAction;
use Wm\WmPackage\Nova\Actions\RegenerateLayerPbfAction;
use Wm\WmPackage\Nova\Cards\LayerAnalytics\LayerAnalyticsCard;
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
        /** @var User|null $user */
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
                $field->canSee(function () use ($currentUser) {
                    return $currentUser && $currentUser->hasRole('Administrator');
                });
                $field->help('⚠️ Modificando il gestore, tutte le tracce e i POI associati a questo layer verranno automaticamente trasferiti al nuovo gestore.');
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
            // Restrict RegenerateLayerPbfAction, ExecuteEcTrackDataChainAction and AddLayersToConfigHomeAction to administrators only
            if ($action instanceof RegenerateLayerPbfAction || $action instanceof ExecuteEcTrackDataChainAction || $action instanceof AddLayersToConfigHomeAction) {
                $action->canSee(function () use ($currentUser) {
                    return $currentUser && $currentUser->hasRole('Administrator');
                });
                $action->canRun(function ($request, $model) use ($currentUser) {
                    return $currentUser && $currentUser->hasRole('Administrator');
                });
            }

            return $action;
        }, $actions);

        return $actions;
    }

    public function cards(NovaRequest $request): array
    {
        $cards = parent::cards($request);

        // The per-layer cards (parent::cards()) are already restricted to the detail view
        // (they return [] when there is no resourceId), but we check explicitly here too:
        // the global analytics card must only ever appear on the index.
        if ($request->resourceId) {
            return $cards;
        }

        $currentUser = $request->user();
        if (! $currentUser || ! $currentUser->hasRole('Administrator')) {
            return $cards;
        }

        /** @var \Wm\WmPackage\Models\Layer|null $anyLayer */
        $anyLayer = static::newModel()->query()->first();
        if (! $anyLayer) {
            return $cards;
        }

        $app = $anyLayer->appOwner;
        $appProperties = $this->getLayerAppProperties($anyLayer);
        $analyticsEnabled = $app &&
            (($appProperties['analytics_app_enabled'] ?? false) ||
             ($appProperties['analytics_webapp_enabled'] ?? false));

        if ($analyticsEnabled) {
            $cards[] = LayerAnalyticsCard::global();
        }

        return $cards;
    }
}

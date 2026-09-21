<?php

namespace App\Nova;

use App\Nova\Traits\FiltersUsersByRoleTrait;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\BelongsToMany;
use Laravel\Nova\Fields\MorphToMany;
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

        $fields = array_map(function ($field) use ($currentUser) {
            // Show User field only to admins
            if ($field instanceof BelongsTo && $field->attribute === 'user') {
                $field->canSee(function () use ($currentUser) {
                    return $currentUser && $currentUser->hasRole('Administrator');
                });

                return $field;
            }

            // wm-package/src/Nova/EcTrack.php referenzia EcPoi::class senza `use`
            // esplicito: risolve alla classe Nova del package, priva
            // dell'override indexQuery() per user_id (oc:8587) — il campo di
            // attach risultava sempre vuoto per un Validator (oc:8611).
            if ($field instanceof BelongsToMany && $field->attribute === 'ecPois') {
                return BelongsToMany::make('EcPois', 'ecPois', EcPoi::class)
                    ->searchable()
                    ->collapsedByDefault();
            }

            // Il campo Layers (stesso bug del campo EcPois sopra) va nascosto
            // per il Validator invece che corretto: un gestore di cammino non
            // deve poter fare attach/detach diretto del layer da qui,
            // l'associazione passa sempre dal pannello Layer/LayerFeatureController.
            if ($field instanceof MorphToMany && $field->attribute === 'layers') {
                $field->canSee(function () use ($currentUser) {
                    return $currentUser && $currentUser->hasRole('Administrator');
                });
            }

            return $field;
        }, $fields);

        return array_values($fields);
    }
}

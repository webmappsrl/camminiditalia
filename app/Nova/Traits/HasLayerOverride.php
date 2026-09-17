<?php

namespace App\Nova\Traits;

use App\Models\User;
use App\Support\UgcLayerAssignment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Laravel\Nova\Fields\Hidden;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\Layer;

trait HasLayerOverride
{
    public function layerOverrideField(NovaRequest $request): ?Select
    {
        if (! $request->user()?->hasRole('Administrator')) {
            return null;
        }

        // Label e placeholder hardcoded in italiano, non tradotti via __():
        // il locale attivo in produzione non è sempre 'it' — stesso motivo
        // per cui il link readonly (layerLinkField, in HasLayerFilterAndLink)
        // usa "Cammino" fisso invece di __('Layer'). Stessa label "Cammino"
        // qui: i due field sono mutuamente esclusivi (uno nasconde in Update,
        // l'altro in Detail/Index), nessuna ambiguità visiva — richiesto dal
        // dev per non differenziare "Cammino"/"Assegna cammino".
        return Select::make('Cammino', 'layer_override')
            ->options(fn () => Layer::all()
                ->mapWithKeys(fn (Layer $layer) => [$layer->id => $layer->getStringName()])
                ->toArray()
            )
            ->searchable()
            ->nullable()
            ->placeholder('Non assegnato')
            ->resolveUsing(function ($value, $resource) {
                return $resource->properties['layer_id'] ?? null;
            })
            ->fillUsing(function (NovaRequest $request, $model, $attribute, $requestAttribute) {
                // fillUsing() bypassa il default $request->exists() di Nova
                // (vendor/laravel/nova/src/Fields/Field.php:395-402): senza
                // questo guard il fillCallback girerebbe su ogni Update,
                // anche senza toccare il campo.
                if (! $request->exists($requestAttribute)) {
                    return;
                }

                $layerId = self::normalizeLayerId($request->input($requestAttribute));

                // Confronto contro il valore mostrato in pagina al render
                // (campo companion layerOverrideOriginalField), non contro
                // il DB attuale: un job di risoluzione automatica potrebbe
                // aver scritto un nuovo layer nel frattempo, e un salvataggio
                // non correlato non deve sovrascriverlo (oc:8575, review formale).
                $original = self::normalizeLayerId($request->input('layer_override_original'));

                if ($original === $layerId) {
                    return;
                }

                static::applyManualLayerOverride($model, $layerId, $request->user());
            })
            ->onlyOnForms()
            ->hideWhenCreating()
            ->hideFromIndex()
            ->hideFromDetail();
    }

    public function layerOverrideOriginalField(NovaRequest $request): ?Hidden
    {
        if (! $request->user()?->hasRole('Administrator')) {
            return null;
        }

        // Companion nascosto di layerOverrideField(): riporta al server il
        // valore mostrato in pagina al render, base di confronto per capire
        // se l'admin ha davvero toccato il select — vedi il commento sopra.
        // fillUsing() è no-op: il valore va solo letto dall'altro campo.
        return Hidden::make('Cammino assegnato (originale)', 'layer_override_original')
            ->resolveUsing(function ($value, $resource) {
                return $resource->properties['layer_id'] ?? null;
            })
            ->fillUsing(function () {
                //
            })
            ->onlyOnForms()
            ->hideWhenCreating()
            ->hideFromIndex()
            ->hideFromDetail();
    }

    private static function normalizeLayerId(mixed $value): ?int
    {
        return ($value === null || $value === '') ? null : (int) $value;
    }

    public static function applyManualLayerOverride(Model $model, ?int $layerId, ?User $actor): void
    {
        $previousLayerId = $model->properties['layer_id'] ?? null;

        $model->setAttribute('properties', UgcLayerAssignment::apply($model->properties ?? [], $layerId, false));

        Log::info('Override manuale layer UGC', [
            'ugc_id' => $model->getAttribute('id'),
            'ugc_type' => get_class($model),
            'user_id' => $actor?->id,
            'user_name' => $actor?->name,
            'previous_layer_id' => $previousLayerId,
            'new_layer_id' => $layerId,
        ]);
    }
}

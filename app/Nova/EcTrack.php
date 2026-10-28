<?php

namespace App\Nova;

use App\Models\EcTrackVariant;
use App\Nova\Traits\FiltersUsersByRoleTrait;
use Illuminate\Support\Facades\DB;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\BelongsToMany;
use Laravel\Nova\Fields\MorphToMany;
use Laravel\Nova\Fields\Select;
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

        $fields[] = $this->variantField($currentUser);

        return array_values($fields);
    }

    /**
     * «Variante di» (oc:8165): dichiara che questa tappa è un'alternativa di un'altra tappa
     * dello stesso cammino. Le due si equivalgono — basta averne percorsa una — e nel totale
     * del cammino contano per una sola, altrimenti un cammino con rami alternativi non
     * raggiunge mai il 100%.
     *
     * Il dato vive in `ec_track_variants` e non in una colonna di `ec_tracks`, che è una tabella
     * di wm-package: il campo lo legge e lo scrive a mano.
     */
    private function variantField($currentUser): Select
    {
        return Select::make(__('Variant of'), 'variant_of_ec_track_id')
            ->options(fn () => $this->variantOptions($currentUser))
            ->searchable()
            ->nullable()
            ->displayUsingLabels()
            // In creazione non c'è ancora una tappa: le opzioni sarebbero vuote e le regole
            // non avrebbero su cosa ragionare, mentre il fill gira dopo il salvataggio.
            ->hideWhenCreating()
            ->resolveUsing(fn () => $this->savedMainId())
            // I controlli scattano solo se il valore cambia (oc:8862): un collegamento invariato
            // resta valido anche se nel frattempo la principale è uscita dal cammino, o se la
            // principale di un Validator l'ha scelta l'admin fra tappe non sue.
            ->rules('nullable', function ($attribute, $value, $fail) use ($currentUser) {
                $trackId = $this->variantResourceId();

                if ($trackId === null || (int) $value === $this->savedMainId()) {
                    return;
                }

                // Prima le regole del model: una principale diventata variante dopo l'apertura
                // del form esce anche dalle opzioni, e il motivo vero è questo.
                if ($key = EcTrackVariant::violation($trackId, (int) $value)) {
                    $fail(__($key));

                    return;
                }

                if (! array_key_exists((int) $value, $this->variantOptions($currentUser))) {
                    $fail(__('The chosen main stage is not among the stages you can choose.'));
                }
            })
            ->fillUsing(function (NovaRequest $request, $model, $attribute, $requestAttribute) {
                return function () use ($request, $model, $requestAttribute) {
                    $mainId = $request->input($requestAttribute);
                    $trackId = $model->getKey();

                    if (blank($mainId)) {
                        EcTrackVariant::whereKey($trackId)->delete();

                        return;
                    }

                    if ((int) $mainId === $this->savedMainId()) {
                        return;
                    }

                    EcTrackVariant::updateOrCreate(
                        ['ec_track_id' => $trackId],
                        ['main_ec_track_id' => (int) $mainId]
                    );
                };
            })
            ->help(__('The stage this one is an alternative of: walking either of the two counts as the same stage of the route.'))
            // Nascosto su una tappa che è già principale di altre varianti: le varianti vivono
            // su un solo livello, e lasciare il campo vuol dire far scegliere al gestore un
            // valore che il guard del model rifiuta al salvataggio, con un errore 500 al posto
            // di un messaggio.
            // Il Validator lo vede sulle sue tappe (oc:8862): sulle altre la policy non gli fa
            // aprire il form.
            ->canSee(fn () => $currentUser
                && $currentUser->hasAnyRole(['Administrator', 'Validator'])
                && ! $this->isVariantRoot());
    }

    /**
     * Le tappe che possono fare da principale: quelle dei cammini di questa tappa, tolte lei
     * stessa e quelle che sono già varianti di qualcun altro (un solo livello). Al Validator
     * solo le sue (oc:8862), più la principale già salvata anche se non è sua: senza, il form
     * la perderebbe e il collegamento creato dall'admin non si potrebbe conservare.
     *
     * @return array<int, string>
     */
    private function variantOptions($user): array
    {
        $id = $this->variantResourceId();

        if ($id === null) {
            return [];
        }

        $morphClass = (new (config('wm-package.ec_track_model')))->getMorphClass();

        $layerIds = DB::table('layerables')
            ->where('layerable_id', $id)
            ->where('layerable_type', $morphClass)
            ->pluck('layer_id');

        $options = DB::table('layerables')
            ->join('ec_tracks', 'ec_tracks.id', '=', 'layerables.layerable_id')
            ->whereIn('layerables.layer_id', $layerIds)
            ->where('layerables.layerable_type', $morphClass)
            ->where('ec_tracks.id', '!=', $id)
            ->whereNotIn('ec_tracks.id', DB::table('ec_track_variants')->select('ec_track_id'))
            ->when($user && ! $user->hasRole('Administrator'), fn ($query) => $query->where('ec_tracks.user_id', $user->id))
            ->distinct()
            ->pluck('ec_tracks.name', 'ec_tracks.id');

        $currentMainId = $this->savedMainId();

        if ($currentMainId !== null && ! $options->has($currentMainId)) {
            $options = $options->union(DB::table('ec_tracks')->where('id', $currentMainId)->pluck('name', 'id'));
        }

        return $options->map(fn ($name) => $this->plainName($name))->all();
    }

    /** La principale già salvata per questa tappa, o null se non è una variante. */
    private function savedMainId(): ?int
    {
        $id = $this->variantResourceId();
        $mainId = $id === null ? null : EcTrackVariant::whereKey($id)->value('main_ec_track_id');

        return $mainId === null ? null : (int) $mainId;
    }

    /** Vero se questa tappa è già la principale di altre varianti. */
    private function isVariantRoot(): bool
    {
        $id = $this->variantResourceId();

        return $id !== null && EcTrackVariant::where('main_ec_track_id', $id)->exists();
    }

    /** L'id della tappa che si sta guardando, o null su una tappa non ancora salvata. */
    private function variantResourceId(): ?int
    {
        return $this->resource->exists ? (int) $this->resource->getKey() : null;
    }

    /** Il nome della tappa nella lingua corrente, dal campo tradotto grezzo. */
    private function plainName(?string $raw): string
    {
        $decoded = json_decode((string) $raw, true);

        if (! is_array($decoded)) {
            return (string) $raw;
        }

        return (string) ($decoded[app()->getLocale()] ?? $decoded['it'] ?? reset($decoded) ?: '');
    }
}

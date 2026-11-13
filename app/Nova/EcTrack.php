<?php

namespace App\Nova;

use App\Models\EcTrackVariant;
use App\Nova\Traits\FiltersUsersByRoleTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\BelongsToMany;
use Laravel\Nova\Fields\MorphToMany;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\Layer as LayerModel;
use Wm\WmPackage\Nova\EcTrack as WmNovaEcTrack;

class EcTrack extends WmNovaEcTrack
{
    use FiltersUsersByRoleTrait;

    /**
     * Un Validator con un solo cammino: la tappa entra subito nel layer così «Variante di»
     * propone le tappe del cammino (oc:8976). Più di un layer o nessuno → nessun attach.
     *
     * Attach dalla relazione `layers()` (pivot Layerable), non da query builder: devono scattare
     * gli observer del pivot (proprietà `layers`, geometria del layer, PBF, attributi del cammino).
     * Il package non definisce un `afterCreate()`, quindi niente `parent::`.
     */
    public static function afterCreate(NovaRequest $request, Model $model): void
    {
        $user = $request->user();
        if (! $user?->hasRole('Validator') || $user->hasRole('Administrator')) {
            return;
        }

        $layerIds = LayerModel::query()->where('user_id', $user->id)->pluck('id');
        if ($layerIds->count() !== 1) {
            return;
        }

        /** @var \Wm\WmPackage\Models\EcTrack $model */
        $model->layers()->attach($layerIds->first());
    }

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
     * Nessuna tassonomia storica nella sezione Tassonomie della tappa.
     *
     * Il package 1.6.1 ripropone lì le tassonomie dichiarate in `wm-package.legacy_taxonomies`
     * — per noi le sole Attività — che però sono già gestite dalla loro relazione: il tab
     * aggiungeva un secondo posto da cui modificare la stessa cosa. Resta il tab Generale con
     * le Zone calcolate dalla geometria, che sono in sola lettura (oc:8951).
     *
     * @return array<int, array<string, mixed>>
     */
    protected function legacyTaxonomies(): array
    {
        return [];
    }

    /**
     * Layer proposti dal bottone «Copia codice web component» (oc:8944): l'Administrator li vede
     * tutti, ogni altro ruolo solo quelli di cui è owner, come nella index dei Layer
     * (`App\Nova\Layer::indexQuery()`). Parte da `parent::`, che filtra già per il flag dell'App.
     */
    #[\Override]
    protected function webComponentLayers(NovaRequest $request): Collection
    {
        $layers = parent::webComponentLayers($request);
        $user = $request->user();

        if ($user?->hasRole('Administrator')) {
            return $layers;
        }

        return $layers->where('user_id', $user?->id)->values();
    }

    /**
     * Get the fields displayed by the resource.
     */
    /**
     * La query che riempie il menu «Layers» dell'attach (oc:8974).
     *
     * Nova la cerca per convenzione: `relatable` più il nome del modello al plurale
     * (`AttachableRelation::attachableQueryMethod()`). Senza questo metodo il menu si porta
     * dietro i layer interi, con l'eager load dichiarato dalla Resource (`$with`: tracce, POI,
     * App, App associate): per disegnare un elenco di nomi significava leggere tutte le tracce
     * del progetto, geometrie comprese. Per fare l'associazione servono l'id e il nome; `user_id`
     * resta perché lo usano `indexQuery()` e l'autorizzazione.
     */
    public static function relatableLayers(NovaRequest $request, $query)
    {
        return $query
            ->without(['ecTracks', 'ecPois', 'appOwner', 'associatedApps'])
            ->select(['layers.id', 'layers.name', 'layers.user_id']);
    }

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

            // Il campo Layers ha lo stesso bug del campo EcPois sopra — in
            // wm-package punta a Layer::class del package, privo degli override
            // locali — ed è nascosto al Validator: un gestore di cammino non
            // deve poter fare attach/detach diretto del layer da qui: le sue
            // tappe nuove entrano nel suo cammino da sole, in afterCreate() (oc:8976).
            // Ripuntarlo alla Resource locale serve comunque, e non per i
            // permessi: la Resource del package aggiunge le Zone fra le
            // tassonomie storiche (wm-package Layer::legacyTaxonomies()), e Nova
            // costruisce i campi della Resource una volta per ogni voce
            // dell'elenco di attach. Con `taxonomy_wheres` piena quella pagina
            // impiegava due minuti e il menu non si apriva (oc:8974).
            if ($field instanceof MorphToMany && $field->attribute === 'layers') {
                return MorphToMany::make('Layers', 'layers', Layer::class)
                    ->collapsedByDefault()
                    ->canSee(function () use ($currentUser) {
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
                        // Delete Eloquent della singola riga (la PK è ec_track_id), non da query
                        // builder: solo così scatta EcTrackVariantObserver::deleted e il cammino
                        // viene ricalcolato (oc:8918).
                        EcTrackVariant::find($trackId)?->delete();

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
            // Stringa calcolata subito: Nova non esegue una closure passata a help(), la
            // serializza come `{}` e il testo sparisce (oc:8976).
            ->help($this->variantFieldHelp())
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

        $layerIds = $this->layerIdsOfTrack($id);

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

    /**
     * L'help del campo, con un avviso se la tappa non è in nessun cammino: senza layer le
     * opzioni sono vuote (oc:8976). Il Validator non può associarla da sé (Field `Layers` e
     * form del Layer gli sono preclusi), quindi l'avviso lo manda dall'amministratore.
     */
    private function variantFieldHelp(): string
    {
        $base = __('The stage this one is an alternative of: walking either of the two counts as the same stage of the route.');
        $id = $this->variantResourceId();

        if ($id !== null && $this->layerIdsOfTrack($id)->isEmpty()) {
            return $base.' '.__('This stage is not on any route yet: ask the administrator to add it to your route, then you can set «Variant of».');
        }

        return $base;
    }

    /**
     * I layer a cui la tappa è associata.
     *
     * @return Collection<int, int>
     */
    private function layerIdsOfTrack(int $id): Collection
    {
        return DB::table('layerables')
            ->where('layerable_id', $id)
            ->where('layerable_type', (new (config('wm-package.ec_track_model')))->getMorphClass())
            ->pluck('layer_id');
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

<?php

namespace App\Nova;

use App\Models\CertificationRequest as CertificationRequestModel;
use App\Nova\Actions\DecideCertificationRequest;
use App\Support\UserDisplay;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Laravel\Nova\Fields\Badge;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\HasMany;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Sezione Nova «Validazioni» del Passaporto del camminatore (oc:8653).
 *
 * Sola lettura per tutti (creazione e modifica solo via API), eliminazione
 * solo per l'Administrator (vedi CertificationRequestPolicy). Lo scoping delle
 * query replica la policy: il Validator vede solo le richieste dei layer di
 * cui è proprietario, così un record estraneo risponde 404 anche via /nova-api.
 */
class CertificationRequest extends Resource
{
    /**
     * @var class-string<CertificationRequestModel>
     */
    public static $model = CertificationRequestModel::class;

    public static $title = 'id';

    public static $search = ['id', 'serial_number'];

    /**
     * @var array<int, string>
     */
    public static $with = ['user', 'layer', 'media', 'decidedBy'];

    public static function uriKey(): string
    {
        return 'certification-requests';
    }

    public static function label(): string
    {
        return __('Validations');
    }

    public static function singularLabel(): string
    {
        return __('Validation');
    }

    public static function indexQuery(NovaRequest $request, Builder $query): Builder
    {
        return static::scopeForUser($request, $query);
    }

    public static function detailQuery(NovaRequest $request, Builder $query): Builder
    {
        return static::scopeForUser($request, parent::detailQuery($request, $query));
    }

    public static function editQuery(NovaRequest $request, Builder $query): Builder
    {
        return static::scopeForUser($request, parent::editQuery($request, $query));
    }

    public static function relatableQuery(NovaRequest $request, Builder $query): Builder
    {
        return static::scopeForUser($request, parent::relatableQuery($request, $query));
    }

    protected static function scopeForUser(NovaRequest $request, Builder $query): Builder
    {
        $user = $request->user();

        /** @var \Illuminate\Database\Eloquent\Builder<CertificationRequestModel> $query */
        return $user ? $query->visibleTo($user) : $query->whereRaw('1 = 0');
    }

    public static function authorizedToCreate(Request $request): bool
    {
        return false;
    }

    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),

            // Administrator: link alla risorsa User. Gli altri (Validator) vedono
            // il camminatore solo come testo: non devono poter aprire il profilo.
            BelongsTo::make(__('User'), 'user', User::class)
                ->readonly()
                ->canSee(fn (Request $request) => (bool) $request->user()?->hasRole('Administrator')),

            Text::make(__('User'), fn ($resource) => $this->userLabel($resource))
                ->exceptOnForms()
                ->canSee(fn (Request $request) => ! $request->user()?->hasRole('Administrator')),

            BelongsTo::make(__('Route'), 'layer', Layer::class)
                ->display(fn ($layer) => $layer->resource->getStringName())
                ->readonly(),

            Text::make(__('Serial number'), 'serial_number')
                ->readonly(),

            Badge::make(__('Status'), 'status')
                ->map([
                    CertificationRequestModel::STATUS_PENDING => 'warning',
                    CertificationRequestModel::STATUS_APPROVED => 'success',
                    CertificationRequestModel::STATUS_REJECTED => 'danger',
                ])
                ->labels([
                    CertificationRequestModel::STATUS_PENDING => __('Pending'),
                    CertificationRequestModel::STATUS_APPROVED => __('Approved'),
                    CertificationRequestModel::STATUS_REJECTED => __('Rejected'),
                ]),

            DateTime::make(__('Submitted at'), 'created_at')
                ->sortable()
                ->readonly(),

            Text::make(__('Photos'), fn ($resource) => $this->photosHtml($request, $resource))
                ->asHtml()
                ->onlyOnDetail(),

            BelongsTo::make(__('Decided by'), 'decidedBy', User::class)
                ->readonly()
                ->nullable()
                ->onlyOnDetail()
                ->canSee(fn (Request $request) => (bool) $request->user()?->hasRole('Administrator')),

            Text::make(__('Decided by'), fn ($resource) => UserDisplay::short($resource->decidedBy))
                ->onlyOnDetail()
                ->canSee(fn (Request $request) => ! $request->user()?->hasRole('Administrator')),

            DateTime::make(__('Decided at'), 'decided_at')
                ->readonly()
                ->onlyOnDetail(),

            Textarea::make(__('Decision note'), 'decision_note')
                ->readonly()
                ->alwaysShow()
                ->onlyOnDetail(),

            HasMany::make(__('Validated stages'), 'validatedTracks', ValidatedEcTrack::class),
        ];
    }

    protected function userLabel(CertificationRequestModel $resource): string
    {
        return UserDisplay::withEmail($resource->user);
    }

    /**
     * Griglia di miniature linkate alle foto. Gli URL vengono generati solo
     * se l'utente può vedere la richiesta: nessun URL deve mai uscire verso
     * chi non è autorizzato.
     */
    protected function photosHtml(NovaRequest $request, CertificationRequestModel $resource): string
    {
        if (! $request->user()?->can('view', $resource)) {
            return '';
        }

        $items = $resource->getMedia(CertificationRequestModel::MEDIA_COLLECTION)->map(function (Media $media) {
            $url = e($media->getUrl());
            $alt = e($media->file_name);

            return '<a href="'.$url.'" target="_blank" rel="noopener noreferrer">'
                .'<img src="'.$url.'" alt="'.$alt.'" style="width:100%;height:160px;object-fit:cover;border-radius:4px;">'
                .'</a>';
        })->implode('');

        return '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:8px;">'
            .$items
            .'</div>';
    }

    /**
     * La richiesta è nota quando Nova carica o esegue l'azione su una singola
     * risorsa (dettaglio, POST dell'azione): serve ai campi dell'azione (tappe)
     * e a mostrarla solo sulle richieste pending che l'utente può vedere. Senza canRun Nova
     * ricadrebbe sulla policy update(), che è false per tutti.
     */
    public function actions(NovaRequest $request): array
    {
        $target = $this->resource instanceof CertificationRequestModel && $this->resource->exists
            ? $this->resource
            : DecideCertificationRequest::targetFromRequest($request);

        return [
            (new DecideCertificationRequest($target))
                ->canSee(fn (Request $request) => $target !== null
                    ? (bool) $request->user()?->can('view', $target) && $target->isPending()
                    : (bool) $request->user()?->can('viewAny', CertificationRequestModel::class))
                ->canRun(fn (Request $request, CertificationRequestModel $model) => (bool) $request->user()?->can('view', $model) && $model->isPending()),
        ];
    }
}

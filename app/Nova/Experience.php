<?php

namespace App\Nova;

use App\Models\Experience as ExperienceModel;
use App\Support\EcTrackLabel;
use App\Support\UserDisplay;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\BelongsToMany;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Sezione Nova «Esperienze» (oc:8169). Sola lettura per tutti (creazione e
 * modifica solo via API), eliminazione solo per l'Administrator (vedi
 * ExperiencePolicy). Lo scoping delle query replica la policy tramite
 * Experience::scopeVisibleTo(), così un record estraneo risponde 404 anche
 * via /nova-api.
 */
class Experience extends Resource
{
    /**
     * @var class-string<ExperienceModel>
     */
    public static $model = ExperienceModel::class;

    public static $title = 'id';

    public static $search = ['id', 'description'];

    /**
     * @var array<int, string>
     */
    public static $with = ['user', 'layer', 'ecTrack', 'media'];

    public static function uriKey(): string
    {
        return 'experiences';
    }

    public static function label(): string
    {
        return __('Experiences');
    }

    public static function singularLabel(): string
    {
        return __('Experience');
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

        /** @var \Illuminate\Database\Eloquent\Builder<ExperienceModel> $query */
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
            // l'autore solo come testo: non devono poter aprire il profilo.
            BelongsTo::make(__('User'), 'user', User::class)
                ->readonly()
                ->canSee(fn (Request $request) => (bool) $request->user()?->hasRole('Administrator')),

            Text::make(__('User'), fn ($resource) => UserDisplay::withEmail($resource->user))
                ->exceptOnForms()
                ->canSee(fn (Request $request) => ! $request->user()?->hasRole('Administrator')),

            BelongsTo::make(__('Route'), 'layer', Layer::class)
                ->display(fn ($layer) => $layer->resource->getStringName())
                ->readonly(),

            Text::make(__('Stage'), fn ($resource) => $resource->ecTrack ? EcTrackLabel::for($resource->ecTrack) : null)
                ->exceptOnForms(),

            Textarea::make(__('Text'), 'description')
                ->readonly()
                ->alwaysShow(),

            Boolean::make(__('Public'), 'is_public')
                ->readonly(),

            DateTime::make(__('Date'), 'created_at')
                ->sortable()
                ->readonly(),

            Text::make(__('Photos'), fn ($resource) => $this->photosHtml($request, $resource))
                ->asHtml()
                ->onlyOnDetail(),

            BelongsToMany::make(__('Recordings'), 'ugcTracks', UgcTrack::class),
        ];
    }

    /**
     * Griglia di miniature linkate alle foto. Gli URL vengono generati solo
     * se l'utente può vedere l'esperienza: nessun URL deve uscire verso chi
     * non è autorizzato.
     */
    protected function photosHtml(NovaRequest $request, ExperienceModel $resource): string
    {
        if (! $request->user()?->can('view', $resource)) {
            return '';
        }

        $items = $resource->getMedia(ExperienceModel::MEDIA_COLLECTION)->map(function (Media $media) {
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
}

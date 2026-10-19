<?php

namespace App\Nova;

use App\Models\ValidatedEcTrack as ValidatedEcTrackModel;
use App\Nova\Filters\ValidatedEcTrackLayerFilter;
use App\Nova\Filters\ValidatedEcTrackSourceFilter;
use App\Nova\Filters\ValidatedEcTrackUserFilter;
use App\Nova\Lenses\ValidatedEcTrackSummary;
use App\Services\StageProgressService;
use App\Support\EcTrackLabel;
use App\Support\UserDisplay;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\LensRequest;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Nova;
use WeakMap;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;

/**
 * Tappe validate del passaporto (oc:8671, sezione di menu da oc:8676). Sola
 * lettura per tutti. Scoping per cammino (StageProgressService::scopeVisibleTo()):
 * il Validator vede le validazioni — manuali o GPS, con o senza richiesta —
 * delle tappe di sua gestione nei suoi layer (regola B, «tappe del
 * gestore»); vale per index, detail e per il
 * pannello HasMany del dettaglio richiesta, che passa da indexQuery().
 * Guest e utenti senza ruolo: 403 sull'intera risorsa.
 */
class ValidatedEcTrack extends Resource
{
    /**
     * @var class-string<ValidatedEcTrackModel>
     */
    public static $model = ValidatedEcTrackModel::class;

    public static $title = 'id';

    public static $search = ['id'];

    public static $globallySearchable = false;

    /**
     * @var array<int, string>
     */
    public static $with = ['ecTrack', 'user'];

    /**
     * Nomi dei cammini in cui ogni tappa è del gestore (regola B), calcolati
     * una volta per richiesta Nova (una query per pagina invece di una per
     * riga).
     *
     * @var WeakMap<Request, array<int, string>>|null
     */
    protected static ?WeakMap $routesCache = null;

    public static function uriKey(): string
    {
        return 'validated-ec-tracks';
    }

    public static function label(): string
    {
        return __('Validated stages');
    }

    public static function singularLabel(): string
    {
        return __('Validated stage');
    }

    public static function indexQuery(NovaRequest $request, Builder $query): Builder
    {
        return static::scopeForUser($request, $query);
    }

    public static function detailQuery(NovaRequest $request, Builder $query): Builder
    {
        return static::scopeForUser($request, parent::detailQuery($request, $query));
    }

    protected static function scopeForUser(NovaRequest $request, Builder $query): Builder
    {
        $user = $request->user();

        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        /** @var \Illuminate\Database\Eloquent\Builder<Model> $query */
        return app(StageProgressService::class)->scopeVisibleTo($query, $user);
    }

    public static function defaultOrderings(Builder $query): Builder
    {
        return $query->orderByDesc('validated_ec_tracks.validated_at')
            ->orderByDesc('validated_ec_tracks.id');
    }

    /**
     * Il canSee del menu «Passport» nasconde solo la voce: senza questo
     * controllo un Guest potrebbe comunque chiamare /nova-api/validated-ec-tracks.
     */
    public static function authorizedToViewAny(Request $request): bool
    {
        return (bool) $request->user()?->hasAnyRole(['Administrator', 'Validator']);
    }

    /**
     * Sulle righe della Lens (LensRequest) sempre false: il loro `id`
     * aggregato non è una validazione, quindi nessun link al dettaglio. Le
     * richieste di detail vere non sono LensRequest.
     */
    public function authorizedToView(Request $request): bool
    {
        return ! $request instanceof LensRequest && static::authorizedToViewAny($request);
    }

    /**
     * Nessuna policy registrata: senza questo override Nova lascerebbe
     * passare update/delete via API (authorizeToUpdate() non chiama
     * authorizedToUpdate()). Solo la lettura è ammessa.
     */
    public function authorizedTo(Request $request, string $ability): bool
    {
        return in_array($ability, ['view', 'viewAny'], true) && static::authorizedToViewAny($request);
    }

    public function authorizeTo(Request $request, string $ability): void
    {
        throw_unless($this->authorizedTo($request, $ability), AuthorizationException::class);
    }

    public static function authorizedToCreate(Request $request): bool
    {
        return false;
    }

    public function authorizedToUpdate(Request $request): bool
    {
        return false;
    }

    public function authorizedToDelete(Request $request): bool
    {
        return false;
    }

    public function authorizedToReplicate(Request $request): bool
    {
        return false;
    }

    public function fields(NovaRequest $request): array
    {
        /** @var ValidatedEcTrackModel $validated */
        $validated = $this->resource;

        return [
            static::userField($request, $validated),

            Text::make(__('Stage'), fn () => $validated->ecTrack instanceof EcTrack
                ? EcTrackLabel::for($validated->ecTrack)
                : '—'),

            Text::make(__('Routes'), fn () => static::routesFor($request)[$validated->ec_track_id] ?? '—'),

            DateTime::make(__('Validated at'), 'validated_at')
                ->readonly()
                ->sortable(),

            Text::make(__('Source'), fn () => match ($validated->source) {
                ValidatedEcTrackModel::SOURCE_MANUAL => __('Paper passport'),
                ValidatedEcTrackModel::SOURCE_GPS => __('GPS'),
                default => $validated->source,
            }),
        ];
    }

    public function filters(NovaRequest $request): array
    {
        return [
            new ValidatedEcTrackSourceFilter,
            new ValidatedEcTrackUserFilter,
            new ValidatedEcTrackLayerFilter,
        ];
    }

    /**
     * Colonna «User», condivisa con la Lens ValidatedEcTrackSummary:
     * Administrator link al detail Nova dell'utente, Validator solo il nome.
     * `$row` può essere uno stdClass quando Nova risolve i campi senza riga
     * (es. ordinamento della Lens): la closure non viene allora eseguita.
     */
    public static function userField(NovaRequest $request, object $row): Text
    {
        $isAdmin = (bool) $request->user()?->hasRole('Administrator');
        $user = fn () => $row instanceof ValidatedEcTrackModel ? $row->user : null;

        $userColumn = Text::make(__('User'), 'user_id', fn () => $isAdmin
            ? static::userLink($user())
            : UserDisplay::short($user()))
            ->sortable();

        return $isAdmin ? $userColumn->asHtml() : $userColumn;
    }

    /**
     * Riepilogo per camminatore e cammino (oc:8676).
     *
     * @return array<int, \Laravel\Nova\Lenses\Lens>
     */
    public function lenses(NovaRequest $request): array
    {
        return [
            new ValidatedEcTrackSummary,
        ];
    }

    /**
     * Link al detail Nova del camminatore (solo Administrator), come la
     * colonna layer di HasLayerFilterAndLink.
     */
    public static function userLink(?Model $user): string
    {
        $label = htmlspecialchars(UserDisplay::short($user), ENT_QUOTES, 'UTF-8');

        if ($user === null) {
            return $label;
        }

        $url = url(Nova::path().'/resources/'.User::uriKey().'/'.$user->getKey());

        return sprintf(
            '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
            e($url),
            $label
        );
    }

    /**
     * `ec_track_id` => nomi dei cammini in cui la tappa è del proprietario
     * effettivo (regola B, managedTracksQuery()), separati da virgola. Una query sulle tappe validate visibili al richiedente.
     *
     * @return array<int, string>
     */
    protected static function routesFor(NovaRequest $request): array
    {
        static::$routesCache ??= new WeakMap;

        if (isset(static::$routesCache[$request])) {
            return static::$routesCache[$request];
        }

        $service = app(StageProgressService::class);
        $user = $request->user();
        $visibleTracks = ValidatedEcTrackModel::query()->select('ec_track_id');
        $visibleTracks = $user !== null ? $service->scopeVisibleTo($visibleTracks, $user) : $visibleTracks->whereRaw('1 = 0');

        $rows = $service->managedTracksQuery()
            ->whereIn('ec_tracks.id', $visibleTracks)
            ->get();

        $names = Layer::whereIn('id', $rows->pluck('layer_id')->unique())
            ->get()
            ->mapWithKeys(fn (Layer $layer) => [$layer->id => $layer->getStringName()]);

        return static::$routesCache[$request] = $rows
            ->groupBy('ec_track_id')
            ->map(fn ($trackRows) => $trackRows
                ->map(fn ($row) => $names[$row->layer_id] ?? null)
                ->filter()
                ->sort(fn ($a, $b) => strnatcasecmp($a, $b))
                ->implode(', '))
            ->all();
    }
}

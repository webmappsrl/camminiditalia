<?php

namespace App\Nova;

use App\Models\CertificationRequest as CertificationRequestModel;
use App\Models\ValidatedEcTrack as ValidatedEcTrackModel;
use App\Support\EcTrackLabel;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\EcTrack;

/**
 * Tappe validate del passaporto (oc:8671). Sola lettura per tutti, non in
 * menu: si vede nel dettaglio della richiesta di certificazione. Scoping come
 * CertificationRequest: il Validator vede solo le righe delle richieste dei
 * suoi layer.
 */
class ValidatedEcTrack extends Resource
{
    /**
     * @var class-string<ValidatedEcTrackModel>
     */
    public static $model = ValidatedEcTrackModel::class;

    public static $title = 'id';

    public static $search = ['id'];

    public static $displayInNavigation = false;

    public static $globallySearchable = false;

    /**
     * @var array<int, string>
     */
    public static $with = ['ecTrack'];

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

        if ($user->hasRole('Administrator')) {
            return $query;
        }

        return $query->whereIn(
            'certification_request_id',
            CertificationRequestModel::query()->visibleTo($user)->select('id')
        );
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
            Text::make(__('Stage'), fn () => $validated->ecTrack instanceof EcTrack
                ? EcTrackLabel::for($validated->ecTrack)
                : '—'),

            DateTime::make(__('Validated at'), 'validated_at')
                ->readonly(),

            Text::make(__('Source'), fn () => match ($validated->source) {
                ValidatedEcTrackModel::SOURCE_MANUAL => __('Paper passport'),
                ValidatedEcTrackModel::SOURCE_GPS => __('GPS'),
                default => $validated->source,
            }),
        ];
    }
}

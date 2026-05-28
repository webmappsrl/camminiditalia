<?php

namespace App\Nova;

use App\Models\User;
use App\Nova\Actions\MarkAsRead;
use App\Nova\Actions\MarkAsUnread;
use App\Nova\Traits\HidesAppFromIndexTrait;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Nova\Fields\Badge;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Nova\UgcPoi as WmNovaUgcPoi;

class UgcPoi extends WmNovaUgcPoi
{
    use HidesAppFromIndexTrait;

    public static $model = \App\Models\UgcPoi::class;

    public static function label(): string
    {
        return __('Pois');
    }

    public static function indexQuery(NovaRequest $request, $query): Builder
    {
        $user = $request->user();

        if ($user->hasRole('Administrator')) {
            return $query;
        }

        if ($user->hasRole('Validator')) {
            return static::filteredQueryForValidator($user, $query);
        }

        return $query->whereRaw('1=0');
    }

    public static function filteredQueryForValidator(User $user, Builder $query): Builder
    {
        $layerIds = $user->layers()->pluck('id')->toArray();

        if (empty($layerIds)) {
            return $query->whereRaw('1=0');
        }

        $placeholders = implode(',', array_fill(0, count($layerIds), '?'));

        return $query
            ->whereRaw("properties->'form'->>'id' = ?", ['report'])
            ->whereRaw("(properties->>'layer_id')::integer IN ({$placeholders})", $layerIds);
    }

    public function fields(NovaRequest $request): array
    {
        $fields = parent::fields($request);

        array_unshift($fields, Badge::make(__('Status'), 'read_at')
            ->map([
                'Non letto' => 'danger',
                'Letto' => 'success',
            ])
            ->resolveUsing(function ($value) {
                return $value === null ? 'Non letto' : 'Letto';
            })
            ->onlyOnIndex()
        );

        return $fields;
    }

    public function actions(NovaRequest $request): array
    {
        return [
            ...parent::actions($request),
            new MarkAsRead,
            new MarkAsUnread,
        ];
    }
}

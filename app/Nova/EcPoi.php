<?php

namespace App\Nova;

use App\Models\EcPoi as EcPoiModel;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Nova\Actions\BulkEditAction;
use Wm\WmPackage\Nova\Actions\DownloadEcPoiAction;
use Wm\WmPackage\Nova\Actions\ExecuteEcPoiDataChainAction;
use Wm\WmPackage\Nova\Actions\TranslateModelAction;
use Wm\WmPackage\Nova\Actions\UploadPoiFile;
use Wm\WmPackage\Nova\EcPoi as WmNovaEcPoi;

class EcPoi extends WmNovaEcPoi
{
    public static $model = EcPoiModel::class;

    public static function label(): string
    {
        return __('Pois');
    }

    public static function authorizedToCreate(Request $request): bool
    {
        $user = $request->user();

        if (! $user) {
            return false;
        }

        return $user->hasRole('Administrator') || ($user->hasRole('Validator') && $user->layers()->exists());
    }

    public function authorizedToUpdate(Request $request): bool
    {
        $user = $request->user();

        if (! $user) {
            return false;
        }

        if ($user->hasRole('Administrator')) {
            return true;
        }

        /** @var EcPoiModel $ecPoi */
        $ecPoi = $this->resource;

        return $user->hasRole('Validator') && $ecPoi->user_id === $user->id;
    }

    public function authorizedToDelete(Request $request): bool
    {
        return $request->user()?->hasRole('Administrator') ?? false;
    }

    public function fields(NovaRequest $request): array
    {
        $fields = parent::fields($request);

        if ($layerField = $this->layerAssignmentField($request)) {
            $fields[] = $layerField;
        }

        return $fields;
    }

    private function layerAssignmentField(NovaRequest $request): ?Select
    {
        $user = $request->user();

        if (! $user || ! $user->hasRole('Validator')) {
            return null;
        }

        $layers = $user->layers()->get();

        if ($layers->isEmpty()) {
            return null;
        }

        $rules = $layers->count() > 1
            ? ['required', Rule::in($layers->pluck('id')->toArray())]
            : ['nullable', Rule::in($layers->pluck('id')->toArray())];

        return Select::make(__('Layer'), 'properties->layer_id')
            ->options($layers->mapWithKeys(fn (Layer $layer) => [$layer->id => $layer->getStringName()])->toArray())
            ->rules(...$rules)
            ->onlyOnForms()
            ->hideWhenUpdating();
    }

    public function actions(NovaRequest $request): array
    {
        $isAdmin = $request->user()?->hasRole('Administrator');

        return [
            (new ExecuteEcPoiDataChainAction)
                ->canSee(fn () => $isAdmin)
                ->canRun(fn ($req, $model) => $isAdmin),
            new DownloadEcPoiAction,
            (new UploadPoiFile)
                ->standalone()
                ->canSee(fn () => $isAdmin)
                ->canRun(fn ($req, $model) => $isAdmin),
            (new TranslateModelAction)
                ->canSee(fn () => $isAdmin)
                ->canRun(fn ($req, $model) => $isAdmin),
            (new BulkEditAction(self::class, ['global']))
                ->canSee(fn () => $isAdmin)
                ->canRun(fn ($req, $model) => $isAdmin),
        ];
    }
}

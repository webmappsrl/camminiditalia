<?php

namespace App\Nova;

use App\Models\EcPoi as EcPoiModel;
use Illuminate\Http\Request;
use Laravel\Nova\Http\Requests\NovaRequest;
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
        return $request->user()?->hasRole('Administrator') ?? false;
    }

    public function authorizedToUpdate(Request $request): bool
    {
        return $request->user()?->hasRole('Administrator') ?? false;
    }

    public function authorizedToDelete(Request $request): bool
    {
        return $request->user()?->hasRole('Administrator') ?? false;
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

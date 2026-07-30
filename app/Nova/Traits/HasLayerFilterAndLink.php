<?php

namespace App\Nova\Traits;

use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Nova;
use Wm\WmPackage\Models\Layer;

trait HasLayerFilterAndLink
{
    protected static array $layerNameCache = [];

    public static function renderLayerLink($rawLayerId): string
    {
        if (! is_numeric($rawLayerId)) {
            return 'Non assegnato';
        }

        $layerId = (int) $rawLayerId;

        if (! array_key_exists($layerId, static::$layerNameCache)) {
            $layer = Layer::find($layerId);
            static::$layerNameCache[$layerId] = $layer ? $layer->getStringName() : null;
        }

        $name = static::$layerNameCache[$layerId];

        if ($name === null) {
            return sprintf('Layer eliminato (ID: %d)', $layerId);
        }

        $url = url(Nova::path()."/resources/layers/{$layerId}");

        return sprintf(
            '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
            e($url),
            htmlspecialchars($name, ENT_QUOTES, 'UTF-8')
        );
    }

    public function layerLinkField(NovaRequest $request): ?Text
    {
        if (! $request->user()?->hasRole('Administrator')) {
            return null;
        }

        $resourceClass = static::class;

        return Text::make(__('Layer'), 'layer_link', function () use ($resourceClass) {
            return $resourceClass::renderLayerLink($this->properties['layer_id'] ?? null);
        })
            ->asHtml()
            ->hideWhenCreating()
            ->hideWhenUpdating();
    }

    public function layerFilterField(NovaRequest $request): ?Select
    {
        if (! $request->user()?->hasRole('Administrator')) {
            return null;
        }

        $modelClass = static::$model;

        return Select::make(__('Filtro Segnalazioni'), 'layer_filter')
            ->options(function () use ($modelClass) {
                $layerIds = $modelClass::query()
                    ->whereRaw("properties->>'layer_id' IS NOT NULL")
                    ->selectRaw("DISTINCT (properties->>'layer_id')::integer AS layer_id")
                    ->pluck('layer_id')
                    ->toArray();

                return Layer::whereIn('id', $layerIds)
                    ->get()
                    ->mapWithKeys(fn (Layer $layer) => [$layer->id => $layer->getStringName()])
                    ->toArray();
            })
            ->searchable()
            ->filterable(function (NovaRequest $request, $query, mixed $value, string $attribute) {
                $query->whereRaw("(properties->>'layer_id')::integer = ?", [(int) $value]);
            })
            ->hideFromIndex()
            ->hideFromDetail()
            ->hideWhenCreating()
            ->hideWhenUpdating();
    }
}

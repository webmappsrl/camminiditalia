<?php

namespace Wm\LayerFeatures;

use Illuminate\Support\Facades\Log;
use Laravel\Nova\Fields\Field;
use Wm\WmPackage\Models\Layer;

class LayerFeatures extends Field
{
    /**
     * The field's component.
     *
     * @var string
     */
    public $component = 'layer-features';
    public function __construct($name, $layer, string $modelClass, $attribute = null, $resolveCallback = null)
    {

        parent::__construct($name, $attribute, $resolveCallback);

        // Assicuriamoci che $layer sia un'istanza di Layer
        if (!$layer instanceof Layer) {
            Log::error("LayerFeatures: Il parametro passato non è un'istanza di Layer.");
            return;
        }
        if (!class_exists($modelClass)) {
            Log::error("LayerFeatures: Il modello specificato non esiste: " . $modelClass);
            return;
        }
        // Carica automaticamente le entità associate
        $this->loadEcFeatures($layer, $name, $modelClass);
    }

    public function loadEcFeatures($layer, $name, $modelClass)
    {
        $selectedFeatureIds = [];
        if ($layer->{$name}) {
            $selectedFeatureIds = $layer->{$name}->pluck('id')->toArray();
        }

        $model = new $modelClass;
        $modelName = $model->getLayerRelationName();

        $this->withMeta(['selectedEcFeaturesIds' => $selectedFeatureIds, 'model' => $modelClass, 'modelName' => $modelName, 'layerId' => $layer->id]);
    }
}

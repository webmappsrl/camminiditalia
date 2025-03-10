<?php

namespace Wm\LayerFeatures;

use Laravel\Nova\Fields\Field;
use Wm\WmPackage\Models\EcTrack;

class LayerFeatures extends Field
{
    /**
     * The field's component.
     *
     * @var string
     */
    public $component = 'layer-features';

    public function __construct($name, $layer, $attribute = null, $resolveCallback = null)
    {
        parent::__construct($name, $attribute, $resolveCallback);

        // Carica automaticamente le EcTracks associate all'app_id del Layer
        $this->loadEcTracks($layer->app_id);
    }

    /**
     * Retrieve and set the EcTracks associated with the given app_id.
     *
     * @param int $appId
     * @return $this
     */
    public function loadEcTracks($appId)
    {
        // Ottieni le tracce associate all'app_id specificato
        $ecTracks = EcTrack::where('app_id', $appId)->get()->toArray();

        $this->withMeta(['tracks' => $ecTracks]);
    }

    public function selectedEcTrackIds($layer)
    {
        $selectedTracks = $layer->ecTracks->pluck('id')->toArray();
        $this->withMeta(['selectedTracks' => $selectedTracks]);
    }
}

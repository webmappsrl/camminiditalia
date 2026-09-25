<?php

// Override locale del config di wm-package (oc:8463): il pacchetto fa
// mergeConfigFrom() sul proprio config/wm-package.php, quindi un valore
// qui vince su quello di default del pacchetto per la stessa chiave —
// le altre chiavi restano quelle definite dal pacchetto, non toccate.
//
// Preferito a una env var: il valore vive in un file versionato (questo
// repo), non in un .env di produzione che non è tracciato da nessun test.
return [
    /*
     | Chiavi di properties->attributes (Layer) che wm-package deve
     | escludere da config.json e dall'endpoint layer() — vedi
     | Wm\WmPackage\Support (src/helpers.php: withoutInternalConfigKeys()).
     | shape_discontinuous: flag interno letto solo da App\Nova\Layer per
     | l'alert di discontinuità del cammino, mai da esporre pubblicamente.
     | shape_manual: override manuale della tipologia (oc:8646), deve restare
     | uguale a App\Services\LayerAttributesService::SHAPE_MANUAL_KEY (lo
     | verifica LayerConfigJsonShapeManualTest). Al frontend arriva solo
     | `shape`, già risolto con l'override.
     */
    'internal_attribute_keys' => ['shape_discontinuous', 'shape_manual'],

    /*
     | Mostra "Uso dei filtri sui cammini" nella card Analytics globale di Nova (oc:8585).
     | Opt-in nel pacchetto (default false): l'evento PostHog che alimenta questa sezione
     | (filterUsed/route) è emesso solo dal pannello "filtro avanzato" della search bar
     | camminiditalia — qui, l'unico consumer che lo ha davvero, va a true.
     */
    'route_filter_analytics_enabled' => true,
];

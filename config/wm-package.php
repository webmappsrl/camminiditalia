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
     */
    'internal_attribute_keys' => ['shape_discontinuous'],
];

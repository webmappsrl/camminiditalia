<?php

return [
    /*
     * ID dell'utente a cui vengono riassegnate le risorse quando un layer perde il proprio owner.
     * Configurabile via CAMMINIDITALIA_DEFAULT_OWNER_ID in .env.
     */
    'default_owner_id' => (int) env('CAMMINIDITALIA_DEFAULT_OWNER_ID', 2),

    /*
     * Indirizzo di ripiego per le notifiche di richiesta di certificazione (oc:8653)
     * quando il layer non ha un owner a cui inviarle.
     */
    'fallback_notification_email' => env('CAMMINIDITALIA_FALLBACK_NOTIFICATION_EMAIL', 'info@camminiditalia.org'),

    /*
     * Passaporto camminatore (oc:8165).
     *
     * `coverage_threshold` è la quota di segmenti di una tappa che bastano a dirla percorsa.
     * Gli altri due parametri del calcolo — lunghezza del segmento (400 m) e distanza massima
     * dal percorso (50 m) — stanno in wm-package, perché li usa la corrispondenza fra UGC track
     * e tappe (oc:8773).
     *
     * I valori sono configurabili perché cambiano da shard a shard, non perché questo cliente
     * li cambierà: ha accettato i consigliati, e una volta concordati restano fermi.
     */
    'passport' => [
        'coverage_threshold' => (int) env('PASSPORT_COVERAGE_THRESHOLD', 80),
    ],
];

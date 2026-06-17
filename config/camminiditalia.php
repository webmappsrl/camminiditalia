<?php

return [
    /*
     * ID dell'utente a cui vengono riassegnate le risorse quando un layer perde il proprio owner.
     * Configurabile via CAMMINIDITALIA_DEFAULT_OWNER_ID in .env.
     */
    'default_owner_id' => (int) env('CAMMINIDITALIA_DEFAULT_OWNER_ID', 2),
];

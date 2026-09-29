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
];

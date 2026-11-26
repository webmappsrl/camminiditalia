<?php

use Laravel\Nova\Actions\ActionResource;
use Laravel\Nova\Http\Middleware\Authenticate;
use Laravel\Nova\Http\Middleware\AuthenticateSession;
use Laravel\Nova\Http\Middleware\Authorize;
use Laravel\Nova\Http\Middleware\BootTools;
use Laravel\Nova\Http\Middleware\DispatchServingNovaEvent;
// use Laravel\Nova\Http\Middleware\EnsureEmailIsVerified;
use Laravel\Nova\Http\Middleware\HandleInertiaRequests;

return [

    /*
    |--------------------------------------------------------------------------
    | Nova License Key
    |--------------------------------------------------------------------------
    |
    | The following configuration option contains your Nova license key. On
    | non-local domains, Nova will verify that the Nova installation has
    | a valid license associated with the application's active domain.
    |
    */

    'license_key' => env('NOVA_LICENSE_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Nova App Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application. This value is used when the
    | framework needs to display the name of the application within the UI
    | or in other locations. Of course, you're free to change the value.
    |
    */

    'name' => env('NOVA_APP_NAME', env('APP_NAME')),

    /*
    |--------------------------------------------------------------------------
    | Nova Domain Name
    |--------------------------------------------------------------------------
    |
    | This value is the "domain name" associated with your application. This
    | can be used to prevent Nova's internal routes from being registered
    | on subdomains which do not need access to your admin application.
    |
    */

    'domain' => env('NOVA_DOMAIN_NAME', null),

    /*
    |--------------------------------------------------------------------------
    | Nova Path
    |--------------------------------------------------------------------------
    |
    | This is the URI path where Nova will be accessible from. Feel free to
    | change this path to anything you like. Note that this URI will not
    | affect Nova's internal API routes which aren't exposed to users.
    |
    */

    'path' => '/nova',

    /*
    |--------------------------------------------------------------------------
    | Nova Authentication Guard
    |--------------------------------------------------------------------------
    |
    | This configuration option defines the authentication guard that will
    | be used to protect your Nova routes. This option should match one
    | of the authentication guards defined in the "auth" config file.
    |
    */

    'guard' => env('NOVA_GUARD', null),

    /*
    |--------------------------------------------------------------------------
    | Nova Password Reset Broker
    |--------------------------------------------------------------------------
    |
    | This configuration option defines the password broker that will be
    | used when passwords are reset. This option should mirror one of
    | the password reset options defined in the "auth" config file.
    |
    */

    'passwords' => env('NOVA_PASSWORDS', null),

    /*
    |--------------------------------------------------------------------------
    | Nova Route Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware will be assigned to every Nova route, giving you the
    | chance to add your own middleware to this stack or override any of
    | the existing middleware. Or, you can just stick with this stack.
    |
    */

    'middleware' => [
        'web',
        HandleInertiaRequests::class,
        DispatchServingNovaEvent::class,
        BootTools::class,
    ],

    'api_middleware' => [
        'nova',
        Authenticate::class,
        // AuthenticateSession::class,
        // EnsureEmailIsVerified::class,
        Authorize::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Nova Pagination Type
    |--------------------------------------------------------------------------
    |
    | This option defines the visual style used in Nova's resource pagination
    | views. You may select between "simple", "load-more", and "links" for
    | your applications. Feel free to adjust this option to your choice.
    |
    */

    'pagination' => 'simple',

    /*
    |--------------------------------------------------------------------------
    | Nova Storage Disk
    |--------------------------------------------------------------------------
    |
    | This configuration option allows you to define the default disk that
    | will be used to store files using the Image, File, and other file
    | related field types. You're welcome to use any configured disk.
    |
     */

    'storage_disk' => env('NOVA_STORAGE_DISK', 'public'),

    /*
    |--------------------------------------------------------------------------
    | Nova Currency
    |--------------------------------------------------------------------------
    |
    | This configuration option allows you to define the default currency
    | used by the Currency field within Nova. You may change this to a
    | valid ISO 4217 currency code to suit your application's needs.
    |
    */

    'currency' => 'USD',

    /*
    |--------------------------------------------------------------------------
    | Branding
    |--------------------------------------------------------------------------
    |
    | These configuration values allow you to customize the branding of the
    | Nova interface, including the primary color and the logo that will
    | be displayed within the Nova interface. This logo value must be
    | the absolute path to an SVG logo within the local filesystem.
    |
    */
    'brand' => [
        // Logo ufficiale del cliente (disco + scritta): il PNG di
        // docs/features/9040-personalizzazione-laravel/logo-cliente.png incorporato nell'SVG
        // come data URI (oc:9040). Non rigenerarlo con build-logo.py di oc:9031: rimetterebbe
        // il logo di prova.
        'logo' => resource_path('img/camminiditalia-logo.svg'),

        // Scala del `primario` del design system di Cammini d'Italia (oc:9040). 100, 300, 400,
        // 500, 600 e 700 sono del design system; 50, 200, 800, 900 e 950 li abbiamo ricavati noi
        // fra i valori vicini. Il bianco sui pulsanti #F07821 sta a 2,6:1: scelta consapevole,
        // come sul sito.
        'colors' => [
            '50' => '254, 246, 236',  // #FEF6EC, ricavato
            '100' => '253, 237, 215', // #FDEDD7 primario-100
            '200' => '250, 212, 169', // #FAD4A9, ricavato
            '300' => '247, 187, 122', // #F7BB7A primario-300
            '400' => '243, 149, 68',  // #F39544 primario-400
            '500' => '240, 120, 33',  // #F07821 primario
            '600' => '225, 93, 21',   // #E15D15 primario-600
            '700' => '187, 70, 19',   // #BB4613 primario-700
            '800' => '148, 56, 15',   // #94380F, ricavato
            '900' => '120, 48, 15',   // #78300F, ricavato
            '950' => '65, 22, 6',     // #411606, ricavato
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Nova Action Resource Class
    |--------------------------------------------------------------------------
    |
    | This configuration option allows you to specify a custom resource class
    | to use for action log entries instead of the default that ships with
    | Nova, thus allowing for the addition of additional UI form fields.
    |
    */

    'actions' => [
        'resource' => ActionResource::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Nova Impersonation Redirection URLs
    |--------------------------------------------------------------------------
    |
    | This configuration option allows you to specify a URL where Nova should
    | redirect an administrator after impersonating another user and a URL
    | to redirect the administrator after stopping impersonating a user.
    |
    */

    'impersonation' => [
        'started' => '/',
        'stopped' => '/',
    ],

    /*
    |--------------------------------------------------------------------------
    | Nova Admin Credentials
    |--------------------------------------------------------------------------
    |
    | This configuration option allows you to specify the admin credentials for
    | Nova. These credentials will be used to create the admin user if it does
    | not already exist in the database.
    |
    */

    'admin_password' => env('ADMIN_NOVA_PASSWORD'),
    'admin_email' => env('ADMIN_NOVA_EMAIL'),

];

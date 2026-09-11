<?php

use App\Models\User;

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | This option defines the default authentication "guard" for your
    | application. Requests from the embedded app are authenticated by the
    | "shopify" guard using the ID token that App Bridge sends with them.
    |
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'shopify'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | The "shopify" guard verifies the ID token Shopify issues for the staff
    | member using the app and resolves their user and shop from it. There
    | is no login form or session: every request proves who is making it.
    |
    | Supported: "shopify"
    |
    */

    'guards' => [
        'shopify' => [
            'driver' => 'shopify',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    |
    | The Shopify guard resolves users itself, but many Laravel features and
    | packages still expect a user provider that describes how users are
    | retrieved out of your database. This one points at the User model.
    |
    | Supported: "database", "eloquent"
    |
    */

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', User::class),
        ],
    ],

];

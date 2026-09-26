<?php

use App\Jobs\Webhooks\AppScopesUpdate;
use App\Jobs\Webhooks\AppUninstalled;
use App\Jobs\Webhooks\CustomersDataRequest;
use App\Jobs\Webhooks\CustomersRedact;
use App\Jobs\Webhooks\ShopRedact;

return [

    /*
    |--------------------------------------------------------------------------
    | Client Credentials
    |--------------------------------------------------------------------------
    |
    | These credentials identify your app to Shopify. You will find them in
    | the Partner Dashboard, and the Shopify CLI exports them under these
    | same environment variable names when it serves the app locally.
    |
    */

    'client_id' => env('SHOPIFY_API_KEY'),

    'client_secret' => env('SHOPIFY_API_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Admin API Version
    |--------------------------------------------------------------------------
    |
    | Shopify releases a new stable version of the Admin API every quarter
    | and supports each one for a year. Every request made through a shop's
    | API client targets this version, so review it when a new one ships.
    |
    */

    'api_version' => env('SHOPIFY_API_VERSION', '2026-07'),

    /*
    |--------------------------------------------------------------------------
    | App Identity
    |--------------------------------------------------------------------------
    |
    | The handle names the app in admin URLs, such as the plan selection page
    | Shopify hosts, and matches the one in "shopify.app.toml". The numeric
    | ID is shown in the Partner Dashboard; subscriptions are keyed on it.
    |
    */

    'app_handle' => env('SHOPIFY_APP_HANDLE'),

    'app_id' => env('SHOPIFY_APP_ID'),

    /*
    |--------------------------------------------------------------------------
    | Partner API
    |--------------------------------------------------------------------------
    |
    | Plans are sold through Shopify App Pricing, and a shop's subscription is
    | read back through the Partner API rather than the Admin API. Create an
    | API client with the "Manage apps" permission in the Partner Dashboard;
    | the organization ID is the number in the dashboard's URL.
    |
    */

    'partner' => [
        'organization_id' => env('SHOPIFY_PARTNER_ORGANIZATION_ID'),
        'access_token' => env('SHOPIFY_PARTNER_ACCESS_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    |
    | Every webhook Shopify delivers to the "/webhooks" endpoint is verified
    | and handed to the job registered here for its topic. Subscriptions are
    | declared in "shopify.app.toml" and deployed with the Shopify CLI. The
    | compliance topics below are mandatory for every app, so keep them.
    |
    */

    'webhooks' => [
        'app/uninstalled' => AppUninstalled::class,
        'app/scopes_update' => AppScopesUpdate::class,
        'customers/data_request' => CustomersDataRequest::class,
        'customers/redact' => CustomersRedact::class,
        'shop/redact' => ShopRedact::class,
    ],

];

<?php

return [
    'title' => 'Home',
    'welcome' => 'Welcome to :app',
    'signed_in' => 'You are signed in as <strong>:user</strong> on <strong>:shop</strong>.',
    'loading_store' => 'Loading store details',
    'features' => 'What\'s included',
    'features_description' => 'Everything an embedded Shopify app needs, ready to build on.',
    'feature' => [
        'authentication' => [
            'heading' => 'Authentication',
            'body' => 'App Bridge ID tokens are verified by a Laravel guard that installs the shop through token exchange.',
            'location' => 'auth middleware',
        ],
        'api' => [
            'heading' => 'Admin API',
            'body' => 'Query the GraphQL Admin API as the shop or the staff member, with expiring tokens refreshed for you.',
            'location' => '$request->shop()->api()->graphql()',
        ],
        'webhooks' => [
            'heading' => 'Webhooks',
            'body' => 'Signed webhooks are verified and dispatched to queued jobs, compliance topics included.',
            'location' => 'shopify.app.toml · config/shopify.php',
        ],
        'billing' => [
            'heading' => 'Billing',
            'body' => 'Plans from Shopify App Pricing, with middleware to gate paid features and trials handled.',
            'location' => 'subscribed middleware',
        ],
        'translations' => [
            'heading' => 'Translations',
            'body' => 'One set of language files, read with __() on the server and t() in React.',
            'location' => 'lang/',
        ],
        'polaris' => [
            'heading' => 'Polaris',
            'body' => 'Pages built from Polaris web components and App Bridge feel native in the Shopify admin.',
            'location' => 'resources/js/pages',
        ],
    ],
];

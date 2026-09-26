<?php

use App\Shopify\AppConfiguration;

it('reads the app\'s configuration file', function () {
    $app = fakeAppConfiguration(<<<'TOML'
        # The app's configuration.
        client_id = "abc123"
        handle = "my-app" # Named in admin URLs.
        application_url = "https://app.example.test"

        [access_scopes]
        scopes = "read_products, write_orders"

        [webhooks]
        api_version = "2026-07"

        [[webhooks.subscriptions]]
        topics = ["app/uninstalled", "app/scopes_update"]
        uri = "/webhooks"

        [[webhooks.subscriptions]]
        compliance_topics = [
            "customers/redact",
            "shop/redact",
        ]
        uri = "https://app.example.test/webhooks#compliance"
        TOML);

    expect($app->clientId())->toBe('abc123')
        ->and($app->handle())->toBe('my-app')
        ->and($app->applicationUrl())->toBe('https://app.example.test')
        ->and($app->webhookApiVersion())->toBe('2026-07')
        ->and($app->scopes())->toBe(['read_products', 'write_orders'])
        ->and($app->webhookSubscriptions())->toBe([
            'app/uninstalled' => ['uri' => '/webhooks', 'compliance' => false],
            'app/scopes_update' => ['uri' => '/webhooks', 'compliance' => false],
            'customers/redact' => ['uri' => 'https://app.example.test/webhooks#compliance', 'compliance' => true],
            'shop/redact' => ['uri' => 'https://app.example.test/webhooks#compliance', 'compliance' => true],
        ]);
});

it('treats a file that is not linked to an app yet as having no client ID', function () {
    expect(fakeAppConfiguration('client_id = ""')->clientId())->toBeNull();
});

it('subscribes the app to a webhook topic', function () {
    $app = fakeAppConfiguration();

    $app->subscribeToWebhook('orders/create');

    expect($app->webhookSubscriptions())->toHaveKey('orders/create', ['uri' => '/webhooks', 'compliance' => false])
        ->and(new AppConfiguration($app->path()))->webhookSubscriptions()->toHaveCount(6);
});

it('reads nothing from a file that does not exist', function () {
    $app = new AppConfiguration('/missing/shopify.app.toml');

    expect($app->exists())->toBeFalse()
        ->and($app->webhookSubscriptions())->toBe([]);
});

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

it('takes a linked app\'s identity, URLs, and scopes while keeping the kit\'s settings', function () {
    $kit = fakeAppConfiguration();
    $linked = fakeAppConfiguration(<<<'TOML'
        client_id = "abc123"
        name = "My App"
        application_url = "https://my-app.example.test"

        [webhooks]
        api_version = "2026-07"

        [[webhooks.subscriptions]]
        topics = ["app/uninstalled"]
        uri = "https://elsewhere.example.test/webhooks"

        [access_scopes]
        scopes = "read_orders,write_products"

        [auth]
        redirect_urls = [
          "https://my-app.example.test",
          "https://my-app.example.test/callback",
        ]
        TOML);

    $merged = fakeAppConfiguration($kit->linkedTo($linked));

    expect($merged->clientId())->toBe('abc123')
        ->and($merged->get('name'))->toBe('My App')
        ->and($merged->handle())->toBeNull()
        ->and($merged->applicationUrl())->toBe('https://my-app.example.test')
        ->and($merged->get('auth.redirect_urls'))->toBe(['https://my-app.example.test', 'https://my-app.example.test/callback'])
        ->and($merged->scopes())->toBe(['read_orders', 'write_products'])
        ->and($merged->get('build.automatically_update_urls_on_dev'))->toBeTrue()
        ->and($merged->webhookSubscriptions())->toHaveCount(5)
        ->and($merged->webhookSubscriptions()['app/uninstalled']['uri'])->toBe('/webhooks');
});

it('keeps the kit\'s scopes when the linked app has none', function () {
    $kit = fakeAppConfiguration(str_replace('scopes = ""', 'scopes = "read_products"', (string) file_get_contents(fakeAppConfiguration()->path())));

    $merged = fakeAppConfiguration($kit->linkedTo(fakeAppConfiguration('client_id = "abc123"')));

    expect($merged->scopes())->toBe(['read_products']);
});

it('points the app\'s URLs at a new origin, keeping the redirect URLs\' paths', function () {
    $app = fakeAppConfiguration(str_replace(
        'redirect_urls = ["https://app.example.test"]',
        "redirect_urls = [\n  \"https://app.example.test/\",\n  \"https://app.example.test/auth/callback\",\n]",
        (string) file_get_contents(fakeAppConfiguration()->path()),
    ));

    $app->pointTo('https://new.trycloudflare.com/');

    expect($app->applicationUrl())->toBe('https://new.trycloudflare.com')
        ->and($app->get('auth.redirect_urls'))->toBe(['https://new.trycloudflare.com', 'https://new.trycloudflare.com/auth/callback'])
        ->and($app->clientId())->toBe('test-client-id')
        ->and($app->webhookSubscriptions())->toHaveCount(5);
});

it('subscribes again to the default webhooks a file has lost', function () {
    $app = fakeAppConfiguration("client_id = \"abc123\"\n\n[access_scopes]\nscopes = \"\"\n");

    expect($app->subscribeToDefaultWebhooks('2026-07'))->toBe(array_keys(AppConfiguration::DEFAULT_WEBHOOKS))
        ->and($app->webhookApiVersion())->toBe('2026-07')
        ->and($app->webhookSubscriptions())->toBe([
            'app/uninstalled' => ['uri' => '/webhooks', 'compliance' => false],
            'app/scopes_update' => ['uri' => '/webhooks', 'compliance' => false],
            'customers/data_request' => ['uri' => '/webhooks', 'compliance' => true],
            'customers/redact' => ['uri' => '/webhooks', 'compliance' => true],
            'shop/redact' => ['uri' => '/webhooks', 'compliance' => true],
        ])
        ->and($app->get('access_scopes.scopes'))->toBe('');
});

it('subscribes again only to the default webhooks that are missing', function () {
    $app = fakeAppConfiguration(<<<'TOML'
        client_id = "abc123"

        [webhooks]

        [[webhooks.subscriptions]]
        topics = ["app/uninstalled", "app/scopes_update", "orders/create"]
        uri = "/webhooks"
        TOML);

    expect($app->subscribeToDefaultWebhooks('2026-07'))->toBe(['customers/data_request', 'customers/redact', 'shop/redact'])
        ->and($app->webhookApiVersion())->toBe('2026-07')
        ->and($app->webhookSubscriptions())->toHaveCount(6)
        ->and($app->webhookSubscriptions()['shop/redact']['compliance'])->toBeTrue();
});

it('leaves a file subscribed to every default webhook alone', function () {
    $app = fakeAppConfiguration();
    $contents = file_get_contents($app->path());

    expect($app->subscribeToDefaultWebhooks('2026-07'))->toBe([])
        ->and(file_get_contents($app->path()))->toBe($contents);
});

it('names a configuration and the command that deploys it', function () {
    $default = new AppConfiguration('/app/shopify.app.toml');
    $dev = new AppConfiguration('/app/shopify.app.dev.toml');

    expect($default->name())->toBeNull()
        ->and($default->deployCommand())->toBe('php artisan shopify:deploy --production')
        ->and($dev->name())->toBe('dev')
        ->and($dev->deployCommand())->toBe('php artisan shopify:deploy --config=dev');
});

it('tells how to get changes to Shopify: applied by shopify:dev locally, deployed otherwise', function () {
    expect((new AppConfiguration('/app/shopify.app.dev.toml'))->releaseInstruction())
        ->toBe('Run "php artisan shopify:dev" to apply the configuration to your development store')
        ->and((new AppConfiguration('/app/shopify.app.toml'))->releaseInstruction())
        ->toBe('Run "php artisan shopify:deploy --production" to release the configuration to Shopify')
        ->and((new AppConfiguration('/app/shopify.app.staging.toml'))->releaseInstruction())
        ->toBe('Run "php artisan shopify:deploy --config=staging" to release the configuration to Shopify');
});

it('reads nothing from a file that does not exist', function () {
    $app = new AppConfiguration('/missing/shopify.app.toml');

    expect($app->exists())->toBeFalse()
        ->and($app->webhookSubscriptions())->toBe([]);
});

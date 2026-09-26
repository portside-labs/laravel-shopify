<?php

use Illuminate\Support\Str;

beforeEach(function () {
    $this->toml = fakeAppConfiguration();

    // The command writes into the app, so it runs against a copy of the files it changes...
    $this->directory = sys_get_temp_dir().'/laravel-shopify-'.Str::random(12);
    mkdir("{$this->directory}/config", recursive: true);
    copy(config_path('shopify.php'), "{$this->directory}/config/shopify.php");

    $this->app->setBasePath($this->directory);
    $this->app->useAppPath("{$this->directory}/app");
    $this->app->useConfigPath("{$this->directory}/config");
});

it('creates a job and a test for the topic, and subscribes the app to it', function () {
    $this->artisan('make:shopify-webhook', ['topic' => 'orders/create'])->assertSuccessful();

    expect(file_get_contents("{$this->directory}/app/Jobs/Webhooks/OrdersCreate.php"))
        ->toContain('class OrdersCreate implements ShouldQueue')
        ->toContain('Handle the "orders/create" webhook.')
        ->and(file_get_contents("{$this->directory}/tests/Feature/Jobs/Webhooks/OrdersCreateTest.php"))
        ->toContain('use App\Jobs\Webhooks\OrdersCreate;')
        ->and(file_get_contents("{$this->directory}/config/shopify.php"))
        ->toContain("        'orders/create' => OrdersCreate::class,\n    ],")
        ->toContain("use App\Jobs\Webhooks\CustomersRedact;\nuse App\Jobs\Webhooks\OrdersCreate;\nuse App\Jobs\Webhooks\ShopRedact;")
        ->and($this->toml->webhookSubscriptions())->toHaveKey('orders/create');
});

it('writes a configuration file that still loads', function () {
    $this->artisan('make:shopify-webhook', ['topic' => 'orders/create'])->assertSuccessful();

    expect(require "{$this->directory}/config/shopify.php")
        ->webhooks->toHaveKey('orders/create', 'App\Jobs\Webhooks\OrdersCreate')
        ->webhooks->toHaveCount(6);
});

it('leaves an existing job, mapping, and subscription alone', function () {
    mkdir("{$this->directory}/app/Jobs/Webhooks", recursive: true);
    file_put_contents("{$this->directory}/app/Jobs/Webhooks/AppUninstalled.php", 'original');
    $config = file_get_contents("{$this->directory}/config/shopify.php");
    $toml = file_get_contents($this->toml->path());

    $this->artisan('make:shopify-webhook', ['topic' => 'app/uninstalled'])
        ->expectsOutputToContain('app/uninstalled is already mapped')
        ->assertSuccessful();

    expect(file_get_contents("{$this->directory}/app/Jobs/Webhooks/AppUninstalled.php"))->toBe('original')
        ->and(file_get_contents("{$this->directory}/config/shopify.php"))->toBe($config)
        ->and(file_get_contents($this->toml->path()))->toBe($toml);
});

it('rejects a topic that is not one', function () {
    $this->artisan('make:shopify-webhook', ['topic' => 'orders'])
        ->expectsOutputToContain('[orders] is not a webhook topic.')
        ->assertFailed();
});

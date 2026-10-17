<?php

use App\Shopify\AppConfiguration;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->directory = sys_get_temp_dir().'/laravel-shopify-'.Str::random(12);
    mkdir($this->directory);
    file_put_contents("{$this->directory}/.env", "APP_NAME=Laravel\nAPP_URL=http://localhost\n");
    $this->app->useEnvironmentPath($this->directory);
    $this->app['env'] = 'local';
    $this->toml = fakeAppConfiguration(str_replace(
        'redirect_urls = ["https://app.example.test"]',
        'redirect_urls = ["https://old.trycloudflare.com", "https://old.trycloudflare.com/auth/callback"]',
        (string) file_get_contents(fakeAppConfiguration()->path()),
    ));

    $this->originalVariables = [];

    foreach (['APP_URL', 'SHOPIFY_DEV_APP_URL'] as $variable) {
        $this->originalVariables[$variable] = [getenv($variable), $_ENV[$variable] ?? null, $_SERVER[$variable] ?? null];
    }
});

afterEach(function () {
    // phpunit.xml sets APP_URL for the whole suite, so the original values are put back rather than removed...
    foreach ($this->originalVariables as $variable => [$putenv, $env, $server]) {
        putenv($putenv === false ? $variable : "{$variable}={$putenv}");

        if ($env === null) {
            unset($_ENV[$variable]);
        } else {
            $_ENV[$variable] = $env;
        }

        if ($server === null) {
            unset($_SERVER[$variable]);
        } else {
            $_SERVER[$variable] = $server;
        }
    }
});

/**
 * Set an environment variable as the Shopify CLI would for the process it starts.
 */
function setShopifyCliVariable(string $name, string $value): void
{
    putenv("{$name}={$value}");
    $_ENV[$name] = $_SERVER[$name] = $value;
}

it('records the URL of the tunnel the Shopify CLI opened', function () {
    setShopifyCliVariable('APP_URL', 'https://example.trycloudflare.com');

    $this->artisan('shopify:record-app-url')->assertSuccessful();

    expect(file_get_contents("{$this->directory}/.env"))->toContain('APP_URL="https://example.trycloudflare.com"');
});

it('points the app\'s URLs in the configuration file at the tunnel', function () {
    setShopifyCliVariable('APP_URL', 'https://example.trycloudflare.com');

    $this->artisan('shopify:record-app-url')
        ->expectsOutputToContain('APP_URL and the URLs in shopify.app.toml now point at https://example.trycloudflare.com.')
        ->assertSuccessful();

    $app = new AppConfiguration($this->toml->path());
    expect($app->applicationUrl())->toBe('https://example.trycloudflare.com')
        ->and($app->get('auth.redirect_urls'))->toBe(['https://example.trycloudflare.com', 'https://example.trycloudflare.com/auth/callback'])
        ->and($app->webhookSubscriptions())->toHaveCount(5);
});

it('prefers the URL of a tunnel given to shopify:dev', function () {
    setShopifyCliVariable('APP_URL', 'https://my-app.ngrok.dev:3000');
    setShopifyCliVariable('SHOPIFY_DEV_APP_URL', 'https://my-app.ngrok.dev');

    $this->artisan('shopify:record-app-url')->assertSuccessful();

    expect(file_get_contents("{$this->directory}/.env"))->toContain('APP_URL="https://my-app.ngrok.dev"')
        ->not->toContain(':3000');
});

it('leaves .env alone outside the local environment', function () {
    $this->app['env'] = 'production';
    setShopifyCliVariable('APP_URL', 'https://example.trycloudflare.com');

    $this->artisan('shopify:record-app-url')->assertSuccessful();

    expect(file_get_contents("{$this->directory}/.env"))->toContain('APP_URL=http://localhost')
        ->and($this->toml->applicationUrl())->toBe('https://app.example.test');
});

it('leaves .env alone when there is no tunnel', function () {
    setShopifyCliVariable('APP_URL', 'http://localhost:8000');

    $this->artisan('shopify:record-app-url')->assertSuccessful();

    expect(file_get_contents("{$this->directory}/.env"))->toContain('APP_URL=http://localhost');
});

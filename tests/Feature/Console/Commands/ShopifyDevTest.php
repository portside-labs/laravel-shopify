<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->directory = sys_get_temp_dir().'/laravel-shopify-'.Str::random(12);
    mkdir($this->directory);
    file_put_contents("{$this->directory}/.env", "APP_NAME=Laravel\nAPP_URL=http://localhost\n");
    $this->app->useEnvironmentPath($this->directory);
    $this->app['env'] = 'local';

    // The Shopify CLI must never run for real: it would open a tunnel and change the developer's app URLs.
    Process::preventStrayProcesses();
});

it('serves the app through the Shopify CLI\'s tunnel', function () {
    Process::fake(['*' => Process::result()]);

    $this->artisan('shopify:dev')
        ->expectsOutputToContain('The Shopify CLI opens a Cloudflare tunnel.')
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => $process->command === ['shopify', 'app', 'dev']);
    expect(file_get_contents("{$this->directory}/.env"))->toContain('APP_URL=http://localhost');
});

it('serves the app through a tunnel of your own and points APP_URL at it', function () {
    Process::fake(['*' => Process::result()]);

    $this->artisan('shopify:dev', ['--tunnel-url' => 'https://my-app.ngrok.dev'])
        ->expectsOutputToContain('ngrok http 3000 --url=my-app.ngrok.dev')
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => $process->command === ['shopify', 'app', 'dev', '--tunnel-url=https://my-app.ngrok.dev:3000']
        && $process->environment === ['SHOPIFY_DEV_APP_URL' => 'https://my-app.ngrok.dev']);
    expect(file_get_contents("{$this->directory}/.env"))->toContain('APP_URL="https://my-app.ngrok.dev"');
});

it('keeps the local port given with the tunnel', function () {
    Process::fake(['*' => Process::result()]);

    $this->artisan('shopify:dev', ['--tunnel-url' => 'https://my-app.ngrok.dev:8080'])->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => $process->command === ['shopify', 'app', 'dev', '--tunnel-url=https://my-app.ngrok.dev:8080']);
    expect(file_get_contents("{$this->directory}/.env"))->toContain('APP_URL="https://my-app.ngrok.dev"')
        ->not->toContain(':8080');
});

it('serves a named configuration', function () {
    config()->set('shopify.app_config', 'dev');
    Process::fake(['*' => Process::result()]);

    $this->artisan('shopify:dev')->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => $process->command === ['shopify', 'app', 'dev', '--config', 'dev']);
});

it('rejects a tunnel that is not served over HTTPS', function () {
    Process::fake(['*' => Process::result()]);

    $this->artisan('shopify:dev', ['--tunnel-url' => 'http://my-app.ngrok.dev'])
        ->expectsOutputToContain('is not an HTTPS URL')
        ->assertFailed();

    Process::assertDidntRun(fn (PendingProcess $process) => $process->command === ['shopify', 'app', 'dev', '--tunnel-url=http://my-app.ngrok.dev:3000']);
    expect(file_get_contents("{$this->directory}/.env"))->toContain('APP_URL=http://localhost');
});

it('only runs in the local environment', function () {
    $this->app['env'] = 'production';
    Process::fake();

    $this->artisan('shopify:dev', ['--tunnel-url' => 'https://my-app.ngrok.dev'])
        ->expectsOutputToContain('shopify:dev only runs in the local environment.')
        ->assertFailed();

    Process::assertNothingRan();
    expect(file_get_contents("{$this->directory}/.env"))->toContain('APP_URL=http://localhost');
});

it('asks for the Shopify CLI when it is not installed', function () {
    Process::fake(['*shopify*version*' => Process::result(exitCode: 127)]);

    $this->artisan('shopify:dev')
        ->expectsOutputToContain('The Shopify CLI is not installed.')
        ->assertFailed();
});

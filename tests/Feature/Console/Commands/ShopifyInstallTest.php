<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->directory = sys_get_temp_dir().'/laravel-shopify-'.Str::random(12);
    mkdir($this->directory);
    file_put_contents("{$this->directory}/.env", "APP_NAME=Laravel\n");
    $this->app->useEnvironmentPath($this->directory);

    // The Shopify CLI must never run for real: it would link and change the developer's own app.
    Process::preventStrayProcesses();
});

it('links the app, pulls its credentials into .env, and checks the configuration', function () {
    $app = fakeAppConfiguration(str_replace('client_id = "test-client-id"', 'client_id = ""', (string) file_get_contents(fakeAppConfiguration()->path())));

    Process::fake([
        '*shopify*version*' => Process::result('3.80.0'),
        '*shopify*config*link*' => function () use ($app) {
            file_put_contents($app->path(), str_replace('client_id = ""', 'client_id = "test-client-id"', (string) file_get_contents($app->path())));

            return Process::result();
        },
        '*shopify*env*pull*' => function () {
            file_put_contents("{$this->directory}/.env", "SHOPIFY_API_KEY=test-client-id\nSHOPIFY_API_SECRET=test-client-secret\n", FILE_APPEND);

            return Process::result();
        },
    ]);

    $this->artisan('shopify:install', ['--client-id' => 'test-client-id'])
        ->expectsOutputToContain('The app is linked and its credentials are in .env.')
        ->expectsOutputToContain('The app is configured correctly.')
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => $process->command === ['shopify', 'app', 'config', 'link', '--client-id', 'test-client-id']);
    Process::assertRan(fn (PendingProcess $process) => $process->command === ['shopify', 'app', 'env', 'pull', '--env-file', "{$this->directory}/.env"]);
    expect(file_get_contents("{$this->directory}/.env"))->toContain('SHOPIFY_APP_HANDLE="laravel-shopify"');
});

it('keeps the linked app unless asked to link another', function () {
    fakeAppConfiguration();
    Process::fake(['*' => Process::result()]);

    $this->artisan('shopify:install')
        ->expectsConfirmation('shopify.app.toml is linked to the app test-client-id. Link it to a different app?', 'no')
        ->assertSuccessful();

    Process::assertDidntRun(fn (PendingProcess $process) => array_slice((array) $process->command, 0, 3) === ['shopify', 'app', 'config']);
    Process::assertRan(fn (PendingProcess $process) => array_slice((array) $process->command, 0, 3) === ['shopify', 'app', 'env']);
});

it('asks for the Shopify CLI when it is not installed', function () {
    Process::fake(['*shopify*version*' => Process::result(exitCode: 127)]);

    $this->artisan('shopify:install')
        ->expectsOutputToContain('The Shopify CLI is not installed.')
        ->assertFailed();
});

it('stops when the Shopify CLI fails', function () {
    fakeAppConfiguration();
    Process::fake([
        '*shopify*version*' => Process::result(),
        '*shopify*env*pull*' => Process::result(exitCode: 1),
    ]);

    $this->artisan('shopify:install')
        ->expectsConfirmation('shopify.app.toml is linked to the app test-client-id. Link it to a different app?', 'no')
        ->expectsOutputToContain('"shopify app env pull --env-file')
        ->assertFailed();
});

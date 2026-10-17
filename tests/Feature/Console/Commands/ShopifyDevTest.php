<?php

use App\Shopify\AppConfiguration;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->directory = sys_get_temp_dir().'/laravel-shopify-'.Str::random(12);
    mkdir($this->directory);
    file_put_contents("{$this->directory}/.env", "APP_NAME=Laravel\nAPP_URL=http://localhost\n");
    $this->app->useEnvironmentPath($this->directory);
    $this->app['env'] = 'local';

    // Locally, the command works on shopify.app.dev.toml next to the kit's shopify.app.toml...
    $this->kit = fakeAppConfiguration(str_replace('client_id = "test-client-id"', 'client_id = ""', (string) file_get_contents(fakeAppConfiguration()->path())));
    $this->devPath = dirname($this->kit->path()).'/shopify.app.dev.toml';
    file_put_contents($this->devPath, (string) file_get_contents(fakeAppConfiguration()->path()));
    $this->app->instance(AppConfiguration::class, $this->kit);

    // The Shopify CLI must never run for real: it would open a tunnel and change the developer's app URLs.
    Process::preventStrayProcesses();
});

it('serves the local configuration through the Shopify CLI\'s tunnel', function () {
    Process::fake(['*' => Process::result()]);

    $this->artisan('shopify:dev')
        ->expectsOutputToContain('Using dev for local development')
        ->expectsOutputToContain('The Shopify CLI opens a Cloudflare tunnel.')
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => $process->command === ['npm', 'install']);
    Process::assertRan(fn (PendingProcess $process) => $process->command === ['shopify', 'app', 'dev', '--config', 'dev']);
    expect(file_get_contents("{$this->directory}/.env"))->toMatch('/^SHOPIFY_APP_CONFIG="?dev"?$/m')
        ->toContain('APP_URL=http://localhost');
});

it('serves the app through a tunnel of your own and points APP_URL at it', function () {
    Process::fake(['*' => Process::result()]);

    $this->artisan('shopify:dev', ['--tunnel-url' => 'https://my-app.ngrok.dev'])
        ->expectsOutputToContain('ngrok http 3000 --url=my-app.ngrok.dev')
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => $process->command === ['shopify', 'app', 'dev', '--config', 'dev', '--tunnel-url=https://my-app.ngrok.dev:3000']
        && $process->environment === ['SHOPIFY_DEV_APP_URL' => 'https://my-app.ngrok.dev']);
    expect(file_get_contents("{$this->directory}/.env"))->toContain('APP_URL="https://my-app.ngrok.dev"');
});

it('keeps the local port given with the tunnel', function () {
    Process::fake(['*' => Process::result()]);

    $this->artisan('shopify:dev', ['--tunnel-url' => 'https://my-app.ngrok.dev:8080'])->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => $process->command === ['shopify', 'app', 'dev', '--config', 'dev', '--tunnel-url=https://my-app.ngrok.dev:8080']);
    expect(file_get_contents("{$this->directory}/.env"))->toContain('APP_URL="https://my-app.ngrok.dev"')
        ->not->toContain(':8080');
});

it('serves the configuration named in SHOPIFY_APP_CONFIG instead', function () {
    config()->set('shopify.app_config', 'staging');
    $staging = new AppConfiguration(dirname($this->kit->path()).'/shopify.app.staging.toml');
    copy($this->devPath, $staging->path());
    $this->app->instance(AppConfiguration::class, $staging);
    Process::fake(['*' => Process::result()]);

    $this->artisan('shopify:dev')->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => $process->command === ['shopify', 'app', 'dev', '--config', 'staging']);
    expect(file_get_contents("{$this->directory}/.env"))->not->toContain('SHOPIFY_APP_CONFIG');
});

it('links an unlinked app through shopify:install before serving it, leaving the kit\'s configuration alone', function () {
    unlink($this->devPath);
    $kit = file_get_contents($this->kit->path());

    Process::fake([
        '*shopify*config*link*' => function () {
            // The CLI writes the file from the Dev Dashboard, without the kit's webhooks...
            file_put_contents($this->devPath, "client_id = \"test-client-id\"\nname = \"My App\"\n");

            return Process::result();
        },
        '*' => Process::result(),
    ]);

    $this->artisan('shopify:dev')
        ->expectsOutputToContain('shopify.app.dev.toml is not linked to an app yet, so it is linked first.')
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => $process->command === ['shopify', 'app', 'config', 'link', '--file-name', 'shopify.app.dev.toml']);
    Process::assertRan(fn (PendingProcess $process) => $process->command === ['shopify', 'app', 'dev', '--config', 'dev']);
    expect((new AppConfiguration($this->devPath))->webhookSubscriptions())->toHaveCount(5)
        ->and((new AppConfiguration($this->devPath))->get('name'))->toBe('My App')
        ->and(file_get_contents($this->kit->path()))->toBe($kit);
});

it('stops when the app could not be linked', function () {
    unlink($this->devPath);
    Process::fake([
        '*shopify*config*link*' => Process::result(exitCode: 1),
        '*' => Process::result(),
    ]);

    $this->artisan('shopify:dev')->assertFailed();

    Process::assertDidntRun(fn (PendingProcess $process) => array_slice((array) $process->command, 0, 3) === ['shopify', 'app', 'dev']);
});

it('subscribes again to the default webhooks the configuration has lost', function () {
    file_put_contents($this->devPath, "client_id = \"test-client-id\"\nname = \"My App\"\n");
    Process::fake(['*' => Process::result()]);

    $this->artisan('shopify:dev')
        ->expectsOutputToContain('The Shopify CLI applies them to your development store as it starts.')
        ->assertSuccessful();

    expect((new AppConfiguration($this->devPath))->webhookSubscriptions())->toHaveKeys(array_keys(AppConfiguration::DEFAULT_WEBHOOKS));
});

it('stops when the Node packages could not be installed', function () {
    Process::fake([
        '*npm*install*' => Process::result(exitCode: 1),
        '*' => Process::result(),
    ]);

    $this->artisan('shopify:dev')
        ->expectsOutputToContain('"npm install" failed.')
        ->assertFailed();

    Process::assertDidntRun(fn (PendingProcess $process) => array_slice((array) $process->command, 0, 3) === ['shopify', 'app', 'dev']);
});

it('rejects a tunnel that is not served over HTTPS', function () {
    Process::fake(['*' => Process::result()]);

    $this->artisan('shopify:dev', ['--tunnel-url' => 'http://my-app.ngrok.dev'])
        ->expectsOutputToContain('is not an HTTPS URL')
        ->assertFailed();

    Process::assertDidntRun(fn (PendingProcess $process) => array_slice((array) $process->command, 0, 3) === ['shopify', 'app', 'dev']);
    expect(file_get_contents("{$this->directory}/.env"))->toContain('APP_URL=http://localhost');
});

it('only runs in the local environment', function () {
    $this->app['env'] = 'production';
    Process::fake();

    $this->artisan('shopify:dev', ['--tunnel-url' => 'https://my-app.ngrok.dev'])
        ->expectsOutputToContain('shopify:dev only runs in the local environment.')
        ->assertFailed();

    Process::assertNothingRan();
    expect(file_get_contents("{$this->directory}/.env"))->toContain('APP_URL=http://localhost')
        ->not->toContain('SHOPIFY_APP_CONFIG');
});

it('asks for the Shopify CLI when it is not installed', function () {
    Process::fake(['*shopify*version*' => Process::result(exitCode: 127)]);

    $this->artisan('shopify:dev')
        ->expectsOutputToContain('The Shopify CLI is not installed.')
        ->assertFailed();
});

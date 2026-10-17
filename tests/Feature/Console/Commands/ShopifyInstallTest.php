<?php

use App\Shopify\AppConfiguration;
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
        // The CLI rewrites the file from the Dev Dashboard, dropping the kit's webhooks and settings...
        '*shopify*config*link*' => function () use ($app) {
            file_put_contents($app->path(), <<<'TOML'
                client_id = "test-client-id"
                name = "My App"
                handle = "laravel-shopify"
                application_url = "https://my-app.example.test"
                embedded = true

                [access_scopes]
                scopes = "write_products"

                [auth]
                redirect_urls = [ "https://my-app.example.test" ]
                TOML);

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
    expect(file_get_contents("{$this->directory}/.env"))->toContain('SHOPIFY_APP_HANDLE="laravel-shopify"')
        ->not->toContain('SHOPIFY_APP_CONFIG');

    $linked = new AppConfiguration($app->path());
    expect($linked->get('name'))->toBe('My App')
        ->and($linked->applicationUrl())->toBe('https://my-app.example.test')
        ->and($linked->scopes())->toBe(['write_products'])
        ->and($linked->get('build.include_config_on_deploy'))->toBeTrue()
        ->and($linked->webhookSubscriptions())->toHaveCount(5);
});

it('links a named configuration and leaves the kit\'s own alone', function () {
    $template = fakeAppConfiguration();
    $kit = (string) file_get_contents($template->path());
    $app = new AppConfiguration(dirname($template->path()).'/shopify.app.dev.toml');
    $this->app->instance(AppConfiguration::class, $app);
    config()->set('shopify.app_config', 'dev');

    Process::fake([
        '*shopify*version*' => Process::result(),
        '*shopify*config*link*' => function () use ($app) {
            file_put_contents($app->path(), "client_id = \"test-client-id\"\nname = \"Dev App\"\n");

            return Process::result();
        },
        '*shopify*env*pull*' => Process::result(),
    ]);

    $this->artisan('shopify:install')->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => $process->command === ['shopify', 'app', 'config', 'link', '--file-name', 'shopify.app.dev.toml']);
    Process::assertRan(fn (PendingProcess $process) => array_slice((array) $process->command, 0, 5) === ['shopify', 'app', 'env', 'pull', '--config'] && $process->command[5] === 'dev');
    expect(file_get_contents($template->path()))->toBe($kit)
        ->and((new AppConfiguration($app->path()))->get('name'))->toBe('Dev App')
        ->and((new AppConfiguration($app->path()))->webhookSubscriptions())->toHaveCount(5);
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

it('links shopify.app.dev.toml in the local environment, leaving the kit\'s configuration alone', function () {
    $this->app['env'] = 'local';
    $kit = fakeAppConfiguration();
    $contents = file_get_contents($kit->path());
    $devPath = dirname($kit->path()).'/shopify.app.dev.toml';

    Process::fake([
        '*shopify*config*link*' => function () use ($devPath) {
            file_put_contents($devPath, "client_id = \"test-client-id\"\nname = \"Dev App\"\n");

            return Process::result();
        },
        '*' => Process::result(),
    ]);

    $this->artisan('shopify:install')
        ->expectsOutputToContain('Using dev for local development')
        ->expectsOutputToContain('Next, run "php artisan shopify:dev".')
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => $process->command === ['shopify', 'app', 'config', 'link', '--file-name', 'shopify.app.dev.toml']);
    Process::assertRan(fn (PendingProcess $process) => $process->command === ['shopify', 'app', 'env', 'pull', '--config', 'dev', '--env-file', "{$this->directory}/.env"]);
    expect(file_get_contents("{$this->directory}/.env"))->toMatch('/^SHOPIFY_APP_CONFIG="?dev"?$/m')
        ->and(file_get_contents($kit->path()))->toBe($contents)
        ->and((new AppConfiguration($devPath))->webhookSubscriptions())->toHaveCount(5);
});

it('subscribes again to the default webhooks a linked app has lost', function () {
    $app = fakeAppConfiguration("client_id = \"test-client-id\"\nname = \"My App\"\nhandle = \"laravel-shopify\"\napplication_url = \"https://my-app.example.test\"\n");
    Process::fake(['*' => Process::result()]);

    $this->artisan('shopify:install')
        ->expectsConfirmation('shopify.app.toml is linked to the app test-client-id. Link it to a different app?', 'no')
        ->expectsOutputToContain('Run "php artisan shopify:deploy --production" to release the configuration to Shopify.')
        ->assertSuccessful();

    expect((new AppConfiguration($app->path()))->webhookSubscriptions())->toHaveKeys(array_keys(AppConfiguration::DEFAULT_WEBHOOKS));
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

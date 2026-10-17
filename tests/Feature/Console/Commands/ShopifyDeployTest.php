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
    $this->travelTo('2026-10-17');

    $this->kit = fakeAppConfiguration(str_replace('https://app.example.test', 'https://my-app.example.com', (string) file_get_contents(fakeAppConfiguration()->path())));
    $this->devPath = dirname($this->kit->path()).'/shopify.app.dev.toml';
    file_put_contents($this->devPath, str_replace('https://my-app.example.com', 'https://example.trycloudflare.com', (string) file_get_contents($this->kit->path())));
    $this->app->instance(AppConfiguration::class, $this->kit);

    // The Shopify CLI must never run for real: it would release configuration to the developer's app.
    Process::preventStrayProcesses();
});

/**
 * Determine if "shopify app deploy" ran with the given arguments.
 *
 * @param  list<string>  $arguments
 */
function deployedWith(array $arguments): Closure
{
    return fn (PendingProcess $process) => $process->command === ['shopify', 'app', 'deploy', ...$arguments];
}

it('checks the configuration in use and deploys it', function () {
    Process::fake(['*' => Process::result()]);

    $this->artisan('shopify:deploy')
        ->expectsOutputToContain('The app is configured correctly.')
        ->expectsOutputToContain('Deploying shopify.app.toml to the app test-client-id.')
        ->assertSuccessful();

    Process::assertRan(deployedWith(['--config', 'shopify.app.toml']));
});

it('deploys the local configuration in the local environment', function () {
    $this->app['env'] = 'local';
    Process::fake(['*' => Process::result()]);

    $this->artisan('shopify:deploy')->assertSuccessful();

    Process::assertRan(deployedWith(['--config', 'shopify.app.dev.toml']));
    expect(file_get_contents("{$this->directory}/.env"))->toMatch('/^SHOPIFY_APP_CONFIG="?dev"?$/m');
});

it('deploys a named configuration', function () {
    Process::fake(['*' => Process::result()]);

    $this->artisan('shopify:deploy', ['--config' => 'dev'])->assertSuccessful();

    Process::assertRan(deployedWith(['--config', 'shopify.app.dev.toml']));
});

it('deploys the production configuration wherever it runs', function () {
    $this->app['env'] = 'local';
    Process::fake(['*' => Process::result()]);

    $this->artisan('shopify:deploy', ['--production' => true])->assertSuccessful();

    Process::assertRan(deployedWith(['--config', 'shopify.app.toml']));
    expect(file_get_contents("{$this->directory}/.env"))->not->toContain('SHOPIFY_APP_CONFIG');
});

it('refuses to deploy the production configuration while it points at a tunnel', function (string $url) {
    file_put_contents($this->kit->path(), str_replace('https://my-app.example.com', $url, (string) file_get_contents($this->kit->path())));
    Process::fake(['*' => Process::result()]);

    $this->artisan('shopify:deploy', ['--production' => true])
        ->expectsOutputToContain("its application_url is {$url}")
        ->assertFailed();

    Process::assertDidntRun(deployedWith(['--config', 'shopify.app.toml']));
})->with([
    'https://example.trycloudflare.com',
    'https://my-app.ngrok.dev',
    'https://example.com',
    'http://localhost:8000',
]);

it('refuses to deploy a configuration that is not linked to an app', function () {
    file_put_contents($this->devPath, 'client_id = ""');
    Process::fake(['*' => Process::result()]);

    $this->artisan('shopify:deploy', ['--config' => 'dev'])
        ->expectsOutputToContain('shopify.app.dev.toml is not linked to an app. Run "php artisan shopify:install" first.')
        ->assertFailed();

    Process::assertDidntRun(deployedWith(['--config', 'shopify.app.dev.toml']));
});

it('stops when a check fails, unless asked to skip them', function () {
    config()->set('shopify.client_id', 'another-client-id');
    Process::fake(['*' => Process::result()]);

    $this->artisan('shopify:deploy')
        ->expectsOutputToContain('deploy anyway with --skip-checks')
        ->assertFailed();

    Process::assertDidntRun(deployedWith(['--config', 'shopify.app.toml']));

    $this->artisan('shopify:deploy', ['--skip-checks' => true])->assertSuccessful();

    Process::assertRan(deployedWith(['--config', 'shopify.app.toml']));
});

it('subscribes again to the default webhooks before deploying', function () {
    file_put_contents($this->devPath, "client_id = \"test-client-id\"\nname = \"Dev App\"\napplication_url = \"https://example.trycloudflare.com\"\n");
    Process::fake(['*' => Process::result()]);

    $this->artisan('shopify:deploy', ['--config' => 'dev', '--skip-checks' => true])
        ->expectsOutputToContain('in shopify.app.dev.toml again.')
        ->assertSuccessful();

    expect((new AppConfiguration($this->devPath))->webhookSubscriptions())->toHaveKeys(array_keys(AppConfiguration::DEFAULT_WEBHOOKS));
});

it('passes the version details and release options on to the Shopify CLI', function () {
    Process::fake(['*' => Process::result()]);

    $this->artisan('shopify:deploy', [
        '--message' => 'Add order sync',
        '--app-version' => 'v1.2.0',
        '--no-release' => true,
        '--allow-deletes' => true,
    ])->assertSuccessful();

    Process::assertRan(deployedWith(['--config', 'shopify.app.toml', '--message', 'Add order sync', '--version', 'v1.2.0', '--no-release', '--allow-deletes']));
});

it('releases updates without asking when it cannot ask, such as in CI', function () {
    Process::fake(['*' => Process::result()]);

    $this->artisan('shopify:deploy', ['--no-interaction' => true])->assertSuccessful();

    Process::assertRan(deployedWith(['--config', 'shopify.app.toml', '--allow-updates']));
});

it('fails when the Shopify CLI does', function () {
    Process::fake([
        '*shopify*app*deploy*' => Process::result(exitCode: 1),
        '*' => Process::result(),
    ]);

    $this->artisan('shopify:deploy')->assertFailed();
});

it('asks for the Shopify CLI when it is not installed', function () {
    Process::fake(['*shopify*version*' => Process::result(exitCode: 127)]);

    $this->artisan('shopify:deploy')
        ->expectsOutputToContain('The Shopify CLI is not installed.')
        ->assertFailed();
});

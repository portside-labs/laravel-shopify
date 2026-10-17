<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\UsesLocalAppConfiguration;
use App\Shopify\AppConfiguration;
use Dotenv\Dotenv;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Process;

#[Signature('shopify:install {--client-id= : The client ID of the app to link, instead of choosing it from a list}')]
#[Description('Link the kit to a Shopify app and pull its credentials into .env')]
class ShopifyInstall extends Command
{
    use UsesLocalAppConfiguration;

    /**
     * Execute the console command.
     */
    public function handle(AppConfiguration $app): int
    {
        if (Process::run(['shopify', 'version'])->failed()) {
            $this->components->error('The Shopify CLI is not installed.');
            $this->line('  Install it with <options=bold>npm install -g @shopify/cli@latest</>, then run this command again.');

            return self::FAILURE;
        }

        $environmentFile = $this->laravel->environmentFilePath();

        if (! is_file($environmentFile)) {
            $this->components->error('The .env file does not exist. Run "composer run setup" first.');

            return self::FAILURE;
        }

        $app = $this->useLocalAppConfiguration($app);
        $file = basename($app->path());
        $config = $app->name() ? ['--config', $app->name()] : [];

        // Linking fetches the app's configuration from the Dev Dashboard...
        if ($app->clientId() === null || $this->confirm("{$file} is linked to the app {$app->clientId()}. Link it to a different app?", false)) {
            if (! $app = $this->link($app)) {
                return self::FAILURE;
            }
        }

        $this->restoreDefaultWebhooks($app);

        // ...and pulling writes its client ID, secret, and scopes into .env.
        if (! $this->shopify(['app', 'env', 'pull', ...$config, '--env-file', $environmentFile])) {
            return self::FAILURE;
        }

        if ($app->handle() !== null) {
            Env::writeVariable('SHOPIFY_APP_HANDLE', $app->handle(), $environmentFile, overwrite: true);
        }

        $this->components->info('The app is linked and its credentials are in .env.');

        // The configuration was loaded before .env changed, so the new credentials are read in before checking it.
        $variables = Dotenv::parse((string) file_get_contents($environmentFile));

        foreach (['SHOPIFY_API_KEY' => 'shopify.client_id', 'SHOPIFY_API_SECRET' => 'shopify.client_secret', 'SHOPIFY_APP_HANDLE' => 'shopify.app_handle'] as $variable => $key) {
            if (isset($variables[$variable])) {
                config([$key => $variables[$variable]]);
            }
        }

        $this->laravel->instance(AppConfiguration::class, $app);

        $status = $this->call('shopify:doctor');

        if ($status === self::SUCCESS && $this->laravel->isLocal()) {
            $this->components->info('Next, run "php artisan shopify:dev". It serves the app through a tunnel, points its URLs at it, and installs it on your development store.');
        }

        return $status;
    }

    /**
     * Subscribe again to the kit's default webhook topics if the configuration file has lost any.
     */
    private function restoreDefaultWebhooks(AppConfiguration $app): void
    {
        $topics = $app->subscribeToDefaultWebhooks((string) config('shopify.api_version'));

        if ($topics !== []) {
            $this->components->info('Subscribed to '.implode(', ', $topics).' in '.basename($app->path()).'. '.$app->releaseInstruction().'.');
        }
    }

    /**
     * Link the configuration file to an app, keeping the kit's webhooks and settings.
     *
     * The CLI rewrites the file from the app's copy in the Dev Dashboard, so the
     * kit's own "shopify.app.toml" is kept aside first and the linked app's
     * client ID, name, handle, URLs, and scopes are written back into it.
     */
    private function link(AppConfiguration $app): ?AppConfiguration
    {
        $template = tempnam(sys_get_temp_dir(), 'shopify-app');
        copy(dirname($app->path()).'/shopify.app.toml', $template);

        try {
            $clientId = $this->option('client-id');
            $file = basename($app->path());

            if (! $this->shopify(['app', 'config', 'link', ...($clientId ? ['--client-id', $clientId] : []), ...($file === 'shopify.app.toml' ? [] : ['--file-name', $file])])) {
                return null;
            }

            file_put_contents($app->path(), (new AppConfiguration($template))->linkedTo(new AppConfiguration($app->path())));
        } finally {
            unlink($template);
        }

        return new AppConfiguration($app->path());
    }

    /**
     * Run a Shopify CLI command in the foreground, letting it prompt the developer.
     *
     * @param  list<string>  $arguments
     */
    private function shopify(array $arguments): bool
    {
        $result = Process::path(base_path())
            ->forever()
            ->tty(Process::supportsTty())
            ->run(['shopify', ...$arguments], fn (string $type, string $output) => $this->output->write($output));

        if ($result->failed()) {
            $this->components->error('"shopify '.implode(' ', $arguments).'" failed.');
        }

        return $result->successful();
    }
}

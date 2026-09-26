<?php

namespace App\Console\Commands;

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

        // Linking fetches the app's configuration from the Dev Dashboard into shopify.app.toml...
        if ($app->clientId() === null || $this->confirm("shopify.app.toml is linked to the app {$app->clientId()}. Link it to a different app?", false)) {
            $clientId = $this->option('client-id');

            if (! $this->shopify(['app', 'config', 'link', ...($clientId ? ['--client-id', $clientId] : [])])) {
                return self::FAILURE;
            }

            $app = new AppConfiguration($app->path());
        }

        // ...and pulling writes its client ID, secret, and scopes into .env.
        if (! $this->shopify(['app', 'env', 'pull', '--env-file', $environmentFile])) {
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

        return $this->call('shopify:doctor');
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

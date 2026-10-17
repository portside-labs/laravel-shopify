<?php

namespace App\Console\Commands\Concerns;

use App\Shopify\AppConfiguration;
use Illuminate\Support\Env;

/**
 * Link and serve a git-ignored "shopify.app.dev.toml" in the local environment.
 *
 * Shopify recommends developing against a separate app from the one in
 * production, so "shopify.app.toml" is left for production and local work
 * gets its own configuration, unless SHOPIFY_APP_CONFIG names another one.
 */
trait UsesLocalAppConfiguration
{
    /**
     * Switch to the local configuration when running locally and no other is named.
     */
    protected function useLocalAppConfiguration(AppConfiguration $app): AppConfiguration
    {
        if (! $this->laravel->isLocal() || filled(config('shopify.app_config'))) {
            return $app;
        }

        $environmentFile = $this->laravel->environmentFilePath();

        if (is_file($environmentFile)) {
            Env::writeVariable('SHOPIFY_APP_CONFIG', AppConfiguration::LOCAL, $environmentFile, overwrite: true);
        }

        config(['shopify.app_config' => AppConfiguration::LOCAL]);

        $app = new AppConfiguration(dirname($app->path()).'/'.AppConfiguration::fileName(AppConfiguration::LOCAL));
        $this->laravel->instance(AppConfiguration::class, $app);

        $this->components->info('Using '.AppConfiguration::LOCAL.' for local development: SHOPIFY_APP_CONFIG='.AppConfiguration::LOCAL.' is set in .env, and '.basename($app->path()).' is git-ignored.');

        return $app;
    }
}

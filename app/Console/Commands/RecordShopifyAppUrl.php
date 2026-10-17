<?php

namespace App\Console\Commands;

use App\Shopify\AppConfiguration;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Hidden;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Env;

/**
 * Record the tunnel's URL in .env and the app configuration when the Shopify CLI starts the app.
 *
 * "shopify app dev" only knows the tunnel's URL once it has opened it, and
 * hands it to the app it starts as APP_URL, which outranks the value in
 * .env. Recording it keeps queue workers, Tinker, and anything else started
 * outside the CLI on the same URL, and keeps the app's URLs in the
 * configuration file in step with the ones the CLI gave Shopify. Run from
 * "composer run dev:shopify".
 */
#[Signature('shopify:record-app-url')]
#[Description('Record the Shopify CLI\'s tunnel URL in .env and the app configuration')]
#[Hidden]
class RecordShopifyAppUrl extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(AppConfiguration $app): int
    {
        $url = Env::get('SHOPIFY_DEV_APP_URL') ?? Env::get('APP_URL');

        if (! $this->laravel->isLocal() || ! is_string($url) || ! str_starts_with($url, 'https://')) {
            return self::SUCCESS;
        }

        $url = rtrim($url, '/');

        Env::writeVariable('APP_URL', $url, $this->laravel->environmentFilePath(), overwrite: true);
        $app->pointTo($url);

        $this->components->info('APP_URL and the URLs in '.basename($app->path())." now point at {$url}.");

        return self::SUCCESS;
    }
}

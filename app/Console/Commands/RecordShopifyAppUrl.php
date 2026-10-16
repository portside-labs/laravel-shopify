<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Hidden;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Env;

/**
 * Write the tunnel's URL to .env when the Shopify CLI starts the app.
 *
 * "shopify app dev" only knows the tunnel's URL once it has opened it, and
 * hands it to the app it starts as APP_URL, which outranks the value in
 * .env. Recording it keeps queue workers, Tinker, and anything else started
 * outside the CLI on the same URL. Run from "composer run dev:shopify".
 */
#[Signature('shopify:record-app-url')]
#[Description('Record the Shopify CLI\'s tunnel URL as APP_URL in .env')]
#[Hidden]
class RecordShopifyAppUrl extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $url = Env::get('SHOPIFY_DEV_APP_URL') ?? Env::get('APP_URL');

        if (! $this->laravel->isLocal() || ! is_string($url) || ! str_starts_with($url, 'https://')) {
            return self::SUCCESS;
        }

        Env::writeVariable('APP_URL', rtrim($url, '/'), $this->laravel->environmentFilePath(), overwrite: true);

        $this->components->info("APP_URL is now {$url}.");

        return self::SUCCESS;
    }
}

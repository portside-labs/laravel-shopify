<?php

namespace App\Console\Commands;

use App\Http\Middleware\EnsureShopIsSubscribed;
use App\Shopify\AppConfiguration;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

#[Signature('shopify:doctor')]
#[Description('Check the app\'s Shopify configuration for common mistakes')]
class ShopifyDoctor extends Command
{
    /**
     * The webhook topics every app distributed by Shopify must handle.
     */
    private const REQUIRED_TOPICS = [
        'app/uninstalled',
        'customers/data_request',
        'customers/redact',
        'shop/redact',
    ];

    /**
     * The number of checks that failed.
     */
    private int $failures = 0;

    /**
     * Execute the console command.
     */
    public function handle(AppConfiguration $app): int
    {
        if (! $app->exists()) {
            $this->components->error("The app configuration file [{$app->path()}] does not exist.");

            return self::FAILURE;
        }

        $this->checkCredentials($app);
        $this->checkUrls($app);
        $this->checkApiVersion($app);
        $this->checkWebhooks($app);
        $this->checkBilling();

        $this->newLine();

        if ($this->failures > 0) {
            $this->components->error(trans_choice('{1} :count check failed.|[2,*] :count checks failed.', $this->failures));

            return self::FAILURE;
        }

        $this->components->info('The app is configured correctly.');

        return self::SUCCESS;
    }

    /**
     * Check that the app's credentials are set and agree with the configuration file.
     */
    private function checkCredentials(AppConfiguration $app): void
    {
        $this->section('Credentials');

        $clientId = config('shopify.client_id');

        $this->check('SHOPIFY_API_KEY is set', filled($clientId), 'Run "php artisan shopify:install", or copy the client ID from the Dev Dashboard.');
        $this->check('SHOPIFY_API_SECRET is set', filled(config('shopify.client_secret')), 'Run "php artisan shopify:install", or copy the client secret from the Dev Dashboard.');
        $this->check('shopify.app.toml is linked to an app', $app->clientId() !== null, 'Run "shopify app config link".');

        if (filled($clientId) && $app->clientId() !== null) {
            $this->check('SHOPIFY_API_KEY matches the client_id in shopify.app.toml', $clientId === $app->clientId(), 'The credentials in .env belong to a different app. Run "php artisan shopify:install".');
        }

        $this->check(
            'SHOPIFY_APP_HANDLE matches the handle in shopify.app.toml',
            config('shopify.app_handle') === $app->handle(),
            "Set SHOPIFY_APP_HANDLE={$app->handle()} in .env.",
            warning: true,
        );
    }

    /**
     * Check that the app's URLs have been set.
     */
    private function checkUrls(AppConfiguration $app): void
    {
        $this->section('URLs');

        $this->check(
            'application_url points at the app',
            ! in_array($app->applicationUrl(), [null, 'https://example.com'], true),
            $app->get('build.automatically_update_urls_on_dev')
                ? '"shopify app dev" points it at the tunnel; set your own domain before "shopify app deploy".'
                : 'Set application_url and redirect_urls to your domain in shopify.app.toml.',
            warning: (bool) $app->get('build.automatically_update_urls_on_dev'),
        );
    }

    /**
     * Check that the Admin API version agrees across files and is still supported.
     */
    private function checkApiVersion(AppConfiguration $app): void
    {
        $this->section('Admin API version');

        $version = (string) config('shopify.api_version');

        $this->check(
            "SHOPIFY_API_VERSION ({$version}) matches the webhook api_version in shopify.app.toml",
            $version === $app->webhookApiVersion(),
            'Webhooks will arrive in a different shape than API responses. Use the same version in both.',
            warning: true,
        );

        if (! preg_match('/^\d{4}-\d{2}$/', $version)) {
            return;
        }

        // Shopify supports each stable version for twelve months after its release...
        $supportedUntil = Carbon::createFromFormat('!Y-m', $version)->addYear();

        $this->check(
            "{$version} is supported until {$supportedUntil->toFormattedDateString()}",
            $supportedUntil->isFuture(),
            'Requests fall forward to the oldest supported version. Upgrade to a newer version.',
        );

        if ($supportedUntil->isFuture()) {
            $this->check(
                "{$version} has more than three months of support left",
                $supportedUntil->isAfter(now()->addMonths(3)),
                'Plan the upgrade to a newer version.',
                warning: true,
            );
        }
    }

    /**
     * Check that every subscribed webhook has a job, and every job a subscription.
     */
    private function checkWebhooks(AppConfiguration $app): void
    {
        $this->section('Webhooks');

        $subscriptions = $app->webhookSubscriptions();

        /** @var array<string, string> $jobs */
        $jobs = config('shopify.webhooks', []);

        $topics = array_unique([...self::REQUIRED_TOPICS, ...array_keys($subscriptions), ...array_keys($jobs)]);

        foreach ($topics as $topic) {
            $subscription = $subscriptions[$topic] ?? null;
            $job = $jobs[$topic] ?? null;

            [$fix, $warning] = match (true) {
                $subscription === null && in_array($topic, self::REQUIRED_TOPICS, true) => ["Shopify requires it. Subscribe to {$topic} in shopify.app.toml.", false],
                $subscription !== null && parse_url($subscription['uri'], PHP_URL_PATH) !== '/webhooks' => ['Set its uri to "/webhooks" in shopify.app.toml.', false],
                $job === null => ["Run \"php artisan make:shopify-webhook {$topic}\", or map a job to it in config/shopify.php.", false],
                ! class_exists($job) => ["Create {$job}, or remove it from config/shopify.php.", false],
                $subscription === null => ["Its job never runs. Subscribe to {$topic} in shopify.app.toml.", true],
                default => [null, false],
            };

            $this->check($job ? "{$topic} → ".class_basename($job) : $topic, $fix === null, (string) $fix, $warning);
        }
    }

    /**
     * Check that billing is configured when a route requires a subscription.
     */
    private function checkBilling(): void
    {
        $gated = collect(Route::getRoutes()->getRoutes())->contains(fn (RoutingRoute $route) => collect($route->gatherMiddleware())->contains(
            fn (mixed $middleware) => is_string($middleware) && (str_starts_with($middleware, 'subscribed') || str_starts_with($middleware, EnsureShopIsSubscribed::class)),
        ));

        if (! $gated) {
            return;
        }

        $this->section('Billing');

        foreach ([
            'SHOPIFY_APP_ID' => 'shopify.app_id',
            'SHOPIFY_APP_HANDLE' => 'shopify.app_handle',
            'SHOPIFY_PARTNER_ORGANIZATION_ID' => 'shopify.partner.organization_id',
            'SHOPIFY_PARTNER_ACCESS_TOKEN' => 'shopify.partner.access_token',
        ] as $variable => $key) {
            $this->check("{$variable} is set", filled(config($key)), 'Routes use the "subscribed" middleware, which reads subscriptions from the Partner API.');
        }
    }

    /**
     * Start a group of checks.
     */
    private function section(string $title): void
    {
        $this->newLine();
        $this->line("  <options=bold>{$title}</>");
    }

    /**
     * Report the outcome of a check, with how to fix it if it did not pass.
     */
    private function check(string $description, bool $passes, string $fix, bool $warning = false): void
    {
        $status = match (true) {
            $passes => '<fg=green;options=bold>PASS</>',
            $warning => '<fg=yellow;options=bold>WARN</>',
            default => '<fg=red;options=bold>FAIL</>',
        };

        $this->components->twoColumnDetail($description, $status);

        if (! $passes) {
            $this->line("  <fg=gray>↳ {$fix}</>");
            $this->failures += $warning ? 0 : 1;
        }
    }
}

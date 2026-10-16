<?php

use App\Shopify\AppConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every test file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Mint an ID token like the one App Bridge issues for a staff member of the given shop.
 *
 * @param  array<string, mixed>  $claims
 */
function idToken(
    string $shop,
    array $claims = [],
    string $clientId = 'test-client-id',
    string $clientSecret = 'test-client-secret',
): string {
    $encode = fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');

    $header = $encode((string) json_encode(['alg' => 'HS256', 'typ' => 'JWT']));

    $payload = $encode((string) json_encode([
        'iss' => "https://{$shop}/admin",
        'dest' => "https://{$shop}",
        'aud' => $clientId,
        'sub' => '42',
        'exp' => now()->getTimestamp() + 60,
        'nbf' => now()->getTimestamp(),
        'iat' => now()->getTimestamp(),
        'jti' => (string) Str::uuid(),
        'sid' => Str::random(64),
        ...$claims,
    ]));

    return "{$header}.{$payload}.".$encode(hash_hmac('sha256', "{$header}.{$payload}", $clientSecret, true));
}

/**
 * A Partner API response describing a shop's Shopify App Pricing subscription.
 *
 * @param  array<string, mixed>  $subscription
 * @return array<string, mixed>
 */
function partnerSubscription(array $subscription = []): array
{
    return ['data' => ['activeSubscription' => [
        'trialEndsAt' => null,
        'cancelAtEndOfCycle' => false,
        'currentBillingCycle' => ['endTime' => '2026-10-01T00:00:00Z'],
        'items' => [
            ['handle' => 'usage', 'price' => ['__typename' => 'TieredPrice']],
            ['handle' => 'pro', 'price' => ['__typename' => 'FlatRatePrice']],
        ],
        ...$subscription,
    ]]];
}

/**
 * Write an app configuration file to a temporary directory and use it in place of the app's own.
 *
 * The default is the kit's shopify.app.toml, linked to the test app. It is
 * written out here rather than read from the project, which the Shopify CLI
 * rewrites with the developer's own app when linking it.
 */
function fakeAppConfiguration(?string $toml = null): AppConfiguration
{
    $directory = sys_get_temp_dir().'/laravel-shopify-'.Str::random(12);
    mkdir($directory);

    file_put_contents("{$directory}/shopify.app.toml", $toml ?? <<<'TOML'
        client_id = "test-client-id"
        name = "Laravel Shopify"
        handle = "laravel-shopify"
        application_url = "https://app.example.test"
        embedded = true

        [build]
        automatically_update_urls_on_dev = true
        include_config_on_deploy = true

        [access_scopes]
        scopes = ""
        use_legacy_install_flow = false

        [auth]
        redirect_urls = ["https://app.example.test"]

        [webhooks]
        api_version = "2026-07"

        [[webhooks.subscriptions]]
        topics = ["app/uninstalled", "app/scopes_update"]
        uri = "/webhooks"

        [[webhooks.subscriptions]]
        compliance_topics = [
            "customers/data_request",
            "customers/redact",
            "shop/redact",
        ]
        uri = "/webhooks"
        TOML);

    return tap(new AppConfiguration("{$directory}/shopify.app.toml"), fn (AppConfiguration $app) => app()->instance(AppConfiguration::class, $app));
}

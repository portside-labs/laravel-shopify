<?php

use App\Models\Shop;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;

it('reads the plan from Shopify the first time it is asked, and remembers it', function () {
    $this->freezeSecond();
    config()->set('shopify.api_version', '2026-07');
    $shop = Shop::factory()->create(['shopify_id' => 5678]);
    Http::fake(['partners.shopify.com/12345/api/2026-07/graphql.json' => Http::response(partnerSubscription())]);

    $subscription = $shop->subscription();

    expect($subscription?->plan)->toBe('pro')
        ->and($subscription?->onTrial())->toBeFalse()
        ->and($subscription?->cancelsAtPeriodEnd)->toBeFalse();
    expect($shop->plan)->toBe('pro')
        ->and($shop->current_period_ends_at)->toEqual(Date::parse('2026-10-01T00:00:00Z'))
        ->and($shop->subscription_synced_at)->toEqual(now());
    Http::assertSent(fn (Request $request) => $request->hasHeader('X-Shopify-Access-Token', 'test-partner-token')
        && data_get($request->data(), 'variables.appId') === 'gid://shopify/App/1234'
        && data_get($request->data(), 'variables.shopId') === 'gid://shopify/Shop/5678');
});

it('trusts the plan it remembers for a few minutes', function () {
    $shop = Shop::factory()->subscribed('pro')->create();
    Http::fake();

    expect($shop->subscribed())->toBeTrue()
        ->and($shop->subscribed('pro'))->toBeTrue()
        ->and($shop->subscribed('basic'))->toBeFalse();
    Http::assertNothingSent();
});

it('asks Shopify again once the plan it remembers is old', function () {
    $shop = Shop::factory()->subscribed('pro')->create(['subscription_synced_at' => now()->subMinutes(6)]);
    Http::fake(['partners.shopify.com/*' => Http::response(['data' => ['activeSubscription' => null]])]);

    expect($shop->subscribed())->toBeFalse()
        ->and($shop->refresh()->plan)->toBeNull();
    Http::assertSentCount(1);
});

it('knows when the plan is still on trial', function () {
    $shop = Shop::factory()->subscribed()->create(['trial_ends_at' => now()->addWeek()]);

    expect($shop->onTrial())->toBeTrue();
});

it('learns the shop\'s Shopify ID from the Admin API when it first needs it', function () {
    config()->set('shopify.api_version', '2026-07');
    $shop = Shop::factory()->create(['shopify_id' => null]);
    Http::fake([
        "{$shop->domain}/admin/api/2026-07/graphql.json" => Http::response(['data' => ['shop' => ['id' => 'gid://shopify/Shop/5678']]]),
        'partners.shopify.com/*' => Http::response(partnerSubscription()),
    ]);

    $shop->subscription();

    expect($shop->refresh()->shopify_id)->toBe(5678);
    Http::assertSent(fn (Request $request) => data_get($request->data(), 'variables.shopId') === 'gid://shopify/Shop/5678');
});

it('links to the plan selection page Shopify hosts for the shop', function () {
    config()->set('shopify.app_handle', 'laravel-shopify');
    $shop = Shop::factory()->create(['domain' => 'example.myshopify.com']);

    expect($shop->planSelectionUrl())
        ->toBe('https://admin.shopify.com/store/example/charges/laravel-shopify/pricing_plans');
});

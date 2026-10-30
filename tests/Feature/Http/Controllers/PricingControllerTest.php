<?php

use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

it('opens the plan selection page Shopify hosts and names the current plan', function () {
    config()->set('shopify.app_handle', 'laravel-shopify');
    $user = User::factory()
        ->for(Shop::factory()->subscribed('pro')->state(['domain' => 'example.myshopify.com']))
        ->create();

    $this->actingAs($user)->get(route('pricing'))->assertInertia(fn (Assert $page) => $page
        ->component('pricing')
        ->where('plan_selection_url', 'https://admin.shopify.com/store/example/charges/laravel-shopify/pricing_plans')
        ->where('plan', 'pro')
        ->where('confirming', null)
    );
});

it('sends the merchant on once Shopify confirms the plan they chose, even while the old one is remembered', function () {
    $user = User::factory()->for(Shop::factory()->subscribed('basic'))->create();
    Http::fake(['partners.shopify.com/*' => Http::response(partnerSubscription())]);

    $response = $this->actingAs($user)->get(route('pricing', ['confirming' => 'pro', 'host' => 'YWRtaW4']));

    $response->assertRedirect(route('billing.welcome', ['host' => 'YWRtaW4', 'plan_handle' => 'pro']));
    expect($user->shop->refresh()->plan)->toBe('pro');
    Http::assertSentCount(1);
});

it('keeps the merchant waiting while Shopify does not show the plan they chose', function (array $partnerApi) {
    $user = User::factory()->for(Shop::factory()->subscribed('basic'))->create();
    Http::fake(['partners.shopify.com/*' => Http::response($partnerApi)]);

    $this->actingAs($user)->get(route('pricing', ['confirming' => 'pro']))->assertInertia(fn (Assert $page) => $page
        ->component('pricing')
        ->where('confirming', 'pro')
        ->where('plan', null)
    );
    Http::assertSentCount(1);
})->with([
    'still on the old plan' => [partnerSubscription(['items' => [['handle' => 'basic', 'price' => ['__typename' => 'FlatRatePrice']]]])],
    'no plan yet' => [['data' => ['activeSubscription' => null]]],
]);

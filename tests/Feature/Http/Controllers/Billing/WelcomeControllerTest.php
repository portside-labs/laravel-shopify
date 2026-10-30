<?php

use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\Http;

it('confirms the plan with Shopify and sends the merchant home', function () {
    $user = User::factory()->for(Shop::factory()->state(['shopify_id' => 5678]))->create();
    Http::fake(['partners.shopify.com/*' => Http::response(partnerSubscription())]);

    $response = $this->actingAs($user)->get(route('billing.welcome', [
        'plan_handle' => 'pro',
        'shop' => 'example.myshopify.com',
        'host' => 'YWRtaW4',
        'embedded' => '1',
    ]));

    $response->assertRedirect(route('home', ['shop' => 'example.myshopify.com', 'host' => 'YWRtaW4', 'embedded' => '1']))
        ->assertInertiaFlash('toast', 'Your plan is active.');
    expect($user->shop->refresh()->plan)->toBe('pro');
});

it('moves a merchant who changes plan onto the new one at once', function (string $from, string $to) {
    $user = User::factory()->for(Shop::factory()->subscribed($from))->create();
    Http::fake(['partners.shopify.com/*' => Http::response(partnerSubscription([
        'items' => [['handle' => $to, 'price' => ['__typename' => 'FlatRatePrice']]],
    ]))]);

    $response = $this->actingAs($user)->get(route('billing.welcome', ['plan_handle' => $to]));

    $response->assertRedirect(route('home'))->assertInertiaFlash('toast', 'Your plan is active.');
    expect($user->shop->refresh())
        ->plan->toBe($to)
        ->pending_plan->toBeNull();
})->with([
    'upgrade' => ['basic', 'pro'],
    'downgrade' => ['pro', 'basic'],
]);

it('confirms a move to a cheaper plan that Shopify schedules for the end of the billing cycle', function () {
    $user = User::factory()->for(Shop::factory()->subscribed('pro'))->create();
    Http::fake(['partners.shopify.com/*' => Http::response(partnerSubscription([
        'pendingUpdate' => ['items' => [['handle' => 'basic', 'price' => ['__typename' => 'FlatRatePrice']]]],
    ]))]);

    $response = $this->actingAs($user)->get(route('billing.welcome', ['plan_handle' => 'basic']));

    $response->assertRedirect(route('home'))
        ->assertInertiaFlash('toast', 'You move to the basic plan when this billing cycle ends.');
    expect($user->shop->refresh())
        ->plan->toBe('pro')
        ->pending_plan->toBe('basic');
});

it('keeps the merchant waiting while Shopify still describes the plan they left', function (?string $from, array $partnerApi) {
    $user = User::factory()->for(Shop::factory()->subscribed()->state(['plan' => $from]))->create();
    Http::fake(['partners.shopify.com/*' => Http::response($partnerApi)]);

    $response = $this->actingAs($user)->get(route('billing.welcome', [
        'plan_handle' => 'pro',
        'shop' => 'example.myshopify.com',
        'host' => 'YWRtaW4',
    ]));

    $response->assertRedirect(route('pricing', ['shop' => 'example.myshopify.com', 'host' => 'YWRtaW4', 'confirming' => 'pro']))
        ->assertInertiaFlashMissing('toast');
    expect($user->shop->refresh())
        ->plan->toBe($from)
        ->expectedPlan()->toBe('pro');
})->with([
    'an upgrade' => ['basic', partnerSubscription(['items' => [['handle' => 'basic', 'price' => ['__typename' => 'FlatRatePrice']]]])],
    'a first plan' => [null, ['data' => ['activeSubscription' => null]]],
]);

it('keeps waiting until Shopify drops a scheduled move the merchant cancelled by choosing their plan again', function () {
    $user = User::factory()->for(Shop::factory()->subscribed('pro')->state(['pending_plan' => 'basic']))->create();
    Http::fake(['partners.shopify.com/*' => Http::response(partnerSubscription([
        'pendingUpdate' => ['items' => [['handle' => 'basic', 'price' => ['__typename' => 'FlatRatePrice']]]],
    ]))]);

    $response = $this->actingAs($user)->get(route('billing.welcome', ['plan_handle' => 'pro']));

    $response->assertRedirect(route('pricing', ['confirming' => 'pro']))->assertInertiaFlashMissing('toast');
    expect($user->shop->refresh()->pending_plan)->toBe('basic');
});

it('sends a merchant who chose no plan home without one', function () {
    $user = User::factory()->for(Shop::factory()->subscribed('pro'))->create();
    Http::fake(['partners.shopify.com/*' => Http::response(['data' => ['activeSubscription' => null]])]);

    $response = $this->actingAs($user)->get(route('billing.welcome'));

    $response->assertRedirect(route('home'))->assertInertiaFlashMissing('toast');
    expect($user->shop->refresh()->plan)->toBeNull();
});

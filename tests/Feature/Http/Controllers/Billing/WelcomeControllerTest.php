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

it('sends a merchant who chose no plan home without one', function () {
    $user = User::factory()->for(Shop::factory()->subscribed('pro'))->create();
    Http::fake(['partners.shopify.com/*' => Http::response(['data' => ['activeSubscription' => null]])]);

    $response = $this->actingAs($user)->get(route('billing.welcome'));

    $response->assertRedirect(route('home'))->assertInertiaFlashMissing('toast');
    expect($user->shop->refresh()->plan)->toBeNull();
});

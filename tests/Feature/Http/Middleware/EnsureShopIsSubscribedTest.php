<?php

use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware(['web', 'auth', 'subscribed'])->get('/paid', fn () => 'paid');
    Route::middleware(['web', 'auth', 'subscribed:pro'])->get('/pro', fn () => 'pro');
});

it('lets a subscribed shop through', function () {
    $user = User::factory()->for(Shop::factory()->subscribed())->create();

    $this->actingAs($user)->get('/paid')->assertOk()->assertSee('paid');
});

it('sends a shop without a plan to the pricing page', function () {
    $user = User::factory()->create();
    Http::fake(['partners.shopify.com/*' => Http::response(['data' => ['activeSubscription' => null]])]);

    $this->actingAs($user)->get('/paid')->assertRedirect(route('pricing'));
});

it('requires the plan the route names', function () {
    $user = User::factory()->for(Shop::factory()->subscribed('basic'))->create();

    $this->actingAs($user)->get('/pro')->assertRedirect(route('pricing'));
});

it('carries the parameters Shopify loaded the app with on the redirect', function () {
    $user = User::factory()->create();
    Http::fake(['partners.shopify.com/*' => Http::response(['data' => ['activeSubscription' => null]])]);

    $this->actingAs($user)->get('/paid?shop=example.myshopify.com&host=YWRtaW4&embedded=1&locale=en')
        ->assertRedirect(route('pricing', [
            'shop' => 'example.myshopify.com',
            'host' => 'YWRtaW4',
            'embedded' => '1',
            'locale' => 'en',
        ]));
});

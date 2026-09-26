<?php

use App\Models\Shop;
use App\Models\User;
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
    );
});

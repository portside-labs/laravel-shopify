<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

it('shows the signed-in staff member and their shop', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('home'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('home')
        ->where('auth.user.name', $user->name)
        ->where('auth.shop.domain', $user->shop->domain)
        ->missing('store')
    );
});

it('loads the store details from the Admin API after the page has rendered', function () {
    config()->set('shopify.api_version', '2026-07');
    $user = User::factory()->create();
    Http::fake([
        "{$user->shop->domain}/admin/api/2026-07/graphql.json" => Http::response([
            'data' => ['shop' => ['name' => 'Example Store', 'email' => 'owner@example.com', 'currencyCode' => 'USD']],
        ]),
    ]);

    $response = $this->actingAs($user)->get(route('home'));

    $response->assertInertia(fn (Assert $page) => $page
        ->loadDeferredProps(fn (Assert $reload) => $reload
            ->where('store.name', 'Example Store')
            ->where('store.currencyCode', 'USD')
        )
    );
});

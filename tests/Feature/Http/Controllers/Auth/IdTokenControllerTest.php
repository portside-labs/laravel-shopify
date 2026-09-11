<?php

it('renders the App Bridge bounce page for pages opened inside the admin', function () {
    $response = $this->get(route('auth.id-token', [
        'shop' => 'example.myshopify.com',
        'embedded' => '1',
        'shopify-reload' => 'http://localhost/',
    ]));

    $response->assertOk()
        ->assertSee('<meta name="shopify-api-key" content="test-client-id">', false)
        ->assertSee('https://cdn.shopify.com/shopifycloud/app-bridge.js');
});

it('sends pages opened outside of the admin to the app inside the admin', function () {
    $response = $this->get(route('auth.id-token', ['shop' => 'example.myshopify.com']));

    $response->assertRedirect('https://admin.shopify.com/store/example/apps/test-client-id');
});

it('does not redirect to shops that are not Shopify stores', function (string $shop) {
    $response = $this->get(route('auth.id-token', ['shop' => $shop]));

    $response->assertOk();
})->with([
    'another domain' => 'evil.example.com',
    'a lookalike domain' => 'example.myshopify.com.evil.com',
    'a domain with a path' => 'example.myshopify.com/admin',
]);

it('tells merchants to open the app from the admin when there is no shop', function () {
    $response = $this->get(route('auth.id-token'));

    $response->assertOk()->assertSee('from your Shopify admin');
});

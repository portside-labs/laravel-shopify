<?php

use App\Models\User;

it('allows the shop and the Shopify admin to frame pages for a signed-in staff member', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('home'));

    $response->assertHeader(
        'Content-Security-Policy',
        "frame-ancestors https://{$user->shop->domain} https://admin.shopify.com;",
    );
});

it('allows the shop named in the request to frame pages for guests', function () {
    $response = $this->get(route('auth.id-token', ['shop' => 'example.myshopify.com', 'embedded' => '1']));

    $response->assertHeader(
        'Content-Security-Policy',
        'frame-ancestors https://example.myshopify.com https://admin.shopify.com;',
    );
});

it('allows any Shopify store to frame pages without a known shop', function (array $query) {
    $response = $this->get(route('auth.id-token', $query));

    $response->assertHeader(
        'Content-Security-Policy',
        'frame-ancestors https://*.myshopify.com https://admin.shopify.com;',
    );
})->with([
    'no shop' => [[]],
    'a shop that is not a Shopify store' => [['shop' => 'evil.example.com', 'embedded' => '1']],
]);

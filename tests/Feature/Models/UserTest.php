<?php

use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('queries the Admin API as the staff member', function () {
    config()->set('shopify.api_version', '2026-07');
    $user = User::factory()->create();
    Http::fake([
        "{$user->shop->domain}/admin/api/2026-07/graphql.json" => Http::response(['data' => ['shop' => ['name' => 'Example']]]),
    ]);

    $response = $user->api()->graphql('query { shop { name } }');

    expect($response->json('data.shop.name'))->toBe('Example');
    Http::assertSent(fn (Request $request) => $request->url() === "https://{$user->shop->domain}/admin/api/2026-07/graphql.json"
        && $request->hasHeader('X-Shopify-Access-Token', $user->access_token));
});

it('cannot query the Admin API once their access token has expired', function () {
    $user = User::factory()->expired()->create();

    expect(fn () => $user->api())->toThrow(LogicException::class);
});

test('the name is the first and last name', function () {
    $user = User::factory()->make(['first_name' => 'Jane', 'last_name' => 'Doe']);

    expect($user->name)->toBe('Jane Doe');
});

<?php

use App\Models\Shop;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('queries the Admin API as the shop', function (array $variables, string $body) {
    config()->set('shopify.api_version', '2026-07');
    $shop = Shop::factory()->create();
    Http::fake([
        "{$shop->domain}/admin/api/2026-07/graphql.json" => Http::response(['data' => ['shop' => ['name' => 'Example']]]),
    ]);

    $response = $shop->api()->graphql('query { shop { name } }', $variables);

    expect($response->json('data.shop.name'))->toBe('Example');
    Http::assertSent(fn (Request $request) => $request->url() === "https://{$shop->domain}/admin/api/2026-07/graphql.json"
        && $request->hasHeader('X-Shopify-Access-Token', $shop->access_token)
        && $request->body() === $body);
})->with([
    'with variables' => [['first' => 1], '{"query":"query { shop { name } }","variables":{"first":1}}'],
    'without variables' => [[], '{"query":"query { shop { name } }","variables":{}}'],
]);

it('cannot query the Admin API once the app has been uninstalled', function () {
    $shop = Shop::factory()->uninstalled()->create();

    expect(fn () => $shop->api())->toThrow(LogicException::class);
});

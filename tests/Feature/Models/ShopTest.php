<?php

use App\Exceptions\AccessTokenRevokedException;
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
    Http::assertSentCount(1);
})->with([
    'with variables' => [['first' => 1], '{"query":"query { shop { name } }","variables":{"first":1}}'],
    'without variables' => [[], '{"query":"query { shop { name } }","variables":{}}'],
]);

it('cannot query the Admin API once the app has been uninstalled', function () {
    $shop = Shop::factory()->uninstalled()->create();

    expect(fn () => $shop->api())->toThrow(LogicException::class);
});

it('refreshes an expiring access token before querying the Admin API', function () {
    $this->freezeSecond();
    config()->set('shopify.api_version', '2026-07');
    $shop = Shop::factory()->expiring()->create();
    $refreshToken = $shop->refresh_token;
    Http::fake([
        "{$shop->domain}/admin/oauth/access_token" => Http::response([
            'access_token' => 'shpat_refreshed',
            'scope' => 'read_products',
            'expires_in' => 3600,
            'refresh_token' => 'refresh-2',
            'refresh_token_expires_in' => 7776000,
        ]),
        "{$shop->domain}/admin/api/2026-07/graphql.json" => Http::response(['data' => ['shop' => ['name' => 'Example']]]),
    ]);

    $shop->api()->graphql('query { shop { name } }');

    expect($shop->access_token)->toBe('shpat_refreshed')
        ->and($shop->access_token_expires_at)->toEqual(now()->addHour())
        ->and($shop->refresh_token)->toBe('refresh-2')
        ->and($shop->refresh_token_expires_at)->toEqual(now()->addDays(90));
    Http::assertSentInOrder([
        fn (Request $request) => $request->url() === "https://{$shop->domain}/admin/oauth/access_token"
            && $request['client_id'] === 'test-client-id'
            && $request['client_secret'] === 'test-client-secret'
            && $request['grant_type'] === 'refresh_token'
            && $request['refresh_token'] === $refreshToken,
        fn (Request $request) => $request->hasHeader('X-Shopify-Access-Token', 'shpat_refreshed'),
    ]);
});

it('forgets its tokens when Shopify rejects the refresh token', function () {
    $shop = Shop::factory()->expiring()->create();
    Http::fake([
        "{$shop->domain}/admin/oauth/access_token" => Http::response([
            'error' => 'invalid_request',
            'error_description' => 'This request requires an active refresh_token',
        ], 401),
    ]);

    expect(fn () => $shop->api())
        ->toThrow(AccessTokenRevokedException::class, 'This request requires an active refresh_token');

    $shop->refresh();
    expect($shop->isInstalled())->toBeFalse()
        ->and($shop->refresh_token)->toBeNull()
        ->and($shop->uninstalled_at)->toBeNull();
});

it('forgets its tokens when Shopify rejects the access token', function () {
    config()->set('shopify.api_version', '2026-07');
    $shop = Shop::factory()->create();
    Http::fake([
        "{$shop->domain}/admin/api/2026-07/graphql.json" => Http::response([
            'errors' => '[API] Invalid API key or access token (unrecognized login or wrong password)',
        ], 401),
    ]);

    expect(fn () => $shop->api()->graphql('query { shop { name } }'))
        ->toThrow(AccessTokenRevokedException::class, '[API] Invalid API key or access token (unrecognized login or wrong password)');

    $shop->refresh();
    expect($shop->isInstalled())->toBeFalse()
        ->and($shop->refresh_token)->toBeNull()
        ->and($shop->uninstalled_at)->toBeNull();
});

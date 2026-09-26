<?php

use App\Shopify\PartnerApi;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('sends queries to the organization\'s Partner API endpoint', function () {
    config()->set('shopify.api_version', '2026-07');
    Http::fake(['partners.shopify.com/12345/api/2026-07/graphql.json' => Http::response(['data' => ['app' => ['id' => 'gid://shopify/App/1234']]])]);

    $data = app(PartnerApi::class)->graphql('query { app { id } }');

    expect($data)->toBe(['app' => ['id' => 'gid://shopify/App/1234']]);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://partners.shopify.com/12345/api/2026-07/graphql.json'
        && $request->hasHeader('X-Shopify-Access-Token', 'test-partner-token')
        && $request['query'] === 'query { app { id } }');
});

it('refuses to run without Partner API credentials', function () {
    config()->set('shopify.partner.access_token', null);

    expect(fn () => app(PartnerApi::class)->graphql('query { app { id } }'))
        ->toThrow(LogicException::class, 'SHOPIFY_PARTNER_ACCESS_TOKEN');
});

it('reports refusals that Shopify answers with a 200', function () {
    Http::fake(['partners.shopify.com/*' => Http::response(['errors' => [['message' => 'Throttled']]])]);

    expect(fn () => app(PartnerApi::class)->graphql('query { app { id } }'))
        ->toThrow(RuntimeException::class, 'Throttled');
});

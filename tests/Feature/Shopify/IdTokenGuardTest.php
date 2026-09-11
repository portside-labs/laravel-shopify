<?php

use App\Events\ShopInstalled;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A token exchange response granting an expiring offline access token to a shop.
 *
 * @return array<string, mixed>
 */
function offlineAccessToken(): array
{
    return [
        'access_token' => 'shpat_offline',
        'scope' => 'read_products,write_products',
        'expires_in' => 3600,
        'refresh_token' => 'refresh-1',
        'refresh_token_expires_in' => 7776000,
    ];
}

/**
 * A token exchange response granting an online access token to a staff member.
 *
 * @return array<string, mixed>
 */
function onlineAccessToken(array $user = []): array
{
    return [
        'access_token' => 'shpua_online',
        'scope' => 'read_products,write_products',
        'expires_in' => 86399,
        'associated_user_scope' => 'read_products',
        'associated_user' => [
            'id' => 42,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
            'email_verified' => true,
            'account_owner' => true,
            'locale' => 'en',
            'collaborator' => false,
            ...$user,
        ],
    ];
}

it('authenticates the staff member from the bearer token App Bridge sends', function () {
    $user = User::factory()->create();

    $response = $this->withToken(idToken($user->shop->domain, ['sub' => (string) $user->shopify_id]))
        ->get(route('home'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('home')
        ->where('auth.user.id', $user->id)
        ->where('auth.shop.id', $user->shop_id)
    );
});

it('authenticates the staff member from the token Shopify adds to the URL when opening the app', function () {
    $user = User::factory()->create();

    $response = $this->get(route('home', [
        'id_token' => idToken($user->shop->domain, ['sub' => (string) $user->shopify_id]),
    ]));

    $response->assertInertia(fn (Assert $page) => $page->where('auth.user.id', $user->id));
});

it('installs the shop and identifies the staff member the first time the app is opened', function () {
    $this->freezeSecond();
    Event::fake([ShopInstalled::class]);
    Http::fake([
        'example.myshopify.com/admin/oauth/access_token' => Http::sequence()
            ->push(offlineAccessToken())
            ->push(onlineAccessToken()),
    ]);
    $jwt = idToken('example.myshopify.com', ['sub' => '42']);

    $response = $this->withToken($jwt)->get(route('home'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('auth.user.email', 'jane@example.com')
        ->where('auth.shop.domain', 'example.myshopify.com')
    );
    $shop = Shop::where('domain', 'example.myshopify.com')->sole();
    expect($shop->access_token)->toBe('shpat_offline')
        ->and($shop->access_token_expires_at)->toEqual(now()->addHour())
        ->and($shop->refresh_token)->toBe('refresh-1')
        ->and($shop->refresh_token_expires_at)->toEqual(now()->addDays(90))
        ->and($shop->scopes)->toBe(['read_products', 'write_products'])
        ->and($shop->installed_at)->toEqual(now());
    $user = $shop->users()->sole();
    expect($user->shopify_id)->toBe(42)
        ->and($user->name)->toBe('Jane Doe')
        ->and($user->account_owner)->toBeTrue()
        ->and($user->scopes)->toBe(['read_products'])
        ->and($user->access_token)->toBe('shpua_online')
        ->and($user->hasValidAccessToken())->toBeTrue();
    Event::assertDispatched(ShopInstalled::class, fn (ShopInstalled $event) => $event->shop->is($shop));
    Http::assertSentInOrder([
        fn (Request $request) => $request->url() === 'https://example.myshopify.com/admin/oauth/access_token'
            && $request['client_id'] === 'test-client-id'
            && $request['client_secret'] === 'test-client-secret'
            && $request['grant_type'] === 'urn:ietf:params:oauth:grant-type:token-exchange'
            && $request['subject_token'] === $jwt
            && $request['subject_token_type'] === 'urn:ietf:params:oauth:token-type:id_token'
            && $request['requested_token_type'] === 'urn:shopify:params:oauth:token-type:offline-access-token'
            && $request['expiring'] === '1',
        fn (Request $request) => $request['requested_token_type'] === 'urn:shopify:params:oauth:token-type:online-access-token',
    ]);
});

it('installs the app again on a shop that had uninstalled it', function () {
    $shop = Shop::factory()->uninstalled()->create();
    Http::fake([
        "{$shop->domain}/admin/oauth/access_token" => Http::sequence()
            ->push(offlineAccessToken())
            ->push(onlineAccessToken()),
    ]);

    $this->withToken(idToken($shop->domain))->get(route('home'))->assertOk();

    $shop->refresh();
    expect($shop->isInstalled())->toBeTrue()
        ->and($shop->access_token)->toBe('shpat_offline')
        ->and($shop->uninstalled_at)->toBeNull();
});

it('refreshes an expiring access token when the merchant opens the app', function () {
    $this->freezeSecond();
    $user = User::factory()->for(Shop::factory()->expiring())->create();
    $refreshToken = $user->shop->refresh_token;
    Http::fake([
        "{$user->shop->domain}/admin/oauth/access_token" => Http::response([
            'access_token' => 'shpat_refreshed',
            'scope' => 'read_products',
            'expires_in' => 3600,
            'refresh_token' => 'refresh-2',
            'refresh_token_expires_in' => 7776000,
        ]),
    ]);

    $this->withToken(idToken($user->shop->domain, ['sub' => (string) $user->shopify_id]))
        ->get(route('home'))
        ->assertOk();

    $shop = $user->shop->refresh();
    expect($shop->access_token)->toBe('shpat_refreshed')
        ->and($shop->access_token_expires_at)->toEqual(now()->addHour())
        ->and($shop->refresh_token)->toBe('refresh-2');
    Http::assertSent(fn (Request $request) => $request['grant_type'] === 'refresh_token'
        && $request['refresh_token'] === $refreshToken);
    Http::assertSentCount(1);
});

it('installs the app again when Shopify rejects the refresh token', function () {
    $this->freezeSecond();
    $user = User::factory()->for(Shop::factory()->expiring())->create();
    Event::fake([ShopInstalled::class]);
    Http::fake([
        "{$user->shop->domain}/admin/oauth/access_token" => Http::sequence()
            ->push(['error' => 'invalid_request', 'error_description' => 'This request requires an active refresh_token'], 401)
            ->push(offlineAccessToken()),
    ]);

    $this->withToken(idToken($user->shop->domain, ['sub' => (string) $user->shopify_id]))
        ->get(route('home'))
        ->assertOk();

    $shop = $user->shop->refresh();
    expect($shop->access_token)->toBe('shpat_offline')
        ->and($shop->refresh_token)->toBe('refresh-1')
        ->and($shop->installed_at)->toEqual(now());
    Event::assertDispatched(ShopInstalled::class, fn (ShopInstalled $event) => $event->shop->is($shop));
    Http::assertSentInOrder([
        fn (Request $request) => $request['grant_type'] === 'refresh_token',
        fn (Request $request) => $request['requested_token_type'] === 'urn:shopify:params:oauth:token-type:offline-access-token',
    ]);
});

it('refreshes the staff member from Shopify once their access token has expired', function () {
    $user = User::factory()->expired()->create(['first_name' => 'Old', 'last_name' => 'Name']);
    Http::fake([
        "{$user->shop->domain}/admin/oauth/access_token" => Http::response(onlineAccessToken(['id' => $user->shopify_id])),
    ]);

    $this->withToken(idToken($user->shop->domain, ['sub' => (string) $user->shopify_id]))
        ->get(route('home'))
        ->assertOk();

    $user->refresh();
    expect($user->name)->toBe('Jane Doe')
        ->and($user->access_token)->toBe('shpua_online')
        ->and($user->hasValidAccessToken())->toBeTrue();
    Http::assertSentCount(1);
});

it('does not exchange tokens for a shop and staff member that are current', function () {
    $user = User::factory()->create();
    Http::fake();

    $this->withToken(idToken($user->shop->domain, ['sub' => (string) $user->shopify_id]))
        ->get(route('home'))
        ->assertOk();

    Http::assertNothingSent();
});

it('treats an ID token that Shopify refuses to exchange as unauthenticated', function () {
    Http::fake([
        'example.myshopify.com/admin/oauth/access_token' => Http::response([
            'error' => 'invalid_subject_token',
            'error_description' => 'The subject token is invalid.',
        ], 400),
    ]);

    $response = $this->withToken(idToken('example.myshopify.com'))->get(route('home'));

    $response->assertRedirect(route('auth.id-token', ['shopify-reload' => 'http://localhost']));
    $this->assertDatabaseMissing('shops', ['domain' => 'example.myshopify.com']);
});

it('rejects tampered ID tokens', function () {
    $user = User::factory()->create();

    $response = $this->withToken(idToken($user->shop->domain, clientSecret: 'other-secret'))->get(route('home'));

    $response->assertRedirect(route('auth.id-token', ['shopify-reload' => 'http://localhost']));
});

it('bounces pages opened without a valid ID token through App Bridge', function () {
    $response = $this->get('/?shop=example.myshopify.com&host=YWRtaW4&embedded=1&id_token=expired');

    $response->assertRedirect(route('auth.id-token', [
        'shop' => 'example.myshopify.com',
        'host' => 'YWRtaW4',
        'embedded' => '1',
        'shopify-reload' => 'http://localhost/?shop=example.myshopify.com&host=YWRtaW4&embedded=1',
    ]));
});

it('asks the app to retry with a fresh ID token when a request is not authenticated', function (array $headers) {
    $response = $this->withHeaders($headers)->get(route('home'));

    $response->assertUnauthorized()
        ->assertHeader('X-Shopify-Retry-Invalid-Session-Request', '1');
})->with([
    'an Inertia request' => [['X-Inertia' => 'true']],
    'a JSON request' => [['Accept' => 'application/json']],
]);

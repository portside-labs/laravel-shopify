<?php

use App\Jobs\Webhooks\AppUninstalled;
use App\Models\Shop;
use Illuminate\Support\Facades\Queue;

/**
 * The headers Shopify sends with a webhook, signed with the given secret.
 *
 * @param  array<string, mixed>  $payload
 * @return array<string, string>
 */
function webhookHeaders(string $topic, string $shop, array $payload, string $secret = 'test-client-secret'): array
{
    return [
        'X-Shopify-Topic' => $topic,
        'X-Shopify-Shop-Domain' => $shop,
        'X-Shopify-Hmac-Sha256' => base64_encode(hash_hmac('sha256', (string) json_encode($payload), $secret, true)),
    ];
}

it('dispatches the job registered for the topic', function () {
    $shop = Shop::factory()->create();
    Queue::fake([AppUninstalled::class]);
    $payload = ['id' => 1, 'domain' => $shop->domain];

    $response = $this->postJson(route('webhooks'), $payload, webhookHeaders('app/uninstalled', $shop->domain, $payload));

    $response->assertNoContent();
    Queue::assertPushed(AppUninstalled::class, fn (AppUninstalled $job) => $job->shop->is($shop) && $job->payload === $payload);
});

it('rejects webhooks that are not signed with the client secret', function (array $headers) {
    $shop = Shop::factory()->create();
    Queue::fake();

    $response = $this->postJson(route('webhooks'), ['id' => 1], $headers + [
        'X-Shopify-Topic' => 'app/uninstalled',
        'X-Shopify-Shop-Domain' => $shop->domain,
    ]);

    $response->assertUnauthorized();
    Queue::assertNothingPushed();
})->with([
    'no signature' => [[]],
    'a signature from another secret' => [[
        'X-Shopify-Hmac-Sha256' => base64_encode(hash_hmac('sha256', (string) json_encode(['id' => 1]), 'other-secret', true)),
    ]],
]);

it('acknowledges topics without a registered job', function () {
    $shop = Shop::factory()->create();
    Queue::fake();
    $payload = ['id' => 1];

    $response = $this->postJson(route('webhooks'), $payload, webhookHeaders('orders/create', $shop->domain, $payload));

    $response->assertNoContent();
    Queue::assertNothingPushed();
});

it('acknowledges webhooks for shops it does not know', function () {
    Queue::fake();
    $payload = ['id' => 1];

    $response = $this->postJson(route('webhooks'), $payload, webhookHeaders('app/uninstalled', 'unknown.myshopify.com', $payload));

    $response->assertNoContent();
    Queue::assertNothingPushed();
});

<?php

use App\Jobs\Webhooks\AppScopesUpdate;
use App\Jobs\Webhooks\CustomersRedact;
use App\Jobs\Webhooks\ShopRedact;
use App\Models\Shop;
use Illuminate\Support\Facades\Queue;

it('delivers a signed webhook to the app, which dispatches its job', function () {
    $shop = Shop::factory()->create(['scopes' => ['read_products']]);
    Queue::fake();

    $this->artisan('shopify:webhook', ['topic' => 'app/scopes_update', 'shop' => $shop->domain])
        ->expectsOutputToContain("Delivered app/scopes_update for {$shop->domain} and queued its job.")
        ->assertSuccessful();

    Queue::assertPushed(AppScopesUpdate::class, fn (AppScopesUpdate $job) => $job->shop->is($shop) && $job->payload['current'] === 'read_products');
});

it('sends the webhook for the only installed shop when none is given', function () {
    $shop = Shop::factory()->create();
    Queue::fake();

    $this->artisan('shopify:webhook', ['topic' => 'customers/redact'])->assertSuccessful();

    Queue::assertPushed(CustomersRedact::class, fn (CustomersRedact $job) => $job->shop->is($shop));
});

it('sends a payload read from a file', function () {
    $shop = Shop::factory()->create();
    $path = tempnam(sys_get_temp_dir(), 'payload');
    file_put_contents($path, json_encode(['customer' => ['id' => 42]]));
    Queue::fake();

    $this->artisan('shopify:webhook', ['topic' => 'customers/redact', 'shop' => $shop->domain, '--payload' => $path])->assertSuccessful();

    Queue::assertPushed(CustomersRedact::class, fn (CustomersRedact $job) => $job->payload === ['customer' => ['id' => 42]]);
});

it('runs the job right away when asked to', function () {
    $shop = Shop::factory()->create(['scopes' => ['read_products']]);

    $this->artisan('shopify:webhook', ['topic' => 'app/scopes_update', 'shop' => $shop->domain, '--sync' => true])
        ->expectsOutputToContain('and ran its job.')
        ->assertSuccessful();
});

it('asks before sending a webhook whose job removes the shop\'s data', function () {
    $shop = Shop::factory()->create();
    Queue::fake();

    $this->artisan('shopify:webhook', ['topic' => 'shop/redact', 'shop' => $shop->domain])
        ->expectsConfirmation("The shop/redact job deletes the shop and its users for {$shop->domain}. Send it anyway?", 'no')
        ->assertFailed();

    Queue::assertNotPushed(ShopRedact::class);
});

it('fails for a shop that has not installed the app', function () {
    $this->artisan('shopify:webhook', ['topic' => 'app/uninstalled', 'shop' => 'unknown.myshopify.com'])
        ->expectsOutputToContain('The shop unknown.myshopify.com has not installed the app.')
        ->assertFailed();
});

it('warns when no job handles the topic', function () {
    $shop = Shop::factory()->create();

    $this->artisan('shopify:webhook', ['topic' => 'orders/create', 'shop' => $shop->domain])
        ->expectsOutputToContain('No job handles orders/create')
        ->assertSuccessful();
});

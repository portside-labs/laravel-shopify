<?php

use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->travelTo('2026-09-26');
});

it('passes a correctly configured app', function () {
    fakeAppConfiguration();

    $this->artisan('shopify:doctor')
        ->expectsOutputToContain('The app is configured correctly.')
        ->assertSuccessful();
});

it('fails when the credentials belong to a different app', function () {
    fakeAppConfiguration();
    config()->set('shopify.client_id', 'another-client-id');

    $this->artisan('shopify:doctor')
        ->expectsOutputToContain('The credentials in .env belong to a different app.')
        ->assertFailed();
});

it('fails when the configuration file is not linked to an app', function () {
    fakeAppConfiguration(str_replace('client_id = "test-client-id"', 'client_id = ""', (string) file_get_contents(fakeAppConfiguration()->path())));

    $this->artisan('shopify:doctor')
        ->expectsOutputToContain('Run "shopify app config link".')
        ->assertFailed();
});

it('fails when a subscribed topic has no job', function () {
    fakeAppConfiguration()->subscribeToWebhook('orders/create');

    $this->artisan('shopify:doctor')
        ->expectsOutputToContain('Run "php artisan make:shopify-webhook orders/create"')
        ->assertFailed();
});

it('fails when a mandatory compliance topic is not subscribed', function () {
    fakeAppConfiguration(str_replace('"shop/redact",', '', (string) file_get_contents(fakeAppConfiguration()->path())));

    $this->artisan('shopify:doctor')
        ->expectsOutputToContain('Shopify requires it. Subscribe to shop/redact in shopify.app.toml.')
        ->assertFailed();
});

it('warns without failing when a job has no subscription', function () {
    fakeAppConfiguration();
    config()->set('shopify.webhooks.orders/create', stdClass::class);

    $this->artisan('shopify:doctor')
        ->expectsOutputToContain('Its job never runs.')
        ->assertSuccessful();
});

it('fails when the Admin API version is no longer supported', function () {
    fakeAppConfiguration();
    config()->set('shopify.api_version', '2025-07');

    $this->artisan('shopify:doctor')
        ->expectsOutputToContain('Upgrade to a newer version.')
        ->assertFailed();
});

it('fails when a route requires a subscription and the Partner API is not configured', function () {
    fakeAppConfiguration();
    config()->set('shopify.partner.access_token', null);
    Route::get('/reports', fn () => 'Reports')->middleware(['auth', 'subscribed:pro']);

    $this->artisan('shopify:doctor')
        ->expectsOutputToContain('SHOPIFY_PARTNER_ACCESS_TOKEN is set')
        ->assertFailed();
});

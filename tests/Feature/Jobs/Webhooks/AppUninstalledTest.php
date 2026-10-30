<?php

use App\Events\ShopUninstalled;
use App\Jobs\Webhooks\AppUninstalled;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

it('forgets the access tokens of the shop and its staff', function () {
    $user = User::factory()->create();
    Event::fake([ShopUninstalled::class]);

    (new AppUninstalled($user->shop, ['id' => 1]))->handle();

    $shop = $user->shop->refresh();
    expect($shop->isInstalled())->toBeFalse()
        ->and($shop->refresh_token)->toBeNull()
        ->and($shop->uninstalled_at)->not->toBeNull();
    $user->refresh();
    expect($user->access_token)->toBeNull()
        ->and($user->hasValidAccessToken())->toBeFalse();
    Event::assertDispatched(ShopUninstalled::class, fn (ShopUninstalled $event) => $event->shop->is($shop));
});

it('forgets the plan Shopify last described, so a reinstall asks again', function () {
    $shop = Shop::factory()->subscribed('pro')->create(['trial_ends_at' => now()->addWeek(), 'cancels_at_period_end' => true]);
    Event::fake([ShopUninstalled::class]);
    Http::fake(['partners.shopify.com/*' => Http::response(['data' => ['activeSubscription' => null]])]);

    (new AppUninstalled($shop, ['id' => 1]))->handle();

    $shop->refresh();
    expect($shop->plan)->toBeNull()
        ->and($shop->trial_ends_at)->toBeNull()
        ->and($shop->current_period_ends_at)->toBeNull()
        ->and($shop->cancels_at_period_end)->toBeFalse()
        ->and($shop->subscription_synced_at)->toBeNull()
        ->and($shop->subscribed())->toBeFalse();
    Http::assertSentCount(1);
});

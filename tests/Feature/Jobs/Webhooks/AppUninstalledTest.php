<?php

use App\Events\ShopUninstalled;
use App\Jobs\Webhooks\AppUninstalled;
use App\Models\User;
use Illuminate\Support\Facades\Event;

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

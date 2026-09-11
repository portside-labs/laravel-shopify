<?php

use App\Jobs\Webhooks\ShopRedact;
use App\Models\User;

it('erases the shop and its staff', function () {
    $user = User::factory()->create();

    (new ShopRedact($user->shop, ['shop_id' => 1, 'shop_domain' => $user->shop->domain]))->handle();

    $this->assertModelMissing($user->shop);
    $this->assertModelMissing($user);
});

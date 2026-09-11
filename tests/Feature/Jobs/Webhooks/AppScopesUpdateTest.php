<?php

use App\Jobs\Webhooks\AppScopesUpdate;
use App\Models\Shop;

it('records the scopes the merchant has granted', function () {
    $shop = Shop::factory()->create(['scopes' => ['read_products']]);

    (new AppScopesUpdate($shop, [
        'previous' => 'read_products',
        'current' => 'read_products,write_products',
        'updated_at' => '2026-09-11T00:00:00+00:00',
    ]))->handle();

    expect($shop->refresh()->scopes)->toBe(['read_products', 'write_products']);
});

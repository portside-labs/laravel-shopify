<?php

namespace App\Jobs\Webhooks;

use App\Models\Shop;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Handle the "shop/redact" compliance webhook.
 *
 * Shopify sends this webhook 48 hours after a merchant uninstalls the app.
 * Everything the app stored about the shop must be erased. Deleting the shop
 * cascades to its users; extend this job as the app stores more.
 *
 * @see https://shopify.dev/docs/apps/build/compliance/privacy-law-compliance
 */
class ShopRedact implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     *
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public Shop $shop,
        public array $payload,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->shop->delete();
    }
}

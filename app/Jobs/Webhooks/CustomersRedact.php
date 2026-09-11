<?php

namespace App\Jobs\Webhooks;

use App\Models\Shop;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Handle the "customers/redact" compliance webhook.
 *
 * A customer has asked the merchant to erase their data. If the app stores
 * customer data, delete it within thirty days. The payload identifies the
 * customer and the orders whose data should be redacted.
 *
 * @see https://shopify.dev/docs/apps/build/compliance/privacy-law-compliance
 */
class CustomersRedact implements ShouldQueue
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
        // This app does not store any customer data yet...
    }
}

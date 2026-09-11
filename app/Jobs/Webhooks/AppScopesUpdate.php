<?php

namespace App\Jobs\Webhooks;

use App\Models\Shop;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Handle the "app/scopes_update" webhook.
 *
 * Shopify sends this webhook when the merchant grants or revokes access
 * scopes, for example after the app starts requesting a new scope.
 */
class AppScopesUpdate implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     *
     * @param  array{current: string}  $payload
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
        $this->shop->forceFill([
            'scopes' => array_values(array_filter(explode(',', $this->payload['current']))),
        ])->save();
    }
}

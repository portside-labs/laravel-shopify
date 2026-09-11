<?php

namespace App\Jobs\Webhooks;

use App\Models\Shop;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Handle the "app/uninstalled" webhook.
 */
class AppUninstalled implements ShouldQueue
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
        $this->shop->uninstall();
    }
}

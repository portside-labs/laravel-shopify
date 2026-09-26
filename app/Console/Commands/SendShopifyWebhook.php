<?php

namespace App\Console\Commands;

use App\Models\Shop;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

use function Laravel\Prompts\select;

#[Signature('shopify:webhook
    {topic : The webhook topic, such as "app/uninstalled"}
    {shop? : The domain of the shop the webhook is sent for}
    {--payload= : A JSON file to send instead of the sample payload}
    {--sync : Run the job now instead of queueing it}')]
#[Description('Send a signed webhook to the app, as Shopify would deliver it')]
class SendShopifyWebhook extends Command
{
    /**
     * The topics whose jobs remove the shop's data.
     */
    private const DESTRUCTIVE_TOPICS = [
        'app/uninstalled' => 'forgets the shop\'s access tokens',
        'shop/redact' => 'deletes the shop and its users',
    ];

    /**
     * Execute the console command.
     */
    public function handle(Kernel $kernel): int
    {
        $topic = (string) $this->argument('topic');

        if (! $shop = $this->shop()) {
            return self::FAILURE;
        }

        if (($payload = $this->payload($topic, $shop)) === null) {
            return self::FAILURE;
        }

        if (! config("shopify.webhooks.{$topic}")) {
            $this->components->warn("No job handles {$topic}, so the app will acknowledge and ignore it. Create one with \"php artisan make:shopify-webhook {$topic}\".");
        }

        if (isset(self::DESTRUCTIVE_TOPICS[$topic]) && ! $this->confirm("The {$topic} job ".self::DESTRUCTIVE_TOPICS[$topic]." for {$shop->domain}. Send it anyway?")) {
            return self::FAILURE;
        }

        if ($this->option('sync')) {
            config(['queue.default' => 'sync']);
        }

        // The webhook is handled in this process, so it reaches the app without a tunnel or a running server...
        $content = (string) json_encode($payload);

        $request = Request::create(route('webhooks', absolute: false), 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SHOPIFY_TOPIC' => $topic,
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => $shop->domain,
            'HTTP_X_SHOPIFY_API_VERSION' => config('shopify.api_version'),
            'HTTP_X_SHOPIFY_WEBHOOK_ID' => (string) Str::uuid(),
            'HTTP_X_SHOPIFY_TRIGGERED_AT' => now()->toIso8601ZuluString(),
            'HTTP_X_SHOPIFY_HMAC_SHA256' => base64_encode(hash_hmac('sha256', $content, (string) config('shopify.client_secret'), binary: true)),
        ], content: $content);

        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        if (! $response->isSuccessful()) {
            $this->components->error("The app answered {$topic} with a {$response->getStatusCode()} status.");

            return self::FAILURE;
        }

        $this->components->info(match (true) {
            ! config("shopify.webhooks.{$topic}") => "Delivered {$topic} for {$shop->domain}.",
            (bool) $this->option('sync') => "Delivered {$topic} for {$shop->domain} and ran its job.",
            default => "Delivered {$topic} for {$shop->domain} and queued its job. A queue worker runs it; \"composer run dev\" starts one.",
        });

        return self::SUCCESS;
    }

    /**
     * The shop the webhook is sent for, asking which one when several are installed.
     */
    private function shop(): ?Shop
    {
        $domain = $this->argument('shop');

        if ($domain === null) {
            $domains = Shop::query()->orderBy('domain')->pluck('domain');

            $domain = match ($domains->count()) {
                0 => null,
                1 => $domains->first(),
                default => select('Which shop is the webhook for?', $domains->all()),
            };
        }

        $shop = $domain ? Shop::firstWhere('domain', $domain) : null;

        if ($shop === null) {
            $this->components->error($domain
                ? "The shop {$domain} has not installed the app."
                : 'No shop has installed the app yet. Open it in a development store first.');
        }

        return $shop;
    }

    /**
     * The payload to send, read from a file or sampled for the topic.
     *
     * @return array<string, mixed>|null
     */
    private function payload(string $topic, Shop $shop): ?array
    {
        if ($path = $this->option('payload')) {
            $payload = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

            if (! is_array($payload)) {
                $this->components->error("The payload file [{$path}] does not contain a JSON object.");

                return null;
            }

            return $payload;
        }

        $customer = ['id' => 1, 'email' => 'customer@example.com', 'phone' => '555-625-1199'];
        $scopes = implode(',', $shop->scopes ?? []);

        // Samples of the payloads Shopify sends for the topics the kit handles...
        return match ($topic) {
            'app/uninstalled' => ['id' => $shop->id, 'domain' => $shop->domain, 'myshopify_domain' => $shop->domain],
            'app/scopes_update' => ['id' => $shop->id, 'previous' => $scopes, 'current' => $scopes, 'updated_at' => now()->toIso8601String()],
            'customers/data_request' => ['shop_id' => $shop->id, 'shop_domain' => $shop->domain, 'orders_requested' => [1], 'customer' => $customer, 'data_request' => ['id' => 1]],
            'customers/redact' => ['shop_id' => $shop->id, 'shop_domain' => $shop->domain, 'customer' => $customer, 'orders_to_redact' => [1]],
            'shop/redact' => ['shop_id' => $shop->id, 'shop_domain' => $shop->domain],
            default => [],
        };
    }
}

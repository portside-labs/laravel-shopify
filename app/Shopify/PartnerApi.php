<?php

namespace App\Shopify;

use Illuminate\Container\Attributes\Config;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use LogicException;
use RuntimeException;

/**
 * A client for the Partner API, which speaks for the app's organization rather than for any one shop.
 *
 * Shopify App Pricing subscriptions are read through it. Refusals and
 * throttling are thrown rather than answered with nothing: a subscription
 * the API could not describe is not one that is missing, and treating it as
 * missing would lock a paying merchant out.
 *
 * @see https://shopify.dev/docs/api/partner
 */
final readonly class PartnerApi
{
    /**
     * Create a new Partner API client instance.
     */
    public function __construct(
        #[Config('shopify.partner.organization_id')] private ?string $organizationId,
        #[Config('shopify.partner.access_token')] private ?string $accessToken,
        #[Config('shopify.api_version')] private string $apiVersion,
    ) {}

    /**
     * Execute a GraphQL query, returning its data.
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     *
     * @throws RequestException
     */
    public function graphql(string $query, array $variables = []): array
    {
        if (! $this->organizationId || ! $this->accessToken) {
            throw new LogicException('Set SHOPIFY_PARTNER_ORGANIZATION_ID and SHOPIFY_PARTNER_ACCESS_TOKEN to read subscriptions from the Partner API.');
        }

        $body = Http::baseUrl("https://partners.shopify.com/{$this->organizationId}/api/{$this->apiVersion}")
            ->withHeader('X-Shopify-Access-Token', $this->accessToken)
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(15)
            ->post('graphql.json', ['query' => $query, 'variables' => (object) $variables])
            ->throw()
            ->json();

        // Refusals and throttling arrive with a 200 and an "errors" list...
        $errors = $body['errors'] ?? null;

        if (is_array($errors)) {
            throw new RuntimeException(
                'The Partner API refused the request: '.collect($errors)->pluck('message')->implode('; '),
            );
        }

        return $body['data'] ?? [];
    }
}

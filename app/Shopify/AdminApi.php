<?php

namespace App\Shopify;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * A client for a shop's Admin API.
 */
final readonly class AdminApi
{
    /**
     * Create a new Admin API client instance.
     */
    public function __construct(
        private string $domain,
        private string $accessToken,
    ) {}

    /**
     * Execute a GraphQL query or mutation.
     *
     * @param  array<string, mixed>  $variables
     *
     * @throws RequestException
     */
    public function graphql(string $query, array $variables = []): Response
    {
        return $this->request()
            ->post('graphql.json', ['query' => $query, 'variables' => (object) $variables])
            ->throw();
    }

    /**
     * Get a pending request for the Admin API, authenticated as the token's owner.
     */
    public function request(): PendingRequest
    {
        return Http::baseUrl("https://{$this->domain}/admin/api/".config('shopify.api_version'))
            ->withHeader('X-Shopify-Access-Token', $this->accessToken)
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(30);
    }
}

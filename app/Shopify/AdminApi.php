<?php

namespace App\Shopify;

use App\Exceptions\AccessTokenRevokedException;
use Closure;
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
     *
     * @param  (Closure(): void)|null  $revoked  Invoked when Shopify rejects the access token.
     */
    public function __construct(
        private string $domain,
        private string $accessToken,
        private ?Closure $revoked = null,
    ) {}

    /**
     * Execute a GraphQL query or mutation.
     *
     * Shopify answers with a 401 once an access token has been revoked, for
     * example after the app was uninstalled. The token's owner is told to
     * forget it, and the exception is answered like any other unauthenticated
     * request so that the app reloads and installs itself again.
     *
     * @param  array<string, mixed>  $variables
     *
     * @throws AccessTokenRevokedException
     * @throws RequestException
     */
    public function graphql(string $query, array $variables = []): Response
    {
        $response = $this->request()->post('graphql.json', [
            'query' => $query,
            'variables' => (object) $variables,
        ]);

        if ($response->unauthorized()) {
            if ($this->revoked !== null) {
                ($this->revoked)();
            }

            $errors = $response->json('errors');

            throw new AccessTokenRevokedException(
                is_string($errors) ? $errors : 'Shopify rejected the access token.',
            );
        }

        return $response->throw();
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

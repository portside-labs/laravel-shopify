<?php

namespace App\Shopify;

use App\Exceptions\AccessTokenRevokedException;
use App\Exceptions\InvalidIdTokenException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Exchange ID tokens for Admin API access tokens, and refresh them.
 *
 * Because Shopify manages the app's installation, there is no OAuth redirect
 * dance: the ID token App Bridge already provides is exchanged directly for
 * an access token the first time a shop or staff member uses the app.
 *
 * @see https://shopify.dev/docs/apps/build/authentication-authorization/access-tokens/token-exchange
 */
final class TokenExchange
{
    /**
     * Create a new token exchange instance.
     */
    public function __construct(private Repository $config) {}

    /**
     * Request an expiring offline access token, which acts as the shop.
     *
     * The token lasts an hour and comes with a refresh token that lasts
     * ninety days, so the shop keeps working between merchant visits.
     *
     * @return array{access_token: string, scope: string, expires_in?: int, refresh_token?: string, refresh_token_expires_in?: int}
     */
    public function offline(IdToken $token): array
    {
        return $this->exchange($token, 'urn:shopify:params:oauth:token-type:offline-access-token')->json();
    }

    /**
     * Request an online access token, which acts as the staff member and expires daily.
     *
     * @return array{
     *     access_token: string,
     *     scope: string,
     *     expires_in: int,
     *     associated_user_scope: string,
     *     associated_user: array{
     *         id: int,
     *         first_name: string,
     *         last_name: string,
     *         email: string,
     *         email_verified: bool,
     *         account_owner: bool,
     *         locale: string,
     *         collaborator: bool,
     *     },
     * }
     */
    public function online(IdToken $token): array
    {
        return $this->exchange($token, 'urn:shopify:params:oauth:token-type:online-access-token')->json();
    }

    /**
     * Trade a refresh token for a new offline access token and refresh token.
     *
     * @return array{access_token: string, scope: string, expires_in: int, refresh_token: string, refresh_token_expires_in: int}
     *
     * @throws AccessTokenRevokedException
     * @throws RequestException
     */
    public function refresh(string $shop, string $refreshToken): array
    {
        $response = $this->post($shop, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);

        if ($response->clientError()) {
            throw new AccessTokenRevokedException(
                $response->json('error_description', 'Shopify rejected the refresh token.'),
            );
        }

        return $response->throw()->json();
    }

    /**
     * Exchange the ID token for the requested type of access token.
     *
     * @throws InvalidIdTokenException
     * @throws RequestException
     */
    protected function exchange(IdToken $token, string $requestedTokenType): Response
    {
        $response = $this->post($token->shop(), [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:token-exchange',
            'subject_token' => (string) $token,
            'subject_token_type' => 'urn:ietf:params:oauth:token-type:id_token',
            'requested_token_type' => $requestedTokenType,
            'expiring' => '1',
        ]);

        if ($response->badRequest()) {
            throw new InvalidIdTokenException(
                $response->json('error_description', 'Shopify rejected the ID token.'),
            );
        }

        return $response->throw();
    }

    /**
     * Send a grant request to the shop's token endpoint.
     *
     * @param  array<string, string>  $grant
     */
    protected function post(string $shop, array $grant): Response
    {
        return Http::connectTimeout(5)
            ->timeout(10)
            ->post("https://{$shop}/admin/oauth/access_token", [
                'client_id' => $this->config->get('shopify.client_id'),
                'client_secret' => $this->config->get('shopify.client_secret'),
                ...$grant,
            ]);
    }
}

<?php

namespace App\Shopify;

use App\Exceptions\AccessTokenRevokedException;
use App\Exceptions\InvalidIdTokenException;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;

/**
 * Authenticate requests from the embedded app with the ID token App Bridge sends.
 *
 * The token arrives as a bearer token on requests made by the app's frontend
 * and as an "id_token" query parameter when Shopify first loads the app in
 * the admin. It identifies both the shop and the staff member, so a valid
 * token is all that is needed: the shop is installed and the staff member's
 * profile is refreshed by exchanging the token for access tokens as needed.
 */
final class IdTokenGuard
{
    /**
     * Create a new guard instance.
     */
    public function __construct(
        private Repository $config,
        private TokenExchange $exchange,
    ) {}

    /**
     * Resolve the staff member making the request.
     */
    public function __invoke(Request $request): ?User
    {
        try {
            return $this->authenticate($request);
        } catch (InvalidIdTokenException) {
            return null;
        }
    }

    /**
     * Authenticate the request, installing the shop and refreshing the user as needed.
     *
     * @throws InvalidIdTokenException
     */
    protected function authenticate(Request $request): ?User
    {
        if (! $token = $this->token($request)) {
            return null;
        }

        $shop = Shop::firstOrNew(['domain' => $token->shop()]);

        if ($shop->accessTokenIsExpiring()) {
            $this->refresh($shop);
        }

        if (! $shop->isInstalled()) {
            $shop->install($this->exchange->offline($token));
        }

        $user = $shop->users()->firstOrNew(['shopify_id' => $token->user()]);

        if (! $user->hasValidAccessToken()) {
            $user->identify($this->exchange->online($token));
        }

        return $user->setRelation('shop', $shop);
    }

    /**
     * Parse the ID token carried by the request, if there is one.
     *
     * @throws InvalidIdTokenException
     */
    protected function token(Request $request): ?IdToken
    {
        $jwt = $request->bearerToken() ?? $request->query('id_token');

        if (! is_string($jwt) || $jwt === '') {
            return null;
        }

        return IdToken::parse(
            $jwt,
            (string) $this->config->get('shopify.client_id'),
            (string) $this->config->get('shopify.client_secret'),
        );
    }

    /**
     * Refresh the shop's expiring access token.
     *
     * A refresh token Shopify no longer honors leaves the shop uninstalled,
     * and since the merchant is present, the app is simply installed again.
     */
    protected function refresh(Shop $shop): void
    {
        try {
            $shop->refreshAccessToken();
        } catch (AccessTokenRevokedException) {
            //
        }
    }
}

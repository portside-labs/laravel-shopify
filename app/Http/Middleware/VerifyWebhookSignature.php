<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verify that an incoming webhook was sent by Shopify.
 *
 * Shopify signs the body of every webhook with the app's client secret and
 * expects requests that fail verification to be rejected with a 401 status.
 *
 * @see https://shopify.dev/docs/apps/build/webhooks/subscribe/https#step-5-verify-the-webhook
 */
class VerifyWebhookSignature
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $signature = base64_encode(hash_hmac(
            'sha256',
            (string) $request->getContent(),
            (string) config('shopify.client_secret'),
            binary: true,
        ));

        abort_unless(hash_equals($signature, (string) $request->header('X-Shopify-Hmac-Sha256')), 401);

        return $next($request);
    }
}

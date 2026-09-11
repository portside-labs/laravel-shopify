<?php

namespace App\Http\Middleware;

use App\Models\Shop;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Allow the Shopify admin to embed the app in an iframe.
 *
 * Shopify requires embedded apps to limit the pages that may frame them to
 * the merchant's own shop and the Shopify admin using a Content Security
 * Policy. Requests without a known shop fall back to any Shopify store.
 *
 * @see https://shopify.dev/docs/apps/build/security/set-up-iframe-protection
 */
class AllowEmbeddingInShopifyAdmin
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set(
            'Content-Security-Policy',
            "frame-ancestors {$this->frameAncestors($request)};",
        );

        return $response;
    }

    /**
     * Get the origins allowed to frame the response to the request.
     */
    protected function frameAncestors(Request $request): string
    {
        $shop = $request->shop()->domain ?? $request->query('shop');

        return Shop::isValidDomain($shop)
            ? "https://{$shop} https://admin.shopify.com"
            : 'https://*.myshopify.com https://admin.shopify.com';
    }
}

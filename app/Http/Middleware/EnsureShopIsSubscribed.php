<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Send a shop without an active plan to choose one.
 *
 * Register it as "subscribed" on the routes a plan pays for. A plan handle
 * may be given to require one plan in particular, as in "subscribed:pro".
 * The check reads the subscription the shop remembers and only asks Shopify
 * again once it is a few minutes old, so most visits cost no request at all.
 */
class EnsureShopIsSubscribed
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, ?string $plan = null): Response
    {
        if (! $request->shop()?->subscribed($plan)) {
            return redirect()->route('pricing', $request->shopifyContext());
        }

        return $next($request);
    }
}

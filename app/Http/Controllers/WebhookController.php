<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Dispatch the job registered for an incoming webhook's topic.
 *
 * Webhooks are verified before they reach this controller, and any topic
 * without a registered job or a known shop is acknowledged and ignored so
 * that Shopify does not keep retrying the delivery.
 */
class WebhookController extends Controller
{
    /**
     * Handle the incoming webhook.
     */
    public function __invoke(Request $request): Response
    {
        $job = config('shopify.webhooks.'.$request->header('X-Shopify-Topic'));

        $shop = Shop::firstWhere('domain', $request->header('X-Shopify-Shop-Domain'));

        if (is_string($job) && $shop) {
            dispatch(new $job($shop, $request->all()));
        }

        return response()->noContent();
    }
}

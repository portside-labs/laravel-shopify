<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The app's side of the door onto a plan.
 *
 * Shopify App Pricing hosts the plan selection page in the admin, outside of
 * the app's frame, where the app cannot send the browser on its own. This
 * page opens it at the top of the window and offers a link for a browser
 * that would not let it.
 */
class PricingController extends Controller
{
    /**
     * Show the pricing page.
     */
    public function __invoke(Request $request): Response
    {
        $shop = $request->shop();

        return Inertia::render('pricing', [
            'plan_selection_url' => $shop?->planSelectionUrl(),
            'plan' => $shop?->subscription()?->plan,
        ]);
    }
}

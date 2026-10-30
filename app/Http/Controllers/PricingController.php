<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
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
 *
 * A merchant whose new plan the Partner API does not show yet waits here
 * instead, and the page asks Shopify again until it does.
 */
class PricingController extends Controller
{
    /**
     * Show the pricing page.
     */
    public function __invoke(Request $request): Response|RedirectResponse
    {
        $shop = $request->shop();
        $confirming = $request->string('confirming')->toString() ?: null;

        if ($confirming !== null && $shop?->syncSubscription()?->hasChosen($confirming)) {
            return redirect()->route('billing.welcome', [...$request->shopifyContext(), 'plan_handle' => $confirming]);
        }

        return Inertia::render('pricing', [
            'plan_selection_url' => $shop?->planSelectionUrl(),
            'plan' => $confirming === null ? $shop?->subscription()?->plan : null,
            'confirming' => $confirming,
        ]);
    }
}

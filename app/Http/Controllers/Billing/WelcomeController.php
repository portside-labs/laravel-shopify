<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The welcome link: where Shopify sends a merchant who has chosen a plan.
 *
 * Configure it on each plan in the Partner Dashboard as the relative path
 * "/billing/welcome". Shopify names the plan in the "plan_handle" parameter,
 * but the Partner API is the word on what was approved, so the subscription
 * is read again before the merchant is sent on to the home page.
 */
class WelcomeController extends Controller
{
    /**
     * Confirm the plan and send the merchant home.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        if ($request->shop()?->syncSubscription() !== null) {
            Inertia::flash('toast', __('pricing.subscribed'));
        }

        return redirect()->route('home', $request->shopifyContext());
    }
}

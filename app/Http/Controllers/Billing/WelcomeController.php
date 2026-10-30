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
 *
 * Shopify sends the merchant back moments before the Partner API shows the
 * change, so until it does they wait on the pricing page rather than find
 * their old plan still in place and choose again.
 */
class WelcomeController extends Controller
{
    /**
     * Confirm the plan and send the merchant home.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        $shop = $request->shop();
        $plan = $request->string('plan_handle')->toString() ?: null;
        $subscription = $shop?->syncSubscription();

        if ($shop !== null && $plan !== null && ! $subscription?->hasChosen($plan)) {
            $shop->expectPlan($plan);

            return redirect()->route('pricing', [...$request->shopifyContext(), 'confirming' => $plan]);
        }

        if ($subscription !== null) {
            Inertia::flash('toast', $subscription->pendingPlan === null
                ? __('pricing.subscribed')
                : __('pricing.scheduled', ['plan' => $subscription->pendingPlan]));
        }

        return redirect()->route('home', $request->shopifyContext());
    }
}

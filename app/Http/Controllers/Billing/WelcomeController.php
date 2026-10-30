<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Shopify\Subscription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The welcome link: where Shopify sends a merchant who has chosen a plan, or changed to another.
 *
 * Configure it on each plan in the Partner Dashboard as the relative path
 * "/billing/welcome". Shopify names the plan in the "plan_handle" parameter,
 * but the Partner API is the word on what was approved, so the subscription
 * is read again before the merchant is sent on to the home page.
 *
 * Shopify sends the merchant back moments before the Partner API catches
 * up, so for a while it can still describe the plan they left. Until the
 * two agree the merchant waits on the pricing page, which keeps asking:
 * sent on, they would find their old plan still in place and choose again.
 * The parameter alone is never believed, so naming a plan in it unlocks
 * nothing.
 */
class WelcomeController extends Controller
{
    /**
     * Confirm the plan and send the merchant home.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        $shop = $request->shop();
        $chosen = $request->filled('plan_handle') ? $request->string('plan_handle')->toString() : null;
        $subscription = $shop?->syncSubscription();

        if ($shop !== null && $chosen !== null && ! $subscription?->hasChosen($chosen)) {
            $shop->expectPlan($chosen);

            return redirect()->route('pricing', [...$request->shopifyContext(), 'confirming' => $chosen]);
        }

        if ($subscription !== null) {
            Inertia::flash('toast', $this->confirmation($subscription, $chosen));
        }

        return redirect()->route('home', $request->shopifyContext());
    }

    /**
     * Tell the merchant which plan they are on now, or which one they are moving to.
     */
    private function confirmation(Subscription $subscription, ?string $chosen): string
    {
        if ($chosen !== null && $subscription->plan !== $chosen) {
            return __('pricing.scheduled', ['plan' => $chosen]);
        }

        return $subscription->plan === '' ? __('pricing.subscribed') : __('pricing.changed', ['plan' => $subscription->plan]);
    }
}

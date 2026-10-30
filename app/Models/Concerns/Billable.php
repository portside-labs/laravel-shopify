<?php

namespace App\Models\Concerns;

use App\Shopify\PartnerApi;
use App\Shopify\Subscription;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Bill the shop through Shopify App Pricing.
 *
 * Shopify owns the subscription: plans are configured in the Partner
 * Dashboard, chosen on the page Shopify hosts in the admin, and read back
 * through the Partner API. The shop keeps what it last heard, so a page can
 * check for a plan cheaply, and asks again once that is a few minutes old.
 * That is also how a cancellation is noticed, since Shopify sends no webhook.
 */
trait Billable
{
    /**
     * The number of minutes a subscription is trusted before Shopify is asked again.
     */
    protected const int SUBSCRIPTION_FRESH_FOR = 5;

    /**
     * Determine if the shop is on an active plan, or on the given one.
     */
    public function subscribed(?string $plan = null): bool
    {
        $subscription = $this->subscription();

        return $subscription !== null && ($plan === null || $subscription->plan === $plan);
    }

    /**
     * Determine if the shop's plan is still in its free trial.
     */
    public function onTrial(): bool
    {
        return $this->subscription()?->onTrial() ?? false;
    }

    /**
     * The shop's subscription, as Shopify last described it.
     *
     * What the shop remembers is not trusted while it lags behind a plan the
     * merchant has just chosen, so a page checked in the meantime asks again.
     *
     * @throws RequestException
     */
    public function subscription(): ?Subscription
    {
        $remembered = Subscription::fromShop($this);
        $expected = $this->expectedPlan();

        if ($this->subscription_synced_at?->greaterThan(now()->subMinutes(self::SUBSCRIPTION_FRESH_FOR))
            && ($expected === null || $remembered?->hasChosen($expected))) {
            return $remembered;
        }

        return $this->syncSubscription();
    }

    /**
     * Ask the Partner API which plan the shop is on, and remember the answer.
     *
     * @throws RequestException
     */
    public function syncSubscription(): ?Subscription
    {
        $node = app(PartnerApi::class)->graphql(<<<'GRAPHQL'
            query ActiveSubscription($appId: ID!, $shopId: ID!) {
                activeSubscription(appId: $appId, shopId: $shopId) {
                    trialEndsAt
                    cancelAtEndOfCycle
                    currentBillingCycle {
                        endTime
                    }
                    items {
                        handle
                        price {
                            __typename
                        }
                    }
                    pendingUpdate {
                        items {
                            handle
                            price {
                                __typename
                            }
                        }
                    }
                }
            }
            GRAPHQL, [
            'appId' => 'gid://shopify/App/'.config('shopify.app_id'),
            'shopId' => $this->shopifyGid(),
        ])['activeSubscription'] ?? null;

        $subscription = is_array($node) ? Subscription::fromPartnerApi($node) : null;

        $this->forceFill([
            'plan' => $subscription?->plan,
            'pending_plan' => $subscription?->pendingPlan,
            'trial_ends_at' => $subscription?->trialEndsAt,
            'current_period_ends_at' => $subscription?->currentPeriodEndsAt,
            'cancels_at_period_end' => $subscription->cancelsAtPeriodEnd ?? false,
            'subscription_synced_at' => now(),
        ])->save();

        $expected = $this->expectedPlan();

        if ($expected !== null && $subscription?->hasChosen($expected)) {
            Cache::forget($this->expectedPlanCacheKey());
        }

        return $subscription;
    }

    /**
     * Remember that the merchant has just chosen the given plan, so Shopify is asked again until it shows it.
     *
     * Shopify sends a merchant back from a plan change moments before the
     * Partner API stops describing the plan they left. Until it catches up,
     * or a few minutes pass, the plan the shop remembers is not trusted.
     */
    public function expectPlan(string $plan): void
    {
        Cache::put($this->expectedPlanCacheKey(), $plan, now()->addMinutes(self::SUBSCRIPTION_FRESH_FOR));
    }

    /**
     * The plan the merchant has just chosen and Shopify does not show yet, if any.
     */
    public function expectedPlan(): ?string
    {
        $plan = Cache::get($this->expectedPlanCacheKey());

        return is_string($plan) ? $plan : null;
    }

    /**
     * Where the merchant picks or changes their plan.
     *
     * Shopify hosts the page inside the admin, outside of the app's frame,
     * so it has to be opened at the top of the window.
     */
    public function planSelectionUrl(): string
    {
        return sprintf(
            'https://admin.shopify.com/store/%s/charges/%s/pricing_plans',
            Str::before($this->domain, '.'),
            config('shopify.app_handle'),
        );
    }

    /**
     * Shopify's global ID for the shop, read from its Admin API the first time it is needed.
     */
    public function shopifyGid(): string
    {
        if ($this->shopify_id === null) {
            $gid = (string) $this->api()->graphql('query { shop { id } }')->json('data.shop.id');

            $this->forceFill(['shopify_id' => (int) Str::afterLast($gid, '/')])->save();
        }

        return "gid://shopify/Shop/{$this->shopify_id}";
    }

    /**
     * Get the attributes that forget the subscription Shopify last described.
     *
     * @return array{plan: null, pending_plan: null, trial_ends_at: null, current_period_ends_at: null, cancels_at_period_end: false, subscription_synced_at: null}
     */
    protected function forgottenSubscriptionAttributes(): array
    {
        return [
            'plan' => null,
            'pending_plan' => null,
            'trial_ends_at' => null,
            'current_period_ends_at' => null,
            'cancels_at_period_end' => false,
            'subscription_synced_at' => null,
        ];
    }

    /**
     * The cache key the plan the merchant has just chosen is kept under.
     */
    private function expectedPlanCacheKey(): string
    {
        return "shops.{$this->getKey()}.expected-plan";
    }
}

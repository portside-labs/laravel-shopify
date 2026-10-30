<?php

namespace App\Shopify;

use App\Models\Shop;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;

/**
 * A shop's Shopify App Pricing subscription, as the Partner API describes it.
 *
 * Shopify owns the subscription; the app only reads it. The plan is the
 * handle given to it in the Partner Dashboard, which is also what Shopify
 * names in the "plan_handle" parameter of a plan's welcome link.
 */
final readonly class Subscription
{
    /**
     * Create a new subscription instance.
     */
    public function __construct(
        public string $plan,
        public ?CarbonInterface $trialEndsAt,
        public ?CarbonInterface $currentPeriodEndsAt,
        public bool $cancelsAtPeriodEnd,
        public ?string $pendingPlan = null,
    ) {}

    /**
     * Read the subscription off the Partner API's "activeSubscription" node.
     *
     * A change Shopify has scheduled for the next billing cycle, as it does
     * with a move to a cheaper plan, is described in "pendingUpdate" while
     * the plan already paid for stays in place.
     *
     * @param  array<string, mixed>  $node
     */
    public static function fromPartnerApi(array $node): self
    {
        return new self(
            plan: (string) self::planHandle($node['items'] ?? null),
            trialEndsAt: self::date($node['trialEndsAt'] ?? null),
            currentPeriodEndsAt: self::date($node['currentBillingCycle']['endTime'] ?? null),
            cancelsAtPeriodEnd: (bool) ($node['cancelAtEndOfCycle'] ?? false),
            pendingPlan: self::planHandle($node['pendingUpdate']['items'] ?? null),
        );
    }

    /**
     * The subscription as the shop last saw it, if it saw one.
     */
    public static function fromShop(Shop $shop): ?self
    {
        if ($shop->plan === null) {
            return null;
        }

        return new self(
            $shop->plan,
            $shop->trial_ends_at,
            $shop->current_period_ends_at,
            $shop->cancels_at_period_end,
            $shop->pending_plan,
        );
    }

    /**
     * Determine if the merchant is still inside their free trial.
     */
    public function onTrial(): bool
    {
        return $this->trialEndsAt !== null && $this->trialEndsAt->isFuture();
    }

    /**
     * Determine if Shopify has recorded the merchant's choice of the given plan.
     *
     * The choice is the plan the shop ends up on: the one Shopify has
     * scheduled for the start of the next billing cycle, if there is one,
     * or else the one in place. Choosing the current plan again to cancel
     * a scheduled move only counts once Shopify has dropped the move.
     */
    public function hasChosen(string $plan): bool
    {
        return ($this->pendingPlan ?? $this->plan) === $plan;
    }

    /**
     * Read the plan's handle off a list of subscription items.
     *
     * The plan is the item carrying the recurring charge; usage meters are
     * items of their own and never name the plan.
     */
    private static function planHandle(mixed $items): ?string
    {
        $items = collect(is_array($items) ? $items : [])->filter(fn ($item) => is_array($item));

        if ($items->isEmpty()) {
            return null;
        }

        $plan = $items->first(fn (array $item) => ($item['price']['__typename'] ?? null) === 'FlatRatePrice') ?? $items->first();

        return (string) ($plan['handle'] ?? '');
    }

    /**
     * Parse a timestamp from the Partner API, if there is one.
     */
    private static function date(mixed $value): ?CarbonInterface
    {
        return is_string($value) && $value !== '' ? Date::parse($value) : null;
    }
}

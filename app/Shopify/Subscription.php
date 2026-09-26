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
    ) {}

    /**
     * Read the subscription off the Partner API's "activeSubscription" node.
     *
     * The plan is the item carrying the recurring charge; usage meters are
     * items of their own and never name the plan.
     *
     * @param  array<string, mixed>  $node
     */
    public static function fromPartnerApi(array $node): self
    {
        $items = collect(is_array($node['items'] ?? null) ? $node['items'] : [])->filter(fn ($item) => is_array($item));
        $plan = $items->first(fn (array $item) => ($item['price']['__typename'] ?? null) === 'FlatRatePrice') ?? $items->first();

        return new self(
            plan: (string) ($plan['handle'] ?? ''),
            trialEndsAt: self::date($node['trialEndsAt'] ?? null),
            currentPeriodEndsAt: self::date($node['currentBillingCycle']['endTime'] ?? null),
            cancelsAtPeriodEnd: (bool) ($node['cancelAtEndOfCycle'] ?? false),
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
     * Parse a timestamp from the Partner API, if there is one.
     */
    private static function date(mixed $value): ?CarbonInterface
    {
        return is_string($value) && $value !== '' ? Date::parse($value) : null;
    }
}

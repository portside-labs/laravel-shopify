<?php

namespace Database\Factories;

use App\Models\Shop;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Shop>
 */
class ShopFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'domain' => Str::slug(fake()->unique()->company()).'.myshopify.com',
            'shopify_id' => fake()->unique()->randomNumber(9),
            'access_token' => 'shpat_'.Str::random(32),
            'access_token_expires_at' => now()->addHour(),
            'refresh_token' => Str::random(64),
            'refresh_token_expires_at' => now()->addDays(90),
            'scopes' => ['read_products'],
            'installed_at' => now(),
            'uninstalled_at' => null,
        ];
    }

    /**
     * Indicate that the shop is on a plan, as confirmed with Shopify just now.
     */
    public function subscribed(string $plan = 'basic'): static
    {
        return $this->state(fn (array $attributes) => [
            'plan' => $plan,
            'current_period_ends_at' => now()->addMonth(),
            'subscription_synced_at' => now(),
        ]);
    }

    /**
     * Indicate that the shop's access token is about to expire.
     */
    public function expiring(): static
    {
        return $this->state(fn (array $attributes) => [
            'access_token_expires_at' => now()->addMinute(),
        ]);
    }

    /**
     * Indicate that the app has been uninstalled from the shop.
     */
    public function uninstalled(): static
    {
        return $this->state(fn (array $attributes) => [
            'access_token' => null,
            'access_token_expires_at' => null,
            'refresh_token' => null,
            'refresh_token_expires_at' => null,
            'uninstalled_at' => now(),
        ]);
    }
}

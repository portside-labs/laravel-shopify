<?php

namespace Database\Factories;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shop_id' => Shop::factory(),
            'shopify_id' => fake()->unique()->randomNumber(8),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified' => true,
            'account_owner' => false,
            'collaborator' => false,
            'locale' => 'en',
            'scopes' => ['read_products'],
            'access_token' => 'shpua_'.Str::random(32),
            'access_token_expires_at' => now()->addDay(),
        ];
    }

    /**
     * Indicate that the user owns the shop's Shopify account.
     */
    public function accountOwner(): static
    {
        return $this->state(fn (array $attributes) => [
            'account_owner' => true,
        ]);
    }

    /**
     * Indicate that the user's online access token has expired.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'access_token_expires_at' => now()->subMinute(),
        ]);
    }
}

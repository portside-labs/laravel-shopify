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
            'access_token' => 'shpat_'.Str::random(32),
            'scopes' => ['read_products'],
            'installed_at' => now(),
            'uninstalled_at' => null,
        ];
    }

    /**
     * Indicate that the app has been uninstalled from the shop.
     */
    public function uninstalled(): static
    {
        return $this->state(fn (array $attributes) => [
            'access_token' => null,
            'uninstalled_at' => now(),
        ]);
    }
}

<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'description' => fake()->paragraph(),
            'category' => fake()->randomElement(['Flowers', 'Keepsakes', 'Stationery', 'Home decor']),
            'price' => fake()->randomFloat(2, 0, 500),
            'stock_quantity' => fake()->numberBetween(0, 100),
            'image_path' => null,
        ];
    }
}

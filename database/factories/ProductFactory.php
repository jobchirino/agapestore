<?php

namespace Database\Factories;

use App\Models\Category;
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
            'category_id' => Category::factory(),
            'name' => fake()->unique()->words(3, true),
            'detal_price' => fake()->randomFloat(2, 5, 50),
            'major_price' => fake()->randomFloat(2, 3, 40),
            'total_stock' => fake()->numberBetween(1, 50),
            'img_route' => null,
        ];
    }
}

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
     * @return array<string, int|string>
     */
    public function definition(): array
    {
        return [
            'sku' => fake()->unique()->bothify('SKU-########'),
            'name' => fake()->sentence(3),
            'initial_stock' => 5,
            'available_stock' => 5,
            'reserved_stock' => 0,
            'confirmed_stock' => 0,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Заготовка товара для проверок. Создаёт отдельные записи с известным исходным количеством.
 *
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Задаёт неповторяющийся артикул, название и пять свободных единиц. Удержанное и подтверждённое количество
     * сначала равно нулю, поэтому исходное равенство счётчиков выполняется.
     *
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

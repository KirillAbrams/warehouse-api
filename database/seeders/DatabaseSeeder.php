<?php

namespace Database\Seeders;

use App\Actions\Demo\RotateDemoTokens;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use LogicException;

/**
 * Заполняет новый запуск двумя пользователями и тремя товарами. Повторный запуск сохраняет существующие товары
 * и историю резервов.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Создаёт только отсутствующие учебные записи, затем общей операцией заменяет их ключи доступа. Печатает
     * новые ключи после успешного завершения замены. Существующее количество товара не сбрасывает.
     */
    public function run(RotateDemoTokens $rotate): void
    {
        foreach (RotateDemoTokens::USERS as $name => $email) {
            User::query()->firstOrCreate(['email' => $email], [
                'name' => $name,
                'password' => bin2hex(random_bytes(32)),
            ]);
        }

        foreach ([
            ['KB-001', 'Mechanical keyboard', 5],
            ['MS-001', 'Wireless mouse', 12],
            ['MN-001', 'Monitor', 3],
        ] as [$sku, $name, $stock]) {
            Product::query()->firstOrCreate(['sku' => $sku], [
                'name' => $name,
                'initial_stock' => $stock,
                'available_stock' => $stock,
            ]);
        }

        $tokens = $rotate->handle() ?? throw new LogicException('Demo users disappeared before token rotation.');

        foreach ($tokens as $email => $token) {
            $this->command?->line($email.' '.$token);
        }
    }
}
